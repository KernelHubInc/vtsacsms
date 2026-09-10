# Local performance report

Test profile: Docker Desktop local development, 15 fictional sites, 30 stations, 45 EVSEs, 60 connectors, PostgreSQL/PostGIS, Redis, production-built Vite assets and Flutter Web. This is practical UAT evidence, not a production load test.

| Check | Local observation | Method |
|---|---:|---|
| Landing page | 214.2 ms average; 122.1–564.8 ms | Five warm HTTP requests |
| Locator page | 105.9 ms average; 79.4–117.7 ms | Five warm HTTP requests |
| Bounding-box station query | 67.9 ms average; 60.2–84.9 ms | Five warm API requests against PostGIS |
| CCS2 / 50 kW / available filter | 53.2 ms average; 48.2–58.2 ms | Five warm server-side filtered API requests |
| Marker clustering | 30 markers rendered and clustered | Playwright browser inspection |
| Platform dashboards | query budgets pass; cached aggregates for expensive cards | Laravel portal query tests |
| Inventory list/count | seeded count plan rendered within browser timeout | Playwright role scenario |
| Maintenance/workboard | technician route exercised at 390 × 844 | Playwright responsive role scenario |

Indexes already cover public lifecycle/search, PostGIS geography, tenant-leading asset/inventory/maintenance/reporting paths, and connector status freshness. The locator hydrates connector, hours, and amenities in bounded grouped queries rather than per-row queries.

Measurements were captured on 2026-07-26 after container warm-up. Hardware, first image pull, cold asset caches, and concurrent Docker Desktop work materially affect local timings. Do not introduce a microservice or cache solely to improve these demo-scale measurements.
