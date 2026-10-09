# Prepaid wallet and AUB QR Ph staging

This release supports simulated prepaid top-ups and a gated AUB adapter. It has not been certified against live AUB. Never enable live collection merely to make a staging test pass.

## Deploy

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
