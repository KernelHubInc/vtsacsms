# Power Solutions brand system

## Brand name

The customer-facing product name is **Power Solutions**. Use it in title case in normal copy. `VTSA`, `VSTA`, and `VTSA CSMS` remain only in internal technical identifiers, historical architecture records, immutable demo identifiers, package namespaces, and integration keys where changing them would break compatibility.

The official client-supplied artwork (November 3, 2026) includes EVCS, POWER SOLUTIONS and the company line **by VTSA International**. Keep the complete lockup intact. The former normalized Canva approximation is superseded.

## Approved artwork

`apps/platform/public/branding/power-solutions-logo-artwork.png` preserves the tightly cropped 1875 × 549 source artwork from the supplied JPG. The displayed `power-solutions-logo.png` is a 2003 × 677 PNG with 64 pixels of white clear space on every side, so the lettering and tiles do not touch the image edges. The original lettering, gradients, motif and byline are preserved without resampling; no logo text is reconstructed with system fonts. The large blank margins from the supplied JPG remain removed. The white plate also keeps the navy lettering readable on dark headers. Do not invert or recolor the artwork.

Use the complete horizontal logo for website headers, admin/operator panels, authentication, mobile pages, email and PDF receipts. Keep its aspect ratio. Compact launcher icons, favicons and PWA install icons use the original diagonal tile motif on a white square, with safe space for OS icon masks. Historical SVG URLs embed the current artwork for compatibility; new web views use the PNG directly.

The logo alternative text is `Power Solutions`; decorative duplicates use an empty alternative. Functional icons and third-party marks remain separate from the product logo.

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

Use the complete official PNG on both light and dark surfaces; its white plate keeps the navy lettering readable. The legacy dark asset filename is retained as a copy for compatibility. Navigable mobile screens use the shared deep-navy `PowerSolutionsAppBar` with the official horizontal logo, page hierarchy, automatic back navigation, and restrained turquoise/blue geometry. Startup and onboarding are the deliberate full-screen exceptions.

Splash and onboarding screens use the centered horizontal lockup over the code-native Power Solutions energy-path motif. The motif combines an abstract charging route with the rising rounded tiles from the brand mark; it is decorative, scalable, and hidden from assistive technology. Do not add artificial launch delays, and keep motion restrained for reduced-motion preferences.

## Asset locations

- Web PNG master, compatibility SVGs and raster metadata assets: `apps/platform/public/branding/`
- Shared Blade logo component: `apps/platform/resources/views/components/brand/logo.blade.php`
- Flutter raster assets: `apps/mobile/assets/branding/`
- Flutter reusable logo widget: `apps/mobile/lib/design_system/branding/power_solutions_logo.dart`
- Flutter shared navigation header: `apps/mobile/lib/design_system/branding/power_solutions_app_bar.dart`
- Flutter vector backdrop and feature artwork: `apps/mobile/lib/design_system/branding/power_solutions_backdrop.dart`
- Android adaptive and legacy icons: `apps/mobile/android/app/src/main/res/`
- iOS icons and launch art: `apps/mobile/ios/Runner/Assets.xcassets/`
- Flutter Web favicon/PWA icons: `apps/mobile/web/`
- Native raster export: `tools/generate_brand_assets.ps1` (`generate_brand_assets.py` remains a compatibility entry point)

Run the generator after replacing master artwork:

```powershell
powershell -NoProfile -File tools/generate_brand_assets.ps1
```

Inspect favicon, sidebar, authentication, splash, and print sizes after regeneration. Do not alter map-provider attribution, third-party payment marks, operational status semantics, or legal copy as part of a visual refresh.

The public charging map is an application-style viewport. Its site navigation, filter grid, and map remain fixed while the accessible station-results pane scrolls independently. On narrow viewports the filters collapse behind a native disclosure and the map/list stack vertically without allowing map layers to overlap navigation or controls.

## Accessibility

Customer-facing logo images require a text alternative. Decorative duplicates are hidden from assistive technology. Use turquoise on white only for non-text decoration or when exact size and weight pass contrast; default links use brand blue. The cyan `focus-ring` primitive is retained for brand composition, while interactive focus on light surfaces uses the higher-contrast link blue (`#1D78AD`). Maintain visible keyboard focus, text-plus-shape status meaning, scalable text, reduced motion, and 44-pixel touch targets.
