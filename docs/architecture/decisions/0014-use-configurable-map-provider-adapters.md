# ADR 0014: Use configurable map-provider adapters

- Status: Accepted
- Date: 2026-07-29
- Supersedes: ADR 0011

## Context

VTSA CSMS needs credential-free local maps while retaining optional Google Maps across Laravel and Flutter. Station discovery and geographic truth must remain independent of rendering vendors.

## Decision

Use `openstreetmap` and `google` as the only provider identifiers. OpenStreetMap is the default and fallback. Laravel web surfaces use a shared contract implemented by Leaflet.js and Google Maps JavaScript adapters. Flutter uses provider-neutral map models and a widget factory implemented by `flutter_map` and `google_maps_flutter`.

PostGIS remains authoritative for WGS84 coordinates, distance, bounds, and visibility. A safe mobile configuration endpoint returns rendering preferences but no keys. Non-secret database preferences override environment defaults; secret readiness remains deployment-managed. Provider-setting changes are authorized, cached, and audited.

## Consequences

Local/demo environments work without Google credentials. SDK failures preserve lists and manual coordinates. Adapter lifecycle and parity require explicit contract, browser, and widget tests. Production OpenStreetMap deployments must select a tile service with suitable policy/capacity. Google remains subject to platform key restriction, quota, and billing controls.
