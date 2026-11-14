# E-Commerce Platform API (Requirements 1 + 2 + 3)

Laravel 13 (PHP 8.4) REST API, modular MVC, JWT authentication, role-based access, Docker,
plus an independent **inventory service written in Node.js** that the app calls **over gRPC**,
and a **performance layer** (Requirement 3): HTTP ETag, Redis cache, indexes / no N+1, MySQL read replica, k6 load tests.

```
                REST /api/v1 (JSON, JWT, ETag)                  gRPC (proto contract)
 Browser / client ──────────────────────────►  Laravel app  ─────────────────────────►  Inventory service (Node.js)
                                               │   │    │                                     │
                                  cache        │   │    │ reads (SELECT)                PostgreSQL (inventory)
                              Redis ◄──────────┘   │    └──────────► MySQL read replica (db-replica)
                                                   │ writes                    ▲
                                                   └──────────► MySQL primary (db) ── replication (GTID)
```

## Modules (`src/Modules`)

| Module | Responsibility |
|---|---|
| Users | Sign up (email OTP), login, logout, refresh, forgot/reset password (OTP), roles |
| Products | Product CRUD, image upload, search, category filter, sorting, pagination, stock on details page |
| Orders | Create orders (reserving stock over gRPC), list/show own orders, admin changes status |
| Notifications | In-app notifications for order events |
| Inventory | gRPC client for the inventory service (generated classes, gateway, deadline, errors) |

Each module has its own `Models`, `Http` (Controllers/Requests/Resources), `Routes`, `Database` (migrations, seeders),
`Services` and **its own service provider** (`Modules/<Name>/Providers`), which registers the module's routes (under `/api/v1`),
migrations, config, contract bindings and event listeners. `bootstrap/providers.php` only lists those providers.

## Modular architecture (module boundaries)

Modules are not just folders: each module owns its data and the others reach it **only through its public API**
(`Contracts`, `Events`, `Exceptions`), never through its models, services or tables.

| Module | Public API (what other modules may use) | Uses from other modules |
|---|---|---|
| Users | `Contracts\UserDirectory` (admin ids), Gate permission `admin` | — |
| Products | `Contracts\ProductCatalog` + `ProductSummary`, `Events\ProductDeleting`, `Exceptions\ProductInUseException` | Inventory: `InventoryGateway` |
| Inventory | `Contracts\InventoryGateway`, `Exceptions\InventoryUnavailableException` (gRPC is internal) | — |
| Orders | `Events\OrderPlaced`, `Events\OrderStatusChanged` | Products: `ProductCatalog`, listens to `ProductDeleting`; Inventory: `InventoryGateway`; Users: Gate `admin` |
| Notifications | — (only reacts to events) | listens to `OrderPlaced`, `OrderStatusChanged`; Users: `UserDirectory` |

- **Events instead of direct calls:** Orders publishes `OrderPlaced` / `OrderStatusChanged`; Notifications subscribes. Orders does not know Notifications exists.
- **Own data only:** order items store a snapshot of the product name and price at order time, so Orders never reads the products table.
  Product existence is validated through `ProductCatalog`, not with an `exists` rule on another module's table.
- **No cross-module foreign keys:** `orders.user_id`, `order_items.product_id` and `user_notifications.user_id` are plain indexed ids.
  Rules between modules go through their APIs (e.g. deleting a product fires `ProductDeleting`; Orders vetoes it if the product was ordered → 409).
- **Proof:** `tests/Unit/ModuleBoundariesTest.php` fails if a module imports another module's internals, reads another module's table,
  or has no provider of its own. Run `docker compose exec app php artisan test`.

## Run with Docker

```bash
cp src/.env.example src/.env          # Windows: copy src\.env.example src\.env
docker compose up -d --build          # first build compiles the gRPC PHP extension (several minutes)
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan jwt:secret --force
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan optimize
docker compose restart app
```

API base URL: `http://localhost:8000/api/v1`

Containers: `app` (PHP-FPM), `nginx`, `db` (MySQL **primary**), `db-replica` (MySQL **read replica**),
`db-replica-setup` (one-shot job that connects the replica on every `up`), `redis` (cache),
`inventory` (Node.js gRPC on 50051), `inventory-db` (PostgreSQL), `phpmyadmin` (http://localhost:8090, servers `db` and `db-replica`).
The inventory service creates its tables and seeds stock for products 1–8 on first start.

**Upgrading an existing Requirement 1 + 2 installation** (keeps the data; the replica copies it automatically):

```bash
docker compose down                   # NOT "down -v": that would delete the databases
docker compose build app              # adds the Redis PHP extension (gRPC layer is reused)
docker compose up -d                  # starts redis, db-replica and the replica setup job
docker compose exec app composer install
docker compose exec app php artisan migrate        # Requirement 3 indexes
docker compose exec app php artisan optimize
docker compose restart app
```

OTP emails: by default `MAIL_MAILER=log`, so the 6-digit code is written to `src/storage/logs/laravel.log`:

```bash
docker compose exec app sh -c "grep -a 'Your code is' storage/logs/laravel.log | tail -n 1"
```

## Seeded accounts

| Role | Email | Password |
|---|---|---|
| Admin | admin@shop.test | Admin12345 |
| Customer | customer@shop.test | Customer12345 |
| Customer | customer2@shop.test | Customer12345 |

## Web interface (Blade)

Open `http://localhost:8000`. Pages live in `src/resources/views/pages`, routes in `src/routes/web.php`,
shared code in `src/public/js/shop.js` and `src/public/css/shop.css`.
The pages contain no business logic: they call the REST API (`/api/v1`) with `fetch` and the JWT saved at login,
so every security rule (401/403/429...) is still enforced by the API.

| Page | APIs used |
|---|---|
| /login, /register, /verify-email, /forgot-password | auth/* |
| /products (search, category, sort, pagination) | products, products/categories |
| /products/{id} (stock from inventory, order now, add to cart) | products/{id}, orders |
| /cart (one order for many products) | orders |
| /orders, /orders/{id} | orders, orders/{id} |
| /notifications | notifications/* |
| /admin/products (create/edit with image/delete) | products POST/PATCH/DELETE |
| /admin/orders (change status) | orders, orders/{id}/status |

## Endpoints (all under `/api/v1`)

Headers: `Accept: application/json`, and for protected routes `Authorization: Bearer <token>`.

| Method | Path | Access |
|---|---|---|
| POST | /auth/register | public (sends OTP) |
| POST | /auth/verify-email | public (returns token) |
| POST | /auth/resend-otp | public |
| POST | /auth/login | public (verified users only) |
| POST | /auth/forgot-password | public (sends OTP) |
| POST | /auth/reset-password | public (OTP + new password) |
| GET | /auth/me | authenticated |
| POST | /auth/refresh | authenticated |
| POST | /auth/logout | authenticated |
| GET | /products | public (`search`, `category`, `min_price`, `max_price`, `sort`, `per_page`, `page`) |
| GET | /products/categories | public |
| GET | /products/{id} | public — includes `stock` from the inventory service |
| POST | /products | admin (multipart, optional `image`) |
| POST/PUT/PATCH | /products/{id} | admin |
| DELETE | /products/{id} | admin (409 if used in orders) |
| GET | /orders | customer: own orders, admin: all (`status`, `per_page` up to 50) |
| GET | /orders/stats | admin: sales report of the last `days` (default 30) |
| POST | /orders | authenticated — reserves stock over gRPC first |
| GET | /orders/{id} | owner or admin, otherwise 403 |
| PATCH | /orders/{id}/status | admin only |
| GET | /notifications | authenticated (own only) |
| POST | /notifications/read-all | authenticated |
| PATCH | /notifications/{id}/read | owner only |

## Error format (unified)

**Every** API error has the same shape, built in one place (`src/app/Http/ApiError.php`, wired in `src/bootstrap/app.php`):

```json
{
  "status": 409,
  "code": "INSUFFICIENT_STOCK",
  "message": "Not enough stock for: Bluetooth Speaker (requested 3, available 1). The order was not created.",
  "details": { "shortages": [ { "product_id": 8, "requested": 3, "available": 1 } ] }
}
```

- `status`, `code`, `message` are always present; `status` equals the HTTP status code.
- `errors` (field → messages) only for validation errors; `details` only when there is extra data.
- No stack trace, file or exception class is ever returned (with `APP_DEBUG=true` a 500 adds `details.debug` with the exception class and location only).
- Errors produced by nginx itself (413 request too large, 502 application down) are JSON in the same format.

| HTTP | code | When |
|---|---|---|
| 401 | `UNAUTHENTICATED` / `INVALID_CREDENTIALS` | missing / invalid / logged-out token; wrong email or password |
| 403 | `FORBIDDEN` / `EMAIL_NOT_VERIFIED` | role or ownership check failed; unverified account |
| 404 | `NOT_FOUND` | unknown resource or route |
| 405 | `METHOD_NOT_ALLOWED` | wrong HTTP method |
| 409 | `INSUFFICIENT_STOCK` / `PRODUCT_IN_USE` | not enough stock (`details.shortages`); product used in orders |
| 413 | `PAYLOAD_TOO_LARGE` | request body over 3 MB (nginx) |
| 422 | `VALIDATION_FAILED` / `INVALID_CODE` | invalid input (`errors`); wrong or expired OTP |
| 429 | `TOO_MANY_REQUESTS` | rate limit (`details.retry_after_seconds` + `Retry-After` header) |
| 500 | `ORDER_NOT_SAVED` / `INTERNAL_ERROR` | order not saved (stock returned); unexpected error |
| 502 | `BAD_GATEWAY` | application server down (nginx) |
| 503 / 504 | `INVENTORY_UNAVAILABLE` / `INVENTORY_TIMEOUT` | inventory service down / no answer before the deadline |

Proof: `tests/Feature/ErrorFormatTest.php` checks every row of this table.

## Security (Requirement 1)

- JWT (HS256) with role claim, 60-minute TTL, refresh and logout (blacklist).
- Role middleware (`role:admin`) and ownership checks for orders and notifications.
- Rate limiting: login 5/min **per account (email + IP)**, so only the attacked account is blocked, plus 30/min per IP against trying many accounts; register 10/min, OTP resend and forgot password 3/min, reset 5/min, 60/min global per user/IP.
- OTP: 6 digits, stored hashed, expires in 10 minutes, deleted after 5 wrong attempts.
- Password policy: minimum 8 characters with letters and numbers.
- Security headers: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, Content-Security-Policy (API), HSTS (HTTPS), no-store on auth responses; nginx hides its version.
- Input validation with Form Requests; image upload limited to jpg/png/webp up to 2 MB.

## Inter-service communication (Requirement 2)

- **Contract**: `proto/inventory/v1/inventory.proto` — 3 operations: `GetStock`, `ReserveStock`, `ReleaseStock`.
- **Inventory service**: `inventory-service/` (Node.js, `@grpc/grpc-js`, PostgreSQL). Own database, talks to nobody else.
- **Laravel client**: `src/Modules/Inventory` (classes generated from the proto + `GrpcInventoryGateway`). Every call has a deadline (`INVENTORY_GRPC_TIMEOUT_MS`, default 2000 ms).
- **Product details**: `GET /products/{id}` asks `GetStock` and returns `stock.available_quantity`. If the service is down, the product is still returned with `available_quantity: null` and a message.
- **Create order**: one `ReserveStock` call for all items (all-or-nothing) → accepted: the order is saved; rejected: 409 with the shortages, nothing created.
- **Failures**: not enough stock → 409; service down → 503; too slow → 504 at the deadline; order save fails after reserving → `ReleaseStock` returns the quantity, 500.
- **Race condition**: each item is taken with one atomic `UPDATE stock SET quantity = quantity - n WHERE product_id = ? AND quantity >= n`; PostgreSQL row locks make concurrent requests for the last unit succeed exactly once.

Regenerate the PHP classes after changing the proto (needs `protoc`):

```bash
protoc -I proto --php_out=src proto/inventory/v1/inventory.proto
```

Manage stock (helper script, not part of the contract):

```bash
docker compose exec inventory node scripts/set-stock.js            # show stock
docker compose exec inventory node scripts/set-stock.js 9 100      # product 9 -> 100 units
```

## Performance (Requirement 3)

Four layers, from the user to the database. Rule followed: **measure → improve one thing → measure**.

| Layer | What | Where |
|---|---|---|
| 1. HTTP cache | `ETag` on every cacheable GET; `If-None-Match` → **304 Not Modified** with an empty body. `Cache-Control: public, no-cache` for the catalog, `private, no-cache` for orders. Browsers do it automatically (DevTools shows 304). | `app/Http/Middleware/ETag.php`, `etag:` middleware on the routes |
| 2. Server cache | Redis, cache-aside. Product lists (60 s), product data (300 s), categories (600 s), sales report (60 s), admin order count (30 s). **Invalidation:** every product write changes a version key, so all old entries are ignored at once. **Never cached:** stock (live over gRPC), prices used to create an order (read from the primary), personal data. Redis down → the API still works from MySQL. | `Modules/Products/Services/ProductCache.php`, `Modules/Orders/Services/SalesReport.php`, `config/performance.php` |
| 3. Database | Large fake data (`perf:seed`: 2,000 customers, 100,000 products, 300,000 orders / 750,000 items). Slow queries found with `EXPLAIN` (`perf:explain`), fixed with indexes: products `(category, price)`, `(price)`, `(name)` (and the redundant `(category)` removed), orders `(created_at, status, total)` (covering index). **N+1** on the orders list fixed with eager loading (`perf:nplus1`: 52 → 3 queries for 50 orders); an N+1 detector (`preventLazyLoading`) fails any test that lazy loads a relation in a list. `perf:explain --compare` also measures the cost of the indexes on writes. | `Modules/*/Database/Migrations/2026_11_12_*`, `OrderController@index` |
| 4. Read replica | `db` (primary, all writes) → GTID replication → `db-replica` (read-only, all SELECTs). Laravel `read` / `write` / `sticky`. **Read-your-writes:** after a write, the GTID of the primary is stored in Redis; reads of that data use the primary until the replica has executed that GTID (`GTID_SUBSET`), then the replica again. Checkout prices, admin writes, account data (login, tokens, OTP) and the delete check always read the primary; a row not found on the replica is looked up once more on the primary before a 404. | `config/database.php`, `app/Support/ReadYourWrites.php`, `docker/mysql/setup-replica.sh` |

**Commands** (inside the app container: `docker compose exec app php artisan ...`):

| Command | What it does |
|---|---|
| `perf:seed` | large fake data (options `--users --products --orders`) |
| `perf:explain --compare` | `EXPLAIN` + median time of the slow queries without / with the indexes |
| `perf:nplus1` | SQL query count of `GET /orders?per_page=50`: lazy (N+1) vs eager |
| `perf:replica` | which server answers reads / writes, row counts on both, read-only check, replication lag |
| `perf:mode baseline\|optimized` | switch all optimizations off / on (also one at a time: `--cache= --etag= --eager= --indexes=`), `--load-test=on` lifts the API rate limit (local only) |
| `perf:clean` | delete the fake data again |

**Load test (k6)** — `perf/k6/load-test.js`, a realistic read-heavy mix (product lists, details with live stock, returning
visitors with `If-None-Match`, customer orders, admin orders, sales report). `demo.cmd perf` runs it twice with the same
data (baseline, then optimized) and writes the comparison table (p50 / p95 / p99 / requests per second / errors per endpoint)
to `perf/results/report.md`. `demo.cmd perfsteps` measures after each single improvement.

**Proof that a change is visible immediately** despite the cache and the replica: `tests/Feature/PerformanceTest.php`,
`demo.cmd cache`, `demo.cmd etag`, and `demo.cmd replica` (pauses the replica, edits a product, the API shows the new data while
the replica still has the old row).

## Tests and demo

**Automated tests** (in-memory SQLite + a fake inventory service, a few seconds):

```bash
demo.cmd tests      # = optimize:clear, php artisan test, optimize (tests refuse to run on a cached config)
```

| File | What it proves |
|---|---|
| `tests/Feature/AuthorizationTest.php` | 401 without / with forged token, 403 for another user's order and admin-only actions, owner/admin access, notifications, logout, unverified login, login rate limit (429) |
| `tests/Feature/ApiDesignTest.php` | `/api/v1` versioning, pagination, category filter, 422/404/405 JSON errors, security headers, 409 on deleting an ordered product |
| `tests/Feature/ErrorFormatTest.php` | every error type (401, 403, 404, 405, 409, 422, 429, 500, 503, 504) has the unified shape and no stack trace |
| `tests/Feature/InventoryTest.php` | stock on product details, inventory down (200 product / 503 order), timeout (504), insufficient stock (409), all-or-nothing, stock returned when saving fails (500), product-name snapshot |
| `tests/Feature/PerformanceTest.php` | Redis cache hit (no SQL), stock never cached, changes / deletes visible immediately, ETag 304 → 200 after a change, private cache for orders, no N+1 (and the N+1 detector), baseline mode, sales report, read-your-writes scopes, account data from the primary |
| `tests/Unit/ModuleBoundariesTest.php` | modules only use each other's public API, never each other's tables, each has its own provider |

**Live demo** against the running stack, one command per scenario (Windows, project folder):

```
demo.cmd            # list of scenarios
demo.cmd auth       # Req 1: JWT, roles, ownership (401 / 403)
demo.cmd api        # Req 1: versioning, pagination, filters, JSON errors, security headers
demo.cmd stock      # Req 2: stock over gRPC, 409, all-or-nothing reservation
demo.cmd down       # Req 2: inventory stopped -> product page works, order 503
demo.cmd slow       # Req 2: inventory 5 s slow -> order 504 at the 2 s deadline
demo.cmd savefail   # Req 2: save fails after reserving -> 500, stock returned
demo.cmd race       # Req 2: 10 simultaneous orders for the last unit -> exactly one succeeds
demo.cmd ratelimit  # Req 1: 6 wrong logins -> 429 (run last)
demo.cmd seed       # Req 3: large fake data (once)
demo.cmd explain    # Req 3: EXPLAIN before / after the indexes
demo.cmd nplus1     # Req 3: N+1 vs eager loading (query count)
demo.cmd cache      # Req 3: Redis hit / miss, invalidation on change
demo.cmd etag       # Req 3: ETag -> 304, 200 after a change
demo.cmd replica    # Req 3: primary / replica, lag, read-your-writes with the replica paused
demo.cmd perf       # Req 3: k6 before / after -> perf/results/report.md
demo.cmd tests      # automated tests
demo.cmd reset      # restore the initial stock
```

## API documentation and proof

Import `docs/ecommerce-api.postman_collection.json` into Postman and run it with the Collection Runner (folders 0, 2, 3, 4, 5, 7).

- Folder **5 - PROOF Requirement 1**: another user's order, unauthorized actions, missing/forged token, brute-force login → all refused (403/401/429).
- Folder **6 - PROOF Requirement 2**: inventory stopped (503), slow (504), save failure (500 + stock returned). Each request's description says what to change first.
- Folder **7 - PROOF Requirement 3**: ETag / 304, cache invalidated immediately after an edit, private cache for orders, sales report.
- Race condition proof (many simultaneous orders for the last unit, exactly one succeeds):

```bash
docker compose exec inventory node scripts/race-test.js
```
