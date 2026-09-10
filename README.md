# Power Solutions

## Milestone 1 local demonstration

Start the complete detached demo (platform, PostgreSQL/PostGIS, Redis, worker, scheduler, MinIO, Mailpit, and Flutter Web):

```powershell
make demo-up
```

Windows without Make:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/demo-up.ps1
```

Then open <http://localhost:8000/charging-map>, <http://localhost:8000/admin>, <http://localhost:8000/operator>, or <http://localhost:3000>. All local demo accounts use `VstaDemo!2026`; see [docs/local/DEMO-CREDENTIALS.md](docs/local/DEMO-CREDENTIALS.md).

```bash
make demo-status
make demo-verify
make demo-test
make demo-reset
make demo-logs
make demo-down
```

Exact setup, mobile commands, map-provider configuration, reset behavior, and troubleshooting are in [docs/local/LOCAL-DEMO.md](docs/local/LOCAL-DEMO.md). To connect the standalone simulator at port 3100, follow [docs/local/OCPP-SIMULATOR-TESTING.md](docs/local/OCPP-SIMULATOR-TESTING.md). Physical chargers, real payments, real settlement, production electronic invoicing, and OCPI are explicitly deferred.

Power Solutions is a standalone, multi-tenant EV charging platform. The repository includes the engineering foundation, tenant-safe identity/RBAC, master location and asset data, the CMS-driven public website/map, the separate OCPP 1.6J/2.0.1 gateway and simulator, Charging/Tariffs, provider-neutral payment and ledger foundations, the consumer charging client, platform/operator portals, Procurement/Inventory, and Maintenance/Asset Management. Customer-owned mobile charging APIs remain an explicit contract gap.

## Repository layout

```text
apps/
  platform/          Laravel 13, Livewire 4, Filament 5, website, portals, and API
  mobile/            Flutter 3.44.6 consumer application
services/
  ocpp-gateway/      Async Python 3.12+ OCPP WebSocket edge
packages/
  contracts/         OpenAPI, event JSON Schema, and generated artifacts
infra/               Local Docker Compose and future deployment configuration
docs/
  product/           Product requirements and personas
  architecture/      Architecture, state machines, and ADRs
  runbooks/          Operational procedures
```

Architecture-wide conventions are integer minor units for money, watt-hours for energy, watts for power, seconds for durations, UTC timestamps, and ULIDs for public identifiers. See [AGENTS.md](AGENTS.md) before changing code.

## Hostinger VPS deployment

The repository includes a production-mode Docker baseline for a single Hostinger VPS. After private-repository access, DNS, and the ignored `.env.production` file are configured, deploy with:

```bash
cd /opt/vtsa-csms && sudo git pull --ff-only origin main && sudo bash scripts/deploy-hostinger.sh
```

Do not use the local `infra/compose.yaml` on a public host. Exact first-deployment, TLS, private GitHub deploy-key, secret generation, OCPP enrollment, backup, verification, and rollback instructions are in [docs/runbooks/hostinger-vps-deployment.md](docs/runbooks/hostinger-vps-deployment.md).

For isolated staging and production stacks across the same two application servers, use [docs/runbooks/staging-production-deployment.md](docs/runbooks/staging-production-deployment.md). The production-only reference remains in [docs/runbooks/two-app-server-deployment.md](docs/runbooks/two-app-server-deployment.md).

## Supported toolchain

- PHP 8.4 and Composer 2.8
- Laravel 13, Livewire 4, and Filament 5
- Node.js 22 LTS and npm
- Flutter 3.44.6 stable with Dart 3.12
- Python 3.12 or 3.13
- Docker Engine 28+ with Docker Compose 2.40+

The project pin is authoritative. A newer patch release can be adopted through a reviewed dependency update.

## First setup

Run from the repository root in PowerShell. The first command creates ignored `.env` files, generates random local-only credentials, and enables the repository Git hooks. It never prints credential values.

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\bootstrap.ps1
composer install --working-dir=apps\platform
npm.cmd ci --prefix apps\platform
npm.cmd ci --prefix packages\contracts

Push-Location apps\mobile
flutter.bat pub get
Pop-Location

py -3.12 -m venv services\ocpp-gateway\.venv
Push-Location services\ocpp-gateway
.venv\Scripts\python.exe -m pip install -e ".[dev]"
Pop-Location
```

If the Python launcher is unavailable, replace `py -3.12` with an explicit Python 3.12+ executable.

## Start the complete local stack

```powershell
docker compose --env-file .env -f infra\compose.yaml build
docker compose --env-file .env -f infra\compose.yaml up -d
docker compose --env-file .env -f infra\compose.yaml exec platform php artisan migrate --force
docker compose --env-file .env -f infra\compose.yaml exec platform php artisan security:sync-permissions
docker compose --env-file .env -f infra\compose.yaml ps
```

Endpoints:

| Surface | URL |
| --- | --- |
| Public platform | http://localhost:8000 |
| Local design-system catalog | http://localhost:8000/design-system |
| Laravel liveness / readiness | http://localhost:8000/health/live · http://localhost:8000/health/ready |
| Filament | http://localhost:8000/admin |
| OCPP gateway liveness / readiness | http://localhost:9002/health/live · http://localhost:9002/health/ready |
| OCPP WebSocket | ws://localhost:9002/ocpp/{charge_point_id} |
| Mailpit | http://localhost:8025 |
| MinIO console | http://localhost:9001 |

Unauthenticated OCPP is enabled only by local Compose. The gateway denies charger connections by default in every other environment.

Exercise the local protocol gateway with either version:

```powershell
Push-Location services\ocpp-gateway
.venv\Scripts\vtsa-charger-simulator.exe --url ws://127.0.0.1:9002/ocpp --identity VTSA-SIM-16 --protocol ocpp1.6 --scenario standard
.venv\Scripts\vtsa-charger-simulator.exe --url ws://127.0.0.1:9002/ocpp --identity VTSA-SIM-201 --protocol ocpp2.0.1 --scenario reboot-during-charging
Pop-Location
```

## Test everything

```powershell
Push-Location apps\platform
vendor/bin/phpunit --do-not-cache-result
Pop-Location

Push-Location apps\mobile
flutter.bat test
flutter.bat devices
flutter.bat test integration_test -d 'DEVICE_ID'
Pop-Location

services\ocpp-gateway\.venv\Scripts\python.exe -m pytest services\ocpp-gateway
npm.cmd test --prefix packages\contracts
```

Verify migrations against PostgreSQL/PostGIS rather than the SQLite unit-test database:

```powershell
docker compose --env-file .env -f infra\compose.yaml exec platform php artisan migrate:fresh --force
docker compose --env-file .env -f infra\compose.yaml exec platform php artisan migrate:status
```

`migrate:fresh` deletes local platform tables; never aim it at a shared or production database.

## Lint and static analysis

```powershell
Push-Location apps\platform
vendor\bin\pint --test
vendor\bin\phpstan analyse --memory-limit=1G
composer validate --strict
composer audit
Pop-Location

Push-Location apps\mobile
dart.bat format --output=none --set-exit-if-changed .
flutter.bat analyze
Pop-Location

services\ocpp-gateway\.venv\Scripts\python.exe -m ruff format --check services\ocpp-gateway
services\ocpp-gateway\.venv\Scripts\python.exe -m ruff check services\ocpp-gateway
services\ocpp-gateway\.venv\Scripts\python.exe -m mypy services\ocpp-gateway\src services\ocpp-gateway\tests
services\ocpp-gateway\.venv\Scripts\python.exe -m pip_audit
npm.cmd run lint --prefix packages\contracts
```

To apply the configured formatters:

```powershell
Push-Location apps\platform
vendor\bin\pint
Pop-Location
dart.bat format apps\mobile
services\ocpp-gateway\.venv\Scripts\python.exe -m ruff format services\ocpp-gateway
```

## Build everything

```powershell
npm.cmd run build --prefix apps\platform
npm.cmd run build --prefix packages\contracts

Push-Location apps\mobile
flutter.bat build apk --debug --dart-define-from-file=dart_defines.example.json
Pop-Location

docker build --target runtime -t vtsa-platform:local apps\platform
docker build -t vtsa-ocpp-gateway:local services\ocpp-gateway
docker compose --env-file .env -f infra\compose.yaml config --quiet
```

Python is packaged and import-checked by the gateway test and Docker build; it does not produce a separately published wheel in this phase.

The Laravel catalog route is available only in `local` and `testing` environments. To open the Flutter catalog instead of the consumer app during development, add `--dart-define=SHOW_DESIGN_CATALOG=true` to `flutter run`. Mobile environment, map-key, emulator networking, and contract details are in the [mobile development runbook](docs/runbooks/mobile-development.md).

## Stop and reset

Stop while retaining data:

```powershell
docker compose --env-file .env -f infra\compose.yaml down
```

Fully reset local services and rotate ignored local credentials:

```powershell
docker compose --env-file .env -f infra\compose.yaml down --volumes --remove-orphans
powershell -ExecutionPolicy Bypass -File .\tools\bootstrap.ps1 -Force
docker compose --env-file .env -f infra\compose.yaml up -d --build
docker compose --env-file .env -f infra\compose.yaml exec platform php artisan migrate --force
docker compose --env-file .env -f infra\compose.yaml exec platform php artisan security:sync-permissions
```

The volume reset permanently removes only local PostgreSQL, Redis, MinIO, and Mailpit volumes.

## Troubleshooting

- `vendor/autoload.php` missing: run `composer install --working-dir=apps\platform`.
- Vite manifest missing: run `npm.cmd ci --prefix apps\platform`, then `npm.cmd run build --prefix apps\platform`.
- PostgreSQL driver missing in host PHP: run migrations and integration checks inside Compose, whose PHP image includes `pdo_pgsql`.
- Docker service unhealthy: run `docker compose --env-file .env -f infra\compose.yaml ps` and then `docker compose --env-file .env -f infra\compose.yaml logs <service>`.
- Flutter SDK mismatch: install the version in `apps/mobile/.flutter-version` and rerun `flutter clean` followed by `flutter pub get`.
- Generated contracts differ: run `npm.cmd run generate --prefix packages\contracts` and commit the source and generated changes together.
- Port collision: inspect ports 5432, 6379, 8000, 8025, 9000, 9001, and 9002 before changing mappings.
- Container does not reflect a source edit: Compose intentionally runs immutable images for reliable cross-platform filesystem behavior; rerun `docker compose --env-file .env -f infra\compose.yaml up -d --build platform worker`.
- Interactive charging map is unavailable: OpenStreetMap is the credential-free default, so first check the browser console and tile/network policy in [map troubleshooting](docs/maps/MAP-TROUBLESHOOTING.md). To opt into Google, set the restricted `GOOGLE_MAPS_BROWSER_API_KEY` described in [the Google Maps runbook](docs/runbooks/google-maps.md). The accessible station list remains available during any provider failure.
- OCPP readiness returns `503`: verify Redis first; the gateway deliberately has no process-memory fallback outside tests.
- Charger WebSocket closes with `1002`: offer exactly `ocpp1.6` or `ocpp2.0.1`. A `1008` close means TLS or charger enrollment/authentication failed.

More operational detail is in [the local development runbook](docs/runbooks/local-development.md).
Charging consumers, expiry handling, and diagnostic procedures are in [the charging operations runbook](docs/runbooks/charging-operations.md).
Payment sandbox setup, timeout recovery, reconciliation, and immutable-ledger procedures are in [the payments runbook](docs/runbooks/payments-local.md).
Maintenance dispatch, technician, fault-automation, SLA, evidence, and recovery procedures are in [the maintenance operations runbook](docs/runbooks/maintenance-operations.md).

## Phase status and open decisions

The modular Laravel platform owns business workflows, including Charging, Tariffs, provider-independent financial foundations, Procurement, Inventory, and Maintenance, while the separately deployable async gateway owns long-lived OCPP connections. The Flutter client implements QR/manual start, live-session recovery, remote stop, payment-finalization status, history, documents, and support entry points over provider-neutral ports. Its customer-owned Laravel charging endpoints and matching OpenAPI remain an explicit release blocker; the app must not reuse workforce endpoints. Maintenance now provides selected-fault incident automation, guarded corrective/preventive work, Inventory-backed parts custody, Assets-owned service restriction, responsive technician screens, and operational dashboards. PostgreSQL/PostGIS is authoritative storage; Redis is ephemeral coordination; MinIO and Mailpit are local substitutes. Contracts are source-controlled and validated before generated artifacts are accepted.

Primary remaining risks are OCPP interoperability across charger vendors, production event durability/replay, certificate enrollment and fleet rotation, payment and settlement compliance, tenant-isolation enforcement, geospatial growth, and operational complexity across the gateway/platform boundary. Payment providers, tax registrations, production regions and sizing, mobile release signing, CA/secret-manager choices, and bank or infrastructure credentials remain deliberately unresolved.

Each phase requires all affected application tests, format checks, static analysis, contract validation, migrations, asset builds, health probes, secret scans, and documentation checks to pass. CI enforces the automatable subset on every pull request.
