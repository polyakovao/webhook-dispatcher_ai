# Task: Mini Webhook Dispatcher (60 minutes)

You are the tech lead. Design, build, containerize and deploy a small service that accepts events over HTTP and reliably delivers them to a customer-configured URL.

You are **not** expected to finish everything. We are looking at what you build first, what you cut, and how you justify it. Talk through your thinking as you go.

## Suggested time plan

| Time | Activity |
|---|---|
| 0-10 min | Ask questions, write a short design note in `NOTES.md` (storage, claiming work, retries, failure modes) |
| 10-40 min | Implement: Must items first, then Should items |
| 40-55 min | Containerize, wire up CI, deploy, smoke test |
| 55-60 min | Walk us through what you built, what you cut, and what you would do next |

## Scope

| Tier | Requirement |
|---|---|
| **Must** | `POST /events` stores an event and returns `202` with its id. A worker delivers it to the target URL with a timeout. `GET /health` works. |
| **Should** | Retry with exponential backoff and jitter, max attempts then `dead`. HMAC signature header. Stable event id sent on every attempt. Safe claiming: two workers must never deliver the same event at the same time. Make the provided unit tests pass. |
| **Stretch** | `/metrics`, replay endpoint for dead events, SSRF guard on target URLs, graceful shutdown, per-endpoint circuit breaker, deploy with a real pipeline. |

## What is provided

- A running skeleton: `GET /health` works, `POST /events` returns `501`, the worker loop is an empty stub.
- `db/schema.sql`: an `events` table (applied automatically by docker compose). Change it if you want, but say why.
- Failing unit tests for two pure functions (`backoff` and `sign`). Make them pass first: it is a quick way to lock down the spec.
- `docker-compose.yml` with the app, Postgres and a local **receiver** to deliver to.
- A CI workflow skeleton and a `smoke.sh` script.

## Delivery spec

**Request your worker sends** (to the event's `url`):

- Method `POST`, `Content-Type: application/json`, body is the event JSON. The body **must contain the event `id`**, and it must be the same on every retry.
- Headers: `X-Webhook-Id`, `X-Webhook-Timestamp` (unix seconds), `X-Webhook-Signature: t=<timestamp>,v1=<hex>`
- Signature: `hex( HMAC_SHA256( secret, timestamp + "." + body ) )`, secret from env `WEBHOOK_SECRET`.

**Outcome handling:**

| Response | Meaning |
|---|---|
| any `2xx` | success, mark `sent` |
| timeout or network error, `408`, `429`, `5xx` | retryable, schedule the next attempt |
| `410` | permanent failure (the endpoint is gone), mark `dead` |
| any other `4xx` | permanent failure, mark `dead` |

**Backoff:** `attempt` is the number of failed attempts so far (1 for the first retry).
`delay_seconds = min(60 * 2^(attempt-1), 21600) * (0.5 + rand)` where `rand` is in `[0, 1)`.
Give up after **8** attempts and mark the event `dead`.

## Local delivery targets

The `receiver` service (http://localhost:9000, or `http://receiver:9000` from inside compose) simulates customer endpoints:

| Path | Behaviour |
|---|---|
| `/ok` | `200` |
| `/created` | `201` |
| `/fail` | `500` |
| `/gone` | `410` |
| `/ratelimit` | `429` with `Retry-After: 5` |
| `/slow` | hangs 30 seconds, then `200` |
| `/flaky/<key>` | `500` the first 2 calls for that key, then `200` |
| `/log` (GET) | the last 50 requests the receiver saw, with headers and body |

## Deliverables

1. Code in a git repo (copy your stack folder out as the repo root).
2. `NOTES.md`, at most one page: design, trade-offs, what is missing, how to run it.
3. A working `docker compose up`, plus CI that runs the tests and builds the image.
4. A deployed instance, if a target was provided. Otherwise a local demo is fine.

## Ground rules

- Pick the simplest thing that works and tell us when you would change it. Postgres as the queue is a perfectly good answer.
- Use whatever documentation you like. Ask the interviewer if AI tools are allowed in this session.
- Do not commit real secrets. Config comes from environment variables.
