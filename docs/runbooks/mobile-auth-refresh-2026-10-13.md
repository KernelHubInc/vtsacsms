# Mobile login and password recovery release

The driver app login, PIN unlock and forgot-password screens now use the Power Solutions navy and teal design with the existing logo, a dark hero and a rounded white form. The EVRO reference informs the composition; email authentication remains the supported sign-in method. No dependency or Google authentication integration was added.

## Changed behavior

- `AuthEntryScaffold` provides the shared responsive presentation. Forms scroll when the keyboard or enlarged text needs more room.
- Existing email sign-in, registration navigation, PIN attempts, biometric unlock and password fallback remain in place.
- Forgot password now observes loading and failure changes. A failed request shows its error, and an in-flight request cannot be submitted twice through the controller.
- Laravel's reset notification previously failed because the named `password.reset` browser route did not exist. `GET /reset-password/{token}` now opens a branded form, and `POST /reset-password` uses the existing password broker and reset workflow.
- The browser form uses CSRF protection and the existing recovery rate limiter. Reset pages are private and non-cacheable, with no referrer leakage. Invalid, expired or reused tokens are rejected. Existing mobile-token and session revocation is preserved.

## Driver recovery flow

1. Select **Forgot password?** on the sign-in screen and enter the account email.
2. Open the reset link in the email. Staging currently configures a 60-minute expiry.
3. Enter and confirm the new password in the browser form.
4. Return to the app and sign in. Configure quick unlock again if desired.

The response to requesting a link does not reveal whether an email has an account. If an email does not arrive, check junk mail and SMTP delivery logs before requesting another link.

## Verification

- Full mobile suite: 178 tests passed with `flutter test --concurrency=1 --timeout=2m`. The initial parallel run timed out in two CPU-intensive PIN-hashing tests; the serial run passed without changing those tests or hashing behavior.
- Login and PIN screenshots reviewed at 390 logical pixels with normal text and 320 pixels with 1.6 text scaling. Navigation and recovery actions tested at both sizes.
- Full platform suite: 222 tests, 1,832 assertions passed. Recovery regression tests cover real notification URL construction, successful reset, token reuse, expiry, unknown email and malformed query input. Existing tests cover session revocation.
- PHP static analysis and `dart analyze` passed with no issues; scoped Pint and Dart formatting passed. Web production assets built successfully. The Flutter analyze launcher stalled before analysis, so the same SDK's Dart analyzer was run directly.
- Staging reset page, CSS and logo returned HTTP 200. A reset POST without CSRF returned 419. Readiness returned 200.
- SMTP transport and sender are configured. Real inbox delivery has not been verified; tests use fake notifications. Browser automation was unavailable, so the live browser page was checked through its HTTP response and assets, not a rendered browser screenshot.

## Staging backend deployment

Deployed image: `vtsa-prepaid-platform:20261013-auth`, based on the previously running `vtsa-prepaid-platform:20261012`. Only the password controller, web routes, reset Blade view and compiled public assets were included. The deployed original controller and route file hashes matched repository HEAD before patching.

The `platform` image entry in `/etc/vtsa-csms/compose.staging-prepaid-platform.json` points to the new image. The original private override is preserved at `/etc/vtsa-csms/compose.staging-prepaid-platform.json.before-auth-20261013`. Release build context is under `/opt/vtsa-releases/auth-20261013`.

No database migration, payment change or OCPP gateway restart was required. Workers retain their existing image. Future image rebuilds must include the committed authentication changes and rebuilt Vite assets; pulling Git alone does not replace the pinned runtime image.

Rollback, if required on the staging VPS:

```bash
cd /opt/vtsa-csms
sudo cp -p /etc/vtsa-csms/compose.staging-prepaid-platform.json.before-auth-20261013 /etc/vtsa-csms/compose.staging-prepaid-platform.json
sudo docker compose -p vtsa-csms-staging --env-file .env.staging \
  -f infra/compose.yaml \
  -f /etc/vtsa-csms/compose.staging-wallet-runtime.json \
  -f /etc/vtsa-csms/compose.staging-prepaid-platform.json \
  up -d --no-deps --no-build --pull never platform
```

Rollback removes the newly added reset page but leaves database contents unchanged.

## Android build

Build from `apps/mobile` with the existing staging configuration and signing setup:

```powershell
flutter build apk --release --dart-define-from-file=dart_defines.staging-demo.json --build-name=1.0.16 --build-number=17
```

The staging APK retains the existing Android debug signing certificate, allowing an in-place update of previous staging builds. It is not a Play Store release. The existing `mobile_scanner` Kotlin Gradle Plugin warning is a future Flutter compatibility concern; no dependency upgrade is included in this authentication change.

Verified APK: package `com.vtsa.vtsa_mobile`, version `1.0.16`, build `17`, minimum Android API 24, target API 36. Size: 79,496,802 bytes.

- APK SHA-256: `b52cae5f335667ecebe5563b00c71719308c4ae1bb0fc04b5ee835905611c28f`
- Signing certificate SHA-256: `9a4ba09af7e23245ae75fea6127e6a644eeb304d69e84aa966f185260e968b3c`

Published download: [Power Solutions staging 1.0.16](https://staging.evcspowersolutions.com/demo/Power-Solutions-Staging-Lumina-1.0.16.apk). The server file checksum matches the local APK. HTTPS returned 200 with `application/vnd.android.package-archive` and the expected 79,496,802-byte size. Previous APK versions remain available.
