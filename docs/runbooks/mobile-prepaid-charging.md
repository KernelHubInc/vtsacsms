# Mobile prepaid charging: staging

## Wallet limit, automatic stop and email confirmation

The amount shown as the estimated preauthorization is the session's reserved budget, not the wallet's entire balance. The backend computes the minimum of the available wallet balance, any account/vehicle spending cap and `MOBILE_CHARGING_MAXIMUM_RESERVATION_MINOR`. Staging is currently configured for 100 centavos (PHP 1) per session. To rehearse a PHP 500 budget, set the staging-only maximum to 50,000 centavos, recreate the web service and confirm the fresh quote displays that budget. Do not change a published tariff to reach the limit without an operator-approved price test.

While charging, the prepaid monitor uses fresh meter readings, the tariff snapshot, connector maximum power and a 90-second stop buffer to request a remote stop before the budget is exhausted. Keep the independent monitor running every ten seconds. Remote stop is a request to the charger, not a hardware cutoff; stale telemetry, network loss or charger refusal can cause the final amount to exceed the hold. Only the charger's final transaction evidence and a complete finalized CDR allow settlement. An over-budget or incomplete result remains `requires_action`, retains the hold and needs an operator review; the wallet cannot be debited beyond its reservation.

Once a completed customer session is captured, Notifications records one tenant/session-scoped email request for the initiating driver's currently verified address. The independent `notifications:send-charging-summaries` runner sends due requests at least once a minute, retrying transient failures with backoff. The branded email and attached A4 PDF share the session reference, station, connector, Philippine-time start/stop times, delivered kWh, duration, final PHP charge and payment method. They confirm the charging payment but are not a tax invoice or official tax receipt; statutory document issuance remains gated by FR-BIL-002. PDF generation is in-memory at send time, so no copy containing driver transaction details is left on the server's public filesystem.

Deploy the additive `charging_email_deliveries` migration and keep a dedicated email runner separate from the charging monitor so SMTP delays cannot block budget-stop checks. Configure and verify the real SMTP mailer and sender address; `MAIL_MAILER=log` does not deliver externally. Inspect attempts by tenant and session without logging addresses or message contents. An already-captured session with no email row can be reconciled individually to queue the missing confirmation; do not bulk-send old sessions. A lost provider acknowledgment may rarely cause a duplicate email after the five-minute claim lease expires; use the session reference to investigate.

## Pricing in admin or operator

1. Open **Charging network > Tariffs**. The role needs tariff view, manage and publish permissions.
2. Create a named PHP tariff if needed; creation alone leaves it draft.
3. Choose **Set pricing**. Select **Energy (per kWh)** and enter **1.00** for the test.
4. Select **LUMINA SUBIC / 202607010021 / Connector 1**. Leave Site blank for this connector-specific price. Do not clear both scopes unless pricing should apply tenant-wide.
5. Use **Asia/Manila** and the applicable tax treatment/rate. The test price has zero added tax; do not infer commercial tax rules from it.
6. Publish. New sessions use the new price. To switch later choose **Session (once)** or **Energy-flow time**, entering the per-minute or per-hour amount. This replaces components for that scope rather than stacking an extra fee.

Examples: 1,250 Wh at PHP 1/kWh costs PHP 1.25. PHP 1/session costs PHP 1 for a finalized session. PHP 1/minute charges only metered energy-flow seconds and rounds once to centavos. Missing time evidence requires review. Existing sessions retain their original tariff snapshot.

## Driver flow

For the supervised CSMS/device/EV start, stop and wallet checks, use
[Charging acceptance test](charging-acceptance-test.md).

OCPP authorization tags issued for new sessions use 20 random alphanumeric
characters, compatible with OCPP 1.6's idTag limit and OCPP 2.0.1. The former
45-character format failed outgoing OCPP 1.6 schema validation before sending
RemoteStartTransaction. Authorization remains hashed, station/session scoped,
expiring and revocable; command secrets remain encrypted. Existing tokens and
command history are preserved. Retry through a new quoted request, not by
replaying a failed or cancelled command. No device configuration change is needed.

1. Install the release APK; complete sign-in and required verification.
2. Use Wallet > Add money > QR Ph. Confirm credited funds before starting.
3. Plug in the EV. Scan the connector QR or enter `202607010021` while it has exactly one connector.
4. Review the connector, price and reserved wallet budget. Tap Request start once.
5. Wait for charger-confirmed start. Watch energy, power, elapsed time, metered energy-flow time and estimated cost. Paused sessions are not finished sessions.
6. Request Stop, or let the prepaid monitor request it near the budget. Wait for final charger evidence.
7. Check final cost and wallet balance. Unused held funds are released. If review is required, retain the session reference; do not pay or start again for that attempt.

The PHP 1 test budget can stop before 1 kWh to leave time for the charger to respond. It is separate from the PHP 1/kWh unit price. Charging completion debits the wallet; AUB funds the wallet. Network loss or charger refusal can delay a remote stop; this is not a hard hardware energy cap.

## Runtime requirements

### Cancelling a stuck start

Use **Cancel pending start** in the mobile activity screen. An unsent command cancels immediately. For a sent command, **Cancellation requested** means the server saved the intent and revoked the unused authorization; it is waiting for physical evidence, not another tap. Refresh the activity after the charger reconnects.

The prepaid monitor can finish cancellation and release the wallet hold after the start/command deadlines plus 60 seconds, provided the current online connection has reported Available after that window and there is no transaction/meter evidence or unresolved inbound transaction event. Offline, Preparing, Charging, stale-connection or conflicting evidence cannot release funds automatically. Reconnect the charger while idle; do not connect a simulator with its identity to fabricate an Available report. If physical charging started, the normal stop and settlement flow applies.

This recovery requires the 2026-10-17 backend release in the API, event consumer, worker and prepaid monitor. The existing mobile cancellation and refresh API contract is unchanged; no APK update or migration is needed. Inspect the `charging.session.cancelled` audit event for reason `customer_cancelled_after_idle_confirmation` and its evidence instead of directly editing session/wallet rows.

Staging verification on 2026-10-17: image `vtsa-prepaid-platform:20261017-cancel-start` is deployed to all five application services. The full candidate suite passed 261 tests / 2,238 assertions; the new regression failed against the preceding image. Pint, PHPStan and the locked Composer advisory audit passed. Forty rollback-only PostgreSQL API checks and a rehearsal against the affected request passed. The live monitor then completed that cancellation, recorded one 100-centavo release with zero spend and cleared the account's active request. Readiness and authorization checks passed; native OCPP gateway PID was unchanged. Backup, source manifest, test output and previous Compose selections are retained privately under `/opt/vtsa-releases/cancel-start-20261017/`. No physical charging cycle was initiated during this verification.

### Auxiliary telemetry and session review

The charger can include voltage, current and temperature alongside billing energy/power samples. `MeterValueService` recognizes finite numeric Voltage/V, Current.Import/Export/Offered/A and Temperature/Celsius/Fahrenheit/K values as auxiliary telemetry. These remain in the retained inbound event and are not stored as billing readings. Voltage/current must be non-negative; signed temperatures are allowed. Malformed or unsupported samples still trigger review. Energy/power validation and meter-reset detection are unchanged.

The earlier Wh/W-only validator flagged Lumina's valid auxiliary samples as `invalid_meter_value`, blocking CDR generation and wallet settlement after a successful stop. Existing review flags are not erased automatically. Recovery must validate the original session's complete retained evidence using the corrected code in a rollback rehearsal, retain the agreed tariff snapshot, and resolve through `ManualSessionReviewService` with an audit note. `PrepaidChargingMonitor` then performs the existing idempotent wallet settlement. Do not edit wallet rows, clear all anomalies, or replay device commands.

The mobile shell currently labels both finalizing and review-required phases as “Finalizing session”; opening the session shows its review state. A blocked review does not resolve by waiting, reconnecting the charger, cancelling a physically completed session or paying again. The portal's Session review queue is currently read-only; recovery uses the authorized backend workflow.

Staging release `vtsa-prepaid-platform:20261017-meter` passed 264 tests / 2,294 assertions, Pint and PHPStan; the mixed-telemetry regression failed against the preceding image. All five application services were updated, readiness passed, and the native gateway process was unchanged. No schema or APK change was required. Release helpers and rollback evidence are retained privately at `/opt/vtsa-releases/meter-20261017/`.

Lumina session `01m54njdaynr91xgbxpew5kfs1` was resolved at 18:49:06 GMT+8 on October 17 after validating its 46 retained meter events and StopTransaction against the corrected validator and recalculating in a rolled-back transaction. Original energy (2,482 Wh), elapsed duration (1,368 seconds), flow duration (1,320 seconds), tariff and historical anomaly evidence were preserved. The saved tariff was PHP 1/hour of energy flow despite the tariff's per-kWh name; it was not changed retrospectively. The audited review finalized PHP 0.37, the wallet recorded one 37-centavo spend and one 63-centavo release from the 100-centavo reservation, and the mobile projection returned completed/captured with no active request. Review the current tariff configuration separately before the next test.

### Services

Redis must use `restart: unless-stopped` in Compose and on the existing container. On October 18, staging returned HTTP 500 because the app VPS had restarted at 22:37 GMT+8 on October 17 while Redis had restart policy `no`. The persisted Redis container remained stopped; session/rate-limit lookups failed with `getaddrinfo for redis failed`, and consumers/workers restarted repeatedly. Starting the same Redis container restored its AOF data and service connectivity. The runtime policy and `infra/compose.yaml` were corrected, without starting the unused local PostgreSQL or Docker OCPP fallback. Staging uses its external database and native gateway.

Recovery checks: authenticated Charging Stations page loaded; login and readiness returned HTTP 200; application workers resumed; all five Compose environment tests passed. The regression asserts Redis's restart policy alongside password protection and loopback binding. Do not flush Redis or remove its volume during this recovery. `unless-stopped` does not undo an intentional operator stop; start the existing service explicitly when appropriate.

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
