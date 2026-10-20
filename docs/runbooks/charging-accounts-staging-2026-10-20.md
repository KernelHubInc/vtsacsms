# Charging account switching and maintenance on staging

## Behavior

The account screen makes several reads when a driver switches between Personal, Business and Fleet. Staging logs showed HTTP 429 for member, limit and invitation reads on 2026-10-20 because all of those reads shared a 30-request-per-minute counter with account mutations. Account reads now have a separate 120-request-per-minute counter; account writes retain a separate 30-request-per-minute counter. This changes no authorization rule or wallet ownership.

Owners can edit a shared account's name and Business/Fleet label. Names are trimmed, repeated whitespace is collapsed, and the server requires 2–160 characters. This label change does not move vehicles, funds or sessions. Personal accounts and non-owner edits are denied.

An owner can delete a shared account only when it is unused. The server rejects deletion if another member is assigned or if a vehicle, invitation, wallet record or charging record refers to it. Personal accounts cannot be deleted. The mobile screen keeps the account selected and displays the rejection reason. Deletion is audited. No existing account was deleted as part of this release.

## Deployment and verification

The staging API patch was copied to the running platform container on 2026-10-20; its future image selection is `vtsa-prepaid-platform:20261020-charging-accounts`. The previous PHP files and Compose selection are stored in `/opt/vtsa-releases/charging-accounts-20261020/` on the application VPS. No migration or OCPP gateway restart is required. The platform container stayed healthy; admin login returned HTTP 200, and unauthenticated PATCH/DELETE requests with a valid ULID path returned HTTP 401. No application errors appeared in the checked post-deployment log window.

Local verification: 267 backend tests and 2,361 assertions passed before the final tenant-isolation test was added; the 15 focused account tests passed afterward with 167 assertions. All 208 mobile tests, PHPStan, Pint, Flutter analysis, OpenAPI generation/lint, and the locked production Composer advisory audit passed. The physical account-switching flow on a phone still needs operator confirmation.
