# Notes: Mini Webhook Dispatcher (PHP)

Design note before the code (PLAN Stage 1); finalized in Stage 8. IDs are from `docs/PRD.md`.

## Answers from the interviewer

- AI tools are allowed: the code is written together with Claude Code (`Co-Authored-By` trailer on commits).
- No deploy target: the local `docker compose` stack is the demo. CI runs the unit tests and builds the image.
- At-least-once is fine (receivers dedupe by `X-Webhook-Id`). The backoff formula wins over `Retry-After`. `tenant_id` stays `default`.

## How each requirement will be done

Postgres is the queue (the starter's `events` table, unchanged). The API (`php -S`) and the worker are separate processes that share only the database.

- **M1** Decode the body as an object, so `{}` stays `{}`; `INSERT … RETURNING id` → `202 {"id"}`.
- **M2** Not a JSON object, `url` not absolute http(s), empty `type`, or `payload` not an object → `400 {"error"}`, nothing stored.
- **M3** curl POST with a 10 s total timeout, no redirects; `curl_exec()` returning `false` counts as code 0.
- **M4** `GET /health` is already there: `200`, or `503` when the DB is down.
- **S1** One pure function, `Outcome::status($code, $attempt)`: 2xx → `sent`; 0, 408, 429, 5xx → `retry`; 410, other 4xx, 3xx → `dead`.
- **S2** `attempts` counts failures; failure n waits `min(60·2^(n−1), 21600)·(0.5 + rand)` s (`Backoff::seconds`); the 8th → `dead`.
- **S3** `X-Webhook-Id`, `X-Webhook-Timestamp` (new per attempt), `X-Webhook-Signature: t=<ts>,v1=<hex HMAC-SHA256(secret, ts.body)>`, and `Content-Type: application/json` (in BRIEF; PRD S3 gets it in Stage 4). No `WEBHOOK_SECRET` → the worker exits at start.
- **S4** The body `{"id", "type", "payload", "created_at"}` comes from the stored row, so the id never changes between attempts.
- **S5** One statement claims one due event and leases it for 60 s via `next_attempt_at` (`FOR UPDATE SKIP LOCKED`, PRD decision 2); no transaction stays open during the HTTP call. The lease marks the row as in flight, so there is no `in_progress` status and `locked_until` stays unused.
- **S6** `Backoff`, `Signer`, `Outcome` first: `vendor/bin/phpunit` → `OK (7 tests, 19 assertions)`.

## Failure modes

| What fails | What happens |
|---|---|
| Worker dies between the POST and the update | The lease expires after 60 s and the event goes out again (at-least-once). |
| Receiver is slow | curl gives up after 10 s → retry with backoff. The 60 s lease outlasts that, so a live worker keeps its event. |
| DB is down | API: `/health` 503, `POST /events` `500 {"error": "internal error"}`, details only in the log. Worker: exits on the PDO error, compose restarts it (`restart: unless-stopped`, Stage 6). |
| `WEBHOOK_SECRET` missing | The worker exits with code 1. Caveat: compose falls back to a dev-only value; a real deploy should fail instead (`${WEBHOOK_SECRET:?}`). |

## Trade-offs: what would change, and when

- `sleep(1)` polling → `LISTEN/NOTIFY` when latency matters; Postgres → a broker at sustained high throughput.
- Backoff ignores `Retry-After` → wait `max(backoff, Retry-After)` once customers rate-limit us.
- `php -S` → nginx + php-fpm or FrankenPHP in production.
- `payload` is `JSONB`: the same JSON value comes out, not the same key order or whitespace.
- Stretch: X1 graceful shutdown (checked), X3 `/metrics` and X2 replay are done. **Cut:** X4 SSRF guard, because a check only when the event is accepted misses DNS rebinding; the real fix resolves, checks and pins the address (`CURLOPT_RESOLVE`) in the worker on every delivery. X5 circuit breaker: per-host failure counts with a cool-down, after X4.

## How to run

`docker compose up -d --build`, then `tests/run-all.sh` (unit → stack → functional → e2e). More in `docs/CHECKLIST.md`.
