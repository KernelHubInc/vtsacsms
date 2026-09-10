#!/usr/bin/env bash
set -Eeuo pipefail

: "${VTSA_PRODUCTION_DB_PASSWORD:?VTSA_PRODUCTION_DB_PASSWORD is required}"
: "${VTSA_STAGING_DB_PASSWORD:?VTSA_STAGING_DB_PASSWORD is required}"

psql --variable=ON_ERROR_STOP=1 \
    --username "$POSTGRES_USER" \
    --dbname postgres \
    --set=production_password="$VTSA_PRODUCTION_DB_PASSWORD" \
    --set=staging_password="$VTSA_STAGING_DB_PASSWORD" <<'SQL'
SELECT format('CREATE ROLE vtsa_production LOGIN PASSWORD %L', :'production_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'vtsa_production') \gexec

SELECT format('CREATE ROLE vtsa_staging LOGIN PASSWORD %L', :'staging_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'vtsa_staging') \gexec

SELECT 'CREATE DATABASE vtsa_production OWNER vtsa_production'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'vtsa_production') \gexec

SELECT 'CREATE DATABASE vtsa_staging OWNER vtsa_staging'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'vtsa_staging') \gexec

REVOKE ALL ON DATABASE vtsa_production FROM PUBLIC;
REVOKE ALL ON DATABASE vtsa_staging FROM PUBLIC;
ALTER DATABASE vtsa_production SET timezone TO 'UTC';
ALTER DATABASE vtsa_staging SET timezone TO 'UTC';
SQL

psql --variable=ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname vtsa_production <<'SQL'
CREATE EXTENSION IF NOT EXISTS postgis;
SQL

psql --variable=ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname vtsa_staging <<'SQL'
CREATE EXTENSION IF NOT EXISTS postgis;
SQL
