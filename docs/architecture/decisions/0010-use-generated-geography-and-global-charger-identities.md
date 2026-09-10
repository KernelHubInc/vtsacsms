# ADR-0010: Use Generated Geography Points and Global Charger Identities

- **Status:** Accepted
- **Date:** 2026-07-22
- **Decision owners:** Locations, Assets, Integrations
- **Scope:** Site coordinates, public discovery, charger and QR identity

## Context

The platform receives editable latitude/longitude values, but authoritative proximity and map-bound queries must use PostGIS. Charger identities must also remain unambiguous at the separately deployed OCPP gateway before a tenant-aware core request exists.

## Decision

Generate a PostgreSQL `geography(Point,4326)` site coordinate from validated latitude and longitude columns and index it with GiST. PostGIS performs production radius, distance, and bounding-box queries.

Make `charging_stations.charge_point_identity` globally unique. Make station, connector, and component QR identifiers globally unique when present. Keep ordinary asset serial-number uniqueness tenant-scoped unless the identifier's issuing system provides a stronger global namespace.

Public discovery is a dedicated allowlisted cross-tenant read query with explicit publication and lifecycle predicates. It does not use unrestricted Eloquent model access.

## Consequences

- Coordinate writes use familiar scalar validation while the database guarantees one derived spatial value.
- The generated point cannot drift from its coordinate inputs.
- GiST-backed queries provide meter-based earth-distance semantics.
- Charger and QR scans resolve without guessing tenant ownership.
- SQLite can test policy behavior through a compatibility implementation but cannot prove PostGIS types, indexes, or plans; PostgreSQL verification remains required.
- Changing an OCPP or QR identity becomes a controlled lifecycle operation rather than an ordinary edit.

## Alternatives considered

- Application-maintained geography columns were rejected because partial writes could create drift.
- Tenant-scoped OCPP identities were rejected because gateway routing would require tenant discovery before charger authentication.
- Plain latitude/longitude production queries were rejected by ADR-0003.
- A separate search database was deferred until measured load justifies a projection.

## Risks and controls

| Risk | Control |
| --- | --- |
| PostGIS extension privilege unavailable | Preinstall extension or grant migration role; migration is idempotent |
| Public cross-tenant leak | Fixed safe select, publication predicates, no mutation surface, contract tests |
| Dense radius query becomes expensive | GiST index, bounded radius/limit, plan and load checks |
| Global identity collision during onboarding | Unique constraints and validated atomic imports |
| QR replacement semantics unclear | Immutable-by-default APIs; define issuance/revocation workflow before product use |

## Follow-up decisions

- Antimeridian and polygon search behavior.
- QR payload versioning, signing, replacement, and revocation.
- Coordinate provenance/geocoding confidence.
- Search projection/cache thresholds and invalidation.
