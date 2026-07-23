# Flash Sale API

A high-concurrency flash-sale backend: one limited item, a burst of concurrent buyers, and a
hard guarantee that stock is **never oversold** and **no user buys twice**.

- **Stack:** PHP 8.3 · Laravel 13 · MySQL 8 (InnoDB) · Eloquent · RESTful JSON
- **Layering:** `Controller → FormRequest → Service → Repository → Model → DB`
- **Concurrency:** atomic conditional `UPDATE … WHERE available_stock > 0` (InnoDB row lock)
  + `UNIQUE(item_id, user_id)` + one transaction with deadlock-retry.

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

> On the author's machine PHP 8.3 is invoked as **`php83`** . Use
> whichever binary is PHP 8.3 on yours. MySQL is a host (MAMP) instance on port **8889**,
> user `root` / password `root`. Adjust `.env` to match your setup.

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

### Docker alternative
A `docker-compose.yml` is provided (MySQL 8 + Redis) for reviewers who prefer containers. It
exposes MySQL on **3306** with `dev_user` / `dev_password`; set `DB_PORT=3306` and those
credentials in `.env`, then `docker compose up -d` and run the migrate step above.

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
# 1. Reset to a clean 10-stock state
php artisan migrate:fresh --seed

# 2. Serve WITH real parallel workers — see the note below
PHP_CLI_SERVER_WORKERS=10 php artisan serve --port=8000 --no-reload

# 3. In another terminal
pip3 install httpx      # if not already installed
python3 test_concurrency.py
```
Expected: **10 successful / 40 rejected**, and the database shows `available_stock = 0` with
`10` orders.

> ⚠️ **Real parallelism matters.** `PHP_CLI_SERVER_WORKERS` is only honored with
> **`--no-reload`**. A plain `artisan serve` runs a *single* worker that serialises requests,
> which would make the concurrency test pass even against broken code. Always use the flags
> above (or nginx + PHP-FPM / Laravel Octane) when load-testing.

---

## Reset between runs

Each successful run leaves the item sold out. Reset the canonical state with:
```bash
php artisan migrate:fresh --seed
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
