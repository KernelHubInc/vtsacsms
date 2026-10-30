# Charging receipt delivery

## Find a receipt

In **Administration > Finance > Charging receipts**, search for the session reference from the driver's Activity screen. The read-only page requires tenant-wide `audit.events.view` permission. It does not grant operators access to all tenant receipts. Records are tenant scoped; recipient addresses and receipt payloads are not exposed in the table.

The table contains recorded receipt requests for settled customer sessions, not every charging session. A session awaiting settlement has no receipt request yet. Check Session diagnostics and payment state first if no row exists.

| Status | Meaning |
| --- | --- |
| Queued | Settlement recorded a receipt request; no attempt yet. |
| Sending | The email runner holds a five-minute claim. |
| Retry pending | The last attempt did not succeed, or its claim expired. Check last issue and next retry. |
| Mail server accepted | The transport returned a message reference. This does not prove inbox placement or exclude a later bounce. |
| Sent (legacy record) | Older code recorded a send timestamp without transport evidence. No message reference is reconstructed. |

Attempts, last attempt, sent time, mailer and message reference help locate a message in the mail provider's logs. Error codes intentionally exclude SMTP responses, recipient addresses, credentials and message contents. The existing delivery record retention applies; no copy of the email or PDF is stored for this feature. There is no open-tracking pixel or automatic bounce/inbox webhook.

The sender runs separately from charging monitoring. Failed sends retry with backoff; log/array transports and fallbacks containing them cannot be reported as external acceptance. A cancelled send remains pending. An uncertain transport acknowledgement can still result in duplicate delivery after a retry; this is not an exactly-once mail protocol.

## Images and spam

Charging receipt HTML embeds the PNG logo, payment check and button arrow as CID attachments. It needs no public image fetch. Its PDF embeds the logo independently, with remote loading disabled. Plain text, alt text and receipt values remain available when a mail client blocks images. Existing welcome/promotional templates are unchanged.

If the mail server accepted a receipt but the driver cannot find it, check Spam, Promotions and All Mail. Gmail's Show original displays SPF, DKIM and DMARC results. Inspect those and the sender provider's delivery logs before changing DNS. Passing authentication does not guarantee inbox placement. Do not resend a receipt already found in Spam just to test delivery.

## Rollout

Apply `2026_10_30_000001_add_charging_email_delivery_tracking.php` before deploying the new sender and admin page. It adds four nullable columns without rewriting existing rows. Old code remains compatible; rollback application code while retaining the added columns. Deploy to the web service and dedicated charging email runner, refreshing route/view/Filament caches. Production job runners remain on App01; no production queue is added to App02.

Run `ChargingReceiptTrackingTest`, `EmailDesignTest` and `CustomerChargingTest`, then the platform quality suite. Tracking tests cover transport failures/retry, duplicate runner invocations, log-only fallback, cancelled sends, tenant isolation, denied/revoked admin permission, filtering and CID/PDF integrity. Preview the receipt at desktop and narrow mobile widths; this does not certify every mail client.
