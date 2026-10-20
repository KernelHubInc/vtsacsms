# ADR-0020: Device-local mobile PIN and biometric unlock

- **Status:** Accepted; physical-device release verification required
- **Date:** 2026-10-06
- **Owners:** Identity and mobile engineering
- **Requirement:** MOB-AUTH-002

## Context

Drivers need a separate app PIN and fingerprint option while charger integration is in progress. Identity remains authoritative under ADR-0009; local authentication cannot create, refresh or extend a Sanctum session. OCPP configuration is unrelated to this feature.

## Decision

After password sign-in and email verification, Android/iOS drivers may enable a six-digit app PIN under Account > PIN & fingerprint. They may additionally confirm an OS-enrolled fingerprint or Face ID using Flutter's `local_auth` plugin with biometrics only. The app does not enroll or receive biometric templates, and does not use the phone's screen-lock PIN as its app PIN.

The PIN verifier uses the maintained `cryptography` PBKDF2-HMAC-SHA256 implementation, 600,000 iterations, a random 32-byte salt, and a 256-bit output. Derivation runs in an isolate to avoid blocking the UI. Store the verifier, salt, attempt counter and tenant/device binding in platform secure storage, never preferences. Never retain the raw PIN or account password. Android backup is disabled. These local records are retained only for the saved session and removed on sign-out, password fallback, expiry/revocation detection or lockout.

iOS writes use the unlocked, this-device-only keychain accessibility class. Background API requests are rejected locally without triggering server session expiry handling. Charging polling/realtime application pauses while locked; unlocking refreshes the authoritative session. Password fallback clears in-memory account projections, including charging history, through their existing mobile controllers; no server-owned Charging data is changed.

Persist each PIN attempt before verification. Five incorrect attempts clear the saved credentials and setup; restart does not reset the counter. Storage failure denies the attempt. Corrupt configuration clears credentials rather than restoring an unprotected session. Native biometric cancellation/lockout leaves app PIN and password recovery available. Concurrent unlock submissions are serialized by the auth controller.

Cold start and backgrounding lock an enrolled session. The shared token-store adapter withholds credentials from API requests until local proof succeeds; retries strip retained authentication headers. The router denies all protected destinations while locked. Local proof must then pass the existing server identity check; offline, timeout and server errors stay locked, while rejected/expired/tenant-mismatched/device-mismatched credentials require password sign-in. Email verification continues to use its existing guard. Late authentication results cannot reopen a session after a lock generation change.

Changing/removing setup requires signing out and signing in with the password again. Password fallback from a locked screen clears local credentials; it cannot revoke the server token without first releasing that token. The token remains subject to existing server expiry and device revocation controls. Existing server authentication and revocation audit paths are unchanged; local unlock is not a new server login or privileged MFA claim.

## Consequences and limits

- No database migration, public API, cross-context writes or OCPP change.
- This is an application access gate over encrypted platform token storage, not a biometric-bound cryptographic key or a passwordless server authenticator. It does not defend against a rooted device or a modified app bypassing local checks. Hardware-bound token encryption/attestation would require a separate decision.
- Any biometric enrolled on the phone can unlock the app when enabled. Removing enrollment leaves PIN/password recovery.
- Browser and desktop builds retain password authentication and do not offer setup.
- A new account/session requires new setup. PINs are not synchronized or transmitted to the server. Platform keychain retention across reinstall follows the OS; retained enrolled sessions still require local proof and server validation.
- Native prompts control biometric retries. Quick unlock never satisfies privileged server MFA.

## Alternatives

The user chose a separate app PIN instead of the phone's screen-lock PIN. A server PIN endpoint was rejected for this scope because a short PIN must not become a reusable remote account password. Storing/replaying the account password was rejected.

## Verification and follow-up

Automated tests cover configuration validation, persistence, retry exhaustion, corrupt/storage failures, tenant/device binding, expiry, server denial/offline behavior, biometric cancellation, route guards, background lock and password fallback. Before release, verify real Android/iOS biometric prompts, no enrollment, biometric lockout, PIN-only use, app-switcher privacy, background/resume during prompts/network calls, restart, sign-out and revocation. Measure PIN derivation on a low-end supported phone. iOS requires macOS/Xcode for its build and device verification.

References: [Flutter local_auth](https://pub.dev/packages/local_auth), [Android setup](https://pub.dev/packages/local_auth_android), [cryptography](https://pub.dev/packages/cryptography).

### Local verification, 2026-10-06

- Full Flutter test suite: 132 passing tests, including quick-unlock storage/controller, navigation, API retry and Charging lock regressions.
- Dart formatting: 111 files checked with no changes.
- Flutter static analysis: no issues. `git diff --check`: clean.
- Android debug APK built successfully using the repository's example defines. Existing `mobile_scanner` Kotlin migration warning remains; the build succeeds.
- Reviewed lockfile additions: `cryptography` 2.9.0, `local_auth` 3.0.2 and its four platform packages. OSV querybatch returned no known advisories for these six packages or existing `flutter_secure_storage` 10.3.1. This is a point-in-time package advisory check, not a device security assessment.
- No Android device or emulator was available. Native integration journeys and physical biometric behavior remain unverified. iOS build/device verification requires macOS/Xcode.
