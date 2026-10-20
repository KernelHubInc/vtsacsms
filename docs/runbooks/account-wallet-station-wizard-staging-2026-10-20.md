# Account wallet and station setup on staging — 2026-10-20

The driver app account switcher supports Personal, Business, and Fleet. Personal has a private wallet and vehicles. Shared Business and Fleet accounts use their own vehicles and prepaid wallet. Owners and billing managers can see a shared wallet; drivers and other roles without `wallet_view` see an explanation and a button to choose another account. Switching accounts changes the wallet and vehicles for the next charge. An unfinished charge remains tied to its original payer.

## Operator setup

In the staging admin/operator panel, open **Charging network → Charging Stations**. **Setup guide** explains the workflow before creating a station. After creating one, choose its **Setup** action for a seven-step guide with progress, Previous/Next controls, and links restricted to pages the operator may access:

1. Create and verify the site, coordinates, timezone, public details, and publication.
2. Register the charging station with the exact device charge point identity and OCPP version.
3. Add each independently controlled EVSE.
4. Add each physical connector with its device number, plug standard, current type, and rated power in watts.
5. Set site operating hours; missing hours are unknown, not closed.
6. Publish an effective tariff for the intended connector and review its pricing basis.
7. Configure and reconnect the idle charger, then verify **Online** and a fresh connector status. A heartbeat alone does not establish plug availability.

The wizard changes navigation and guidance only; it does not auto-create equipment or change device configuration.

## Verification and deployment

- Platform: 264 tests and 2,297 assertions passed; scoped Pint passed; Blade templates compiled. The seven station setup tests passed with 106 assertions.
- Mobile: 207 tests passed, including account switching, role-restricted wallet behavior, and wallet reload after switching. Flutter analysis and formatting passed.
- Staging web: the station setup Blade view was deployed to the running platform container and compiled. The staging override points to `vtsa-prepaid-platform:20261020-station-wizard-v2` for the next recreation; the other services, OCPP gateway, payment configuration, and database were untouched. `/admin/login` and `/api/v1/public/stations` returned HTTP 200; an unauthenticated JSON wallet request returned the expected HTTP 401. The platform container was healthy. A container recreation failed during deployment, so the old image was restored and started before the view was applied without another restart. The current container uses the old base image with the tested view copied in, and the override contains the new image for future recreations.
- Staging Android build: version `1.0.22` (`versionCode` 23), signed with the same certificate as `1.0.21`. Both published URLs returned HTTP 200, Android APK content type and the expected 79,989,306-byte length. SHA-256: `aa9867539ba39bcbf46056b487eedcfbbd0bc1bdc71b37cc8b14a43b68d8c2d7`.
  - Versioned: https://staging.evcspowersolutions.com/demo/Power-Solutions-Staging-Lumina-1.0.22.apk
  - Latest demo: https://staging.evcspowersolutions.com/demo/Power-Solutions-Demo.apk
- Release files and the original view are under `/opt/vtsa-releases/station-wizard-20261020/` on the application VPS. To roll back the view, copy `station-setup.before.blade.php` into the running container, run `php artisan view:clear`, and restore `compose.before.json` to `/etc/vtsa-csms/compose.staging-prepaid-platform.json`. Review any later override changes before restoring it.

An authenticated staging wallet session and physical EV charge have not been exercised in this release. No migration was run and no production deployment was made.
