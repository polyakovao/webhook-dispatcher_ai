# Running the tests

| Layer | What it proves | Needs | Command | Time |
|---|---|---|---|---|
| Unit | pure functions: `Backoff`, `Signer`, `Outcome` | `composer install` | `vendor/bin/phpunit` | < 1 s |
| Functional | the API over HTTP (202, 400, 500, payload stored as sent) and the worker as a process (refuses to start without a secret) | the stack, for the API tests | `vendor/bin/phpunit --testsuite functional` | ~1 s |
| End-to-end | the whole flow: API → DB → worker → receiver, retries, dead after 8, signature, two workers | the stack and `jq` | `tests/e2e.sh --workers 2` | ~20 s |

In Claude Code, `/test`, `/test functional`, `/test e2e` and `/test all` run the same commands.

## One command

```bash
tests/run-all.sh
```

Runs unit, then builds and starts the stack (`docker compose up -d --build --wait`), then functional and e2e with two workers. It takes about 25 s with a warm Docker cache. It stops at the first red step with `FAILED at: <step>` and leaves the stack running.

## The same, step by step, from the project root

```bash
composer install                            # once
vendor/bin/phpunit                          # OK (7 tests, 19 assertions)
docker compose up -d --build
vendor/bin/phpunit --testsuite functional   # OK (4 tests, 26 assertions)
tests/e2e.sh --workers 2                    # ALL PASS
```

Run `tests/run-all.sh` before a commit. After editing `bin/` or `src/`, run `docker compose restart worker` first: the API rereads files on every request, but the worker does not. The API tests talk to the app on :8080, so they test the code that the running stack serves.

## When something is red

- "is not reachable": the stack is down. Run `docker compose up -d --build`.
- An e2e `FAIL` line shows what was expected and what came back. The cause is usually in `docker compose logs --tail 50 worker app`.
- On a fresh starter, red is expected until the plan stage that implements the feature (`docs/PLAN.md`).

## Adding a test

Every change comes with a test that fails without it (`CLAUDE.md`, Rules):

- pure logic → a method in `tests/LibTest.php`
- API or worker behavior → `tests/Functional/`
- a flow across API, worker and receiver → a `check` line in `tests/e2e.sh`, labelled with its PRD ID
