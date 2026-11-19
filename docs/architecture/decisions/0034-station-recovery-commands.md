# ADR 0034 — Authorized station recovery commands

- Status — Accepted for staging verification
- Date — 2026-11-19
- Owners — Charging, Assets, Organizations

Charging owns station recovery intents in `charging_commands`. Station commands
have a nullable session reference and a nullable connector reference for restart.
Existing session commands retain their identifiers and event schemas. Recovery
uses `charging.station_command.state_changed.v1`, with station ID, optional
connector ID, command type, state and safe reason in the existing event envelope.
Requested intent and command outcomes also produce audit evidence.

Organizations defines `charging.connectors.unlock` and `charging.stations.restart`.
Asset view and the exact operation permission must both cover the station's site.
Request and dispatch each recheck the human actor and current authority. Existing
bootstrap permission plans remain frozen so pending enrollments can complete.
Permission catalog synchronization alone grants no recovery access.

An operator confirms locally that energy flow has stopped and records a reason.
The backend additionally requires an active station, an online connection, fresh
idle reports on that connection for every active connector, and no pending,
active or unsettled charging session or unexpired connector reservation. A
station lock serializes recovery requests with remote starts. A pending recovery
or a recently acknowledged or uncertain result delays a new remote start.
Dispatch repeats these checks and binds to the original connection. A reconnect,
expired command or revoked permission prevents dispatch.

OCPP 1.6 restart uses `Reset` with `Soft`. OCPP 2.0.1 uses `Reset` with `OnIdle`.
Unlock uses the physical connector number and, for 2.0.1, its EVSE number. Wire
responses count as acknowledged only for `Unlocked`, or `Accepted`/`Scheduled`
for restart. Confirmation of restart requires new physical heartbeat and
connector reports. Commands are never retried automatically after uncertain
delivery. Recovery never settles a wallet or changes a charging session.

The gateway allowlist must explicitly include `Reset` and `UnlockConnector`.
Existing production start/stop and payment settings must survive deployment.
Exercise protocol responses, tenant/site isolation, permission revocation,
freshness, active-session rejection, duplicate delivery and expiry in staging
before production. A physical restart or unlock needs an idle supervised device.

The additive migration makes only the session and connector references nullable.
Roll back application images while retaining nullable columns and command/audit
history. Observe command queues during a rolling deployment because an older
worker cannot deserialize the new command types. Stop and drain the charging
worker before applying new types, then start the worker from the new image.
