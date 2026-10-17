# Station setup in administration and operator portals

Requirements: FR-AST-001/002/003, FR-MOB-002, FR-CHG-003.

The same resources are shared by administration and operator panels. Permissions,
not job titles, determine access. Equipment managers need `assets.manage` in the
station's site scope plus viewing/panel permissions. Location managers control
hours and publication. Tariff managers/publishers control pricing. Read-only staff
can inspect setup and live evidence but cannot add or change equipment. Drivers
do not receive these management permissions.

## Commissioning sequence

1. **Sites → Create:** enter the actual operator, location, coordinates, IANA
   timezone, address and visibility. A site is a physical location, not a plug.
   Save, then open its edit page. **Publish location** requires Active, Public,
   address, coordinates and valid timezone. **Unpublish location** removes public
   discovery; neither action changes a physical charger or ends a session.
2. **Charging Stations → Create:** select the site, exact device identity,
   serial, unique station QR identifier, supported OCPP version and visibility.
   Creation redirects to **Station setup**. Existing stations have a **Setup**
   row action and a **Setup checklist** button on their edit page.
3. Follow **EVSEs → Add EVSE**. Select the station and its actual outlet number.
   An EVSE is an independently controlled charging outlet. Configure each outlet
   explicitly; do not guess a multi-plug device's concurrent charging capability.
4. Follow **Connectors → Add connector**. Select the parent station/EVSE, actual
   OCPP connector number, plug standard, AC/DC current type and rated watts.
   Example: 7 kW = 7000 W. A unique QR identifier is generated automatically and
   shown in View and Station setup. Use the existing mobile QR workflow; a QR
   identifier is not the WebSocket identity. Activate both EVSE and connector
   when the equipment is ready for service. Draft is the default.
5. **Operating hours → Add operating day:** add each weekday, Monday–Sunday.
   Times are in the site's timezone. Earlier closing than opening means next-day
   closing; equal times are rejected as ambiguous. Select **Open 24 hours** for
   all-day opening (stored as 00:00–24:00). Closed days have no times.
   Existing days are edited rather than added again. Missing days remain unknown.
6. **Tariffs → Create**, then **Set pricing**: select the specific connector or
   desired scope, rate, energy/session/energy-flow-time mode and applicable tax
   settings. Publish through that action. Existing sessions retain their snapshot.
7. Return to **Station setup**. The checklist distinguishes public configuration,
   equipment lifecycle, hours and effective tariff coverage from device evidence.
   It does not certify a successful payment or physical charge.
8. Keep the deployment's working OCPP host/protocol settings. The final path is
   `/ocpp/{charge-point-identity}`. When firmware appends a separate ID, use the
   base path `/ocpp` and enter the ID once. Dynamic gateway enrollment resolves
   saved active stations; no per-device Nginx edit or restart is needed.
9. Reconnect only an idle device and confirm a fresh connection and connector
   status. Perform a supervised EV session separately to verify energy, stop,
   wallet settlement and receipt. Configuration alone cannot prove those steps.

For LUMINA SUBIC, retain identity `202607010021`, EVSE 1, Connector 1, Type 2,
AC, 7000 W and the existing published test tariff. Do not duplicate its records.

## Validation and historical integrity

- Parent records are tenant scoped and server-authorized, including forged IDs.
- EVSE numbers are unique within a station. OCPP 1.6 connector numbers are unique
  across all EVSEs of a station; OCPP 2.0.1 numbers are unique within an EVSE.
- Station row locks serialize concurrent equipment additions. Duplicate attempts
  return field errors and do not create additional equipment or audit successes.
- Parent and protocol numbering are not editable through EVSE/connector forms.
  Plug/current identity is locked after creation. Correct a mistaken unused draft
  by deleting/recreating it; retain equipment with history. This is not a hardware
  replacement/migration workflow.
- Creation, hours changes and publication use owning-context workflows and audits.
- Available/Charging/Faulted remain device observations, never invented at creation.
- Existing guarded deletion remains in effect. No schema migration or backfill is
  required; no existing site is automatically published or equipment activated.

## Verification

`tests/Feature/Portal/StationSetupTest.php` exercises actual Filament creation,
duplicate errors, hours editing, publication, setup rendering, operator/viewer
permissions, numbering immutability, cross-site/cross-tenant denial and protocol
numbering. Run the portal and enrollment tests plus the full PHP suite, Pint,
PHPStan and frontend production build before deploying.

Deployment must rebuild/recreate the platform and application workers using the
existing private staging overrides. It does not require changing/restarting the
OCPP gateway or rebuilding the mobile APK. Preserve the prior image for rollback.

## Staging verification � 2026-10-15

Deployed image: `vtsa-prepaid-platform:20261015-station-setup-r2`. The final
release container passed 254 backend tests / 2,150 assertions. PHPStan, Pint,
frontend production build, four map tests and Composer dependency audit passed.
37 rollback-only PostgreSQL/API checks passed before and after deployment,
including connector creation, duplicate rejection, 24-hour schedule persistence
and publication. No real payment or charger command was issued.

All five application services were checked running; the native `vtsa-ocpp`
process remained unchanged. No migration was needed. Release and rollback
evidence is retained under `/opt/vtsa-releases/station-setup-20261015-r2/`.
The preceding admin-audit and initial setup images remain available for rollback.

Authenticated browser checks confirmed the LUMINA SUBIC setup page and its
station-filtered connector/site-filtered hours links. Its Type 2 AC 7000 W
connector and effective tariff are present. Operating days are unconfigured;
the charger is offline, last seen 2026-10-13 14:28:21 UTC. This release does not
claim a completed physical charging session. The mobile APK is unchanged.
