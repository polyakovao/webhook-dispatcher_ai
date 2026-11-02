#!/usr/bin/env bash
# Quick end-to-end check. Usage: ./smoke.sh [base_url]
# Sends one event to each receiver scenario, then shows what the receiver saw.
set -euo pipefail

BASE="${1:-http://localhost:8080}"
RECEIVER_PUBLIC="${RECEIVER_PUBLIC:-http://localhost:9000}"
RECEIVER_INTERNAL="${RECEIVER_INTERNAL:-http://receiver:9000}"

echo "== health"
curl -fsS "$BASE/health"; echo

post() {
  local path="$1"
  printf "%-22s -> HTTP " "$path"
  curl -sS -o /dev/null -w "%{http_code}\n" -X POST "$BASE/events" \
    -H 'content-type: application/json' \
    -d "{\"url\":\"$RECEIVER_INTERNAL$path\",\"type\":\"order.created\",\"payload\":{\"order_id\":123}}"
}

echo "== posting events (expect 202 each)"
post /ok
post /created
post /fail
post /gone
post /ratelimit
post /flaky/demo-1

echo
echo "== give the worker a moment, then look at what the receiver saw"
sleep 3
curl -fsS "$RECEIVER_PUBLIC/log" | head -60
