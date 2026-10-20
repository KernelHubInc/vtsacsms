# Hostinger staging: connect an OCPP 1.6J charger

This procedure extends the existing `/opt/vtsa-csms/.env.staging` + `infra/compose.yaml` deployment from the staging mobile/KYC work. The helper also retains `infra/compose.kyc.yaml` so the prior KYC settings remain injected. The intended public endpoint is **`wss://staging.evcspowersolutions.com/ocpp/<charge-point-id>`**, using the site's existing host Nginx TLS certificate. It does not require another hostname. The charger's model and actual charge-point ID are still operator inputs.

Scope: authenticated connectivity, boot, heartbeat, status and protocol evidence into Laravel. Remote commands remain disabled. This is a single-gateway staging topology, not the separate multi-node cluster runbook. Live connection reporting requires migration `2026_10_04_000001_create_charging_station_connections` before deploying the updated backend. Follow the [rollout procedure](../architecture/ocpp-gateway.md#rollout), including backup of the actual staging database, which may be on a separate VPS. No database reset is required. The gateway never writes core tables.

## Verified simulator deployment — 2026-10-04

`wss://staging.evcspowersolutions.com/ocpp/DEMO-CP-023` passed an authenticated
OCPP 1.6J BootNotification, six heartbeats over 150 seconds, and a fresh reconnect
from the Windows simulator. Missing credentials, an incorrect password, and an
unknown identity each returned HTTP 403. The gateway, both consumers, platform,
worker, and scheduler were healthy after deployment; Laravel liveness returned
200. No charging transaction or physical-device test was performed.

The station's previously empty OCPP version was set to the existing `1.6J`
catalog entry through the platform's Assets model with an audit record. Events
received before that correction were quarantined and retained. Subsequent
heartbeats, reconnect boot, and disconnect events were processed successfully.

The platform's original PHP server command is explicit in the OCPP overlay:
Compose otherwise clears the image CMD when an entrypoint is supplied. The
existing KYC DNS override is retained using `--compose-override`. The public
Nginx configuration was backed up before installing the include. The deployment
package does not replace the VPS's existing base Compose file or private env
files; retain its SMTP, storage, Redis authentication, and port configuration.

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

For simulator-first validation, a new, unique simulator password can be enrolled
before configuring any physical device. Do not use the charger's settings PIN.
This workstation's `scripts/test-ocpp-staging.ps1` loads its ignored, Windows
DPAPI-encrypted credential and tests `DEMO-CP-023` on the public WSS endpoint:

```powershell
.\scripts\test-ocpp-staging.ps1
```

The default six heartbeats span 150 seconds at the gateway's 30-second interval.
The credential is tied to the enrolling Windows account, is never printed, and
is injected only into the simulator process environment. The command restores
any pre-existing password environment variable when it finishes. On another
machine, use the simulator's `--password-prompt` with the enrolled password.
Only one peer should use a charge-point identity at a time; stop the simulator
before later connecting the physical device. This test proves protocol
connectivity, not physical charging or downstream core event processing.

If the running platform includes an additional Compose override, supply it on
every helper command, for example on this VPS:

```bash
python3 scripts/ocpp-staging.py status --compose-override /etc/vtsa-csms/compose.staging-kyc-dns.yaml
```

`--compose-override` is repeatable. These files load before the OCPP security
overlay and remain subject to the staging validation checks. Preserve the
existing KYC DNS mapping when recreating the platform or operating this stack.

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

## Legacy Basic-auth activation (ADR 0023)

For the current Authorisation = 0 device, use **URL-only deployment** below instead. This section describes the older credential-based setup; the current admin form no longer provisions passwords. The legacy station API can still provision credentials if retaining Basic mode.

After deploying this release's platform and gateway images and applying `2026_10_09_000001_create_identity_charger_credentials`, activate authoritative platform authentication. Keep a current backup of the actual staging database (which may be on the separate database VPS); do not assume the local Compose PostgreSQL container is the database. Do not rerun initial `prepare`.

For this staging deployment, retain the existing DNS override:

```bash
cd /opt/vtsa-csms
sudo python3 scripts/ocpp-staging.py activate-enrollment \
  --compose-override /etc/vtsa-csms/compose.staging-kyc-dns.yaml &&
sudo python3 scripts/ocpp-staging.py gateway-up \
  --compose-override /etc/vtsa-csms/compose.staging-kyc-dns.yaml &&
sudo python3 scripts/ocpp-staging.py verify \
  --compose-override /etc/vtsa-csms/compose.staging-kyc-dns.yaml
```

`activate-enrollment` imports existing Basic hashes via stdin into Identity without overwriting existing credentials, creates a private environment backup, and sets `OCPP_CORE_AUTH_URL` to the HTTPS APP_URL plus `/api/internal/v1/ocpp/authenticate`. The core must receive the matching `OCPP_GATEWAY_INTERNAL_TOKEN`; the existing overlay already supplies it. DNS, the server certificate chain and HTTPS reachability must work from the gateway container. Deployment requires the **new gateway image**: `gateway-up` recreates but does not build it. The additive table is initially empty until credentials are imported or set through admin/API.

For legacy Basic mode, provision the station credential through the station API and set its OCPP version. Set the station active when commissioning is complete. The device uses its exact charge-point ID as the Basic username and its own password. Additional enrollments require no env changes, Nginx changes or restart. Draft CSV imports require this commissioning step. The station API supports a write-only `ocpp_password` on create/update.

The old manual `enroll` command refuses changes in dynamic mode to prevent editing an unused registry. Dynamic mode currently uses Basic authentication over WSS, without requiring a client certificate. Client-certificate-only or disabled legacy entries are refused during migration and require deliberate handling. Core outages, invalid credentials, wrong protocol and inactive assets/tenants reject new connections. Existing sockets keep their binding until disconnected.

Verify an enrolled simulator with `scripts/test-ocpp-staging.ps1`, and verify a newly created, separately identified test station without restarting the gateway. Never connect the simulator with an ID currently used by a physical charger. An unenrolled 403 proves rejection only. Roll back the mode by restoring the protected environment backup and recreating the gateway; this restores the old registry and will not include newly enrolled stations. Keep the additive table and credential data for roll-forward; do not drop it as a routine rollback.


## URL-only deployment (current device setup, ADR 0024)

This replaces the Basic-auth activation above for the manufacturer-confirmed Authorisation = 0 setup. Deploy the current platform image and rebuild the gateway image first. No additional schema migration or credential import is required. Keep WSS and gateway/core service authentication enabled. A registered ID is not proof of physical device identity; apply network restrictions where practical.

After pulling this release, build only the gateway with the existing overlays, then activate:

```bash
cd /opt/vtsa-csms
sudo docker compose --env-file .env.staging --env-file .env.staging.ocpp \
  -f infra/compose.yaml -f infra/compose.kyc.yaml \
  -f /etc/vtsa-csms/compose.staging-kyc-dns.yaml \
  -f infra/compose.ocpp-staging.yaml build ocpp-gateway &&
sudo python3 scripts/ocpp-staging.py activate-url-only \
  --compose-override /etc/vtsa-csms/compose.staging-kyc-dns.yaml &&
sudo python3 scripts/ocpp-staging.py gateway-up \
  --compose-override /etc/vtsa-csms/compose.staging-kyc-dns.yaml &&
sudo python3 scripts/ocpp-staging.py verify \
  --compose-override /etc/vtsa-csms/compose.staging-kyc-dns.yaml
```

The helper checks the deployed platform endpoint before changing private configuration. It retains existing registry entries for rollback, clears `OCPP_CORE_AUTH_URL` and sets `OCPP_CORE_REGISTRATION_URL`. Recreating the gateway disconnects existing sockets briefly. Restoring the protected environment backup and recreating the gateway restores the old mode. `gateway-up` does not build images.

Existing active `DEMO-CP-022` with version 1.6J needs no password, credential record or re-save. Its final connection URL is `wss://staging.evcspowersolutions.com/ocpp/DEMO-CP-022`. If the firmware appends its charge-point ID, enter only the base URL `/ocpp` in its URL field. The ID must appear once. Configure Authorisation = 0. Admin now has no OCPP password field.

From local PowerShell, test an ID not currently connected by physical hardware:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\test-ocpp-staging.ps1 -Identity DEMO-CP-022 -Heartbeats 1
```

This clears any inherited simulator password for the test, sends BootNotification/Heartbeat without device credentials and starts no charging transaction. For a deliberately retained legacy Basic deployment only, the helper's `-UseBasicAuthentication` switch loads the existing encrypted simulator credential. URL-only mode ignores old device Authorization headers; the local UI simulator's shared password setting therefore cannot override the ID in the URL. Connection success still requires a correct URL, protocol and active registration. This release's local tests are not evidence that staging has been activated.
