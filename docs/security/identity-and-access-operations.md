# Identity and Access Operations

**Status:** Phase 3 implemented foundation  
**Audience:** Developers, operators, and security reviewers

## Identity Lifecycle

Invitation acceptance activates an account and verifies the invited email because the single-use token is delivered to that address. Existing accounts gain a new tenant membership without changing their password. New accounts must supply a name and password. Invitations expire after seven days, store only a SHA-256 token verifier, and may be revoked before acceptance.

Tenant operators suspend memberships. Platform-authorized workflows may suspend a global account. Global suspension and password recovery increment the subject security version, revoke all Sanctum tokens, and logically revoke browser sessions. Reactivation is an explicit audited action; removing `disabled_at` through direct model updates is not an approved workflow.

Email verification URLs are signed, expire after 60 minutes, and identify users by public ULID rather than the internal numeric key. Password-reset responses never disclose whether an email exists.

## Mobile Number Verification

`MobileVerificationSender` is the provider boundary. Local and test environments bind `FakeLocalMobileVerificationSender`, which performs no external I/O and never logs numbers or codes. The fake verification code is `000000`; it must not be enabled in production. Challenges expire in ten minutes, allow at most five attempts, and store only hashes of the code and normalized E.164 number. The number itself uses Laravel's encrypted cast.

No SMS vendor, sender identity, production credential, or regional delivery rule has been selected.

## MFA Readiness

Accounts carry explicit MFA requirement and enrollment timestamps. Login consults `MfaChallengeProvider`; when MFA is required and no production provider exists, token issuance fails closed with `mfa_unavailable`. This phase does not invent a TOTP, WebAuthn, SMS, recovery-code, or help-desk reset policy.

## Panels

| Panel | Path | Eligibility | Tenant context |
| --- | --- | --- | --- |
| Platform administration | `/admin` | Active tenant-wide `identity.panels.platform.access` assignment in a tenant with an active platform organization | Server-selected eligible platform tenant |
| Operator workspace | `/operator` | Active tenant-wide `identity.panels.operator.access` assignment in a tenant with an active charge-point-operator organization | Server-selected eligible operator tenant |

Panel visibility is not authorization. Panel middleware establishes tenant context after session authentication; model scopes and policies remain authoritative. The session identifier is never stored directly: `auth_sessions` stores a tenant-bound SHA-256 reference plus safe device metadata.

## Rate Limits

- Login: five attempts per minute per normalized email and IP, plus 30 per hour per IP.
- Password recovery and invitation acceptance: three per minute per email/IP and 20 per hour per IP.
- Email/mobile verification: three per minute per authenticated public user ULID.

Exact production limits remain subject to load testing and abuse telemetry.

## Operational Commands

After migrations, synchronize the immutable permission vocabulary:

```powershell
php artisan security:sync-permissions
```

`php artisan db:seed --class=FoundationRoleSeeder` materializes deterministic operator-administrator and site-viewer role templates for existing tenants. It creates no tenants, users, invitations, or credentials.

## Security Boundaries

- Tenant-owned models fail closed without `CurrentTenant`.
- Cross-tenant foreign keys include tenant ID.
- Site lists, direct lookups, reports, and formula-safe CSV exports use the same `AccessibleSitesQuery`; a UI filter is never the boundary.
- Sanctum abilities and live scoped RBAC must both permit an action.
- Queue jobs carry and revalidate a versioned tenant/actor envelope.
- Audit events contain actor, tenant, source IP, user agent, action, target entity, before/after state, and correlation ULID, then join an immutable per-tenant hash chain.

PostgreSQL RLS, production MFA, SSO, support impersonation, mobile token rotation, and external audit anchoring remain open decisions.
