# E-Commerce Platform API (Requirement 1: API Design & Security)

Laravel 13 (PHP 8.4) REST API, modular MVC, JWT authentication, role-based access, Docker.

## Modules (`src/Modules`)

| Module | Responsibility |
|---|---|
| Users | Sign up (email OTP), login, logout, refresh, forgot/reset password (OTP), roles |
| Products | Product CRUD, image upload, search, category filter, sorting, pagination |
| Orders | Create orders, list/show own orders, admin changes status |
| Notifications | In-app notifications for order events |

Each module has its own `Models`, `Http` (Controllers/Requests/Resources), `Routes`, `Database/Migrations` and `Services`. Routes and migrations are loaded automatically by `App\Providers\ModulesServiceProvider`.

## Run with Docker

```bash
cp src/.env.example src/.env          # Windows: copy src\.env.example src\.env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan jwt:secret --force
docker compose exec app php artisan migrate --seed
```

API base URL: `http://localhost:8000/api`

OTP emails: by default `MAIL_MAILER=log`, so the 6-digit code is written to `src/storage/logs/laravel.log`:

```bash
docker compose exec app sh -c "grep -a 'Your code is' storage/logs/laravel.log | tail -n 1"
```

To send real emails, set `MAIL_MAILER=smtp` and the SMTP settings in `src/.env`.

## Seeded accounts

| Role | Email | Password |
|---|---|---|
| Admin | admin@shop.test | Admin12345 |
| Customer | customer@shop.test | Customer12345 |
| Customer | customer2@shop.test | Customer12345 |

## Endpoints

Auth header for protected routes: `Authorization: Bearer <token>` and `Accept: application/json`.

| Method | Path | Access |
|---|---|---|
| POST | /api/auth/register | public (sends OTP) |
| POST | /api/auth/verify-email | public (returns token) |
| POST | /api/auth/resend-otp | public |
| POST | /api/auth/login | public (verified users only) |
| POST | /api/auth/forgot-password | public (sends OTP) |
| POST | /api/auth/reset-password | public (OTP + new password) |
| GET | /api/auth/me | authenticated |
| POST | /api/auth/refresh | authenticated |
| POST | /api/auth/logout | authenticated |
| GET | /api/products | public (`search`, `category`, `min_price`, `max_price`, `sort`, `per_page`) |
| GET | /api/products/categories | public |
| GET | /api/products/{id} | public |
| POST | /api/products | admin (multipart, optional `image`) |
| POST/PUT/PATCH | /api/products/{id} | admin |
| DELETE | /api/products/{id} | admin |
| GET | /api/orders | customer: own orders, admin: all |
| POST | /api/orders | authenticated |
| GET | /api/orders/{id} | owner or admin, otherwise 403 |
| PATCH | /api/orders/{id}/status | admin only |
| GET | /api/notifications | authenticated (own only) |
| POST | /api/notifications/read-all | authenticated |
| PATCH | /api/notifications/{id}/read | owner only |

## Error format

All errors are JSON: `{"message": "..."}`, validation errors add `"errors": {...}`.
Codes used: 401 (no/invalid token), 403 (not allowed), 404, 405, 409, 422 (validation), 429 (rate limit).

## Security

- JWT (HS256) with role claim, 60-minute TTL, refresh and logout (blacklist).
- Role middleware (`role:admin`) and ownership checks for orders and notifications.
- Rate limiting: login 5/min, register 10/min, OTP resend and forgot password 3/min, reset 5/min, 60/min global per user/IP.
- OTP: 6 digits, stored hashed, expires in 10 minutes, deleted after 5 wrong attempts.
- Password policy: minimum 8 characters with letters and numbers.
- Security headers: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, Content-Security-Policy (API), HSTS (HTTPS), no-store on auth responses.
- Input validation with Form Requests; image upload limited to jpg/png/webp up to 2 MB.

## API documentation and proof

Import `docs/ecommerce-api.postman_collection.json` into Postman.
Folder "5 - PROOF" proves that accessing another user's order or performing an unauthorized action is rejected (403/401). Run it with the Collection Runner.