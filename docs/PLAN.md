# Plan and tracker

There is no issue tracker, so this file is it. Work top to bottom, one stage at a time. A stage is done when its **Check** passes. Then tick its boxes, add a row to the log at the bottom, and wait for "ok, go" before committing (one ok, one commit).

Time boxes follow BRIEF's 60-minute plan. If a stage runs over, cut scope and write the cut into `NOTES.md` instead of eating the next stage's time. Requirement IDs (M1, S3, X2…) are defined in `docs/PRD.md`.

## Stage 0. Dev loop (0–3 min)

- [ ] `composer install`
- [ ] Add `docker-compose.override.yml` that bind-mounts `./public`, `./src` and `./bin` read-only into `/app/…` for `app` and `worker`
- [ ] `docker compose up -d --build`

**Check:** `curl -s localhost:8080/health` → `{"status":"ok"}`. `/test` runs and is red with `Tests: 7, Assertions: 0, Errors: 7` (two stubs and the missing `Outcome` class).

## Stage 1. Design note (3–10 min)

- [ ] Ask the interviewer the open questions in PRD §6, AI tools first
- [ ] Draft `NOTES.md`, one page at most: storage, claiming, retries, and failure modes (worker crash, slow receiver, DB down, missing secret)

**Check:** every Must and Should ID in the PRD has a line in `NOTES.md` saying how it will be done.

## Stage 2. Lock the spec with unit tests (10–15 min) · S1, S2, S3, S6

- [ ] `src/Signer.php`, `src/Backoff.php`: implement them per their docblocks
- [ ] `src/Outcome.php`: `Outcome::status(int $httpCode, int $attempt): string`, with `MAX_ATTEMPTS = 8`
- [ ] Add `.phpunit.result.cache` to `.gitignore`

**Check:** `/test` → `OK (7 tests, 19 assertions)`.

## Stage 3. POST /events (15–20 min) · M1, M2

- [ ] Decode the body with `json_decode($body)` so that objects stay objects (`{}` must not turn into `[]`)
- [ ] Validate per M2 → `400 {"error": …}`; otherwise `INSERT … RETURNING id` → `202 {"id": …}`
- [ ] Turn unhandled exceptions into `500 {"error": "internal error"}`, never a 200 with a stack trace

**Check:** `/test functional`: the three `ApiTest` tests pass (`WorkerTest` stays red until Stage 4).

## Stage 4. Worker (20–35 min) · M3, S1–S4

- [ ] Exit at start when `WEBHOOK_SECRET` is empty
- [ ] Claim one due event with the SQL from PRD decision 2
- [ ] POST `{"id", "type", "payload", "created_at"}` with the three `X-Webhook-*` headers and a 10 s timeout; `curl_exec()` returning `false` → code 0
- [ ] `Outcome::status()` and `Backoff::seconds()` → `UPDATE status, attempts, last_error, next_attempt_at, updated_at`
- [ ] Idle → `sleep(1)`. One log line per delivery: `id status attempts error`
- [ ] `docker compose restart worker`

**Check:** `/test functional` and `/test e2e` are all green.

## Stage 5. Two workers (35–40 min) · S5

- [ ] Nothing to build if Stage 4 used the PRD claim; this stage proves it

**Check:** `/test e2e --workers 2` → `ALL PASS`, including `distinct event ids 20`.

## Stage 6. Container, CI, deploy (40–50 min) · Deliverable 3, X6

- [ ] `worker` in compose: `restart: unless-stopped` (it exits on DB errors) and `stop_grace_period: 15s` (longer than the 10 s HTTP timeout)
- [ ] Commit `composer.lock`, so that the image and CI install the same versions
- [ ] CI keeps PHPUnit and the image build. Add an image scan and a push to GHCR on `main`; name each new action or tool and its license before adding it
- [ ] Deploy only if the interviewer gave a target; otherwise the local demo is the deploy

**Check:** `docker build .` succeeds locally. CI is green on GitHub after the push, which needs "ok, go".

## Stage 7. Stretch (until 55 min) · cheapest first

- [ ] X1 graceful shutdown: mostly there already (pcntl handlers plus `stop_grace_period`)
- [ ] X3 `GET /metrics`
- [ ] X2 replay endpoint
- [ ] X4 SSRF guard
- [ ] X5 circuit breaker: most likely a "next step" in `NOTES.md`

**Check:** each item's check in the PRD; `/test` and `/test e2e` still pass.

## Stage 8. Wrap-up (55–60 min)

- [ ] Final `NOTES.md`: what was built, what was cut and why, next steps, how to run it
- [ ] PR description from `.github/pull_request_template.md`, stating that the code was written together with Claude Code
- [ ] Walkthrough: the claim SQL, the outcome table, what we would change for production

**Check:** `tests/run-all.sh` → `ALL GREEN` on the final commit, and the PR is open (needs "ok, go").

## Log

| Stage | Finished | Done / cut |
|---|---|---|
