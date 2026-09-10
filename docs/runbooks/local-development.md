# Local Development Runbook

## Scope

This runbook operates the phase-one infrastructure only. It does not provision production systems or create tenants, users, chargers, tariffs, payments, or other domain records.

## Start

From the repository root in PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\bootstrap.ps1
docker compose --env-file .env -f infra\compose.yaml build
docker compose --env-file .env -f infra\compose.yaml up -d
docker compose --env-file .env -f infra\compose.yaml exec platform php artisan migrate --force
docker compose --env-file .env -f infra\compose.yaml ps
```

The bootstrap command generates untracked local credentials without displaying their values. Re-running it preserves existing values unless `-Force` is supplied.

## Verify

```powershell
Invoke-RestMethod http://localhost:8000/health/live
Invoke-RestMethod http://localhost:8000/health/ready
Invoke-RestMethod http://localhost:9002/health/live
Invoke-RestMethod http://localhost:9002/health/ready
```

The public Laravel foundation is at `http://localhost:8000`, Filament at `http://localhost:8000/admin`, Mailpit at `http://localhost:8025`, and the MinIO console at `http://localhost:9001`.

Run a charger scenario after installing the gateway development dependencies:

```powershell
Push-Location services\ocpp-gateway
.venv\Scripts\vtsa-charger-simulator.exe --url ws://127.0.0.1:9002/ocpp --identity VTSA-SIM-001 --protocol ocpp1.6 --scenario duplicate
Pop-Location
```

Other scenarios are `standard`, `fault`, and `reboot-during-charging`; both `ocpp1.6` and `ocpp2.0.1` are supported. These local unauthenticated messages are deliberately published to `vtsa:local:ocpp:quarantine:v1`, not the tenant event stream.

## Logs

```powershell
docker compose --env-file .env -f infra\compose.yaml logs -f platform worker ocpp-gateway
```

Application logs are JSON and include request and correlation IDs. Supply valid ULIDs through `X-Request-ID` and `X-Correlation-ID` to preserve caller context; invalid values are replaced.

## Stop and reset

Stop without deleting data:

```powershell
docker compose --env-file .env -f infra\compose.yaml down
```

Delete all local Compose data and rotate local credentials:

```powershell
docker compose --env-file .env -f infra\compose.yaml down --volumes --remove-orphans
powershell -ExecutionPolicy Bypass -File .\tools\bootstrap.ps1 -Force
```

The reset is destructive only to the named local Compose volumes. It does not delete source files.

## Failure triage

- If a port is occupied, stop the conflicting local process or change the left side of the relevant port mapping.
- If readiness returns `503`, inspect `docker compose ... ps` and the PostgreSQL logs first.
- If a dependency lock changes, rebuild the affected image; do not edit installed dependency directories.
- If Docker Desktop cannot pull an image, authenticate to the registry if required and retry. No registry credentials belong in this repository.
- OCPP WebSockets accept unauthenticated connections only in local Compose. Outside this environment the gateway requires an enrolled per-device Argon2 Basic credential or matching client-certificate fingerprint and TLS. See the [gateway architecture](../architecture/ocpp-gateway.md) and [certificate rotation runbook](ocpp-certificate-rotation.md).
