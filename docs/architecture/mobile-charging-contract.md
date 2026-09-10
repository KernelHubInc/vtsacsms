# Mobile Charging Contract Boundary

## Status

The Phase 10 Flutter application defines and tests the customer charging ports in this document. The Laravel platform does not yet expose the corresponding customer-owned endpoints. Existing `/api/v1/charging-sessions` routes are workforce operations protected by tenant and site permissions; they must not be reused by the consumer app or made public.

This document is a contract proposal and release gate, not evidence that the server endpoints exist.

The proposed QR-to-payment orchestration, simulator interaction, recovery behavior, implementation order, and UAT criteria are documented in [mobile-qr-charging-transaction-flow.md](mobile-qr-charging-transaction-flow.md).

## Ownership and trust

- Charging owns connector reservation, command orchestration, canonical session state, metering, anomaly handling, and CDR finalization.
- Tariffs owns the effective tariff and immutable session snapshot.
- Payments owns tokenized method references, preauthorization, capture, webhook verification, and payment state.
- Billing owns authorized receipt, invoice, and credit-note artifacts.
- Support owns issue records; Payments owns refund execution after approved review.
- The mobile app holds a replaceable projection. Realtime and push are hints; a versioned HTTPS read is authoritative.
- The authenticated customer ID and tenant are derived server-side from the Sanctum token and customer relationship. Client-supplied tenant or owner IDs cannot expand scope.

## Required customer endpoints

| Method and path | Purpose | Essential controls |
| --- | --- | --- |
| `POST /api/v1/mobile/charging/prepare` | Resolve a charger code and return connector readiness, compatibility context, expiring tariff disclosure, estimated preauthorization, and safe tokenized method summaries | Customer authentication, code rate limit, public ULIDs, no card data |
| `POST /api/v1/mobile/charging-sessions/remote-start` | Reserve/orchestrate start from the accepted quote and payment reference | Required idempotency key, customer ownership, quote expiry, connector concurrency guard |
| `GET /api/v1/mobile/charging-sessions/active` | Recover the caller's pending/live/finalizing/payment-pending session | Customer ownership only; at most the policy-approved active result |
| `GET /api/v1/mobile/charging-sessions` | Cursor-paginated customer history | Customer ownership in query and policy; no tenant-wide fallback |
| `GET /api/v1/mobile/charging-sessions/{sessionId}` | Read one customer session projection | Customer ownership and tenant scope; ULID route binding |
| `POST /api/v1/mobile/charging-sessions/{sessionId}/remote-stop` | Request stop for the caller's physically active session | Required idempotency key; ownership; canonical transition guard |
| `POST /api/v1/mobile/charging-sessions/{sessionId}/cancel` | Cancel an eligible start-pending orchestration | Ownership; safe state guard; audit |
| `POST /api/v1/mobile/charging-sessions/{sessionId}/refund-requests` | Create a review request, not an immediate refund | Required idempotency key; ownership; eligibility; audit |
| `POST /api/v1/mobile/support/issues` | Create an issue linked to an owned session | Required idempotency key; ownership; validation and abuse controls |

## Projection requirements

Session responses need canonical state, aggregate version, UTC timestamps, station and connector presentation facts, energy Wh, duration seconds, current power W when trustworthy, estimated/final minor-unit money with ISO currency, start deadline when applicable, safe anomaly codes, payment state, and authorized document links. A state update with a lower aggregate version cannot replace a newer projection.

Preparation responses need an expiring quote ID and tariff-version ID. A start request references those IDs; the app never sends a client-calculated amount. Payment methods contain provider-independent token references and safe labels only. PAN, CVV, provider secrets, raw authorization data, and internal tenant-wide identifiers are forbidden.

## Idempotency and recovery

- Start, stop, refund-review, and issue mutations accept `Idempotency-Key` and return the original semantic result for a duplicate key and actor.
- Start acceptance returns a session in a pre-physical state. Only canonical transaction evidence advances it to charging.
- Stop acceptance does not mark the session complete.
- Delayed/out-of-order payment callbacks keep the session pending until verified provider or reconciliation evidence advances Payments.
- Realtime channels and push payloads carry only a session ULID, event category, and safe routing metadata. The app retrieves the current authorized projection.
- Access from a second device resolves the same server session. Revoked tokens cannot read or mutate it.

## Unresolved decisions

- Customer-to-tenant derivation and multi-operator identity policy.
- Whether a customer may have more than one concurrent session.
- Push provider, platform credentials, notification categories, and deep-link domain.
- Preauthorization policy and action-required hosted flow by provider.
- Refund eligibility vocabulary and review SLA.
- Receipt/invoice terminology, legal content, expiry, and download mechanism.
- Realtime transport and authorization handshake.

No provider credentials, tax registrations, charger-specific exceptions, or production infrastructure details are assumed.
