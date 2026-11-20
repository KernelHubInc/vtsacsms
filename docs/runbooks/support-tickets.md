# Customer support tickets — staging acceptance

## Images and support levels

The updated mobile build adds **Add images** in **Activity → Session → Report an issue**. Choose up to five JPEG/PNG images, 2 MB each (maximum 3000 × 3000 pixels); review previews and remove unwanted images before submitting. Existing installed builds still support text reports. The website offers the same optional upload on the email-code confirmation step, after entering the request details. The server validates and sanitizes every image; invalid uploads never partially create a ticket. Avoid passwords, payment details, faces and identity documents.

In **Support → Support Tickets**, filter by **Support level**. New tickets and existing unclassified tickets default to **Level 2 — EVCS Admin**. **Assign support level** lets authorized staff select **Level 1 — Operator site admin**, **Level 2 — EVCS Admin**, or **Level 3 — Kernel Hub (App Developer)** and confirm. **Conversation** displays the private customer images; choose **Open image** for a larger view. Level selection does not give another person/organization access: staff still need the existing Support permissions for the ticket's tenant/site. General enquiries without a station require the tenant-wide inbox. No developer account or cross-tenant access is created automatically.

Optional images are encrypted in the shared database, excluded from email and public storage, and removed 90 days after resolution/closure by the existing purge command. Reopening keeps them until the next completed retention period. Apply additive migration `2026_11_20_000001_add_support_images_and_levels` after a backup. Preserve the application encryption key; monitor database/backup growth. Confirm GD is enabled, PHP `post_max_size=12M` / `upload_max_filesize=2M` / `max_file_uploads=10` (the application accepts five), and a proxy body limit of at least 12 MB. The previous staging-only rollout notes below describe the earlier release; the current owner has requested both staging and production promotion.

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
