# Maintenance Operations Runbook

## Purpose

This runbook covers Phase 13 incident automation, preventive generation, SLA monitoring, technician work, Inventory parts integration, and recovery from failed automation. It does not define charger-vendor behavior, legal warranty terms, tax treatment, or production credentials.

## Scheduled Automation

Run the tenant-safe automation manually:

```powershell
cd apps/platform
php artisan maintenance:run-automation
```

The Laravel scheduler runs it every five minutes with overlap protection. For each active tenant it establishes a service tenant context with a new correlation ULID, then:

1. generates due preventive work idempotently;
2. escalates persistent selected faults;
3. validates recovery candidates after their configured stable interval; and
4. emits one acknowledgement/resolution SLA-breach notification per target.

The command reports counts only. It must not print attachment paths, customer data, credentials, or raw OCPP messages.

## Incident Triage

- Confirm the incident tenant, site, asset, normalized fault code, first/last observation UTC time, occurrence count, and source event references.
- A missing active incident may be correct when no enabled tenant rule selects that fault code.
- Replayed source events must not increase the occurrence count.
- Do not create a charger-specific rule until its approved normalized fault semantics and operational owner are documented.
- A healthy status starts a recovery candidate. It does not resolve the incident until the configured stable interval elapses.

## Work-order Recovery

- Never edit transition rows or change a closed/canceled order back to an active state.
- If work recurs after closure, use the follow-up/reopen operation to create a new linked work order.
- If a transition is rejected, address its guard: assignment, skill validity, checklist/safety result, resolution evidence, separated verifier, parts consistency, or asset lifecycle.
- If an asset return-to-service request fails, leave the work order short of closure and investigate the Assets-owned lifecycle. Do not update the asset table manually.

## Parts Recovery

- Confirm a `maintenance_work_order` reservation exists before issue.
- Inventory issue and unused return must each have a unique stable idempotency key.
- Check `inventory_stock_movements`; quantity-on-hand is never repaired by updating a balance field.
- Release unused active reservations when work is canceled or no longer needs the part.
- A wrong movement requires an approved compensating Inventory movement, not mutation or deletion.

## Attachments

- Evidence is stored privately under a tenant/work-order prefix.
- Allowed Phase 13 types are JPEG, PNG, WebP, and PDF, up to 10 MB.
- New uploads have `scan_status=pending`. Do not expose or treat them as accepted evidence until a production-approved scanner marks them clean.
- Compare the stored SHA-256 checksum when investigating file corruption.

## SLA and Dashboard Checks

- Targets are UTC instants; display conversion uses the user's or site's IANA timezone.
- Approved hold states record the pause start, accumulated integer seconds, reason, and review time. Automation skips breach notification while the clock is paused.
- Dashboard availability is the selected period's asset capacity less overlapping downtime. MTTA measures report-to-acknowledgement; MTTR measures actual start-to-verified repair.
- Empty periods return zero, not invented values.

## Verification

```powershell
cd apps/platform
vendor\bin\pint --test
composer analyse
vendor/bin/phpunit --do-not-cache-result tests/Feature/Maintenance/MaintenanceWorkflowsTest.php
vendor/bin/phpunit --do-not-cache-result

cd ../../packages/contracts
npm.cmd run lint
npm.cmd run build
```

For PostgreSQL validation, run `php artisan migrate:fresh --force` against an isolated disposable database with PostGIS available. Never point this command at a shared or production database.
