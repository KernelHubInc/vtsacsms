# Staging administration and account audit

## Removing charging-network records

Administrators and scoped operators with the existing manage permission can use **Delete** on Sites, Charging Stations, EVSEs, Connectors, and Operating Hours. Sites and stations also expose it on their edit page. Every deletion requires confirmation and records an audit event.

Sites, stations, EVSEs, and connectors must be **Draft** and have no linked records. Remove an unused hierarchy from the bottom up: connector, EVSE, station, then site. A reference in another context, including a connection projection, tariff, maintenance record, or delegated scope, blocks deletion. The message identifies the remaining reference categories. This is deliberately conservative: changing the lifecycle to Draft does not erase history or make a referenced record deletable.

For equipment already used, retain it and select **Retired** in its lifecycle setting. Do this only after resolving charging requests, sessions, and wallet holds; retirement is not an OCPP stop command. Never delete payment, wallet ledger, invoice, audit, or charging-session history to clean up a screen. Existing account suspension and organization/inventory deactivation workflows remain the appropriate controls for those resources. No bulk deletion or historical-data purge is introduced.

Operating-hour deletion removes that day-specific rule. Review the remaining schedule before publishing the site; deleting a rule is not a way to mark a day closed. Use **Closed all day** for that purpose.

## Staging demo credentials

`php artisan identity:rotate-staging-demo` previews the 19 login mappings. `php artisan identity:rotate-staging-demo --apply` prompts privately for a new password. For secure automation, `--password-stdin` reads one line from standard input. Do not put passwords in shell arguments, source files, or this document.

The command is restricted to staging (and isolated tests). It matches the original seeded public IDs and active demo-tenant memberships; target email collisions fail the entire transaction. It updates only those existing identities to the same local part at `evcspowersolutions.com`, preserving verification status, disabled state, membership, role assignments, vehicles, wallets, and history. It revokes their existing browser/mobile/API sessions and old reset tokens. Existing demo users must sign in again.

Primary logins after rotation:

- Administration: `superadmin@evcspowersolutions.com`, `admin@evcspowersolutions.com`
- Operator: `operator.admin@evcspowersolutions.com`, `operator.ops@evcspowersolutions.com`
- Driver: `driver@evcspowersolutions.com`
- Other seeded roles retain their original local part: auditor, sitehost, site.manager, requester, approver, procurement, inventory.manager, warehouse, maintenance.manager, technician, finance, support, fleet.manager, executive.

This does not provision email mailboxes or alter the configured SMTP sender. Recovery emails require a deliverable mailbox or alias. Do not rerun the full demo seeder on staging: it seeds sample network and financial history and uses isolated local-development credentials. The staging rotation command intentionally avoids reseeding. Before production, create individually assigned privileged accounts and rotate shared demonstration credentials.

## Recovery and verification

Password recovery now changes the hash and revokes browser, mobile, and API sessions across all of the identity's memberships atomically. It preserves other users' sessions and writes tenant-scoped audit evidence without recording credentials. Password-reset links remain single-use and expire through Laravel's password broker.

Regression coverage includes confirmation/action rendering, linked-record rejection, active-record rejection, bottom-up draft removal, tenant and permission boundaries, credential collision rollback, stable account identities, and password-reset browser-session revocation. The existing registration, verification, vehicle, wallet, sharing, payment-adapter, and charging suites remain required checks.

No schema migration or mobile API contract change is required. Deploy the application image to the web application and all four consumers/workers together. Keep the previous image and private compose overrides for application rollback. Credential rotation is an intentional database change and is not undone by an application-image rollback. Verify login with the new addresses after rotation. Physical charging and real provider settlement must be verified in separate supervised tests; automated tests do not prove those outcomes.

## Verified staging release — 2026-10-15

Application image: `vtsa-prepaid-platform:20261015-admin-audit`. All five application services run this image. The native `vtsa-ocpp` service was not restarted. No schema migration or real payment/charger command was performed.

- Backend: 248 tests / 2,058 assertions passed locally and in the release container.
- Mobile: 204 tests passed; Flutter analyzer reported no issues. The existing 1.0.20 APK remains compatible.
- PHP formatter and PHPStan passed; production web assets built successfully.
- Patched `symfony/http-foundation` from 7.4.14 to 7.4.21; locked dependency audit reports no known advisories.
- PostgreSQL: 32 rollback-only application checks passed before and after deployment, including account creation/selection, registered-user invitations, vehicle assignment, wallet isolation, deletion, and password-reset token revocation.
- All 19 demo identities rotated successfully. Fresh HTTPS login/logout verified for superadmin, operator.admin, and driver. Admin login and readiness endpoints return HTTP 200.
- SMTP connection and authentication succeeded; no email was sent and mailbox delivery was not asserted.

Root-only release evidence and database backup: `/opt/vtsa-releases/admin-audit-20261015/`. This is an on-host recovery copy, not an off-host disaster-recovery backup. The earlier unconfirmed physical charging request remains under payment review; it was not forced into a completed/refunded state. A supervised EV charge and actual email receipt are still separate acceptance checks.

The browser preview could not attach during this run. Admin page/action behavior was verified through Filament/Livewire tests, not a new visual browser inspection.

At the final check, physical charger `202607010021` was offline with last message `2026-10-13 14:28:21 UTC` (October 13, 10:28 PM GMT+8), preceding this deployment. Gateway service health does not prove charger connectivity. Reconnect the idle charger before physical charging acceptance.
