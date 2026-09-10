#!/usr/bin/env bash
set -Eeuo pipefail

repository_path="${VTSA_REPOSITORY_PATH:-/opt/vtsa-csms}"
environment_file="${VTSA_DATA_ENV_FILE:-/etc/vtsa-csms/data.env}"
compose_file="$repository_path/infra/cluster/compose.data.yaml"

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

environment_value() {
    sed -n "s/^$1=//p" "$environment_file" | tail -n 1 | tr -d '\r'
}

[[ "${EUID}" -eq 0 ]] || fail "run this command with sudo"
[[ -r "$environment_file" ]] || fail "cannot read $environment_file"
[[ -f "$compose_file" ]] || fail "cannot find $compose_file; update /opt/vtsa-csms first"
command -v docker >/dev/null 2>&1 || fail "Docker is not installed"
docker compose version >/dev/null 2>&1 || fail "the Docker Compose plugin is not installed"

if grep -Eq '(^|=)CHANGE_ME' "$environment_file"; then
    fail "$environment_file still contains CHANGE_ME placeholders"
fi

bind_address="$(environment_value DATA_BIND_ADDRESS)"
[[ -n "$bind_address" ]] || fail "DATA_BIND_ADDRESS is missing"
case "$bind_address" in
    0.0.0.0|::|127.0.0.1|localhost)
        fail "DATA_BIND_ADDRESS must be an encrypted private/VPN interface address, not $bind_address"
        ;;
esac
ip -o address show | awk '{print $4}' | cut -d/ -f1 | grep -Fxq "$bind_address" \
    || fail "DATA_BIND_ADDRESS $bind_address is not assigned to this server"

[[ "$(environment_value POSTGRES_PRODUCTION_PASSWORD)" != "$(environment_value POSTGRES_STAGING_PASSWORD)" ]] \
    || fail "production and staging PostgreSQL passwords must differ"
[[ "$(environment_value REDIS_PRODUCTION_PASSWORD)" != "$(environment_value REDIS_STAGING_PASSWORD)" ]] \
    || fail "production and staging Redis passwords must differ"
[[ "$(environment_value MINIO_PRODUCTION_ROOT_PASSWORD)" != "$(environment_value MINIO_STAGING_ROOT_PASSWORD)" ]] \
    || fail "production and staging MinIO root passwords must differ"
[[ "$(environment_value MINIO_PRODUCTION_APP_PASSWORD)" != "$(environment_value MINIO_STAGING_APP_PASSWORD)" ]] \
    || fail "production and staging MinIO application passwords must differ"
[[ "$(environment_value MINIO_PRODUCTION_ROOT_USER)" != "$(environment_value MINIO_PRODUCTION_APP_USER)" ]] \
    || fail "production MinIO root and application users must differ"
[[ "$(environment_value MINIO_STAGING_ROOT_USER)" != "$(environment_value MINIO_STAGING_APP_USER)" ]] \
    || fail "staging MinIO root and application users must differ"

compose=(docker compose --env-file "$environment_file" -f "$compose_file")

"${compose[@]}" config --quiet
"${compose[@]}" pull postgres redis-production redis-staging minio-production minio-staging
"${compose[@]}" up -d --wait postgres redis-production redis-staging minio-production minio-staging
"${compose[@]}" --profile init run --rm minio-production-init
"${compose[@]}" --profile init run --rm minio-staging-init

"${compose[@]}" exec -T postgres sh -ec \
    'PGPASSWORD="$VTSA_PRODUCTION_DB_PASSWORD" psql -h 127.0.0.1 -U vtsa_production -d vtsa_production -Atc "SELECT extversion FROM pg_extension WHERE extname = '\''postgis'\'';"' \
    | grep -Eq '^[0-9]'
"${compose[@]}" exec -T postgres sh -ec \
    'PGPASSWORD="$VTSA_STAGING_DB_PASSWORD" psql -h 127.0.0.1 -U vtsa_staging -d vtsa_staging -Atc "SELECT extversion FROM pg_extension WHERE extname = '\''postgis'\'';"' \
    | grep -Eq '^[0-9]'
"${compose[@]}" exec -T redis-production sh -ec \
    'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli ping' | grep -q PONG
"${compose[@]}" exec -T redis-staging sh -ec \
    'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli ping' | grep -q PONG

"${compose[@]}" ps
printf '\nData services are healthy. Configure both app nodes with DATA_BIND_ADDRESS=%s.\n' "$bind_address"
