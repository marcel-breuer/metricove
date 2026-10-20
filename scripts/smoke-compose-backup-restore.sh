#!/usr/bin/env bash

set -Eeuo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "$script_dir/.." && pwd)"
cd "$repo_root"

compose_file="$repo_root/compose.yaml"
compose_env_file="$repo_root/.env.example"
compose_project_name="metricove-backup-restore-smoke-$BASHPID"
app_port="${APP_PORT:-18081}"
app_url="http://localhost:$app_port"
app_image_tag="${APP_IMAGE_TAG:-local}"
build_images="${BUILD_IMAGES:-1}"
keep_on_failure="${KEEP_SMOKE_STACK_ON_FAILURE:-0}"
smoke_dir="$(mktemp -d "${TMPDIR:-/tmp}/metricove-backup-restore.XXXXXXXX")"
cookie_jar="$smoke_dir/cookies.txt"

export APP_ENV=testing
export APP_DEBUG=false
export APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
export APP_URL="$app_url"
export APP_PORT="$app_port"
export APP_IMAGE_TAG="$app_image_tag"
export DB_DATABASE=metricove_backup_smoke
export DB_USERNAME=metricove_backup_smoke
export DB_PASSWORD=metricove_backup_smoke_only
export MAIL_MAILER=array
export COMPOSE_FILE="$compose_file"
export COMPOSE_ENV_FILE="$compose_env_file"
export COMPOSE_PROJECT_NAME="$compose_project_name"
export BACKUP_DIR="$smoke_dir/backups"
export SESSION_ENCRYPT=false

compose() {
    docker compose \
        --project-name "$compose_project_name" \
        --file "$compose_file" \
        --env-file "$compose_env_file" \
        "$@"
}

cleanup() {
    local exit_status=$?
    trap - EXIT

    if (( exit_status != 0 )) && [[ "$keep_on_failure" == 1 ]]; then
        printf 'Preserving failed smoke stack %s and artifacts in %s\n' \
            "$compose_project_name" "$smoke_dir" >&2
        exit "$exit_status"
    fi

    compose down --timeout 15 --volumes --remove-orphans >/dev/null 2>&1 || true
    rm -rf -- "$smoke_dir"
    exit "$exit_status"
}
trap cleanup EXIT

case "$build_images" in
    0) ;;
    1) compose build --parallel ;;
    *)
        printf '%s\n' "BUILD_IMAGES must be 0 or 1." >&2
        exit 2
        ;;
esac

compose config --quiet
compose up --detach --wait --wait-timeout 180

for endpoint in /health /api/health; do
    curl --silent --show-error --fail --retry 10 --retry-connrefused --retry-delay 2 \
        "$app_url$endpoint" >/dev/null
done

setup_status="$(curl --silent --show-error --fail \
    --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$app_url/api/setup/status")"
if ! grep -Eq '"setup_required"[[:space:]]*:[[:space:]]*true' <<<"$setup_status"; then
    printf '%s\n' "The fresh recovery-smoke database unexpectedly contains a completed setup." >&2
    exit 1
fi

xsrf_cookie="$(awk -F '\t' '$6 == "XSRF-TOKEN" { value = $7 } END { print value }' "$cookie_jar")"
if [[ -z "$xsrf_cookie" ]]; then
    printf '%s\n' "The setup status endpoint did not issue the expected XSRF cookie." >&2
    exit 1
fi
xsrf_token="$(python3 -c 'import sys, urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))' <<<"$xsrf_cookie")"

curl --silent --show-error --fail \
    --cookie "$cookie_jar" \
    --cookie-jar "$cookie_jar" \
    --header 'Accept: application/json' \
    --header 'Content-Type: application/json' \
    --header "X-XSRF-TOKEN: $xsrf_token" \
    --data '{"name":"Backup Smoke Owner","email":"backup-smoke@example.test","password":"Metricove-Smoke-Password-1","password_confirmation":"Metricove-Smoke-Password-1"}' \
    "$app_url/api/setup" >/dev/null

compose exec -T api sh -ec \
    'mkdir -p /var/www/html/storage/app/private && printf "%s\n" "original-media-marker" > /var/www/html/storage/app/private/backup-restore-probe.txt && chmod 600 /var/www/html/storage/app/private/backup-restore-probe.txt'

compose exec -T database psql -v ON_ERROR_STOP=1 \
    --username "$DB_USERNAME" --dbname "$DB_DATABASE" <<'SQL'
CREATE TABLE backup_restore_probe (value text NOT NULL);
INSERT INTO backup_restore_probe (value) VALUES ('original-database-marker');
SQL

backup_path="$(scripts/backup.sh)"

compose exec -T database psql -v ON_ERROR_STOP=1 \
    --username "$DB_USERNAME" --dbname "$DB_DATABASE" \
    --command="UPDATE backup_restore_probe SET value = 'mutated-database-marker'"
compose exec -T api rm -f /var/www/html/storage/app/private/backup-restore-probe.txt

scripts/restore-backup.sh "$backup_path" --apply

database_marker="$(compose exec -T database psql --username "$DB_USERNAME" --dbname "$DB_DATABASE" --tuples-only --no-align \
    --command='SELECT value FROM backup_restore_probe')"
if [[ "$database_marker" != "original-database-marker" ]]; then
    printf '%s\n' "The PostgreSQL marker was not restored from the backup." >&2
    exit 1
fi

storage_marker="$(compose exec -T api cat /var/www/html/storage/app/private/backup-restore-probe.txt)"
if [[ "$storage_marker" != "original-media-marker" ]]; then
    printf '%s\n' "The app_storage marker was not restored from the backup." >&2
    exit 1
fi

restored_setup="$(curl --silent --show-error --fail --retry 10 --retry-all-errors --retry-delay 2 \
    --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$app_url/api/setup/status")"
if ! grep -Eq '"setup_required"[[:space:]]*:[[:space:]]*false' <<<"$restored_setup"; then
    printf '%s\n' "The initial setup record was not present after restore." >&2
    exit 1
fi

printf '%s\n' "Compose backup/restore smoke passed for PostgreSQL and app_storage."
