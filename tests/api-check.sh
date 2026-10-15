#!/usr/bin/env bash
# Run (in WSL, from the repo): bash tests/api-check.sh
# End-to-end checks of the v2 REST API against the local DDEV site. Creates only 'test-api-*' records and removes them.
set -uo pipefail
cd "$(dirname "$0")/.."

BASE="https://wp-intranet-phonelist.ddev.site/wp-json/phonelist/v2"
SECRET=$(ddev wp eval 'echo INTRANET_PHONELIST_API_SECRET;' 2>/dev/null)
APP=$(ddev wp user application-password create admin api-check --porcelain 2>/dev/null | tail -1)
FAILED=0
LAST_BODY=""

remove_records() {
    local ids; ids=$(ddev wp post list --post_type=iphl_staff,iphl_division,iphl_company --post_status=any --meta_key=_iphl_source_id --meta_compare=LIKE --meta_value=test-api --format=ids 2>/dev/null)
    [ -n "$ids" ] && ddev wp post delete $ids --force >/dev/null 2>&1
    ids=$(ddev wp post list --post_type=iphl_company --title='Test Api Division' --format=ids 2>/dev/null)
    [ -n "$ids" ] && ddev wp post delete $ids --force >/dev/null 2>&1
    ddev wp db query "DELETE FROM wp_iphl_jobs WHERE idempotency_key LIKE 'test-api-%' OR payload LIKE '%test-api-%'" >/dev/null 2>&1
}
cleanup() {
    remove_records
    ddev wp user application-password delete admin "$(ddev wp user application-password list admin --name=api-check --field=uuid 2>/dev/null)" >/dev/null 2>&1
}
trap cleanup EXIT
remove_records
KEY="test-api-key-$(date +%s)"

sign() { # method, full path with query, body → header value
    local t; t=$(date +%s)
    printf 't=%s,v1=%s' "$t" "$(printf '%s' "$t.$1.$2.$3" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')"
}

# request METHOD PATH BODY MODE(signed|unsigned|app|tampered|other-record) [extra curl args...] → prints status
request() {
    local method=$1 path=$2 body=$3 mode=$4; shift 4
    local full="/wp-json/phonelist/v2$path"
    local args=(-sk -o /tmp/api-check-body -w '%{http_code}' -X "$method" -H 'Content-Type: application/json')
    case $mode in
        signed) args+=(-H "X-Phonelist-Signature: $(sign "$method" "$full" "$body")") ;;
        tampered) args+=(-H "X-Phonelist-Signature: $(sign "$method" "$full" "$body ")") ;;
        other-record) args+=(-H "X-Phonelist-Signature: $(sign "$method" "${full}-other" "$body")") ;;
        app) args+=(-u "admin:$APP") ;;
    esac
    [ -n "$body" ] && args+=(--data-raw "$body")
    local status; status=$(curl "${args[@]}" "$@" "$BASE$path")
    echo "$status"
}

expect() { # description expected actual
    LAST_BODY=$(cat /tmp/api-check-body)
    if [ "$2" == "$3" ]; then echo "  ok   $1"; else echo "  FAIL $1: expected $2, got $3 — $LAST_BODY"; FAILED=1; fi
}

has() { # description needle
    if grep -q -- "$2" <<<"$LAST_BODY"; then echo "  ok   $1"; else echo "  FAIL $1: '$2' not in $LAST_BODY"; FAILED=1; fi
}

lacks() {
    if grep -q -- "$2" <<<"$LAST_BODY"; then echo "  FAIL $1: '$2' in $LAST_BODY"; FAILED=1; else echo "  ok   $1"; fi
}

T1='2026-10-15T09:00:00+08:00'; T0='2026-10-15T08:00:00+08:00'; T2='2026-10-15T10:00:00+08:00'
T2_QUERY='2026-10-15T10:00:00%2B08:00' # '+' must be encoded in a query string
JANE='"first_name":"Jane","last_name":"Api","status":"active","date_of_birth":"1985-09-05"'

expect "PUT creates" 201 "$(request PUT /staff/test-api-1 "{$JANE,\"updated_at\":\"$T1\"}" signed)"
expect "a body id that differs from the URL is refused" 422 "$(request PUT /staff/test-api-1 "{\"id\":\"test-api-9\",$JANE,\"updated_at\":\"$T1\"}" signed)"
expect "...and doesn't write the other record" 404 "$(request GET /staff/test-api-9 '' app)"
expect "a type in the query can't change the route" 200 "$(request GET '/staff/test-api-1?type=nope' '' app)"
expect "PUT again is unchanged" 200 "$(request PUT /staff/test-api-1 "{$JANE,\"updated_at\":\"$T1\"}" signed)"
has "unchanged result" '"result":"unchanged"'
expect "older PUT is refused" 409 "$(request PUT /staff/test-api-1 "{$JANE,\"updated_at\":\"$T0\"}" signed)"
has "problem details" '"status":409'
expect "missing last_name is invalid" 422 "$(request PUT /staff/test-api-1 "{\"first_name\":\"Jane\",\"status\":\"active\",\"updated_at\":\"$T1\"}" signed)"
has "field error names last_name" '"field":"last_name"'
expect "tampered body is refused" 401 "$(request PUT /staff/test-api-1 "{$JANE,\"updated_at\":\"$T1\"}" tampered)"
expect "a signature for another record is refused" 401 "$(request PUT /staff/test-api-1 "{$JANE,\"updated_at\":\"$T1\"}" other-record)"
expect "unsigned on a local site is accepted" 200 "$(request PUT /staff/test-api-1 "{$JANE,\"updated_at\":\"$T1\"}" unsigned)"
expect "not JSON is a bad request" 400 "$(request PUT /staff/test-api-1 'nope' signed)"
expect "PATCH of an unknown id" 404 "$(request PATCH /staff/test-api-nope "{\"updated_at\":\"$T1\"}" signed)"
expect "PATCH changes one field" 200 "$(request PATCH /staff/test-api-1 "{\"position\":\"Tester\",\"updated_at\":\"$T2\"}" signed)"
expect "GET as a logged-in user" 200 "$(request GET /staff/test-api-1 '' app)"
has "record has its fields" '"position":"Tester"'
lacks "private fields hidden from users" 'date_of_birth'
expect "GET signed" 200 "$(request GET /staff/test-api-1 '' signed)"
has "private fields shown to signed requests" '"date_of_birth":"1985-09-05"'
expect "GET list filtered by name" 200 "$(request GET '/staff?name=jane%20ap' '' app)"
has "list finds the record" '"id":"test-api-1"'
expect "empty snapshot is refused" 422 "$(request PUT /staff '{"records":[]}' signed)"
expect "batch is accepted" 202 "$(request POST /batch "{\"operations\":[{\"op\":\"put\",\"type\":\"divisions\",\"id\":\"test-api-D\",\"data\":{\"name\":\"Test Api Division\",\"updated_at\":\"$T1\"}},{\"op\":\"patch\",\"type\":\"staff\",\"id\":\"test-api-1\",\"data\":{\"division_id\":\"test-api-D\",\"updated_at\":\"$T2\"}}]}" signed -H "Idempotency-Key: $KEY")"
JOB=$(grep -o '"job":"[^"]*"' <<<"$LAST_BODY" | cut -d'"' -f4)
expect "same idempotency key returns the same job" 200 "$(request POST /batch '{"operations":[{"op":"delete","type":"staff","id":"test-api-1","data":{}}]}' signed -H "Idempotency-Key: $KEY")"
has "same job id" "\"job\":\"$JOB\""
sleep 3
expect "job status" 200 "$(request GET "/jobs/$JOB" '' signed)"
has "job is done" '"status":"done"'
has "both items applied" '"result":"updated"'
expect "companies list" 200 "$(request GET /companies '' app)"
has "lists companies WordPress created, with no Data Hub ID" '"id":null,"updated_at":null,"name":"Test Api Division"'
expect "job status needs a signature" 401 "$(request GET "/jobs/$JOB" '' tampered)"
expect "club for logged-in users" 200 "$(request GET /club '' app)"
expect "DELETE without updated_at is invalid" 422 "$(request DELETE /staff/test-api-1 '' signed)"
expect "DELETE" 204 "$(request DELETE "/staff/test-api-1?updated_at=$T2_QUERY" '' signed)"
expect "DELETE again" 204 "$(request DELETE "/staff/test-api-1?updated_at=$T2_QUERY" '' signed)"
expect "DELETE division" 204 "$(request DELETE "/divisions/test-api-D?updated_at=$T2_QUERY" '' signed)"
expect "unknown route" 404 "$(request GET /nothing '' app)"
has "unknown route is problem details" '"type":"about:blank"'
expect "anonymous read is refused" 401 "$(request GET /staff/test-api-1 '' tampered)"

if [ $FAILED -eq 0 ]; then echo "api-check OK"; else echo "api-check FAILED"; exit 1; fi
