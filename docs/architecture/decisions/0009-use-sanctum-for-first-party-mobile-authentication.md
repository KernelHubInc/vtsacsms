# ADR-0009: Use Sanctum for First-Party Mobile Authentication

- **Status:** Accepted
- **Date:** 2026-07-20
- **Decision owners:** Architecture, identity, mobile, and security engineering
- **Scope:** First-party Laravel/mobile authentication

## Context

ADR-0008 requires opaque, tenant-bound, immediately revocable credentials whose abilities can only reduce current RBAC. The first-party Flutter client also needs Laravel-supported bearer-token handling, device inventory, expiry, and a maintainable authentication guard. The Phase 3 rerun explicitly selects Laravel Sanctum.

## Decision

Use Sanctum 4 for first-party mobile bearer authentication with a custom `MobileAccessToken` model and ULID primary key. Each token stores exactly one tenant, a device ULID, expiry, reduced permission abilities, and the subject security version. Only the one-time plaintext credential is returned; Sanctum stores its SHA-256 verifier.

Authentication is not authorization. After Sanctum identifies the subject, middleware rechecks that:

- the token record still exists, is unexpired, and matches the current security version;
- the subject is activated and not suspended;
- the tenant and membership are active;
- an optional tenant header equals the credential-bound tenant; and
- the requested permission is present in both token abilities and live scoped RBAC.

The earlier `api_tokens` credential remains a separate foundation for deliberately issued operator/integration tokens. It is not used by the first-party mobile login endpoints.

## Consequences

Sanctum supplies the supported Laravel guard and token parsing while the platform retains stricter tenant and revocation checks. Device revocation deletes the authoritative token row; global recovery increments `security_version` and deletes all mobile tokens. Every request confirms token persistence, which is safe for long-lived application workers but adds one database lookup.

Browser sessions remain Laravel sessions and are additionally represented by tenant-bound, hashed `auth_sessions` metadata. Panel middleware rejects a logically revoked session on its next request.

## Alternatives Considered

- JWT access tokens were rejected because role, membership, tenant, and incident revocation would be delayed.
- Sanctum's default unscoped token table was rejected because it lacks mandatory tenant, device, and security-version ownership.
- Replacing scoped RBAC with Sanctum abilities was rejected because abilities are only a reduction layer.

## Follow-up Decisions

- Mobile secure-storage and rotation protocol.
- Production MFA provider and recovery codes.
- SSO/federation and machine identity realms.
- Token/session retention and device-risk signals.
