# Power Solutions Milestone 1 local demo

This is a local UAT environment containing fictional data. It is not a production deployment and none of its locations, stock, financial documents, charger states, or organizations are real.

## Requirements and first start

Install Docker Desktop with Compose v2, Git, and PowerShell 7 or Windows PowerShell 5.1. Allocate at least 8 GB RAM and 20 GB free disk space to Docker. Flutter, PHP, Node, PostgreSQL, Redis, MinIO, and Mailpit run from the repository containers.

From the repository root:

```powershell
make demo-up
```

On a Windows host without Make:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/demo-up.ps1
```

On Linux/macOS:

```bash
./tools/bootstrap.ps1 # use pwsh for the safe environment bootstrap
./scripts/demo-up.sh
```

The command creates missing `.env` files from safe templates, generates local-only secrets, builds the images and frontend assets, starts detached services, migrates, deterministically seeds, checks health, and prints URLs. It is safe to rerun.

## URLs

| Experience | URL |
|---|---|
| Public website | <http://localhost:8000> |
| Store locator | <http://localhost:8000/charging-map> |
| Platform admin | <http://localhost:8000/admin> |
| Operator portal | <http://localhost:8000/operator> |
| Technician workboard | <http://localhost:8000/operator/technician-workboard> |
| Flutter Web | <http://localhost:3000> |
| Mailpit | <http://localhost:8025> |
| MinIO console | <http://localhost:9001> |
| API health | <http://localhost:8000/api/health> |

Credentials are in [DEMO-CREDENTIALS.md](DEMO-CREDENTIALS.md). The demo tenant ULID is `01J0000000VTSADEMA00000000`.

## Common commands

```bash
make demo-status
make demo-verify
make demo-test
make demo-logs
make demo-reset
make demo-down
```

`demo-reset` intentionally removes only this Compose project's named local volumes, then recreates and reseeds them. `demo-down` preserves data.

## Flutter

`demo-up` always serves Flutter Web at port 3000. Host alternatives:

```bash
make mobile-web
make mobile-android
make mobile-ios
```

Android emulators reach the host as `http://10.0.2.2:8000`; iOS simulators and Flutter Web use `http://127.0.0.1:8000` or `http://localhost:8000`. A physical device needs the host's LAN IP and local firewall permission. Pass `DEFAULT_TENANT_ID=01J0000000VTSADEMA00000000`. Real push, payment, and OCPP credentials are not required.

## Maps

The local default is `MAP_PROVIDER_DEFAULT=openstreetmap`; it uses Leaflet and OpenStreetMap tiles with the same server-side station API as Google. To test Google on Laravel web surfaces:

```env
MAP_PROVIDER_DEFAULT=google
GOOGLE_MAPS_BROWSER_API_KEY=your_restricted_development_key
GOOGLE_MAPS_MAP_ID=
```

Restrict the key by HTTP referrer and API, add quota alerts, then rerun `make demo-up`. A missing key or false readiness setting falls back to OpenStreetMap rather than breaking station discovery. Never commit the key. See [the complete map setup](../maps/MAP-PROVIDERS.md).

## Email, files, and logs

Registration and password email is captured by Mailpit. MinIO's console shows the private `vtsa-local` bucket; its local credentials are generated into the ignored root `.env`. Use `make demo-logs` for service logs or `docker compose --env-file .env -f infra/compose.yaml logs platform worker`.

## OCPP simulator

The optional OCPP gateway uses the `milestone2` Compose profile and is exposed at `ws://localhost:9002/ocpp/{charge_point_id}`. When the separate Dockerized VSTA simulator at port 3100 connects to it, configure the simulator's CSMS base URL as `ws://host.docker.internal:9002/ocpp`. Follow [OCPP-SIMULATOR-TESTING.md](OCPP-SIMULATOR-TESTING.md) for startup, UI steps, expected results, protocol evidence, enrollment limitations, and troubleshooting.

See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for common failures and [MILESTONE-1-LIMITATIONS.md](MILESTONE-1-LIMITATIONS.md) before UAT.
