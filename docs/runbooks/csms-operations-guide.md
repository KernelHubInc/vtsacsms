# CSMS instruction guide

Audience: platform administrators, operators, support and release engineers. The UI and permissions depend on the signed-in tenant and role. This guide describes existing workflows; a feature's presence does not mean it is enabled or approved for production use.

## 1. Access and roles

Use `https://staging.evcspowersolutions.com` for training with synthetic data and `https://evcspowersolutions.com` for approved live operations. Administrators enter `/admin`, operators enter `/operator`, and drivers use the approved mobile app or `/mobile/` browser app. Keep demo accounts, seeders and practice records in staging.

For a pending administrator account, an authorized deployment operator can issue a unique temporary password and hand it directly to the intended recipient. On the same environment's `/workforce/setup` page, enter the account email, temporary password and display name, then choose and confirm a personal password. The temporary password works once within 24 hours. After setup, sign in at `/admin` with the personal password. See the RBAC user guide for the training walkthrough and the initial administrator enrollment runbook for issuance.

Sign in with an activated account and current membership. Invitations are scoped, expire and must be accepted by their intended recipient. Use the password-recovery workflow; do not send passwords in chat. Disabled accounts, inactive tenants and expired/revoked memberships must be denied by the server. Ask an authorized administrator for missing permissions rather than working around hidden buttons.

MFA-required accounts cannot use panels until a verified browser challenge flow exists. This is a fail-closed safeguard, not a working MFA enrollment feature. A device PIN/biometric unlock is a local mobile gate and does not satisfy privileged server MFA. Google login is native-Android-first and disabled by default; production needs its own approved client/signing configuration.

## 2. Configure an operator and location

An authorized administrator uses the operator onboarding wizard to select/create the charge-point operator and issue its scoped invitation. Assign only the permissions and organization/site scope the operator needs. Preserve the grantor's authority limits. Use membership suspension and role workflows to remove access; deleting an invitation is not equivalent to revoking an accepted membership.

In Charging network, create the Site with the actual operator, address, coordinates and IANA timezone. Enter the real weekly opening hours. Enable Active/Public and use Publish location only when ready for discovery. A site is a physical place, a charging station is an OCPP device, an EVSE is an independently controlled outlet, and a connector is its physical plug.

## 3. Register equipment

Open Charging Stations and create the station at the correct site. Enter the exact manufacturer-confirmed charge-point identity, serial number, OCPP version and QR identifier. Identities are case-sensitive operational bindings, not display names or secrets. Never connect a simulator using a physical charger's identity.

In Station setup, add the real EVSE numbers and physical connectors. Enter connector numbers, plug type, AC/DC and power in watts. 7 kW is 7000 W; energy is measured in Wh/kWh. OCPP 1.6 connector numbers must be unique across the station; connector 0 is station-level status. Do not infer multi-outlet mapping from another model.

Use the manufacturer-approved WSS endpoint and security settings supplied by the administrator. Enter the identity once, either appended by firmware or in the full URL. URL-only registration is an accepted security exception with impersonation risk; use approved network restrictions. Do not disable TLS or invent credentials to make a device connect.

Reconnect only while idle. Confirm recent connection evidence and fresh per-connector status. Heartbeat alone does not prove a usable outlet. An Active database record does not clear a device fault.

## 4. Configure pricing and publication

Create a tariff in the approved currency and use the intended pricing dimension: energy, session or energy-flow time. Scope a test price to the exact connector. A blank connector can broaden applicability to a site or tenant. Use approved tax and platform-fee policy only; do not invent tax treatment. Publish only after reviewing effective pricing in Station setup and the driver quote.

The backend stores money in integer minor units with currency, energy in Wh, power in W and durations in seconds. Prices shown in the UI are presentation units. New tariff versions apply to new sessions; active sessions retain their snapshot. Timestamps are persisted in UTC and schedules/display use the location timezone.

Before public handover, verify map location, hours, equipment, pricing, fresh connector state and a supervised complete transaction. Draft or inactive configuration should not be presented as ready to charge.

## 5. Driver registration and wallet

Drivers complete account/profile, vehicle or plate-pending information, and explicit terms/privacy acceptance. Verify email through the delivered expiring link. Complete KYC only when enabled and required. Optical identity checks do not verify government issuance; unavailable verification needs the documented review workflow.

When Google is enabled on the approved native client, choose the account and complete the same required profile/terms flow. Linking to an existing password account requires its current password. A matching email does not authorize an automatic account merge. The browser client hides Google until its distinct browser integration is approved.

Choose the intended personal or shared charging account before adding funds. Confirm amount, currency, payer/destination and provider result. A pending QR/top-up is not a credited balance. Never edit ledger balances or fabricate callback evidence to resolve a payment. Real payment availability depends on production configuration and provider acceptance.

## 6. Charging a vehicle

Select the saved vehicle, find the correct published station and scan its connector QR or supported station identifier. Confirm plug compatibility, actual outlet, current tariff, fees, available wallet funds and reserved budget. Shared-account limits can reduce the reservation below the full balance.

Plug in according to the manufacturer's instructions, review the charge and request start once using the app's confirmation control. Wait for actual charger/session evidence and increasing energy. Pending, Preparing or an accepted command does not prove charging began. Do not create repeated requests to force progress.

To stop, use the app's stop action and wait for confirmed completion. Automatic prepaid stopping is a remote request, not a hardware spending cutoff. Connectivity, delayed meter data or charger refusal can exceed the reservation. An unconfirmed stop requires operator attention and the device's approved physical safety procedure. Never disconnect unsafely or simulate final readings.

After completion, check final energy/cost, one wallet debit, unused-reservation release and receipt status. Incomplete or over-budget evidence may remain under review with a hold. Do not manually mark payment/session success to conceal the anomaly.

## 7. Operator diagnostics

Station setup shows connection freshness, physical connector mapping, effective tariff and operating hours. Online plus Unknown usually needs valid outlet mapping and fresh StatusNotification. Offline needs device/network/power investigation. Faulted/Unavailable needs maintenance and the device display; changing lifecycle status is not a repair.

Use Session diagnostics, Command diagnostics, Status and faults and the Session review queue. For a stuck pending start, use its guarded cancellation workflow where appropriate and wait for evidence before releasing funds. For automatic_stop_unconfirmed or remote_stop_unconfirmed, investigate the device, gateway route, monitor and charging worker with support. Keep stop/monitor processing available when new starts are disabled.

Record tenant-safe session/command reference, exact station identity, EVSE/connector, timestamp and timezone, displayed error and correlation ID. Share only redacted diagnostics. Never include tokens, raw identity documents, passwords or payment secrets.

## 8. Reports, finance and audit

Open the reports available to the assigned role and scope. Queued exports require reporting permissions at request, execution and download. A revoked membership can legitimately prevent completion. Keep exports private; possession of a file path is not authorization. Re-request a failed export through the UI instead of changing its requester or tenant.

Review wallet/payment/charging exceptions through owning workflows. Reconcile provider evidence and ledger entries; use linked corrective records where supported. Do not overwrite settled history, cross tenant boundaries or treat simulated funds as production accounting. Settlement, refunds, invoicing and provider-specific behavior need their own approved operating scope.

Activity log records supported authentication, permission, vehicle, wallet and charging evidence. The ledger is hash-chained; external anchoring remains an assurance gap. Keep exports/logs only for the approved retention period and use authorized erasure/retention procedures.

## 9. Daily operations and incidents

Check readiness, both production node roles, queue age/failed jobs, scheduler/prepaid monitor, connector freshness, unconfirmed stops, payment reconciliation, private storage, mail delivery, disk space and backup age. Readiness proves configured database/Redis access, not physical charging or end-to-end email/payment success.

For a suspected compromise, preserve safe evidence and correlation IDs, notify the designated incident owner and restrict the affected identity/tenant/integration using approved controls. Rotate secrets through the protected secret mechanism. Avoid copying production data into staging or public issues. A billing discrepancy must preserve financial history while investigated.

For an outage during release, use the deployment checklist's stop/rollback steps, retain the healthy node and previous mobile release, and do not run destructive database commands. Resume normal traffic only after technical health and the affected business checks pass.

## 10. Handover record

Record approved environment/origins, release SHA, active/inactive feature scope, operator/tenant roles, station mappings, effective tariffs, acceptance session references, support contact ownership, backup/restore evidence and unresolved decisions. Contact names, RPO/RTO, tax rules and vendor behavior must be supplied by their owners. The security audit and deployment release record accompany this guide; neither is a blanket production-launch authorization.
