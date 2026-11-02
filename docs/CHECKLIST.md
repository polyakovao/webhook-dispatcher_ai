# Checklist: what to run

Paths are relative to the project root: the folder with `docker-compose.yml` (`php/` in the starter repo). Run every command from there, because `docker compose` also looks for a compose file in parent folders. On a fresh starter, most of these stay red until the stage in `docs/PLAN.md` that builds them.

## Once

- [ ] Docker is running, and `jq --version` works (`tests/e2e.sh` needs it)
- [ ] `composer install` → `vendor/`
- [ ] `docker-compose.override.yml` exists (PLAN Stage 0). It mounts `public/`, `src/` and `bin/` into the containers, so code edits need no rebuild

## Start and look

- [ ] `docker compose up -d --build`
- [ ] `docker compose ps` → `app`, `db`, `receiver` and `worker` are `Up`
- [ ] `curl -s localhost:8080/health` → `{"status":"ok"}`
- [ ] `./smoke.sh` → `HTTP 202` for each of the six events
- [ ] `docker compose logs --tail 20 worker` → one line per delivery: `<id> <status> <attempts> <error>`
- [ ] `curl -s localhost:9000/log | jq '.[-1]'` → the last request the receiver got, with its `x-webhook-*` headers

## Tests

| Layer | Command | Green | Needs |
|---|---|---|---|
| Unit | `vendor/bin/phpunit` | `OK (7 tests, 19 assertions)` | `vendor/` |
| Functional | `vendor/bin/phpunit --testsuite functional` | `OK (4 tests, 26 assertions)` | the stack |
| End-to-end | `tests/e2e.sh --workers 2` | `ALL PASS` | the stack, `jq` |
| All three | `tests/run-all.sh` | `ALL GREEN in …s` | Docker, `vendor/`, `jq`; starts the stack itself |

In Claude Code: `/test`, `/test functional`, `/test e2e`, `/test all`. Details are in `docs/TESTING.md`.

## After an edit

| You changed | Run |
|---|---|
| `public/index.php` | nothing: the API rereads it on every request |
| `bin/worker.php`, `src/` | `docker compose restart worker` |
| `Dockerfile`, `docker-compose*.yml`, `composer.json` | `docker compose up -d --build` |
| `db/schema.sql` | `docker compose down -v`, then `docker compose up -d` (wipes the DB) |

## Before a commit

- [ ] `tests/run-all.sh` → `ALL GREEN`
- [ ] `git status` lists only what you meant to change (not `.phpunit.result.cache`)

## Stop

`docker compose down` stops the stack and keeps the data. `docker compose down -v` also deletes the DB volume.
