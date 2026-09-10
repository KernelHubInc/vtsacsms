# VTSA OCPP Gateway

The gateway is VTSA CSMS's separately deployable asynchronous protocol edge. It accepts OCPP 1.6J and OCPP 2.0.1 WebSockets, validates the enrolled charger identity, normalizes supported messages, and publishes protocol evidence to Redis Streams. It never owns tariffs, canonical charging sessions, payments, billing, or settlements and never writes Laravel-owned tables.

## Local setup

From `services/ocpp-gateway` in PowerShell:

```powershell
py -3.12 -m venv .venv
.\.venv\Scripts\python.exe -m pip install -e ".[dev]"
$env:GATEWAY_REDIS_URL = "redis://127.0.0.1:6379/0"
$env:OCPP_DEVELOPMENT_ALLOW_UNAUTHENTICATED = "true"
$env:OCPP_REQUIRE_TLS = "false"
.\.venv\Scripts\vtsa-ocpp-gateway.exe
```

Local Compose exposes HTTP on `http://localhost:9002` and chargers on `ws://localhost:9002/ocpp/{charge_point_identity}`. Unauthenticated chargers are allowed only when `OCPP_DEVELOPMENT_ALLOW_UNAUTHENTICATED=true`; their events go to the quarantine stream and contain no tenant/asset binding.

## Simulator

With the Compose gateway running:

```powershell
.\.venv\Scripts\vtsa-charger-simulator.exe --url ws://127.0.0.1:9002/ocpp --identity VTSA-SIM-001 --protocol ocpp1.6 --scenario standard
.\.venv\Scripts\vtsa-charger-simulator.exe --url ws://127.0.0.1:9002/ocpp --identity VTSA-SIM-201 --protocol ocpp2.0.1 --scenario reboot-during-charging
```

Available scenarios are `standard`, `duplicate`, `fault`, and `reboot-during-charging`. The simulator can also be imported to drive boot, heartbeat loops, status changes, authorization/start/stop flows, meter values, DataTransfer, diagnostics, firmware/security statuses, abrupt disconnects, reconnects, duplicate replay, faults, and reboot-during-charge cases.

For enrolled Basic authentication, put the password in `SIMULATOR_BASIC_PASSWORD`; do not pass it on the command line or commit it. For mTLS, use `--ca-file`, `--certificate-file`, and `--private-key-file` with local certificate paths.

## Internal interfaces

| Interface | Authentication | Purpose |
| --- | --- | --- |
| `GET /health/live` | none | Process liveness only |
| `GET /health/ready` | none | Redis connectivity, connection count, TLS mode |
| `GET /internal/metrics` | bearer service credential | Prometheus text metrics |
| `POST /internal/v1/commands` | bearer service credential | Correlated core-to-charger command dispatch |
| `WS /ocpp/{identity}` | enrolled Basic or client certificate | Charger protocol connection |

Internal HTTP endpoints fail closed with `503` until `GATEWAY_INTERNAL_API_TOKEN` is injected. Command bodies carry ULID command, correlation, tenant, charger, and actor IDs; authenticated charger protocol identity; reason code; expected-state evidence; aware UTC deadline; action-specific snake-case payload; and a bounded timeout. The target tenant/charger must match the enrolled live connection. `OCPP_ALLOWED_COMMANDS` is an explicit deployment allowlist; an empty value disables every outbound command. A protocol response means the charger acknowledged that OCPP call, not that a physical or business state transition completed.

Redis keys and streams include the deployment environment. Local defaults are:

- normalized bound events: `vtsa:local:ocpp:events:v1`;
- unenrolled development events: `vtsa:local:ocpp:quarantine:v1`;
- core authorization requests: `vtsa:local:ocpp:authorization-requests:v1`;
- connection leases, message claims/replies, and 1.6 wire transaction sequences: ephemeral Redis keys.

Authorization requests contain the authenticated tenant/charger binding, protocol, token representation, and request ULID. A core consumer responds by `RPUSH`ing JSON such as `{"status":"Accepted","expires_at":null,"parent_token":null}` to `{GATEWAY_REDIS_KEY_PREFIX}:authorization-response:{request_id}` and applies a short TTL before the configured timeout. Absence, malformed response, or transport failure is `Invalid`; the gateway never authorizes a driver by itself.

## Configuration

| Variable | Default | Notes |
| --- | --- | --- |
| `GATEWAY_HOST`, `GATEWAY_PORT` | `0.0.0.0`, `9000` | Listener |
| `GATEWAY_FORWARDED_ALLOW_IPS` | `127.0.0.1` | Uvicorn proxy-header trust; broaden only when the listener is private behind the approved TLS edge |
| `GATEWAY_NODE_ID` | generated ULID | Set a stable per-process/deployment identity when required |
| `GATEWAY_REDIS_URL` | `redis://redis:6379/0` | Omit only in in-process tests |
| `GATEWAY_ENVIRONMENT` | `local` | Lowercase deployment namespace; set explicitly outside local |
| `GATEWAY_REDIS_KEY_PREFIX` | `vtsa:{environment}:ocpp` | Isolation prefix for coordination/reply keys |
| `GATEWAY_CONNECTION_LEASE_SECONDS` | `45` | Minimum `9`; renewed every third of the lease |
| `GATEWAY_MESSAGE_DEDUPLICATION_SECONDS` | `86400` | Duplicate CALL response replay horizon |
| `GATEWAY_COMMAND_TIMEOUT_SECONDS` | `30` | Hard upper bound for command correlation |
| `GATEWAY_AUTHORIZATION_TIMEOUT_SECONDS` | `5` | Fail-closed core authorization timeout |
| `GATEWAY_HEARTBEAT_INTERVAL_SECONDS` | `30` | Boot response and WebSocket ping interval |
| `GATEWAY_MAXIMUM_CLOCK_SKEW_SECONDS` | `300` | Adds anomaly evidence; does not rewrite charger time |
| `GATEWAY_MAXIMUM_MESSAGE_BYTES` | `262144` | Applied before protocol routing |
| `GATEWAY_RAW_MESSAGE_LOGGING` | `true` | Structured frames; disable when evidence is unnecessary |
| `GATEWAY_REDACTED_FIELDS` | sensitive defaults | Recursive keys normalized across snake/camel case |
| `OCPP_CHARGER_REGISTRY_JSON` | `{}` | Safe Assets projection; use secret/config injection, not source |
| `OCPP_DATA_TRANSFER_ALLOWLIST` | empty | Vendor IDs; default denies all DataTransfer payloads |
| `OCPP_ALLOWED_COMMANDS` | empty | Default denies all outbound commands |
| `OCPP_REQUIRE_TLS` | `true` | May be terminated in-process or by an approved trusted edge |
| `OCPP_TLS_CERTIFICATE_FILE`, `OCPP_TLS_PRIVATE_KEY_FILE` | empty | Must be supplied together for in-process TLS |
| `OCPP_TLS_CLIENT_CA_FILE`, `OCPP_TLS_REQUIRE_CLIENT_CERT` | empty, `false` | mTLS trust bundle and enforcement switch |
| `OCPP_TRUSTED_CLIENT_CERTIFICATE_FINGERPRINT_HEADER` | empty | Opt-in fingerprint from an approved header-stripping TLS edge |

Registry entries contain ULID `tenant_id` and `charger_id`, `enabled`, and either an Argon2 `basic_password_hash`, a lowercase SHA-256 client certificate fingerprint, or both. The gateway accepts the client certificate only when its fingerprint matches the enrolled charger. An in-process TLS server may expose the peer certificate through ASGI scope; otherwise an approved edge may inject the configured fingerprint header only after stripping all client-supplied copies. Never enable that header on a directly reachable listener. Real credential material and production registry contents must not enter this repository.

## Quality commands

```powershell
.\.venv\Scripts\python.exe -m ruff format --check .
.\.venv\Scripts\python.exe -m ruff check .
.\.venv\Scripts\python.exe -m mypy src tests
.\.venv\Scripts\python.exe -m pytest
.\.venv\Scripts\python.exe -m pip_audit
```

See [the external simulator testing runbook](../../docs/local/OCPP-SIMULATOR-TESTING.md), [SUPPORTED_MESSAGES.md](SUPPORTED_MESSAGES.md), [the gateway architecture](../../docs/architecture/ocpp-gateway.md), and [the certificate rotation runbook](../../docs/runbooks/ocpp-certificate-rotation.md).
