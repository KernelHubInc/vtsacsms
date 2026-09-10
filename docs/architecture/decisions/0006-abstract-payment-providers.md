# ADR-0006: Use a Capability-aware Payment Provider Abstraction

- **Status:** Accepted
- **Date:** 2026-07-20
- **Decision owners:** Architecture, payments engineering, finance, and security
- **Scope:** Payment provider integration boundary

## Context

Payment providers expose different concepts, state names, idempotency behavior, hosted payment flows, authorization/capture/refund capabilities, webhook security, disputes, reports, and regional payment methods. Embedding one provider SDK and object model throughout Charging, Billing, mobile APIs, and admin UI would create vendor lock-in, spread PCI/security concerns, make testing difficult, and corrupt historical behavior when a provider changes.

A provider-neutral abstraction must not pretend all providers are identical or reduce behavior to an unsafe lowest common denominator. Capability and evidence differences need to remain explicit at the adapter boundary.

## Decision

The Payments bounded context owns a **provider-neutral domain model and ports**, with one adapter per approved provider/account/API version.

Core capabilities include:

- create/retrieve a payment customer reference where the provider model requires it;
- attach/list/revoke safe tokenized payment-method references;
- create/retrieve payment intents;
- authorize or perform provider-required customer action;
- capture, cancel/void, refund, and retrieve their statuses;
- receive, authenticate, deduplicate, and translate provider webhooks;
- receive/retrieve disputes and safe evidence deadlines/outcomes;
- import/retrieve transaction, fee, and payout report evidence for Settlements; and
- declare a tested provider capability matrix.

Charging, Billing, Settlements, mobile APIs, support, and admin flows use Payments application contracts and provider-neutral states. They do not import provider SDK classes, webhook schemas, status strings, or credentials.

## Domain and Adapter Boundary

### Core owns

- VTSA intent/attempt/authorization/capture/refund/dispute ULIDs and state machine;
- tenant, payer and billable references, amount minor/currency, idempotency/correlation;
- normalized failure categories and whether outcome is confirmed/ambiguous;
- application authorization, approval, audit, and event publication;
- cumulative amount guards and reconciliation state requirements; and
- adapter port interfaces and contract tests.

### Provider adapter owns

- SDK/HTTP client and provider API version;
- provider account/environment mapping and secret references;
- provider request/response/webhook/report schemas and signature verification implementation;
- mapping of provider IDs/status/reasons/capabilities to core results;
- safe preservation of provider metadata/evidence required for support/reconciliation;
- provider-specific idempotency header/key and retry/status-retrieval behavior; and
- conformance fixtures and failure simulations.

### Billing and Settlements retain their ownership

- Billing decides the amount claimed/documented and owns allocations/account balance.
- Settlements matches expected platform records to provider reports/payouts/fees.
- Payments executes/observes provider actions but cannot rewrite invoices, tariff calculations, sessions, or settlement exceptions.

## Capability Model

An adapter declares capabilities such as:

- separate authorization and capture;
- authorization expiry and incremental authorization;
- partial/multiple capture;
- cancel/void timing;
- partial/multiple refunds;
- customer action/redirect/deep-link method;
- supported payment methods/currencies/markets;
- provider idempotency and retrieval guarantees;
- signed webhook algorithms/replay metadata;
- dispute/report/payout availability; and
- token portability/account constraints.

Use cases check capability before initiation. Unsupported operations fail explicitly before an external effect. Provider-specific optional behavior is exposed as a reviewed capability extension, not arbitrary metadata branching throughout the application.

## Provider Selection and History

- A payment intent selects a provider adapter/account/configuration version before submission and freezes it for historical interpretation.
- Provider selection/routing is policy-driven and auditable. The initial product may use one provider; abstraction does not imply active multi-provider routing.
- An in-flight intent is not moved between providers. A new provider attempt that could create another charge requires a new intent linked to the old one and only after the old outcome is proven safe.
- Historical records retain provider/account/API/configuration references and normalized plus safe raw evidence needed to explain results.
- Token references are assumed non-portable unless a provider-approved migration proves otherwise.

## Payment Data and Webhooks

- Use provider-hosted/tokenized payment collection. Raw PAN/CVV does not enter VTSA clients/servers/logs/events under this architecture.
- Provider credentials are environment/account-scoped secret references available only to the adapter runtime.
- Webhook ingress locates the adapter by an unambiguous configured endpoint/account context, preserves the raw body transiently for signature verification, checks timestamp/replay, and deduplicates provider event ID before translation.
- Return a provider-appropriate acknowledgement without leaking internal processing details; domain work is idempotent and can be queued after verified receipt.
- Unknown/unmapped account/resource/status or contradictory events are quarantined and reconciled, never attached by amount/customer guesswork.

## Error Semantics

Adapters return structured results, not thrown provider strings as business decisions:

- confirmed success with normalized/provider references;
- confirmed decline/failure with safe category and retryability;
- customer action required with a short-lived safe continuation artifact;
- unsupported capability/configuration error before external effect; or
- outcome unknown after timeout/network/ambiguous response.

For `outcome unknown`, Payments retrieves/reconciles using the same idempotency/provider reference. It does not initiate another effect until resolved or an audited manual process proves safety.

Provider response codes/messages may be retained as restricted evidence but are not directly shown to customers or used as stable cross-context API values.

## Testing Contract

Every adapter must pass the same provider-neutral contract suite plus provider-specific fixtures:

- amount/currency and capability validation;
- idempotent create/authorize/capture/cancel/refund retries;
- timeout before/after provider effect and later retrieval/callback;
- duplicate, reordered, replayed, invalid-signature, and unknown webhooks;
- partial capture/refund and cumulative amount guards where supported;
- capture/cancel and refund/dispute races;
- provider API/version error mapping and safe logging;
- account/environment/tenant mismatch rejection; and
- reconciliation of platform/provider references and totals.

No tests call live production provider endpoints or use production credentials. Sandbox contract tests are opt-in CI with managed test secrets and deterministic local fakes for the main suite.

## Consequences

### Positive

- Provider SDK/status/security details remain isolated from charging and finance domain code.
- Providers can be added, upgraded, or replaced with a known contract and migration impact.
- Deterministic fakes enable thorough timeout/duplicate/race testing.
- Hosted/tokenized flows minimize card-data scope.
- Historical payment interpretation remains tied to the actual provider/configuration version.
- Capability checks avoid unsafe assumptions across providers.

### Negative

- The abstraction and contract suite require upfront design and maintenance.
- Provider features may not map cleanly; normalized states plus provider evidence must coexist.
- Active multi-provider routing and token migration remain complex despite the abstraction.
- Debugging crosses VTSA intent/attempt and provider resource histories.
- Lowest-common-denominator pressure can limit valuable provider features unless capability extensions are governed.

## Alternatives Considered

### Direct provider SDK use across modules

Rejected because it spreads vendor models/secrets and makes state, testing, migration, and PCI scope harder to control.

### Choose one provider and accept permanent coupling

Rejected because market/provider/API changes are likely and payments are too high-risk to embed throughout the product. The first release may still configure only one adapter.

### Build an external payment microservice immediately

Rejected initially under the modular monolith. Payments remains a strong module boundary that can be extracted if compliance, scale, or team autonomy later requires it.

### Normalize only through generic key/value metadata

Rejected because it abandons type/state/amount guarantees and pushes provider branching to every consumer.

## Risks and Controls

| Risk | Control |
| --- | --- |
| Abstraction hides unsafe difference | Explicit capability matrix, adapter-specific evidence/extensions, fail before effect |
| Duplicate financial effects | Stable idempotency, intent/attempt state machine, provider retrieval, amount guards |
| Webhook spoof/replay | Raw-body signature/timestamp verification, account-bound endpoint, dedup/quarantine |
| Provider outage/ambiguous response | Outcome-unknown state, circuit/timeout, retrieval/reconciliation, no blind retry |
| Credential/card leakage | Hosted tokenization, secret manager, adapter-only access, telemetry/contracts scanning |
| Provider migration breaks history | Freeze provider/account/API/config version and references per intent |

## Follow-up Decisions

- Launch market/provider(s), merchant-of-record/account model, methods, currencies, and PCI validation.
- Exact port schemas and normalized failure/capability taxonomy.
- Preauthorization/capture/cancel/refund/dispute policies and approval thresholds.
- Hosted flow/mobile SDK/deep-link architecture.
- Provider credential/KMS ownership, webhook endpoints, reconciliation/report ingestion, and retention.
- Conditions for provider routing/failover or extracting Payments as a service.
