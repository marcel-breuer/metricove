#!/bin/sh

set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
env_file="$repo_root/.env"
example_file="$repo_root/.env.example"

if [ -f "$env_file" ]; then
    printf '%s\n' "Found .env; leaving the existing file unchanged."
    exit 0
fi

if ! command -v openssl >/dev/null 2>&1; then
    printf '%s\n' "OpenSSL is required to generate local application secrets." >&2
    exit 1
fi

if [ ! -f "$example_file" ]; then
    printf '%s\n' ".env.example is missing." >&2
    exit 1
fi

app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
db_password=$(openssl rand -hex 32)
temporary_file=$(mktemp "$repo_root/.compose-env.XXXXXX")
trap 'rm -f "$temporary_file"' EXIT HUP INT TERM

umask 077
sed \
    -e "s|^APP_KEY=.*|APP_KEY=$app_key|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$db_password|" \
    "$example_file" > "$temporary_file"
chmod 600 "$temporary_file"
mv "$temporary_file" "$env_file"
trap - EXIT HUP INT TERM

printf '%s\n' "Created .env with fresh application and database secrets (mode 600)."
