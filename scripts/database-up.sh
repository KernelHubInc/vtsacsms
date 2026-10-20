#!/usr/bin/env bash
set -Eeuo pipefail

repository_path="${VTSA_REPOSITORY_PATH:-/opt/vtsa-csms}"
environment_file="${VTSA_DATABASE_ENV_FILE:-/etc/vtsa-csms/database.env}"
compose_file="$repository_path/infra/database/compose.yaml"

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

environment_value() {
    sed -n "s/^$1=//p" "$environment_file" | tail -n 1 | tr -d '\r'
}

[[ -r "$environment_file" ]] || fail "cannot read $environment_file"
[[ -f "$compose_file" ]] || fail "cannot find $compose_file"
command -v docker >/dev/null 2>&1 || fail "Docker is not installed"
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 is not available"

if grep -Eq '(^|=)CHANGE_ME' "$environment_file"; then
    fail "$environment_file still contains CHANGE_ME placeholders"
fi

[[ "$(environment_value POSTGRES_APP_USER)" == "vtsa_app" ]] \
    || fail "POSTGRES_APP_USER must be vtsa_app"
[[ "$(environment_value POSTGRES_PRODUCTION_DATABASE)" == "vtsa_production" ]] \
    || fail "POSTGRES_PRODUCTION_DATABASE must be vtsa_production"
[[ "$(environment_value POSTGRES_STAGING_DATABASE)" == "vtsa_staging" ]] \
    || fail "POSTGRES_STAGING_DATABASE must be vtsa_staging"

admin_password="$(environment_value POSTGRES_ADMIN_PASSWORD)"
app_password="$(environment_value POSTGRES_APP_PASSWORD)"
[[ ${#admin_password} -ge 24 ]] || fail "POSTGRES_ADMIN_PASSWORD must be at least 24 characters"
[[ ${#app_password} -ge 24 ]] || fail "POSTGRES_APP_PASSWORD must be at least 24 characters"
[[ "$admin_password" != "$app_password" ]] \
    || fail "PostgreSQL admin and application passwords must differ"

bind_address="$(environment_value POSTGRES_BIND_ADDRESS)"
case "$bind_address" in
    ""|0.0.0.0|::)
        fail "POSTGRES_BIND_ADDRESS must be loopback or an encrypted private/VPN address"
        ;;
esac

if [[ "$bind_address" != "127.0.0.1" ]]; then
    ip -o address show | awk '{print $4}' | cut -d/ -f1 | grep -Fxq "$bind_address" \
        || fail "POSTGRES_BIND_ADDRESS $bind_address is not assigned to this server"
fi

compose=(docker compose --env-file "$environment_file" -f "$compose_file")
"${compose[@]}" config --quiet
"${compose[@]}" pull postgres
"${compose[@]}" up --detach --wait postgres
"${compose[@]}" exec -T postgres bash /opt/vtsa/provision-vtsa-databases.sh

for database in vtsa_production vtsa_staging; do
    "${compose[@]}" exec -T postgres sh -ec \
        "PGPASSWORD=\"\$VTSA_APP_PASSWORD\" psql -h 127.0.0.1 -U vtsa_app -d $database -Atc 'SELECT current_database(), current_user, postgis_version();'" \
        | grep -Fq "$database|vtsa_app|" \
        || fail "$database verification failed"
done

"${compose[@]}" ps
postgres_port="$(environment_value POSTGRES_PORT)"
printf '\nPostgreSQL is ready on %s:%s.\n' \
    "$bind_address" "${postgres_port:-5432}"
