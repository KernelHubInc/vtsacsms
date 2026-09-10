# Testing VTSA CSMS with the VSTA OCPP Simulator

This runbook connects the standalone VSTA simulator at <http://localhost:3100> to the local VTSA CSMS OCPP gateway. It covers local protocol testing only. It does not define production charger credentials, certificates, or network configuration.

## What is being connected

```text
Simulator browser (:3100)
        |
        | REST/SSE
        v
Simulator API container (:8100)
        |
        | OCPP 1.6J WebSocket
        v
VTSA OCPP gateway (:9002) ---> Redis Streams ---> Laravel OCPP consumers
```

The simulator API, rather than the browser, opens the charger WebSocket. For that reason, its CSMS base URL must use `host.docker.internal`, not `localhost`, when both applications run with Docker Desktop.

## Local endpoints

| Purpose | Address |
| --- | --- |
| Simulator UI | <http://localhost:3100> |
| Simulator API health | <http://localhost:8100/api/v1/health> |
| Gateway liveness | <http://localhost:9002/health/live> |
| Gateway readiness | <http://localhost:9002/health/ready> |
| CSMS WebSocket base URL from the simulator container | `ws://host.docker.internal:9002/ocpp` |
| CSMS WebSocket base URL from a host-native simulator | `ws://localhost:9002/ocpp` |
| Complete charger URL | `ws://host.docker.internal:9002/ocpp/{charge_point_id}` |

The VSTA simulator currently operates as an OCPP 1.6J client. The VTSA gateway also supports OCPP 2.0.1 for other clients.

## 1. Start the VTSA gateway

Run these commands from the VTSA CSMS repository root:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\bootstrap.ps1
docker compose --env-file .env -f infra\compose.yaml up -d redis
docker compose --env-file .env -f infra\compose.yaml --profile milestone2 up -d --build ocpp-gateway
```

If the Laravel platform is already running, start its two OCPP consumers:

```powershell
docker compose --env-file .env -f infra\compose.yaml --profile milestone2 up -d --no-deps ocpp-event-consumer ocpp-authorization-consumer
```

Check the services:

```powershell
docker compose --env-file .env -f infra\compose.yaml --profile milestone2 ps ocpp-gateway ocpp-event-consumer ocpp-authorization-consumer
curl.exe -sS http://localhost:9002/health/ready
```

The readiness response must have HTTP status `200`, top-level status `ready`, and `checks.redis` set to `ok`.

## 2. Start the VSTA simulator

Run the simulator's normal database, API, and web services from its repository root. Do not enable its `fixture` profile when testing VTSA CSMS:

```powershell
docker compose up -d --build database simulator-api web
```

If the simulator's built-in `test-csms` fixture was previously started, stop only that fixture:

```powershell
docker compose stop test-csms
```

The fixture listens on host port `9000`, which is not the VTSA gateway. It can also conflict with the VTSA demo's MinIO API on port `9000`. The real local VTSA OCPP gateway is exposed on port `9002`.

Verify the simulator:

```powershell
curl.exe -sS http://localhost:8100/api/v1/health
```

Then open <http://localhost:3100>.

## 3. Create or configure a simulated charger

In the simulator UI:

1. Select **Create charger**, or open a disconnected charger and select **Edit**.
2. Use a unique charge point ID such as `VSTA-SIM-001`.
3. Set **CSMS WebSocket base URL** to:

   ```text
   ws://host.docker.internal:9002/ocpp
   ```

4. Keep the protocol as **OCPP 1.6**.
5. Save the charger.
6. Select **Connect**.

The simulator appends the encoded charge point ID automatically. Do not add the ID to the base URL field. For `VSTA-SIM-001`, the final URL is:

```text
ws://host.docker.internal:9002/ocpp/VSTA-SIM-001
```

## 4. Confirm the initial connection

A successful connection has all of these signals:

- the simulator displays connection status **Connected**;
- registration becomes **Accepted** after `BootNotification`;
- the wire console shows a `BootNotification` CALL and matching CALLRESULT;
- the gateway readiness connection count increases while the charger remains connected; and
- the gateway log contains `ocpp_connection_opened` with protocol `ocpp1.6`.

Watch the gateway log from the VTSA repository root:

```powershell
docker compose --env-file .env -f infra\compose.yaml --profile milestone2 logs -f --tail 100 ocpp-gateway
```

Press `Ctrl+C` to stop following logs. This does not stop the service.

## 5. Run the protocol smoke tests

Run the following checks from the simulated charger's detail screen.

| Test | Simulator action | Expected CSMS response or evidence |
| --- | --- | --- |
| Boot | Select **Connect** | `BootNotification` returns `Accepted` with UTC time and heartbeat interval |
| Heartbeat | Select **Heartbeat** | `Heartbeat` receives a correlated CALLRESULT with current UTC time |
| Available status | Choose `Available`, then **Apply state** | `StatusNotification` receives an empty successful CALLRESULT |
| Fault status | Choose `Faulted`, select a test error, then **Apply state** | Fault `StatusNotification` is accepted and visible in the gateway log |
| Reconnect | Select **Reconnect** | Old connection closes and a new connection boots successfully |
| Unexpected disconnect | Run the relevant entry under **Scenarios** | Gateway emits a disconnect event and accepts the later reconnect |
| Duplicate CALL | Run the duplicate-message scenario or use **Protocol lab** | The gateway replays the cached response for the same message ID |
| Schema rejection | Use **Protocol lab** with an explicitly permitted negative test | Invalid messages receive an OCPP error and do not become business events |

Use disposable identities and synthetic values only.

## 6. Inspect local protocol evidence

Local Compose permits unenrolled identities specifically for protocol development. Their normalized events go to the quarantine stream and contain no tenant or charger ULID.

Check whether quarantined events were written:

```powershell
docker compose --env-file .env -f infra\compose.yaml exec -T redis redis-cli XLEN vtsa:local:ocpp:quarantine:v1
docker compose --env-file .env -f infra\compose.yaml exec -T redis redis-cli XREVRANGE vtsa:local:ocpp:quarantine:v1 + - COUNT 10
```

Raw gateway frames are structured and redact configured sensitive fields. Never place real credentials, payment data, or personal information in a simulator payload.

## 7. Understand protocol testing versus tenant integration

There are two different test levels:

### Unenrolled protocol smoke test

- Works immediately with local Compose.
- Confirms networking, WebSocket negotiation, schema handling, boot, heartbeat, statuses, reconnects, duplicates, and faults.
- Publishes only to the quarantine stream.
- Does not update tenant-owned charger projections, sessions, billing, or maintenance records.
- Driver `Authorize` requests fail closed because the connection has no trusted tenant/asset binding.

### Enrolled end-to-end test

- Requires the simulator charge point ID to match an Assets-owned charging-station identity.
- Requires an injected gateway registry entry containing the correct tenant ULID, charger ULID, enabled state, and approved Basic-password hash or client-certificate fingerprint.
- Requires the simulator credential to be supplied through its environment, never through a browser field or committed file.
- Publishes bound events for the Laravel OCPP consumers to validate and apply.
- Can exercise authorization, transaction start/stop, meter values, charging projections, and downstream fault automation.

The repository deliberately does not contain a shared charger password, production certificate, or production registry. Provision an enrolled local identity through an approved local secret/configuration workflow before treating a simulator test as tenant-integrated.

## 8. Optional command-line baseline

Use the gateway repository's simulator to determine whether an issue belongs to VTSA CSMS or to the external simulator UI. From the VTSA repository root:

```powershell
Push-Location services\ocpp-gateway
.venv\Scripts\vtsa-charger-simulator.exe --url ws://127.0.0.1:9002/ocpp --identity VTSA-SMOKE-16 --protocol ocpp1.6 --scenario fault
.venv\Scripts\vtsa-charger-simulator.exe --url ws://127.0.0.1:9002/ocpp --identity VTSA-SMOKE-201 --protocol ocpp2.0.1 --scenario fault
Pop-Location
```

Both commands should exit successfully. The `fault` scenario tests boot and status handling without requiring driver authorization.

## Troubleshooting

### Connection refused

```powershell
docker compose --env-file .env -f infra\compose.yaml --profile milestone2 ps ocpp-gateway
curl.exe -sS http://localhost:9002/health/ready
```

Use `host.docker.internal`, not `localhost`, in a charger managed by the Dockerized simulator API.

### WebSocket closes with code 1002

The client did not offer a supported subprotocol. The VSTA simulator must offer exactly `ocpp1.6`.

### WebSocket closes with code 1008

TLS or charger enrollment authentication failed. Check the gateway deployment mode and injected enrollment without printing credentials.

### Boot is accepted but the admin portal does not change

This is expected for an unenrolled local identity. Its events are quarantined. Complete the enrolled test setup before expecting tenant-owned projections to change.

### Authorize or transaction start is rejected

An anonymous development connection has no trusted tenant binding, so authorization fails closed. Inspect the `Authorize` response in the wire console. Do not bypass authorization to make an end-to-end test appear successful.

### Port 9000 is already allocated

The simulator's optional `test-csms` fixture may be running. Stop that fixture before starting the complete VTSA demo, or start only the VTSA gateway and its Redis dependency. Do not stop unrelated containers without identifying them first.

### View recent logs without following them

```powershell
docker compose --env-file .env -f infra\compose.yaml --profile milestone2 logs --tail 200 ocpp-gateway ocpp-event-consumer ocpp-authorization-consumer
```

## Completion checklist

- [ ] Gateway readiness returns `200` and Redis is `ok`.
- [ ] Simulator API health succeeds.
- [ ] Charger uses `ws://host.docker.internal:9002/ocpp` as its base URL.
- [ ] `ocpp1.6` is negotiated.
- [ ] `BootNotification` is accepted.
- [ ] Heartbeat receives a correlated result.
- [ ] Available and fault statuses are accepted.
- [ ] Reconnect succeeds without leaving a stale connection owner.
- [ ] Quarantine evidence exists for an unenrolled smoke test.
- [ ] Tenant-facing assertions are made only with an enrolled, authenticated charger.

See also [the gateway service guide](../../services/ocpp-gateway/README.md), [supported messages](../../services/ocpp-gateway/SUPPORTED_MESSAGES.md), and [gateway architecture](../architecture/ocpp-gateway.md).
