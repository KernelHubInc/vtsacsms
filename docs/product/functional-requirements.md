# Functional Requirements

**Status:** Baseline for refinement; not implementation authorization  
**Notation:** `MUST` is required, `SHOULD` is expected unless an ADR or approved requirement changes it, and `MAY` is optional.

## 1. Cross-cutting Platform Requirements

- **FR-PLT-001:** The platform MUST issue ULIDs for public and cross-context identifiers and MUST NOT expose sequential database identifiers.
- **FR-PLT-002:** The platform MUST represent monetary amounts as integer minor units with an ISO 4217 currency code.
- **FR-PLT-003:** The platform MUST represent energy in watt-hours, power in watts, durations in seconds, and instants in UTC.
- **FR-PLT-004:** The platform MUST capture tenant, actor, action, resource, timestamp, correlation ID, reason where required, and before/after-safe metadata for auditable changes.
- **FR-PLT-005:** The platform MUST support idempotency for externally retried mutations, webhooks, gateway messages, and asynchronous consumers.
- **FR-PLT-006:** The platform MUST use versioned public APIs and integration-event contracts.
- **FR-PLT-007:** The platform MUST expose consistent validation and error codes without disclosing internal or cross-tenant information.
- **FR-PLT-008:** The platform MUST enforce server-side permissions and tenant/resource scope on every protected action.
- **FR-PLT-009:** The platform MUST provide correlation across browser/mobile requests, jobs, OCPP messages, provider callbacks, and audit records.
- **FR-PLT-010:** The platform SHOULD support localization, IANA timezones, accessible interfaces, and tenant-approved branding without changing canonical stored units.

## 2. Public Website and Map

- **FR-PUB-001:** Visitors MUST be able to view published marketing, legal, support, and charging-network content without authentication.
- **FR-PUB-002:** Visitors MUST be able to search and browse published charging locations in map and list views using a geographic area and supported filters.
- **FR-PUB-003:** Public location results MUST show only publishable fields, including access information, compatible connectors, availability freshness, amenities, and a tariff summary where applicable.
- **FR-PUB-004:** Location data MUST distinguish live/estimated/stale/unknown availability and show the observation time.
- **FR-PUB-005:** Public pages SHOULD support deep links into the consumer app while retaining a web fallback.
- **FR-PUB-006:** Search and location pages MUST remain usable with keyboard navigation and without relying on color alone.
- **FR-PUB-007:** The public surface MUST honor consent and privacy choices for non-essential analytics or marketing technology.

## 3. Consumer Mobile App

- **FR-MOB-001:** A driver MUST be able to register/sign in, recover access, manage profile/preferences, and secure the account according to the Identity policy.
- **FR-MOB-002:** A driver MUST be able to discover locations/connectors and view compatibility, access, availability freshness, and applicable price disclosures.
- **FR-MOB-003:** The app MUST support a configured authorization method for starting a session and MUST present an actionable failure reason when initiation fails.
- **FR-MOB-004:** A driver MUST be able to view live canonical session state, elapsed duration seconds, energy watt-hours, estimated cost in minor units/currency, and data freshness.
- **FR-MOB-005:** A driver MUST be able to request a stop for their own active session where platform and charger capability permit.
- **FR-MOB-006:** The app MUST recover session state after process termination, connectivity loss, duplicate requests, or device change.
- **FR-MOB-007:** A driver MUST be able to manage provider-tokenized payment method references without VTSA CSMS handling raw card data.
- **FR-MOB-008:** A driver MUST be able to view their finalized session history and applicable receipts/invoices/credit notes.
- **FR-MOB-009:** A driver MUST be able to set notification preferences subject to mandatory transactional/legal communications.
- **FR-MOB-010:** A driver MUST be able to open a support case linked to their own location/session/payment record.
- **FR-MOB-011:** Guest/ad-hoc charging MUST NOT be implemented until its market, identity, payment, and receipt requirements are approved.

## 4. Identity

- **FR-IDN-001:** Identity MUST manage human and machine identities, credential lifecycle, sign-in sessions, verification, recovery, and revocation.
- **FR-IDN-002:** Privileged workforce roles MUST support MFA and recent-authentication checks.
- **FR-IDN-003:** Identity MUST publish stable subject identifiers without exposing credential data to other contexts.
- **FR-IDN-004:** Administrators MUST be able to revoke sessions and machine credentials within their permitted scope.
- **FR-IDN-005:** Authentication and recovery events MUST be auditable and security-notifiable without logging secrets.

## 5. Tenancy

- **FR-TEN-001:** Platform operators MUST be able to create, activate, suspend, and close tenants through audited lifecycle transitions.
- **FR-TEN-002:** Every tenant-owned record and operation MUST resolve exactly one tenant context before data access.
- **FR-TEN-003:** Tenancy MUST manage tenant policy defaults, enabled capabilities/entitlements, locale, and default timezone/currency settings without overriding record-specific currency/timezone facts.
- **FR-TEN-004:** Suspending a tenant MUST block new tenant operations while preserving controlled access required for incident, export, billing, or closure policy.
- **FR-TEN-005:** Tenant data export/deletion requests MUST be authorized, auditable, resumable, and constrained by retention/legal-hold policy.

## 6. Organizations

- **FR-ORG-001:** A tenant MUST be able to model legal and operating organizations with a hierarchy that prevents cycles.
- **FR-ORG-002:** Administrators MUST be able to invite, activate, scope, and revoke workforce memberships and role assignments.
- **FR-ORG-003:** Permission grants MUST be constrained by the grantor's permissions and tenant/organization/resource scope.
- **FR-ORG-004:** Organization history and membership/role changes MUST be audited.
- **FR-ORG-005:** The system SHOULD support teams for operational assignment without treating teams as security boundaries unless explicitly configured.

## 7. Locations

- **FR-LOC-001:** Authorized users MUST be able to create and maintain locations, addresses, WGS 84 coordinates, IANA timezone, access instructions, hours, amenities, and publication state.
- **FR-LOC-002:** The system MUST validate location geography and support PostGIS proximity/bounds queries.
- **FR-LOC-003:** Publication MUST require a minimum complete and valid set of public fields and MUST retain version/audit history.
- **FR-LOC-004:** Local hours and closures MUST retain the location timezone while resolved instants are handled in UTC.
- **FR-LOC-005:** Location removal MUST preserve references required by historical sessions, billing, settlements, and maintenance.

## 8. Assets

- **FR-AST-001:** Assets MUST model charger, EVSE, connector, and replaceable/serialized component relationships with tenant ownership and physical location history.
- **FR-AST-002:** Charger and connector identifiers MUST be unique in the appropriate tenant/protocol scope and changes MUST not corrupt historical sessions.
- **FR-AST-003:** Authorized users MUST be able to commission, place in service, restrict, retire, or decommission assets through controlled transitions.
- **FR-AST-004:** Assets MUST own manufacturer/model/serial references, capability declarations, warranty/service metadata, and current lifecycle/operational status.
- **FR-AST-005:** Moving or replacing an asset MUST preserve custody, configuration, commissioning, and historical work/session associations.
- **FR-AST-006:** Vendor-specific configuration or behavior MUST require a documented integration profile; generic fields MUST not silently encode a vendor assumption.

## 9. Charging and OCPP Operations

- **FR-CHG-001:** The gateway MUST authenticate a charger connection, negotiate only supported OCPP subprotocols, validate messages, and associate the connection with one registered asset.
- **FR-CHG-002:** The gateway MUST normalize supported OCPP messages into versioned core contracts while retaining protocol message references for diagnostics.
- **FR-CHG-003:** The platform MUST track connection freshness and projected charger/EVSE/connector availability separately from authoritative asset lifecycle state.
- **FR-CHG-004:** Authorized operators MUST be able to issue supported remote commands with tenant scope, permission, reason where required, unique command ID, timeout, correlation, and result history.
- **FR-CHG-005:** The platform MUST authorize charging starts against driver/token, tenant, asset state, access policy, tariff availability, and payment policy selected for the market.
- **FR-CHG-006:** Charging MUST create and advance a canonical session using the documented state machine and reject invalid transitions.
- **FR-CHG-007:** Start/stop/transaction messages and meter samples MUST be idempotent by source identity/message keys and tolerate late or reordered delivery.
- **FR-CHG-008:** Meter readings MUST retain source timestamp, received timestamp, measurand/unit conversion evidence, register/context metadata, and data-quality flags.
- **FR-CHG-009:** The platform MUST calculate session energy from trustworthy readings in watt-hours without replacing raw normalized evidence.
- **FR-CHG-010:** Finalization MUST record stop reason, final readings/data quality, actual duration seconds, applied tariff version reference, and any exception requiring review.
- **FR-CHG-011:** An incomplete session MUST enter an exception/finalization workflow; the platform MUST NOT fabricate charger-specific readings or stop behavior.
- **FR-CHG-012:** The gateway and core MUST expose connection/command/session telemetry without storing credentials or personal data in logs.
- **FR-CHG-013:** Reservations, smart charging, firmware management, ISO 15118, and roaming are deferred until separately approved.

## 10. Tariffs

- **FR-TAR-001:** Authorized users MUST be able to draft versioned tariffs with currency, dimensions, schedules, eligibility, tax treatment reference, and effective interval.
- **FR-TAR-002:** Published tariff versions MUST be immutable; changes create a new version with non-ambiguous effective timing.
- **FR-TAR-003:** Tariff evaluation MUST select a single applicable version or produce an explicit conflict/no-tariff error.
- **FR-TAR-004:** Rating MUST use integer minor units, watt-hours, watts, and seconds with an explicit rounding order and itemized calculation evidence.
- **FR-TAR-005:** Authorized users MUST be able to simulate a tariff against sample usage before publication.
- **FR-TAR-006:** Customer-facing price disclosure MUST be derived from the same versioned tariff semantics used for billing, subject to applicable market presentation rules.
- **FR-TAR-007:** Subscriptions, promotions, parking/idle fees, demand pricing, and tax-inclusive/exclusive rules remain pending detailed decisions.

## 11. Payments

- **FR-PAY-001:** Payments MUST expose a provider-neutral contract for customer/token references, payment intents, authorization, capture, cancellation, refund, dispute, and status retrieval.
- **FR-PAY-002:** The platform MUST NOT store CVV or raw PAN and MUST use provider-hosted/tokenized payment collection.
- **FR-PAY-003:** Every payment mutation MUST have an idempotency key and retain provider/account/reference/status history.
- **FR-PAY-004:** Provider webhooks MUST be signature-verified against the raw request, replay-protected, stored as safe receipt evidence, and processed idempotently.
- **FR-PAY-005:** Payments MUST follow the documented state machine and MUST reject or flag impossible/regressive transitions.
- **FR-PAY-006:** Capture and refund amounts MUST never exceed the permitted authorized/captured balance; all amounts use minor units and currency consistency checks.
- **FR-PAY-007:** Timeouts and ambiguous provider responses MUST enter a retrieval/reconciliation path before another financial attempt is made.
- **FR-PAY-008:** Authorized finance users MUST be able to request reasoned refunds within policy; larger/high-risk actions MAY require a second approver.
- **FR-PAY-009:** Payment method/provider changes MUST not rewrite historical provider references or financial facts.
- **FR-PAY-010:** Offline authorization, preauthorization amount policy, provider routing, and merchant-of-record behavior remain market decisions.

## 12. Billing

- **FR-BIL-001:** Billing MUST create an immutable rated-charge record that references the source session, applied tariff version, calculation breakdown, currency, and finalization status.
- **FR-BIL-002:** Billing MUST issue legally required invoice/receipt artifacts only after market-specific numbering, tax, seller, buyer, and timing rules are approved.
- **FR-BIL-003:** Finalized invoices MUST not be edited; corrections use credit notes, debit adjustments where legal, or replacement documents with explicit links.
- **FR-BIL-004:** Billing MUST prevent duplicate charge/invoice creation for the same source and version.
- **FR-BIL-005:** Drivers and authorized finance staff MUST be able to retrieve documents within retention and access policy.
- **FR-BIL-006:** Billing MUST expose account balances and allocation evidence without owning provider payment execution.

## 13. Settlements

- **FR-SET-001:** Settlements MUST import or receive provider transaction, fee, payout, and adjustment reports through a versioned adapter with source-file/message identity.
- **FR-SET-002:** Reconciliation MUST match source payments/refunds/disputes to provider records using deterministic rules and identify unmatched, duplicate, amount, currency, and timing exceptions.
- **FR-SET-003:** The system MUST preserve expected, provider-reported, fee, net, and difference amounts separately in minor units/currency.
- **FR-SET-004:** Settlement allocation MUST reference immutable source financial records and configured commercial rules effective for the source period.
- **FR-SET-005:** Authorized users MUST be able to investigate, explain, resolve, reopen, and audit reconciliation exceptions without rewriting source records.
- **FR-SET-006:** Settlement preparation and approval MUST support separation of duties and policy thresholds.
- **FR-SET-007:** Bank instruction generation or payout execution MUST remain disabled until beneficiary verification, provider, approval, security, and legal requirements are defined.

## 14. Procurement

- **FR-PRC-001:** Procurement MUST manage tenant-scoped supplier records, requisitions, approval decisions, purchase orders, revisions, and delivery expectations.
- **FR-PRC-002:** Requisitions and purchase orders MUST use integer minor units/currency and preserve approved price/version evidence.
- **FR-PRC-003:** Approval MUST enforce organization scope, thresholds, and separation-of-duty policy.
- **FR-PRC-004:** Issued purchase-order commitments MUST be versioned; material changes require an approved revision rather than silent overwrite.
- **FR-PRC-005:** Procurement MUST communicate expected receipts to Inventory but MUST NOT directly change on-hand stock.
- **FR-PRC-006:** Partial delivery, over/under receipt, rejection, cancellation, and supplier return MUST be represented explicitly.
- **FR-PRC-007:** Accounts-payable execution and supplier banking data are outside this baseline pending integration and security decisions.
- **FR-PRC-008:** A purchase request MUST identify its department, cost center, requester, line items, required date, integer-minor-unit estimate, currency, revision, and approval evidence.
- **FR-PRC-009:** Approval matrices MUST select ordered approval steps by tenant, document type, scope, currency, and amount threshold; approvers MUST hold the exact configured role and MUST NOT approve their own request.
- **FR-PRC-010:** Sourcing MUST support RFQ issue, invited suppliers, immutable quotation revisions, normalized quotation comparison, selected quotation evidence, and conversion to an approved purchase order.
- **FR-PRC-011:** Vendor invoices MUST support line-level, currency-safe three-way matching to the approved PO revision and posted goods receipts, including quantity, price, and tax discrepancies.
- **FR-PRC-012:** An unresolved match discrepancy MUST enter an auditable finance review workflow and MUST block accounting export until resolved or explicitly accepted by an authorized reviewer.
- **FR-PRC-013:** Accounting export MUST use a versioned adapter, idempotency key, UTC status evidence, and safe provider reference; it MUST NOT contain supplier bank credentials.

## 15. Inventory

- **FR-INV-001:** Inventory MUST manage item catalog references, units of measure, warehouses/bins, lots where required, and serialized stock custody.
- **FR-INV-002:** Every on-hand quantity change MUST post an immutable, balanced stock-ledger movement with tenant, item, location, quantity, reason, source, actor, and UTC timestamp.
- **FR-INV-003:** Inventory MUST support controlled receipts, put-away, reservations, issues/consumption, returns, transfers, quarantine/release, adjustments, and write-offs.
- **FR-INV-004:** Reservation, transfer, count, and serialized-item lifecycles MUST follow the documented state machines.
- **FR-INV-005:** The platform MUST prevent negative available stock unless an explicit tenant policy and approved exception allows it.
- **FR-INV-006:** Cycle/stock counts MUST freeze or account for concurrent movements and post only approved variances.
- **FR-INV-007:** Parts issued to maintenance MUST reference the work order; unused parts returned MUST create a compensating movement.
- **FR-INV-008:** A serialized charger/component transferred into operational service MUST hand off authoritative asset identity/custody to Assets through an explicit contract.
- **FR-INV-009:** Each item MUST use the tenant-configured moving-average or standard-cost valuation method; money MUST remain integer minor units with an ISO 4217 currency code. Fractional stock, landed costs, and currency conversion remain disabled until product/finance approval.
- **FR-INV-010:** Available stock MUST be derived from immutable custody movements less active reservations; the platform MUST NOT persist or directly update a quantity-on-hand field.
- **FR-INV-011:** Transfers MUST post dispatch into an explicit in-transit custody bin and post destination receipt from that bin, preserving partial quantities and discrepancies.
- **FR-INV-012:** Goods receipt inspection MUST route accepted and rejected quantities to distinct custody bins and preserve PO-line, lot, serial, actor, correlation, and idempotency evidence.
- **FR-INV-013:** Physical and cycle counts MUST support plans, blind count sheets, recounts, approval, and movement-backed variance adjustments.
- **FR-INV-014:** Inventory APIs, reports, imports, exports, jobs, and Filament resources MUST intersect tenant scope, permission, and the actor's authorized warehouses.
- **FR-INV-015:** Reorder reporting MUST compare accessible available stock with configured minimum and target quantities without creating procurement commitments automatically.

## 16. Maintenance

- **FR-MNT-001:** Authorized users and integrations MUST be able to report faults with asset/location, severity evidence, observation time, and source.
- **FR-MNT-002:** Maintenance MUST create, triage, prioritize, plan, schedule, assign, and track corrective/preventive work orders using the documented state machine.
- **FR-MNT-003:** Work orders MUST retain issue, diagnosis, actions, safety checks, labor duration seconds, parts reservations/consumption, attachments, downtime, and completion evidence.
- **FR-MNT-004:** Maintenance MUST coordinate asset restriction/return-to-service through the Assets contract; it MUST NOT directly rewrite asset state.
- **FR-MNT-005:** Dispatchers MUST be able to pause work with an explicit reason such as awaiting parts, site access, external service, or safety clearance.
- **FR-MNT-006:** Completion and verification MUST be separable for configured safety-critical work.
- **FR-MNT-007:** Closed/canceled work MUST remain terminal; a recurrence MUST create a new reasoned, audited `REPORTED` work order linked to the prior order without changing prior completion evidence.
- **FR-MNT-008:** Preventive schedules MUST support idempotent UTC-date, runtime-second, charging-session-count, and watt-hour checkpoints while preserving the source fact and plan checkpoint.
- **FR-MNT-009:** Maintenance MUST retain warranty, RMA, vendor-repair, inspection, approval, claim, and recovered-minor-unit evidence; production vendor/accounting adapters and legal warranty terms remain open decisions.
- **FR-MNT-010:** Selected normalized OCPP fault codes MAY open an incident; duplicate observations MUST NOT create duplicate active incidents, and resolution MUST require a configured stable recovery interval.
- **FR-MNT-011:** SLA targets, approved pause evidence, breach notification, and priority/version history MUST use UTC instants and integer seconds without inventing tenant response times.
- **FR-MNT-012:** Maintenance dashboards MUST govern open work, breach, downtime, availability, MTTA, MTTR, repeat failure, cost, part consumption, and warranty-recovery definitions and MUST intersect the actor's site scope.

## 17. Notifications

- **FR-NOT-001:** Notifications MUST own versioned templates, recipient preferences, rendering, channel selection, delivery attempts, and provider results.
- **FR-NOT-002:** Source contexts MUST request a semantic notification without formatting provider-specific messages.
- **FR-NOT-003:** The system MUST distinguish mandatory transactional/security messages from optional marketing communications.
- **FR-NOT-004:** Notification sends MUST be idempotent, rate controlled, localized where supported, and auditable without logging sensitive message bodies unnecessarily.
- **FR-NOT-005:** Provider failures MUST retry safely and surface terminal failure for operational follow-up.

## 18. Support

- **FR-SUP-001:** Support MUST manage tenant-scoped cases, category, priority, status, SLA evidence, assignee/queue, participants, and correspondence.
- **FR-SUP-002:** Cases MAY link to identity, location, asset, session, payment, invoice, settlement exception, or work order using public references without taking ownership of those records.
- **FR-SUP-003:** Support users MUST see only policy-approved/masked data and sensitive reveals MUST be justified and audited.
- **FR-SUP-004:** Refunds, remote commands, identity changes, and financial corrections initiated from support MUST invoke the owning context's controlled workflow.
- **FR-SUP-005:** Attachments MUST be authorized, malware-scanned by a selected mechanism, size/type constrained, and retained according to policy.

## 19. Reporting

- **FR-RPT-001:** Reporting MUST build read-only, tenant-scoped projections from owned data contracts/events and MUST NOT become an operational source of truth.
- **FR-RPT-002:** Metrics MUST have governed definitions, units, timezone/currency treatment, freshness, and lineage.
- **FR-RPT-003:** Authorized users MUST be able to filter, paginate, and export approved reports within row/column permissions and resource limits.
- **FR-RPT-004:** Reports MUST disclose eventual-consistency/freshness and distinguish estimated, provisional, final, and corrected facts.
- **FR-RPT-005:** Large exports MUST run asynchronously, expire, be encrypted/access-controlled, and be audited.

## 20. CMS

- **FR-CMS-001:** CMS MUST manage tenant/platform-scoped pages, navigation, approved media references, localization, revisions, previews, and publish schedules.
- **FR-CMS-002:** Draft, review, publish, unpublish, and rollback actions MUST be authorized and audited.
- **FR-CMS-003:** CMS content MUST be sanitized and MUST not execute untrusted script.
- **FR-CMS-004:** Operational location/availability/tariff facts MUST remain owned by their contexts and be composed into public pages, not copied into editable CMS truth.

## 21. Integrations

- **FR-INT-001:** Integrations MUST manage partner/API-client identity, tenant/resource scopes, version, status, rate policy, ownership, and credential rotation/revocation metadata.
- **FR-INT-002:** Outbound webhooks MUST be signed, uniquely identified, retried with backoff, observable, and manually replayable only with authorization and audit.
- **FR-INT-003:** Inbound messages/files MUST be authenticated, schema validated, idempotent, quarantined on unsafe failure, and traceable to source.
- **FR-INT-004:** Mapping/transformation versions MUST be retained so historical imports can be reproduced or explained.
- **FR-INT-005:** Integrations MUST not bypass domain authorization, state machines, validation, or tenant boundaries.
- **FR-INT-006:** Secrets MUST be stored only through the selected secret-management mechanism and never returned after creation.

## 22. Admin and Operator Experience

- **FR-ADM-001:** The Laravel platform MUST present navigation and actions based on effective permissions and scope while enforcing the same controls server-side.
- **FR-ADM-002:** Operational lists MUST support search/filtering, stable pagination, freshness/state indicators, empty/loading/error states, and accessible keyboard interaction.
- **FR-ADM-003:** Risky actions MUST show target, expected effect, permission, reason, and confirmation; asynchronous results MUST remain traceable after navigation.
- **FR-ADM-004:** Audit/history views MUST clearly distinguish source facts, derived projections, manual decisions, and corrections.
- **FR-ADM-005:** Users working across scopes MUST always see the active tenant/organization/location context and cannot silently switch scope during a mutation.

## 23. Traceability and Deferred Requirements

Before implementation, each delivery increment must map applicable `FR-*` requirements to user stories, authorization rules, API/event schemas, migrations, threat cases, automated tests, operational dashboards, and an acceptance owner. Deferred/open items remain non-requirements until approved in the PRD or an ADR.
