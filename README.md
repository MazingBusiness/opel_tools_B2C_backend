# OPEL B2C backend

Laravel shop API for the OPEL B2C React store. Own database. Payments on this app; fulfilment on B2B.

Plan: [docs/b2c-backend-plan.md](docs/b2c-backend-plan.md)  
B2B scan map: [docs/b2b-reference-map.md](docs/b2b-reference-map.md)  
React Google login: [docs/react-firebase-google-auth.md](docs/react-firebase-google-auth.md)

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

## Auth (`/api/v1/auth`)

One-step OTP login/signup (email or Indian mobile). User is created on successful verify, not on request. Google uses Firebase in React; this API verifies the ID token.

| Method | Path | Auth |
|--------|------|------|
| POST | `/api/v1/auth/otp/request` | public — `{ "identifier": "you@email.com" }` or `"9876543210"` |
| POST | `/api/v1/auth/otp/verify` | public — `{ "identifier": "...", "code": "123456" }` returns Sanctum `token` and `profile_complete` |
| POST | `/api/v1/auth/google` | public — `{ "id_token": "<Firebase ID token>" }` same token payload as OTP verify |
| GET | `/api/v1/auth/me` | Bearer |
| PATCH | `/api/v1/auth/profile` | Bearer — `name`, `email`, `phone`, `avatar` (URL text) |
| POST | `/api/v1/auth/logout` | Bearer |

Email OTPs use Laravel Mail (`MAIL_MAILER=log` locally). Phone OTPs use [SMS Alert](https://www.smsalert.co.in/) (`SMSALERT_API_KEY`, `SMSALERT_SENDER`). If those env values are empty, the code is written to the application log.

Google: set `FIREBASE_CREDENTIALS` and `FIREBASE_PROJECT_ID`. React steps: [docs/react-firebase-google-auth.md](docs/react-firebase-google-auth.md).

## API docs (local)

Interactive OpenAPI UI (Scramble): [http://127.0.0.1:8000/docs/api](http://127.0.0.1:8000/docs/api) after `php artisan serve`. Spec JSON: `/docs/api.json`. Available only when `APP_ENV=local`.

Try OTP from the docs, copy the code from `storage/logs/laravel.log`, verify, then Authorize with the returned Bearer token for `/me` and `/profile`.

## Check

- `GET /up` — framework health
- `GET /api/v1/health` — API v1 ping
- `GET /docs/api` — API docs UI (local)

```bash
php artisan test
```
