#!/usr/bin/env bash

set -Eeuo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$script_dir"

fail() {
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

if ! command -v docker >/dev/null 2>&1; then
    fail 'Docker is not installed or is not on PATH. Install Docker Engine with the Compose plugin, then run ./start.sh again.'
fi

if ! docker compose version >/dev/null 2>&1; then
    fail 'Docker Compose v2 is unavailable. Install or enable the Docker Compose plugin, then run ./start.sh again.'
fi

start_docker_daemon() {
    case "$(uname -s)" in
        Darwin)
            if command -v open >/dev/null 2>&1; then
                open -a Docker >/dev/null 2>&1 || true
            fi
            ;;
        Linux)
            if command -v systemctl >/dev/null 2>&1; then
                if [[ "$(id -u)" -eq 0 ]]; then
                    systemctl start docker || fail 'Could not start Docker with systemctl.'
                elif command -v sudo >/dev/null 2>&1; then
                    sudo systemctl start docker || fail 'Could not start Docker. Check that your account can use sudo.'
                else
                    fail 'Docker is installed but not running. Start the Docker service, then run ./start.sh again.'
                fi
            elif command -v service >/dev/null 2>&1; then
                if [[ "$(id -u)" -eq 0 ]]; then
                    service docker start || fail 'Could not start Docker with the service command.'
                elif command -v sudo >/dev/null 2>&1; then
                    sudo service docker start || fail 'Could not start Docker. Check that your account can use sudo.'
                else
                    fail 'Docker is installed but not running. Start the Docker service, then run ./start.sh again.'
                fi
            else
                fail 'Docker is installed but not running. Start Docker manually, then run ./start.sh again.'
            fi
            ;;
        *)
            fail 'Docker is installed but not running. Start Docker Desktop or the Docker service, then run ./start.sh again.'
            ;;
    esac
}

if ! docker info >/dev/null 2>&1; then
    printf '%s\n' 'Docker is not ready; attempting to start the local Docker service.'
    start_docker_daemon

    ready=0
    for _ in {1..60}; do
        if docker info >/dev/null 2>&1; then
            ready=1
            break
        fi
        sleep 1
    done

    if [[ "$ready" -ne 1 ]]; then
        fail 'Docker did not become ready within 60 seconds. Start it manually and check access to the Docker socket.'
    fi
fi

"$script_dir/scripts/prepare-compose-env.sh"

printf '%s\n' 'Validating Docker Compose configuration.'
docker compose --env-file .env config --quiet

printf '%s\n' 'Building Metricove and starting the complete stack. Persistent data will be kept.'
docker compose --env-file .env up --detach --build --wait --wait-timeout 180

app_port="${APP_PORT:-$(sed -n 's/^[[:space:]]*APP_PORT[[:space:]]*=[[:space:]]*//p' .env | tail -n 1 | tr -d "\"'[:space:]")}"
app_port="${app_port:-8080}"

printf '\nMetricove is ready at http://localhost:%s\n' "$app_port"
printf 'Complete first-time household setup at http://localhost:%s/setup\n' "$app_port"
printf '%s\n' 'Run ./start.sh again to rebuild and start the stack after a restart or update.'
