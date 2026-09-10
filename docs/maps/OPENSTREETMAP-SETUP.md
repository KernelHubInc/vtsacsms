# OpenStreetMap Setup

No Google credential is required. The local defaults are:

```env
MAP_TILE_URL_TEMPLATE=https://tile.openstreetmap.org/{z}/{x}/{y}.png
MAP_TILE_ATTRIBUTION="© OpenStreetMap contributors"
MAP_MIN_ZOOM=3
MAP_MAX_ZOOM=19
MAP_TILE_MAX_NATIVE_ZOOM=19
MAP_MARKER_CLUSTERING=true
```

Leaflet.js and `flutter_map` are renderers, not tile-hosting services. The community OpenStreetMap tile endpoint is suitable for limited local/demo use subject to its policy; it must not be assumed to support unrestricted production traffic.

For production, contract with a managed OpenStreetMap-compatible provider, operate an approved company tile service, or select another approved compatible service. Configure its HTTPS template and required attribution. Do not remove attribution. Token placeholders may appear in a deployment-injected URL, but credentials must not be committed or entered through the admin UI.

The web adapter uses a small in-memory viewport and marker set. Flutter’s default tile provider keeps a bounded visible buffer (`panBuffer=1`, `keepBuffer=2`) and disposes its cache with the widget; no durable unbounded tile cache is enabled.
