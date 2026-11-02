#!/usr/bin/env bash
# End-to-end check of the local stack (start it with: docker compose up -d --build).
# The expected values are BRIEF.md / docs/PRD.md: when a check fails, fix the code, not this file.
#
#   tests/e2e.sh               API validation, one event per receiver path, retries, dead after 8
#   tests/e2e.sh --workers 2   the same with 2 workers, plus 20 events that must each arrive once
#
# Needs curl, jq and docker compose. Leaves no retries behind (this run's leftovers are marked dead).
set -euo pipefail
cd "$(dirname "$0")/.."

API=http://localhost:8080
RECEIVER=http://localhost:9000
RUN=$(date +%s) # tags this run's events (type e2e.<RUN>) and gives /flaky a fresh key
WORKERS=1
if [ "${1:-}" = "--workers" ]; then WORKERS="${2:-2}"; fi
fails=0

sql() { docker compose exec -T db psql -U dispatcher -d dispatcher -qtA -c "$1"; }

check() { # name, expected, actual
    if [ "$2" = "$3" ]; then
        printf 'PASS  %-38s %s\n' "$1" "$3"
    else
        printf 'FAIL  %-38s expected [%s], got [%s]\n' "$1" "$2" "$3"
        fails=$((fails + 1))
    fi
}

code_of() { # request body -> HTTP status of POST /events
    curl -s -o /dev/null -w '%{http_code}' -X POST "$API/events" -H 'content-type: application/json' -d "$1"
}

post() { # receiver path, payload JSON -> event id
    curl -fsS -X POST "$API/events" -H 'content-type: application/json' \
        -d "{\"url\":\"http://receiver:9000$1\",\"type\":\"e2e.$RUN\",\"payload\":$2}" | jq -r .id
}

state() { sql "SELECT status || ' ' || attempts FROM events WHERE id = '$1'"; }

wait_for() { # SQL boolean, max seconds; returns quietly, the checks report what is wrong
    for _ in $(seq "$2"); do
        if [ "$(sql "SELECT $1")" = t ]; then return 0; fi
        sleep 1
    done
}

requests() { curl -fsS "$RECEIVER/log"; } # the last 50 requests the receiver saw

curl -fsS "$API/health" >/dev/null || { echo "API is down: docker compose up -d --build"; exit 1; }
if [ "$WORKERS" -gt 1 ]; then docker compose up -d --scale worker="$WORKERS" worker 2>/dev/null; fi

echo "== API (M1, M2)"
# bodies go through variables: macOS bash 3.2 brace-expands {a,b} inside "$(...)"
valid="{\"url\":\"http://receiver:9000/ok\",\"type\":\"e2e.$RUN\",\"payload\":{}}"
no_url="{\"type\":\"e2e.$RUN\",\"payload\":{}}"
ftp_url="{\"url\":\"ftp://receiver/x\",\"type\":\"e2e.$RUN\",\"payload\":{}}"
check "POST /events -> 202"               202 "$(code_of "$valid")"
check "invalid JSON -> 400"               400 "$(code_of '{')"
check "missing url -> 400"                400 "$(code_of "$no_url")"
check "non-http url -> 400"               400 "$(code_of "$ftp_url")"

echo "== first attempt per receiver path (M3, S1, S3)"
ok=$(post /ok '{"n":1}')
created=$(post /created '{}')
fail=$(post /fail '{"n":1}')
gone=$(post /gone '{"n":1}')
ratelimit=$(post /ratelimit '{"n":1}')
flaky=$(post "/flaky/e2e-$RUN" '{"n":1}')
slow=$(post /slow '{"n":1}') # last: it holds a worker for the whole 10 s timeout
wait_for "NOT EXISTS (SELECT 1 FROM events WHERE event_type = 'e2e.$RUN' AND attempts = 0 AND status NOT IN ('sent', 'dead'))" 40
check "/ok -> sent"                       "sent 0"  "$(state "$ok")"
check "/created -> sent"                  "sent 0"  "$(state "$created")"
check "/fail (500) -> retry"              "retry 1" "$(state "$fail")"
check "/gone (410) -> dead"               "dead 1"  "$(state "$gone")"
check "/ratelimit (429) -> retry"         "retry 1" "$(state "$ratelimit")"
check "/slow (timeout) -> retry"          "retry 1" "$(state "$slow")"
check "payload {} arrives as {}"          '{}' "$(requests | jq -r --arg id "$created" '.[] | select(.headers["x-webhook-id"] == $id) | .body | fromjson? | .payload | tojson')"
# recomputed inside the worker container, so the secret never leaves it
check "signature = HMAC(secret, ts.body)" ok "$(docker compose exec -T worker php -r '
    $out = "not delivered";
    foreach (json_decode(file_get_contents("http://receiver:9000/log"), true) as $r) {
        $h = $r["headers"];
        if (($h["x-webhook-id"] ?? "") === $argv[1]) {
            $ts = $h["x-webhook-timestamp"];
            $want = "t=$ts,v1=" . hash_hmac("sha256", $ts . "." . $r["body"], getenv("WEBHOOK_SECRET"));
            $out = $h["x-webhook-signature"] === $want ? "ok" : "mismatch";
        }
    }
    echo $out;' "$ok")"

echo "== retries (S2, S4)"
sql "UPDATE events SET next_attempt_at = now() WHERE id = '$flaky'" >/dev/null # skip the backoff wait
wait_for "(SELECT attempts = 2 FROM events WHERE id = '$flaky')" 15
sql "UPDATE events SET next_attempt_at = now() WHERE id = '$flaky'" >/dev/null
wait_for "(SELECT status = 'sent' FROM events WHERE id = '$flaky')" 15
check "/flaky -> sent on 3rd attempt"     "sent 2" "$(state "$flaky")"
check "/flaky: 3 requests, same id"       3 "$(requests | jq --arg id "$flaky" '[.[] | select(.headers["x-webhook-id"] == $id and (.body | fromjson? | .id) == $id)] | length')"
sql "UPDATE events SET attempts = 7, next_attempt_at = now() WHERE id = '$fail'" >/dev/null
wait_for "(SELECT status = 'dead' FROM events WHERE id = '$fail')" 15
check "/fail: dead after 8 attempts"      "dead 8" "$(state "$fail")"

if [ "$WORKERS" -gt 1 ]; then
    echo "== $WORKERS workers, 20 events, each delivered once (S5)"
    for _ in $(seq 20); do post "/ok?c=$RUN" '{}' >/dev/null; done
    wait_for "NOT EXISTS (SELECT 1 FROM events WHERE target_url LIKE '%?c=$RUN' AND status <> 'sent')" 30
    check "deliveries"                    20 "$(requests | jq --arg p "/ok?c=$RUN" '[.[] | select(.path == $p)] | length')"
    check "distinct event ids"            20 "$(requests | jq --arg p "/ok?c=$RUN" '[.[] | select(.path == $p) | .headers["x-webhook-id"]] | unique | length')"
    docker compose up -d --scale worker=1 worker 2>/dev/null
fi

# leftovers would keep retrying (and /slow blocks a worker for 10 s each time)
sql "UPDATE events SET status = 'dead', last_error = 'e2e cleanup' WHERE event_type = 'e2e.$RUN' AND status IN ('pending', 'retry')" >/dev/null

echo
if [ "$fails" -gt 0 ]; then echo "$fails FAILED (docker compose logs --tail 50 worker app)"; exit 1; fi
echo "ALL PASS"
