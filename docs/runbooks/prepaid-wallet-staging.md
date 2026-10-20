# Prepaid wallet and AUB QR Ph staging

This release supports simulated prepaid top-ups and a gated AUB adapter. It has not been certified against live AUB. Never enable live collection merely to make a staging test pass.

## Deploy

### QR creation recovery, 2026-10-11

The first PHP 1 order appeared in the merchant portal as Unpaid while the app
had no usable QR or invoice saved. Its original provider response was not retained,
so the precise failed validation cannot be reconstructed. Do not recreate that
same provider order or substitute a portal checkout/platform ID for `invoiceId`.

Creation failures now log `payments.qr_topup.creation_failed` with tenant/top-up
references, a reason, bounded provider error/status codes, and lengths of the
documented QR fields. Raw XML, QR values, signatures, keys and provider messages
are excluded. A verified successful merchant response retains a valid invoice
even when the QR or expiry is incomplete. Inquiry without an invoice is skipped
without changing the order's state. No database migration is needed.

The mobile recovery screen explains when a QR is unavailable, offers manual
refresh of the existing request and stops automatic polling of ambiguous/review
states. Valid pending responses display the QR; expired codes are hidden. Refresh
never submits another provider order. Only a verified matching payment callback
credits the wallet.

For acceptance, use one user-initiated PHP 1 request after deploying diagnostics.
Inspect its diagnostic reason if it has no QR. Do not relax signature/merchant
validation or invent missing invoice/expiry values to make a code visible. A
verified successful response with valid QR and expiry can omit invoiceId;
the observed AUB response did so. That omission prevents inquiry, not QR display.
After
a usable QR is generated, the owner can pay it and verify that the signed callback
credits exactly once. On 2026-10-12 the owner reported payment success and the
backend contained a paid PHP 1 order with one matching 100-centavo credit.
Provider settlement reconciliation remains unverified.

The running web container and staging platform image both contain the missing-
invoice compatibility fix (SHA256 of `QrTopups.php`:
`8293966706ee3494911d89246fe3b6583f35392fef2cd0f3456809b58391651a`).
The previous image is retained as
`vtsa-csms-staging-platform:before-qr-recovery-20261012`; original source backup
is `/root/qr-recovery-20261011T083404Z/`. The image update copied only application
source, not runtime environment secrets. No service restart or migration was
needed. Future source builds must include the same repository fix.

### Production-credential activation, 2026-10-11

Following the owner's authorization for real PHP 1 tests, the staging web app
loaded the privately supplied merchant credentials. The outbound public IPv4
was verified as `187.53.134.122`, matching `AUB_SERVER_IP`. No credential values
were printed or committed.

The VPS base Compose file now forwards the wallet/AUB fields. Only the running
`platform` service was recreated; its existing PHP server command and entrypoint
were retained in `/etc/vtsa-csms/compose.staging-wallet-runtime.json`.
Preserve this override for subsequent web-app deployments:

```bash
cd /opt/vtsa-csms
docker compose -p vtsa-csms-staging --env-file .env.staging \
  -f infra/compose.yaml \
  -f /etc/vtsa-csms/compose.staging-wallet-runtime.json \
  up -d --no-deps --no-build --pull never platform
docker exec vtsa-csms-staging-platform-1 php artisan config:clear
```

Runtime verification returned `mode=live`, `topups_enabled=true`, minimum and
maximum 100 centavos, and simulated payments disabled. A tenant-scoped wallet
read returned HTTP 200; admin login returned HTTP 200; an unsigned empty callback
returned HTTP 400. The native OCPP service stayed active and its consumers were
not restarted. The worker and scheduler were already stopped and were not started
as part of this activation; background processing requires separate diagnosis.

These checks verify configuration and callback rejection, not AUB acceptance of
the merchant/signing key. No provider order, payment or refund was created.
The next acceptance step is a user-initiated PHP 1 QR creation, followed by a
matching signed confirmation and balance update if the user completes payment.
Retain the documented refund limitation.

Private pre-activation configuration backups are held in
`/root/aub-activation-20261011T070743Z/`. To stop new collection, set
`WALLET_MODE=disabled` and recreate the web app with the command above. Keep AUB
credentials and callback approval available for outstanding confirmations;
do not roll back wallet tables or erase financial records.

### Staging repair, 2026-10-11

Wallet requests failed because the deployed database had not applied the wallet
migration. The migration has now been applied on staging; tenant-scoped controller
probes for a new subject return HTTP 200, an empty live balance/history and no
top-ups. The probes did not create an account or payment.

For another deployment with the same missing schema, run this once on the app VPS:

```bash
docker exec vtsa-csms-staging-platform-1 php artisan migrate --force --path=database/migrations/2026_10_08_000002_create_prepaid_wallets.php
```

The app connects to the existing external database; no local database is created.
The base Compose file now forwards wallet/AUB configuration to all application
processes. Keep merchant secrets in private environment injection, never in Git.
Do not print the resolved Compose configuration when it contains those secrets.

The 1.0.10 mobile build permits real wallet payments only when the backend also
enables them. Simulated payments remain off. At this repair, staging still has
`WALLET_MODE=disabled`, no AUB merchant/signing configuration, and no confirmed
merchant activation for prepaid charging credit. Add money therefore remains
disabled. Merchant credentials, activation, approved limits, callback verification
and live settlement acceptance are outstanding; this repair does not certify live
AUB collection or connect wallet funds to physical charging.

Verification for this repair: 205 backend tests / 1,706 assertions, 155 mobile
tests, and five Compose environment tests passed. PHPStan, Pint and Flutter
analysis passed. Composer audit reported no advisories. No live AUB request or
financial transaction was made.

### Deployment settings

The local `.env` and `apps/platform/.env`, and the VPS
`/opt/vtsa-csms/.env.staging`, have AUB parameter slots prepared. Existing values
are preserved. Merchant ID and signing key must be entered privately; they are
not generated by this application. The staging tenant and HTTPS callback are
pre-filled on the VPS. `AUB_SERVER_IP` must be the outbound IP accepted by AUB.
The portal login password is not used by this API adapter and must not be copied
into application configuration.

The owner clarified on 2026-10-11 that this is a production-ready merchant and
authorized controlled testing with real money. Do not treat the credentials as
a sandbox or route these tests through `WALLET_MODE=simulated`. Provider API
collection uses the guarded `live` mode. The portal URL is not the gateway API
URL. The merchant ID, signing key and server IP were still absent from the VPS
at the configuration check, so collection has not been enabled.

For the first real payment test, the private staging environment now prepares
`WALLET_MINIMUM_MINOR=100` and `WALLET_MAXIMUM_MINOR=100` (exactly PHP 1 per top-up).
These take effect when the application containers receive the updated environment.
This top-up amount is separate from the existing PHP 1 per charging-session
tariff on `202607010021`; setting a tariff does not charge or refund a wallet.
The application has no QR Ph refund operation. Do not promise an automatic refund;
the merchant must establish the available refund/reversal process with AUB.

To fill the private VPS fields without putting secrets in command arguments:

```bash
sudo nano /opt/vtsa-csms/.env.staging
```

Fill `AUB_MERCHANT_ID` and `AUB_SIGNING_KEY` from the merchant's API credentials.
Leave `WALLET_MODE=disabled`, `AUB_QRPH_APPROVED=false` and
`FEATURE_REAL_PAYMENTS=false` while the required credentials are missing.
Prepared environment fields alone do not change running containers. Apply the
updated Compose configuration and recreate application processes during actual
activation; retain existing private deployment overrides.

Deploy the matching backend before the mobile build. Apply additive migration `2026_10_08_000002_create_prepaid_wallets.php` using the established staging deployment. It creates four new tables and does not alter existing financial records. Restart app/worker processes after changing injected configuration. Compose environment values must reach containers; editing a host file alone is insufficient. Do not use `migrate:fresh` on staging.

For simulated testing only, inject:

```dotenv
WALLET_MODE=simulated
FEATURE_SIMULATED_PAYMENTS=true
FEATURE_REAL_PAYMENTS=false
AUB_QRPH_APPROVED=false
WALLET_MINIMUM_MINOR=100
WALLET_MAXIMUM_MINOR=100000
```

These are test limits (PHP 1 through PHP 1,000), not approved live business limits. APP_ENV must already be local/testing/staging/demo. Keep KYC policy enabled as configured; use an eligible staging account. No AUB credentials are needed for simulation.

1. Sign in, open Wallet, confirm TEST BALANCE is visible.
2. Create a test top-up. Copy its reference. Pending must not increase the balance.
3. Inside the staging Laravel container run:

```bash
php artisan wallet:simulate TENANT_ULID TOPUP_ULID
```

Replace both references with the staging tenant and displayed top-up ID. This command only confirms simulated orders and cannot affect the live book. Running twice must credit once. Reopen Wallet or check status to see confirmed simulated funds.

4. Test invalid amounts, interrupted requests with same-key retry, account switching, app restart and pagination. Tests must never display a payable QR for simulated orders.

## Stop/start simulation

Set `FEATURE_SIMULATED_PAYMENTS=false` to stop simulated collection; set it back to true with `WALLET_MODE=simulated` to resume. Recreate/reload the affected containers using the deployment's usual process. The disabled view shows the live book; simulated records remain retained and reappear when simulation resumes. These flags do not stop registration mail or the queue worker.

For all new collection, use `WALLET_MODE=disabled`. This preserves records and does not reject authenticated confirmations for existing live orders while AUB approval/key configuration is retained.

## Live readiness (not approved)

Resolve ADR-0022's AUB questions first. Live mode additionally requires explicit `FEATURE_REAL_PAYMENTS=true`, `AUB_QRPH_APPROVED=true`, `AUB_TENANT_ID`, `AUB_MERCHANT_ID`, `AUB_SIGNING_KEY`, `AUB_SERVER_IP`, and `AUB_NOTIFY_URL` through secret/environment injection. Confirm merchant currency PHP and approved amount limits. Use a separate approved merchant/account for controlled staging collection where available; simulated and real funds must never mix.

The callback path is `/api/v1/webhooks/aub/qrph`. It requires publicly reachable HTTPS, no interactive authentication, and returns plain `success` after durable processing. It does not use a mobile bearer token. Rejected messages get safe error envelopes and no credit. Do not record raw callback bodies or keys in reverse proxy logs. Review infrastructure request-body and outbound HTTP telemetry settings before live use.

The supplied PDF uses `invoiceId` in creation and `invoice_id` in inquiry. SHA256 is the only implemented signature mode. Unknown/unsigned/error responses remain unresolved. No QR Ph refund/close request is issued.

For explicit, read-only provider inquiry after approval:

```bash
php artisan wallet:reconcile TENANT_ULID
```

This checks at most 50 expired/ambiguous orders older than 15 minutes for the configured merchant tenant. It never creates a new payment and never credits from an ambiguous query response. Orders missing invoice IDs require AUB support/reference lookup; do not guess an ID or create another order to recover money. Review is resolved by verified notification in this release; there is no manual "mark paid" switch. Automated query-based recovery and settlement report comparison remain live-release requirements.

## Roll-forward and testing limitations

Disable collection first if rollback is necessary. Retain the database tables and compatible callback processor until pending real orders resolve. Migration down deletes new financial tables and must not be used once there is money history without approved backup/retention handling. Old application code can remain deployed with the additive schema.

Reservation/finalization contracts are tested internally; physical charging is not yet wired to prepaid funds. Cards, hosted online wallet checkout, cash-out and refunds are unavailable. Before release perform PostgreSQL concurrency tests, AUB-reviewed signing/status contract tests, and an explicitly authorized small real-money verification followed by settlement reconciliation. Merely generating a QR does not establish successful collection.

## Local verification (2026-10-08)

- Backend: 197 tests / 1,572 assertions passed, including idempotent credits, holds, tenant/owner isolation, late confirmations, invalid payment facts, network ambiguity, and XML/signature validation.
- Mobile: 142 tests passed; analyzer reports no issues. The complete run used concurrency 2 and a two-minute test timeout for the existing PIN derivation tests. Assertions were unchanged.
- PHPStan, Pint, OpenAPI validation/generation and Vite production build passed.
- A phone-size widget render was inspected; physical-device installation, camera behavior and staging end-to-end behavior remain unverified.
- New QR dependencies had no reported OSV advisories at review time. The existing backend Filament medium advisory CVE-2026-104181 / GHSA-7m6h-rg42-m449 remains; no PHP dependency was changed or advisory suppressed. Apply the supported patch before production promotion.
- No AUB production requests were made. No backend deployment or APK upload was performed.

## Packaged staging APK

`Power-Solutions-Staging-Wallet-1.0.7.apk` is available locally under `.cache/mobile-staging-wallet/`, with a checksum and build-info JSON. Version 1.0.7 / code 8, package `com.vtsa.vtsa_mobile`, minimum Android API 24, three ABIs. Signature verification passed using the existing staging debug certificate; this is a staging test artifact.

SHA256: `e9005f6f3575c9452c09c54b0afac30d51b3686d015143eafc1f651b6414456f`.

The build targets the staging API, enables simulated payments, and keeps real payments and remote charging off. The Gradle build warns that the existing mobile_scanner plugin will need a future Kotlin migration; it completed successfully. Deploy the backend described above before device acceptance. This APK has not been uploaded or tested on a physical device.
