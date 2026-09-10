# Map Troubleshooting

## Web

- **Blank or duplicate Leaflet map:** confirm the page uses `data-map-config`, has one connected `data-map-canvas`, and was not initialized outside the registry. Livewire navigation must emit `livewire:navigating` so the adapter destroys its map.
- **Map container already initialized:** obsolete direct Leaflet initialization remains. Search for `L.map(` outside `OpenStreetMapWebProvider`.
- **Map clipped after a drawer/tab/sidebar change:** verify `ResizeObserver` is active and call the adapter `resize()` after the element becomes visible.
- **Google script loads twice:** only `loadGoogleMaps` may append `data-vtsa-google-maps`; remove static web SDK tags on Laravel pages.
- **Google falls back:** check System settings readiness, `GOOGLE_MAPS_BROWSER_API_KEY`, allowed origin, Maps JavaScript API restriction, quota, and browser console error code.
- **Tile errors:** verify HTTPS, `{z}/{x}/{y}`, attribution, provider quota/CORS, and network. The list and filters should remain usable.
- **No markers:** inspect the bounding-box API response and coordinate range. Never “fix” overlap by changing stored coordinates.

## Flutter

- **OpenStreetMap blank on Web:** check tile CORS and HTTPS. The configured host must allow browser tile requests.
- **Google blank:** native key injection or the Flutter Web Maps JavaScript script is missing. Keep `GOOGLE_MAPS_READY=false` until configured.
- **Controller used after disposal:** ensure map commands stay inside provider state and timers are canceled before controller disposal.
- **Too many requests:** viewport work must occur after camera idle/debounce; do not call repository search from every camera frame.
- **Emulator cannot reach API:** Android normally uses `10.0.2.2`; iOS simulator normally uses `127.0.0.1`.
- **Old response replaces new viewport:** verify `DiscoveryController` request generation remains intact.

## Diagnostics

Logs contain provider, surface, and safe failure code only. They must not contain tile tokens or Google keys. `/api/v1/app/config` is intentionally safe to inspect. Use `make maps-test`, then browser tests, before changing provider configuration.
