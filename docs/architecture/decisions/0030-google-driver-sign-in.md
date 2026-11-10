# ADR 0030: Google identity for driver sign-in

Status: Implemented candidate, Android staging acceptance pending.

Date: 2026-11-09. Owner: Identity.

## Context

The operator supplied staging Web/server and Android OAuth client IDs for Google login and signup in the native Android app. Existing customer identity, enrollment, roles, wallet ownership and KYC remain owned by their existing contexts.

## Decision

Use the official Flutter `google_sign_in` Android integration to obtain a Google ID token with the configured server client ID as its audience. Laravel verifies RS256 signatures against Google's fixed HTTPS JWKS endpoint using `firebase/php-jwt`, with cached public keys, bounded refresh on key rotation, issuer/audience/authorized-party/expiry/issued-at/subject/email checks and rate-limited endpoints. No service-account credential or OAuth client secret is required for this ID-token flow.

Identity stores a nullable, globally unique `users.google_subject`. Users are global identities; tenant membership and authorization are still independently checked for every issued mobile token. The provider subject is hidden from serialization and retained only while its identity exists, with the same access and erasure controls as the user record. Google ID/access tokens and Google profile pictures are not persisted. A pending signup/link token lives only in the app controller's memory and is cleared after success, cancellation or leaving registration. Login failure audit records exclude tokens, emails and provider subjects.

Existing linked users are identified by Google's stable subject, including after a Google email change. No automatic account merge occurs by email. A matching existing account must present its current password together with the verified Google token before linking. The link and session issue are transactional, with a unique constraint preventing concurrent provider reassignment. Active tenant/membership, expiry, disabled identity and MFA requirements remain enforced. Google sign-in does not enroll password-only quick unlock.

New users complete names, adulthood/date-of-birth, vehicle plate or plate-pending, and explicit terms/privacy acceptance. Identity invokes Organizations' consumer enrollment and Assets' vehicle contract with the configured signup scope. User, provider link, membership, vehicle, audit and mobile token commit together. New Gmail identities and Google Workspace identities with verified email and a hosted domain may satisfy email verification. Other Google-account email addresses still require local email verification. Linking an existing account does not change its existing email or verification status. KYC is enforced by the existing protected operations.

The API adds `POST /api/v1/auth/google` and `POST /api/v2/auth/google/register`. A verified, unregistered identity receives a registration-required response without an application token. Success uses the existing mobile token/session format. Existing password login and registration reuse the shared Identity workflows and preserve their contracts. Google login/signup is configured per environment and tenant and defaults to disabled.

## Operations and rollback

Apply the additive `2026_11_09_000001_add_google_subject_to_users` migration once before enabling the feature or deploying a Google-enabled Android build. Both API nodes must have the same environment-specific Google configuration. Disable `GOOGLE_SIGN_IN_ENABLED` to stop new Google authentication while retaining password/recovery and existing session behavior. Keep the provider-link column when rolling back application code after users have linked accounts. Removing it would destroy identity-link data.

Native Android is the first enabled client. The `/mobile/` browser build hides this option until its Google-rendered browser button and approved JavaScript origins are configured. Production requires its own approved OAuth clients and signing fingerprints before activation.

## Verification and acceptance

Automated tests use generated RSA keys and mocked Google key responses. They exercise issuer/audience/party/signature/algorithm/expiry validation, key rotation and outage behavior, wrong tenant, membership expiry/suspension, disabled users, MFA, password linking, signup validation and consumer scope, third-party email verification, cancellation, concurrent UI taps and registration navigation. Full local quality checks accompany the change.

A physical Android acceptance test must use the actual test APK signing certificate registered in Google Cloud. Confirm account chooser cancellation, new signup, existing password linking, repeat login, expired proof recovery, logout and email verification. Automated fixtures cannot prove the supplied OAuth clients were correctly configured in Google Cloud. Never log a live Google token to diagnose a configuration failure.

References: [Android Flutter configuration](https://pub.dev/packages/google_sign_in_android), [Google token verification](https://developers.google.com/identity/sign-in/android/backend-auth), [Google button branding](https://developers.google.com/identity/branding-guidelines).
