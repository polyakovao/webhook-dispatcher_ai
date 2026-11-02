# Notes: Mini Webhook Dispatcher (PHP)

A producer posts an event and gets `202` with its id; a worker delivers it to the customer's URL, signed, retries with backoff and gives up after 8 attempts. Postgres is the queue; the API (`php -S`) and the worker are separate processes that share only the database. Written together with Claude Code. IDs are from `docs/PRD.md`, the stage log is in `docs/PLAN.md`.

The interviewer allowed AI tools and gave no deploy target, so the local `docker compose` stack is the demo. At-least-once is fine (receivers dedupe by `X-Webhook-Id`), the backoff formula wins over `Retry-After`, and `tenant_id` stays `default`.

## What was built

- **M1** `POST /events` decodes the body as an object, so `{}` stays `{}`; `INSERT … RETURNING id` → `202 {"id"}`.
- **M2** Not a JSON object, `url` not absolute http(s), empty `type`, or `payload` not an object → `400 {"error"}`, nothing stored.
- **M3** curl POST with a 10 s total timeout, no redirects; `curl_exec()` returning `false` counts as code 0.
- **M4** `GET /health`: `200`, or `503` when the DB is down.
- **S1** `Outcome::status($code, $attempt)`: 2xx → `sent`; 0, 408, 429, 5xx → `retry`; 410, other 4xx, 3xx → `dead`.
- **S2** `attempts` counts failures; failure n waits `min(60·2^(n−1), 21600)·(0.5 + rand)` s; the 8th → `dead`.
- **S3** `Content-Type: application/json`, `X-Webhook-Id`, `X-Webhook-Timestamp`, `X-Webhook-Signature: t=<ts>,v1=<hex HMAC-SHA256(secret, ts.body)>`. No `WEBHOOK_SECRET` → the worker exits at start.
- **S4** The body `{"id", "type", "payload", "created_at"}` comes from the stored row, so the id never changes between attempts.
- **S5** One statement claims one due event and leases it for 60 s via `next_attempt_at` (`FOR UPDATE SKIP LOCKED`, PRD decision 2): no transaction stays open during the POST, no `in_progress` status.
- **S6** Unit `OK (7 tests, 19 assertions)`, functional `OK (7 tests, 46 assertions)`, e2e `ALL PASS`.
- **Stretch** X1 graceful stop (checked: a stop during a 10 s delivery records the result), X3 `GET /metrics`, X2 `POST /events/{id}/replay`. CI: unit tests, image build, Trivy scan, GHCR push on `main`.

## Failure modes

| What fails | What happens |
|---|---|
| Worker dies between the POST and the update | The lease expires after 60 s and the event goes out again (at-least-once). |
| Receiver is slow | curl gives up after 10 s → retry with backoff. The 60 s lease outlasts that. |
| DB is down | API: `/health` 503, `POST /events` 500 without details. Worker: exits on the PDO error; `restart: unless-stopped` brings it back (checked). |
| `WEBHOOK_SECRET` missing | The worker exits with code 1. Compose falls back to a dev-only value; a real deploy should fail instead (`${WEBHOOK_SECRET:?}`). |

## Cut, and known gaps

- **X4 SSRF guard, cut:** a check only when the event is accepted misses DNS rebinding; the real fix resolves, checks and pins the address (`CURLOPT_RESOLVE`) in the worker on every delivery. **X5 circuit breaker, cut:** per-host failure counts with a cool-down, after X4.
- The e2e two-worker check can pass with one worker doing all the work: it drains 20 events before the other wakes from `sleep(1)`. A manual run of 1000 events split 459/541 with no duplicate.
- A payload with `\u0000` answers 500: PHP accepts it, `JSONB` does not. `JSONB` also drops key order and whitespace.
- No auth on any endpoint, replay included. `/metrics` counts the whole table on every scrape.

## Next steps

- `LISTEN/NOTIFY` instead of polling, then batched claims and a partial index on due events.
- Wait `max(backoff, Retry-After)` on 429; nginx + php-fpm or FrankenPHP instead of `php -S`.
- X4 and X5 as above, auth for the API, and the e2e run in CI.

## How to run

`docker compose up -d --build`, then `tests/run-all.sh` (unit → stack → functional → e2e with two workers, about 25 s). More in `docs/CHECKLIST.md`.
