# Mobile Development Runbook

## Scope

The Phase 10 app covers onboarding, identity, local vehicle compatibility, bounded discovery, QR/manual charging start, live status, stop orchestration, recovery, payment-finalization status, activity history, authorized documents, refund-review entry, and issue reporting. The Flutter ports are complete, but their customer-owned Laravel endpoints remain a release blocker documented in [the mobile charging contract boundary](../architecture/mobile-charging-contract.md). The proposed complete flow through the OCPP simulator, payment, CDR, and billing is documented in [the mobile QR charging transaction flow](../architecture/mobile-qr-charging-transaction-flow.md).

## Local startup

1. Start the Laravel platform and verify `GET /health/ready`.
2. Copy `apps/mobile/dart_defines.example.json` to an ignored local file.
3. Set a real tenant ULID for `DEFAULT_TENANT_ID` if testing Sanctum login.
4. Run `flutter pub get`, then `flutter run --dart-define-from-file=dart_defines.local.json` from `apps/mobile`.

Android emulators reach the host at `10.0.2.2`. iOS simulators normally use `127.0.0.1`. Physical devices need a reachable development HTTPS endpoint; do not weaken platform transport security for a shared environment.

## Google Maps

Discovery always has a synchronized accessible list. If `GOOGLE_MAPS_API_KEY` is empty, the app disables the interactive map and retains full list discovery.

For Android, provide the key through an environment variable or local Gradle property and repeat the same value as a Dart define. For iOS, use an untracked user build setting or xcconfig and the Dart define. Restrict keys by Android package/signing certificate or iOS bundle identifier and enable only the Maps SDK required for that platform. Configure budget alerts and quotas in the owning cloud account; never place unrestricted keys in source or CI logs.

## Authentication behavior

Sanctum access tokens are stored only in secure platform storage. Logout attempts server revocation and always removes the local token. The interceptor serializes concurrent refresh attempts and retries the failed request once only if an adapter returned a refresh token. The current Laravel contract does not issue refresh tokens, so normal expiry returns the driver to sign-in without inventing a renewal credential.

Station deep links use `vtsa:///stations/{station-ulid}`. The app rejects unknown schemes and malformed identifiers. HTTPS universal/app links remain disabled until an owned production domain and platform association files are approved.

## Discovery behavior

- Google Maps loads after `onCameraIdle` and debounces viewport changes for 350 ms.
- Requests contain `west`, `south`, `east`, and `north`, plus approved server filters.
- A request generation prevents a late response from replacing a newer viewport.
- Stale data always displays as stale or unknown, even when the last connector state was available.
- Search narrows only the already bounded result set; it is not a hidden global-network download.
- Offline state preserves the last loaded data with an explicit freshness warning.

## Charging and recovery behavior

- The camera is not mounted until the driver accepts the rationale. Denial or camera failure leaves manual code entry available.
- Only printed identifiers or `vtsa:///charge/{code}` links are accepted. The server resolves every code to an authorized connector; the app does not decode asset identity from the text.
- Preparation retrieves connector readiness, vehicle compatibility context, an expiring tariff/preauthorization disclosure, and tokenized payment-method references.
- Start and stop use stable idempotency keys while a request is in flight. Repeated taps do not create additional commands.
- A remote-start acknowledgment stays `start pending` until the server reports `charging` or a canonical suspended state. A remote-stop acknowledgment stays `stop pending` until transaction evidence advances the session.
- WebSocket/realtime and push messages are hints. Aggregate versions reject regressive updates; polling retrieves authoritative state whenever realtime is unavailable.
- Offline mode preserves the last confirmed values and disables start/stop. Reconnection, app resume, sign-in on another device, and a matching push notification retrieve the current server session.
- The only persisted recovery record contains `owner_id`, `session_id`, and `saved_at`. Tariffs, payment references, metering, receipts, and session projections are not cached as authority.
- A completed session with pending/unknown provider state remains `payment processing`. The app does not expose a duplicate payment action.
- Receipt and invoice links must be short-lived, customer-authorized server URLs. Never log or persist them beyond the active projection.

To exercise scanning on a real device, verify allow, deny, deny-permanently, camera-unavailable, invalid QR, repeated QR, background/foreground, and manual-entry behavior. To exercise recovery, terminate the app during each canonical session phase and compare the recovered screen with the platform record.

## Verification

Run from `apps/mobile`:

```powershell
dart format --output=none --set-exit-if-changed .
flutter analyze
flutter test
flutter devices
flutter test integration_test -d 'DEVICE_ID'
flutter build apk --debug --dart-define-from-file=dart_defines.example.json
```

Manually verify large text, TalkBack/VoiceOver labels, light/dark themes, list-only discovery, denied location and camera access, offline recovery, stale station status, app termination during charging, delayed payment, notification handoff, deep links, and directions handoff on real devices before release.

The integration command requires a configured Android/iOS emulator or physical device. The pull-request workflow provisions an Android API 35 emulator; a desktop or browser target is not a substitute for the camera and lifecycle journey.

## Open decisions

Customer charging endpoint ownership, consumer tenant derivation, registration contract, refresh-token policy, server persistence for vehicles/favorites, production app-link domain, push provider, analytics/crash vendors, supported locales, mobile-number verification UX, app-update enforcement policy, production signing, and store identifiers require explicit decisions. No production credential or provider-specific behavior is assumed here.

Flutter 3.44 currently warns that `mobile_scanner` 7.4.0 still applies the Kotlin Gradle Plugin instead of using Flutter's Built-in Kotlin path. The debug APK builds successfully, but dependency upgrades must track the upstream migration before Flutter turns this warning into an error.
