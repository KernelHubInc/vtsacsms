# ADR 0037: Private ticket images and three support levels

Status: Accepted by product-owner request, 2026-11-20

Owners: Support; Notifications email contract unchanged.

## Context and decision

Customers need to show charger issues, and the operations team needs a clear routing level. Support owns `support_level`, with Level 2 (EVCS Admin) as the database and intake default, Level 1 (operator site admin) for local assistance, and Level 3 (Kernel Hub, app developer). Existing escalation counts retain their historical meaning. Authorized support staff choose the level with a confirmation. The level is routing metadata: it does not grant access, create a Kernel Hub account, or send customer data to another organization. Tenant/site policy checks still apply when listing, changing or viewing a ticket. A general enquiry with no station remains in the tenant-wide inbox matching its initial Level 2 routing.

Accept at most five JPEG/PNG images per initial report, each at most 2 MiB and 3000 by 3000 pixels. Validate actual file contents and decode/re-encode pixels before persistence, stripping EXIF location and appended content. Reject SVG, HEIC, animated images, PDFs and other formats. The mobile photo picker requests resized/compressed images without full metadata, displays previews and allows removal. The server is authoritative for all limits. Existing text-only JSON clients remain compatible; images use multipart requests.

The website accepts images at the email-confirmation step. An unverified visitor never creates a ticket or durable image. The encrypted verification cache contains text only. No upload is staged in public storage or embedded in a verification email.

Small, bounded image content is encrypted with Laravel's application encryption and stored in Support-owned PostgreSQL rows alongside the ticket acceptance transaction. The API's normalized image digests participate in the private intent fingerprint; a retry creates neither duplicate tickets nor duplicate images, and a conflicting retry is rejected. No external storage write occurs inside a database transaction. This avoids orphaned uploads and preserves consistency across both production app servers without introducing an unconfigured shared filesystem. The tradeoff is up to approximately 14 MiB of encrypted database content per maximum-size report, plus database/backups overhead. Monitor support storage and backup growth; a future object-storage transition must preserve the same private access and atomic acceptance contracts.

Staff images are served only through an authenticated, live panel-access and ticket-policy check. Opaque image ULIDs are not credentials. Responses use the verified image content type, no-store, no-sniff, no-referrer and a restrictive CSP. Reads are audited without content, filenames, contact details or image hashes. Images are not public links, email attachments, inline data in delivery logs, or model JSON output. Original filenames are discarded.

## Retention, operations and rollback

Image content is cleared 90 days after the ticket is resolved/closed. Open/reopened cases retain evidence. The existing `support:purge-expired-contact` runner also performs bounded, tenant-scoped image cleanup, retaining minimal image metadata and an audit event. Pixel decoding needs GD, already included in the platform image. PHP must accept a 12 MiB total request and at least six parsed files (the application rejects more than five); the image includes this setting. The reverse proxy must permit at least 12 MiB for support submissions. Never change charging/payment configuration for this release.

Apply the additive `2026_11_20_000001` migration after a database backup and before switching PHP processes. Existing unclassified tickets obtain Level 2 through the additive column default; the historical escalation count is untouched. Code rollback retains the column and customer images. Do not run demo seeders or expose image content to validate deployment. A new mobile build is needed for the picker; old mobile builds continue sending text-only reports. The same source is used for Android and the signed iOS Codemagic build.

The owner has deferred the existing approved privacy publication. Before public store review, the policy must also describe optional support images and their retention. No invented legal text is published by this change. Acceptance includes malformed/oversized files, multiple images, encrypted storage, wrong codes, repeated and conflicting intent keys, cross-tenant access, routing validation, and retention/reopening.
