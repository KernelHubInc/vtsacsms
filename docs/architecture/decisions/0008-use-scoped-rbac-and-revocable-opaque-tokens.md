# ADR-0008: Use Scoped RBAC and Revocable Opaque Tokens

- **Status:** Accepted
- **Date:** 2026-07-20
- **Decision owners:** Architecture, identity, and security engineering
- **Scope:** Tenant workforce authorization and Phase 3 API authentication

## Context

One person may hold different responsibilities in different tenants and may be limited to an organization, location, asset group, warehouse, support queue, or finance scope. Role names alone cannot express this safely. Long-lived self-contained bearer tokens also make tenant, role, subject, and incident revocation difficult to apply immediately.

## Decision

Use a code-owned exact permission catalog with tenant-owned roles and role assignments. Assignments carry an explicit scope type, optional resource ULID, expiry, and grantor. Authorization evaluates durable tenant/membership/assignment state live and denies by default. The grantor must hold both assignment authority and every delegated permission at the target scope.

Use opaque human API tokens with:

- a ULID token identifier and one-time plaintext secret;
- only a SHA-256 verifier stored in the database;
- one fixed tenant and human subject;
- a permission ability allow list that can only reduce RBAC authority;
- mandatory UTC expiry and individual revocation; and
- a copied subject security version for bulk revocation.

The token is authenticated before tenant context is established, then tenant and membership lifecycle are revalidated. API authorization requires both token ability and current RBAC. Role removal therefore takes effect without waiting for token expiry.

## Consequences

### Positive

- One identity can hold isolated, different assignments across tenants.
- Scoped permissions support least privilege and future bounded contexts.
- Token theft can be contained individually or per subject, tenant suspension takes immediate effect, and stored token data is not usable as a credential.
- Permission names form stable policy and audit vocabulary without coupling domain code to persona names.

### Negative

- Live evaluation adds queries and requires a later cache/invalidation design at scale.
- Opaque token authentication requires authoritative storage availability.
- Human token issuance currently requires a tenant-wide high-risk permission; delegated issuance policy remains to be designed.
- MFA freshness, separation-of-duty conflicts, limits, and workflow state remain additional policy layers rather than properties of RBAC alone.

## Alternatives Considered

### Hard-coded role columns or role-name checks

Rejected because roles vary by tenant and scope, and role-name conditionals make delegation and least privilege brittle.

### Permission claims trusted until JWT expiry

Rejected for privileged workforce access because revocation and role/tenant suspension would be delayed. Self-contained short-lived access tokens may be reconsidered with a proven refresh/revocation design for mobile scale.

### Token abilities without server-side RBAC

Rejected because a token must never outlive or exceed current membership and assignment authority.

### Adopt a generic RBAC package immediately

Not selected. Phase 3 requires composite tenant references, explicit resource scopes, live grantor-subset enforcement, and queue context behavior. A package may be evaluated later only if it preserves these contracts without hidden global-team state.

## Follow-up Decisions

- MFA, recent-authentication, recovery, browser sessions, SSO, and driver/workforce realm design.
- Machine/service identities and resource-bound client credentials.
- Token rotation/refresh, maximum lifetimes, device inventory, and mobile secure storage.
- Role templates, conflicting-role policies, thresholds, and dual approval.
- Safe authorization caching and invalidation events.
