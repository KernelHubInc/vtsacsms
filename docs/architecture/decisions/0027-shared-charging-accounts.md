# ADR 0027: Shared customer charging accounts

Status: implemented; release validation requirements are tracked in the runbook.
Date: 2026-10-13. Owners: Organizations, Identity, Assets, Billing, Payments and Charging.

## Context and decision

The standalone VSTA platform already has tenant memberships, organization-scoped roles, driver garages, prepaid ledgers and customer charging. Reuse those models. A charging account is an Organization with explicit personal/business/fleet metadata, not a new tenant, operator, login or external SaaS account. Personal details stay on Identity User. Operator and site scopes are unchanged.

Each active person receives one personal organization per tenant on first account access or charge preparation. A locked tenant membership and tenant/subject unique constraint make this lazy backfill repeatable. Existing personal garages and prepaid accounts keep their subject owner and balances. Shared garages and balances use a charging-account owner instead. No historical rows or funds are moved.

Existing Role/Permission/RoleAssignment implements owner, admin, billing manager, fleet manager and driver. Customer account authorization requires an exact organization-scoped grant and active user, tenant, membership and organization. A tenant-wide workforce role alone does not grant access to a customer's account. Customer roles contain no workforce or operator permissions. Owners cannot be transferred or revoked by these endpoints.

Identity uses purpose-isolated UserInvitation records. Charging invitations are accepted only through an authenticated, email-verified recipient session; the ID is not a bearer capability. Emails direct recipients to sign up/sign in and open Charging accounts. The existing token invitation endpoint rejects charging-purpose invitations. Seven-day expiry, consumed/revoked checks, current inviter authority and account-before-invitation locks prevent reuse or stale authority. Delivery failure is audited and the durable invitation remains discoverable in-app.

Assets owns vehicle assignments. Drivers see assigned shared vehicles; managers see their account's garage. Organization membership and assignment are rechecked before charging starts. Assignment rows are retained on membership revocation, but grant no access without an active role. Deliberately reinviting a person restores access to assignments still on file; managers should remove assignments before reinviting when that is not intended. Profile contact details and payment credentials are not part of member projections.

## Payment and charging boundary

Charging freezes the authenticated subject, selected account, selected vehicle, payer account and prepaid-account ID in each new quote and customer-session attribution. Shared starts require a vehicle. Personal vehicle selection remains optional for compatibility. Missing account headers retain personal semantics; an invalid or inaccessible explicit selector fails closed.

Only Billing writes prepaid entries, reservations and ledger postings. Payments preserves the initiating subject and destination account on the top-up and credits that original destination after verified AUB confirmation, including when the initiating member has since been revoked. Charging reserves and settles through Billing's application contract. Existing AUB signatures, replay handling, book separation and idempotency remain in force. No new refund execution or invoice-download API is introduced.

Owners can set per-driver/per-vehicle **per-session reservation caps**, in integer PHP minor units. The lower applicable cap wins; null removes a cap and zero blocks new reservations. Account and wallet locks serialize cap changes with new holds. Caps do not retroactively modify existing reservations, represent monthly budgets or guarantee an offline charger cutoff. The existing prepaid stop/review safeguards still apply.

The original payer is immutable even if selection, membership or funding access changes. There is no fallback wallet. A revoked member loses session access immediately; authorized account managers retain history/control. Removing only a vehicle assignment blocks new starts but still lets the original, otherwise authorized member stop an existing session. OCPP paths, identities, messages, gateway authentication and hardware capabilities are untouched.

## Migration and release

Migration `2026_10_13_000001_add_shared_charging_accounts.php` is additive apart from relaxing the legacy subject columns to accommodate shared owners. Tenant-aware account foreign keys protect ownership; PostgreSQL checks enforce exactly one garage/wallet owner and valid nonnegative caps. Empty unused schema can roll back. Once charging accounts exist, rollback is refused to protect ownership and financial evidence; roll forward instead.

Deploy the migration and matching platform/worker code before releasing the mobile client. Old clients continue using personal scope; old workers must not settle shared sessions. No account sharing should be enabled during a mixed-worker deployment. PostgreSQL migration rehearsal, provider callback delivery, invitation mail delivery and supervised physical shared-account charging remain release checks. See [audit and runbook](../../runbooks/shared-charging-accounts.md).

## Addendum — registered recipients (2026-10-13)

The operator now requires existing registered users for new invitations. Identity checks enabled user status and Organizations' tenant-scoped active-membership contract after authorizing the inviter. Unknown/disabled/outside-tenant email addresses produce the same field validation error without notification. Current members are not reinvited. The earlier invitation-to-register wording above describes the initial design and is superseded by this requirement. Recipient email verification and acceptance remain mandatory.
