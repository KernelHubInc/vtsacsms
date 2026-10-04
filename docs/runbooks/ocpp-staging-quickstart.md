# Hostinger staging: connect an OCPP 1.6J charger

This procedure extends the existing `/opt/vtsa-csms/.env.staging` + `infra/compose.yaml` deployment from the staging mobile/KYC work. The helper also retains `infra/compose.kyc.yaml` so the prior KYC settings remain injected. The intended public endpoint is **`wss://staging.evcspowersolutions.com/ocpp/<charge-point-id>`**, using the site's existing host Nginx TLS certificate. It does not require another hostname. The charger's model and actual charge-point ID are still operator inputs.

Scope: authenticated connectivity, boot, heartbeat, status and protocol evidence into Laravel. Remote commands remain disabled. This is a single-gateway staging topology, not the separate multi-node cluster runbook. No database migration or reset is required. The gateway never writes core tables.

## 1. Confirm the current deployment and asset

Run on the staging VPS (`187.53.134.122` in the previous staging task):

```bash
cd /opt/vtsa-csms
sudo docker compose version
sudo docker compose --env-file .env.staging -f infra/compose.yaml ps
sudo nginx -t
```

Require Docker Compose **2.24.4+**, Python 3, an already healthy staging platform/Redis, `APP_ENV=staging`, `APP_DEBUG=false` and the existing HTTPS `APP_URL`. Preserve the existing Compose project name, database, APP_KEY, storage, SMTP and KYC settings. If this VPS now uses `infra/cluster/compose.app.yaml`, a containerized proxy, or another proxy host, stop here: this loopback overlay does not match that topology.

In the staging admin, register/verify the physical charging station under the correct active tenant/site. Copy its **tenant ULID**, **charging-station ULID** (`charger_id` in the gateway registry), and exact **charge-point identity**. Do not invent IDs or use a connector ULID as the charger ID. Set its OCPP version to `1.6J`, lifecycle to active, and configure active EVSE **1** with the actual numbered connectors (1, 2, etc.). `connectorId=0` is station-level evidence, not a physical connector.

Enrollment below creates the gateway projection only; it cannot prove the IDs exist in Assets. Wrong tenant/station IDs or inactive/mismatched connectors cause rejected or quarantined core events even if BootNotification is Accepted. Multi-connector station-level events may be quarantined by the current core's unique-connector resolver; validate each connector's StatusNotification separately.

Ensure any prior fixes made directly inside container writable layers are present in the deployed images/source before proceeding: Compose recreation discards container-only edits. The helper reuses the platform image for consumers; it does not rebuild or migrate Laravel.

## 2. Prepare private configuration

Place the new repository files on the VPS, then run:

```bash
cd /opt/vtsa-csms
sudo python3 scripts/ocpp-staging.py prepare
sudo python3 scripts/ocpp-staging.py check
```

The helper prompts for the three existing asset identifiers and the device's Basic authentication password twice. Configure a unique password of at least 16 characters on the device; enter it only at the hidden prompt. The Basic username must equal the charge-point ID. This Basic-auth workflow accepts letters, digits, dots, underscores and hyphens in that ID. Confirm WSS and Basic-auth support in the charger's vendor configuration; no vendor-specific setting names are assumed.

Preparation builds the gateway, hashes the password with Argon2id inside a one-off container, URL-encodes the existing Redis password, and creates root-readable `.env.staging.ocpp` (mode 0600). It reuses the existing gateway service token when set, otherwise generates one locally. No plaintext charger password is saved. Single-quoted dotenv values preserve the hash's `$` characters. Existing private configuration is never overwritten. Do not print `docker compose config`, copy this file into Git, or share it in screenshots.

## 3. Start the gateway and consumers

```bash
sudo python3 scripts/ocpp-staging.py up
sudo python3 scripts/ocpp-staging.py status
```

This recreates platform/worker/scheduler with matching gateway settings, clears cached configuration before each PHP process starts, and starts the gateway plus both long-running consumers. A short staging web interruption and charger reconnect are expected. Existing Redis/PostgreSQL/MinIO services and volumes are left running. No `down`, prune, migration, seeding, payment enablement, or production commands run.

The gateway publishes only `127.0.0.1:9002`, requires forwarded HTTPS, denies unknown chargers, accepts only `ocpp1.6`, uses `vtsa:staging:ocpp` streams/replies in Redis DB 0, and waits for Redis-backed readiness. Do not expose port 9002 in Hostinger or the host firewall. Proxy-header trust is broad only because the listener is private; keep untrusted containers off the staging Docker network.

## 4. Add WebSocket routing to the existing HTTPS server

Locate the Nginx file containing the staging site's **port 443** server block. Back up that file before editing. Install the snippet:

```bash
sudo install -m 0644 infra/nginx/ocpp-staging-location.conf /etc/nginx/snippets/vtsa-ocpp-staging.conf
```

Inside the existing `server { ... }` block for `staging.evcspowersolutions.com` that owns the TLS certificate, add:

```nginx
include /etc/nginx/snippets/vtsa-ocpp-staging.conf;
```

Then validate and reload:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

Check the private gateway and public WSS route after reloading:

```bash
sudo python3 scripts/ocpp-staging.py verify
```

This prints service status and Redis-backed gateway readiness, then performs a TLS-verified WebSocket handshake using a random **unenrolled** identity without sending OCPP messages. An expected 403 means that handshake was rejected; it does not prove an enrolled physical device can boot or that Laravel processed its status. A 404 indicates an incorrect/missing public route; a 502 indicates an unavailable upstream. These checks never use an active charger identity or replace its connection.

Keep the existing `/` application location, certificate, API, KYC and APK-download routes. Do not create a duplicate server or add the include to port 80. The snippet preserves `/ocpp/<id>`, passes Upgrade and Authorization headers, overwrites the forwarded protocol, and extends the idle timeout to 120 seconds. Public `/internal/` routing is not added. TLS must terminate at this host Nginx; an upstream CDN's TLS alone is insufficient for this configuration.

## 5. Configure the physical charger

| Device field | Value |
| --- | --- |
| Protocol | OCPP 1.6 JSON / 1.6J |
| Central-system base URL | `wss://staging.evcspowersolutions.com/ocpp` |
| Charge-point ID | Exact staging Assets identity |
| Full socket URL | `wss://staging.evcspowersolutions.com/ocpp/<charge-point-id>` |
| WebSocket subprotocol | `ocpp1.6` |
| Security | TLS + HTTP Basic (Security Profile 2 where supported) |
| Basic username | Same charge-point ID |
| Basic password | The device password entered during preparation |
| Port | 443 |

Some devices append the identity to the base URL; others require the full socket URL. Supply it once, according to the vendor manual. Do not embed the password in the URL. Synchronize device time/NTP, enable certificate verification, and use its appropriate CA trust store. BootNotification returns a 30-second heartbeat interval in the current gateway.

## 6. Acceptance checks

```bash
sudo python3 scripts/ocpp-staging.py status
sudo python3 scripts/ocpp-staging.py logs
```

Confirm all of these before calling the physical integration working:

1. Gateway readiness reports Redis `ok` and an active connection after the device connects.
2. The device reports `BootNotification` → `Accepted`, then successful Heartbeat responses.
3. Each numbered connector sends `StatusNotification`; the event consumer logs `processed`, and the matching connector updates in staging. Investigate `quarantined` rather than treating socket connectivity as acceptance.
4. Restart the device connection: it reconnects and reports status without creating duplicate business records. Leave it connected beyond the Nginx idle timeout and observe repeated heartbeats.
5. An unknown identity or wrong password cannot connect. Perform this with a separate test identity so you do not replace the real charger's active socket.
6. Both consumers remain running; no recurring Redis authentication, pending-message or tenant-binding errors appear.

HTTP `/health/live` on the staging hostname is Laravel health, not proof of OCPP. The helper reads gateway readiness privately. An ordinary browser GET to `/ocpp/...` is not a WebSocket/OCPP test.

The previous mobile demo APK has charging disabled. This deployment does not enable it. RFID/Authorize is also fail-closed: arbitrary RFID tags are not automatically authorized. A physical charging test needs the existing core authorization/session workflow, fresh connector availability and its business prerequisites. Do not change the gateway to accept every token. Remote commands, payments and real energy-flow testing require a separate supervised acceptance step; socket/Boot acceptance alone proves none of them.

## Additional devices on the shared endpoint

The same Nginx route serves every enrolled device: `wss://staging.evcspowersolutions.com/ocpp/{device-id}`. No per-device Nginx location, hostname or port is needed. The WebSocket subprotocol is `ocpp1.6`. Each device uses its own exact Assets identity as its Basic username and its own password.

After the first `prepare` and `up` succeed, add another existing active Assets station:

```bash
sudo python3 scripts/ocpp-staging.py enroll &&
sudo python3 scripts/ocpp-staging.py check &&
sudo python3 scripts/ocpp-staging.py gateway-up &&
sudo python3 scripts/ocpp-staging.py verify
```

`enroll` prompts for the new device identity, tenant ULID, station ULID and hidden password. It adds an independently hashed entry, preserves existing devices and service/Redis credentials, and creates a private backup. Duplicate identities or station ULIDs are refused instead of replacing credentials. `gateway-up` recreates only the gateway with the new registry; existing devices briefly disconnect and must reconnect. Enrollment is not hot-reloaded, and adding a record in admin alone does not enroll its credentials. Core asset/protocol/connector validation and the physical acceptance checks above remain necessary for every device.

If first preparation stops because `APP_ENV=demo` or `APP_DEBUG=true`, correct the existing `.env.staging` to `APP_ENV=staging` and `APP_DEBUG=false`, retaining its project name and secrets. Run `prepare` successfully before `check`/`up`; join dependent commands with `&&` so failures stop the sequence. The helper now reports missing private configuration directly.

## Troubleshooting and rollback

| Symptom | Check |
| --- | --- |
| 404 / no upgrade | Include is in the correct TLS server; URL contains `/ocpp/` and ID only once |
| 502 | Gateway running, loopback 9002 listening, Redis readiness passing |
| 403 / handshake rejection | Exact ID, Basic username/password, enrollment, forwarded HTTPS, `ocpp1.6` |
| TLS failure | Device time, hostname/certificate chain, WSS support and CA store |
| Connected but no status | Both consumers, same Redis DB/streams, tenant/station/EVSE/connector registration |
| Authorize Invalid | Core authorization prerequisite failed or consumer response timed out |
| Event quarantined | Tenant binding, protocol, active asset hierarchy and connector numbering |

Continue using the helper or **both** env files and **all three** Compose files (base, KYC, OCPP) for future OCPP operations. Running the base Compose file alone can restore unsafe local defaults or drop KYC configuration. The helper refuses deployment when the running platform has additional unknown Compose overrides. Use `enroll` to add devices; do not rerun initial `prepare` or delete a populated registry. Credential rotation still requires a protected backup and a deliberate update to that device's private registry entry, preserving every other entry, followed by `gateway-up`. Keep operational change/audit records without secrets.

To withdraw this deployment, remove the one Nginx include and validate/reload Nginx, then stop just the OCPP services:

```bash
sudo docker compose --env-file .env.staging --env-file .env.staging.ocpp \
  -f infra/compose.yaml -f infra/compose.kyc.yaml -f infra/compose.ocpp-staging.yaml \
  stop ocpp-gateway ocpp-event-consumer ocpp-authorization-consumer
```

If reverting core settings, confirm `FEATURE_OCPP=false` and `FEATURE_REMOTE_CHARGING=false` in the base private env, then recreate only platform/worker/scheduler using the prior reviewed Compose configuration/images. Preserve all data and private configuration backups. Never use `down -v`.

Known limit: the current Redis Streams consumers read new messages; abandoned pending messages are not automatically reclaimed. Inspect consumer errors and stream pending counts after failures. Redis AOF is not the production durability/replay solution. Staging connection validation can proceed, but crash/replay recovery and hardware charging acceptance remain release gates for production.

Configuration references: [Docker Compose merge/override semantics](https://docs.docker.com/reference/compose-file/merge/) and [Nginx WebSocket proxying](https://nginx.org/en/docs/http/websocket.html).
