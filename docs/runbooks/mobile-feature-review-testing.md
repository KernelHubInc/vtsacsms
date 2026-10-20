# Mobile feature-review staging test: 1.0.6 (7)

Source: `VTSA Powersolutions Feature Review.pdf`, dated 2026-10-06. Product confirmations: age 18 and Plate pending allowed.

| ID / review point | Implementation and test scope |
| --- | --- |
| FR-01 Registration errors | Generic account-creation failure; duplicate-email validation does not expose “already taken.” This is not a claim of complete account-enumeration resistance. |
| FR-02 Names and birthday | Separate first/middle/last names; optional middle name; date picker with `dd MMM yyyy` display; server-validated minimum age 18. Existing profiles are preserved. |
| FR-03 Profile / OCR | Registration fields prefill KYC when available. Existing OCR compares submitted photos with entered details. Automatic pre-submit OCR autofill remains deferred. |
| FR-04 KYC consent and details | Existing consent/photo/liveness workflow retained; separate names, guided dates, Philippine issuing-country selector, nationality selector. No change to automatic approval policy. |
| FR-05 Vehicle onboarding | Plate or Plate pending creates a default server vehicle atomically with the account. Manufacturer/model/connectors can be added later. |
| FR-06 Vehicle switching | Explore vehicle selector applies preferred connectors. Unknown compatibility is not shown as compatible. Earlier local vehicles remain editable for migration. |
| FR-07 Wallet | Follow-up implemented using the subsequently supplied AUB Merchant-Presented QR v1.4.3: simulated prepaid balance and gated QR Ph adapter. See [wallet staging runbook](prepaid-wallet-staging.md). Live collection and card payments remain disabled. |
| FR-08 Activity | Existing server session metrics, timestamps, receipt/invoice links retained. Payment status labels are readable. No receipt is fabricated if absent. |
| FR-09 Discovery | Individual available/in-use/reserved/stale/offline connector states and reported availability count; Nearest first uses a 50-km search and straight-line distance. Queue lengths/bookings remain deferred pending operating rules and APIs. |
| FR-10 Charging controls | Disabled-state copy explains availability without internal milestone terminology. Existing feature flags still gate actions. |
| FR-11 Filters and hours | Food, shopping, restroom, and open-now filters; weekly local hours; overnight carryover; no “Closed now” for missing hours. Connector filters apply to one matching connector. |

## Deployment order

1. Preserve the existing staging application key and take the normal database backup.
2. Deploy the matching platform source and the additive migration `2026_10_08_000001_add_driver_onboarding.php` through the existing staging image/release process. Run `php artisan migrate --force` in that staging release. Do not run `migrate:fresh`.
3. Refresh the staging application configuration/routes using the normal release process, and replace platform/worker/scheduler together as appropriate. Preserve current KYC secrets, HTTPS notices, and automatic approval OFF. Do not resume maintenance mail scheduling merely to test this APK.
4. Check v1 registration returns the update-required response, v2 registration is present, existing sign-in still works, and authenticated `/api/v1/vehicles` returns the current driver's garage.
5. Install the APK and test the scenarios below. Uploading an APK alone does not apply backend routes or migrations.

This task generates a local APK; it does not establish that the backend changes have been deployed or the APK is available on Hostinger. A code rollback should retain these additive tables/columns. Do not drop user-entered data to roll back a mobile build.

## Device acceptance

- Register at exactly age 18; reject one day younger, impossible dates, missing plate without pending, and weak passwords. Verify account recovery if email delivery fails.
- Sign in to an existing account and check PIN/fingerprint unlock, favorites and old local vehicles. Add the plate/pending choice to migrate a local vehicle. Check a second device sees server vehicles.
- Switch default vehicles; remove the default; verify the next one becomes default. Verify two users/tenants cannot access each other's vehicles.
- Open KYC, confirm profile prefill, consent, document/date requirements, photo retakes and real-camera liveness prompts. Use authorized synthetic staging material; successful unit tests do not prove physical-camera performance.
- Filter by a vehicle's connector and available/high-power; the available connector must itself match. Disconnect a test charger and verify freshness handling.
- Exercise nearest search with location allowed and denied, overnight operating hours and missing hours, and food/shopping filters. Distances are not driving-route estimates.
- Open finalized activity with server-issued receipt/invoice links; pending payments must not offer duplicate payment.

## Remaining release concerns

- AUB Merchant-Presented QR v1.4.3 now supports the gated QR Ph adapter. Merchant approval for prepaid funding, confirmed signing/status contracts, reconciliation and controlled live verification remain prerequisites; remote card payments need a separate supported API.
- Pre-submit OCR autofill and queue/booking workflows are not included.
- Dependency audit on 2026-10-08 reports existing medium Filament advisory `CVE-2026-104181` / `GHSA-7m6h-rg42-m449`. The mobile change does not modify the affected dependency. Apply the supported backend patch before a production promotion.
- Server deployment, PostgreSQL concurrency, email delivery, real camera/liveness and APK installation must be tested on staging/devices; local automated tests cannot certify those.

## Local verification results

- Mobile: 138 tests passed; final Dart analyzer reports no issues.
- Backend: 186 tests / 1,514 assertions passed; subsequent focused privacy/registration/discovery checks passed 14 tests / 113 assertions.
- PHPStan and Pint passed. OpenAPI lint, event-schema validation, contract generation and Vite production build passed.
- APK: version 1.0.6, version code 7, three Android ABIs, existing staging signer, camera/internet/biometric permissions, staging host and v2 signup constants verified.
- Dependency audit is **not clean**: the existing medium Filament advisory above remains. No security advisory was suppressed.

Implementation entry points: mobile `auth_screens.dart`, `kyc_screen.dart`, `vehicle_screens.dart`, `discovery_screen.dart`; server `RegisterRequest`, `RegistrationController`, `DriverVehicles`, `PublicStationSearch`; migration `2026_10_08_000001_add_driver_onboarding.php`. Tests are in `feature_review_test.dart`, updated mobile journeys, `MobileRegistrationTest`, `DriverVehiclesTest`, and `MasterDataAssetFoundationTest`.
