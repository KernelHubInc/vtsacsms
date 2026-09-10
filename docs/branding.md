# Power Solutions brand system

## Brand name

The customer-facing product name is **Power Solutions**. Use it in title case in normal copy. `VTSA`, `VSTA`, and `VTSA CSMS` remain only in internal technical identifiers, historical architecture records, immutable demo identifiers, package namespaces, and integration keys where changing them would break compatibility.

The company line visible in the supplied Canva reference is not part of the primary product lockup. It may appear only in an approved legal or company-information context.

## Approved logo variants

| Variant | Use | Minimum rendered size |
| --- | --- | --- |
| Horizontal | Headers, authentication, navigation, documents | 136 px wide |
| Horizontal dark | Deep navy or dark surfaces | 136 px wide |
| Stacked | Square or centered presentation areas | 96 px wide |
| Mark | Favicon, app icon, compact navigation, splash | 24 px |
| Monochrome | Single-color print and constrained output | 136 px wide |

Keep clear space around every lockup equal to at least one small tile in the mark. Preserve aspect ratio, never stretch, outline, rotate, shadow, filter, recolor, or rearrange the logo. Use an explicit light or dark variant instead of a CSS filter. The logo alternative text is `Power Solutions`; decorative marks use an empty alternative.

The repository did not contain a lossless Canva export. The normalized vector family follows the supplied Canva design reference and approved colors. If the client later supplies production master artwork, replace the normalized files without changing their public filenames, then run the asset generator and the complete visual regression checks.

## Color tokens

| Role | Value |
| --- | --- |
| Brand navy | `#12366B` |
| Brand blue | `#2589BE` |
| Brand turquoise | `#57DDD2` |
| Brand cyan | `#45CFC7` |
| Brand dark navy | `#0B2448` |
| Default / soft cyan / soft blue / muted surfaces | `#FFFFFF` / `#E9FAF8` / `#EDF7FC` / `#F5F8FB` |
| Primary / secondary / on-primary / link text | `#17243A` / `#66758A` / `#FFFFFF` / `#1D78AD` |
| Default / strong border / focus | `#D8E4ED` / `#AFC6D8` / `#45CFC7` |
| Success / warning / danger / information | `#18A978` / `#E8A522` / `#D64555` / `#2589BE` |

Primary interaction uses navy with `#0E2B59` hover, `#091F43` active, and `#9CB0C6` disabled. Accent interaction uses cyan with `#32BBB4` hover and `#25A49E` active.

Web tokens live in `apps/platform/resources/css/tokens.css`; Tailwind consumes the same custom properties. Flutter tokens and `ThemeData` live in `apps/mobile/lib/design_system/theme/`. Keep literal brand values out of feature code.

## Typography and interaction

Inter is preferred with the existing system-ui fallback stack. Use strong, readable weights for page titles, labels, tabular data, and critical state. The visual POWER/SOLUTIONS distinction belongs only inside the logo.

Primary buttons are navy with white text. Accent buttons are cyan with dark navy text. Destructive actions always use semantic danger. Every control must provide default, hover where relevant, focus-visible, active, disabled, and loading behavior without layout shift.

## Navigation and operational status

Desktop operational navigation uses deep navy, pale inactive labels, and a cyan indicator plus weight/background for the active item. Mobile navigation retains labels, safe-area spacing, and a minimum 44-by-44 logical-pixel target.

Brand colors do not replace operational semantics. Available, preparing, charging, suspended, finishing, reserved, unavailable, faulted, offline, and unknown always have text labels; use an icon, marker, or shape in addition to color. Fault and destructive states remain red, warnings remain amber, and offline/unknown remain neutral.

## Light, dark, and splash usage

Use the standard horizontal asset on white and soft surfaces. Use the dark-background asset on navy and dark surfaces. Navigable mobile screens use the shared deep-navy `PowerSolutionsAppBar` with the inverse horizontal logo, page hierarchy, automatic back navigation, and restrained turquoise/blue geometry. Startup and onboarding are the deliberate full-screen exceptions.

Splash and onboarding screens use the centered horizontal lockup over the code-native Power Solutions energy-path motif. The motif combines an abstract charging route with the rising rounded tiles from the brand mark; it is decorative, scalable, and hidden from assistive technology. Do not add artificial launch delays, and keep motion restrained for reduced-motion preferences.

## Asset locations

- Web SVG and raster metadata assets: `apps/platform/public/branding/`
- Shared Blade logo component: `apps/platform/resources/views/components/brand/logo.blade.php`
- Flutter raster assets: `apps/mobile/assets/branding/`
- Flutter reusable logo widget: `apps/mobile/lib/design_system/branding/power_solutions_logo.dart`
- Flutter shared navigation header: `apps/mobile/lib/design_system/branding/power_solutions_app_bar.dart`
- Flutter vector backdrop and feature artwork: `apps/mobile/lib/design_system/branding/power_solutions_backdrop.dart`
- Android adaptive and legacy icons: `apps/mobile/android/app/src/main/res/`
- iOS icons and launch art: `apps/mobile/ios/Runner/Assets.xcassets/`
- Flutter Web favicon/PWA icons: `apps/mobile/web/`
- Deterministic raster generator: `tools/generate_brand_assets.py`

Run the generator after replacing master artwork:

```powershell
python tools/generate_brand_assets.py
```

Inspect favicon, sidebar, authentication, splash, and print sizes after regeneration. Do not alter map-provider attribution, third-party payment marks, operational status semantics, or legal copy as part of a visual refresh.

The public charging map is an application-style viewport. Its site navigation, filter grid, and map remain fixed while the accessible station-results pane scrolls independently. On narrow viewports the filters collapse behind a native disclosure and the map/list stack vertically without allowing map layers to overlap navigation or controls.

## Accessibility

Customer-facing logo images require a text alternative. Decorative duplicates are hidden from assistive technology. Use turquoise on white only for non-text decoration or when exact size and weight pass contrast; default links use brand blue. The cyan `focus-ring` primitive is retained for brand composition, while interactive focus on light surfaces uses the higher-contrast link blue (`#1D78AD`). Maintain visible keyboard focus, text-plus-shape status meaning, scalable text, reduced motion, and 44-pixel touch targets.
