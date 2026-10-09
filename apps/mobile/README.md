# Power Solutions Consumer Mobile App

Flutter 3.44.6 consumer application covering authentication, vehicle compatibility, bounded charger discovery, QR/manual charging start, live session recovery, remote stop, payment-finalization status, history, documents, refund-review entry, and issue reporting.

## Architecture

Features use `presentation`, `application`, `domain`, and `data` directories. Presentation code depends on application controllers and domain values. API, secure storage, shared preferences, map providers, connectivity, navigation handoff, analytics consent, crash reporting, app updates, and push registration are adapters behind explicit interfaces.

The public discovery repository sends bounded server queries to `/api/v1/public/stations`; it never downloads the complete network. Charging state is a replaceable projection of the server: command acceptance is not treated as physical start or stop evidence. Local recovery stores only the account ID, session ULID, and save time. Canonical energy remains integer watt-hours, power integer watts, durations integer seconds, money integer minor units plus currency, and timestamps UTC.

## PIN and fingerprint unlock

After password sign-in and email verification, open **Account > PIN & fingerprint** to create a separate six-digit app PIN and optionally confirm fingerprint/Face ID. The native app locks on restart/background. Five incorrect PINs, sign-out or password fallback remove setup. Expired/revoked sessions require password sign-in; offline quick unlock stays locked until the server can validate the session. Browser/desktop builds do not offer setup. No API or database migration is required.

PIN verifiers and attempt limits use secure storage; biometric data remains with the OS. See [ADR-0020](../../docs/architecture/decisions/0020-mobile-pin-and-biometric-unlock.md) for security boundaries and required physical Android/iOS verification. Changing the PIN requires signing out and signing in with the password again.

## Configure

Copy `dart_defines.example.json` to an ignored local file such as `dart_defines.local.json` and set:

- `API_BASE_URL`: Chrome uses `http://localhost:8000`; Android emulator normally uses `http://10.0.2.2:8000`; iOS simulator uses `http://127.0.0.1:8000`.
- `APP_ENVIRONMENT`: `local`, `staging`, or `production`. Non-local API URLs must use HTTPS.
- `DEFAULT_TENANT_ID`: tenant ULID required by the current Sanctum login contract.
- `MAP_PROVIDER`: `openstreetmap` (default) or `google`.
- `GOOGLE_MAPS_READY`: non-secret build-time readiness flag. Keep false unless the native/web Google SDK key is configured.
- `GOOGLE_MAPS_API_KEY`: restricted native build key when selecting Google. It is not read from the server configuration response.

OpenStreetMap needs no app key. The production tile service must still permit the expected traffic and identify the application as documented in `docs/maps/OPENSTREETMAP-SETUP.md`.

The native Google Maps SDK needs its key at build time. Do not commit it:

```powershell
$env:GOOGLE_MAPS_API_KEY = '<restricted-local-key>'
flutter.bat run --dart-define-from-file=dart_defines.local.json
```

For iOS, define the user build setting `GOOGLE_MAPS_API_KEY` in the local Xcode scheme or an untracked xcconfig. Production keys must use platform/application restrictions and API restrictions.

## Commands

```powershell
flutter.bat pub get
flutter.bat run -d chrome --web-port=7357 --dart-define-from-file=dart_defines.web.example.json
dart.bat format --output=none --set-exit-if-changed .
flutter.bat analyze
flutter.bat test
flutter.bat devices
flutter.bat test integration_test -d 'DEVICE_ID'
flutter.bat build apk --debug --dart-define-from-file=dart_defines.example.json
```

Launch the design-system catalog with `--dart-define=SHOW_DESIGN_CATALOG=true`.
Integration journeys require an Android/iOS emulator or physical device. CI provisions an Android emulator for this step.

## Contract dependencies

The checked-in platform contract supports login, password reset, email verification, current identity, logout, and bounded public station search. These mobile ports are intentionally pending an approved server contract:

- self-service consumer registration;
- refresh-token rotation (the client performs single-flight refresh only when a provider returns a refresh token);
- customer profile mutation;
- server-synchronized vehicles and favorites;
- richer public operating-hours, amenities, and connector-instance availability detail.

Phase 10 adds Flutter ports for a customer-owned mobile charging contract. The current Laravel routes expose workforce-scoped Charging operations and do **not** yet implement these customer endpoints:

- `POST /api/v1/mobile/charging/prepare`
- `GET /api/v1/mobile/charging-sessions/active`
- customer-owned list/detail/start/stop/cancel routes under `/api/v1/mobile/charging-sessions`
- refund review at `/api/v1/mobile/charging-sessions/{sessionId}/refund-requests`
- issue creation at `/api/v1/mobile/support/issues`

Do not route the app to operator endpoints or weaken their policies. The mobile endpoints require a separate Laravel phase with customer ownership, tenant derivation, versioned tariff/preauthorization disclosure, tokenized payment references, receipt/invoice authorization, cursor pagination, idempotency, and matching OpenAPI. See [the mobile charging contract boundary](../../docs/architecture/mobile-charging-contract.md).

Vehicles and favorites use account-scoped, non-sensitive local preferences in this release. Access tokens use platform secure storage. Raw credentials, card data, and map-provider credentials are never persisted by the app.

Camera access is requested only after the in-app rationale. Android declares the camera as optional so manual code entry remains usable on camera-less devices. The selected push provider remains open; the app consumes provider-neutral start, stop, fault, payment, and completion hints and always refreshes authoritative server state.

## Feature review testing build

Build 1.0.6 (7) uses `/api/v2/auth/register` and authenticated `/api/v1/vehicles`. Deploy the matching backend migration before testing signup/vehicle sync. Existing local vehicles remain visible until edited and saved to the server. See [acceptance and deployment notes](../../docs/runbooks/mobile-feature-review-testing.md). AUB top-ups, pre-submit OCR autofill, and queue/booking workflows remain unavailable.

## Prepaid wallet testing build

Build 1.0.7 (8) adds a Wallet screen backed by `/api/v1/wallet`: balances, activity, top-up requests and server-confirmed status. Its staging build enables simulated payments and keeps real payments disabled. Deploy the matching backend and wallet migration before testing. Simulated funds cannot pay for live charging; no payable QR is generated in simulation. See [prepaid wallet staging](../../docs/runbooks/prepaid-wallet-staging.md) for configuration and confirmation commands.
