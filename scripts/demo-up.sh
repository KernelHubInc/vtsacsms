#!/usr/bin/env sh
set -eu
docker compose --env-file .env -f infra/compose.yaml up --build --detach --remove-orphans
docker compose --env-file .env -f infra/compose.yaml exec -T platform php artisan migrate --force
docker compose --env-file .env -f infra/compose.yaml exec -T platform php artisan db:seed --class='Database\Seeders\MilestoneOneDemoSeeder' --force
docker compose --env-file .env -f infra/compose.yaml exec -T platform php artisan optimize:clear
./scripts/demo-verify.sh
