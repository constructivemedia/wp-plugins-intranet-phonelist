#!/usr/bin/env bash
# Run (in WSL, from the repo): bash tests/staging-check.sh
# The API as staging and production see it: the local DDEV site with .env temporarily overridden (environment type,
# secret). .env is restored afterwards, even on failure. Creates only 'test-api-stg-*' records and removes them.
set -uo pipefail
cd "$(dirname "$0")/.."

BASE="https://wp-intranet-phonelist.ddev.site/wp-json/phonelist/v2"
SECRET="staging-check-$(date +%s)"
FAILED=0
BACKUP=""
[ -f .env ] && BACKUP=$(mktemp) && cp .env "$BACKUP"

cleanup() {
    if [ -n "$BACKUP" ]; then cp "$BACKUP" .env && rm -f "$BACKUP"; else rm -f .env; fi
    local ids; ids=$(ddev wp post list --post_type=iphl_staff --post_status=any --meta_key=_iphl_source_id --meta_compare=LIKE --meta_value=test-api-stg --format=ids 2>/dev/null)
    [ -n "$ids" ] && ddev wp post delete $ids --force >/dev/null 2>&1
    ddev wp eval 'update_option("intranet_phonelist_deleted", array_filter(get_option("intranet_phonelist_deleted", []), fn ($key) => !str_contains($key, ":test-api-stg"), ARRAY_FILTER_USE_KEY), false);' >/dev/null 2>&1
    ddev wp user application-password delete admin "$(ddev wp user application-password list admin --name=staging-check --field=uuid 2>/dev/null)" >/dev/null 2>&1
}
trap cleanup EXIT

APP=$(ddev wp user application-password create admin staging-check --porcelain 2>/dev/null | tail -1)

# site KEY=VALUE... : .env becomes the original, with these keys replaced
site() {
    local keys; keys=$(printf '%s\n' "$@" | cut -d= -f1 | paste -sd'|')
    { [ -n "$BACKUP" ] && grep -Ev "^($keys)=" "$BACKUP"; printf '%s\n' "$@"; } > .env
}

# request METHOD PATH AUTH(an Authorization value, none or app) [BODY] → "status body"
request() {
    local args=(-sk -o /tmp/staging-check-body -w '%{http_code}' -X "$1" -H 'Content-Type: application/json')
    case $3 in none) ;; app) args+=(-u "admin:$APP") ;; *) args+=(-H "Authorization: $3") ;; esac
    [ -n "${4:-}" ] && args+=(--data-raw "$4")
    local status; status=$(curl "${args[@]}" "$BASE$2")
    echo "$status $(cat /tmp/staging-check-body)"
}

expect() { # description status text-in-body result
    local status=${4%% *} body=${4#* }
    if [ "$status" == "$2" ] && grep -qF -- "$3" <<<"$body"; then echo "  ok   $1"; else echo "  FAIL $1: expected $2 with '$3', got $status $body"; FAILED=1; fi
}

T1='2026-10-15T09:00:00+08:00'; T2='2026-10-15T10:00:00+08:00'
STG='"first_name":"Stg","last_name":"Api","status":"active","date_of_birth":"1985-09-05"'
TOKEN="Bearer $SECRET"

echo "staging"
site "WP_ENVIRONMENT_TYPE=staging" "INTRANET_PHONELIST_API_SECRET=$SECRET"
expect "no token can't read" 401 'Bearer token missing.' "$(request GET /staff none)"
expect "no token can't write" 401 'Bearer token missing.' "$(request PUT /staff/test-api-stg-1 none "{$STG,\"updated_at\":\"$T1\"}")"
expect "a wrong token" 401 'Bearer token invalid.' "$(request GET /staff 'Bearer nope')"
expect "part of the token" 401 'Bearer token invalid.' "$(request GET /staff "Bearer ${SECRET:0:10}")"
expect "the token without Bearer" 401 'Bearer token malformed.' "$(request GET /staff "$SECRET")"
expect "an admin's Application Password" 401 'Bearer token malformed.' "$(request GET /staff app)"
expect "the token in the query string" 401 'Bearer token missing.' "$(request GET "/staff?token=$SECRET" none)"
expect "snapshots need the token" 401 'Bearer token missing.' "$(request PUT /staff none '{"records":[]}')"
expect "club needs the token" 401 'Bearer token missing.' "$(request GET /club none)"
expect "the token writes" 201 '"result":"created"' "$(request PUT /staff/test-api-stg-1 "$TOKEN" "{$STG,\"updated_at\":\"$T1\"}")"
expect "a lower-case scheme" 200 '"id":"test-api-stg-1"' "$(request GET /staff/test-api-stg-1 "bearer $SECRET")"
expect "reads have the private fields" 200 '"date_of_birth":"1985-09-05"' "$(request GET /staff/test-api-stg-1 "$TOKEN")"
expect "a batch replies with its counts" 200 '"updated":1' "$(request POST /batch "$TOKEN" "{\"operations\":[{\"op\":\"patch\",\"type\":\"staff\",\"id\":\"test-api-stg-1\",\"data\":{\"position\":\"Tester\",\"updated_at\":\"$T2\"}}]}")"
expect "...and the record changed" 200 '"position":"Tester"' "$(request GET /staff/test-api-stg-1 "$TOKEN")"

echo "development"
site "WP_ENVIRONMENT_TYPE=development" "INTRANET_PHONELIST_API_SECRET=$SECRET"
expect "no token is accepted" 200 '"id":"test-api-stg-1"' "$(request GET /staff/test-api-stg-1 none)"
expect "a wrong token is still refused" 401 'Bearer token invalid.' "$(request GET /staff 'Bearer nope')"

echo "production, no secret"
site "WP_ENVIRONMENT_TYPE=production" "INTRANET_PHONELIST_API_SECRET="
expect "no token" 503 'not configured' "$(request GET /staff none)"
expect "any token" 503 'not configured' "$(request GET /staff 'Bearer anything')"
expect "an empty token" 503 'not configured' "$(request PUT /staff/test-api-stg-1 'Bearer ' "{$STG,\"updated_at\":\"$T2\"}")"

echo "production"
site "WP_ENVIRONMENT_TYPE=production" "INTRANET_PHONELIST_API_SECRET=$SECRET"
expect "no token" 401 'Bearer token missing.' "$(request GET /staff none)"
expect "a batch is processed" 200 '"deleted":1' "$(request POST /batch "$TOKEN" "{\"operations\":[{\"op\":\"delete\",\"type\":\"staff\",\"id\":\"test-api-stg-1\",\"data\":{\"updated_at\":\"$T2\"}}]}")"

if [ $FAILED -eq 0 ]; then echo "staging-check OK"; else echo "staging-check FAILED"; exit 1; fi
