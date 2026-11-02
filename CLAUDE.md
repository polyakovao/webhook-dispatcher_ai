# Webhook Dispatcher (PHP)

Interview task with a 60-minute time box: accept events over HTTP and deliver them reliably to a customer URL.

- `BRIEF.md`: the task. It is the source of truth.
- `docs/PRD.md`: requirements with IDs (M1…X6), acceptance checks and design decisions.
- `docs/PLAN.md`: stages, each with a check. There is no issue tracker; this file is it.
- `docs/PROMPT.md`: how to start from a clean starter.
- `docs/TESTING.md`: how to run the tests.
- `docs/CHECKLIST.md`: what to run and when: setup, start, tests, after an edit, before a commit.

## Stack and layout

Plain PHP 8.3 (no framework), PDO pgsql, PHPUnit 11, Postgres 16 as the queue. PHP has no background threads, so the worker is its own process and its own compose service.

| Path | What |
|---|---|
| `public/index.php` | HTTP router: `GET /health`, `POST /events` |
| `bin/worker.php` | delivery loop: claim, POST, classify, update |
| `src/Backoff.php`, `src/Signer.php`, `src/Outcome.php` | pure functions, unit tests in `tests/LibTest.php` |
| `src/Db.php` | PDO connection from `DATABASE_URL` |
| `db/schema.sql` | `events` table, applied when the `db` volume is empty |
| `receiver/server.cjs` | fake customer endpoints on :9000; `GET /log` returns the last 50 requests |
| `tests/Functional/` | functional tests: the API over HTTP, the worker as a process |
| `tests/e2e.sh` | end-to-end check of the running stack (needs `jq`) |
| `tests/run-all.sh` | one full local test run: unit, stack, functional, e2e |

## Commands (run from this folder)

- Unit tests: `vendor/bin/phpunit`, or `/test`
- Functional tests: `vendor/bin/phpunit --testsuite functional`, or `/test functional` (the API tests need the stack)
- End-to-end: `/test e2e`, or `tests/e2e.sh [--workers 2]`
- Everything in one run: `tests/run-all.sh`, or `/test all` (builds and starts the stack first)
- Stack: `docker compose up -d --build`; logs: `docker compose logs -f worker`
- Reload worker code: `docker compose restart worker` (the API rereads files on every request)
- Two workers: `docker compose up -d --scale worker=2`
- SQL shell: `docker compose exec db psql -U dispatcher -d dispatcher`

## Gotchas

- Run `docker compose` from this folder. Compose also looks for a compose file in parent directories, and on this machine a parent directory holds an unrelated project.
- The image copies the code at build time. Live edits need `docker-compose.override.yml` with bind mounts (PLAN, Stage 0).
- Host PHP may be newer than the image's 8.3. Don't use 8.4+ features (property hooks, `array_find`, `|>`). Don't call `curl_close()`: it has done nothing since 8.0 and is deprecated in 8.5.
- `json_decode($body, true)` turns `{}` into `[]`, and `json_encode` writes it back as `[]`. Decode request bodies to objects.
- An uncaught exception in the API comes back as `200`, with the error text and stack trace in the body (`display_errors` is on in the image). Catch it and return a 500 JSON error.
- curl: if the headers arrived but the body timed out, `curl_exec()` returns `false` while `CURLINFO_RESPONSE_CODE` still says `200`. Treat `false` as code `0` (timeout or network error).
- `db/schema.sql` runs only on an empty volume. After a schema change, run `docker compose down -v` (it wipes the data, so ask first), then `up`.
- `/flaky/<key>` counts calls per key in the receiver's memory. Use a fresh key per test, or restart the receiver.
- macOS ships bash 3.2, which brace-expands `{a,b}` inside `"$(...)"`. Pass JSON bodies to commands through variables.
- GitHub reads workflows only from `.github/workflows/` at the repo root. That is why this folder has to be the repo root.

## Rules

- Every change comes with a test that fails without it: pure logic → a method in `tests/LibTest.php`; API or worker behavior → `tests/Functional/`; a flow across API, worker and receiver → a `check` line in `tests/e2e.sh`. A bug fix starts with a test that reproduces the bug. No test, not done.
- Write the simplest code that passes the stage's check, in the style of the code around it. No frameworks. Before adding a Composer package, a GitHub Action or a tool, name its license.
- Config comes only from env: `DATABASE_URL`, `WEBHOOK_SECRET`. Never print their values or commit real secrets (`.env*` is git-ignored).
- Commit, push or open a PR only after an explicit "ok, go": one ok, one action. Keep the `Co-Authored-By` trailer.
- Tests and e2e expectations are the spec: don't weaken them. If BRIEF, PRD and code disagree, stop and ask.
- Report each stage as: what you ran and verified, what you assume, and a short **Not verified** list.
