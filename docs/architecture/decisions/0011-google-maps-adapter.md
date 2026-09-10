# ADR 0011: Google Maps behind a public-map adapter

- Status: Superseded by ADR 0014
- Date: 2026-07-22

## Context

The public experience needs an interactive station map, but station discovery, filtering, status semantics, and accessibility must not depend on a browser mapping vendor.

## Decision

Use Google Maps JavaScript API behind a small browser adapter. The Laravel API remains the authority for server-side bounding-box search and all filters. The UI provides a synchronized semantic list that works without the map. The official marker-clustering package groups dense results.

## Consequences

The browser adapter can be replaced without changing public station contracts. A restricted public browser key and quota monitoring are operational requirements. Vendor script failure reduces the experience to the list rather than making station discovery unavailable. Google directions links leave VTSA CSMS and open in a new tab.
