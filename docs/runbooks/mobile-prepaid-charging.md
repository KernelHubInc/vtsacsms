# Mobile prepaid charging: staging

## Pricing in admin or operator

1. Open **Charging network > Tariffs**. The role needs tariff view, manage and publish permissions.
2. Create a named PHP tariff if needed; creation alone leaves it draft.
3. Choose **Set pricing**. Select **Energy (per kWh)** and enter **1.00** for the test.
4. Select **LUMINA SUBIC / 202607010021 / Connector 1**. Leave Site blank for this connector-specific price. Do not clear both scopes unless pricing should apply tenant-wide.
5. Use **Asia/Manila** and the applicable tax treatment/rate. The test price has zero added tax; do not infer commercial tax rules from it.
6. Publish. New sessions use the new price. To switch later choose **Session (once)** or **Energy-flow time**, entering the per-minute or per-hour amount. This replaces components for that scope rather than stacking an extra fee.

Examples: 1,250 Wh at PHP 1/kWh costs PHP 1.25. PHP 1/session costs PHP 1 for a finalized session. PHP 1/minute charges only metered energy-flow seconds and rounds once to centavos. Missing time evidence requires review. Existing sessions retain their original tariff snapshot.

## Driver flow

1. Install the release APK; complete sign-in and required verification.
2. Use Wallet > Add money > QR Ph. Confirm credited funds before starting.
3. Plug in the EV. Scan the connector QR or enter `202607010021` while it has exactly one connector.
4. Review the connector, price and reserved wallet budget. Tap Request start once.
5. Wait for charger-confirmed start. Watch energy, power, elapsed time, metered energy-flow time and estimated cost. Paused sessions are not finished sessions.
6. Request Stop, or let the prepaid monitor request it near the budget. Wait for final charger evidence.
7. Check final cost and wallet balance. Unused held funds are released. If review is required, retain the session reference; do not pay or start again for that attempt.

The PHP 1 test budget can stop before 1 kWh to leave time for the charger to respond. It is separate from the PHP 1/kWh unit price. Charging completion debits the wallet; AUB funds the wallet. Network loss or charger refusal can delay a remote stop; this is not a hard hardware energy cap.

## Runtime requirements

Run one worker consuming `charging`, plus one prepaid monitor every ten seconds. Keep queue retry time longer than worker timeout and gateway request timeout. The monitor and Stop route must remain available when new starts are disabled. Never replay unrelated queues as a deployment shortcut.

The gateway allowlist must include the required start/stop actions, and the internal URL must reach the gateway holding the device connection. Do not connect a simulator using the physical charger's ID.

The pilot supports PHP wallet payments, one all-day pricing dimension, one unsettled customer session, and no automatic billing of incomplete or over-budget evidence. Refund execution, support submission and legal invoice generation remain outside this pilot; retain the session reference for operator assistance.

## Staging release: 12 October 2026

- [APK 1.0.12, build 13](https://staging.evcspowersolutions.com/demo/Power-Solutions-Staging-Lumina-1.0.12.apk): 79,447,802 bytes.
- SHA256: `ffb7a4682ba38545edf25fb6896487b961da2d92560671c4d06203d052036a45`.
- Signed with the same staging Android certificate as 1.0.11; not a production Play Store release.
- Charger `202607010021`: tariff version `01m4qmpg9610gw2mqh9172rsac`, PHP 1/kWh, connector-specific. The old per-session version remains intact.
- Web and consumers use `vtsa-prepaid-platform:20261012`. Root-only overrides: `/etc/vtsa-csms/compose.staging-prepaid-platform.json` and `/etc/vtsa-csms/compose.staging-prepaid.json`. They preserve remote PostgreSQL. Do not print these files; they contain credentials.
- Backups/source bundle: `/root/prepaid-release-20261012/`. Previous images: `vtsa-prepaid-backup-platform:20261012` and `vtsa-prepaid-backup-core:20261012`.
- Native gateway: only OCPP 1.6 remote start/stop enabled. Installed module and source accept lowercase platform ULIDs. Rebuild and configure the Docker fallback before using it for mobile charging.
- Both additive migrations dated `2026_10_12` were applied. Do not roll back their schema after customer usage; it contains financial correlation evidence.
- Release images are pinned in the private overrides. A Git pull alone does not rebuild them. A subsequent release must update the image reference in both overrides and recreate web, consumers, worker and monitor together.

Runtime checks:

```bash
cd /opt/vtsa-csms
docker ps --format '{{.Names}} {{.Status}}'
docker logs --since 5m vtsa-prepaid-staging-charging-worker-1
docker logs --since 5m vtsa-prepaid-staging-charging-monitor-1
sudo journalctl -u vtsa-ocpp --since '5 minutes ago' --no-pager
```

Recreate the prepared release:

```bash
cd /opt/vtsa-csms
docker compose -p vtsa-csms-staging --env-file .env.staging \
  -f infra/compose.yaml \
  -f /etc/vtsa-csms/compose.staging-wallet-runtime.json \
  -f /etc/vtsa-csms/compose.staging-prepaid-platform.json \
  up -d --no-deps --no-build --pull never platform ocpp-event-consumer ocpp-authorization-consumer
docker compose -p vtsa-prepaid-staging \
  -f /etc/vtsa-csms/compose.staging-prepaid.json up -d --no-build --pull never
```

Republish the verified APK from Windows:

```powershell
cd "C:\Users\Migz\Documents\VSTA Charging"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\publish-staging-apk.ps1 -Publish
```

Acceptance checked: automated backend/mobile/gateway tests, authenticated internal command transport to a nonexistent ID, PostgreSQL migrations, physical charger heartbeat/Available status, and public APK download. No physical charging or real wallet debit was initiated. With no EV present, install and browse only. Physical Start, meter values, pause/resume, Stop and final wallet debit still need a supervised EV test.
