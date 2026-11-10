# ADR 0029: Automatic prepaid stop delivery and recovery

Status: Implemented candidate; staging acceptance required before production.

Date: 2026-11-05. Owners: Charging and Tenancy.

## Context

The scheduled prepaid monitor runs as a service, but charger-command queue middleware previously required a human actor. An automatic stop could therefore expire without dispatch. Expired stops also left the session `stopping`, outside the monitor's retry path. The driver must not have to confirm a spending-limit stop or repeat it manually.

## Decision

Tenancy exposes `TenantServiceJob`, an explicit application contract evaluated inside an active tenant context. Service envelopes require a null human actor and a job-specific persisted authorization check; all other service jobs remain denied. Human membership checks are unchanged. Charging implements this contract only for remote stops matching the persisted session, station, connector, protocol transaction and customer quote/budget. The command actor must match the recorded customer. Allowed reasons are prepaid spending control or a recorded pending-start cancellation. Logging out, disabling a driver or revoking future-start permission must not disable an already authorized safety stop. Service jobs cannot authorize remote starts.

Charging computes a conservative forward cost from the frozen tariff/fee snapshot using maximum connector power. The horizon is the greater of the existing 90-second allowance and gateway timeout plus the ten-second monitor interval, plus meter age and the largest observed dispatch delay among the station's five latest dispatched commands (queue-delay contribution capped at 300 seconds). Time tariffs use the older required Wh/W timestamp. Missing or stale required telemetry still triggers stopping. Monetary values remain integer minor units, energy Wh and duration integer seconds. Forecast evidence records the budget, projected cost, horizon, reading age, queue delay and stale-meter trigger in the existing audit/transition records.

An automatic stop is recovered without driver action. The monitor expires a requested command whose deadline has elapsed and may create a new idempotent intent after a minimum 30-second attempt interval. Explicit rejections may also retry. The default limit is three attempts per reason/session. Every retry preserves the same transaction identity. Terminal commands are never reopened. A dispatched command is not sent again by duplicate queue delivery.

The monitor, expiry scheduler and dispatcher lock the session before its command, including when recording a gateway response. This consistent order avoids deadlocks between stop recovery and command expiry/outcome handling. Network requests remain outside database transactions.

Accepted, timed-out or delivery-unknown commands are ambiguous. A retry requires the same still-active transaction, a currently online station, and continuing energy-flow evidence sampled after both a 60-second confirmation window and the current connection's start. Evidence is either a fresh positive aggregate W sample or two strictly increasing aggregate Wh samples. Heartbeats, a zero-power sample, a single final meter reading, future/reset/out-of-order samples and stale connection data cannot authorize such a retry. Missing physical end evidence is never invented. Recovery still depends on the device reporting trustworthy transaction telemetry; vendor-local hard budget limits remain separate capability work.

After the confirmation window or attempt exhaustion, Charging records the non-billing-blocking `remote_stop_unconfirmed` anomaly and one `automatic_stop_unconfirmed` audit transition for operator monitoring. A delivery problem alone does not change the customer's payment to `requires_action`. Existing final-evidence, over-budget and ledger protections remain authoritative. Clean final transaction evidence settles the reserved wallet once, releases unused funds and queues its existing receipt automatically. Over-budget or incomplete CDRs still need financial review and cannot overdraw a reservation.

The production Compose worker profile includes a dedicated `charging-worker`; the general worker consumes default/reports/invitations only. Both run only on App01. App02 production remains web-only. The deploy helper includes the dedicated worker on the primary, and release checks reject it on App02. Staging's isolated existing charging worker is preserved/verified separately.

## Operational consequences and acceptance

No database migration or mobile API change is required. Update API, command workers and prepaid monitor together; mobile/native/browser screens already display `stopping` and poll the session. The server monitor runs with the app closed. Keep Stop/read/settlement services available when new starts are disabled. Do not replay historical failed jobs as a rollout shortcut.

Deploy to staging first. Confirm actual command dispatch, charger acceptance, physical stop, final Wh, wallet settlement and receipt in a supervised low-budget EV test. Automated tests/simulators do not establish physical charger compliance. Production promotion is explicitly deferred until that test succeeds. Roll back the application/worker image together if necessary, preserving all audit, CDR and ledger evidence; no schema rollback is needed.

The historical test session whose collection was explicitly cancelled remains unchanged. This release does not re-collect that session, clear financial review records or send historical device commands.
