#!/usr/bin/env bash
set -Eeuo pipefail

BASE_IMAGE_TAG="${BASE_IMAGE_TAG:-baseline}"
CANDIDATE_IMAGE_TAG="${CANDIDATE_IMAGE_TAG:-candidate}"
COMPOSE_FILE="${COMPOSE_FILE:-compose.yaml}"
COMPOSE_ENV_FILE="${COMPOSE_ENV_FILE:-.env.example}"
COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-metricove-release-smoke}"
APP_PORT="${APP_PORT:-18080}"
APP_URL="${APP_URL:-http://localhost:${APP_PORT}}"
COOKIE_JAR="$(mktemp)"

compose() {
    local image_tag="$1"
    shift
    APP_IMAGE_TAG="$image_tag" docker compose \
        --project-name "$COMPOSE_PROJECT_NAME" \
        --file "$COMPOSE_FILE" \
        --env-file "$COMPOSE_ENV_FILE" \
        "$@"
}

cleanup() {
    APP_IMAGE_TAG="$CANDIDATE_IMAGE_TAG" docker compose \
        --project-name "$COMPOSE_PROJECT_NAME" \
        --file "$COMPOSE_FILE" \
        --env-file "$COMPOSE_ENV_FILE" \
        down --timeout 15 --volumes --remove-orphans >/dev/null 2>&1 || true
    rm -f "$COOKIE_JAR"
}
trap cleanup EXIT

request_health() {
    local endpoint="$1"
    curl --silent --show-error --fail --retry 10 --retry-connrefused --retry-delay 2 "$APP_URL$endpoint" >/dev/null
}

assert_setup_status() {
    local expected="$1"
    local response
    response="$(curl --silent --show-error --fail --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" "$APP_URL/api/setup/status")"
    if ! grep -Eq "\"setup_required\"[[:space:]]*:[[:space:]]*${expected}" <<< "$response"; then
        echo "Unexpected setup status after image transition; expected setup_required=$expected." >&2
        exit 1
    fi
}

compose "$BASE_IMAGE_TAG" down --timeout 15 --volumes --remove-orphans
echo "Starting base images and waiting for healthy services."
compose "$BASE_IMAGE_TAG" up --detach --wait --wait-timeout 180
request_health "/health"
request_health "/api/health"
assert_setup_status true
xsrf_cookie="$(awk -F '\t' '$6 == "XSRF-TOKEN" { value = $7 } END { print value }' "$COOKIE_JAR")"
if [[ -z "$xsrf_cookie" ]]; then
    echo "The setup status endpoint did not issue the expected XSRF cookie." >&2
    exit 1
fi
xsrf_token="$(python3 -c 'import sys, urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))' <<< "$xsrf_cookie")"

curl --silent --show-error --fail \
    --cookie "$COOKIE_JAR" \
    --cookie-jar "$COOKIE_JAR" \
    --header 'Accept: application/json' \
    --header 'Content-Type: application/json' \
    --header "X-XSRF-TOKEN: $xsrf_token" \
    --data '{"name":"Release Smoke Owner","email":"release-smoke@example.test","password":"Metricove-Smoke-Password-1","password_confirmation":"Metricove-Smoke-Password-1"}' \
    "$APP_URL/api/setup" >/dev/null
assert_setup_status false

echo "Upgrading to candidate images and checking the persistent setup record."
compose "$CANDIDATE_IMAGE_TAG" up --detach --wait --wait-timeout 180
request_health "/health"
request_health "/api/health"
assert_setup_status false

echo "Rolling back to base images and checking the persistent setup record."
compose "$BASE_IMAGE_TAG" up --detach --wait --wait-timeout 180
request_health "/health"
request_health "/api/health"
assert_setup_status false

echo "Upgrade and rollback smoke passed; disposable Compose volumes will be removed."
