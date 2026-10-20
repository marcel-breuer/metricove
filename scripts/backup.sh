#!/usr/bin/env bash

set -Eeuo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "$script_dir/.." && pwd)"
cd "$repo_root"

compose_file="${COMPOSE_FILE:-compose.yaml}"
compose_env_file="${COMPOSE_ENV_FILE:-.env}"
compose_project_name="${COMPOSE_PROJECT_NAME:-metricove}"
backup_root="${BACKUP_DIR:-$repo_root/backups}"
created_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
backup_name="metricove-backup-$(date -u +%Y%m%dT%H%M%SZ)-$BASHPID"
final_dir="$backup_root/$backup_name"
temporary_dir=""
restart_targets=()
writers_stopped=0
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
    docker run --rm --network none --volumes-from "$api_container" \
        --user www-data --entrypoint "$1" "$api_image" "${@:2}"
}

finish() {
    local exit_status=$?
    trap - EXIT

    if (( writers_stopped )) && (("${#restart_targets[@]}" > 0)); then
        if ! compose up --detach --wait --wait-timeout 180 "${restart_targets[@]}" >/dev/null; then
            printf '%s\n' "Could not restart and verify all services that were running before the backup." >&2
            exit_status=1
        fi

        if (( gateway_was_running )); then
            if ! compose restart --timeout 30 gateway >/dev/null \
                || ! compose up --detach --wait --wait-timeout 180 gateway >/dev/null; then
                printf '%s\n' "Could not refresh and verify the gateway after restarting the API." >&2
                exit_status=1
            fi
        fi
    fi

    if [[ -n "$temporary_dir" && -d "$temporary_dir" ]]; then
        rm -rf -- "$temporary_dir"
    fi

    exit "$exit_status"
}
trap finish EXIT

if ! command -v docker >/dev/null 2>&1 || ! command -v sha256sum >/dev/null 2>&1; then
    printf '%s\n' "Docker Compose and sha256sum are required." >&2
    exit 1
fi

compose config --quiet
mkdir -p -- "$backup_root"
chmod 700 -- "$backup_root"

if [[ -e "$final_dir" ]]; then
    printf '%s\n' "A backup with the generated name already exists; retry the command." >&2
    exit 1
fi

running_services="$(compose ps --services --filter status=running)"
if grep -Fxq -- gateway <<<"$running_services"; then
    gateway_was_running=1
fi
if ! grep -Fxq -- database <<<"$running_services"; then
    printf '%s\n' "The Compose database service must be running before a backup." >&2
    exit 1
fi
if ! grep -Fxq -- api <<<"$running_services"; then
    printf '%s\n' "The Compose API service must be running so its private storage volume can be read." >&2
    exit 1
fi

for service in api worker scheduler; do
    if grep -Fxq -- "$service" <<<"$running_services"; then
        restart_targets+=("$service")
    fi
done

temporary_dir="$(mktemp -d "$backup_root/.metricove-backup.XXXXXXXX")"
chmod 700 -- "$temporary_dir"

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

compose exec -T database sh -ec \
    'pg_dump --format=custom --no-acl --username="$POSTGRES_USER" --dbname="$POSTGRES_DB"' \
    > "$temporary_dir/database.dump"

run_storage_helper tar \
    -C /var/www/html/storage/app -czf - . \
    > "$temporary_dir/app-storage.tar.gz"

printf '{\n  "format_version": 1,\n  "created_at": "%s",\n  "database_file": "database.dump",\n  "app_storage_file": "app-storage.tar.gz"\n}\n' \
    "$created_at" > "$temporary_dir/manifest.json"

(cd "$temporary_dir" && sha256sum database.dump app-storage.tar.gz manifest.json > SHA256SUMS)
chmod 600 -- "$temporary_dir"/*
mv -- "$temporary_dir" "$final_dir"
temporary_dir=""

printf '%s\n' "$final_dir"
