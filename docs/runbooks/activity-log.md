# Activity log and exports

## Administration instructions

Open **Identity and audit → Activity log** (`/admin/audit-events`). This uses the existing immutable audit store.

1. Open Filters. Select category, outcome and dates. Dates are UTC; **Before** excludes that day.
2. Optionally enter an exact action, user/actor ID, record ID or correlation ID. Find the public user ID in Users. Correlation IDs connect related requests.
3. Apply filters and use View for recorded details. Records cannot be edited or deleted.
4. Select **Export activity**. The dialog starts with the applied filters. Choose Excel (.xlsx) or PDF.
5. Download from **Reports → Queued exports** when completed. The `reports` worker must be running; production workers remain on App 01 only.

The general Queued exports page also offers Activity and audit log with dates. Excel contains all matching rows up to its 1,048,576-row sheet limit. PDF shows the first 200 rows and labels any truncation; narrow the dates for smaller reports. Exports are ordered by event ID; the table starts with the newest time. Export snapshots end before the request second, excluding their own queue/download events.

## Coverage

| Area | Recorded evidence |
| --- | --- |
| Sign-ins | New explicit mobile/browser-app and admin/operator sign-in outcomes. Successful identity is the actor; failed claims have no authenticated actor and refer to a known tenant member as the target. Mobile MFA-required attempts are Denied. |
| Identity | Registration in the existing enabled registration flow, plus existing verification, recovery, token issue/revocation, invitation, lifecycle and KYC actions. Local PIN/biometric unlock is not a server login. |
| Vehicles | Created, updated, removed, assignments and garage erasure. New create/update records contain changed field names, not private field values. Older `assets.driver_vehicle.saved` events remain unchanged. |
| Wallet | Existing top-up, reserve, spend, release and limit-change events. New movements include wallet/reference/account IDs, currency, live/simulated book, amount and resulting balance/hold. Duplicate callbacks do not duplicate movement events. |
| Charging | Existing remote commands/cancellation/review plus new state-change entries for charging aggregates. Successful audit recording does not mean a successful physical charge. |
| Other workflows | Existing QR top-up, refund request, asset/site, tariff, account sharing, permission, maintenance, procurement, inventory and configuration workflows that use AuditRecorder. |
| Exports | Requests and authorized downloads. Permissions are rechecked at request, generation and download. |

Amounts are integer minor units: PHP 50000 means ₱500.00. Reserved funds are a hold; released funds are not new income. Spend/release rows from one settlement share its final balance snapshot. Use the wallet ledger for accounting totals, session reports for energy/times, and payment reconciliation for provider settlement. Do not sum all activity amounts.

## Access and privacy

Tenant-wide AuditView (`audit.events.view`) is required to view; AuditExport, ReportingView and ReportingExport are additionally required tenant-wide to export. Scoped site/organization grants cannot expose unrelated identity or wallet history. No privileges are granted automatically.

Files remain private and requester-only, with seven-day download access and permission/scope rechecks. Existing cleanup applies; this change does not delete audit evidence or set a new retention policy. Retention approval and external anchoring remain operational decisions. Database append-only protections and per-tenant hash chains are retained.

Exports omit IP addresses, user agents, arbitrary before/after payloads, free-form reasons, credentials, tokens, email addresses, plates and KYC evidence. The admin table retains its optional source-IP column. Selected wallet/state/change metadata is projected through `AuditLogQuery`; additions require an explicit privacy review.

## Limits

- This is a business activity log, not every HTTP request or validation failure. Specialized session/command diagnostics, wallet ledger, payment history and server logs remain available.
- Unknown or unattributable identities generate credential-free security log notices, not tenant activity rows. Panel-ineligible accounts, early validation/CSRF failures and rate-limit rejections are not all represented here. Absence is not proof that no failed login occurred.
- Missing historical events cannot be reconstructed. Existing immutable rows are not rewritten to add new details.
- Missing navigation means insufficient access. Empty results may be caused by filters or the selected tenant. Queued exports need a worker; expired/revoked exports cannot be downloaded.

## Rollout and verification

Deploy the same release to application and report workers. No schema migration, financial backfill, new service or automatic permission grant is required.

`ActivityLogTest` covers tenant and role isolation, UI filters/export, revoked downloads, wallet idempotency, vehicle privacy, browser/mobile login outcomes, charging state evidence, formula-safe Excel and PDF generation. Existing audit tests cover hash-chain and mutation protection.
