#!/usr/bin/env bash
set -uo pipefail

: "${STAGING_URL:?STAGING_URL must be set, e.g. https://staging.example.test}"

fail=0

expect() { # name expected_code url [extra curl args...]
    local name="$1" want="$2" url="$3"; shift 3
    local got
    got=$(curl -s -o /tmp/smoke.body -w '%{http_code}' "$@" "$STAGING_URL$url")
    if [[ "$got" == "$want" ]]; then
        echo "PASS  $name ($got)"
    else
        echo "FAIL  $name (got $got, want $want)"
        fail=1
    fi
}

expect 'unauth admin delete_record' 403 '/admin/delete_record' -X POST
expect 'unauth admin update_status' 403 '/admin/update_status' -X POST
expect 'rest v130 config requires auth' 401 '/rest-api/v130/config'

exit "$fail"
