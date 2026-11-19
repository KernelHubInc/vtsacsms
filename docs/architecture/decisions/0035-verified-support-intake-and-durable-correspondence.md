# ADR 0035: Verified support intake and durable correspondence

- Status: Accepted for staging; production promotion requires a separate release decision.
- Requirements: SUP-001–SUP-004.

## Decision

Support owns customer cases, message bodies, verified guest contact and case lifecycle. The existing mobile POST `/api/v1/mobile/support/issues` accepts its unchanged category/description/session payload and an idempotency key. Charging's `SupportSessionQuery` resolves only a session the authenticated reporter may access. Assets' public station query resolves public website selections without looking up private sessions. Support never changes a wallet, session, payment or account-deletion outcome.

The website accepts visitors without manufacturing an account. An eight-digit code is sent to the entered email, bound to an opaque challenge kept in the browser's server session. Verification expires after 15 minutes and five incorrect attempts. The cache holds an encrypted submission and a keyed code verifier, not the plaintext code. It is an incomplete form until verified; PostgreSQL owns every accepted ticket. Public tenant ownership comes exclusively from validated server configuration. A visitor's claimed session reference in free text never grants access to that session.

Accepted creation and public staff replies call Notifications' `SupportEmailRequests` contract within the same database transaction. Notifications owns its durable delivery rows, claims, retries and provider acceptance evidence. `SupportEmailQuery` supplies the minimum current recipient and public message at send time. Internal notes cannot be sent, even through a malformed delivery request. Verification email is sent outside a database transaction. Code emails refuse log/array transports outside tests.

SMTP acceptance is distinct from inbox delivery. Failed submissions remain queued with safe error categories and bounded backoff, ending in a failed state after eight attempts. Durable intent keys prevent normal duplicate tickets/replies/delivery rows. A stable RFC Message-ID aids tracing; SMTP cannot guarantee exactly-once delivery if a worker dies after provider acceptance but before recording it. Reconciliation must inspect the delivery ID before a manual resend.

Tenant-wide support can handle general and account-deletion cases. Scoped operators can list, read and respond only to tickets for sites covered by their Support permission and current operator/site assignment. An unassigned/general request stays with tenant-wide support. Contact reveal requires its separate permission and an audit record. Ticket references are public ULIDs, not authentication credentials.

## Privacy and rollout

Guest contact email is encrypted in PostgreSQL and hidden from model serialization. Verification payloads expire in 15 minutes. The staging default clears the new guest email field 90 days after resolution/closure; reopening before expiry cancels that deadline. The purge is limited to the new contact field and emits an audit record. Case descriptions, correspondence and mandatory audit history follow the existing Support privacy/retention process; this change does not erase or alter historical case evidence. No attachments are accepted. Production policy review must confirm the guest contact retention default and published privacy coverage before enabling intake.

`SUPPORT_INTAKE_ENABLED` defaults false. Enable only staging for this release. The existing mobile form needs no APK/AAB update; a new support navigation area, My Tickets or in-app conversation remains a separate mobile release. Email replies to the sender mailbox are not automatically ingested; the customer email states that limitation.

## Operations

Apply the additive migration before switching PHP processes. Run `notifications:send-support-emails` continuously or through the scheduler. Run `support:purge-expired-contact` for contact expiry. A lost cache requires requesting another code and cannot lose an accepted ticket. Restore SMTP or retry failed delivery deliberately; never mark a log message as delivered. Rolling back code retains accepted tickets and delivery evidence. Disable the feature to stop new intake and delivery before rolling back.

Identical open reports from the same verified requester, session/site and content reuse one ticket even when the existing mobile app generates a new request key on retry. Every accepted key is retained in Support-owned `support_intake_requests`, so replay after resolution still returns the original case. A fresh key may open a new case after resolution; reopening a case is refused while an identical case is open. Database uniqueness protects both request keys and open intent fingerprints.
