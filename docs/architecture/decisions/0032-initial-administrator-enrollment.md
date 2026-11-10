# ADR-0032: Initial administrator enrollment

**Status:** Accepted by explicit tenant-owner approval

**Date:** 2026-11-11

**Owners:** Identity and Organizations
**Requirements:** FR-IDN-001, FR-IDN-003, FR-IDN-005, FR-IDN-006, FR-ORG-002–004

## Context

Normal invitations require an authorized human grantor and revalidate that grantor at acceptance. A new tenant with no platform administrator cannot use that flow. Copying another environment's credentials, inventing a human grantor, or printing an initial password would undermine the existing security boundaries.

## Decision

Provide a trusted Platform CLI operation for one owner-approved administrator pair per active tenant. The operator supplies the tenant ULID, two distinct new email identities, exact platform organization name and approval reference, and explicitly acknowledges the bootstrap. The current reviewed templates contain all 67 permissions for the immutable super administrator and 27 permissions for the editable administrator. A changed permission catalog fingerprint fails closed and requires template review. Normal role editing and assignment retain grantor-subset and tenant-scope enforcement.

Organizations owns the atomic bootstrap reservation, platform organization, memberships, roles, permission grants and assignments. The tenant-unique reservation serializes retries; a digest binds the original identities, organization and permission lists. A different plan fails. Existing platform-panel assignments prevent a fresh bootstrap. Repeating the same completed plan returns the original public IDs without creating or restoring grants. Every new privileged assignment and completed bootstrap enters the tamper-evident audit ledger with the approval reference and a Platform actor; no human grantor is fabricated.

Identity's `WorkforceEnrollmentService::prepare` owns new identities and credentials. Its credential-free projection returns a subject ULID and the existing internal user key required by the legacy membership foreign key. Organizations never imports or writes the User model. This narrow compatibility reference does not expose a password/hash or establish a second identity owner. Enrollment records use public subject ULIDs without a cross-context foreign key. A stored hash binds the approved email address; changing the identity email blocks completion and resend rather than redirecting the approved access. Identity completion consults Tenancy's active-status query and Organizations' current authorization query.

New identities have no activation or email verification and an inaccessible random password. They cannot sign in. Only the recipient of a private email may set the usable password and activate the account. The 256-bit setup token is stored as a SHA-256 verifier, expires after 24 hours and is consumed under row locks. The emailed URL carries the credential in its fragment, keeping it out of HTTP access URLs. The isolated page removes the fragment from visible history and posts the token with CSRF protection. Responses use no-store and no-referrer; the page's CSP permits only local assets and connections. Passwords require at least 12 characters and the shared complexity policy; bcrypt inputs over 72 bytes are rejected. Passwords and setup tokens are never flashed, audited, printed or included in application telemetry.

Notifications are encrypted queue jobs on `invitations`, dispatched after commit. A stale or expired job cannot send. SMTP acceptance records delivery time only for the current token. Production bootstrap requires HTTPS and SMTP, rejecting log/array delivery. A failed enqueue leaves a durable pending enrollment; the explicit resend option rotates only unfinished, enabled identities. Completion does not authenticate a browser session, change MFA requirements, or restore revoked membership/roles. MFA-required identities retain the existing fail-closed panel policy while the production MFA provider remains unresolved.

## Retention, deployment and failure behavior

The migration adds two tables and changes no existing credentials or grants. Apply it once before any bootstrap. Rolling application rollback retains these tables and audit evidence; the migration refuses a destructive down operation. A used verifier is cleared immediately. A daily scheduled command clears expired verifiers without deleting subject or privilege evidence. Bootstrap/enrollment metadata follows the existing audit retention and erasure policy; a future deletion workflow must preserve required privilege evidence.

Database and audit changes commit atomically. Mail transport runs outside the transaction. SMTP acceptance does not prove inbox placement. An interrupted response can be retried safely; a completed token is unavailable. If SMTP or queue delivery fails, inspect only safe job metadata, repair the existing delivery configuration, and resend pending links. Never recover a raw token from queue payloads or logs. See the [administrator enrollment runbook](../../runbooks/initial-administrator-enrollment.md).

## Alternatives and follow-up

Rejected: shared passwords, copied staging accounts, automatic login, a fake human grantor, and a permanent HTTP bootstrap endpoint. This CLI is a privileged deployment operation, not a public registration flow. The exact two-role template is intentionally narrow; a general tenant-provisioning product workflow, production MFA, and external audit anchoring remain separate decisions.
