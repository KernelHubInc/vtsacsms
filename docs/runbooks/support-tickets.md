# Customer support tickets — staging acceptance

## Submit from the existing mobile app

Use the staging app: **Activity → select a session → Report an issue**. Choose a category, enter at least ten characters and submit. The unchanged app request now creates a ticket linked to its accessible session/site. The current app shows its existing confirmation; the new ticket reference arrives in the acknowledgement email. A new APK is not required.

## Submit from the website

Open `https://staging.evcspowersolutions.com/support`. Enter an email, category, subject and description; select a public station when relevant. Send the verification code, retrieve the eight-digit code from that mailbox and confirm within 15 minutes. The confirmation shows a `SUP-…` reference. Visitors do not need an app account. No ticket is accepted before verification. Never include passwords, PINs or complete payment credentials.

## Handle tickets

Open **Admin/Operator → Support → Support Tickets**. Search by ticket or session reference and filter by category, status or priority. **Conversation** shows the original request and the latest 100 staff messages. **Latest email** shows queued, retrying, mail-server acceptance or needs-attention status. **Respond** saves a public reply and queues an email; selecting **Internal note** keeps the note staff-only. **Update status** manages ordinary cases. Resolving an account-deletion request remains its separate privacy workflow and does not itself delete account data.

Tenant-wide `support.view` / `support.manage` permissions handle the tenant inbox. Site/operator scope restricts handling to assigned station sites; general requests without a station and account-deletion requests require tenant-wide support. **View contact** requires `support.sensitive_reveal` and records a reveal audit. Existing assignments are preserved.

Customers receive acknowledgements and public replies at their verified email. Email transport acceptance does not prove inbox delivery. Replies to the sender mailbox are not automatically added to tickets; customers can submit a follow-up with their reference. My Tickets/in-app conversations are outside this release.

## Configuration and delivery

- `SUPPORT_INTAKE_ENABLED=true` on staging only. Leave production unchanged.
- `SUPPORT_PUBLIC_TENANT_ID` must name the intended active tenant; a blank value falls back to the configured driver-registration tenant. Never derive tenant ownership from public form input.
- Preserve existing SMTP settings. A test SMTP inbox captures staging mail; it does not deliver to an external mailbox. Do not copy production-only credentials to staging.
- Run `php artisan notifications:send-support-emails` at least every minute. The existing scheduler registers it; a legacy staging stack without an active scheduler needs a dedicated worker loop.
- Delivery rows live in Notifications' `support_email_deliveries`. Inspect kind, source IDs, `sent_at`, `message_id`, safe error code, attempts and `failed_at`. Eight failed attempts require explicit operator review. Internal notes are never mail content.
- Run `php artisan support:purge-expired-contact`. The staging default clears verified guest email 90 days after resolution. It retains the case/audit record and never edits pre-existing contact fields.

Apply migration `2026_11_19_000002_add_support_intake_and_email_delivery` once after a backup. Update all staging PHP processes so the intake, portal and mail worker use the same code. The migration is additive; its down operation retains data for code rollback. Do not run demo seeders.

## QA acceptance

1. Submit one mobile issue for your own staging session. Check one ticket and acknowledgement; retry the same request key and confirm no duplicate.
2. Submit a website request using a test mailbox without an app account. Check that no ticket exists before email verification, then confirm its reference and inbox record.
3. Enter a wrong/expired code; try the honeypot, rapid form submission and repeated code requests. Check safe errors and no new tickets.
4. Reply as an authorized admin. Confirm the public reply is queued/sent and an internal note is never emailed. Simulate SMTP failure in a controlled test and confirm durable retry without losing the ticket.
5. Sign in as an operator scoped to a different site. Confirm tickets, conversations, responses, contact reveal and direct record access are blocked. Revoked permissions must take effect immediately.

Review the guest contact retention default, privacy coverage, test mailbox behavior and business handling procedure before requesting production promotion.

Identical open reports from the same verified requester, session/site and content reuse one ticket even when the existing mobile app generates a new request key on retry. Every accepted key is retained in Support-owned `support_intake_requests`, so replay after resolution still returns the original case. A fresh key may open a new case after resolution; reopening a case is refused while an identical case is open. Database uniqueness protects both request keys and open intent fingerprints.
