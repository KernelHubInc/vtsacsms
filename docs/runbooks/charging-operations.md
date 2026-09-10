# Charging Operations Runbook

## Required workers

Run the normal Laravel queue worker for command delivery and realtime work:

```powershell
Push-Location apps\platform
php artisan queue:work --queue=default --tries=3 --timeout=90
Pop-Location
```

Run one or more normalized event consumers with a stable process supervisor. The Redis consumer group coordinates multiple instances:

```powershell
Push-Location apps\platform
php artisan charging:consume-ocpp-events
Pop-Location
```

Run the authorization request consumer independently so an overloaded event consumer cannot delay charger authorization replies:

```powershell
Push-Location apps\platform
php artisan charging:consume-ocpp-authorizations
Pop-Location
```

Run Laravel's scheduler continuously. It evaluates persisted command and start deadlines once per minute:

```powershell
Push-Location apps\platform
php artisan schedule:work
Pop-Location
```

For a bounded diagnostic pass, use `charging:consume-ocpp-events --once`. Do not run the normal consumer without supervision in production.

## Safe operational checks

```powershell
Push-Location apps\platform
php artisan about
php artisan schedule:list
php artisan charging:expire-operations
php artisan integrations:publish-outbox --limit=100
Pop-Location
```

The expiry command is idempotent and tenant-aware. It may be run manually after scheduler downtime. It does not mark an acknowledged remote start active; absent physical transaction evidence, the session expires and its connector reservation is released.

Check the operator Filament diagnostics for session state/evidence, command correlation and terminal outcome, CDR state, anomalies, and review queue. Never resolve a meter anomaly without examining the retained normalized event timestamps and readings.

## Failure triage

| Symptom | Check | Safe action |
| --- | --- | --- |
| Commands remain `requested` | Queue worker, gateway URL/credential injection, command deadline | Restore the worker; allow expiry workflow to close stale pre-start attempts |
| Commands are `delivery_unknown` | Gateway logs by correlation ID and charger connection ownership | Do not blindly replay remote start; confirm protocol/session evidence first |
| Session remains `starting` after acknowledged command | OCPP event consumer, Redis stream pending entries, charger transaction event | Restore event consumption; never force `charging` from command state |
| Session remains physically active after disconnect | Gateway connection history and later transaction/meter evidence | Keep last evidenced physical state; use manual review only under approved policy |
| CDR is `review_required` | Anomaly flags, ordered Wh samples, UTC source/receive times, tariff snapshot hash | Record an audited complete/estimated/unbillable decision; never edit finalized CDR evidence |
| Connector is stuck `reserved` | Open reservation, expiry scheduler, session start deadline | Run expiry command; only clear manually through the application workflow |

Redis is transport/coordination, not durable business truth. PostgreSQL inbox, transition, session, meter, CDR, audit, and outbox records are the source for reconstruction. The scheduled outbox publisher provides at-least-once delivery, so consumers must deduplicate by `event_id`. Production stream retention, pending-entry recovery, dead-letter policy, and a more durable broker remain open architecture decisions.
