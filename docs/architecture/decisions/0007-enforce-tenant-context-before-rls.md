# ADR-0007: Enforce Tenant Context in the Application Before RLS Rollout

- **Status:** Accepted
- **Date:** 2026-07-20
- **Decision owners:** Architecture and security engineering
- **Scope:** Laravel tenant-owned data access and background execution

## Context

VTSA CSMS uses shared PostgreSQL tables for multiple commercial tenants. Every request, job, export, search, cache, and future domain workflow must deny cross-tenant access. PostgreSQL row-level security is a desirable additional barrier, but an unsafe session variable, pooled connection, migration role, or reset strategy can create a false assurance or retain the prior tenant.

Phase 3 needs an enforceable baseline before those deployment details are selected.

## Decision

Establish an immutable `TenantContext` at each protected ingress and apply fail-closed application scoping to every tenant-owned model. A tenant-owned query without context returns no rows. Tenant-owned writes require context, set or verify `tenant_id`, and reject ownership mutation.

Tenant-owned tables use non-null tenant IDs, tenant-first indexes, tenant-aware uniqueness, and composite tenant foreign keys where the relationship is available. Raw or unscoped access is permitted only inside a reviewed boundary that supplies an explicit tenant predicate and establishes context before tenant use cases execute.

Queue handlers accept a versioned tenant envelope, revalidate lifecycle and actor membership, establish context for one handler invocation, and clear it in guaranteed cleanup. Long-lived workers never keep an ambient default tenant.

PostgreSQL RLS remains a planned defense-in-depth layer. It is not represented as active until runtime roles, session/transaction context, connection-pool reset, migrations, support bypass, worker behavior, and failure tests are proven by a later ADR.

## Consequences

### Positive

- Tenant queries fail closed during missing middleware, local scripts, and worker reuse.
- Ownership errors are caught in application tests and many are rejected by database constraints.
- Domain modules receive one consistent context contract from their first migration.
- RLS can be introduced deliberately without being the only isolation control.

### Negative

- Eloquent global scopes can be bypassed by privileged code and therefore require code review and raw-query tests.
- Platform-global scheduling/support paths need explicit APIs rather than ordinary model queries.
- Live permission and lifecycle checks add database work until a tenant-safe invalidation/cache design is proven.
- SQLite tests cannot prove PostgreSQL pooling or RLS behavior.

## Alternatives Considered

### Trust request tenant headers

Rejected. A client tenant ID is only a selector and cannot establish ownership or membership.

### Add tenant filters manually in each controller

Rejected because omission is silent, duplicated, and unsafe for jobs, model binding, exports, and future modules.

### Enable RLS immediately and rely on it alone

Rejected for Phase 3 because connection and bypass-role behavior has not been proven. RLS remains required for evaluation, not assumed.

### Database per tenant

Rejected by the shared-platform baseline. Physical isolation may be revisited for justified large or regulated tenants.

## Follow-up Decisions

- RLS policy/session template and connection-pool reset proof.
- Runtime, migration, audit-writer, reporting, and emergency database roles.
- Tenant-safe cache, search, object, realtime, export, and scheduler contracts.
- Whether selected tenants require physical workload/data isolation.
