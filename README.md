# E-Commerce Platform API (Requirements 1 + 2)

Laravel 13 (PHP 8.4) REST API, modular MVC, JWT authentication, role-based access, Docker,
plus an independent **inventory service written in Node.js** that the app calls **over gRPC**.

```
                  REST /api/v1 (JSON, JWT)                     gRPC (proto contract)
 Postman / client ───────────────────────────►  Laravel app  ─────────────────────────►  Inventory service (Node.js)
                                                    │                                         │
                                                 MySQL (shop)                          PostgreSQL (inventory)
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
docker compose exec app composer update grpc/grpc google/protobuf   # first time only: installs everything + adds the gRPC packages to composer.lock
# (after composer.lock has been updated and committed, teammates just run: docker compose exec app composer install)
docker compose exec app php artisan key:generate
docker compose exec app php artisan jwt:secret --force
docker compose exec app php artisan migrate --seed
```

API base URL: `http://localhost:8000/api/v1`

Containers: `app` (PHP-FPM), `nginx`, `db` (MySQL), `inventory` (Node.js gRPC on 50051), `inventory-db` (PostgreSQL).
The inventory service creates its tables and seeds stock for products 1–8 on first start.

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
| GET | /orders | customer: own orders, admin: all |
| POST | /orders | authenticated — reserves stock over gRPC first |
| GET | /orders/{id} | owner or admin, otherwise 403 |
| PATCH | /orders/{id}/status | admin only |
| GET | /notifications | authenticated (own only) |
| POST | /notifications/read-all | authenticated |
| PATCH | /notifications/{id}/read | owner only |

## Error format

All errors are JSON: `{"message": "..."}`; validation errors add `"errors": {...}`, insufficient stock adds `"shortages": [...]`.

| Code | When |
|---|---|
| 401 | missing / invalid / logged-out token |
| 403 | role or ownership check failed, unverified email |
| 404 / 405 | unknown resource / wrong HTTP method |
| 409 | not enough stock, product used in orders |
| 422 | validation |
| 429 | rate limit |
| 500 | order could not be saved (reserved stock is returned) |
| 503 / 504 | inventory service down / did not answer before the deadline |

## Security (Requirement 1)

- JWT (HS256) with role claim, 60-minute TTL, refresh and logout (blacklist).
- Role middleware (`role:admin`) and ownership checks for orders and notifications.
- Rate limiting: login 5/min, register 10/min, OTP resend and forgot password 3/min, reset 5/min, 60/min global per user/IP.
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

## API documentation and proof

Import `docs/ecommerce-api.postman_collection.json` into Postman and run it with the Collection Runner (folders 0, 2, 3, 4, 5).

- Folder **5 - PROOF Requirement 1**: another user's order, unauthorized actions, missing/forged token, brute-force login → all refused (403/401/429).
- Folder **6 - PROOF Requirement 2**: inventory stopped (503), slow (504), save failure (500 + stock returned). Each request's description says what to change first.
- Race condition proof (many simultaneous orders for the last unit, exactly one succeeds):

```bash
docker compose exec inventory node scripts/race-test.js
```
