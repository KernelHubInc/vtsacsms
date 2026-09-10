# Portal Export Operations

## Generate exports

Users with `reporting.view` can open **Queued exports**. Users also holding `reporting.export` may queue session, asset, or finance CSV files. A queue worker must be running:

```powershell
php artisan queue:work --tries=3
```

Completed exports are stored on the private `local` disk under:

```text
portal-exports/{tenant_ulid}/{export_ulid}.csv
```

Do not expose this directory through the public storage link.

## Diagnose a failed export

1. Find the `portal_exports` record inside the affected tenant.
2. Check `failure_code`; application exceptions are not persisted in the record.
3. Search structured logs by `job_id`, `tenant_id`, and `correlation_id`.
4. Confirm the tenant is active, the human actor is enabled, membership is active, and `reporting.export` remains assigned.
5. Confirm the configured private disk is writable.
6. Requeue by requesting a new export. Do not change the requester or tenant on an existing record.

`authorization_revoked` is an expected security outcome when permission or membership changes before execution. `generation_failed` requires log investigation.

## Retention

Automated retention is an open decision. Until approved, completed files are private durable artifacts and must not be deleted without explicit operational approval and an audit trail.
