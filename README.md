# Sukoon API (Laravel)

Backend API for the Sukoon rental platform.

## Requirements

- PHP 8.2+
- Composer
- SQLite (default) or MySQL

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
```

## Run

```bash
php artisan serve
```

Recommender SQLite sync:

```bash
php artisan recommender:sync
```

## API documentation

Every HTTP endpoint (auth, apartments, contracts, payments, admin, ML proxy) is documented in **[API_REFERENCE.md](./API_REFERENCE.md)** — request bodies, validation rules, response shapes, and error codes.

## Related services (not in this repo)

| Service | Port | Laravel path |
|---------|------|----------------|
| ID verification (ML) | 8001 | `/api/ml/*` |
| Python recommender | 8002 | `/api/recommender/*` and `POST /api/recommendations` |

Configure Paymob and FCM in `.env` for payments and push notifications.
