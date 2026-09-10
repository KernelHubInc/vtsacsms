# VTSA CSMS Product Requirements Document

**Status:** Phase 0 architecture baseline  
**Product:** VTSA CSMS  
**Audience:** Product, engineering, security, operations, finance, procurement, maintenance, and implementation partners  
**Last updated:** 2026-07-20

## 1. Product Vision

VTSA CSMS is a standalone, multi-tenant electric-vehicle charging platform that lets charging-network businesses publish locations, serve drivers, operate charger estates, price and bill charging, reconcile money, manage parts and purchasing, and maintain physical assets from one governed product.

The platform consists of:

- a public marketing website and searchable charging map;
- a Flutter consumer mobile app;
- a Laravel admin and operator platform;
- a separately deployable OCPP charger gateway;
- charging-session and tariff capabilities;
- payments, billing, reconciliation, and settlement capabilities;
- procurement and inventory capabilities; and
- maintenance and asset-management capabilities.

This phase defines the product and architecture only. It does not authorize implementation of business features.

## 2. Product Principles

1. **Safe charging first.** Operational commands and session records must be traceable, idempotent, and resilient to intermittent connectivity.
2. **Tenant isolation by design.** Every tenant-scoped request, query, event, job, cache entry, and export must carry and enforce tenant context.
3. **One source of truth per concept.** A bounded context owns changes to its data; other contexts integrate through explicit contracts and events.
4. **Financial correctness over convenience.** Monetary records are immutable or corrected by compensating entries, and provider callbacks are verified and reconciled.
5. **Protocol and provider independence.** Charger protocols and payment providers are isolated behind versioned platform contracts.
6. **Operational transparency.** Users can understand current state, history, exceptions, and who changed what.
7. **Portable measurements.** Money uses integer minor units with currency; energy uses watt-hours; power uses watts; duration uses seconds; instants use UTC.
8. **Privacy and least privilege.** Collect only necessary data and grant the minimum access needed for a role and scope.

## 3. Goals

- Support multiple commercial tenants on a shared platform without data leakage.
- Provide consistent discovery and charging journeys across public web, mobile, and operator channels.
- Normalize supported OCPP traffic into a stable charging domain independent of protocol version.
- Maintain authoritative session, meter, tariff, payment, invoice, settlement, stock, and work-order histories.
- Enable each tenant to operate many organizations, locations, chargers, warehouses, staff teams, and commercial arrangements.
- Support audited exception handling for payments, settlements, stock movements, and maintenance.
- Establish module contracts and state machines that allow incremental delivery without prematurely splitting the core into microservices.

## 4. Non-goals for the Initial Product Baseline

- Vehicle-to-grid energy trading, grid balancing, and demand-response orchestration.
- Roaming network interoperability unless later selected through an ADR and requirements update.
- Owning a card vault, processing raw PAN/CVV, or acting as a bank.
- Charger firmware development or undocumented vendor-specific OCPP extensions.
- Automated tax registration, legal tax advice, or country-specific fiscal compliance without jurisdictional requirements.
- Native accounting, payroll, or full ERP replacement.
- Production infrastructure topology, cloud vendor, bank credentials, payment secrets, and certificate material.
- Business feature implementation during Phase 0.

## 5. Product Scope

### 5.1 Experience surfaces

- **Public web:** marketing content, public location/EVSE/connector discovery, availability display, tariff summaries, accessibility details, and deep links to the app.
- **Consumer mobile:** identity, discovery, charging initiation/control where permitted, live session status, receipts/invoices, payment methods through provider tokenization, support, and notifications.
- **Admin/operator:** tenant configuration, role-scoped operations, locations/assets, charger monitoring, sessions, tariffs, financial operations, procurement, inventory, maintenance, CMS, support, reporting, and integration management.
- **OCPP gateway:** authenticated charger connections, protocol validation, heartbeats/status, transaction messages, meter values, remote command routing, correlation, and safe store-and-forward behavior.
- **External API/webhooks:** versioned integration surfaces for approved partners and tenant systems.

### 5.2 Bounded contexts

| Context | Responsibility |
| --- | --- |
| Identity | Human and machine identities, authentication methods, sessions, MFA, and credential lifecycle |
| Tenancy | Tenant lifecycle, tenant isolation, feature entitlements, and tenant-level policy defaults |
| Organizations | Legal/operating organization hierarchy, memberships, teams, and business scopes |
| Locations | Sites, addresses, access hours, geospatial coordinates, amenities, and publication state |
| Assets | Chargers, EVSEs, connectors, component hierarchy, serial identity, commissioning, and asset lifecycle |
| Charging | Charger connectivity projection, authorization, commands, reservations if adopted, meter readings, and charging sessions |
| Tariffs | Price plans, dimensions, schedules, versions, eligibility, and charge calculation inputs |
| Payments | Payment customers/tokens references, intents, authorizations, captures, refunds, disputes, and provider adapters |
| Billing | Rated charge records, invoices, credit notes, receipts, tax evidence inputs, and account balances |
| Settlements | Reconciliation, merchant/payout records, allocation, fees, exceptions, and settlement statements |
| Procurement | Suppliers, requisitions, purchase orders, approvals, and goods-receipt expectations |
| Inventory | Catalog items, warehouses/bins, stock ledger, reservations, transfers, counts, lots, and serialized stock custody |
| Maintenance | Fault reports, work orders, schedules, assignments, labor/parts consumption, evidence, and service history |
| Notifications | Templates, preferences, message orchestration, delivery attempts, and provider status |
| Support | Cases, correspondence, categorization, SLA clocks, and links to operational records |
| Reporting | Read-optimized projections, exports, dashboards, scheduled reports, and governed metrics |
| CMS | Marketing/navigation content, public pages, media references, and publication workflow |
| Integrations | Partner registrations, API clients, webhooks, import/export jobs, mapping, and integration health |

Detailed ownership rules are in [`../architecture/data-ownership.md`](../architecture/data-ownership.md).

## 6. Primary User Outcomes

- A driver can discover a suitable available connector, understand the displayed price, charge, follow progress, pay securely, and obtain a record of the transaction.
- An operator can determine whether a charger is connected and serviceable, safely issue permitted commands, investigate sessions, and manage exceptions.
- A commercial administrator can configure locations, assets, access, and tariffs without crossing tenant boundaries.
- Finance teams can trace a customer charge from session and tariff version through provider transaction, invoice/receipt, reconciliation, fees, payout, and corrections.
- Procurement and warehouse teams can order, receive, locate, reserve, transfer, count, and issue parts with an auditable stock ledger.
- Maintenance teams can triage faults, plan work, reserve parts, capture service evidence, and return assets to service under controlled authorization.
- Platform operators can manage tenants and platform health without silently impersonating tenant staff or bypassing audit controls.

## 7. Measures of Success

Target values are deliberately deferred until expected scale, markets, and SLAs are approved. The platform must make these measures observable:

- public map search success and freshness of published availability;
- charging start success by protocol, asset model, location, and failure reason;
- session completeness, meter-data gaps, and time to finalization;
- payment authorization/capture success and duplicate-charge prevention;
- reconciliation match rate, aged exceptions, and settlement timeliness;
- charger connectivity/availability and remote-command latency;
- stock accuracy, stockout rate, order lead time, and inventory adjustments;
- maintenance mean time to acknowledge/repair and repeat-fault rate;
- notification delivery rate, support response/resolution time, and customer satisfaction;
- tenant-isolation incidents, privileged-access reviews, and audit completeness.

## 8. Canonical Data Conventions

| Concept | Canonical representation |
| --- | --- |
| Public identifiers | ULIDs; never sequential identifiers in public contracts |
| Money | Integer minor units plus ISO 4217 currency code; never floating point |
| Energy | Integer watt-hours (`Wh`) |
| Power | Integer watts (`W`) |
| Duration | Integer seconds |
| Timestamps | UTC instants using timezone-aware storage and ISO 8601 API values |
| Local schedules | IANA timezone identifier plus local rule; resolved instants stored in UTC |
| Distance | Integer meters unless a contract explicitly states otherwise |
| Coordinates | PostGIS geometry/geography using WGS 84 (`SRID 4326`) |
| Quantities | Integer base units where indivisible; explicit fixed-precision decimal only where fractional stock is a real requirement |

All API and event fields include the unit in their name when ambiguity is possible, such as `energy_wh`, `power_w`, `duration_seconds`, and `amount_minor`.

## 9. Delivery Boundaries and Phasing

### Phase 0 — Product and architecture definition

This document set, architecture decisions, ownership boundaries, event catalog, state machines, security/tenancy models, assumptions, and open decisions. No business features.

### Candidate delivery increments

Sequencing remains an open product decision, but likely increments are:

1. platform foundation, identity, tenancy, organizations, and audit;
2. locations/assets, public discovery, and CMS;
3. OCPP connectivity and operational monitoring;
4. charging sessions, tariff calculation, and driver app journey;
5. payments, billing, reconciliation, and settlements;
6. procurement, inventory, and maintenance;
7. support, reporting, and external integrations.

Each increment requires its own refined requirements, threat model, migrations, operational readiness, and acceptance tests.

## 10. Dependencies

- Supported OCPP version(s), charger certification matrix, and charger credential/certificate enrollment method.
- Payment-provider and merchant-of-record selection by market.
- Mapping/geocoding, messaging, email, push, object storage, and observability providers.
- Jurisdiction-specific privacy, invoicing, tax evidence, receipt, accessibility, and retention requirements.
- Tenant onboarding model, commercial entitlements, settlement obligations, and support model.
- Expected fleet size, concurrent connections, session throughput, map traffic, and retention periods.

## 11. Assumptions

- The first architecture uses a shared application and shared PostgreSQL cluster with row-based tenant isolation; dedicated deployment options may be evaluated later.
- One session has one immutable tenant owner determined when authorization/session creation occurs, even if commercial parties differ.
- A location has an IANA timezone; all instants are stored in UTC.
- Payment instruments are hosted/tokenized by a compliant provider; VTSA CSMS stores only provider references and safe metadata.
- A charger connection may be unreliable, duplicate messages, and deliver some messages late; platform workflows are idempotent.
- Redis can be lost and rebuilt without loss of authoritative business data.
- Reporting projections may be eventually consistent and never own operational writes.
- Procurement and inventory cover operational parts and equipment, not a general-purpose accounting ERP.
- Tax and settlement rules are configuration/adapter concerns pending market-specific analysis.

## 12. Unresolved Decisions

- Initial countries, currencies, languages, privacy regimes, tax/invoice rules, and accessibility obligations.
- Initial OCPP versions and profiles; whether ISO 15118/Plug & Charge, reservations, smart charging, and roaming are in scope.
- Charger enrollment and credential rotation model, plus vendor certification requirements.
- Payment providers, merchant-of-record model, preauthorization strategy, offline risk rules, refund policy, and chargeback operations.
- Tenant hierarchy limits, cross-tenant operator use cases, custom domains, data residency, and dedicated-tenant needs.
- Tariff dimensions, rounding order, idle fees, parking fees, subscriptions, promotions, and price-display regulations.
- Settlement beneficiaries, commission model, payout cadence, reserve/fee allocation, and accounting-system integration.
- Inventory costing method, fractional quantity needs, approval thresholds, and supplier integration.
- Maintenance SLA model, preventive schedules, remote diagnostics, warranty handling, and safety sign-off.
- Cloud/region topology, availability objectives, recovery objectives, sizing, retention, and archive strategy.
- Map/geocoding, notification, observability, object-storage, malware-scanning, and analytics vendors.

## 13. Architecture Summary

VTSA CSMS uses a Laravel modular monolith for core business capabilities and operator/public APIs, a Flutter mobile client, and a separately deployable OCPP gateway for long-lived charger connections and protocol normalization. PostgreSQL/PostGIS is the authoritative store, Redis supports ephemeral coordination, and an outbox/event model connects bounded contexts and reporting projections. Every durable concept has one owning context. Tenant context, authorization, auditing, ULIDs, canonical units, UTC timestamps, idempotency, and versioned contracts are platform-wide invariants.

See [`../architecture/system-context.md`](../architecture/system-context.md), [`../architecture/container-architecture.md`](../architecture/container-architecture.md), and the ADRs in [`../architecture/decisions/`](../architecture/decisions/).

## 14. Risks

| Risk | Potential effect | Architectural response |
| --- | --- | --- |
| Charger/vendor protocol variation | Failed or incomplete sessions | Dedicated gateway, conformance fixtures, versioned normalized messages, certification matrix |
| Tenant leakage | Severe privacy/commercial incident | Mandatory tenant scope, policy enforcement, tenant-aware indexes/cache/jobs, isolation tests, audit |
| Duplicate or reordered distributed messages | Duplicate commands, charges, or inconsistent state | Idempotency keys, inbox/outbox, provider identifiers, state-machine guards, reconciliation |
| Tariff ambiguity and rounding | Disputes and financial loss | Immutable tariff versions, integer minor units, explicit rounding stages, calculation breakdown |
| Provider or network outage | Charging/payment degradation | Timeouts, circuit breakers, queued retries, safe failure modes, reconciliation, provider abstraction |
| Incomplete session/meter data | Incorrect billing | Data-quality flags, provisional/final states, late-data policy, operator exception workflow |
| Scaling long-lived charger connections | Availability or latency issues | Independently scalable gateway, connection ownership/leases, load testing, backpressure |
| Overgrown modular monolith | Tight coupling and slow delivery | Enforced data ownership, module APIs, architecture tests, ADR-governed extraction |
| Regulatory uncertainty | Rework or market-entry delay | Market requirements before implementation, configuration boundaries, legal review checkpoints |
| Stock/asset identity mismatch | Maintenance delays and inaccurate inventory | Serialized custody, immutable stock ledger, controlled handoff to Assets, cycle counts |

## 15. Open Decisions

The unresolved decisions in section 12 are tracked as product gates. A decision is closed only by an approved ADR, requirements update, or market-specific policy artifact with an owner and effective date. No developer should fill these gaps with an undocumented default.

Priority decisions before implementation are: target market and regulatory baseline; supported OCPP profiles; tenancy/commercial hierarchy; payment and merchant model; tariff/rating rules; identity provider and privileged-access model; expected scale/SLOs; and deployment/region strategy.

## 16. Proposed Repository Layout

```text
/
├── AGENTS.md
├── apps/
│   ├── platform/                 # Laravel modular monolith and web/API entrypoints
│   │   ├── app/Modules/<Context>/
│   │   │   ├── Application/
│   │   │   ├── Domain/
│   │   │   ├── Infrastructure/
│   │   │   └── Presentation/
│   │   ├── resources/            # Blade, Livewire, Tailwind, localization
│   │   └── tests/
│   ├── ocpp-gateway/             # Separate protocol edge and core contract client
│   └── mobile/                   # Flutter consumer application
├── contracts/
│   ├── events/                   # Versioned integration-event schemas
│   ├── gateway/                  # OCPP gateway/core commands and responses
│   └── public-api/               # OpenAPI and webhook schemas
├── docs/
│   ├── product/
│   ├── architecture/
│   │   └── decisions/
│   ├── operations/
│   └── security/
├── infrastructure/              # Environment definitions after deployment ADRs
├── packages/                     # Only genuinely shared, context-neutral packages
└── tools/                        # Local quality, generation, and verification tooling
```

The layout is a proposal, not an instruction to scaffold applications during Phase 0.

## 17. Acceptance Checklist

- [x] Product scope covers public web/map, Flutter app, Laravel platform, OCPP gateway, charging/tariffs, finance, procurement/inventory, and maintenance/assets.
- [x] Phase 0 explicitly excludes business-feature implementation.
- [x] All 18 bounded contexts have a stated responsibility and data owner.
- [x] Money, energy, power, duration, timestamps, coordinates, and public ID conventions are explicit.
- [x] Roles, personas, and scoped access expectations are documented.
- [x] Functional and non-functional requirements use stable identifiers.
- [x] System context, container boundaries, and trust/dependency boundaries are documented.
- [x] Charging, payment, inventory, and maintenance state machines define terminal and exception behavior.
- [x] Cross-context integration events have ownership and delivery conventions.
- [x] Security and multi-tenancy models cover web, mobile, admin, gateway, workers, cache, storage, and reporting.
- [x] ADRs cover the modular monolith, separate OCPP gateway, PostgreSQL/PostGIS, Redis, Flutter, and payment abstraction.
- [x] Assumptions, risks, dependencies, and unresolved decisions are explicit without invented credentials or vendor behavior.
- [x] Root engineering standards cover coding, testing, security, documentation, and migrations.
- [ ] Product, security, operations, finance, and engineering stakeholders approve the baseline.
- [ ] Open implementation gates receive owners and target dates before Phase 1 starts.
