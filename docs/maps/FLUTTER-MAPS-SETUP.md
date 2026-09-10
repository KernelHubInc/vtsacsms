# Flutter Maps Setup

## OpenStreetMap default

```powershell
flutter run -d chrome `
  --web-port=7357 `
  --dart-define-from-file=dart_defines.web.example.json
```

`flutter_map` renders tiles and `flutter_map_marker_cluster` clusters stable station markers. Attribution is an always-visible overlay. The configuration endpoint is optional; failure keeps local defaults.

## Google mode

Configure native or web credentials as described in `GOOGLE-MAPS-SETUP.md`, then:

```powershell
flutter run --dart-define=MAP_PROVIDER=google --dart-define=GOOGLE_MAPS_READY=true
```

`GOOGLE_MAPS_READY` is an explicit deployment assertion; it is not a credential. OpenStreetMap builds never instantiate the Google widget.

## Architecture and recovery

`StationMap` selects one provider widget. `MapConfiguration`, `MapPosition`, `MapBounds`, and `StationMapMarker` have no plugin imports. `DiscoveryController` debounces/ignores stale viewport work and the server remains authoritative.

The map state holds only its controller, debounce timer, selected ID, and transient tile failure. App termination loses no business truth. The station list remains available during provider, tile, real-time, or network failure.

## Current location

The “Show my location” action checks service availability, requests foreground permission when necessary, retrieves one medium-accuracy position, and renders it through either provider. Android declares coarse/fine foreground permissions; iOS provides `NSLocationWhenInUseUsageDescription`; web geolocation requires a secure context other than the localhost development exception. Denial or timeout leaves the map and list usable. The coordinate is transient and is not written to storage or sent to the station API.

## Commands

```powershell
flutter analyze
flutter test
flutter build web --release --dart-define=MAP_PROVIDER=openstreetmap
flutter test integration_test -d <device-id>
```

Android/iOS integration execution requires a provisioned emulator/simulator or physical device.
