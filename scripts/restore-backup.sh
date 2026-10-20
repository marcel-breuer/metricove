#!/usr/bin/env bash

set -Eeuo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "$script_dir/.." && pwd)"
cd "$repo_root"

usage() {
    printf 'Usage: %s BACKUP_DIRECTORY --apply\n' "$0" >&2
}

if (("$#" != 2)) || [[ "$2" != "--apply" ]]; then
    usage
    printf '%s\n' "Restoring replaces the configured Compose database and app_storage volume; pass --apply to continue." >&2
    exit 2
fi

if [[ ! -d "$1" ]]; then
    printf '%s\n' "The backup directory does not exist." >&2
    exit 1
fi

backup_path="$(cd -- "$1" && pwd -P)"
compose_file="${COMPOSE_FILE:-compose.yaml}"
compose_env_file="${COMPOSE_ENV_FILE:-.env}"
compose_project_name="${COMPOSE_PROJECT_NAME:-metricove}"
restore_suffix="$(date -u +%Y%m%d%H%M%S)_$BASHPID"
restart_targets=()
writers_stopped=0
safe_to_restart=1
gateway_was_running=0

compose() {
    docker compose \
        --project-name "$compose_project_name" \
        --file "$compose_file" \
        --env-file "$compose_env_file" \
        "$@"
}

run_storage_helper() {
    local api_container api_image
    api_container="$(compose ps --all --quiet api)"

    if [[ -z "$api_container" || "$api_container" == *$'\n'* ]]; then
        printf '%s\n' "Expected exactly one Compose API container for app_storage access." >&2
        return 1
    fi

    api_image="$(docker inspect --format '{{.Config.Image}}' "$api_container")"
    docker run --interactive --rm --network none --volumes-from "$api_container" \
        --user www-data --entrypoint "$1" "$api_image" "${@:2}"
}

finish() {
    local exit_status=$?
    trap - EXIT

    if (( writers_stopped )); then
        if (( safe_to_restart )) && (("${#restart_targets[@]}" > 0)); then
            if ! compose up --detach --wait --wait-timeout 180 "${restart_targets[@]}" >/dev/null; then
                printf '%s\n' "Could not restart and verify all services that were running before the restore." >&2
                exit_status=1
            fi

            if (( gateway_was_running )); then
                if ! compose restart --timeout 30 gateway >/dev/null \
                    || ! compose up --detach --wait --wait-timeout 180 gateway >/dev/null; then
                    printf '%s\n' "Could not refresh and verify the gateway after restarting the API." >&2
                    exit_status=1
                fi
            fi
        elif ((! safe_to_restart)); then
            printf '%s\n' "Restore stopped before database and file recovery both completed; API, worker, and scheduler remain stopped." >&2
        fi
    fi

    exit "$exit_status"
}
trap finish EXIT

if [[ ! -f "$backup_path/database.dump" || -L "$backup_path/database.dump" \
    || ! -f "$backup_path/app-storage.tar.gz" || -L "$backup_path/app-storage.tar.gz" \
    || ! -f "$backup_path/manifest.json" || -L "$backup_path/manifest.json" \
    || ! -f "$backup_path/SHA256SUMS" || -L "$backup_path/SHA256SUMS" ]]; then
    printf '%s\n' "The backup bundle is incomplete or contains an unexpected symlink." >&2
    exit 1
fi

if ! grep -Fxq -- '  "format_version": 1,' "$backup_path/manifest.json"; then
    printf '%s\n' "The backup format is unsupported." >&2
    exit 1
fi

if ! awk '
    NF != 2 || length($1) != 64 || $1 !~ /^[[:xdigit:]]+$/ { exit 1 }
    ($2 != "database.dump" && $2 != "app-storage.tar.gz" && $2 != "manifest.json") { exit 1 }
    seen[$2]++ { exit 1 }
    END { if (NR != 3) exit 1 }
' "$backup_path/SHA256SUMS"; then
    printf '%s\n' "The checksum manifest is invalid." >&2
    exit 1
fi

if ! (cd "$backup_path" && sha256sum --check --strict SHA256SUMS >/dev/null); then
    printf '%s\n' "Backup checksum verification failed." >&2
    exit 1
fi

if ! tar --absolute-names -tzf "$backup_path/app-storage.tar.gz" | awk '
    /^\// { bad = 1; exit 1 }
    {
        count = split($0, parts, "/")
        for (part_index = 1; part_index <= count; part_index++) {
            if (parts[part_index] == "..") {
                bad = 1
                exit 1
            }
        }
    }
    END { if (bad) exit 1 }
'; then
    printf '%s\n' "The app_storage archive contains an invalid path." >&2
    exit 1
fi

if ! tar -tvzf "$backup_path/app-storage.tar.gz" | awk '
    substr($0, 1, 1) != "-" && substr($0, 1, 1) != "d" { exit 1 }
'; then
    printf '%s\n' "The app_storage archive contains a non-file entry." >&2
    exit 1
fi

compose config --quiet
running_services="$(compose ps --services --filter status=running)"
if grep -Fxq -- gateway <<<"$running_services"; then
    gateway_was_running=1
fi
if ! grep -Fxq -- database <<<"$running_services" || ! grep -Fxq -- api <<<"$running_services"; then
    printf '%s\n' "The Compose database and API services must be running before a restore." >&2
    exit 1
fi
if ! compose exec -T database pg_restore --list < "$backup_path/database.dump" >/dev/null; then
    printf '%s\n' "The PostgreSQL backup archive is invalid." >&2
    exit 1
fi

for service in api worker scheduler; do
    if grep -Fxq -- "$service" <<<"$running_services"; then
        restart_targets+=("$service")
    fi
done

writers_stopped=1
for service in worker scheduler api; do
    if printf '%s\n' "${restart_targets[@]}" | grep -Fxq -- "$service"; then
        case "$service" in
            worker) stop_timeout=120 ;;
            scheduler) stop_timeout=30 ;;
            api) stop_timeout=30 ;;
        esac
        compose stop --timeout "$stop_timeout" "$service" >/dev/null
    fi
done

compose exec -T -e RESTORE_SUFFIX="$restore_suffix" database sh -ec '
    set -eu
    target="$POSTGRES_DB"
    case "$target" in
        ""|postgres|template0|template1|*[!a-zA-Z0-9_]*)
            printf "%s\n" "The configured database name is not safe to replace." >&2
            exit 1
            ;;
    esac
    if [ "${#target}" -gt 63 ]; then
        printf "%s\n" "The configured database name exceeds PostgreSQL identifier limits." >&2
        exit 1
    fi

    restore_db="metricove_restore_${RESTORE_SUFFIX}"
    target_exists="$(psql --username="$POSTGRES_USER" --dbname=postgres --tuples-only --no-align \
        --command="SELECT 1 FROM pg_database WHERE datname = '\''$target'\''")"
    if [ "$target_exists" != "1" ]; then
        printf "%s\n" "The configured application database does not exist." >&2
        exit 1
    fi

    createdb --username="$POSTGRES_USER" --owner="$POSTGRES_USER" "$restore_db"
    cleanup_restore_db() {
        dropdb --if-exists --username="$POSTGRES_USER" --maintenance-db=postgres "$restore_db" >/dev/null 2>&1 || true
    }
    trap cleanup_restore_db EXIT
    pg_restore --exit-on-error --no-owner --no-privileges --username="$POSTGRES_USER" --dbname="$restore_db"
    trap - EXIT
' < "$backup_path/database.dump"

safe_to_restart=0
compose exec -T -e RESTORE_SUFFIX="$restore_suffix" database sh -ec '
    set -eu
    target="$POSTGRES_DB"
    previous_db="metricove_previous_${RESTORE_SUFFIX}"
    restore_db="metricove_restore_${RESTORE_SUFFIX}"
    psql --username="$POSTGRES_USER" --dbname=postgres --set=ON_ERROR_STOP=1 \
        --command="ALTER DATABASE \"$target\" RENAME TO \"$previous_db\""
    if ! psql --username="$POSTGRES_USER" --dbname=postgres --set=ON_ERROR_STOP=1 \
        --command="ALTER DATABASE \"$restore_db\" RENAME TO \"$target\""; then
        psql --username="$POSTGRES_USER" --dbname=postgres --set=ON_ERROR_STOP=1 \
            --command="ALTER DATABASE \"$previous_db\" RENAME TO \"$target\"" || true
        exit 1
    fi
'

# These helpers only replace the named volume; do not let one claim the stopped API's IP.
run_storage_helper /bin/sh -ec \
    'find /var/www/html/storage/app -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +'
run_storage_helper tar \
    --no-same-owner --no-same-permissions -xzf - -C /var/www/html/storage/app \
    < "$backup_path/app-storage.tar.gz"

compose exec -T -e RESTORE_SUFFIX="$restore_suffix" database sh -ec \
    'dropdb --username="$POSTGRES_USER" --maintenance-db=postgres "metricove_previous_${RESTORE_SUFFIX}"'

safe_to_restart=1
printf '%s\n' "Database and app_storage were restored from the verified backup bundle."
