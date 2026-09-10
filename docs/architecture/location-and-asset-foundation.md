# Location and Asset Foundation

**Status:** Implemented Phase 4 baseline  
**Owners:** Locations and Assets bounded contexts

## Scope and hierarchy

The implemented operational hierarchy is:

```mermaid
flowchart LR
    operator["Operator organization"] --> site["Site"]
    site --> station["Charging station"]
    station --> evse["EVSE"]
    evse --> connector["Connector"]
    connector --> component["Asset component"]
```

Locations owns administrative geography, sites, WGS 84 coordinates, operating hours, amenities, parking rules, site media, publication, and public discovery eligibility. Assets owns manufacturer/model/firmware catalogs, OCPP identity metadata, station/EVSE/connector/component hierarchy, communications and metering assets, vehicle compatibility, lifecycle, and station media.

Operators and site hosts remain typed Organizations records. The `Operator` and `SiteHost` domain models are type-constrained views of that source of truth; Phase 4 does not duplicate organization ownership.

## Coordinates and spatial queries

PostgreSQL stores validated latitude and longitude input columns and generates `sites.coordinates geography(Point,4326)`. A GiST index named `sites_coordinates_gist` serves radius, bounding-box, and distance queries.

- Nearby search uses `ST_DWithin`, then orders by `ST_Distance`; distances are integer-compatible meters at the API boundary.
- Bounding-box search uses `ST_Intersects` against a WGS 84 envelope.
- Coordinates outside valid latitude/longitude ranges are rejected by request validation and PostgreSQL check constraints.
- SQLite tests use a Haversine/bounds compatibility path only to exercise product rules. It is not the production spatial authority.

Public discovery uses a deliberate cross-tenant read projection and returns only allowlisted fields. A result requires both the Site and Charging Station to be `active` and public, and the Site publication time to be present and effective. Draft, maintenance, retired, unpublished, and coordinate-less sites are excluded.

## Identity, lifecycle, and history

All public and cross-context identifiers are ULIDs. `charge_point_identity` is globally unique because the independently deployed OCPP gateway must resolve one unambiguous charger across tenants. QR identifiers are globally unique because they are scanned before tenant context is trusted. Asset serial numbers are tenant-unique unless their issuing domain already guarantees a stronger key, such as ICCID.

Site, station, EVSE, connector, and component lifecycle values are `draft`, `active`, `maintenance`, and `retired`. OCPP connectivity and connector availability remain Charging-owned projections and do not silently change asset lifecycle.

Firmware installation history is effective-dated. Installing a newer firmware closes the current open interval in the same database transaction. Effective times must increase and firmware must belong to the station's configured charger model.

## Tenant and authorization controls

Every operational table contains non-null `tenant_id`, tenant-first indexes, and composite tenant/parent foreign keys. Eloquent models fail closed without established tenant context. API and Filament queries additionally apply tenant/site assignment scopes; Filament filtering is not the security boundary.

Public station search bypasses tenant Eloquent scopes only through its dedicated read-only query, with fixed visibility predicates and an allowlisted select. It cannot mutate tenant data.

## Media and data transfer

Site and station media are private S3-compatible objects under `tenants/{tenant_id}/sites/{site_id}` or `tenants/{tenant_id}/stations/{station_id}`. Only JPEG, PNG, and WebP image boundaries are accepted, with a 10 MiB limit and required alternative text. Database rows hold object metadata; object bytes are not stored in PostgreSQL.

Station CSV imports have an exact downloadable header, a 1,000-row/2 MiB boundary, atomic validation and persistence, globally unique OCPP/QR checks, and tenant/site authorization. Exports reuse the same authorized station query and escape spreadsheet-formula prefixes.

Media creation, station imports, station exports, and station administration append immutable tenant audit records with the current actor and correlation context.

## Archival

Referenced global master records use `archived_at`. Normal selection queries use the `available` scope. Foreign keys restrict destructive deletion while referenced; archival preserves historical display and compatibility evidence. Operational assets use lifecycle retirement instead of deletion.

## Migration and operations

Migration `2026_07_22_000400_create_location_and_asset_foundation` is additive to the Phase 3 `sites` table. It enables PostGIS when PostgreSQL is used, creates the geography column after relational columns, and creates the spatial index. Production rollout requires a migration role permitted to install or access an already-installed PostGIS extension. If extension creation is centrally managed, operators must preinstall PostGIS and allow the statement to succeed idempotently.

Monitor public spatial query latency, result cardinality, GiST index use, invalid import counts, object-storage failures, and identity/QR uniqueness conflicts. Query-plan checks with representative density remain a pre-production capacity task.

## Assumptions and open decisions

- Administrative boundary rows are curated/imported later; Phase 4 seeds only the country required for local development and does not claim a complete government dataset.
- Network-provider, manufacturer, firmware, vehicle, and model catalogs require governed sources before production population.
- The canonical QR payload/URL format, label issuance workflow, replacement/revocation behavior, and offline scan UX remain open.
- Antimeridian-crossing bounding boxes are not accepted by the current `west < east` contract; split queries or a wrapped-envelope contract require a follow-up decision.
- Media malware scanning/quarantine and derivative generation need an integration decision before untrusted production uploads are published.
- Station relocation, custody history, commissioning approvals, and integration-event/outbox publication belong to later business workflow phases.
- PostgreSQL row-level security remains the staged defense-in-depth decision in ADR-0007; this phase retains application scope plus tenant-aware constraints.
