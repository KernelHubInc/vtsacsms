# Component Inventory

## Naming and ownership

Shared visual primitives use the `Vtsa` prefix in Flutter and the `ui` Blade namespace. Filament receives the same theme semantics but retains its framework component APIs. Domain-specific components belong to their bounded context and must compose these primitives rather than add conflicting global variants.

## Foundation inventory

| Foundation | Blade | Flutter | Filament | Required variants / states |
| --- | --- | --- | --- | --- |
| Button | `x-ui.button` | `VtsaButton` | Theme styles | Primary, secondary, quiet, danger; small/default/large; busy/disabled |
| Form field | `x-ui.form-field` | `VtsaTextField` | Theme styles | Label, hint, error, required, prefix/suffix, disabled |
| Card | `x-ui.card` | `VtsaCard` | Section/card theme | Default, subtle, interactive, selected |
| Status chip | `x-ui.status-chip` | `VtsaStatusChip` | Badge colors | Neutral, success, warning, danger, information, charging states |
| Skeleton | `x-ui.skeleton` | `VtsaSkeleton` | Loading placeholder theme | Text, avatar, card; reduced-motion static mode |
| Empty state | `x-ui.empty-state` | `VtsaEmptyState` | Empty-state composition | No data, no results, permission-limited |
| Error state | `x-ui.error-state` | `VtsaErrorState` | Error composition | Inline, section, page; retry/support reference |
| Confirmation | `x-ui.confirmation-dialog` | `showVtsaConfirmationDialog` | Filament modal theme | Standard, risky, destructive; reason slot |
| Toast | `x-ui.toast` | `showVtsaToast` | Notification theme | Success, information, warning, failure; polite/urgent semantics |
| Page shell | `x-ui.page-shell` | `VtsaPageShell` | Panel layout theme | Title, description, breadcrumbs, actions, content width |
| Responsive navigation | `x-ui.responsive-nav` | `VtsaResponsiveNavigation` | Panel navigation theme | Mobile drawer, tablet rail, desktop sidebar |
| Mobile bottom shell | Not applicable | `VtsaBottomNavigationShell` | Not applicable | Four destinations, active-session slot, safe area |
| Focus treatment | Global CSS | Theme focus/Material states | Theme CSS | Keyboard-visible ring, high contrast |

## Supporting primitives

- Brand mark placeholder using typography/geometry only; no unapproved logo asset.
- Icon container with accessible-label rules.
- Divider, metadata pair, unit value, timestamp/freshness label, and correlation reference.
- Inline banner for offline, stale data, permissions, or partial evidence.
- Progress indicator for known and unknown duration.
- Segmented map/list switch and filter chip.

These supporting primitives are specified but should be implemented only when a product increment needs them.

## Status-chip vocabulary

Visual tones are intentionally smaller than domain state vocabularies:

| Tone | Appropriate states |
| --- | --- |
| Neutral | Draft, unknown, unavailable, canceled when no warning is required |
| Success | Available, completed, succeeded, approved, received |
| Information | Requested, pending, authorizing, starting, stopping, finalizing, submitted |
| Warning | Suspended, partially complete, review required, stale, outcome unknown |
| Danger | Faulted, failed, rejected, blocked, destructive impact |
| Charging | Active energy transfer only |

The component takes a displayed label; it does not infer domain meaning from arbitrary strings.

## Page-state compositions

### Loading

Page title and navigation remain stable. Skeleton geometry approximates expected content. A single loading status is announced; individual skeleton blocks are hidden.

### Empty

“No data yet” and “No results for these filters” are distinct. The latter preserves filters and offers clear/reset actions. Permission-limited absence is not presented as ordinary emptiness.

### Error

Explain the failed operation, preserve safe context/input, offer a bounded retry, and expose a correlation reference when one exists. Avoid raw exceptions.

### Offline and stale

Offline is a shell-level banner when the whole client is disconnected. Stale is attached to the affected dataset or value. Network-only mutation controls are disabled with reasons, not removed silently.

## Visual catalog coverage

The Laravel design-system route and Flutter catalog widget exercise both themes, semantic colors, buttons, fields, cards, chips, loading, empty/error, dialog, toast, page shell, and responsive navigation. Catalog data is fictional UI copy only and must not create database records or imply a business feature exists.

## Contribution checklist

- Reuse a semantic token; do not hard-code a close color or spacing value.
- Add a variant only for a recurring semantic need, not one screen.
- Define keyboard, screen-reader, loading, disabled, error, dark, reduced-motion, and large-text behavior.
- Add component or widget tests and catalog coverage.
- Confirm terminology against the owning architecture/state machine.
- Do not put authorization or domain transition logic inside a visual component.

## Deferred components

Data tables, charts, maps, date/time inputs, file upload, stepper, command palette, timeline/audit evidence, filter builder, money/measurement input, and domain-specific session/asset cards are deferred until their first product increment.
