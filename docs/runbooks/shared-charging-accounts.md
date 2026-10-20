# Shared charging accounts

## Audit and implementation map

This change extends the standalone platform. Its existing tenant, operator and organization boundaries remain in force; no external SaaS dependency is introduced.

Requirements: FR-ACC-001 through FR-ACC-006 in [Functional Requirements](../product/functional-requirements.md).

| Requirement | Existing implementation | Compatible extension |
| --- | --- | --- |
| Person and account identity | Identity User public ULID; Organizations Organization, Membership, RoleAssignment; Sanctum tenant middleware | Charging account metadata on Organization, exact organization-scoped roles; existing tenant membership retained |
| Invitations | Identity UserInvitation, hashed tokens, Laravel notifications | Purpose-isolated charging invitations, accepted only by the authenticated verified recipient |
| Vehicles | Assets DriverGarage and DriverVehicle; encrypted plates; owner-scoped API | Account ownership and explicit driver assignments on the existing garage/vehicle model |
| Funding | Billing PrepaidAccount/Entry/Reservation and double-entry ledger; Payments QrTopup/AUB verified callbacks | Separate organization-owned prepaid balances; initiating person remains recorded on top-ups |
| Charging | CustomerCharging quote/start, subject ownership, connector reservation, RemoteStart/Stop and PrepaidChargingMonitor | Quote-frozen account, driver, vehicle and payer; recheck access before start; settle the original wallet |
| Mobile | Flutter controllers, repositories, API client, design system, Account/Wallet/Activity/vehicle screens | Account selector and management, scoped vehicles/wallet, payer disclosure before start |
| Invoices and refunds | Financial backend records exist; mobile charging returns no invoice/receipt and `refundable=false` | No new invoice or refund execution contract; existing operational processes remain |

Before this change, the mobile charging request accepted `vehicle_id` but did not authorize or persist it. All charging and wallet ownership was personal. Tenant membership alone cannot represent multiple customer accounts: organization-scoped role assignments already provide that dimension and are reused.

## Scope and limits

One login represents one person. Personal accounts keep existing personal balances and garages. Business and fleet accounts have independent wallets and garages. No balance transfer or silent payer fallback is performed.

The optional driver and vehicle limits are **per-session reservation caps in PHP minor units**, not monthly budgets. The lower applicable cap limits the quote and is rechecked before a hold is reserved. Charging still uses the existing remote stop monitor; network loss can delay a physical stop and excess final cost enters review. Monthly allowances, subscriptions, memberships as commercial products and offline hardware limits are outside this implementation.

Invitations expire after seven days and are accepted once by the authenticated, verified recipient. Email directs the recipient to register/sign in, verify the invited email and accept in Charging accounts; it does not expose a bearer acceptance secret. Profile birth dates, mobile numbers and payment credentials are never included in member lists. Invitation email delivery failure is audited and does not hide the invitation from its recipient in the app.

## Roles and account boundaries

| Action | Owner | Admin | Billing manager | Fleet manager | Driver |
| --- | --- | --- | --- | --- | --- |
| Read own account membership | Yes | Yes | Yes | Yes | Yes |
| Invite members | Any non-owner role | Driver/fleet manager only | No | No | No |
| Change roles/revoke membership | Yes; cannot replace/revoke owner | No | No | No | No |
| Manage vehicles/assign drivers | Yes | Yes | No | Yes | No |
| Read/fund shared wallet | Yes | No | Yes | No | No |
| Set per-session driver/vehicle caps | Yes | No | No | No | No |
| Start charging | Account vehicles | Account vehicles | No | Account vehicles | Assigned vehicles only |
| Read account charging history | Yes | Yes | Yes | Yes | Own sessions only |
| Stop another member's account session | Yes | Yes | No | Yes | No |

No shared-account role exposes a member's personal wallet or private garage. Member removal expires its server role immediately, including requests made with an existing access token. Historical records remain available to permitted account roles and financial settlement keeps its original payer. Vehicle assignment rows are retained; a later deliberate reinvitation restores access to assignments still on file. Remove assignments if they should not survive a future reinvitation.

## Mobile setup and use

### Choose an account, not a different login

| Type | Use | Who can use its funds and vehicles |
| --- | --- | --- |
| Personal | Your own charging | Only you. Existing personal funds, vehicles and history stay private. It cannot accept invited members. |
| Business | Company/team charging from a company-funded wallet | Invited members according to their roles; Drivers require assigned vehicles. |
| Fleet | A managed group of vehicles and drivers | The same sharing controls as Business, organized as a fleet account. This label does not grant extra permissions or automated fleet services. |

You can own or join several accounts using one verified login. Use the actual radio control or tap an account row to select it. The current account is shown in Wallet, Activity and vehicle navigation; account selection is also available from those screens. Creating a Business/Fleet account selects the server-confirmed new account. Accepting an invitation loads and selects the joined account. Account choices remain available while supplementary details load.

Switching accounts does not convert one type into another, move wallet funds, copy vehicles or reassign past sessions. A newly created account starts with its own empty garage and wallet. Personal is the default after a fresh sign-in/app initialization; the choice is not persisted as a financial preference. Owners can rename a shared account or change its Business/Fleet label. This does not transfer ownership, funds, vehicles or history.

Owners may delete only a newly unused shared account. The server rejects deletion if another member, vehicle, wallet record, invitation or charging record still refers to it. Personal accounts cannot be edited or deleted. The app keeps the current account selected when deletion is rejected and shows the reason. Account detail reads use a separate rate limit from changes so switching between accounts does not consume the change budget.



1. Sign in with an individual verified account. Account → Charging accounts creates/loads the personal account without moving its existing balance or vehicles.
2. Choose Create business or fleet, enter an account name and select Business or Fleet. The creator becomes owner and the new account becomes selected. Personal profile settings remain separate.
3. Invite members by their registered email. They must already have an active registration in this platform. They sign in, verify that email and accept under Charging accounts → Invitations. Owners can change a member's role or revoke it later.
4. Select the shared account and open its vehicle list. Save the existing vehicle profile fields. Use Assign a vehicle to a driver to review or change access. A driver cannot create or edit shared vehicles.
5. The owner or billing manager opens Account wallet and uses the existing AUB QR Ph top-up flow. Wait for verified provider confirmation. Shared top-ups remain separate from personal funds and simulated books remain separate from live books.
6. The owner optionally sets a per-driver or per-vehicle cap. Empty removes it, zero disables new starts, and amounts are PHP per session. A lower cap changed after a quote requires a fresh review.
7. The driver selects the account and vehicle before scanning/entering a connector code. Review driver, vehicle, account, payer, tariff and reserved budget, then request start. Physical start/stop and final debit still depend on the existing charger confirmation and meter evidence.
8. Activity history is scoped to the selected account and role. Drivers can open their own account history. The person's current charging session stays visible with its original payer even when browsing a different account. Session details show the frozen attribution. A selected revoked account remains without permissions rather than silently becoming a personal payer.
9. You can switch accounts while a charge is active or pending, to manage members, browse vehicles or top up. A dispatched start/stop operation must finish before changing the selection. Switching never stops the charger or releases a hold. A new charging session remains blocked until the current request is resolved; use View current charging request. Unsent starts cancel immediately. Sent starts can now record a cancellation and request Stop when a transaction is known; unconfirmed outcomes still require reconciliation before funds can be released.

### Sharing checklist and common problems

- **Owner:** create the account, add vehicles, invite the other person's verified sign-in email, wait for acceptance, assign each Driver a vehicle, and fund this account's wallet. Owners, Admins and Fleet managers already have access to the account's vehicles; assignment is for Drivers.
- **Invited person:** sign in using the invited email, verify it, open Charging accounts → Invitations for you and accept. Each person keeps their own login and private Personal account. No password is shared with another member.
- **Empty shared wallet:** this is a separate balance, not a copy of Personal. Only the Owner and Billing manager can view or top it up. Drivers see their approved charging budget at review, not the full balance.
- **Driver has no vehicles:** an invitation is not a vehicle assignment. The owner or fleet manager assigns a vehicle after the invitation is accepted. Drivers cannot add/edit shared vehicles.
- **Admin cannot fund:** the Admin role manages people/vehicles/charging, not money. Invite a Billing manager for funding or use the Owner role.
- **Spending caps:** the owner can edit a driver's or vehicle's existing cap. Blank removes it; zero blocks new starts. Caps apply to each session's reservation, not a monthly allowance or guaranteed physical stop while a charger is offline.
- **Removing access:** the owner changes a role or removes a member in Members. History and existing financial attribution remain. Remove vehicle assignments too if a later reinvitation should not restore those assignments.

### Account selection repair — 1.0.19

The old page disabled every account while any session was nonterminal, so a long-lived unconfirmed start made the radio choices appear broken. It also left the previous account selected after creating or joining another account. The page now uses accessible radio controls, selects created/joined accounts, and loads secondary details without blocking selection.

Account changes invalidate only account-scoped quotes/history. They no longer invalidate the active session's realtime subscription, abandon a dispatched command, or leave an old history response in the newly selected account. New quote preparation is blocked for an unfinished session. Wallet selection is verified through the real app router, including replacing the displayed balance and hiding it from Drivers. Personal screens no longer fetch irrelevant shared-member details. Role guidance, validated create/invite forms and prefilled spending caps explain the next action.

The deployed staging API passed **25 rollback-only checks** on 2026-10-13, including Personal/Business/Fleet creation/listing, scoped wallet/vehicles, invitations, assignment, access denial and revocation. Test data was rolled back; no live payment or OCPP command was sent. The full backend suite passed **238 tests / 1,950 assertions** against the isolated test database. No backend release or migration is required for this mobile repair.

Mobile verification: **199 tests passed**, including the full router wallet-switch/permission check, actual radio taps during an active request, Business/Fleet creation, invitation acceptance, slow detail loading, spending-cap editing and stale-response isolation. Flutter analysis found no issues and formatting passed. Account screens were rendered and inspected at 320/390-pixel widths. The realtime and dispatched-start regressions failed before the controller correction and passed afterward. Installation and a supervised physical charging cycle are still device acceptance checks, not claims made by these automated tests.

Published Android **1.0.19 / build 20** on 2026-10-13. Both the versioned and latest demo URLs returned HTTP 200 with **79,988,878 bytes**, matching SHA256 `6c67e21d66e7e3d34e3da8007dd3ffca0246247f9d56f9bc9bc494755f903bd6`. The APK uses the existing staging signing certificate and supports an in-place update. Private publication evidence and the previous demo target are retained at `/opt/vtsa-releases/account-sharing-20261013/` on the staging VPS. See [download links](mobile-staging-demo.md).

## API and operational rollout

The published contract is `packages/contracts/openapi/charging-accounts.v1.json`, referenced by the platform OpenAPI entry point. New endpoints manage accounts, members, recipient invitations, assignments and limits. Existing vehicle/wallet/preparation/start/history routes accept the optional `X-Charging-Account-ID` ULID header. Missing header retains personal behavior. Explicit invalid/unauthorized selectors fail; no payer fallback occurs. Session-specific reads and stop/cancel authorize against the record's original account, independently of current selection.

Migration: `apps/platform/database/migrations/2026_10_13_000001_add_shared_charging_accounts.php`. Before release, back up the platform database, rehearse on a PostgreSQL copy, deploy the schema, then matching API/queue/charging-monitor code. Do not permit new shared starts until all charging settlement workers run the new code. Run `php artisan migrate --force` only within the configured platform runtime; do not run a fresh migration or demo seeder. Rebuild caches/restart workers using the existing deployment procedure, then release the mobile build.

Personal backfill is lazy and repeatable under a locked active tenant membership; no bulk reassignment, funding transfer or overwrite is required. Existing personal history remains visible. Unused schema can roll back; once any charging account exists the rollback refuses to erase attribution, so roll forward. Account foreign keys restrict deletion of referenced ownership records.

## Verification and release

### Personal account navigation regression

Opening Account → Personal previously called `ChargingAccountsController.load()`
from the account screen's `initState`. That synchronously notified the router
and the previous route's account selector during widget construction. A test
using `VtsaApp` reproduced `setState() or markNeedsBuild() called during build`;
the earlier standalone screen tests did not exercise this route transition.

The screen now starts its initial refresh after the first frame, with a mounted
check so a dismissed page cannot start the request. Router-level regressions
cover opening/reopening Personal, switching fleet → personal, offline retry,
Back while loading, and preserving an active charging request. No API, database,
wallet settlement or OCPP behavior changes are needed for this fix.

Verification on 2026-10-13: the navigation regression failed before the change
and passed after it; all **11 account tests** and the full **189 mobile tests**
passed, and Flutter analysis found no issues. No Android device or emulator
was attached, so installing the replacement APK on the affected phone and
repeating Account → Personal remains the device acceptance check.

Tests cover invitation expiry/recipient verification/reuse/revocation, role escalation, multiple accounts, private wallet/garage boundaries, driver assignment, cross-tenant/cross-account IDs, limits, duplicate holds/settlement, legacy personal history and original-payer settlement after revocation. Signed synthetic AUB callbacks exercise shared credits exactly once without contacting AUB. Gateway tests exercise existing OCPP protocol behavior; they do not prove a physical charging cycle.

Local verification on 2026-10-13:

- Platform: **237 tests / 1,947 assertions passed**, including unused-schema rollback/reapply and refusal to roll back populated account ownership. Pint and PHPStan passed. Tests use the repository's isolated SQLite database.
- Flutter: **185 tests passed** with `--concurrency=1 --timeout=120s`; the longer timeout accommodates existing PIN hashing without changing its assertions or production hashing. Analyzer reported no issues. Account screens were rendered and inspected at 320/390-pixel widths; charging journey tests also cover enlarged text.
- Android release APK compilation passed using `flutter build apk --release --dart-define-from-file=dart_defines.staging-demo.json`. This was local build verification, not a versioned distribution release or deployment. The existing `mobile_scanner` plugin reports a future Kotlin Gradle compatibility warning; it did not block this build.
- Gateway: **83 passed, 1 skipped**. The skipped Redis integration test requires `TEST_REDIS_URL`; no physical device or VPS was contacted.
- OpenAPI/event validation and generated API types passed. Composer's locked dependency audit reported no security advisories. No dependencies were added.

Real invitation email delivery, AUB shared-account callback delivery, concurrent load and a supervised EV charging cycle remain release acceptance checks. Mobile refunds and invoice downloads remain unavailable in the current charging API; do not treat UI/API placeholders as completed integrations. Monthly allowances, subscription products, payer transfers, owner transfer and offline hard spending limits need separate product decisions.

## Staging deployment — 2026-10-13

Deployed `vtsa-prepaid-platform:20261013-shared-accounts` to the staging API, charging queue worker, prepaid monitor, OCPP event consumer and OCPP authorization consumer. The native gateway retained its PID and configuration. This was an image release from the local working tree, with a source SHA256 manifest; it was not a Git push or pull. The runtime Compose overrides in `/etc/vtsa-csms/` select this image.

The remote PostgreSQL 18.6 database was backed up and restored into an isolated PostGIS container before migration. Staging was missing `2026_10_08_000001_add_driver_onboarding`; that additive prerequisite and `2026_10_13_000001_add_shared_charging_accounts` both passed rehearsal, repeated migration checks and live application. No existing user, wallet, charger or session records were removed.

The PostgreSQL rehearsal exposed a fractional duration bug: `MeterValueService` could write a fractional Carbon interval to a bigint. Durations now truncate to non-negative integer seconds, with a fractional timestamp regression test. The legacy maintenance migration test now restores its pre-migration schema before inserting a legacy state, then verifies the PostgreSQL constraint is validated again. No applied migration was edited.

Final checks:

- Full backend suite: **238 tests / 1,950 assertions** on SQLite; **238 tests / 1,951 assertions** on isolated PostgreSQL. Pint and PHPStan passed; the maintenance fixture change also passed its nine-test local suite.
- **20 deployed API checks** covered individual login, personal account bootstrap, fleet creation, invitation acceptance/recipient denial, vehicle creation/assignment, wallet privacy, limits, history and immediate revocation. All synthetic tenant/user/account records were rolled back. No real payment or charger command was sent.
- Public readiness, admin login and station discovery returned HTTP 200. Anonymous account and wallet access returned HTTP 401. All five updated containers stayed running without restarts or application error lines in the checked post-deployment window.
- Charger `202607010021` remained connected; a fresh heartbeat was observed at **2026-10-13 09:07:22 UTC / 17:07:22 Asia/Manila**.
- Android **1.0.17 / build 18**, package `com.vtsa.vtsa_mobile`, retains the previous staging signing certificate. Both versioned and latest demo downloads returned HTTP 200 with **79,890,462 bytes** and SHA256 `990cc850b72a99015f2bc78dc356eddd8bdc5e5fbcee01b76f067c4ebaea8475`. See [mobile download instructions](mobile-staging-demo.md).

Private deployment evidence and backup: `/opt/vtsa-releases/shared-accounts-20261013/` on the staging app VPS. It contains `database-before.dump`, source `manifest.json`, pre-deployment Compose overrides, PostgreSQL test results and deployment/API-check logs. The backup is root-only and outside the public web directory; it is a same-host recovery copy, not off-host disaster recovery. The isolated rehearsal database is removed after verification.

Rollback is forward-first after account use. Retain the additive schema and repair code forward once any charging account or shared attribution exists. Before any account use, previous image selections are preserved in the private Compose backups; restore all five services together. Never restore the entire database over newer payment or charger events as an automatic deployment rollback.

Existing operational issue: session `01m4qqakw4jfh9akwz685454x1` has remained `starting` since October 12 with `prepaid_start_unconfirmed`, no protocol transaction ID, no confirmed start and zero recorded energy. Its payment state is `requires_action`; the reservation remains held pending reconciliation. The current manual finalization workflow accepts finalizing/review-required sessions, so it does not resolve this unconfirmed start directly. This deployment does not manufacture a StopTransaction, release that hold or declare the physical charging cycle successful. The operator must establish the charger outcome before reconciliation and another supervised charging test.

## Registered invitations and start cancellation � 1.0.20

New invitations require an enabled, registered user with an active membership in the current tenant. Email matching is trimmed and case-insensitive. Unknown, disabled and other-tenant-only recipients receive the same email validation error; no invitation or notification is created. Existing account members must be managed through Members rather than reinvited. Acceptance still requires the correct verified recipient and current inviter permission. This is enforced by Identity with Organizations' active-membership query, not just by the app.

The invitation form stays open on validation failure, preserves the entered email/role and displays the server's error beside the email field. Account selection, incoming invitations and member management have separate labelled sections; guidance remains available below the controls.

The app offers Cancel pending start immediately while a start is pending. An unsent start is cancelled atomically and its reservation settled to zero. Once dispatch has begun, cancellation records `customer_cancel_requested` in the existing session cancellation reason and increments its version. It does not falsely terminate a session or release held funds. Repeated taps return the same intent. The prepaid monitor requests a stop once a transaction ID exists, including after a late StartTransaction. Rejected stop attempts are bounded at three; uncertain/unconfirmed outcomes remain held for operator review. The app shows Cancellation requested, recovery instructions and the session reference. Charge/payment terminal evidence remains necessary; a successful cancellation API response alone is not proof the charger stopped.

No database migration or OCPP gateway change is needed. Release the compatible platform API, charging worker and monitor before the APK. Retain the additive response field `cancellation_requested` for older clients. Never manually clear the physical session or release a real hold solely because the app shows zero energy.

Staging backend deployed on 2026-10-13 using image `vtsa-prepaid-platform:20261013-invite-cancel`. API, OCPP event/authorization consumers, charging worker and prepaid monitor all run that image. Native gateway PID was preserved, readiness/admin login returned HTTP 200 and the checked post-deployment logs contained no application error lines. Private source manifests, candidate-image tests, previous Compose selections and rollback-only API evidence are at `/opt/vtsa-releases/invite-cancel-20261013/`. No real payment, cancellation or physical charging command was triggered during verification.

Verification: the invitation and dispatched-cancellation regressions failed against the previous behavior and passed after the fixes; the complete backend suite passed **241 tests / 1,986 assertions**, and the deployed candidate image passed **23 focused tests / 192 assertions**. **27 live API checks** passed using synthetic records that were rolled back and faked notification delivery. The mobile suite passed **203 tests**, with eight focused foundation/error-parser checks after adding the field-error regression. Flutter analysis, PHPStan, Pint, contract validation/generation and the locked Composer advisory audit passed. No dependencies were added or upgraded. Physical phone/EV confirmation is still required.

Published Android **1.0.20 / build 21**: both versioned and demo downloads returned HTTP 200 with **79,989,002 bytes** and SHA256 `023d3096e3381fa6773e67a2fa5f611a71228a0063c18b3e33fd6df6065036b7`. Signing certificate matches 1.0.19; install over the previous app. APK verification and the prior demo alias are retained in the same private release evidence directory. The existing mobile_scanner Kotlin compatibility warning remains nonblocking with the pinned Flutter version.
