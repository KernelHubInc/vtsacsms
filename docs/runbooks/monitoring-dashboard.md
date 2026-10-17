# Admin and operator monitoring dashboard

The `/admin` and `/operator` home pages use the same Reporting-owned, read-only dashboard. No schema migration, charger command, payment request, or mobile release is needed.

## Access and filters

- Panel access remains governed by the existing Identity policy. Reporting permission is required for dashboard data; other panel users receive a workspace message and keep their existing navigation.
- Location choices intersect Locations view and Reporting view grants. Asset data additionally intersects Assets view grants. Session data uses the existing authorized-session query. Finance data uses session Payment view grants. Maintenance counts intersect Maintenance view grants.
- A forged or revoked location filter returns 403. Permission decisions are repeated on every refresh, with no shared dashboard cache.
- Live wallet top-ups require tenant-wide Reporting and Payment view. They are hidden when a location is selected because wallet funding has no location attribution. Site-only and organization-only operators must not see tenant-wide wallet totals.
- The period selector supports 7, 30 and 90 calendar days, including today, in `Asia/Manila` (GMT+8). Stored instants remain UTC. Location and period filter the metrics; search and connection/lifecycle filter the station cards only.
- Live data refreshes every 15 seconds while the page is active. This is polling, not a guarantee of immediate delivery from a device. Last-message timestamps expose source freshness separately from page refresh time.

## Metric definitions

| Metric | Definition |
| --- | --- |
| Active stations | Assets lifecycle `active`; online requires fresh connection evidence. Draft, maintenance and retired totals are shown separately. |
| Available connectors | Only currently `Available`; occupied, reserved, offline, unknown and non-active equipment are excluded. Denominator includes every configured connector in the selected authorized network. Empty equipment returns no percentage. |
| Connector breakdown | Connection freshness and socket generation are resolved by Charging's `StationConnectionQuery`. Non-active station, EVSE or connector lifecycle overrides availability in this operational breakdown. A socket reconnect must receive a matching status report before reusing availability. |
| Charging sessions | Canonical `charging` sessions only. Paused, waiting-to-start, finalizing and review-required sessions are separate. These are session workflow states, not proof that an offline device is currently transferring energy. |
| Completed-session energy and trends | Sum of integer Wh on completed sessions whose stop time falls in the period. Each entire session is attributed to its local stop date, not split into meter-sample intervals across midnight. In-progress sessions and unresolved finalization are excluded. |
| Finalized charging charges | Completed sessions with a final cost and currency, grouped by currency and stop date. Gross charges are not collected cash, refunds or net revenue. |
| Confirmed wallet top-ups | Billing `prepaid_entries.kind=topup`, joined to the same tenant's `live` wallet book, grouped by currency and credit timestamp. Pending provider orders and simulated credits are excluded. This counts posted credits, not net refunds or current balances. |
| Delayed starts | Requested/authorizing/authorized/starting sessions past their start deadline or requested more than five minutes ago. Read-only warning; no automatic cancellation or refund. |
| Payment exceptions | Failed or expired session Payment intents created during the period, intersected with authorized sessions. QR wallet orders are not included in this session-payment count. |
| Maintenance | Current non-closed/non-canceled work orders; SLA breach means the resolution target has passed. Counts are scoped by selected location and Maintenance permissions. |

## Operator workflow

1. Select a location and reporting period. Check online stations and available plugs independently.
2. Search by station name, location or charge-point ID; filter online, offline, not seen, or equipment lifecycle. Eight station cards appear per page.
3. Open a station's setup checklist to inspect its identity, connector configuration, tariff and hours using existing permissions.
4. Expand daily totals for exact kWh, session counts and currency-separated charges behind the energy chart.
5. Use the attention queue to inspect equipment, session diagnostics, session payments or work orders. Counters never issue charger commands or modify financial records.

## Verification and deployment

Regression coverage: `MonitoringDashboardTest` checks occupied/reserved exclusion, stale connections, lifecycle overrides, GMT+8 boundaries, long-running session attribution, currency separation, simulated-credit exclusion, unauthorized site filters, tenant isolation, and Livewire filters/pagination. Existing portal tests remain applicable.

Run PHPUnit, Pint, PHPStan, the Vite production build, map tests and dependency audit. Verify PostgreSQL date grouping on a candidate container before deployment. Use the existing staging image deployment with rollback to the previous image; no migrations are required. Keep the independently running OCPP gateway untouched.

Limits: this dashboard does not replace settlement reconciliation, invoice reporting or physical charger verification. It intentionally does not report elapsed session time as energy-flow utilization. A site-wide “under repair” inference is not made from a fault alone.

## Staging release — 2026-10-16

- Deployed image: `vtsa-prepaid-platform:20261016-monitoring`; rollback image: `vtsa-prepaid-platform:20261015-station-setup-r2`.
- Candidate image: 257 backend tests / 2,182 assertions passed. PostgreSQL/API rehearsal and post-deployment validation: 40 checks each, verification records rolled back, no external payment requests or charger commands.
- Vite production build, four map JavaScript tests, Pint and PHPStan passed; Composer reported no locked-dependency security advisories.
- All five application services verified; health readiness returned HTTP 200; native OCPP gateway process unchanged. No migration applied.
- The real admin dashboard rendered successfully against PostgreSQL; the read query took 221 ms during verification. Existing network data remained intact: 35 stations, 61 connectors, one active station online at the check time. These are a dated observation, not permanent expected totals.
- Browser preview could not attach in this session. Livewire rendering and filter interactions were verified by automated tests and server-side rendering; desktop/mobile visual appearance still needs a browser check.
- Release evidence and backup: `/opt/vtsa-releases/monitoring-20261016/`. Local source changes are not committed by this deployment.
