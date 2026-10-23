# Admin and operator monitoring dashboard

The `/admin` and `/operator` home pages use the same Reporting-owned, read-only dashboard. No schema migration, charger command, payment request, or mobile release is needed.

The operations layout groups four immediate signals (active stations, available connectors, charging sessions and completed-session energy), then shows daily activity, connector state, issues needing attention, current sessions, site energy, finance and station setup. The shared admin/operator theme gives other resource pages the same sidebar spacing, headings and panel treatment. It follows the CSMS Operations Dashboard design direction using Power Solutions branding and real authorized records. Example counts, service-uptime claims and payment-method splits from the design are not shown because this query does not have evidence for them.

## Access and filters

- Panel access remains governed by the existing Identity policy. Reporting permission is required for dashboard data; other panel users receive a workspace message and keep their existing navigation.
- Location choices intersect Locations view and Reporting view grants and include only active, non-retired sites. Retired sites remain in historical records but are excluded from this operational dashboard and cannot be selected by a forged filter. Asset data additionally intersects Assets view grants. Session data uses the existing authorized-session query. Finance data uses session Payment view grants. Maintenance counts intersect Maintenance view grants.
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
| Live charging sessions | At most four newest sessions in canonical `charging` state, scoped through the authorized Charging query. Energy is the session's current stored Wh, not a guaranteed live power reading. |
| Top sites | The three authorized sites with the most completed-session energy in the selected period. Empty networks show an explicit empty state. |

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

## Admin dashboard design staging release — 2026-10-22

- The Power Solutions operations dashboard layout was released to staging as `vtsa-prepaid-platform:20261022-admin-dashboard-design`, layered on the prior `20261022-location-filter` image. The bundle contains only the Reporting dashboard query, shared dashboard Blade template, admin/operator theme source, and Vite build assets. Unrelated mobile, charging, notification, and payment work in the local tree was not included.
- The existing staging platform container was stopped, updated with those exact files, restarted, and had its Blade cache rebuilt. `/etc/vtsa-csms/compose.staging-prepaid-platform.json` now selects the new image for future platform recreation. Its previous version is saved under `/root/admin-dashboard-design-20261022/compose.staging-prepaid-platform.json.before`. The extracted release bundle and Dockerfile are in that root-only directory. Roll back by restoring the prior override and the `20261022-location-filter` image; the OCPP gateway and database were unchanged.
- The uploaded bundle SHA-256 is `59ad31815718e98376addf882f55ebf60b01fbfb6c0853cf43112a3013ad5845`. Deployed query, template, and Vite manifest hashes matched the tested local files. Local Vite build and Blade compile passed; the candidate image passed PHP syntax and Blade compilation. After restart, the platform container was healthy, `/admin/login` returned HTTP 200, and the new theme asset returned HTTP 200.
- The preceding full Laravel suite passed (281 tests, 2,526 assertions), including the dashboard regression; Pint and Composer audit passed. Local PHPStan was blocked by a Windows PHP readability issue on `phpstan.neon.dist`. An authenticated browser visual check of the dashboard remained outstanding. At the time of this release the source changes were uncommitted; they were subsequently recorded in `test_env` commit `2da20f17`.

## Appearance correction staging release — 2026-10-23

- Source commit `2da20f17` on `test_env` records the existing dashboard design plus the admin/operator light, dark and system appearance correction. Light mode now uses a readable light sidebar and the standard logo; dark mode uses the navy sidebar and dark-background logo. System mode follows Filament's resolved device preference. Dashboard data and permissions are unchanged by this appearance correction.
- Staging web image `vtsa-prepaid-platform:20261023-admin-theme-2da20f17` is layered on `20261023-php2shell-web`. Only the two panel providers, theme source and compiled theme asset changed in the running service. The charging workers, OCPP gateway and database were not restarted or migrated. The prior Compose override and release bundle are saved under `/root/vtsa-theme-2da20f17/` with root-only permissions; the bundle SHA-256 is `9381e683e73a9626be760b2a400813decd58427d5ab052920af72b3abc9c04a9`.
- To roll back, change only `services.platform.image` in `/etc/vtsa-csms/compose.staging-prepaid-platform.json` to `vtsa-prepaid-platform:20261023-php2shell-web` and recreate only the `platform` service with the existing four-file staging Compose invocation. Preserve any later override changes; the saved override is a reference.
- Before deployment, the full Laravel suite passed (283 tests, 2,542 assertions), PHPStan and Pint passed, the Vite production build passed, and the push hook's OCPP, contract and Flutter tests passed. The candidate image passed PHP syntax and theme-manifest checks. After deployment, the web container was healthy and both login pages and the new theme asset returned HTTP 200. Local light/dark/system visual checks passed; the staging browser could not attach for an authenticated dashboard screenshot, so an authenticated staging visual check remains outstanding.
