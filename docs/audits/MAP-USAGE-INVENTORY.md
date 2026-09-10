# Map Usage Inventory

Audit date: 2026-07-29

## Implemented map surfaces

| Surface | Route or screen | Before | Preserved behavior and API | Before failure | Adapter and configuration | Migration | Automated coverage |
|---|---|---|---|---|---|---|---|
| Public store locator | `/charging-map` | Partial `osm`/Google switch; direct adapter imports | Bounding-box requests to `/api/v1/public/stations`, marker clusters, status markers, filters, map/list synchronization, near-me, selection drawer, directions, accessible list | OpenStreetMap worked; Google without a key happened to select OSM; dual failure showed list | `MapProviderFactory` → `OpenStreetMapWebProvider` or `GoogleMapsWebProvider`; public surface | Complete | PHP configuration/API tests, JS contract tests, Playwright locator journey |
| Public station detail | `/stations/{slug}` | No embedded map; hard-coded Google directions URL | Exact API coordinates, station facts, external directions | Directions remained available; no map | Shared web factory plus provider-independent directions template | Complete | Public page feature test and browser detail journey |
| Platform network | `/admin/network-map` | Direct Google adapter | Tenant-authorized snapshot, clustering, status filtering, active/fault/stale indicators, list/detail synchronization | Key absence hid the map behind an overlay | Shared web factory; admin surface; OpenStreetMap fallback | Complete | Tenant projection feature test and browser admin map test |
| Operator network | `/operator/network-map` | Direct Google adapter | Exact authorized-site projection, filters, clusters, list/detail synchronization | Same key overlay | Shared web factory; operator surface | Complete | Tenant-isolation feature/browser coverage |
| Platform site picker | `/admin/sites/create`, `/admin/sites/{id}/edit` | Manual latitude/longitude only | Manual coordinate entry plus click/tap and draggable marker, current/default center, validation preservation | Manual entry remained available | Shared web factory; admin surface | Complete | PHP validation plus browser coordinate-picker test |
| Operator site picker | `/operator/sites/create`, `/operator/sites/{id}/edit` | Manual latitude/longitude only | Same picker, constrained by the existing Site resource query/policy | Manual entry remained available | Shared web factory; operator surface | Complete | Resource authorization tests and shared picker browser test |
| Flutter discovery | `/explore` (map, nearby, search, favorites and compatibility entry paths) | Direct `google_maps_flutter`; no key forced list mode | Server bounding boxes, 350 ms camera debounce, stable station IDs, clusters, status/selection markers, explicit current-location action, selected card, filters, list fallback, external directions | Missing Google key disabled the map | `StationMap` → `OpenStreetMapStationMap` (`flutter_map`) or `GoogleStationMap` (`google_maps_flutter`) | Complete | Provider resolution, factory, marker mapping, OpenStreetMap/current-location widget, stale-request and full journey tests |

Seven implemented map surfaces were discovered and all seven use the provider abstraction.

## Audited non-map or composed surfaces

- There is no separate authenticated Laravel consumer map or site-host panel in this repository. The Flutter discovery map is the consumer/authenticated map.
- Nearby, search, favorites, and vehicle-compatible discovery are filters or entry paths to the same Flutter `/explore` map; they are not duplicate map widgets.
- Charging stations, EVSEs, connectors, components, and maintenance records inherit location from `Site`. They do not store independent coordinates, so separate asset/charger/maintenance location pickers would invent a second geographic source of truth.
- Fault, maintenance, active-session, revenue, and utilization signals are composed into the authorized admin/operator network map or dashboards; no additional rendered map exists.
- The directions action is deliberately separate from rendering. It launches a configured external URL on web and an installed navigation application on mobile.
- No Google Places, geocoder, or address-autocomplete dependency was found.

## Provider-specific persistence audit

No provider-specific station, marker, tile, place, or projection data is stored. `sites.latitude`, `sites.longitude`, and the generated PostGIS geography remain WGS84/EPSG:4326 authority. Provider settings contain rendering preferences only; Google credentials remain deployment-managed.
