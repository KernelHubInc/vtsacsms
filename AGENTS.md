# VTSA CSMS Engineering Standards

This file governs every change in this repository. More specific `AGENTS.md` files may add constraints for their subtree but may not weaken these standards. If a user instruction conflicts with this file, surface the conflict before proceeding.

## Product and Architecture Guardrails

- VTSA CSMS is a standalone, multi-tenant EV charging platform.
- The Laravel application is a modular monolith. Preserve the bounded contexts documented in `docs/architecture/data-ownership.md`; do not create a generic service layer that bypasses module ownership.
- The OCPP gateway is a separately deployable container. It communicates with the core platform through versioned commands, queries, and integration events, never by writing core-owned database tables.
- Bounded contexts are: Identity, Tenancy, Organizations, Locations, Assets, Charging, Tariffs, Payments, Billing, Settlements, Procurement, Inventory, Maintenance, Notifications, Support, Reporting, CMS, and Integrations.
- Use integer minor units plus an ISO 4217 currency code for money. Never use floating point for monetary values.
- Store energy in watt-hours, power in watts, and durations in integer seconds. Convert only at presentation and integration boundaries.
- Store timestamps in UTC with timezone-aware types. Convert to a user's or location's IANA timezone only for display and calendar rules.
- Use ULIDs for public and cross-context identifiers. Internal database keys may also use ULIDs; do not expose sequential identifiers.
- Do not invent credentials, tax rules, bank details, charger-vendor behavior, or production infrastructure configuration. Record unknowns as explicit decisions or assumptions.

## Coding Standards

- Target PHP 8.2+ and the repository's pinned Laravel version. Use strict types in PHP files where Laravel conventions permit.
- Follow PSR-12, Laravel conventions, and idiomatic Eloquent. Prefer typed properties, enums, value objects for measured values, constructor injection, and small final classes where extension is not intended.
- Use the repository-pinned Livewire v4 release. Prefer `wire:model`; use `.blur`, `.live`, or debounce modifiers only for a documented interaction or performance reason. Use attributes such as `#[Validate]` and `#[Url]` where appropriate.
- Keep controllers, Livewire components, console commands, and gateway handlers thin. Put use-case orchestration in the owning module's application layer and domain rules in that module's domain layer.
- Validate requests at every trust boundary. Use Form Requests for HTTP endpoints and explicit DTO/schema validation for events, jobs, webhooks, mobile APIs, and OCPP messages.
- Prevent N+1 queries with explicit eager loading. Select only required columns for high-volume reporting and operational screens.
- Do not access another context's Eloquent models or repositories for writes. Use its public application contract. Cross-context reads require an explicit query contract or reporting projection.
- Avoid hidden side effects in model observers. Prefer explicit application workflows and transactional outbox events.
- Use database transactions for atomic business changes. External network calls must not occur inside an open database transaction.
- Jobs, consumers, webhooks, payment callbacks, and OCPP message handlers must be idempotent.
- Tailwind and Blade UI must be responsive, accessible, keyboard usable, and visibly customized. Prefer semantic HTML, intentional whitespace, useful empty/loading/error states, and consistent focus treatment.
- Keep comments focused on why a non-obvious decision exists. Do not narrate the code.

## API and Event Standards

- Version public/mobile APIs and integration-event schemas. Breaking changes require a new version and a migration/deprecation plan.
- API errors must use a consistent machine-readable code, safe message, correlation ID, and field-level validation details where applicable.
- Use cursor pagination for large or changing collections.
- Integration events use past-tense names and include `event_id`, `event_type`, `schema_version`, `occurred_at`, `tenant_id` when tenant-scoped, `aggregate_type`, `aggregate_id`, `correlation_id`, `causation_id`, and `data`.
- Assume at-least-once delivery. Producers use a transactional outbox; consumers deduplicate by `event_id` and tolerate reordered events where the source permits them.
- Never put secrets, raw card data, access tokens, or unnecessary personal data in events or logs.

## Testing Standards

- Every behavior change requires proportionate automated tests. A bug fix requires a regression test that fails before the fix.
- Unit-test value objects, state transitions, authorization policies, tariff calculations, allocation logic, and other deterministic domain rules.
- Feature-test HTTP, Livewire, console, queue, policy, and persistence boundaries. Contract-test module APIs, payment adapters, integration events, and OCPP/core exchanges.
- Test tenant isolation explicitly: same identifiers across tenants, unauthorized cross-tenant access, queue tenant context, exports, search, and cached results.
- Test idempotency, retries, duplicate delivery, out-of-order callbacks, timeouts, and partial failure for external integrations.
- Use factories and builders. Tests must not depend on execution order, wall-clock time, external networks, or production credentials.
- Freeze time where time affects behavior. Include DST and timezone cases when local schedules are involved while persisting UTC.
- Run the smallest relevant test set during development and the full quality suite before handoff. Do not weaken or delete tests merely to make a change pass.

## Security and Privacy Standards

- Deny by default. Enforce authorization server-side with policies/permissions and tenant scope; hidden UI is not authorization.
- Follow OWASP ASVS and API Security guidance. Protect against injection, XSS, CSRF, SSRF, insecure direct object references, mass assignment, unsafe file uploads, and open redirects.
- Treat the public site, mobile app, admin UI, OCPP connections, webhooks, and support tooling as separate trust boundaries.
- Store passwords only with Laravel's current strong password hashing configuration. Require MFA for privileged roles when the identity design is implemented.
- Payment providers must tokenize payment instruments. VTSA CSMS must not store CVV or raw PAN. Verify webhook signatures against the raw request body and apply replay protection.
- Encrypt sensitive data in transit and at rest using platform-managed mechanisms. Secrets belong in a secret manager or environment injection, never in source, fixtures, logs, screenshots, or documentation.
- Log authentication, privilege, payment, settlement, maintenance override, inventory adjustment, export, and configuration changes to tamper-evident audit records.
- Redact personal and financial data from telemetry. Define retention and erasure behavior before collecting a new sensitive field.
- Security-sensitive dependencies and generated lock files must be reviewed and scanned. Escalate critical vulnerabilities; do not silently suppress them.

## Database and Migration Standards

- PostgreSQL is the system of record; PostGIS owns geospatial queries. Redis is ephemeral and must never be the only copy of durable business data.
- Every tenant-owned table includes a non-null `tenant_id`, an index beginning with `tenant_id` for common access paths, and tenant-aware unique constraints.
- Use ULIDs consistently with an ordered, indexed database representation. Foreign-key column types must exactly match their referenced keys.
- Use `timestamptz` for instants, integer/bigint for minor money units and measurements, ISO codes for currencies, and explicit check constraints where practical.
- Migrations must be forward-compatible with rolling deployment: prefer additive changes, nullable/backfilled columns, dual-read/write transitions when required, and later constraint enforcement/removal.
- Large backfills run in resumable batches outside a schema migration. Avoid long table locks and unbounded data updates.
- Never edit a migration already applied in a shared environment. Add a new migration and document rollback or roll-forward behavior.
- Destructive migrations require a backup/restore assumption, impact analysis, explicit approval, and a staged data-retention plan.
- Seeders must be deterministic and safe for their target environment. Never seed real personal data or credentials.

## Documentation Standards

- Update the PRD, architecture views, event catalog, state machines, data ownership, security model, or ADRs when a change affects them.
- Record significant, durable architectural choices as numbered ADRs. Mark superseded ADRs; do not rewrite history.
- Mermaid diagrams must render in GitHub-compatible Markdown and use stable state/context names matching the prose.
- Requirements use stable identifiers. Link implementation and tests back to requirement IDs when practical.
- Document assumptions, failure modes, operational impacts, migrations, and unresolved decisions. Do not present guesses as decisions.

## Change Workflow and Definition of Done

1. Read the relevant requirements, owning context, state machine, and ADRs before changing code.
2. Keep a change within one owning context where possible; document any cross-context contract.
3. Implement the smallest complete change, including authorization, validation, observability, and failure behavior.
4. Add or update automated tests and documentation.
5. Run formatting, static analysis, security/dependency checks, relevant tests, and production build checks configured by the repository.
6. Report changed files, verification performed, migrations/operational steps, risks, and unresolved decisions.

A change is not complete while known test failures, undocumented schema changes, tenant-scope gaps, placeholder secrets, or unreviewed cross-context writes remain.
