#!/usr/bin/env sh
set -eu
docker compose --env-file .env -f infra/compose.yaml down --volumes --remove-orphans
./scripts/demo-up.sh
