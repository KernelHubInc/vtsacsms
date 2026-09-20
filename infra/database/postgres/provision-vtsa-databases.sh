#!/usr/bin/env bash
set -Eeuo pipefail

: "${POSTGRES_USER:?POSTGRES_USER is required}"
: "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD is required}"
: "${VTSA_APP_USER:?VTSA_APP_USER is required}"
: "${VTSA_APP_PASSWORD:?VTSA_APP_PASSWORD is required}"
: "${VTSA_PRODUCTION_DATABASE:?VTSA_PRODUCTION_DATABASE is required}"
: "${VTSA_STAGING_DATABASE:?VTSA_STAGING_DATABASE is required}"

case "$VTSA_APP_USER" in
    vtsa_app) ;;
    *) printf 'ERROR: VTSA_APP_USER must be vtsa_app.\n' >&2; exit 1 ;;
esac

for database in "$VTSA_PRODUCTION_DATABASE" "$VTSA_STAGING_DATABASE"; do
    case "$database" in
        vtsa_production|vtsa_staging) ;;
        *) printf 'ERROR: unsupported database name: %s\n' "$database" >&2; exit 1 ;;
    esac
done

psql --variable=ON_ERROR_STOP=1 \
    --username "$POSTGRES_USER" \
    --dbname postgres \
    --set=admin_user="$POSTGRES_USER" \
    --set=admin_password="$POSTGRES_PASSWORD" \
    --set=app_user="$VTSA_APP_USER" \
    --set=app_password="$VTSA_APP_PASSWORD" \
    --set=production_database="$VTSA_PRODUCTION_DATABASE" \
    --set=staging_database="$VTSA_STAGING_DATABASE" <<'SQL'
SELECT format('ALTER ROLE %I WITH LOGIN PASSWORD %L', :'admin_user', :'admin_password') \gexec

SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'app_user', :'app_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'app_user') \gexec

SELECT format('ALTER ROLE %I WITH LOGIN PASSWORD %L', :'app_user', :'app_password') \gexec

SELECT format('CREATE DATABASE %I OWNER %I', :'production_database', :'app_user')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'production_database') \gexec

SELECT format('CREATE DATABASE %I OWNER %I', :'staging_database', :'app_user')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'staging_database') \gexec

SELECT format('ALTER DATABASE %I OWNER TO %I', :'production_database', :'app_user') \gexec
SELECT format('ALTER DATABASE %I OWNER TO %I', :'staging_database', :'app_user') \gexec
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', :'production_database') \gexec
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', :'staging_database') \gexec
SELECT format('GRANT CONNECT, TEMPORARY ON DATABASE %I TO %I', :'production_database', :'app_user') \gexec
SELECT format('GRANT CONNECT, TEMPORARY ON DATABASE %I TO %I', :'staging_database', :'app_user') \gexec
SELECT format('ALTER DATABASE %I SET timezone TO %L', :'production_database', 'UTC') \gexec
SELECT format('ALTER DATABASE %I SET timezone TO %L', :'staging_database', 'UTC') \gexec
SQL

for database in "$VTSA_PRODUCTION_DATABASE" "$VTSA_STAGING_DATABASE"; do
    psql --variable=ON_ERROR_STOP=1 \
        --username "$POSTGRES_USER" \
        --dbname "$database" \
        --set=app_user="$VTSA_APP_USER" <<'SQL'
CREATE EXTENSION IF NOT EXISTS postgis;
SELECT format('ALTER SCHEMA public OWNER TO %I', :'app_user') \gexec
SELECT format('GRANT USAGE, CREATE ON SCHEMA public TO %I', :'app_user') \gexec
SELECT format('GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO %I', :'app_user') \gexec
SELECT format('GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO %I', :'app_user') \gexec
SELECT format('GRANT ALL PRIVILEGES ON ALL FUNCTIONS IN SCHEMA public TO %I', :'app_user') \gexec
SQL
done

printf 'PostgreSQL databases %s and %s are ready for %s.\n' \
    "$VTSA_PRODUCTION_DATABASE" "$VTSA_STAGING_DATABASE" "$VTSA_APP_USER"
