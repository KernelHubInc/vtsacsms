# ADR 0041: Level 3 support email alerts

Status: Accepted by product-owner request, 2026-11-27. Deployment verification is recorded per environment.

Requirements: FR-SUP-001, FR-SUP-003, FR-SUP-006.

## Decision

A real transition into Level 3 asks Notifications to persist an operational email intent in the same transaction as the Support state and escalation audit. Re-selecting Level 3 is a no-op. Returning to another level and later escalating again creates a new escalation ULID. This continues ADR 0035's durable-intent contract and extends ADR 0037's routing behavior without changing ticket access.

Organizations' `SupportSuperAdministrators` query returns distinct public user identifiers for the current active tenant only. Recipients must have active, unexpired membership and tenant-wide assignment to the existing `platform-super-admin` or `production-super-administrator` role, with administration-panel, support-view and support-management grants on that role. Identity supplies the enabled, verified account's current email at send time. No email is hard-coded, and an administrator or site-scoped role does not become a recipient just because its label resembles Superadmin. New/custom Superadmin role keys require an explicit Organizations contract update.

Notifications owns `support_escalation_deliveries`, including per-recipient intent uniqueness, claims, attempts, acceptance and safe failure/cancellation evidence. It stores public user and ticket identifiers, not addresses, customer text, images or message bodies. Separate storage prevents an old correspondence worker from interpreting a staff alert as a customer acknowledgement during a rolling deployment; it also preserves the existing customer-email status column's meaning.

The existing `notifications:send-support-emails` runner processes the additional queue. Sending occurs after the claiming transaction closes. Before each attempt, Organizations rechecks live recipient eligibility, Identity rechecks the address, and Support's minimal escalation query resolves the ticket in the current tenant. Revoked/disabled/unverified recipients or unavailable tickets are cancelled without sending. A real escalation is historical evidence; its alert may arrive after the ticket has subsequently changed status. The authenticated portal remains the source of its latest state.

Each email contains only the ticket reference, current priority, Level 3 routing notice and a protected Admin search link. The link carries no access token and requires normal authentication and ticket authorization. Customer descriptions, subjects, attachments and internal notes stay in the portal. Both HTML and text alternatives are supplied; untrusted text is escaped.

## Failure behavior and operations

Claims expire after five minutes. Failed sends retry with bounded backoff and stop after eight attempts. Stable Message-IDs and durable recipient intent prevent normal duplicate sends, but SMTP cannot guarantee exactly-once delivery across a crash after acceptance and before recording `sent_at`; inspect acceptance evidence before any manual retry. A tenant with no eligible recipients retains a failed `no_eligible_superadmin` row and safe warning instead of claiming an email was sent. Escalation itself still succeeds. After fixing recipient access, an authorized operator can deliberately reroute and re-escalate the ticket to request a fresh alert. Do not automatically grant additional access or invent fallback recipients.

Delivery evidence follows the existing operational Support/Notifications retention process. No new personal contact field or customer-content retention is introduced. New alerts require the existing support feature and worker to be enabled and an approved external mail transport. Test capture inbox acceptance is not external inbox delivery.

Apply additive migration `2026_11_27_000001_create_support_escalation_deliveries` after a backup and before switching PHP processes. Update the existing support-mail worker; no new worker, mobile build, mailbox or credential is required. Code rollback retains the new table and evidence; old code ignores queued staff alerts until a compatible worker resumes. Preserve charging, payment, Google sign-in and SMTP configuration. Verify enqueueing/rollback, no duplicate transition, recipient expiry/revocation, tenant isolation, bounded retries, claim recovery, HTML/text privacy and SMTP capture in staging before production activation. Do not test by escalating a real customer's case.
