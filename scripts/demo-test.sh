#!/usr/bin/env sh
set -eu
docker compose --env-file .env -f infra/compose.yaml exec -T platform vendor/bin/pint --test
docker compose --env-file .env -f infra/compose.yaml exec -T platform vendor/bin/phpstan analyse --memory-limit=1G
docker compose --env-file .env -f infra/compose.yaml exec -T platform php artisan test
docker compose --env-file .env -f infra/compose.yaml build flutter-web
docker compose --env-file .env -f infra/compose.yaml --profile quality run --rm --no-deps flutter-check
