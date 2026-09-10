# Google Maps Key Runbook

## Local configuration

Set `GOOGLE_MAPS_BROWSER_API_KEY` in `apps/platform/.env` and choose `google` for the required map surface. A non-empty key is the server-side web-readiness signal. Leave it blank to use the credential-free OpenStreetMap default. `GOOGLE_MAPS_MAP_ID` is optional. Never commit production values.

Flutter native builds receive a separately restricted key at build time and use `GOOGLE_MAPS_MOBILE_READY` only as a non-secret server readiness signal. The safe `/api/v1/app/config` response never contains a key. See [Google setup](../maps/GOOGLE-MAPS-SETUP.md) and [Flutter setup](../maps/FLUTTER-MAPS-SETUP.md).

## Restriction checklist

1. Create a browser-only key in the intended Google Cloud project.
2. Apply HTTP referrer application restrictions using exact production and approved preview origins. Add localhost only to a separate development key.
3. Restrict API use to Maps JavaScript API. Do not enable Places, Routes, Geocoding, or server APIs unless an approved ADR and threat review requires them.
4. Use separate keys and projects, or at least separate keys, for development, staging, and production.
5. Rotate a suspected-exposed key, review usage, and remove the old key after the new configuration is deployed.

## Quota and billing safeguards

- Set conservative per-minute and per-day Maps JavaScript API quotas based on measured traffic, then increase deliberately.
- Configure Google Cloud billing budgets and escalating alerts. Budget alerts are notifications, not hard spending caps.
- Alert on request spikes, authorization failures, quota exhaustion, and unexpected referrers.
- Review the API dashboard and billing export regularly; investigate material deviation from the traffic forecast.
- The map must retain its list fallback during quota exhaustion, script failure, ad blocking, or billing suspension.

## Verification

- Load `/charging-map` and confirm no key appears in repository files or server logs.
- Confirm requests from an unapproved referrer are rejected.
- Confirm only the Maps JavaScript API accepts the key.
- Remove the local key and confirm the accessible list and filters still work.
- Verify marker clustering at a bounding box containing many stations and verify stale status remains labeled.
