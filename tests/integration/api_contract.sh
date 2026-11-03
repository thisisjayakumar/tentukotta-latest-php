#!/usr/bin/env bash
#
# Integration contract checks against a running staging instance.
#
#   STAGING_URL=http://localhost:8080 ./tests/integration/api_contract.sh
#
# These exercise real HTTP behaviour: public pages, denied paths, admin gates,
# REST authentication and the legacy mobile API contract. The API-key success
# cases need the local MySQL credentials in .env; without them they are SKIPped.

set -uo pipefail

: "${STAGING_URL:?STAGING_URL must be set, e.g. http://localhost:8080}"

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
fail=0

if [ -f "$ROOT/.env" ]; then
    set -a
    # shellcheck disable=SC1090
    . "$ROOT/.env"
    set +a
fi

pass() { echo "PASS  $1"; }
failure() { echo "FAIL  $1"; fail=1; }
skip() { echo "SKIP  $1 ($2)"; }

expect_status() { # name expected url [curl args...]
    local name="$1" want="$2" url="$3"; shift 3
    local got
    got=$(curl -s -o /dev/null -w '%{http_code}' "$@" "$STAGING_URL$url")
    if [[ "$got" == "$want" ]]; then pass "$name ($got)"; else
        failure "$name (got $got, want $want)"; fi
}

expect_body() { # name pattern url [curl args...]
    local name="$1" pattern="$2" url="$3"; shift 3
    local body code
    body=$(mktemp)
    code=$(curl -s -o "$body" -w '%{http_code}' "$@" "$STAGING_URL$url")
    if grep -q -- "$pattern" "$body"; then pass "$name ($code)"; else
        failure "$name (body did not contain: $pattern)"; fi
    rm -f "$body"
}

expect_header() { # name header
    local name="$1" header="$2"
    if curl -sI "$STAGING_URL/" | grep -qi "^${header}:"; then pass "$name"; else
        failure "$name (missing $header)"; fi
}

# --- public pages ------------------------------------------------------------
expect_status 'home page' 200 '/'
expect_status 'movies page' 200 '/movies'
expect_status 'search page' 200 '/search?q=movie'
expect_status 'login page' 200 '/login'

# --- denied paths ------------------------------------------------------------
expect_status 'db_backup denied' 403 '/db_backup/'
expect_status 'application directory denied' 403 '/application/config/database.php'

# --- admin gate --------------------------------------------------------------
expect_status 'anonymous admin delete_record' 403 '/admin/delete_record' -X POST
expect_status 'anonymous admin update_status' 403 '/admin/update_status' -X POST

# --- REST API ----------------------------------------------------------------
expect_status 'rest v130 requires a key' 401 '/rest-api/v130/config'
expect_body 'rest v130 401 body is JSON' 'Unauthorized' '/rest-api/v130/config'

# --- legacy mobile API contract ---------------------------------------------
expect_body 'legacy api without key reports an error' 'API secret key' '/api/test'

# --- security headers --------------------------------------------------------
expect_header 'X-Content-Type-Options header' 'X-Content-Type-Options'
expect_header 'X-Frame-Options header' 'X-Frame-Options'
expect_header 'Referrer-Policy header' 'Referrer-Policy'

# --- authenticated API contract (needs local DB) -----------------------------
API_KEY=""
if command -v mysql >/dev/null 2>&1 && [ -n "${DB_DATABASE:-}" ]; then
    API_KEY=$(mysql -N -B \
        -h"${DB_HOSTNAME:-localhost}" \
        -u"${DB_USERNAME:-}" \
        ${DB_PASSWORD:+-p"$DB_PASSWORD"} \
        "$DB_DATABASE" \
        -e "select value from config where title='mobile_apps_api_secret_key'" 2>/dev/null || true)
fi

if [ -z "$API_KEY" ]; then
    skip 'rest v130 with X-API-KEY' 'no local DB credentials'
    skip 'legacy api with api_secret_key parameter' 'no local DB credentials'
else
    expect_body 'rest v130 with X-API-KEY' 'app_config' '/rest-api/v130/config' \
        -H "X-API-KEY: $API_KEY"
    expect_body 'legacy api with api_secret_key parameter' 'api key is verified' '/api/test' \
        --get --data-urlencode "api_secret_key=$API_KEY"
fi

exit "$fail"
