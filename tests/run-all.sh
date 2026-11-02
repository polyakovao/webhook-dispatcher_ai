#!/usr/bin/env bash
# One full local test run: unit tests, then build and start the stack, then functional and e2e tests.
# Stops at the first red step. The stack stays up afterwards; stop it with `docker compose stop`.
#
#   tests/run-all.sh
set -euo pipefail
cd "$(dirname "$0")/.."

step() { current=$1; printf '\n== %s\n' "$1"; }
trap 'echo; echo "FAILED at: $current"' ERR

step "unit"
[ -d vendor ] || { echo "no vendor/ folder: run composer install first"; exit 1; }
vendor/bin/phpunit

step "stack: build and start"
docker compose up -d --build --wait
for _ in $(seq 30); do curl -fs http://localhost:8080/health >/dev/null && break; sleep 1; done
curl -fsS http://localhost:8080/health
echo

step "functional"
vendor/bin/phpunit --testsuite functional

step "end-to-end"
tests/e2e.sh --workers 2

echo
echo "ALL GREEN in ${SECONDS}s"
