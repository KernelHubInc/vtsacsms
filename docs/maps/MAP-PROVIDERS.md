# Map Providers

| Surface | `openstreetmap` | `google` |
|---|---|---|
| Laravel public/admin/operator | Leaflet.js 1.9.4 and Leaflet.markercluster | Google Maps JavaScript API loaded asynchronously and `@googlemaps/markerclusterer` |
| Flutter Android/iOS/Web | `flutter_map` 8.3.1 and `flutter_map_marker_cluster` 8.2.2 | `google_maps_flutter` 2.18.0 |

OpenStreetMap is the default and fallback. Both modes consume the same station endpoints and exact coordinates.

## Switching

Set one or more Laravel surface variables:

```env
MAP_PROVIDER_DEFAULT=openstreetmap
MAP_PROVIDER_ADMIN=openstreetmap
MAP_PROVIDER_OPERATOR=openstreetmap
MAP_PROVIDER_USER_WEB=openstreetmap
MAP_PROVIDER_PUBLIC=openstreetmap
MAP_PROVIDER_MOBILE=openstreetmap
```

Authorized platform administrators may change non-secret rendering preferences under **System settings → Map providers**. Database preferences take precedence and are audited. Google credentials cannot be viewed or edited there.

Flutter accepts:

```powershell
flutter run --dart-define=MAP_PROVIDER=openstreetmap
flutter run --dart-define=MAP_PROVIDER=google --dart-define=GOOGLE_MAPS_READY=true
```

The second command also requires platform-specific Google credentials. Without readiness, Flutter resolves OpenStreetMap.

The Flutter “Show my location” action uses the same transient `MapPosition` in either provider. Permission denial does not affect station discovery.

## Fallback states

- Unknown provider: warning plus OpenStreetMap.
- Google not configured: `google_not_configured`, non-blocking warning, OpenStreetMap.
- SDK initialization failure: clean partial objects, try OpenStreetMap once.
- Both providers fail: controlled error overlay and usable station list/manual coordinate fields.
- Tile failure: diagnostic warning and usable filters/list.

No live Google verification is part of credential-free CI.
