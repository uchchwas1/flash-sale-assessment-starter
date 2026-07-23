# Submission — Flash Sale API

A high-concurrency flash-sale service built in **Laravel 13 / PHP 8.3 / MySQL 8**, layered
`Controller → FormRequest → Service → Repository → Model → DB`. It sells exactly the
available stock under a burst of concurrent buyers, never oversells, and never lets a
single user buy twice.

**Verified:** the provided `test_concurrency.py` (50 concurrent requests, 10 stock) yields
exactly **10 successful / 40 rejected**, with `available_stock = 0` and `orders = 10`; a
committed `pcntl_fork` test (`tests/Feature/PurchaseConcurrencyTest.php`) proves the same
under 50 genuinely parallel OS processes.

---

## 1. Concurrency Strategy — how race conditions and double-selling are prevented

**The failure mode being defended against.** The naive flow — read `available_stock`, check
`> 0` in PHP, then write `stock - 1` — is a classic *lost update*. Under 50 concurrent
requests all 50 read `10`, all pass the check, all write `9`: fifty sold, stock wrong. Speed
does not fix this; the check and the write must be a single serialized operation.

The correctness rests on **two DB-level guarantees, not application timing**:

**a) Atomic conditional decrement** (`app/Repositories/ItemRepository.php`)
```sql
UPDATE items SET available_stock = available_stock - 1
WHERE id = ? AND available_stock > 0;
```
InnoDB takes an exclusive row lock for this single statement and serializes concurrent
writers. The `WHERE available_stock > 0` guard fuses the "is stock available?" check and the
decrement into one indivisible step, so no two buyers can both claim the same unit. The
**affected-row count** is the source of truth — `1` = claimed, `0` = sold out (MySQL has no
`RETURNING`).

**b) `UNIQUE(item_id, user_id)`** (`orders` table) — the double-purchase guard. Even if the
same user fires two requests simultaneously, the database lets exactly one `INSERT` win and
rejects the other; the service maps that violation to `409`.

Both run inside **one transaction** (`app/Services/PurchaseService.php`): decrement and
order-insert commit together or roll back together. If a duplicate insert fails after the
decrement, the rollback **restores the unit** — no stock is leaked.

**Defense in depth.** `available_stock` is `INT UNSIGNED` with `CHECK (available_stock >= 0)`,
so even a buggy or hand-written query physically cannot persist an oversell. `user_id` uses
the `utf8mb4_bin` collation so `User_1` and `user_1` are correctly treated as different
buyers (the MySQL default collation is case-insensitive and would merge them).

**Reliability under contention.** The transaction runs with `attempts: 5`
(`DB::transaction($cb, 5)`). Under 50 buyers on one row, InnoDB can raise transient
deadlocks/lock-waits; retrying *only* on those means a legitimate winner is never lost, so we
land on **exactly 10** sold rather than "≤ 10." Domain rejections (sold-out / duplicate) are
never retried — they roll back and surface immediately.

Net effect: correctness is a property of the schema and the transaction, independent of how
fast the code runs or how many app servers exist.

---

## 2. Trade-offs & Alternatives

| Alternative considered | Why not chosen here |
|---|---|
| **`SELECT … FOR UPDATE`** (pessimistic lock) | Correct and explicit, but holds the row lock for the *entire* transaction (select → decide → insert → commit) instead of a single statement — a longer critical section and larger deadlock surface under 50+ racers. The atomic `UPDATE` locks for the shortest possible span. |
| **Optimistic locking** (version column + retry) | Shines when conflicts are *rare*; a flash sale is the opposite — nearly every request conflicts, causing retry storms. |
| **Redis atomic `DECR` as source of truth** | Extremely fast, but splits truth across two stores: a crash between the Redis decrement and the DB write leaves them inconsistent, needing reconciliation. For a correctness-first task, a single authoritative store (the DB) is simpler and safer. Kept for the scale answer below. |
| **Queue + single worker** (serialize via one consumer) | Genuinely serializes access, but makes `/buy` *asynchronous* — the caller gets "accepted," not "you bought it." The test expects an immediate success/reject in the same response, so this fights the contract. |

**Why this approach wins for the task:** it is the simplest thing that is *provably* correct,
needs no extra infrastructure, survives app restarts (truth lives in the DB), and matches the
synchronous request/response contract the harness expects — KISS, with correctness guaranteed
by the schema.

**Implemented Redis optimisation — duplicate-buyer fast path.** A repeat buyer is
rejected *before any MySQL round-trip* via a Redis set per item
(`SISMEMBER buyers:item:{id} {userId}`; `app/Buyers/RedisBuyerRegistry.php`). It is
**advisory only** and fails open: if Redis is unavailable or the entry is cold, the request
falls through to the DB `findByItemAndUser` check and the authoritative
`UNIQUE(item_id, user_id)` constraint — so a miss is always safe. This deflects
repeat-clickers off the database during a hot sale without ever becoming a second source of
truth. (Under tests an in-memory `ArrayBuyerRegistry` stands in, so correctness needs no live
Redis.)

**One deliberate product decision:** a repeat purchase by the same user returns **`409`**
(clear "double purchase prevented") rather than an idempotent `200`. Without an idempotency
key you cannot distinguish a *retry of a call that already succeeded* from a *deliberate second
purchase*, so returning success would silently hide a real double-buy. Idempotency-key–based
safe retries are listed as a production enhancement below.

---

## 3. Production Readiness — what I'd add for 100,000 requests/sec

At 100k rps against a single hot row, that row's lock becomes the bottleneck: every buyer
queues behind the same InnoDB lock. The design stays *correct* at any scale but won't be
*fast enough*. What I'd add:

- **Redis as the front-line stock gate.** Hold the counter in Redis and admit/reject buyers
  with an atomic **Lua script** (decrement + record-buyer in one indivisible step) at
  microsecond latency. MySQL becomes the durable ledger written asynchronously — Redis
  absorbs the burst, the DB stays authoritative.
- **Decouple persistence with a queue.** Successful reservations publish to Kafka/SQS;
  workers persist orders to MySQL at a sustainable rate, flattening the spike into steady
  writes and letting the API respond immediately.
- **Idempotency keys** on `/buy` so client retries/timeouts don't create duplicate
  reservations once Redis and the DB are eventually consistent.
- **Authentication.** Today `user_id` is an unauthenticated client string — one actor could
  send `user_1…user_10` and take all stock. In production `user_id` must come from an
  authenticated principal (Sanctum/JWT), and the body value ignored.
- **Rate limiting & a waiting room.** Per-IP/user throttling plus a queue ("you're number N
  in line") to shed load protectively instead of collapsing; flash sales are bot magnets.
- **Reservation TTL** — a held-but-unpaid unit auto-releases after N seconds (introduces the
  reserve → confirm state machine, out of scope today).
- **Horizontal scale & resilience** — stateless API pods behind a load balancer, read
  replicas for the `GET` status path, circuit breakers, and rich metrics
  (sold/rejected/latency/lock-wait) with alerting.
- **Reconciliation job** — because Redis is now the fast path, a periodic sweep guarantees
  Redis and MySQL converge so the invariant (orders ≤ stock) is never violated after a crash.

**Through-line:** keep the DB as the *correctness* authority, move *throughput* into Redis +
queues in front of it, and add idempotency + reconciliation so the two stores coexist safely.

---

## Architecture at a glance

```
routes/api.php
  └─ ItemController (thin)                app/Http/Controllers/ItemController.php
       ├─ BuyItemRequest (validation)     app/Http/Requests/BuyItemRequest.php
       ├─ PurchaseService (transaction)   app/Services/PurchaseService.php
       │    ├─ ItemRepository  ── atomic conditional decrement
       │    └─ OrderRepository ── insert (UNIQUE guards double-buy)
       ├─ ItemResource / ApiResponse      consistent JSON envelope
       └─ FlashSaleException + handler     domain errors → 404 / 409 envelope
```

Guarantees enforced by the schema (`database/migrations`):
`CHECK (available_stock >= 0)`, `CHECK (available_stock <= total_stock)`,
`UNIQUE(item_id, user_id)`, `user_id` as `utf8mb4_bin`.
