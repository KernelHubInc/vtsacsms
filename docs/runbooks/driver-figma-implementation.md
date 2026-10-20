# Driver journey: Figma implementation

Reference: [CSMS Driver App design direction board](https://www.figma.com/design/kT7YeBN6rgOm0Yy7tYYOsD/CSMS-Driver-App-v.1?node-id=7-2), inspected 12 October 2026. Screen nodes: Explore `7:20`, Station selection `7:63`, Ready to charge `7:100`, Active charging `7:136`.

## Scope and architecture

The app remains Flutter/Dart with GoRouter, ChangeNotifier controllers, Dio API clients, secure token storage and existing authentication redirects. Driver styling is scoped to discovery and charging; the wallet, profile, identity and payment implementations are retained. No backend contracts, migrations, gateway configuration or dependencies are introduced.

The four frames contain native shapes and text, not exported image assets. Native Material icons and system typography are retained; Inter is not bundled in this repository. Shared spacing, cards, buttons and status components are reused. The board's blue, navy, ice, green and divider colors are in `DriverTheme`; dark mode retains the existing accessible theme. Native safe areas replace the board's illustrated status bar and phone outline. The overlapping Explore heading/subtitle in Figma is laid out vertically without overlap.

## Interactions and integration boundaries

- Explore retains the configured Google/OSM provider, station filters, manual search within loaded bounds, location permission flow, vehicle filtering, favorites and a closable selected pin preview. Results are API-backed; the thumbnail is the board's EV text mark. Distances are labeled straight-line where returned; no travel-time estimate is fabricated.
- Station selection uses public connector IDs already returned by discovery. Only online, fresh, available/preparing connectors can be selected. Continue carries the chosen connector ID into `/charge?code=...`; the existing prepare endpoint validates the target and returns the tariff. Discovery has no per-connector tariff summary, so this screen promises pricing at review rather than displaying a sample price.
- Ready to charge provides physical setup guidance, then fetches the existing expiring quote. App/account authorization is the supported start method; the RFID choice is explicitly unavailable. Existing wallet selection, compatibility warning, reservation disclosure and explicit start confirmation remain. Guidance is not evidence that an EV is physically connected.
- Active charging renders server energy, duration, cost and status. Power and energy-flow time remain under session details. The energy rule is decorative, not a percentage or battery state of charge. Polling/offline/disconnected/pending/payment-review states remain explicit. End charging confirms and uses the existing remote-stop controller; acknowledgement does not mark the session complete.
- Activity and Account names are retained consistently rather than renaming those existing destinations to Sessions and You. The active-session return banner remains on the main tabs.
- Help provides local recovery guidance and the session reference. Existing issue/refund UI is preserved; live support/refund endpoints remain outside the prepaid pilot as documented in the charging runbook. No new service integration is implied.

## Verification

The automated journey tests use repository fakes, never a real charger, wallet or payment provider. They exercise search, occupied-plug rejection, selected connector ID propagation, tariff review, confirmed start, pending state and confirmed stop. Screens are rendered at 320 and 390 logical pixels, including enlarged text. Preview PNGs are generated only when requested:

```powershell
cd "C:\Users\Migz\Documents\VSTA Charging\apps\mobile"
$env:FLUTTER_ROOT = 'C:\Users\Migz\Documents\flutter'
flutter test --dart-define=DRIVER_PREVIEWS=true test/driver_journey_design_test.dart
```

Previews are stored in `.cache/driver-design-preview` at the repository root. The default fixture disables map tiles, so its Explore screenshot verifies the list fallback; provider rendering and gestures have separate map tests. Screenshots use fixture data, not live staging evidence. A supervised physical EV session is still required for end-to-end charger acceptance.

No deployment, real charging command, wallet transaction, commit or push is part of this design change.

## Changed files

Paths below are relative to `apps/mobile` unless indicated otherwise.

| Files | Change |
| --- | --- |
| `lib/design_system/components/driver_components.dart` | Scoped driver colors, page layout, notices and bottom navigation |
| `lib/design_system/components/vtsa_components.dart` | Allow button labels to wrap on small screens and with enlarged text |
| `lib/features/discovery/presentation/discovery_screen.dart` | Search, compact real map, station results, retained filters and pin dismissal |
| `lib/features/discovery/presentation/station_widgets.dart` | Compact station summary using the existing station model |
| `lib/features/discovery/presentation/station_detail_screen.dart` | Plug selection, live readiness gate, station disclosure and directions |
| `lib/features/charging/presentation/charging_start_screen.dart` | Guided preparation, selected connector resolution and retained quote/start confirmation |
| `lib/features/charging/presentation/live_charging_screen.dart` | Status, energy/cost hierarchy, help, confirmation-based stop and expandable readings |
| `lib/app/app_router.dart`, `lib/app/consumer_shell.dart` | Selected connector routing and scoped styling; existing auth guards retained |
| `test/driver_journey_design_test.dart`, `test/support/fakes.dart` | Responsive journey tests, fixture connector tracking and optional PNG rendering |
| `test/charging_journeys_test.dart`, `test/app_journeys_test.dart`, `test/auth_navigation_test.dart` | Update expected screen labels and scrolling while retaining behavior assertions |
| `docs/runbooks/driver-figma-implementation.md` (repository root) | Scope, differences from Figma, integration limits and verification instructions |

The full mobile regression suite passed: **163 tests**. The responsive journey preview run passed all four cases (320 px, 390 px, enlarged text and active-session stop). Existing unrelated working-tree changes are outside this inventory.

Flutter analysis passed with no issues. The Dart format check passed for all 14 touched Dart files, and the scoped Git whitespace check passed.

The Android staging release build passed as version **1.0.13+14** using `dart_defines.staging-demo.json`. Output: `apps/mobile/build/app/outputs/flutter-apk/app-release.apk` (75.7 MB). An initial `--no-pub` build encountered stale generated registration for the test-only `integration_test` plugin; rebuilding with Flutter's normal metadata generation resolved it without changing dependencies. The existing `mobile_scanner` plugin emits a future Kotlin Gradle compatibility warning. The APK has not been installed on a phone or published to the VPS as part of this change.

## Branded tabs and expanded map — 2026-10-13

Follow-up source frames: `4:162` Account, `4:219` Wallet and `4:95` Activity. These screens use the v4 shared palette and navigation, with the existing bundled Power Solutions horizontal logo replacing the design's text wordmark. No new dependencies or backend contracts are introduced.

- Account: ice profile card, truthful email-verification label, compact navigation rows, existing profile/identity/vehicles/security/privacy/sign-out actions and a wallet shortcut. Unsupported RFID management and saved-card/notification screens from the concept are not represented as working features.
- Wallet: navy available-balance card, existing QR Ph Add money flow, Top-ups/Transactions switching, separate pagination and payment-status recovery. No payment is initiated until the existing amount confirmation. Reserved funds remain visible.
- Activity: All/Completed/Pending filters apply to loaded server history; the current session remains pinned separately. Session cards retain authoritative energy, duration, final/estimated cost and status, with existing receipt/refund/issue navigation. Completed records matching the recovered terminal session are no longer excluded from history.
- Explore: bundled logo, responsive 300–440 logical-pixel map, in-map GPS and expand/close controls. Expanded view retains the map instance/camera, station preview and provider attribution. Android back first closes the expanded view. GPS is requested only on interaction; denial leaves browsing available. Repeated GPS presses recenter even when coordinates are unchanged. Rotation stays disabled and pinch zoom remains available. Both map providers initialize around a previously requested user position.

Changed implementation: `consumer_shell.dart`, `driver_components.dart`, `account_screens.dart`, `wallet_screen.dart`, `activity_screens.dart`, `discovery_screen.dart` and both provider map widgets. Regression coverage is in `driver_tabs_design_test.dart`, updated logo/wallet assertions and shared fixtures. Preview helper waits for bundled images before capture. Map previews use transparent test tiles; they validate controls and markers, not live map-network availability or physical GPS accuracy.

The release requires the same staging API configuration as 1.0.13. No migrations, gateway changes or payment-provider changes are required.

Verification: the full mobile suite passed **166 tests**. After final chip/row styling, the three responsive/map cases passed again and Flutter analysis reported no issues. Account, Activity and Wallet previews were checked at 390 px and 320 px with 160% text. Existing payment confirmation, sign-in, quick unlock and charging tests remain passing. Device installation and live charger/payment testing remain separate from these automated checks.

Release build: **1.0.14+15**, staging defines, 79,480,418 bytes. SHA-256: `1eacc07a95356efa63ba68817eff91d9f5053ca008e37f24acedf29b803dd2b2`. Android compilation succeeded; the existing `mobile_scanner` future Kotlin compatibility warning remains.

Published on 2026-10-13: [Download 1.0.14](https://staging.evcspowersolutions.com/demo/Power-Solutions-Staging-Lumina-1.0.14.apk). Remote SHA-256 matched; HTTPS HEAD returned 200, the exact byte count above and Android APK content type. Previous APKs remain available. Installation and real-device validation have not been performed by this task.
