# ADR-0002: Use a Separately Deployable OCPP Gateway

- **Status:** Accepted
- **Date:** 2026-07-20
- **Decision owners:** Architecture, charging engineering, and operations
- **Scope:** Charger protocol edge

## Context

OCPP connections are long-lived, stateful WebSockets from intermittently connected, vendor-diverse edge devices. They require protocol negotiation, schema validation, request/result correlation, connection ownership, backpressure, and command routing. Their concurrency, deployment draining, security, latency, and failure profile differ from public/admin HTTP traffic and Laravel business workflows.

Embedding charger sockets directly in the Laravel web runtime would couple connection stability and scaling to web/API deployments, expose the core more directly to untrusted device traffic, and mix protocol semantics with canonical charging/business state.

## Decision

Implement the OCPP charger edge as a **separately deployable gateway container** with its own runtime lifecycle and logically owned operational persistence.

The gateway:

- terminates authenticated charger WebSocket/TLS connections;
- negotiates only approved OCPP versions/subprotocol profiles;
- validates, limits, correlates, and safely logs protocol frames;
- binds a connection identity to one registered Assets charger and tenant;
- normalizes supported protocol messages into versioned gateway/core contracts;
- routes correlated core commands to the current connection owner;
- records command/message delivery evidence and supports bounded store-and-forward; and
- exposes gateway health, connection, protocol, latency, rejection, and backlog telemetry.

The gateway does **not** authorize drivers, own asset lifecycle, choose/calculate tariffs, own canonical charging sessions, make payment/billing decisions, or write Laravel core tables.

## Boundary and Data

- Core and gateway communicate through mutually authenticated, versioned commands/queries and durable messages.
- The gateway owns protocol inbox/outbox, call/correlation IDs, connection leases/presence, delivery attempt/evidence, and bounded diagnostic protocol metadata.
- Charging owns normalized session/meter/command business state after validation and acceptance.
- Assets owns charger enrollment, tenant binding, capabilities, and active/restricted/retired state; a safe registry projection is supplied to the gateway.
- Gateway operational persistence is logically separate from the core. PostgreSQL is the default durable store technology under ADR-0003; Redis may accelerate connection coordination under ADR-0004 but is not durable truth.
- Phase 6 selects asynchronous Python, the maintained `ocpp` library, Redis connection/deduplication coordination, and Redis Streams as the initial normalized-event transport. Durable broker/inbox-outbox topology and protocol evidence retention remain follow-up decisions.

## Message and Command Semantics

- Every inbound source message/call has a stable deduplication identity derived from authenticated charger plus protocol identifiers/profile rules.
- Every core command has a ULID, tenant, charger/EVSE/connector/session target as applicable, command type, expected precondition, actor/reason, idempotency key, correlation, and UTC deadline.
- `accepted for dispatch`, `delivered`, `protocol acknowledged`, `rejected`, `timed out`, and `delivery unknown` are distinct outcomes.
- A command acknowledgement does not prove a physical charging-session transition. Charging advances only from trustworthy normalized transaction/status/meter evidence.
- At-least-once delivery is assumed; both sides maintain inbox/outbox deduplication and aggregate/state guards.
- The gateway applies backpressure and rejects/quarantines unsupported, malformed, oversized, over-rate, mismatched, or vendor-specific messages according to documented policy.

## Connection Ownership and Deployment

- One gateway node owns a charger connection at a time. Connection registry/lease semantics prevent split-brain command delivery.
- Core routes by charger ULID through an abstraction, not directly to a process address stored as business truth.
- Deployments stop accepting new connections, drain within a bounded period, and then close connections using protocol-safe behavior so chargers can reconnect.
- Reconnection, duplicate connections, node crash, network partition, core outage, and Redis outage require soak/failure testing.
- The design must support horizontal scaling by concurrent connection and message load independent of Laravel web/workers.

## Security

- Treat chargers as untrusted even when authenticated. Enrollment and message identity are server-derived.
- Use supported TLS and an approved OCPP security profile; certificate/basic-credential details are charger-capability decisions, not invented here.
- Per-device credentials, rotation/revocation, connection/message rate and size limits, action allow lists, schema validation, and safe redaction are mandatory capabilities.
- High-risk outbound firmware/diagnostics commands and vendor `DataTransfer` remain disabled by empty deployment allowlists. Inbound firmware, diagnostics/log, and security statuses are transported as bounded evidence without fetching URLs or applying business decisions. Certificate management OCPP profiles and remote configuration remain unsupported until specifically designed/threat-modeled.
- Gateway-to-core access is least privilege and cannot invoke arbitrary module methods or database writes.

## Consequences

### Positive

- Charger connectivity and protocol load scale/deploy independently from customer/admin web traffic.
- The core sees stable canonical contracts rather than OCPP version/vendor details.
- The protocol edge has a narrower security boundary and dedicated abuse/backpressure controls.
- Gateway technology can match high-concurrency needs without forcing that runtime on the core.
- Connection draining, ownership, and protocol observability become explicit responsibilities.

### Negative

- Adds a distributed boundary, separate deployment, service authentication, persistence, monitoring, and on-call responsibility.
- Core/gateway contract versioning and eventual consistency must be managed.
- Commands can have ambiguous network outcomes and require reconciliation rather than local transactions.
- Local integration and end-to-end protocol testing is more involved.
- Duplicated concepts must be carefully limited to safe registry/connection projections.

## Alternatives Considered

### Run OCPP inside Laravel web/API

Rejected because long-lived device connections and protocol workload would share failure and deployment behavior with unrelated web/API traffic.

### Use a charger-vendor cloud as the primary gateway

Rejected as the standalone platform default because it would outsource canonical protocol reachability and create vendor lock-in. A vendor cloud may later be an Integrations adapter, not an undocumented source of truth.

### One gateway per tenant

Rejected as the default due to operational cost. Dedicated tenant gateway deployment remains possible only if isolation/scale/commercial evidence justifies it.

### Protocol microservice per OCPP version

Rejected initially. One gateway can isolate version-specific adapters behind a canonical contract until incompatible scaling/team needs are demonstrated.

## Risks and Controls

| Risk | Control |
| --- | --- |
| Split-brain connection/duplicate command | Leases/ownership fencing, idempotency, state preconditions, chaos tests |
| Core/gateway version skew | Versioned schemas, compatibility window, consumer/provider contract tests, staged rollout |
| Gateway loses messages | Durable inbox/outbox and acknowledgements, bounded retries, reconciliation/alerts |
| Compromised charger floods platform | Per-device rate/size/action limits, connection containment, queue isolation |
| Protocol mapping corrupts session | Evidence-first mapping, conformance fixtures, raw-safe references, canonical state guards |
| Gateway becomes business service | Enforced responsibilities and APIs; no core table access or tariff/payment decisions |

## Phase 6 Implementation Note

The implemented baseline is documented in [`../ocpp-gateway.md`](../ocpp-gateway.md). It supports OCPP 1.6J and 2.0.1 adapters, per-device Basic/mTLS-ready identity, Redis latest-owner leases, duplicate response replay, correlated allowlisted commands, fail-closed core authorization, normalized versioned events, metrics/readiness, TLS configuration, and a fault/reconnect simulator. It does not claim OCPP certification or production broker/CA readiness.

## Follow-up Decisions

- Required security profiles and OCPP certification matrix by charger capability.
- Production core transport/broker, routing, retention, durable inbox/outbox, and replay.
- Durable operational schema, message retention, and physical database topology.
- Capacity/SLOs, multi-region fencing, per-device rate limits, and deployment drain timings.
