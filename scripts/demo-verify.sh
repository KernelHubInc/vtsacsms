#!/usr/bin/env sh
set -eu
curl --fail --silent http://localhost:8000/health/ready >/dev/null
curl --fail --silent http://localhost:8000/charging-map >/dev/null
curl --fail --silent 'http://localhost:8000/api/v1/public/stations?limit=250' >/dev/null
curl --fail --silent http://localhost:3000/ >/dev/null
docker compose --env-file .env -f infra/compose.yaml exec -T postgres pg_isready
docker compose --env-file .env -f infra/compose.yaml exec -T redis redis-cli ping
printf '%s\n' 'Milestone 1 core health checks passed. OCPP and real payments: DEFERRED - MILESTONE 2.'
