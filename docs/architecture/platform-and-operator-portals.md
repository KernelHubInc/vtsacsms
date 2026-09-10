# Platform and Operator Portals

## Scope

Phase 11 exposes existing bounded-context application capabilities through two Filament panels:

- `/admin` is the platform-administration panel for an explicitly selected tenant.
- `/operator` is the operator workspace for tenant-wide or site-scoped users.

The panels do not create a new ownership layer. Locations, Assets, Charging, Tariffs, Payments, Billing, Settlements, Identity, Organizations, CMS, Integrations, Audit, and Reporting continue to own their data and workflows.

## Authorization boundary

The tenant context middleware runs after Filament authentication and before panel pages or resources execute. Every tenant-owned Eloquent model retains the `BelongsToTenant` global scope. Site-dependent resources additionally constrain their base query through `AccessibleSitesQuery` or `AccessibleChargingSessionsQuery`.

Financial records that cannot be proven to belong to one site require a tenant-scoped permission. Payment intents linked to charging sessions are the exception: their resource and export query intersect billable session IDs with the actor's accessible sessions.

UI visibility, navigation groups, table filters, and map filters are convenience controls, not authorization controls.

Operator-user administration calls the audited Identity and Organizations application services. Invitations carry an explicit tenant or site scope, role assignment applies the non-escalation rule (a grantor cannot delegate authority they do not hold), and membership suspension revokes mobile tokens. Custom role composition applies the same non-escalation rule and keeps system roles immutable. Permission definitions remain code-governed through `PermissionCatalog`. Remote-stop actions likewise call the Charging application workflow and require `charging.remote_commands.execute` for the session's exact site.

## Dashboard projection

`PortalDashboardQuery` owns the read-only operational projection used by both panels. It:

- calculates aggregates from accessible site and session query contracts;
- batches map status, asset counts, and active-session counts to avoid N+1 queries;
- caches summaries for 60 seconds and map snapshots for 30 seconds;
- includes the tenant, user, and a hash of the exact accessible-site ID set in every cache key;
- stores money as integer minor units, energy as watt-hours, and duration as seconds until presentation;
- excludes stale signals from the availability denominator.

Inventory reorder alerts are calculated from accessible warehouses, available-custody movements, active reservations, and configured reorder points. Phase 13 adds a separate Maintenance dashboard projection for open work, SLA breach, downtime, availability, MTTA, MTTR, repeat failures, integer-minor-unit cost, net part consumption, and warranty recovery. It intersects the actor's Maintenance-authorized sites and returns zero for empty evidence instead of synthetic values.

The operator panel now exposes site-scoped work orders, incidents, preventive plans, and a mobile-first technician workboard. The platform panel reuses the same resources and policies inside the active tenant; neither panel relies on table filters for isolation.

Global master-list records are managed through a type whitelist. Creation and archival are audited; the portal does not expose destructive deletion for referenced records. Hierarchical and model-specific master data continues to use the existing dedicated APIs/import workflows rather than a generic editor that could bypass required relationships.

## Network map

The internal maps use the shared provider factory selected by ADR 0014. Leaflet plus `Leaflet.markercluster` implements OpenStreetMap; Google Maps JavaScript API plus MarkerClusterer implements Google. The server supplies only sites authorized for the current user. Client filters support text and status, while fault, active-session, stale, offline, and available marker states remain visible in both the map and accessible list.

OpenStreetMap is the default and fallback. The map remains usable as a station list when a provider fails. No private credential is returned by the mobile configuration endpoint; any public Google browser key is injected only into the web adapter and must be restricted as described in the Google Maps runbook.

## Queued exports

Portal exports are durable `portal_exports` records and tenant-aware queue jobs. An export records its ULID, tenant, requester public ULID, type, filters, state, private-storage path, row count, and completion/failure state.

The queue envelope propagates tenant, human actor, correlation, job, and causation ULIDs. At execution time the middleware verifies the tenant and membership again; the job then rechecks `reporting.export` and reconstructs the actor's current site scope. Generated CSV files:

- contain only safe reporting fields;
- never contain payment tokens, provider credentials, PAN, CVV, or secret configuration;
- escape spreadsheet-formula prefixes;
- are written to private local storage under a tenant-specific path;
- are downloadable only by the requesting user from the tenant-scoped panel page.

## Open decisions

- Decide whether expensive historical Maintenance metrics require a dedicated reporting projection beyond the current bounded queries.
- Decide whether completed export files require automatic retention/deletion and an object-storage lifecycle policy.
- Decide whether platform administrators may assume a tenant or require a dedicated cross-tenant support workflow. The current implementation remains single-tenant per request.
- Define editable, non-secret tenant settings. Deployment and secret-managed configuration is intentionally read-only in the portal.
- Select a browser automation environment if end-to-end JavaScript tests beyond HTTP and Livewire boundaries are required.
