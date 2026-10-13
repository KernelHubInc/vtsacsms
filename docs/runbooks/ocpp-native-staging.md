# Native and Docker OCPP on staging

This is the installed single-host arrangement for `187.53.134.122`. It preserves
the remote PostgreSQL server and the existing Laravel/Redis consumers. It is not
an instruction to remove Docker from the VPS.

## Switch commands (run on the VPS)

```bash
sudo ocpp-runtime status
sudo ocpp-runtime native
sudo ocpp-runtime docker
```

Both choices keep the public URL:
`wss://staging.evcspowersolutions.com/ocpp/{charge-point-id}`.
Switching disconnects existing sockets; chargers and simulators must reconnect.
The command verifies readiness, private routing and service-token access before
stopping the old process. Failures after modifying routing trigger rollback.
Inspect both services if rollback itself fails. The switch does not run migrations,
rebuild images, change station eligibility, or enable remote charging.

```bash
sudo systemctl status vtsa-ocpp --no-pager
sudo journalctl -u vtsa-ocpp -n 100 --no-pager
sudo docker logs --tail 100 vtsa-csms-staging-ocpp-gateway-1
sudo nginx -t
```

## Installed layout

| Resource | Purpose |
| --- | --- |
| `/opt/vtsa-ocpp/releases/<timestamp>` | Versioned native source, virtual environment and dependency inventory |
| `/opt/vtsa-ocpp/current` | Selected native release |
| `/opt/vtsa-ocpp-python` | Isolated Python 3.13 runtime |
| `/etc/vtsa-ocpp/gateway.json` | Private runtime settings, root-owned and group-readable only by the gateway |
| `/etc/vtsa-ocpp/runtime.json` | Switcher paths, runtime names and private ports |
| `/usr/local/sbin/ocpp-runtime` | Installed copy of `scripts/ocpp-runtime.py` |
| `/usr/local/lib/vtsa-ocpp/launch.py` | Protected-settings launcher |
| `/etc/systemd/system/vtsa-ocpp.service` | Native service definition |
| `/etc/nginx/conf.d/vtsa-ocpp-upstream.conf` | One active backend for public WebSockets and private commands |
| `/etc/nginx/conf.d/vtsa-ocpp-internal.conf` | Private core-to-gateway proxy |
| `/etc/nginx/snippets/vtsa-ocpp-staging.conf` | Public OCPP location within the existing HTTPS server |

Docker listens on `127.0.0.1:9002`; native listens on `127.0.0.1:9003`.
The private proxy listens on loopback and the observed staging Docker bridge
`172.16.2.1:9004`, with an allowlist for loopback and `172.16.2.0/24`.
It exposes only health and internal routes, and gateway bearer authentication
still protects metrics and commands. It is not added to the public HTTPS site.

The platform's `OCPP_GATEWAY_INTERNAL_URL` is `http://172.16.2.1:9004` in **both**
modes. Keep this value in `.env.staging` on future deployments. The OCPP Compose
overlay now honors that variable. Native Redis uses the existing authenticated
loopback publication; Docker uses its original Redis service address. Secrets
must never be copied into this document or committed.

## Deploying subsequent changes

Git pull alone does not update the installed native release or installed switcher.
Install updated gateway source into a new release directory using Python 3.13,
install its dependencies, run the gateway quality checks and dependency audit,
and record `uv pip freeze` for that release. Switch to Docker before repointing
`current`, then restart/verify native and switch back. Retain the old release for
rollback. Refresh the protected native settings explicitly when service tokens,
Redis credentials or core endpoints change; the two configurations do not
automatically synchronize.

After updating runtime tooling:

```bash
cd /opt/vtsa-csms
sudo install -m 755 scripts/ocpp-runtime.py /usr/local/sbin/ocpp-runtime
sudo install -m 644 scripts/ocpp-native-launch.py /usr/local/lib/vtsa-ocpp/launch.py
sudo install -m 644 infra/systemd/vtsa-ocpp.service /etc/systemd/system/vtsa-ocpp.service
sudo systemctl daemon-reload
sudo ocpp-runtime status
```

The older `ocpp-staging.py up`, `gateway-up`, `verify`, `status` and `logs` commands
target Docker. They do not manage the native service. After rebuilding or recreating
Docker while native is selected, run `ocpp-runtime native` again to stop the idle
Docker gateway and restore the selected startup policy. Avoid `docker compose down`:
native still depends on existing Redis/core services.

## Acceptance

### LAN heartbeat compatibility — 2026-10-11

The physical charger `202607010021` was reconnecting exactly 60 seconds after each
upgrade even while sending OCPP Heartbeat. Staging now uses these protected native
settings, with the same keys supported by Docker environment injection:

```text
GATEWAY_HEARTBEAT_INTERVAL_SECONDS=30
GATEWAY_WS_PING_TIMEOUT_SECONDS=0
GATEWAY_MESSAGE_IDLE_TIMEOUT_SECONDS=180
```

Zero disables only Uvicorn's WebSocket Pong deadline. Ping frames continue every
30 seconds, and BootNotification requests an OCPP heartbeat every 30 seconds.
An application receive timeout closes a silent socket after 180 seconds and emits
the existing disconnect event. The core's 180-second last-message threshold also
expires presence if disconnect delivery fails. Other deployments keep the default
30-second Pong deadline. Require an idle timeout of at least three heartbeat
intervals; do not use an infinite application timeout or force online in the UI.

This is a staging firmware compatibility setting, not a claim that all charger
firmware handles Ping/Pong identically. It tolerates missing Pong while requiring
OCPP traffic. TLS, registration and tenant checks remain in force. Nginx's existing
120-second read timeout is kept alive by server Ping frames.

The installed native release is `/opt/vtsa-ocpp/releases/20261011T021500Z`.
Dependencies were retained from the previous release; the tested source was copied
into both its source tree and isolated installed package. The previous release and
`/etc/vtsa-ocpp/gateway.json.before-heartbeat-20261011` are retained for rollback.
Rolling back the native symlink/settings and restarting disconnects clients.
The companion nullable status column may safely remain during a code rollback.
Do not drop it while any new consumer or API code is running.

The status migration was applied to the existing remote staging database. The six
platform source/migration files were installed in the host checkout and all five
core containers. The four local core image tags were updated with the same files
so container recreation preserves the patch. Previous images remain tagged
`vtsa-status-backup-1:20261011` through `vtsa-status-backup-4:20261011`; the source
backup is `/root/ocpp-status-20261011/platform-source-before.tar.gz`.
Commit the local source changes before the next Git-based deployment. The standby
Docker gateway must be rebuilt/recreated with the updated Compose overlay before
switching to it to get the new heartbeat settings; the active native gateway is
already updated.

Check the physical connection without occupying its identity with a simulator:

```bash
sudo ocpp-runtime status
sudo journalctl -u vtsa-ocpp --since '10 minutes ago' --no-pager | grep -E '202607010021|ocpp_message_idle_timeout'
```

Admin Charging Stations and Status and faults poll every five seconds. The public
station endpoint caches for five seconds and the foreground mobile app polls every
five seconds, so a healthy pipeline may take roughly ten seconds to show a change.
Charging and suspended labels require real device StatusNotification evidence.

Verification on October 11: the physical connection opened at `02:10:48 UTC`
and remained on the same connection ID beyond `02:31 UTC`, with approximately
30-second last-message updates and Available on the public API and admin query.
No simulator used this physical identity during verification. Actual vehicle
charging was not initiated. Automated checks passed: 204 backend tests, 150 mobile
tests, 82 gateway tests, 23 staging/runtime tests and four map tests. The optional
local Redis integration test was skipped because `TEST_REDIS_URL` was unset;
staging Redis readiness and event consumption were checked separately. PHP static
analysis, Flutter analysis, gateway typing/lint, contracts, platform asset build,
and Composer/pip vulnerability audits also passed.

Verify readiness and internal authentication, rejection of an unknown device,
and BootNotification/Heartbeat for a registered unused test identity through
public WSS. Confirm the backend's last-seen projection updates. Exercise switching
in both directions. Use an invalid command request to validate private endpoint
reachability without starting charging. Physical charger acceptance and actual
remote start/stop require a separate idle-device test and appropriate feature flags.
