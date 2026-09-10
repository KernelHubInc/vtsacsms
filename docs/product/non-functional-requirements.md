# Non-functional Requirements

**Status:** Architecture baseline. Numeric service targets require scale and commercial approval.  
**Related:** [`PRD.md`](PRD.md), [`../architecture/security-model.md`](../architecture/security-model.md)

## 1. Availability and Resilience

- **NFR-AVL-001:** Public discovery, driver charging, operator control, gateway connectivity, and financial back-office functions MUST have separately defined SLOs before production release.
- **NFR-AVL-002:** Failure of a non-critical provider such as marketing analytics MUST NOT prevent charging operations.
- **NFR-AVL-003:** A Redis loss or restart MUST NOT lose authoritative business data; durable workflows MUST recover from PostgreSQL/outbox state.
- **NFR-AVL-004:** The gateway MUST tolerate transient core unavailability with bounded buffering/backoff and defined safe behavior; it MUST not claim success before protocol/core evidence supports it.
- **NFR-AVL-005:** External calls MUST use explicit connection/request timeouts, bounded retries with jitter, circuit breaking where appropriate, and idempotency.
- **NFR-AVL-006:** Workers MUST support retry, poison-message isolation, observable dead-letter handling, and authorized replay.
- **NFR-AVL-007:** Recovery point and recovery time objectives, backup frequency, restore testing, multi-region needs, and degraded-mode behavior are release gates, not assumed values.

## 2. Performance and Capacity

- **NFR-PRF-001:** The architecture MUST scale public/API traffic, Laravel workers, reporting work, and OCPP connections independently at their container boundaries.
- **NFR-PRF-002:** Numeric latency targets for public search, live session updates, remote commands, and administrative queries MUST be set from user needs and measured at percentile levels.
- **NFR-PRF-003:** Map queries MUST use bounded geographic requests, spatial indexes, result limits/clustering, and pagination; clients MUST not load an unbounded network estate.
- **NFR-PRF-004:** High-volume meter samples MUST use append-efficient schemas, batching where safe, retention/partition evaluation, and indexes proven against representative load.
- **NFR-PRF-005:** Large reports, imports, exports, reconciliation runs, and backfills MUST be asynchronous, resumable, resource-limited, and must not starve charging workloads.
- **NFR-PRF-006:** A capacity model MUST be approved before production, covering tenants, identities, locations, chargers, concurrent connections, messages/second, sessions/day, meter-sample frequency, storage growth, webhooks, and report concurrency.
- **NFR-PRF-007:** Load and soak tests MUST verify accepted capacity with realistic connection lifetimes, retry storms, hot tenants, and provider degradation.

## 3. Consistency and Data Integrity

- **NFR-DAT-001:** PostgreSQL is authoritative for durable business state. Cross-context propagation is eventually consistent unless a documented synchronous contract is required.
- **NFR-DAT-002:** Business changes and their outbox records MUST commit atomically.
- **NFR-DAT-003:** Consumers MUST provide effectively-once business effects over at-least-once delivery through deduplication and state guards.
- **NFR-DAT-004:** Financial and stock ledgers MUST be append-only after posting; corrections use linked compensating entries.
- **NFR-DAT-005:** Published tariffs, finalized rated charges, invoices, and externally material financial records MUST retain immutable version/evidence references.
- **NFR-DAT-006:** Database constraints MUST enforce essential invariants such as non-null tenant, matching identifier types, legal enum/state values, amount bounds, and tenant-aware uniqueness where practical.
- **NFR-DAT-007:** Time-series/device messages MUST retain source time and received time; clock skew and data quality MUST be visible.
- **NFR-DAT-008:** Data imports and migrations MUST be reconcilable with counts, checksums or equivalent evidence, failure lists, and restart points.

## 4. Multi-tenancy

- **NFR-TEN-001:** Tenant context MUST be mandatory in HTTP, CLI, job, event, cache, websocket/realtime, file, export, and reporting paths.
- **NFR-TEN-002:** Tenant-scoped tables MUST include non-null `tenant_id`, tenant-first access indexes, tenant-aware unique constraints, and access controls described in the tenancy model.
- **NFR-TEN-003:** Cache keys, locks, rate limits, idempotency keys, object paths, metrics dimensions, and search/projection documents MUST be namespaced by tenant where scoped.
- **NFR-TEN-004:** Automated isolation tests MUST attempt horizontal and vertical cross-tenant access through every public/administrative channel and background execution mode.
- **NFR-TEN-005:** Platform support access to tenant data MUST be time-bounded, reasoned, attributable, least privilege, and reviewable.
- **NFR-TEN-006:** Noisy-neighbor controls MUST exist for requests, jobs, gateway messages, exports, webhooks, storage, and reporting before multi-tenant production release.

## 5. Security

- **NFR-SEC-001:** Security design and verification MUST align with applicable OWASP ASVS and OWASP API Security controls, with an approved target level before release.
- **NFR-SEC-002:** All external and service communications MUST use authenticated, supported TLS; exact certificate and service-identity mechanisms require deployment decisions.
- **NFR-SEC-003:** Passwords MUST use Laravel's current strong hashing configuration; privileged accounts MUST support MFA and step-up authentication.
- **NFR-SEC-004:** Authorization MUST be deny-by-default and enforced at resource level. Direct object reference attacks MUST not reveal existence across scopes.
- **NFR-SEC-005:** Secrets MUST be injected from an approved secret store, rotated, access-logged, and absent from repository, logs, events, errors, and client bundles.
- **NFR-SEC-006:** VTSA CSMS MUST not store raw PAN or CVV. Payment data scope MUST be minimized through hosted/tokenized provider flows.
- **NFR-SEC-007:** Webhooks MUST verify signature and timestamp/replay controls before domain processing; charger messages MUST be authenticated and schema/protocol validated.
- **NFR-SEC-008:** Sensitive actions and data exports MUST create tamper-evident audit records with UTC time and actor/correlation context.
- **NFR-SEC-009:** Dependency, static analysis, secret, and container/image scanning MUST be CI release gates with a documented vulnerability exception process.
- **NFR-SEC-010:** Security incidents MUST support credential/session revocation, tenant/asset/integration containment, evidence preservation, and stakeholder notification under an approved plan.

## 6. Privacy and Compliance

- **NFR-PRV-001:** Personal data collection MUST have a documented purpose, classification, lawful basis/authorization as applicable, owner, retention, and deletion behavior before implementation.
- **NFR-PRV-002:** Interfaces, logs, reporting projections, support tools, and lower environments MUST minimize and mask personal/financial data.
- **NFR-PRV-003:** Data-subject access, correction, portability, deletion, restriction, and legal-hold workflows MUST be defined for selected jurisdictions before launch.
- **NFR-PRV-004:** Location/search/charging telemetry MUST not be repurposed for marketing or analytics without approved policy and consent handling.
- **NFR-PRV-005:** Data residency, cross-border transfers, subprocessors, cookie rules, fiscal retention, and breach timelines are unresolved until target markets are selected.
- **NFR-PRV-006:** Production data MUST not be copied to development/test without an approved, irreversible de-identification process.

## 7. Observability and Auditability

- **NFR-OBS-001:** Every ingress MUST receive or create a correlation ID propagated across synchronous calls, jobs, events, gateway commands, and safe audit metadata.
- **NFR-OBS-002:** Services MUST emit structured logs, metrics, and traces using UTC timestamps and consistent service/environment/tenant-safe dimensions.
- **NFR-OBS-003:** Telemetry MUST redact secrets, tokens, raw payment data, message bodies containing personal data, and charger credentials.
- **NFR-OBS-004:** Health signals MUST distinguish liveness, readiness, dependency degradation, queue delay, gateway connection health, and business-flow failures.
- **NFR-OBS-005:** Alerts MUST be actionable, severity-classified, routed to an owned response, and linked to runbooks; alert thresholds remain operational decisions.
- **NFR-OBS-006:** Audit records MUST be queryable by tenant, actor, resource, action, time, and correlation while resisting mutation by ordinary administrators.
- **NFR-OBS-007:** Financial and inventory processes MUST expose reconciliation/control totals, exceptions, and immutable source lineage.

## 8. Maintainability and Modularity

- **NFR-MNT-001:** Core business code MUST remain organized by the 18 bounded contexts with architecture tests preventing prohibited dependencies.
- **NFR-MNT-002:** Contexts MUST expose explicit application/query contracts; direct cross-context table writes and model dependencies are prohibited.
- **NFR-MNT-003:** Public, event, gateway, and payment-adapter schemas MUST be versioned and contract tested.
- **NFR-MNT-004:** Significant architectural changes MUST use ADRs; state/ownership/event changes MUST update their canonical documents in the same change.
- **NFR-MNT-005:** Code MUST pass configured formatting, static analysis, unit/feature/contract tests, dependency scans, and production builds before merge.
- **NFR-MNT-006:** Operational complexity is a design cost: a new service, database, queue system, or provider requires ownership, monitoring, recovery, security, and local-development plans.

## 9. Deployability and Change Safety

- **NFR-DEP-001:** Build artifacts MUST be immutable, reproducible from pinned source/dependencies, traceable to a commit, and promoted rather than rebuilt across environments.
- **NFR-DEP-002:** Configuration MUST be environment-injected and validated at startup; secrets MUST not have insecure defaults.
- **NFR-DEP-003:** Deployments MUST support health-gated rollout and rollback/roll-forward while preserving message and schema compatibility.
- **NFR-DEP-004:** Database changes MUST be compatible with rolling deployment and use expand/migrate/contract for breaking changes.
- **NFR-DEP-005:** Long data migrations MUST be resumable jobs with progress and reconciliation, not unbounded schema-migration statements.
- **NFR-DEP-006:** Gateway deployment MUST drain or safely transfer charger connections and command ownership without duplicate command effects.
- **NFR-DEP-007:** Environment and deployment topology remain open; documentation MUST not contain real infrastructure credentials.

## 10. Accessibility, Localization, and Usability

- **NFR-UX-001:** Public, driver web flows, and operator UI MUST target WCAG 2.2 AA or the approved market standard, verified by automated and manual testing.
- **NFR-UX-002:** Critical workflows MUST be keyboard accessible, work with screen readers, not rely on color alone, and have visible focus/error/status treatment.
- **NFR-UX-003:** Responsive interfaces MUST support agreed mobile/tablet/desktop viewport and input modes without hiding required safety or pricing information.
- **NFR-UX-004:** User-facing dates/times, numbers, currency, distance, and energy MAY be localized, but canonical persisted/API units remain those in the PRD.
- **NFR-UX-005:** Translation keys and content MUST support pluralization and expansion; no business rule may depend on rendered text.
- **NFR-UX-006:** Irreversible/high-impact actions MUST clearly identify the target, consequence, current state, and recovery path.

## 11. Compatibility and Portability

- **NFR-CMP-001:** Supported browser, OS, mobile, OCPP, and charger matrices MUST be versioned release artifacts before testing/launch.
- **NFR-CMP-002:** Mobile APIs MUST remain compatible for an approved minimum app-version window and support forced/encouraged upgrade policy without exposing secrets.
- **NFR-CMP-003:** OCPP support MUST be driven by published protocol profiles and conformance tests; undocumented vendor behavior requires an approved integration profile.
- **NFR-CMP-004:** Core domain contracts MUST not expose provider-specific payment types except in provider metadata/evidence boundaries.
- **NFR-CMP-005:** Data export formats and retention portability require definition before tenant offboarding implementation.

## 12. Verification Gates Still Requiring Numeric Decisions

Before production readiness can be accepted, owners must approve measurable targets for:

- availability/SLOs and error budgets by journey;
- response, event-propagation, command, and session-update latency percentiles;
- maximum concurrent chargers and connection churn;
- message, session, payment, report, and export throughput;
- database/event/object storage retention and archive retrieval;
- backup intervals, restore objectives, and restore-test cadence;
- notification, support, and maintenance SLA policies;
- audit/security log retention and detection/response timing;
- supported clients/protocols and accessibility test coverage.

No numeric target should be inferred from this architecture baseline.
