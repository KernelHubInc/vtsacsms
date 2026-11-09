# Driver registration and charging screens

## Release scope

The mobile and `/mobile/` browser application share the revised Flutter screens. Power Solutions navy and turquoise remain the brand colors. Reference screenshots inform the layout only; no third-party logos, promotions, prices, vehicle photos or charger identifiers are copied.

- Registration shows a red outline and an actionable inline message for each invalid field. Untouched fields stay neutral; leaving a field or pressing Continue validates it, and subsequent edits revalidate until corrected. Continue scrolls to and focuses the first invalid text input. A live password checklist shares the Unicode-aware eight-character/mixed-case/number/symbol rules with local validation; changing the password rechecks a previously validated confirmation. Server field rejections use safe recovery copy rather than exposing arbitrary server messages or claiming a valid email has incorrect syntax. Error text wraps on narrow screens, uses contrasting colors in light/dark mode, and includes screen-reader feedback. Network and configuration failures appear separately; they never mark valid fields as incorrect. A duplicate email receives generic recovery guidance without identifying whether the account exists.
- Registration follows the Power Solutions frame `10:1140` in Figma file `YUMRyiSsvqTNVfUbd7ocpS`: Account security first, followed by Personal information and Vehicle details. Decorative eyebrow labels are omitted; section icons reuse the exported Figma shield, person and car assets. It retains the existing company logo and native device chrome. Labels stay above inputs, password and confirmation visibility can be toggled together, confirmation must match exactly before submission and is sent to the existing server confirmation check, plate-pending remains optional, and policy links open the current environment's public terms/privacy pages. The requirements panel uses the approved eight-character password policy rather than the older twelve-character design copy. The form stays scrollable with the keyboard open, adapts to larger text, and has a bounded desktop width. The shared input/date components keep their previous appearance outside registration.
- Registration is a three-step flow with progress: Account security → Personal information → Vehicle details. Continue validates only the current step; Back (including the phone Back action) keeps entered values and resets password visibility. Hidden steps remain mounted for autofill but cannot receive focus. Create account appears only on the last step and rechecks the whole form before the existing registration request. Server field errors reopen the relevant step; connection failures keep the final step available for retry. Step changes, sign-in navigation and repeat submission are blocked while the request is pending. Form state stays in memory only and is cleared on leaving/reloading registration; passwords are never saved as a draft.
- Explore shows the default vehicle above the map. Tap the card to manage vehicles and change the default. The existing vehicle compatibility filter remains separate from the current charging vehicle. No photograph or drivetrain type is invented when those fields are absent.
- Station details show location, power, availability, hours and connector cards. Cards remain unselectable when offline, stale, busy or unconfirmed. Preparing connectors retain the existing review flow. Compatibility remains advisory. Live pricing and the wallet reservation are obtained at review, not inferred from connector power. Directions and Continue stay below the scrolling content.
- QR scanning opens a full camera view after the existing permission explanation. Manual charge point entry remains available. Camera errors, offline restrictions, duplicate-scan suppression, review and explicit start confirmation remain in place. The interface scrolls on short/landscape screens and supports enlarged text.

## Enabling public driver registration

Previously `/api/v2/auth/register` accepted only the demo tenant in non-production environments. Production now supports an explicitly configured signup destination. Defaults remain closed; deployment must supply real identifiers.

Set these values in the application's environment:

```dotenv
DRIVER_REGISTRATION_ENABLED=true
DRIVER_REGISTRATION_TENANT_ID=<existing active tenant ULID>
DRIVER_REGISTRATION_ORGANIZATION_ID=<existing consumer fleet organization ULID>
```

The organization must be active, have type `fleet`, and belong to the configured tenant. Use the dedicated consumer-driver organization, never a customer's shared business/fleet account. That tenant must already have the least-privilege `consumer-driver` role. Enrollment cannot accept a role or organization supplied by the client. The configured tenant ID must match the mobile build's tenant ID.

Configure the same production values on App 01 and App 02, recreate the affected application containers through the release script, and rebuild Laravel's configuration cache. Production queue jobs remain on App 01 only. Staging uses its own tenant/organization. When explicit signup is disabled, the existing guarded demo signup remains available only in non-production demo environments.

There is no database migration for this change. Organizations owns membership and consumer-role assignment through `ConsumerEnrollment`; Tenancy exposes the active-tenant query. Identity registration, Assets vehicle creation and the registration audit commit together in the existing transaction. Verification email is sent after commit. SMTP failure does not roll back a successfully created account or bypass email verification.

Before enabling signup, verify outbound mail, the organization and consumer role. Test a new email, email verification, sign-in, default vehicle creation, invalid fields, a duplicate email, a different tenant and disabled registration. A refused signup returns `registration_unavailable` with no field errors. Do not enable `FEATURE_DEMO_MODE` on production to work around registration.

## Google sign-in for the staging Android app

Google login and signup use the existing Laravel identity and mobile token system. The official Android account chooser is enabled by `GOOGLE_SIGN_IN_ENABLED` plus `GOOGLE_SIGN_IN_SERVER_CLIENT_ID` in the Flutter build. The shared browser build keeps this option hidden until a Google-rendered web button and approved origins are configured.

Before enabling the backend, apply `2026_11_09_000001_add_google_subject_to_users`. Set the following on the staging API alongside its existing driver-registration configuration, then rebuild the configuration cache and redeploy the affected API containers through the normal release process:

```dotenv
GOOGLE_SIGN_IN_ENABLED=true
GOOGLE_SIGN_IN_TENANT_ID=01J0000000VTSADEMA00000000
GOOGLE_SIGN_IN_SERVER_CLIENT_ID=516894406407-cqnbbigrkrq95fq0e0b6ttevub18gn10.apps.googleusercontent.com
GOOGLE_SIGN_IN_ANDROID_CLIENT_ID=516894406407-lu0cf4am14ubdiv1q8mk8mgu45vl43c3.apps.googleusercontent.com
```

These are public staging client identifiers supplied by the operator. Do not use them for production. Both clients must belong to the selected staging Google Cloud project. The Android client must register package `com.vtsa.vtsa_mobile` and the SHA-1 of the certificate signing the installed APK. The existing test-signed APK certificate has SHA-1 `CD:5D:D5:A3:E4:BC:C7:5D:E5:89:BD:E5:98:B3:5A:9E:BF:EA:13:65`. A future Play App Signing certificate needs its own registration. The Web client acts as the Android token's server audience and does not require an origin or redirect URI for this native flow. No client secret or service-account JSON is needed. Outbound HTTPS to Google's fixed JWKS endpoint must work.

In Google Auth Platform, complete branding, external audience, support/privacy details and basic `openid`, `email`, `profile` scopes. Add test users while testing as required by the console. App name/logo display may require Google brand verification. Google Cloud configuration cannot be confirmed by local automated fixtures.

Driver instructions:

1. Tap **Continue with Google** on Sign in or Create account and select a Google account. Dismissing the chooser leaves the account signed out.
2. A linked account signs in through the normal tenant-scoped mobile session.
3. A matching existing password account asks for the current Power Solutions password once, then links the verified Google identity. Use Forgot your password if necessary. Linking never moves vehicles, wallet funds or memberships into a new account.
4. A new account opens Personal information with Google names prefilled for correction, then Vehicle details. Complete date of birth and plate/plate-pending, explicitly accept the linked terms/privacy policy, and tap Create account. A separate password is optional through later password recovery.
5. Gmail and authoritative Workspace identities can complete email verification through Google. Google accounts using a third-party email address must still follow the local email-verification screen. Existing linked accounts retain their existing verification status. KYC remains a separate requirement for protected operations.
6. If the Google proof expires while completing the form, choose **Choose another Google account** to authenticate again. Leaving registration clears the in-memory Google proof.

Google is an additional sign-in method. Existing email/password and recovery keep their normal contracts. New provider links and sign-ins appear in the existing audit log. `POST /api/v1/auth/google` verifies the token before returning a session or a registration-required response; `POST /api/v2/auth/google/register` validates the remaining profile and creates the account atomically. Tokens, client secrets and provider subjects must never be added to support screenshots or logs. See [ADR 0030](../architecture/decisions/0030-google-driver-sign-in.md).

Deployment acceptance requires an actual signed Android APK with the correct Google Cloud fingerprint. Verify cancellation, new-user onboarding, password-confirmed linking, repeated login/logout, disabled accounts and failed/expired Google proof. Roll back activation with `GOOGLE_SIGN_IN_ENABLED=false` and retain provider-link data. Production activation and `/mobile/` browser integration are subsequent rollouts.

Official references: [Flutter Google sign-in](https://pub.dev/packages/google_sign_in), [Android configuration](https://pub.dev/packages/google_sign_in_android), [Google backend token verification](https://developers.google.com/identity/sign-in/android/backend-auth), and [button artwork](https://developers.google.com/identity/branding-guidelines). The Google G image in `apps/mobile/assets/interface/google-g.png` is the official asset downloaded from Google's branding guide.

## Verification boundaries

Automated tests use fake mobile repositories and an in-memory backend database. They do not create live accounts, charge a vehicle, debit a wallet or send email. Screen previews are fixture data. Camera permission and QR detection must also be checked on a physical phone and a supported HTTPS browser after deployment.

## Registration and KYC usability update

The shared Android and browser registration screens retain entered values on errors and keep network/configuration failures separate from field validation. Server validation remains authoritative. No passwords are persisted and no authentication or KYC assurance policy changes.

Selfie, document and live-check cameras use a full-width preview on phones (bounded to 720 logical pixels on wider screens), a larger face oval, and a 320–640 pixel adaptive camera area. The guide remains inside the actual camera preview so orientation and letterboxing do not misalign it with the photo. Preview sizing preserves the image aspect ratio; it does not stretch or crop evidence. Photo tips are expandable, primary capture/retry/cancel controls stay in the bottom safe area, and live movement instructions include progress beside the camera. Small/landscape screens and enlarged text can scroll rather than clipping controls. The live check still waits for explicit readiness; stopping, backgrounding or leaving disposes the camera. Captures retain the existing review/retake flow and temporary-file cleanup.

Automated camera fixtures verify layout, permission denial, retry, readiness, server-driven feedback and lifecycle cleanup. They cannot establish real camera orientation, face detection or biometric accuracy. After release, check a physical phone and an HTTPS browser with camera permissions, bright/backlit scenes, portrait/landscape rotation and large text. The outline is guidance only; it does not approve identity or relax the server's checks.

Design reference: [W3C error identification](https://www.w3.org/WAI/WCAG22/Understanding/error-identification) — describe errors in text near their fields and help users reach them; color alone is insufficient.
