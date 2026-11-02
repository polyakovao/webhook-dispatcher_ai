# PRD: Mini Webhook Dispatcher (PHP)

`BRIEF.md` is the source. This file restates it as numbered requirements with acceptance checks and records the design decisions. If the two disagree, BRIEF wins and this file gets fixed.

## 1. Goal

A producer posts an event and gets an answer right away. We store the event durably and deliver it to the customer's URL in the background. Customer endpoints can be slow, flaky, rate-limited or gone, so delivery needs timeouts, retries, a signature, and protection against concurrent workers delivering the same event twice. Success means BRIEF's Must and Should tiers pass the checks below within the 60-minute time box.

## 2. Actors

- **Producer**: calls `POST /events`.
- **Customer endpoint**: receives signed `POST`s, verifies the signature, dedupes by event id. Locally this is `receiver/` on :9000.
- **Operator**: runs the service and reads the logs and the `events` table.

## 3. Requirements

Checks marked *e2e* are lines printed by `tests/e2e.sh`; *functional* are tests in `tests/Functional/`; *unit* are tests in `tests/LibTest.php`.

### Must

| ID | Requirement | Check |
|---|---|---|
| M1 | `POST /events` with `{"url", "type", "payload"}` stores a `pending` row and returns `202 {"id": "<uuid>"}`. The payload is stored and delivered unchanged: `{}` stays `{}`. | functional: `testAcceptsAnEventAndStoresItUnchanged`; e2e: `POST /events -> 202`, `payload {} arrives as {}` |
| M2 | Invalid input returns `400 {"error": "..."}` and stores nothing. Input is invalid when the body is not a JSON object, `url` is not an absolute `http`/`https` URL, `type` is not a non-empty string, or `payload` is not a JSON object. | functional: `testRejectsInvalidInputWith400AndStoresNothing`; e2e: `invalid JSON`, `missing url`, `non-http url -> 400` |
| M3 | The worker POSTs each due event to its `url` with a 10 s timeout. | e2e: `/ok -> sent`, `/slow (timeout) -> retry` |
| M4 | `GET /health` returns `200 {"status": "ok"}`, or `503` when the DB is unreachable. The starter already has this. | `curl localhost:8080/health` |

### Should

| ID | Requirement | Check |
|---|---|---|
| S1 | Outcomes follow BRIEF: 2xx → `sent`; timeout, network error, 408, 429, 5xx → `retry`; 410 and any other 4xx → `dead`. All of it lives in one pure function, `Outcome::status(int $httpCode, int $attempt): string`, where code 0 means timeout or network error. | unit: `testOutcome*`; e2e: one line per receiver path |
| S2 | The next attempt comes after `min(60·2^(n−1), 21600)·(0.5 + rand)` seconds, where n is the number of failed attempts so far. The 8th failure → `dead`. | unit: Backoff tests, `testOutcomeGivesUpAfterEightFailedAttempts`; e2e: `/fail: dead after 8 attempts` |
| S3 | Headers: `X-Webhook-Id`, `X-Webhook-Timestamp` (unix seconds, new on each attempt) and `X-Webhook-Signature: t=<ts>,v1=<hex HMAC-SHA256(secret, ts + "." + body)>`. The secret comes from `WEBHOOK_SECRET`, and the worker refuses to start without it. | unit: `testSignProducesKnownHmacSha256HexVector`; functional: `testRefusesToStartWithoutSecret`; e2e: `signature = HMAC(secret, ts.body)` |
| S4 | The body is `{"id", "type", "payload", "created_at"}`. `id` and `X-Webhook-Id` are the same on every attempt. | e2e: `/flaky: 3 requests, same id` |
| S5 | Two workers never deliver the same event at the same time. | e2e with `--workers 2`: `distinct event ids 20`. This is a smoke check; the guarantee comes from decision 2 |
| S6 | The provided unit tests pass. | `vendor/bin/phpunit` → `OK (7 tests, 19 assertions)` |

### Stretch

Pick by value per minute. Whatever is not done goes into `NOTES.md` under "what is missing".

| ID | Requirement | Check |
|---|---|---|
| X1 | Graceful shutdown: on SIGTERM the worker finishes and records the current delivery, then exits. `stop_grace_period` is longer than the HTTP timeout. | `docker compose stop worker` during a `/slow` delivery → `worker stopped` in the log, and the event is updated, not left leased |
| X2 | `POST /events/{id}/replay` moves a `dead` event back to `pending` with attempts 0, due now. It returns 404 when there is no such dead event. | replay a `/gone` event → `202`, and the row is `pending` |
| X3 | `GET /metrics`: event count per status, in Prometheus text format. | `curl localhost:8080/metrics` |
| X4 | SSRF guard: reject URLs that resolve to private, loopback or link-local addresses, with the `receiver` host allowlisted for local dev. Restrict curl to http/https. | POST with `http://127.0.0.1/` → 400 |
| X5 | Per-endpoint circuit breaker. | most likely cut, and explained in `NOTES.md` |
| X6 | Deploy with a real pipeline: CI scans and pushes the image, and deploys if a target is provided. | CI green |

## 4. Non-functional

- **At-least-once delivery.** If a worker dies between the POST and the status update, the event is delivered again once its lease expires. Receivers dedupe by `X-Webhook-Id`.
- **The lease (60 s) outlasts the HTTP timeout (10 s)**, so a live worker never loses its event mid-delivery.
- **Errors don't leak internals.** An unhandled exception returns `500 {"error": "internal error"}`, and the details go only to the log. Check: functional `testDatabaseOutageAnswers500WithoutDetails`.
- **Config comes only from env** (`DATABASE_URL`, `WEBHOOK_SECRET`). No real secrets in git.
- **Runs on PHP 8.3**, the image's version. A new dependency is added only after its license is named.

## 5. Decisions

| # | Decision | Why | Change it when |
|---|---|---|---|
| 1 | Postgres is the queue | already in the stack, transactional, and BRIEF endorses it | sustained high throughput or fan-out: move to a broker |
| 2 | Claim one event per statement (SQL below). The lease is `next_attempt_at = now() + 60 s` | no transaction stays open during the HTTP call; `SKIP LOCKED` lets workers pass each other; without `FOR UPDATE`, two workers can take the same id and both deliver it | batching claims for throughput (the lease then has to cover the whole batch) |
| 3 | No `in_progress` status; `locked_until` stays unused | the lease already marks an event as in flight, and fewer states mean less to keep consistent | operators need to see in-flight events |
| 4 | `attempts` counts failed attempts | same meaning as `attempt` in the backoff formula | — |
| 5 | One event per loop iteration, `sleep(1)` when idle | simplest; scale out by adding workers | latency matters: use LISTEN/NOTIFY instead of polling |
| 6 | Redirects are not followed; 3xx → `dead` | a redirect can point at an internal host | customers need redirects: follow them, running the SSRF check on every hop |
| 7 | Backoff ignores `Retry-After` | the spec's formula is explicit | customers rate-limit us: wait `max(backoff, Retry-After)` |
| 8 | `php -S` built-in server | BRIEF allows it | production: nginx + php-fpm, or FrankenPHP |

```sql
UPDATE events SET next_attempt_at = now() + interval '60 seconds'
WHERE id = (
    SELECT id FROM events
    WHERE status IN ('pending', 'retry') AND next_attempt_at <= now()
    ORDER BY next_attempt_at
    LIMIT 1
    FOR UPDATE SKIP LOCKED
)
RETURNING *;
```

## 6. Open questions (ask the interviewer)

1. Are AI tools allowed in this session? BRIEF says to ask.
2. Is there a deploy target (cloud account, registry)? If not, a local demo is the deploy.
3. Is at-least-once OK, meaning receivers dedupe by `X-Webhook-Id`?
4. Should `Retry-After` on a 429 win over the backoff formula?
5. Does `tenant_id` matter (per-tenant secrets, fairness)? We leave it at `default`.
