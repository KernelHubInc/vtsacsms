# ADR-0001: Use a Laravel Modular Monolith for the Core Platform

- **Status:** Accepted
- **Date:** 2026-07-20
- **Decision owners:** Architecture and engineering
- **Scope:** Core business and web/API platform; excludes the separately deployed OCPP gateway and Flutter client

## Context

VTSA CSMS begins with 18 related bounded contexts and many workflows that cross operational and financial boundaries. Early requirements and team topology will evolve. Premature microservices would add distributed transactions, deployment/version coordination, service discovery, observability, local-development, and incident overhead before measured scale or independent-team needs justify it.

A conventional undifferentiated Laravel application would be simpler initially but would make data ownership and context boundaries easy to violate, increasing the chance of a tightly coupled codebase that cannot evolve safely.

## Decision

Build the core platform as a **Laravel modular monolith**: one versioned application codebase and deployable artifact, organized into explicit bounded-context modules:

Identity, Tenancy, Organizations, Locations, Assets, Charging, Tariffs, Payments, Billing, Settlements, Procurement, Inventory, Maintenance, Notifications, Support, Reporting, CMS, and Integrations.

The same Laravel artifact may run as separately scaled web/API, worker, and scheduler process roles. Those roles are deployment/runtime variants, not independent business services.

The OCPP gateway is excluded from the monolith by ADR-0002 because its protocol, connection, scaling, and failure profile is materially different.

## Decision Details

### Module structure

Each module has conceptual `Domain`, `Application`, `Infrastructure`, and `Presentation` layers. The exact Laravel namespace/scaffold will be finalized when the application is created.

- Domain owns invariants, aggregates, policies, state transitions, domain events, and value objects without UI/provider concerns.
- Application owns commands/queries, ports, DTOs, authorization-aware orchestration, and transaction boundaries.
- Infrastructure owns Eloquent persistence, queue/outbox mechanisms, and external adapters implementing module ports.
- Presentation owns HTTP, Livewire v3, console, and event-consumer adapters.

### Boundaries

- Each table, aggregate, and lifecycle has exactly one owning module as documented in `data-ownership.md`.
- A module cannot write another module's tables or import its internal Eloquent/repository/domain implementation.
- Cross-module writes use the owner's public application command. Cross-module reads use an explicit query contract or read projection.
- Committed facts cross contexts through versioned integration events and a transactional outbox.
- A database transaction is local to the owning context plus outbox/audit. Distributed workflows use explicit state and compensation.
- Architecture tests will enforce allowed namespaces/dependencies and migration/table ownership.

### Shared code

Shared packages are limited to genuinely context-neutral primitives such as ULID handling, canonical measurement/money value types, event envelope, correlation, clock, and test utilities. A `Common` module must not become a home for ambiguous business logic or cross-context models.

### Extraction criteria

A module may be considered for a separate service only when evidence shows one or more of:

- independent scaling or availability requirements cannot be met safely within process-role scaling;
- a distinct security/compliance boundary is required;
- an independently released team owns a stable contract;
- incompatible runtime/technology is justified;
- workload isolation is materially necessary; or
- measured coupling/deployment impact is reduced by extraction.

Extraction requires a new ADR, explicit API/event contract, independent data ownership, migration/rollback plan, observability/on-call ownership, and a quantified operational benefit.

## Consequences

### Positive

- One atomic database transaction can enforce local invariants and outbox publication.
- Local development, testing, deployment, refactoring, and end-to-end tracing are simpler than a microservice estate.
- Laravel/Livewire capabilities and team expertise can be used consistently.
- Module boundaries provide a path to independent services if evidence later requires them.
- A single codebase supports shared security, tenancy, audit, and canonical-unit standards.

### Negative

- Discipline and automated fitness tests are required; language/runtime visibility alone cannot enforce every boundary.
- A bad query, memory leak, or deployment can affect several contexts unless process/workload isolation is designed.
- A single release cadence can couple teams as the organization grows.
- Shared PostgreSQL increases the temptation to join/write across ownership boundaries.
- Some cross-context operations that could be synchronous must still be modeled for retries and eventual consistency to preserve future seams.

## Alternatives Considered

### Microservices from the start

Rejected for the initial core because service boundaries, scale, team autonomy, and operational support are not proven. It would make consistency and delivery harder without a demonstrated benefit.

### Conventional layered Laravel application

Rejected because grouping all models/controllers/services by technical layer obscures bounded contexts and data ownership.

### Separate application per major business area in one database

Rejected because independent applications sharing writable tables preserve the worst coupling while adding deployment/network complexity.

### Full event-sourced core

Rejected as a platform-wide default. Append-only audit/financial/stock histories and outbox events are required, but rebuilding every aggregate solely from events adds complexity not justified for all contexts.

## Risks and Controls

| Risk | Control |
| --- | --- |
| Boundary erosion | Architecture tests, context-owned migrations/repositories, code review checklist, `data-ownership.md` |
| Shared deployment blast radius | Separate runtime roles/queues, health-gated rollout, feature controls, query/load budgets |
| Cross-context transaction growth | Local transaction rule, explicit contracts/events, no network call inside transaction |
| Oversized shared utilities | Named ownership review; business concepts stay in a context |
| Future extraction becomes difficult | Versioned contracts, no cross-module table writes, module-local schemas and events |

## Follow-up Decisions

- Exact Laravel version and module namespace/directory conventions.
- Context table prefix versus PostgreSQL schema convention.
- Dependency/architecture test tooling.
- Queue topology and which cross-context interactions are synchronous.
- Release/version compatibility policy for mobile, gateway, and events.
