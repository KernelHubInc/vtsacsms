# ADR 0040: Google sign-in across driver clients

Status: Implemented candidate; live provider and signed-device acceptance required.

Date: 2026-11-23. Owner: Identity.

## Context

ADR 0030 initially activated native Android staging only. Production build definitions omitted Google configuration, while the client bootstrap excluded iOS and browser builds. The owner supplied production Web, Android and iOS OAuth client IDs plus the Play app-signing SHA-1. These public identifiers belong to `evcs-power-solutions`; staging retains its separate Google project and tenant.

## Decision

Use the existing Identity Google ID-token verification, password-confirmed account linking, consumer enrollment and mobile session contracts for Android, iOS and the browser driver application. The browser uses Google's rendered GIS button and authentication events; it never calls the unsupported browser `authenticate` method. Covered routes ignore provider events, repeat requests remain guarded, and browser account reselection clears the old pending proof. Tokens stay in memory until the existing API exchange and never enter logs or URL parameters.

Native iOS supplies its approved iOS client ID, the shared Web/server audience and a reversed-client-ID URL scheme. Production public Flutter definitions are tracked so future release builds do not silently omit Google sign-in. Firebase preparation writes the environment-specific callback scheme while retaining existing deep links, Firebase push and live charging/payment flags. Archive validation checks the signed application has the approved Google IDs and callback scheme.

The backend accepts an explicitly configured iOS authorized party in addition to the existing server and Android clients. Signature, issuer, server audience, expiry, tenant, membership, disabled-account and MFA checks remain unchanged. No automatic matching-email merge is added. No schema migration or new sensitive data collection is required.

## Operational constraints

Production uses only its approved clients. Play-installed Android builds must use the Play app-signing certificate fingerprint supplied by the owner: `F8:35:9E:23:CA:DC:24:D4:2B:AE:77:BC:5B:CD:C1:1A:59:F1:3F:09`. A directly installed production APK requires a separate Google Android OAuth registration for its actual signing certificate. Browser origins must be explicitly authorized in their corresponding project, including the staging origin for staging's Web client. Client IDs are public; client secrets and service-account material must not enter mobile/web assets.

Staging iOS remains unavailable until a staging Apple OAuth client is supplied; production credentials are never reused to bypass that requirement. Public iOS App Review also requires an equivalent privacy-focused sign-in option under Apple guideline 4.8, normally Sign in with Apple; this Google integration alone does not satisfy that release requirement. A Windows build cannot prove an iOS signed archive or physical-device login.

## Verification and rollback

Regression coverage checks configured iOS authorized parties, rejects unapproved clients and wrong audiences, preserves Google proof across password-link retries, verifies platform eligibility and rejects incomplete configuration. Build preparation tests preserve app links, reject mismatched Firebase OAuth clients and detect a missing archive callback scheme. Existing cross-tenant, signature, expiry, enrollment and linking tests remain required.

Acceptance includes Google chooser cancellation, existing password linking, new registration, logout/re-login, wrong-client failure, browser popup/origin restrictions and the Play-distributed signing certificate. Provider authentication needs a supervised account; fixture tests cannot prove Google Cloud console settings. Disable only Google authentication if rollback is necessary; preserve password recovery, provider-link data, charging and QR Ph settings. Roll back application images without restoring financial databases.

This extends ADR 0030's client scope; its Identity ownership and linking decisions remain in force.

## Client lifecycle correction (2026-11-25)

Returning from a provider chooser must not issue private notice requests until the driver is authenticated and email-verified. Public station discovery can still refresh for guests. The same authenticated guard applies to the existing periodic notice refresh.

An unauthorized response can expire only the token and tenant that sent it. Guest requests and delayed responses from a previous account must not clear a newer login, rotate its credentials, or replay an earlier account's mutation. Refresh completion checks the original session again before persisting rotated credentials; a retry is cancelled if the account changes before dispatch. A genuine current-session rejection still clears the expired credentials and requests sign-in. Refresh requests share one in-flight operation per session; the interceptor itself is not queued, so a rejected nested retry can finish without waiting behind its own error handler.

Regression tests cover chooser pause/resume, Google enrollment continuation, guest requests, delayed cross-tenant rejection, late refresh success/failure, logout during refresh, and account switching before a mutation retry. Existing Google cancellation, password-confirmed linking and authenticated login coverage remains required. This is a Flutter client correction: deploy both browser bundles and distribute new native builds; no backend migration or authentication-policy change is required. Real provider/device acceptance remains a separate release check.
