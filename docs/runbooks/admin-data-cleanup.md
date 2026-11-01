# Admin data review and cleanup

## Read-only audit

Run `php artisan data:audit-demo TENANT_ULID --sample-limit=100` inside the platform container. The JSON report counts each table and lists bounded sample IDs with the names of matching fields. It does not print contact details, passwords, tokens, or financial payloads, and it cannot delete records.

Review every admin resource against this inventory. Shared and indirectly scoped tables are identified separately. A zero-match result is not proof that every record is genuine: renamed fixtures and dependencies require manual review. Never delete records solely because a name contains “Demo.”

## November 1, 2026 cleanup

Both staging and production received PostgreSQL custom-format backups, verified with `pg_restore --list`, before changes. Private backup paths and exact manifests remain on the server, outside Git.

Staging had reused fixture identities and entirely synthetic operational records. The reviewed cleanup removed four unused organizations, four retired demo stations, two demo sites, fictional catalogue/geography entries, and their verified sample maintenance, procurement, inventory, and invoice dependencies. It retained Lumina Subic and VTSA-CP01, their site addresses and coordinates, all existing logins, driver memberships, vehicles, tariffs, wallet records, charging history, and existing audit events.

Reused organizations and role accounts received clear Power Solutions display names. Role IDs, permissions, membership assignments, email addresses, passwords, and MFA settings stayed unchanged. Retaining role accounts was an explicit owner decision; a display-name cleanup does not certify that each account is required for production access.

The owner explicitly approved removing five synthetic stock movements, one fictional invoice line, and 3,156 synthetic maintenance transitions. A staging-only transaction checked the exact manifest, incoming foreign keys, environment, and protected-table hashes. Only the three corresponding deletion triggers were temporarily suspended, and all were restored before commit. A rollback rehearsal preceded the committed transaction. This was a one-time exception, not an application feature or a general permission to erase history.

Post-cleanup checks preserved 40 users, 15 vehicles, nine charging sessions, three prepaid accounts, 33 prepaid entries, and 31 QR top-ups. An `operations.synthetic_data.cleaned` audit event records the manifest hash and counts.

Production had no sample organizations or charging network records to delete. Its empty network was not populated from staging. Both environments retained the unapproved refund policy as an internal draft, with a `cms.placeholder.unpublished` audit event. Existing audit history is intentionally preserved and may still mention historical demo names.

## Preventing recurrence

Sample supplier, fake payment-provider configuration, and development tariff seeders now run only in local, development, testing, or demo environments. Do not run demo seeders to initialize staging or production. Use the operator onboarding workflow and normal resource creation for real records.

CMS pages marked as requiring legal review are excluded from public routes, navigation, and the sitemap even if their status is accidentally set to published. Add approved refund content in CMS Pages, clear the legal-review flag only after review, then publish with a valid publication time. No refund or tax policy is inferred by this release.

## Deployment and recovery

Apply the additive operator-onboarding migration once on the primary production node and synchronize permissions. The production worker on App01 consumes `charging,default,reports,invitations`; App02 remains web-only. Staging's existing reports worker also consumes `invitations`. Older default-queue jobs are not released as part of this change.

Take fresh backups before another cleanup. Restore any backup into an isolated database first, reconcile affected records, and follow the incident recovery procedure. Do not overwrite a live database with an older snapshot after new charging or wallet activity has occurred. Source-code rollback alone does not restore deleted fixture data.
