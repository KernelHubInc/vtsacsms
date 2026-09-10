# ADR-0003: Use PostgreSQL and PostGIS as the System of Record

- **Status:** Accepted
- **Date:** 2026-07-20
- **Decision owners:** Architecture and data engineering
- **Scope:** Durable relational and geospatial platform data

## Context

VTSA CSMS requires transactional integrity for tenant data, charging sessions, published tariffs, payments/billing/settlements, immutable stock movements, and work histories. It also requires geographic points, proximity/bounds queries, spatial indexing, and future location geometry capabilities for the public charging map.

A single authoritative relational technology reduces early operational complexity while supporting module-owned tables, constraints, outbox records, reporting queries, and proven backup/recovery tooling. Geospatial behavior should be database-native rather than approximated with latitude/longitude arithmetic in application code.

## Decision

Use **PostgreSQL** as the authoritative durable relational database and enable **PostGIS** for geospatial data and queries.

Use one database technology initially, with logical ownership by bounded context. The Laravel core and OCPP gateway do not share writable business tables; gateway persistence is a separate logical database/schema owned by the gateway even if physically hosted on the same managed PostgreSQL service.

## Data Conventions

- Public and cross-context identifiers are ULIDs. The exact PostgreSQL storage representation is selected before migrations and used consistently across keys/foreign keys.
- Tenant-owned tables include non-null `tenant_id`, tenant-aware uniqueness, and tenant-first access indexes.
- Money uses `bigint`/integer minor units plus ISO 4217 currency; no floating point.
- Energy uses integer/bigint Wh; power integer/bigint W; durations integer/bigint seconds.
- Instants use `timestamptz` and application/API values are UTC. Local schedule rules retain an IANA timezone separately.
- Location geometry uses PostGIS WGS 84/SRID 4326. Use `geography` for earth-distance semantics or an explicitly transformed `geometry` for spatial operations as query requirements dictate.
- `jsonb` is allowed for genuinely variable, versioned provider/protocol evidence or metadata, not as a substitute for modeled, constrained, commonly queried domain fields.
- Essential invariants use foreign keys/check/exclusion constraints where ownership and rollout compatibility permit.
- Posted financial/stock/audit evidence is append-only with compensating records.

## Tenancy and Ownership

- Application-level tenant scoping is mandatory.
- PostgreSQL row-level security is the target defense-in-depth control for tenant-owned tables, subject to a proven connection/pooling/migration implementation and staged rollout documented in the tenancy model.
- Database runtime roles have least privilege. Application runtime never uses a migration, superuser, or RLS-bypass role.
- Context tables use schemas or prefixes selected in a follow-up convention. Migrations belong to the owning module.
- Direct cross-context writes are prohibited even when SQL could perform them.

## Geospatial Use

PostGIS owns authoritative geographic validation/search capabilities such as:

- charging locations within map bounds or radius;
- distance ordering using suitable indexed operations;
- geometry validity and WGS 84 coordinate storage;
- service/administrative boundaries if later licensed and approved; and
- spatial projection feeds for public discovery/reporting.

Map tiles, geocoding, routes, directions, and licensed place data can remain external provider capabilities. Coordinates received from a provider are validated and stored as VTSA location facts with provenance as needed.

## Operational Requirements

- Use automated backups and point-in-time recovery with approved RPO/RTO and regularly tested restoration.
- Monitor connections, locks, slow queries, replication/backup lag, disk growth, vacuum/bloat, index use, errors, and tenant/workload hotspots.
- Use connection pooling compatible with transactions, session tenant context, prepared statements, and RLS if enabled; the precise product/mode is a deployment decision.
- Schema migrations follow expand/migrate/contract, avoid long locks, and move large backfills to resumable jobs.
- Evaluate table partitioning for meter readings/events/audit only from measured volume and query/retention needs.
- Read replicas can serve appropriate eventually consistent reporting/query workloads only after read-after-write semantics and tenant controls are explicit.
- Data archive and deletion preserve financial/legal/audit constraints and cross-context references.

## Consequences

### Positive

- Strong transactions, constraints, indexing, concurrency control, and mature Laravel support.
- PostGIS supplies accurate, indexed geospatial semantics for the charging map.
- PostgreSQL features support outbox/inbox, JSONB evidence, RLS defense in depth, and advanced reporting queries.
- One primary database technology simplifies backup, expertise, monitoring, and local development initially.
- Open-source data formats and broad managed-service availability reduce provider lock-in.

### Negative

- Team members primarily familiar with MySQL need PostgreSQL/PostGIS training and query/migration review.
- PostgreSQL-specific SQL, PostGIS types, RLS, and operators reduce transparent portability to another database.
- A shared cluster can create noisy-neighbor/blast-radius risk without quotas, query controls, and workload separation.
- Time-series meter growth, vacuum, indexes, and geospatial queries require capacity-aware schema design.
- RLS connection context can fail dangerously if pooling/session reset is implemented incorrectly and therefore requires proof/testing.

## Alternatives Considered

### MySQL with latitude/longitude columns

Rejected for this product baseline because PostGIS provides a stronger, mature geospatial feature set and PostgreSQL offers useful RLS/constraint/query capabilities. Existing team familiarity alone does not outweigh the map and isolation requirements.

### Separate spatial database/search service

Rejected initially because it duplicates location truth and operational overhead before scale requires a projection/search engine.

### Document database as the primary store

Rejected because transactional relationships, immutable financial/stock controls, tenant-aware constraints, and reporting favor a relational system of record.

### Time-series database from the start

Rejected for initial meter data. PostgreSQL schemas/partitioning should be proven against capacity first; a specialized time-series projection may be added by ADR if measured needs justify it.

### Database per bounded context

Rejected initially under the modular monolith decision. Logical ownership is enforced in one technology; physical separation can follow extracted service/security/scale needs.

## Risks and Controls

| Risk | Control |
| --- | --- |
| Cross-context SQL coupling | Context-owned migrations/repositories, architecture tests, projection contracts |
| Cross-tenant leak | Mandatory scopes/indexes, RLS proof/rollout, isolation tests, least-privilege roles |
| Hot/large meter tables | Representative load tests, append schema, partition/retention evaluation, queue batching |
| Slow spatial query | Correct geography/geometry choice, GiST/SP-GiST indexes, bounded queries, query plans |
| Migration downtime | Expand/contract, lock/timeout review, batch backfills, rollback/roll-forward plan |
| Restore assumptions fail | Automated backups plus scheduled restore and reconciliation tests |

## Follow-up Decisions

- PostgreSQL/PostGIS supported versions and managed/self-hosted platform.
- ULID storage type and generation responsibility.
- Context schema/prefix naming and migration tooling.
- RLS connection context, policy template, bypass roles, and rollout sequence.
- Backup/RPO/RTO, retention, replicas, partitioning/archive, and pooling topology.
