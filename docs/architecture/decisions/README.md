# Architecture Decision Records

ADRs preserve durable architectural choices and their tradeoffs. Accepted ADRs are not rewritten to hide history; a change adds a new ADR and marks the old record superseded.

| ADR | Decision | Status |
| --- | --- | --- |
| [0001](0001-use-a-laravel-modular-monolith.md) | Use a Laravel modular monolith for the core platform | Accepted |
| [0002](0002-separate-ocpp-gateway.md) | Deploy the OCPP gateway separately from the core platform | Accepted |
| [0003](0003-use-postgresql-and-postgis.md) | Use PostgreSQL and PostGIS as the system of record | Accepted |
| [0004](0004-use-redis-for-ephemeral-platform-services.md) | Use Redis for cache, queues, and ephemeral coordination | Accepted |
| [0005](0005-use-flutter-for-the-consumer-mobile-app.md) | Use Flutter for the consumer mobile app | Accepted |
| [0006](0006-abstract-payment-providers.md) | Isolate payment providers behind a capability-aware abstraction | Accepted |
| [0007](0007-enforce-tenant-context-before-rls.md) | Enforce tenant context in the application before RLS rollout | Accepted |
| [0008](0008-use-scoped-rbac-and-revocable-opaque-tokens.md) | Use scoped RBAC and revocable opaque tokens | Accepted |
| [0009](0009-use-sanctum-for-first-party-mobile-authentication.md) | Use Sanctum for first-party mobile authentication | Accepted |
| [0010](0010-use-generated-geography-and-global-charger-identities.md) | Use generated geography points and global charger/QR identities | Accepted |
| [0011](0011-google-maps-adapter.md) | Keep Google Maps behind a public-map adapter with an accessible list fallback | Superseded by 0014 |
| [0012](0012-use-an-immutable-stock-movement-ledger.md) | Use an immutable stock movement ledger | Accepted |
| [0013](0013-use-append-only-maintenance-evidence-and-owner-contracts.md) | Use append-only maintenance evidence and owner-context contracts | Accepted |
| [0014](0014-use-configurable-map-provider-adapters.md) | Use configurable OpenStreetMap and Google adapters across web and Flutter | Accepted |

## ADR Template

New records should include status, date, owners, context, decision, decision details, consequences, alternatives, risks/controls, and follow-up decisions. Use the next zero-padded number and a stable descriptive filename.
