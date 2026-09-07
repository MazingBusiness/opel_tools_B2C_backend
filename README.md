# Mazing B2C backend

Laravel shop API for the Mazing B2C React store. Own database. Payments on this app; fulfilment on B2B.

Plan: [docs/b2c-backend-plan.md](docs/b2c-backend-plan.md)  
B2B scan map: [docs/b2b-reference-map.md](docs/b2b-reference-map.md)

## Requirements

- PHP 8.3+ (`brew install php composer` if needed)
- Composer

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Local default DB is SQLite (`database/database.sqlite`). Production uses MySQL (see `.env.example`).

## Check

- `GET /up` — framework health
- `GET /api/v1/health` — API v1 ping

```bash
php artisan test
```
