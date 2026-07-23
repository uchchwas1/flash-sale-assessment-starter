# Flash Sale API

A high-concurrency flash-sale backend: one limited item, a burst of concurrent buyers, and a
hard guarantee that stock is **never oversold** and **no user buys twice**.

- **Stack:** PHP 8.3 · Laravel 13 · MySQL 8 (InnoDB) · Eloquent · RESTful JSON
- **Layering:** `Controller → FormRequest → Service → Repository → Model → DB`
- **Concurrency:** atomic conditional `UPDATE … WHERE available_stock > 0` (InnoDB row lock)
  + `UNIQUE(item_id, user_id)` + one transaction with deadlock-retry.
- **Redis (optional):** a duplicate-buyer fast path (`SISMEMBER buyers:item:{id} {userId}`)
  rejects repeat buyers before touching MySQL. Advisory only — **degrades gracefully**: if
  Redis is down, the DB remains the authoritative guard, so the app runs fine without it.

🎥 **Video walkthrough:** [Project brief & live output demo](https://drive.google.com/file/d/1tVm_BtI0p-Z1BMM44iM3F9Kg3otynR3J/view?usp=sharing)

See **[SUBMISSION.md](SUBMISSION.md)** for the concurrency strategy, trade-offs, and
production-readiness write-up.

---

## Endpoints

| Method | URL | Purpose | Success | Failure |
|---|---|---|---|---|
| `GET`  | `/items/{id}` | Current stock status | `200` | `404` not found |
| `POST` | `/items/{id}/buy` | Claim one unit — body `{"user_id":"string"}` | `201` | `409` sold out / already purchased · `422` invalid · `404` not found |

All responses use one envelope:
```json
{ "success": true,  "message": "...", "data":   { } }
{ "success": false, "message": "...", "errors": { } }
```

---

## Requirements

- **PHP 8.3** with `pdo_mysql`, `mbstring`, `pcntl` (the concurrency test forks processes)
- **Composer**
- **MySQL 8+**
- **Redis (optional)** — for the duplicate-buyer fast path. The app uses the `predis`
  client (pure PHP, no extension needed) and **works without Redis running** (fast path is
  skipped, DB stays authoritative). To enable it: install Redis (`brew install redis` then
  `redis-server --daemonize yes`) and keep `REDIS_CLIENT=predis` in `.env`.

---

## Setup

```bash
# 1. Install dependencies
composer install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Point .env at your MySQL, then create the database
#    DB_CONNECTION=mysql  DB_HOST=127.0.0.1  DB_PORT=8889
#    DB_DATABASE=flash_sale  DB_USERNAME=root  DB_PASSWORD=root
#    (create the schema `flash_sale` in MySQL first)

# 4. Migrate + seed the flash-sale item (id=1, stock 10)
php artisan migrate:fresh --seed
```

### Run entirely in Docker (no PHP/MySQL/Redis needed on the host)

The stack is fully containerized — app + MySQL 8 + Redis:

```bash
docker compose up --build
```

That's it. The `app` container installs dependencies, waits for MySQL, runs
`migrate:fresh --seed` (item 1, stock 10), and serves on **http://localhost:8000** with real
parallel workers. It talks to MySQL/Redis over the compose network (hosts `mysql` / `redis`),
so no host `.env` changes are required.

> **Host port note:** to avoid clashing with a MySQL/Redis you may already run on the host,
> the containers publish MySQL on **3307** and Redis on **6380** (internally they stay
> 3306/6379, which is all the app uses). Only `:8000` must be free on the host. If you see
> `bind: address already in use`, something else holds that port — free it or change the
> mapping in `docker-compose.yml`.

Verify:
```bash
curl http://localhost:8000/items/1
curl -X POST http://localhost:8000/items/1/buy \
     -H 'Content-Type: application/json' -d '{"user_id":"user_1"}'
```

Run the load test from the host against the container:
```bash
python3 test_concurrency.py            # expects 10 successful / 40 rejected
```

Useful container commands:
```bash
docker compose exec app php artisan flash-sale:reset          # reset DB + Redis
docker compose exec app php artisan flash-sale:clear-buyers   # clear Redis cache only
docker compose exec app php artisan test                      # run the suite (needs a test DB)
docker compose down -v                                        # stop and wipe data
```

> The suite uses a separate `flash_sale_test` database. To run it in Docker, create that DB
> once: `docker compose exec mysql mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS flash_sale_test"`.

---

## Run

```bash
php artisan serve --port=8000
```

- The API is served at `http://localhost:8000` (routes live at the root, e.g. `/items/1`).
- **Free port 8000 first** if another app holds it.

Quick check:
```bash
curl http://localhost:8000/items/1
curl -X POST http://localhost:8000/items/1/buy \
     -H 'Content-Type: application/json' -d '{"user_id":"user_1"}'
```

---

## Tests

### Automated suite (unit + feature + concurrency)
```bash
php artisan test
```
Tests run against a dedicated **`flash_sale_test`** MySQL database (configured in
`phpunit.xml`) — they never touch your dev data. Create it once:
```sql
CREATE DATABASE flash_sale_test CHARACTER SET utf8mb4;
```
The suite includes `tests/Feature/PurchaseConcurrencyTest.php`, which forks 50 real processes
to prove no oversell under genuine parallelism (requires the `pcntl` extension).

### Provided load test (`test_concurrency.py`)
```bash
# 1. Reset to a clean 10-stock state (clears DB *and* Redis)
php artisan flash-sale:reset

# 2. Serve WITH real parallel workers — see the note below
PHP_CLI_SERVER_WORKERS=10 php artisan serve --port=8000 --no-reload

# 3. In another terminal
pip3 install httpx      # if not already installed
python3 test_concurrency.py
```
Expected: **10 successful / 40 rejected**, and the database shows `available_stock = 0` with
`10` orders.


---

## Reset between runs

Each successful run leaves the item sold out. Reset the canonical state with:
```bash
php artisan flash-sale:reset
```
This refreshes + reseeds the DB **and** clears the Redis buyer registry, keeping the two
stores consistent.


### Clear the Redis cache only

If you reset the DB separately (or the fast path is wrongly returning
`409 "already purchased"` for an available item), clear the buyer registry without touching
the database:
```bash
php artisan flash-sale:clear-buyers
```
Equivalent raw Redis command (note Laravel's key prefix `flashsale-database-`):
```bash
redis-cli DEL flashsale-database-buyers:item:1     # one item
redis-cli --scan --pattern 'flashsale-database-buyers:item:*' | xargs redis-cli DEL  # all items
```

---

## Project layout

```
app/
  Http/Controllers/ItemController.php      # thin — delegates to the service
  Http/Requests/BuyItemRequest.php         # user_id validation (trim, max:100)
  Http/Resources/ItemResource.php          # GET response shape
  Http/Middleware/ForceJsonResponse.php    # framework errors render as JSON
  Services/PurchaseService.php             # transaction + deadlock-retry + guards
  Repositories/ItemRepository.php          # atomic conditional decrement
  Repositories/OrderRepository.php         # order insert + lookup
  Exceptions/                              # SoldOut / DuplicatePurchase / ItemNotFound
  Support/ApiResponse.php                  # the JSON envelope
database/migrations/                       # items + orders (CHECKs, UNIQUE, utf8mb4_bin)
database/seeders/ItemSeeder.php            # item id=1, stock 10
routes/api.php                             # /items/{id}, /items/{id}/buy (root prefix)
tests/Feature/                             # repository, service, API, concurrency tests
test_concurrency.py                        # provided load harness
```
