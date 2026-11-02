---
name: test
description: Run this project's tests. No argument or "unit" runs the PHPUnit unit tests; "functional" runs the PHPUnit functional suite (the API over HTTP, the worker as a process); "e2e" runs tests/e2e.sh against the local docker compose stack (API validation, outcome per receiver path, retries with a stable id, dead after 8 attempts, signature); "e2e --workers 2" also checks that two workers never deliver the same event; "all" runs tests/run-all.sh (unit, then build and start the stack, then functional and e2e). Use after every code change and as the Check step of each stage in docs/PLAN.md.
argument-hint: "[unit | functional | e2e [--workers 2] | all]"
allowed-tools: Bash(vendor/bin/phpunit *) Bash(tests/e2e.sh *) Bash(tests/run-all.sh *) Bash(docker compose ps *) Bash(docker compose up -d *) Bash(docker compose restart worker) Bash(docker compose logs *)
---

# Run the tests

Arguments: $ARGUMENTS

Run every command from the project root (the folder with `composer.json`) and spell it exactly as below. The pre-approved permissions match the command text, so `./vendor/bin/phpunit` or a `cd … &&` prefix will ask for permission.

## unit (default)

1. `vendor/bin/phpunit`
2. No `vendor/` folder? Ask before running `composer install`.
3. Report the summary line (`OK (7 tests, 19 assertions)`), or each failing test's name with its first failure message.

## functional

1. The API tests need the stack: check it as in e2e step 1. The worker test needs nothing.
2. `vendor/bin/phpunit --testsuite functional`
3. Report the summary line (`OK (4 tests, 26 assertions)`), or each failing test's name with its first failure message.

## e2e

1. `docker compose ps --services --status running` must list app, worker, db and receiver. Otherwise run `docker compose up -d --build`.
2. If `bin/` or `src/` changed since the worker started, run `docker compose restart worker`. The API rereads files on every request; the worker does not.
3. Run `tests/e2e.sh`, or `tests/e2e.sh --workers 2`. It takes about 30 s, most of it the 10 s `/slow` timeout.
4. Report the PASS/FAIL lines as printed. For each FAIL, read `docker compose logs --tail 50 worker` (or `app`), name the cause and fix the code.

## all

1. `tests/run-all.sh`: unit, then `docker compose up -d --build --wait`, then functional and e2e with two workers. About 25 s with a warm Docker cache.
2. It stops at the first red step and prints `FAILED at: <step>`. Report that step's output and fix it as described in that step's section above.

## Rules

- `tests/LibTest.php`, `tests/Functional/` and `tests/e2e.sh` encode the spec (BRIEF.md, docs/PRD.md). Never weaken an expectation to get green. If one looks wrong, stop and ask.
- Never print the values of `WEBHOOK_SECRET` or `DATABASE_URL`. The e2e signature check runs inside the worker container for this reason.
- Ask before `docker compose down -v`: it wipes the database.
- The 2-worker check is a smoke test, so a pass does not prove there is no race. The guarantee comes from the `FOR UPDATE SKIP LOCKED` claim (docs/PRD.md, decision 2).
