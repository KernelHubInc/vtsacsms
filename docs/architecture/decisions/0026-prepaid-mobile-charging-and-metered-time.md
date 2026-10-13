# ADR 0026: Prepaid mobile charging and metered time

Status: implemented for staging validation; physical charger acceptance pending.
Date: 2026-10-12. Owners: Charging, Tariffs and Billing.

## Decision

The operator requested PHP 1 per kWh for charger `202607010021`, with admin/operator control over energy-only, once-per-session and time prices. Time means energy flowing, not elapsed time including pauses.

Tariffs publishes a new immutable version from the portal. An append-only applicability ending closes the previous version in the same scope without changing its price or historical snapshots. New starts cannot select an ended version. Existing sessions retain their original snapshot. Publishing requires both manage and publish permission, with tenant checks and audit evidence. Portal prices use decimal strings converted to integer minor units; energy uses Wh and time uses seconds.

New time versions use `energy_flow`. Previously published versions and snapshots retain their existing elapsed-time meaning. Metered time is a conservative sampled measurement: count only adjacent positive, aggregate `Power.Active.Import` samples no more than 60 seconds apart. Exclude zero-power, ambiguous duplicate timestamps, known paused periods and unobserved intervals. Do not extrapolate start/end boundaries. Insufficient coverage routes final time billing to review. This sampling can undercount brief transitions; it is not a certified continuous timer. Configure the device for approximately 15-second aggregate Wh/W readings and validate its actual output before using time pricing commercially.

## Customer charging

Customer routes derive tenant and subject from a verified mobile token. Preparation resolves exactly one published active connector and persists an expiring quote. The pilot supports one all-day energy, session or time component, with explicit tax treatment and no hidden discount, minimum, maximum, parking or idle fee. More complex tariff combinations remain available in the tariff engine but are rejected for this mobile pilot.

Start serializes per customer and connector, rechecks the published price and availability, reserves live prepaid funds through Billing, and records ownership before command dispatch after commit. A plugged-in `Preparing` connector is eligible if there is no other active session and its connection is current. The quote discloses the reserved budget. The initial test limit is 100 minor PHP units (`MOBILE_CHARGING_MAXIMUM_RESERVATION_MINOR`), bounded by available wallet funds.

Only final, complete, PHP CDR evidence settles a reservation once. Unused funds are released. Definite rejection or expiry before dispatch releases the hold; an uncertain dispatched start retains both its pending intent and funds for reconciliation. Duplicate, expired, changed-price and other-customer requests cannot create another charge. Stop acknowledgement never means physical completion.

The monitor runs every ten seconds, with a 90-second maximum-power allowance when assessing the remaining energy/time budget. Missing Wh telemetry (or W telemetry for time pricing) for 60 seconds requests a stop. This is a remote spending control, not a guaranteed hardware limit: network loss and charger refusal can delay stopping. Excess final cost, missing evidence or unknown command outcomes require operator review and must not overdraw the wallet or invent a successful charge.

Charging commands use the `charging` queue. A worker and prepaid monitor must be healthy before enabling mobile starts. Existing unrelated queues must not be replayed as a deployment shortcut.

## Deployment and rollback

Migrations `2026_10_12_000001` and `000002` are additive. Deploy compatible code to web, OCPP event consumers and command workers before enabling remote starts. Retain original published tariffs, CDRs, wallet entries and AUB payment records. Roll forward after usage; rolling back the schema would delete financial correlation evidence. Disable new remote starts first when recovering; preserve Stop and session reads for active customers.

## Remaining acceptance

Validate physical start, Wh/W units, pause/resume, remote stop, disconnected recovery, exact final debit and wallet release on the Aoening charger with an EV present. Do not infer success from a simulator or a WebSocket handshake. Refund execution, tax/legal invoice content, combined/time-of-day mobile tariffs and hard offline energy caps are separate decisions.
