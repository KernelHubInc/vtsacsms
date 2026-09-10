#!/usr/bin/env bash
set -Eeuo pipefail

repo_dir="${VTSA_REPO_DIR:-/opt/vtsa-csms}"
compose_file="$repo_dir/infra/cluster/compose.app.yaml"
environment="${1:-}"
release_tag="${2:-}"
migrate="${3:-}"

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

[[ "$environment" == "staging" || "$environment" == "production" ]] || \
    fail "Usage: vtsa-deploy-app staging|production sha-<40-character-commit> [--migrate]"
env_file="${VTSA_APP_ENV_FILE:-/etc/vtsa-csms/$environment.env}"
state_dir="/var/lib/vtsa-csms/$environment"
backup_dir="/var/backups/vtsa-csms/$environment"
state_file="$state_dir/current-app-image-tag"

[[ "$release_tag" =~ ^sha-[0-9a-f]{40}$ ]] || \
    fail "Usage: vtsa-deploy-app staging|production sha-<40-character-commit> [--migrate]"
[[ -z "$migrate" || "$migrate" == "--migrate" ]] || fail "The only supported option is --migrate."
[[ -d "$repo_dir/.git" ]] || fail "$repo_dir is not a Git checkout."
[[ -f "$env_file" ]] || fail "$env_file does not exist."
command -v docker >/dev/null 2>&1 || fail "Docker is not installed."
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 is not available."

exec 9>/var/lock/vtsa-csms-app-deploy.lock
flock -n 9 || fail "Another staging or production deployment is already running on this node."

if grep -Eq 'CHANGE_ME|(^|\.)example\.com($|[[:space:]])' "$env_file"; then
    fail "Replace every CHANGE_ME and example.com value in $env_file."
fi
grep -qx "DEPLOY_ENVIRONMENT=$environment" "$env_file" || \
    fail "$env_file must contain DEPLOY_ENVIRONMENT=$environment."

require_setting() {
    local key="$1"
    local expected="$2"
    grep -Fqx "$key=$expected" "$env_file" || \
        fail "$env_file must contain $key=$expected to preserve environment isolation."
}

if [[ "$environment" == "staging" ]]; then
    require_setting COMPOSE_PROJECT_NAME vtsa-csms-staging
    require_setting APP_HTTP_PORT 8081
    require_setting OCPP_PUBLIC_PORT 9001
    require_setting DB_DATABASE vtsa_staging
    require_setting DB_USERNAME vtsa_staging
    require_setting REDIS_PORT 6380
    require_setting REDIS_PREFIX vtsa:staging:
    require_setting MINIO_BUCKET vtsa-staging
    other_env_file="/etc/vtsa-csms/production.env"
else
    require_setting COMPOSE_PROJECT_NAME vtsa-csms-production
    require_setting APP_HTTP_PORT 80
    require_setting OCPP_PUBLIC_PORT 9000
    require_setting DB_DATABASE vtsa_production
    require_setting DB_USERNAME vtsa_production
    require_setting REDIS_PORT 6379
    require_setting REDIS_PREFIX vtsa:production:
    require_setting MINIO_BUCKET vtsa-production
    other_env_file="/etc/vtsa-csms/staging.env"
fi

setting_value() {
    local file="$1"
    local key="$2"
    sed -n "s/^$key=//p" "$file" | head -n 1
}

if [[ -f "$other_env_file" ]]; then
    for secret_key in APP_KEY DB_PASSWORD REDIS_PASSWORD MINIO_ACCESS_KEY GATEWAY_INTERNAL_API_TOKEN; do
        current_value="$(setting_value "$env_file" "$secret_key")"
        other_value="$(setting_value "$other_env_file" "$secret_key")"
        [[ -n "$current_value" && "$current_value" != "$other_value" ]] || \
            fail "$secret_key must be set and different between staging and production."
    done
fi

commit="${release_tag#sha-}"
previous_tag="$(cat "$state_file" 2>/dev/null || true)"
export IMAGE_TAG="$release_tag"
compose=(docker compose --env-file "$env_file" -f "$compose_file")

cd "$repo_dir"
git fetch --quiet --prune origin main
git cat-file -e "$commit^{commit}" 2>/dev/null || fail "Commit $commit was not fetched from origin."
git checkout --quiet --detach "$commit"
"${compose[@]}" config --quiet

printf 'Pulling %s release %s...\n' "$environment" "$release_tag"
"${compose[@]}" pull

if [[ "$migrate" == "--migrate" ]]; then
    mkdir -p "$backup_dir"
    backup_file="$backup_dir/postgres-$(date -u +%Y%m%dT%H%M%SZ).dump"
    partial_backup="$backup_file.part"
    printf 'Creating pre-migration database backup...\n'
    if ! "${compose[@]}" --profile tools run --rm -T db-tools \
        'pg_dump --format=custom --no-owner --no-privileges' > "$partial_backup"; then
        rm -f "$partial_backup"
        fail "Database backup failed; migration was not attempted."
    fi
    mv "$partial_backup" "$backup_file"
    chmod 600 "$backup_file"

    printf 'Applying backward-compatible migrations once...\n'
    "${compose[@]}" run --rm -T platform php artisan migrate --force
    "${compose[@]}" run --rm -T platform php artisan security:sync-permissions
fi

rollback() {
    if [[ "$previous_tag" =~ ^sha-[0-9a-f]{40}$ ]]; then
        printf 'Restoring previous application image %s...\n' "$previous_tag" >&2
        export IMAGE_TAG="$previous_tag"
        git checkout --quiet --detach "${previous_tag#sha-}"
        docker compose --env-file "$env_file" -f "$repo_dir/infra/cluster/compose.app.yaml" \
            up --detach --remove-orphans --wait --wait-timeout 180 || true
    fi
}

printf 'Replacing this %s node while its peer remains online...\n' "$environment"
if ! "${compose[@]}" up --detach --remove-orphans --wait --wait-timeout 180; then
    rollback
    fail "Containers did not become healthy."
fi

if ! "${compose[@]}" exec -T platform php artisan optimize; then
    rollback
    fail "Laravel optimization failed."
fi
"${compose[@]}" exec -T platform php artisan queue:restart

ready=false
for attempt in $(seq 1 30); do
    if "${compose[@]}" exec -T web wget --quiet --spider http://127.0.0.1:8080/health/ready; then
        ready=true
        break
    fi
    sleep 2
done

if [[ "$ready" != true ]]; then
    rollback
    fail "Node readiness failed; the previous image was restored when available."
fi

mkdir -p "$state_dir"
printf '%s\n' "$release_tag" > "$state_file"
chmod 600 "$state_file"
"${compose[@]}" ps
printf '%s application node is ready on release %s.\n' "$environment" "$release_tag"
