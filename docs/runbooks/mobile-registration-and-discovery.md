# Driver registration and charging screens

## Release scope

The mobile and `/mobile/` browser application share the revised Flutter screens. Power Solutions navy and turquoise remain the brand colors. Reference screenshots inform the layout only; no third-party logos, promotions, prices, vehicle photos or charger identifiers are copied.

- Registration outlines the specific invalid fields and scrolls to the first one. Field edits clear their previous highlight. Invalid fields also have screen-reader hints. Network and configuration failures appear separately; they never mark valid fields as incorrect. A duplicate email receives generic recovery guidance without identifying whether the account exists.
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

## Google sign-in: deferred configuration

Google sign-in is not enabled or advertised in this release. OAuth client IDs are not configured, and the user requested it last. No dummy sign-in button or credential is included.

For the next stage:

1. In the chosen Google Cloud project, configure Google Auth Platform branding, audience and the app's support/privacy details. Add test users while the app is in testing.
2. Create a Web application OAuth client. Register the actual browser origins separately for staging and production. A browser origin has no `/mobile/` path. Keep local development origins separate.
3. Create an Android OAuth client for the package ID from the build and the SHA-1 of the certificate that signs the APK. Test-signed APK and future Play App Signing certificates may require different Android clients.
4. Supply the public Web and Android client IDs for implementation. Do not put a client secret or service-account private key in Flutter, Git or chat. The Firebase service-account key restriction encountered earlier is separate from creating OAuth clients.
5. Integrate the official Flutter Google sign-in plugin, including its Google-rendered web button. The backend must verify signature, issuer, audience, expiry and verified-email claims; use Google's stable subject identifier for account links. Existing password accounts need an authenticated linking flow, not automatic takeover by a matching email.
6. New Google users must still complete required driver details, date-of-birth and vehicle/plate onboarding. Tenant restrictions, suspended accounts, driver permissions and KYC rules must remain enforced.
7. Test Android and web cancellation, denied consent, wrong audience, expired tokens, account linking, new-account onboarding and tenant isolation before enabling the button.

Official integration references: [Flutter Google sign-in](https://pub.dev/packages/google_sign_in), [Android configuration](https://pub.dev/packages/google_sign_in_android), and [Google backend token verification](https://developers.google.com/identity/sign-in/android/backend-auth).

## Verification boundaries

Automated tests use fake mobile repositories and an in-memory backend database. They do not create live accounts, charge a vehicle, debit a wallet or send email. Screen previews are fixture data. Camera permission and QR detection must also be checked on a physical phone and a supported HTTPS browser after deployment.
