#!/usr/bin/env bash
set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose_file="$repo_dir/infra/compose.production.yaml"
env_file="${ENV_FILE:-$repo_dir/.env.production}"
backup_dir="${BACKUP_DIR:-$repo_dir/backups}"
compose=(docker compose --env-file "$env_file" -f "$compose_file")

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

command -v docker >/dev/null 2>&1 || fail "Docker is not installed."
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 is not available."
command -v gzip >/dev/null 2>&1 || fail "gzip is required for database backups."
[[ -f "$env_file" ]] || fail "Create $env_file from .env.production.example first."

if grep -Eq 'CHANGE_ME|(^|\.)example\.com($|[[:space:]])' "$env_file"; then
    fail "Replace every CHANGE_ME and example.com value in $env_file."
fi

if [[ "$(uname -s)" == "Linux" ]]; then
    permissions="$(stat -c '%a' "$env_file")"
    if (( 10#$permissions % 100 > 0 )); then
        fail "$env_file must not be readable by group or other users. Run: chmod 600 $env_file"
    fi
fi

cd "$repo_dir"
"${compose[@]}" config --quiet

printf 'Building immutable production images...\n'
"${compose[@]}" build --pull

printf 'Starting durable dependencies...\n'
"${compose[@]}" up --detach --wait --wait-timeout 120 postgres redis minio
"${compose[@]}" up --no-log-prefix minio-init

mkdir -p "$backup_dir"
backup_file="$backup_dir/postgres-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
printf 'Creating pre-migration PostgreSQL backup at %s...\n' "$backup_file"
"${compose[@]}" exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB"' | gzip -9 > "$backup_file"
chmod 600 "$backup_file"

printf 'Applying database migrations...\n'
"${compose[@]}" run --rm platform php artisan migrate --force
"${compose[@]}" run --rm platform php artisan security:sync-permissions

printf 'Starting application services...\n'
"${compose[@]}" up --detach --remove-orphans --wait --wait-timeout 180
"${compose[@]}" exec -T platform php artisan optimize
"${compose[@]}" exec -T platform php artisan queue:restart

printf 'Verifying application readiness...\n'
for attempt in $(seq 1 30); do
    if "${compose[@]}" exec -T web wget --quiet --spider http://127.0.0.1:8080/health/ready; then
        "${compose[@]}" ps
        printf '\nDeployment completed successfully.\n'
        exit 0
    fi
    sleep 2
done

"${compose[@]}" ps
"${compose[@]}" logs --tail=100 platform web caddy >&2
fail "The application did not become ready within 60 seconds."
