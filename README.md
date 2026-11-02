# Webhook Dispatcher: PHP starter

Read `BRIEF.md` first. Stack: PHP 8.3, plain PHP (no framework), PDO pgsql, PHPUnit 11.

PHP has no background threads, so the **worker is a separate process** (`bin/worker.php`) and a separate `worker` service in compose. That also makes the "two workers must not double-deliver" requirement easy to test.

## Run the tests (they fail until you implement `src/Backoff.php` and `src/Signer.php`)

```bash
composer install
vendor/bin/phpunit
```

## Run everything locally

```bash
docker compose up --build
docker compose up --scale worker=2   # to test concurrent workers
./smoke.sh                           # in another terminal
```

API: http://localhost:8080, receiver: http://localhost:9000, Postgres: localhost:5432.

## Layout

| Path | Purpose |
|---|---|
| `public/index.php` | HTTP API router (`/health` works, `POST /events` is a stub) |
| `bin/worker.php` | Delivery loop (stub), runs as its own process |
| `src/Backoff.php`, `src/Signer.php` | Pure functions with the failing tests |
| `src/Db.php` | PDO connection from `DATABASE_URL` |
| `tests/LibTest.php` | Unit tests to make pass |

Environment variables: `DATABASE_URL`, `WEBHOOK_SECRET`.
