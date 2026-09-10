# ADR-0005: Use Flutter for the Consumer Mobile App

- **Status:** Accepted
- **Date:** 2026-07-20
- **Decision owners:** Product architecture and mobile engineering
- **Scope:** Driver-facing iOS and Android application

## Context

VTSA CSMS needs a consumer app for location discovery, charging initiation/status, tokenized payment interaction, documents, notifications, and support. A shared mobile product must maintain consistent behavior while supporting platform-specific secure storage, deep links, push notifications, permissions, accessibility, and store release requirements.

Maintaining separate native iOS and Android applications would increase feature parity and staffing cost before the product's market-specific flows are stable. A web-only/PWA experience may not provide the desired background, secure storage, deep-link, push, and store-distribution behavior consistently.

## Decision

Use **Flutter** and Dart for a shared iOS and Android consumer application.

The Flutter app is a client of versioned VTSA public/mobile APIs. It holds presentation and interaction logic plus a replaceable offline/cache projection; Laravel/Charging/Tariffs/Payments/Billing remain authoritative for authorization, session state, calculation, and financial facts.

Flutter Web/desktop are not included by this decision. The public website and Laravel admin/operator UI remain web surfaces.

## Application Boundaries

Organize the app by driver feature/domain (for example identity, discovery, charging, payments, documents, notifications, support), with shared design, networking, storage, telemetry, and localization infrastructure.

- Generated/typed API models may be produced from versioned OpenAPI schemas, wrapped so transport models do not become UI/domain state everywhere.
- State management, navigation, dependency injection, HTTP, local database, and code-generation packages are selected by a follow-up mobile convention; this ADR does not invent a package stack.
- Business-critical calculation and permission decisions stay server-side. The app may render server-provided estimates and explain freshness/quality.
- Push/realtime messages are minimal hints. The app refetches authoritative state using sequence/version and correlation information.
- Local cached data is scoped by signed-in subject/environment and cleared on logout/account switch/revocation according to data classification.

## Security and Privacy

- Store refresh/session material only in supported OS secure storage; never store provider, gateway, or application signing secrets in the bundle.
- Use system browser/provider-supported hosted flows for authentication/payment action where required, with exact redirect/deep-link validation and one-time state/nonce.
- Do not collect or proxy raw card data. Provider SDKs/components, if selected, expose tokenized results only.
- Enforce TLS and validate server identity using platform defaults; certificate pinning/attestation requires a rotation/recovery threat decision before adoption.
- Minimize logs/crash reports and redact tokens, personal data, location history, payment references, and support content.
- Request OS permissions just in time with a clear purpose and a usable denied-state fallback.
- Root/jailbreak/emulator detection, device attestation, biometrics, screenshot controls, and background location are not assumed security boundaries and require explicit decisions.

## Offline and Failure Behavior

- Discovery data may be cached with visible freshness; published availability and price must identify stale/unknown data.
- Charging mutations require idempotency keys persisted long enough to retry the same intent after app/network interruption.
- App termination or device loss never ends a server/charger session. On launch/sign-in, the app retrieves any active session from the API.
- Remote start/stop shows requested/pending/confirmed/failed/unknown outcomes and does not infer physical state from an HTTP acknowledgement.
- Payment action and callbacks resume through a verified intent ID/state; the app cannot mark a payment successful locally.
- Queued offline business mutations are limited to explicitly safe cases. Charging/payment actions are not blindly replayed after an unbounded delay.

## Quality and Release

- Support a documented minimum iOS/Android version matrix and compatible API/app-version window.
- Use unit tests for app/domain/presentation logic, widget tests for states/accessibility, integration tests for critical journeys, and device testing for push/deep links/secure storage/payment handoff.
- Validate accessibility, localization expansion, dark/text scaling if supported, low connectivity, process death, background/resume, clock skew, and duplicate taps.
- Builds are reproducible and signed only by protected release systems. Environment endpoints/configuration are explicit and no production secrets are compiled into the app.
- Mobile release must support minimum-version, encouraged/forced update, deprecation, incident disablement, privacy disclosures, and store compliance policies.

## Consequences

### Positive

- Shared UI/feature code and test suite across iOS and Android.
- Consistent branded experience and rapid iteration for a product with evolving workflows.
- Strong tooling for custom responsive UI and accessibility semantics.
- Native plugins can cover maps, push, secure storage, deep links, biometrics, and payment-provider components when selected.
- A typed client and feature boundaries can align with versioned Laravel APIs.

### Negative

- Mobile engineers need Flutter/Dart expertise and must still understand native build/release systems.
- Platform-specific plugin behavior and OS lifecycle issues require native testing and occasional Swift/Kotlin work.
- App binary/runtime characteristics may differ from fully native apps.
- Third-party plugin maintenance and supply-chain review add risk.
- Store release lag requires server contract compatibility and feature-control discipline.

## Alternatives Considered

### Separate Swift and Kotlin apps

Rejected initially due to duplicate feature/release effort and likely parity drift. This may be revisited if measured platform-specific experience/performance requires it.

### React Native

Not selected. It is viable, but Flutter's unified rendering/tooling and the explicit product direction favor Flutter. A change would require evidence and a superseding ADR.

### Progressive Web App only

Rejected as the sole consumer app due to uncertain platform parity for push, secure storage, deep-link/payment handoff, background behavior, and store expectations. The public web remains an important fallback/discovery surface.

### Embed driver flows in the Laravel admin app

Rejected because workforce and consumer trust, UX, release, identity, and authorization boundaries differ.

## Risks and Controls

| Risk | Control |
| --- | --- |
| Plugin compromise/abandonment | Minimize dependencies, pin/scan/review plugins, wrapper interfaces, native fallback plan |
| Duplicate charge/start from retries | Persisted idempotency keys, disabled duplicate UI, server state guards, resume tests |
| Stale local state | Version/freshness labels, API resync on reconnect/resume/push |
| Secrets/PII in device telemetry | Secure storage, log redaction, data-classified analytics/crash configuration |
| Old app/API incompatibility | Versioned contract, compatibility window, contract tests, update/deprecation controls |
| Platform-specific failures | Real-device matrix, lifecycle/deep-link/push/payment integration tests |

## Follow-up Decisions

- Supported iOS/Android versions, devices, languages, regions, and accessibility target.
- Flutter/Dart version, state management, navigation, networking, local storage, and code generation.
- Identity flow, universal/app links, push provider, maps SDK, payment SDK/hosted flow, and analytics/crash provider.
- Offline discovery cache, active-session polling/realtime policy, and app update/deprecation policy.
- CI/signing/store ownership, release tracks, attestation/pinning, and privacy permission posture.
