# Roles and Personas

**Status:** Phase 0 baseline  
**Related:** [`PRD.md`](PRD.md), [`../architecture/security-model.md`](../architecture/security-model.md), [`../architecture/tenancy-model.md`](../architecture/tenancy-model.md)

## 1. Access Model

VTSA CSMS uses role-based permissions constrained by attributes and scope:

- **role** describes an allowed job function;
- **tenant** is the mandatory commercial data boundary;
- **organization scope** limits access to one or more operating/legal organizations;
- **resource scope** may further limit access by location, asset group, warehouse, support queue, or financial account;
- **relationship and state** constrain actions such as a driver viewing their own session or a technician updating an assigned work order;
- **high-risk conditions** may require MFA freshness, step-up authentication, dual approval, or separation of duties.

Roles are composable permission bundles, not hard-coded columns on a user. A user may hold different roles in different tenants or organizations. Access is deny-by-default, evaluated server-side, and audited. Platform support access is separate from tenant membership and never implies silent impersonation.

## 2. External Personas

### Public visitor

**Goal:** Learn about VTSA services and find a suitable public charging location.  
**Needs:** Fast accessible marketing pages; map/list search; connector, access, amenity, availability, and tariff summaries; privacy choices; app deep links.  
**Constraints:** Sees only explicitly published data. Has no tenant administration or non-public operational data.

### Driver

**Goal:** Reliably start, monitor, stop, and pay for their own charging.  
**Needs:** Account/security controls, saved preferences, map and directions, clear pricing, compatible connector details, live session updates, payment token management, receipts/invoices, notifications, and support.  
**Constraints:** May see and act only on their identity, authorized vehicles/tokens, payment references, and sessions. Remote actions require a valid charging authorization and current session relationship.

### Guest driver

**Goal:** Complete a legally permitted ad-hoc charging journey without a full account.  
**Needs:** Minimal identification, price disclosure, provider-hosted payment, session status/recovery, and receipt retrieval.  
**Constraints:** Whether guest charging is supported is an open market decision. Access must be short-lived and strongly bound to a specific transaction.

### Partner integration client

**Goal:** Exchange approved operational or financial information with VTSA CSMS.  
**Needs:** Versioned APIs/events, scoped machine credentials, idempotency, clear errors, sandbox/test data, and integration health.  
**Constraints:** Has no interactive human privileges. Each client is restricted to explicit tenant, resource, action, IP/network policy where adopted, and rate limits.

### Supplier contact

**Goal:** Receive and respond to purchase-order or delivery communications where enabled.  
**Needs:** Clear order references and controlled document exchange.  
**Constraints:** No general platform access by default. A supplier portal is not assumed in scope.

## 3. Tenant Roles

### Tenant owner

**Goal:** Establish the tenant and delegate administration safely.  
**Typical permissions:** Tenant settings, organizations, entitlements view, highest tenant role assignment, security policy, audit/export requests, and approved integrations.  
**Controls:** MFA and recent authentication; cannot defeat platform policy; destructive or financial actions may require dual approval.

### Tenant administrator

**Goal:** Manage people, operating structure, locations, assets, and configuration.  
**Typical permissions:** Memberships below owner, organization/location scopes, asset configuration, tenant operational settings, notification/CMS settings.  
**Controls:** Cannot grant permissions they do not possess. No finance, settlement, or secret viewing unless separately assigned.

### Organization administrator

**Goal:** Administer one operating organization or delegated branch.  
**Typical permissions:** Scoped memberships, locations, asset views, and operational reports.  
**Controls:** Organization and resource scopes are mandatory; cannot alter the tenant boundary.

### Network operations controller

**Goal:** Keep chargers connected, available, and safe.  
**Typical permissions:** Fleet status, diagnostics, alarms, session lookup, permitted remote commands, operational notes, and fault escalation.  
**Controls:** High-impact commands require reason capture, current asset/session checks, and audit. Firmware behavior and vendor extensions require approved support.

### Location manager

**Goal:** Operate assigned charging sites.  
**Typical permissions:** Location hours/access/amenities, assigned asset and session views, local incident reporting, and local performance reports.  
**Controls:** Only assigned locations; tariff publication and financial access are separate permissions.

### Tariff manager

**Goal:** Define transparent, effective-dated charging prices.  
**Typical permissions:** Draft/version tariffs, simulate calculations, propose publication, and view resulting rated charges.  
**Controls:** Published versions are immutable. Approval may be separate from drafting. Currency and market constraints apply.

### Finance operator

**Goal:** Resolve payment, invoice, reconciliation, and customer balance exceptions.  
**Typical permissions:** Provider transaction references, payment attempts, invoices/credit notes, refunds within limits, reconciliation cases, and exports.  
**Controls:** No raw card data; refund/adjustment limits and reason codes; audited exports; settlement approval is separately assignable.

### Settlement analyst

**Goal:** Match expected funds to provider reports and prepare beneficiary statements.  
**Typical permissions:** Reconciliation runs, fees, payout imports, allocation exceptions, and settlement statement preparation.  
**Controls:** Preparation and approval should be separable; cannot change source payment/session facts.

### Settlement approver

**Goal:** Approve a controlled settlement after reviewing evidence and exceptions.  
**Typical permissions:** Review, approve/reject, release-to-external-process where implemented.  
**Controls:** MFA/step-up, approval thresholds, separation from preparer, immutable audit. Bank details are not defined by this baseline.

### Procurement requester

**Goal:** Request parts or equipment needed for operations.  
**Typical permissions:** Create requisitions, attach specifications, track status, and acknowledge delivery needs.  
**Controls:** Cost-center/organization scope and approval thresholds.

### Procurement buyer

**Goal:** Source approved needs and manage purchase orders.  
**Typical permissions:** Suppliers, quotes metadata, purchase orders, delivery expectations, and approved changes.  
**Controls:** Cannot approve their own purchase beyond policy; cannot directly edit stock balances.

### Procurement approver

**Goal:** Approve purchasing commitments within delegated limits.  
**Typical permissions:** Review/reject requisitions and purchase orders.  
**Controls:** Thresholds, organization scope, separation of duties, reasoned audit.

### Warehouse operator

**Goal:** Keep physical stock accurate and available.  
**Typical permissions:** Receive against approved orders, put away, reserve/issue, transfer, count, quarantine, and record discrepancies.  
**Controls:** All quantity changes create immutable ledger movements. Adjustments above policy require approval.

### Inventory controller

**Goal:** Govern catalog, stock accuracy, counts, and adjustments.  
**Typical permissions:** Item/warehouse configuration, count approval, discrepancy resolution, adjustment approval, and valuation reports if configured.  
**Controls:** Cannot rewrite posted ledger entries; corrections use compensating movements.

### Maintenance dispatcher

**Goal:** Turn faults and preventive needs into planned work.  
**Typical permissions:** Triage, prioritize, assign, schedule, reserve parts, and manage SLA status.  
**Controls:** Scoped assets and teams; cannot certify work they performed if policy requires independent verification.

### Field technician

**Goal:** Diagnose and repair assigned assets efficiently and safely.  
**Typical permissions:** Assigned work orders, checklists, notes, evidence upload, time, used/returned parts, and completion request.  
**Controls:** Limited asset commands; safety-critical return-to-service may require verifier approval; no unrestricted inventory adjustment.

### Maintenance verifier

**Goal:** Confirm completed work and authorize closure/return-to-service under policy.  
**Typical permissions:** Review evidence, test outcomes, accept/reject completion, and reopen.  
**Controls:** Separation from technician where required; reason required for override.

### Support agent

**Goal:** Resolve driver and operator questions without excessive data access.  
**Typical permissions:** Scoped case queue, customer/session lookup with masking, communication, categorization, and escalation.  
**Controls:** Just-in-time reveal for sensitive data; no raw payment data; refunds/remote commands require separately granted workflows.

### Support supervisor

**Goal:** Manage queues, escalations, SLAs, and quality.  
**Typical permissions:** Reassignment, escalation, case audits, selected exceptions, and support reports.  
**Controls:** Tenant/queue scope and audited sensitive access.

### Content editor

**Goal:** Maintain accurate public marketing and help content.  
**Typical permissions:** Draft CMS pages/media references, previews, and localization.  
**Controls:** Cannot publish unless granted publisher permission; no operational access by implication.

### Content publisher

**Goal:** Review and release public content.  
**Typical permissions:** Approve, schedule, publish, unpublish, and roll back content versions.  
**Controls:** Publication audit and separation from editing where configured.

### Reporting analyst

**Goal:** Analyze tenant performance using governed measures.  
**Typical permissions:** Dashboards, filtered reports, scheduled exports, and approved aggregates.  
**Controls:** Row/column security, export limits, watermark/audit where applicable, eventual-consistency disclosure.

### Tenant auditor

**Goal:** Independently review activity and controls.  
**Typical permissions:** Read-only configuration, histories, evidence, and audit reports.  
**Controls:** No mutation; time-bounded assignment; sensitive fields remain masked unless explicitly authorized.

## 4. Platform Roles

### Platform operations administrator

**Goal:** Operate the shared VTSA service and tenant lifecycle.  
**Typical permissions:** Tenant provisioning/suspension workflow, platform feature controls, service health, queue/retry operations, and incident response.  
**Controls:** Separate privileged identity, MFA, just-in-time elevation, reason/ticket, session recording where available, and complete audit. No default access to tenant business data.

### Platform security administrator

**Goal:** Govern authentication policy, privileged access, security monitoring, and incident containment.  
**Controls:** Cannot silently alter business/financial records. Emergency access is time-bound and reviewed.

### Platform support engineer

**Goal:** Diagnose cross-service technical issues.  
**Typical permissions:** Redacted telemetry, correlation traces, gateway connection metadata, and controlled tenant-assisted diagnostics.  
**Controls:** No standing sensitive-data access; tenant access requires approval/reason and is visibly audited.

### Service account

**Goal:** Perform one machine-to-machine function.  
**Controls:** Non-human identity, least-privilege scopes, credential rotation, expiry/revocation, no interactive login, usage monitoring, and named owner.

## 5. Separation-of-Duties Baseline

The permission model must be able to prevent these combinations or require a second actor:

- purchase-order creator and approver above a configured threshold;
- settlement preparer and final approver;
- inventory adjustment requester and approver above a configured threshold;
- tariff drafter and publisher where tenant policy requires review;
- technician and maintenance verifier for safety-critical work;
- refund requester and approver above a configured threshold;
- platform privileged-access requester and reviewer.

Exact thresholds are tenant/market policy decisions, not hard-coded assumptions.

## 6. Open Decisions

- Whether drivers and tenant workforce share an identity realm or use separate realms with account linking.
- Identity provider, enterprise SSO protocols, MFA methods, and recovery policy.
- Guest/ad-hoc driver requirements and legal identity evidence by market.
- Delegated administration depth and whether one user may operate across unrelated tenants.
- Mandatory separation-of-duty combinations and financial approval thresholds.
- Emergency access workflow, review interval, and tenant notification obligations.
- Contractor/supplier/roaming roles beyond the personas above.
