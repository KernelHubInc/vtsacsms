# ADR-0004: Use Redis for Ephemeral Platform Services

- **Status:** Accepted
- **Date:** 2026-07-20
- **Decision owners:** Architecture and platform operations
- **Scope:** Cache, rate limiting, locks/coordination, queue transport, realtime/connection acceleration

## Context

VTSA CSMS needs low-latency caching, request/device rate controls, short-lived locks and idempotency coordination, Laravel background job transport, realtime/presence acceleration, and OCPP connection-routing hints. PostgreSQL should remain the durable source of truth and should not absorb every high-churn ephemeral operation.

Using several specialized products initially would increase operational and local-development complexity. Redis is well supported by Laravel and common high-concurrency gateway runtimes, but its failure and persistence semantics must not be confused with authoritative business storage.

## Decision

Use **Redis** for explicitly ephemeral/reconstructable platform services:

- cache entries and invalidation tags;
- rate-limit/quota counters;
- distributed locks and short leases with fencing/ownership safeguards where required;
- Laravel queue transport and delayed/retry scheduling, backed by PostgreSQL business state/outbox for durable intent;
- OCPP connection presence/routing hints and gateway coordination;
- realtime pub/sub/presence acceleration; and
- short-lived session/idempotency acceleration where the durable result remains in PostgreSQL.

Redis is never the only copy of a charging session, command delivery evidence, payment/refund outcome, invoice, settlement, stock movement/balance, work order, audit record, integration event intent, or other durable business fact.

## Namespacing and Data Rules

Conceptual key pattern:

```text
<environment>:<application>:<context>:tenant:<tenant_ulid>:<purpose>:<resource>
```

Platform-global keys use an explicit `platform` segment. Every key family has an owner, value schema/version, TTL/expiry policy (or justification for none), maximum cardinality/size, invalidation/rebuild method, privacy classification, and metric.

- No secret, raw payment data, unrestricted personal data, or large protocol/file payload is cached.
- Cached tenant-owned values include/verifiably match tenant context after retrieval.
- Serialization is safe and versioned; never deserialize arbitrary executable objects from untrusted sources.
- Key scans and unbounded wildcard deletion are not normal application behavior.
- Cache keys do not expose sensitive values; use stable safe identifiers/hashes where needed.

## Queue and Outbox Semantics

- A module commits business state and a PostgreSQL outbox record atomically.
- A dispatcher enqueues delivery work in Redis. If enqueue acknowledgement is lost, the outbox record remains discoverable and is retried.
- Workers are at-least-once. They use PostgreSQL inbox/event/aggregate idempotency before producing business effects.
- A successfully acknowledged Redis job is not evidence that a payment, command, notification, or integration event completed.
- Poison jobs have bounded retries, quarantine/dead-letter evidence, alerting, and authorized replay.
- Separate queues/concurrency budgets protect charging and financial callbacks from reports, exports, imports, and bulk notification work.

If accepted throughput/replay/retention requirements exceed this model, adopt a dedicated durable event broker through a new ADR; do not silently turn Redis into an unbounded event archive.

## Locks and Coordination

- Prefer database uniqueness/transactions for durable invariants. Redis locks coordinate work; they do not replace database constraints.
- Lock keys include tenant and target; acquisition has bounded wait and TTL.
- Long-running or safety/financial critical ownership uses a fencing token/version checked by the durable store so an expired lock holder cannot commit stale work.
- Unlock uses an owner token atomically; a process cannot release another owner's lock.
- Failure to acquire or uncertainty fails/retries safely and visibly, never bypasses the lock.

## OCPP and Realtime Use

- Gateway nodes may publish connection presence and routing metadata with short lease/heartbeat TTLs.
- Durable gateway command/message evidence remains in the gateway store; Redis loss causes re-registration/recovery, not false command success.
- Pub/sub/realtime messages are hints. Mobile/web clients refetch authoritative API state after reconnect or missed sequence.
- Split-brain connection ownership requires fencing/durable checks in addition to Redis presence.

## Failure Expectations

- Applications handle Redis timeout/unavailability with explicit, bounded degraded behavior.
- Cache failure becomes a miss where safe; the database is protected from a cache-miss stampede with bounded concurrency/backoff.
- Rate-limit system failure follows risk-specific fail-open/fail-closed policy. Authentication, payment, and command paths require explicit security review.
- Queue dispatch recovers from PostgreSQL outbox. Running job retries are idempotent.
- Sessions and authentication must not become unrecoverable solely because Redis was flushed.
- Gateway nodes rebuild presence/routing state from live connections and durable operational evidence.

High availability, persistence configuration, eviction policy, topology, memory limits, and maintenance windows remain operational decisions. Even if Redis persistence is enabled, the durable-data prohibition remains.

## Consequences

### Positive

- Mature Laravel integration for cache, rate limits, locks, queues, and sessions.
- Low-latency operations suitable for high-churn coordination and charger connection hints.
- One initial ephemeral technology reduces operational/local-development surface.
- TTL and atomic primitives fit temporary state and dedup acceleration.
- Independent scaling from PostgreSQL protects durable workload when managed correctly.

### Negative

- Redis becomes a shared operational dependency and possible contention/noisy-neighbor point.
- Eviction, failover, and network partitions can invalidate locks, queues, sessions, or presence.
- Teams may accidentally treat cached/queued data as durable unless rules/tests enforce recovery.
- Key memory/cardinality and hot-key behavior require discipline and monitoring.
- Correct distributed locking/fencing is complex for critical operations.

## Alternatives Considered

### PostgreSQL for all cache/queue/lock needs

Rejected as the default because high-churn ephemeral traffic could contend with authoritative transactions. PostgreSQL remains appropriate for durable outbox/inbox and invariant enforcement.

### Dedicated products for broker, cache, rate limit, and coordination immediately

Rejected initially due to operational complexity without accepted scale requirements. Capabilities may split later by ADR.

### In-process memory only

Rejected for shared coordination, queues, rate limits, and multi-instance behavior. Local in-process cache may be used only for explicitly safe per-process immutable/short-lived data.

### Redis as event store or business database

Rejected because durability, reconciliation, query, and integrity requirements belong in PostgreSQL/domain stores.

## Risks and Controls

| Risk | Control |
| --- | --- |
| Business data lost on flush/eviction | PostgreSQL source/outbox, rebuild tests, prohibited data review |
| Tenant cache leak/collision | Namespaced keys, value tenant check, tenant switch/isolation tests |
| Duplicate jobs after failover | Inbox/idempotency/state guards in durable store |
| Stale lock holder commits | Fencing/version checks and database constraints |
| Hot key/noisy tenant | Per-tenant key design/quotas, queue separation, memory/latency monitoring |
| Sensitive data cached/logged | Data classification, minimization, safe serialization, telemetry redaction |

## Follow-up Decisions

- Redis supported version/topology/managed provider, authentication/TLS, network placement, and secret management.
- High availability, persistence, eviction and memory policies, backups if operationally useful, and recovery tests.
- Queue families, priorities, retry/dead-letter handling, and worker isolation.
- Gateway lease/fencing and realtime technology.
- Threshold for adopting a dedicated durable broker.
