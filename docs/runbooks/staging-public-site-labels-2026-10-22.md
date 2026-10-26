# Staging public-site labels — 2026-10-22

The public website no longer labels the entire charging map as a demo. That notice was hard-coded in the map template and drawer, even though Lumina Subic is a physical charger. The unavailable-feature page now links back to the website without calling it a demo.

On staging, the operator approved `Power Solutions` as the public operator name for Lumina and VTSA-CP01, and `Power Solutions` as the public name of VTSA's site. The shared operator organization and VTSA site were renamed in one tenant-scoped transaction with two audit entries. VTSA's address and coordinates were kept at the operator's request. No charger identifiers, OCPP configuration, availability, tariff, wallet, or charging-session records changed. The old `/stations/pineway-center-demo` URL is not redirected; the new public URL is `/stations/power-solutions-vtsa`.

The original organization and site rows were saved on the staging application VPS at `/root/vtsa-staging-backups/public-names-before-20261022.json` (mode `0600`, SHA-256 `3cbc3b77ffa107b10608e6f83ae5ce5f59e243eae7b0d54d22991894e9b570e7`). The operation is reversible using the recorded values and audit history. This file is not part of the deployment bundle.

Before production publication, verify that VTSA's retained Pasig address and coordinates refer to a real, publicly accessible charging location. The previous map copy described that location as fictional; this change preserves the operator's requested data but does not independently validate it. Retired demo records remain private in staging for immutable maintenance history and are not returned by the public station API. This change does not clean up those historical records or deploy to production.
