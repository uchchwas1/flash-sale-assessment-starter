# Submission — Flash Sale API

**Laravel 13 / PHP 8.3 / MySQL 8 (InnoDB) / Redis**, layered
`Controller → FormRequest → Service → Repository → Model → DB`.

🎥 **Video walkthrough:** [Project brief & live output demo](https://drive.google.com/file/d/1tVm_BtI0p-Z1BMM44iM3F9Kg3otynR3J/view?usp=sharing)

**Verified:** `test_concurrency.py` (50 concurrent requests, 10 stock) → exactly
**10 successful / 40 rejected**, `available_stock = 0`, `orders = 10`. A committed
`pcntl_fork` test (`tests/Feature/PurchaseConcurrencyTest.php`) proves the same with 50
parallel OS processes. Full suite: 34 tests.

---

## 1. Concurrency Strategy

The naive read-check-write flow loses updates under concurrency: 50 requests all read
stock `10`, all pass the check, all write `9`. The fix is making the check and the write
**one serialized operation**, enforced by the database — not by application timing.

**a) Atomic conditional decrement** (`app/Repositories/ItemRepository.php`)
```sql
UPDATE items SET available_stock = available_stock - 1, total_stock = total_stock - 1
WHERE id = ? AND available_stock > 0;
```
InnoDB row-locks this single statement and serializes concurrent writers; the `WHERE` guard
makes over-decrement impossible. Affected-rows is the verdict: `1` = unit claimed, `0` =
sold out.

**The last-unit race, step by step** — two users hit `/buy` in the same millisecond with
one unit left:

```
                     available_stock = 1
        ┌─────────────────────┴─────────────────────┐
   User A's UPDATE                              User B's UPDATE
   acquires row lock                            BLOCKS — waits for lock
        │                                             │
   WHERE available_stock > 0  ✓ (1 > 0)               │ (still waiting)
   stock: 1 → 0                                       │
   affected rows = 1  → A WON                         │
   INSERT order (A)  ✓                                │
   COMMIT → releases lock                             │
        │                                             ▼
        │                              lock acquired, UPDATE runs now
        │                              WHERE available_stock > 0  ✗ (0 > 0 is false)
        │                              affected rows = 0  → B LOST
        │                              throw SoldOutException → 409
        ▼                                             ▼
   201 Created, remaining_stock: 0            409 "Item is sold out"
```

There is no gap between "check stock" and "decrement" for a race to slip through — they are
the same locked statement, so exactly one buyer can ever win the last unit.

**b) `UNIQUE(item_id, user_id)`** on `orders` — the double-purchase guard. Two simultaneous
requests from the same user: one `INSERT` wins, the other violates the constraint → `409`.

**c) One transaction** (`app/Services/PurchaseService.php`) wraps decrement + insert: they
commit or roll back together, so a rejected duplicate restores its decremented unit. It runs
with `attempts: 5` — retrying only on transient InnoDB deadlocks so a legitimate winner is
never lost and exactly 10 (not ≤ 10) are sold.

**Schema backstops:** `INT UNSIGNED` + `CHECK (available_stock >= 0)` make an oversell
unpersistable even by a buggy query; `user_id` is `utf8mb4_bin` so dedupe is exact-match
(`User_1` ≠ `user_1` — MySQL's default collation would merge them).

---

## 2. Trade-offs & Alternatives

| Alternative | Why not chosen |
|---|---|
| `SELECT … FOR UPDATE` | Holds the row lock for the whole transaction instead of one statement — longer critical section, bigger deadlock surface under 50 racers. |
| Optimistic locking (version column) | Built for rare conflicts; a flash sale is constant conflict → retry storms. |
| Redis `DECR` as source of truth | Fast, but splits truth across two stores; a crash between Redis and DB writes needs reconciliation. Wrong trade for a correctness-first task. |
| Queue + single worker | Serializes correctly but makes `/buy` asynchronous ("accepted", not "bought") — the test expects an immediate verdict in the same response. |

The chosen design is the simplest provably-correct one: no extra infrastructure in the
critical path, survives restarts (truth lives in the DB), and matches the synchronous
contract the harness expects.

**Redis is used, but only as an advisory fast path:** known repeat buyers are rejected via
`SISMEMBER buyers:item:{id}` before any MySQL round-trip (`app/Buyers/RedisBuyerRegistry.php`).
It fails open — Redis down or cold means the request falls through to the DB checks — so it
deflects load without ever becoming a second source of truth.

**Duplicate = `409`, not idempotent `200`:** without an idempotency key you can't tell a
safe retry from a deliberate second purchase, so returning success would hide real
double-buys.

---

## 3. Production Readiness — 100,000 req/sec

At that scale the single hot row's lock is the bottleneck. The design stays correct but
needs throughput moved in front of the DB:

- **Redis stock gate** — atomic Lua script (decrement + record buyer) admits/rejects in
  microseconds; MySQL becomes the durable ledger, written asynchronously.
- **Queue for persistence** (Kafka/SQS) — flattens the spike into steady DB writes.
- **Idempotency keys** on `/buy` — safe client retries once Redis/DB are eventually consistent.
- **Authentication** — today `user_id` is a client string, so one actor could claim all stock
  under different ids; it must come from an authenticated principal (Sanctum/JWT).
- **Rate limiting + waiting room** — flash sales are bot magnets; shed load protectively.
- **Reservation TTL** — auto-release unpaid holds (reserve → confirm state machine).
- **Horizontal scale** — stateless pods, read replicas for `GET`, metrics + alerting.
- **Reconciliation job** — periodic sweep guaranteeing Redis and MySQL converge after crashes.

Principle: the DB stays the *correctness* authority; Redis + queues carry the *throughput*.

---

## Architecture at a glance

```
routes/api.php
  └─ ItemController (thin)
       ├─ BuyItemRequest        validation (trim, max:100)
       ├─ PurchaseService       Redis fast path → transaction (decrement + insert, retry x5)
       │    ├─ ItemRepository   atomic conditional decrement
       │    └─ OrderRepository  insert (UNIQUE guards double-buy)
       └─ Exceptions + handler  domain errors → {success,message,errors} with 404/409/422
```
