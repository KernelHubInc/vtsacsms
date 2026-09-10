# Map Migration Report

Report date: 2026-07-29

## Result

- Map surfaces discovered: 7.
- Migrated to provider contracts: 7.
- Remaining unexplained surfaces: 0.
- Obsolete direct adapters removed: `osm-maps-adapter.js`, `google-maps-adapter.js`.
- Shared implementations retained: one web factory/two adapters; one Flutter factory/two provider widgets.
- Provider-specific station tables introduced: 0.
- Stored coordinates changed: 0.

OpenStreetMap is the default for public, admin, operator, user-web, and mobile surfaces. Google remains selectable and falls back deterministically when it is not ready.

## Functional parity

Public locator filters, server bounds, clustering, status markers, selection, detail drawer, accessible list, directions, stale status, and near-me remain. Portal maps preserve authorized snapshots and status/detail synchronization. Site forms add click/drag coordinate picking without removing manual validated fields. Flutter preserves server bounds, debounce, stale-response protection, selected cards, filtering, favorites entry, directions, and user-triggered transient current location.

No separate site-host, maintenance, fault, active-session, or authenticated Laravel consumer map exists. These signals are composed into existing network maps; asset coordinates continue to inherit from Site.

## Practical performance findings

- Laravel's production Vite build emits one lazy-capable application bundle; only the resolved provider initializes or loads its remote SDK.
- Station searches remain bounded at 250 records and use the existing 30-second public response cache.
- Marker IDs are stable ULIDs. Web adapters update/remove known markers; Flutter controllers survive widget updates.
- Flutter tile buffering is bounded and camera searches are debounced 350 ms.
- Repeated Livewire navigation destroys provider listeners, observers, markers, and map instances.

## Verification results

- Laravel: 122 tests passed with 953 assertions.
- Web provider contracts: 4 tests passed.
- Browser: 13 end-to-end tests passed, including public, admin, operator, coordinate-picker, fallback, and Flutter Web coverage.
- Flutter: 37 unit tests and 13 widget tests passed.
- Flutter integration: 2 mobile journeys are authored. They cannot execute on this host because the repository has no Windows desktop target and Flutter reports that web devices are not supported for `integration_test`. The Flutter Web discovery journey is covered by the passing browser suite.
- Static analysis: Larastan analyzed 711 PHP files without errors; Flutter analyze reported no issues.
- Lint and contracts: Pint, the event/OpenAPI lint, generated-contract validation, and Dart formatting passed.
- Builds: Laravel Vite and Flutter Web release builds passed.
- Local runtime: the Docker demo, migrations, deterministic seed data, service health checks, and tenant-isolation verification passed. The demo was left running.

These results are functional verification, not architectural service-level guarantees; external tile response time still varies with local network availability.

## Verification boundary

OpenStreetMap has credential-free unit, widget, feature, build, and rendered browser coverage. Google has readiness/fallback, provider-factory, clustering configuration, and mocked contract coverage. Live Google SDK rendering is not claimed because no valid restricted credential was supplied.

## Known limitations

- Flutter Web Google mode still requires its documented Maps JavaScript bootstrap key in addition to the Dart readiness assertion.
- Flutter current-location permission is user-triggered and foreground-only; no background tracking or location persistence is implemented.
- The community OpenStreetMap tile endpoint is a local/demo default, not an approved production capacity commitment.
