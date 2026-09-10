# Power Solutions Currentline Design Principles

## Purpose

Currentline is the original design system for Power Solutions. It is designed for drivers making time-sensitive decisions and for teams operating safety-, availability-, and finance-sensitive infrastructure. It does not reproduce the visual language, copy, assets, layouts, or trade dress of another charging company.

Currentline is a visual and interaction foundation, not a domain specification. Architecture state machines and owning bounded contexts remain authoritative when labels or flows evolve.

## Principles

1. **Operational truth before decoration.** Show actual state, freshness, source, and uncertainty. Never make stale or provisional data look current or final.
2. **One clear next action.** A surface may contain dense evidence, but its primary action and consequence must remain obvious.
3. **Friction follows risk.** Browsing is fast. Remote commands, refunds, publication, stock adjustments, and other consequential actions require context, reason, confirmation, and traceability.
4. **Context never disappears.** Tenant, organization, location, asset, time zone, currency, and measurement units stay visible wherever ambiguity could cause a wrong decision.
5. **State is more than color.** Every state uses text and, where useful, icon or shape. Color is reinforcement only.
6. **Calm density.** Driver experiences favor progressive disclosure and generous touch targets. Operator experiences support compact scanning without sacrificing hierarchy or keyboard access.
7. **Maps and lists are peers.** Every map result and action has an accessible list equivalent. Selection and filters remain synchronized.
8. **Recovery is part of the happy path.** Offline, stale, empty, loading, partial, and error states explain what is known, what is safe, and what the user can do next.
9. **Platform conventions stay visible.** Money is presented from integer minor units, energy from watt-hours, power from watts, durations from seconds, and timestamps from UTC with explicit display time zones.
10. **Original, restrained character.** The visual signature uses deep navy, capable blue, and restrained cyan/turquoise energy accents. It avoids automotive clichés, copied charger illustrations, and competitor wording.

## Shared token contract

Token names describe purpose, not a single rendered color. Implementations may use platform-native APIs, but semantic meaning and contrast intent must match.

### Typography

The interface stack is `Inter`, then the platform UI sans-serif. Flutter uses the platform sans-serif until a licensed cross-platform font asset is approved. Tabular numerals are required for money, energy, power, duration, and operational identifiers.

| Token | Size / line height | Weight | Use |
| --- | --- | --- | --- |
| `display` | 48 / 52 | 650 | Rare marketing or catalog statement |
| `heading-1` | 36 / 42 | 650 | Page title |
| `heading-2` | 28 / 34 | 650 | Major section |
| `heading-3` | 22 / 28 | 650 | Card group or detail section |
| `title` | 18 / 24 | 650 | Card and dialog title |
| `body` | 16 / 24 | 400 | Primary reading text and mobile fields |
| `body-small` | 14 / 20 | 400 | Dense operator content |
| `label` | 13 / 18 | 600 | Controls and metadata labels |
| `caption` | 12 / 16 | 500 | Supporting metadata; never critical instructions alone |
| `code` | 13 / 20 | 500 | ULIDs, connector IDs, protocol and correlation references |

### Spacing

The base unit is 4 px/logical pixels. Supported steps are `0`, `1` (4), `2` (8), `3` (12), `4` (16), `5` (20), `6` (24), `8` (32), `10` (40), `12` (48), `16` (64), and `20` (80). Components must not introduce arbitrary spacing where a token works.

### Radius

| Token | Value | Use |
| --- | --- | --- |
| `radius-sm` | 6 | Compact controls and tags |
| `radius-md` | 10 | Fields and buttons |
| `radius-lg` | 14 | Cards and menus |
| `radius-xl` | 20 | Feature cards and mobile sheets |
| `radius-full` | 999 | Chips and circular controls |

### Elevation

| Token | Light theme | Dark theme | Use |
| --- | --- | --- | --- |
| `elevation-0` | none | none | In-flow surfaces |
| `elevation-1` | `0 1px 2px rgb(23 27 44 / 8%)` | `0 1px 2px rgb(0 0 0 / 28%)` | Cards |
| `elevation-2` | `0 8px 24px rgb(23 27 44 / 12%)` | `0 10px 28px rgb(0 0 0 / 36%)` | Popovers and sticky controls |
| `elevation-3` | `0 20px 48px rgb(23 27 44 / 18%)` | `0 24px 56px rgb(0 0 0 / 48%)` | Dialogs and sheets |

### Core and semantic colors

| Role | Light | Dark | Notes |
| --- | --- | --- | --- |
| `canvas` | `#F5F8FB` | `#07172C` | App background |
| `surface` | `#FFFFFF` | `#0B2448` | Primary surface |
| `surface-subtle` | `#EDF7FC` | `#102F57` | Grouping and skeleton base |
| `surface-raised` | `#FFFFFF` | `#12366B` | Menus and dialogs |
| `text` | `#17243A` | `#F5FBFF` | Primary text |
| `text-muted` | `#66758A` | `#B9CFDD` | Secondary text |
| `border` | `#D8E4ED` | `#27496F` | Default divider |
| `border-strong` | `#AFC6D8` | `#4E7194` | Emphasized divider |
| `brand` | `#12366B` | `#57DDD2` | Primary action / dark-mode emphasis |
| `brand-strong` | `#0B2448` | `#82EEE6` | Hover or high emphasis |
| `accent` | `#45CFC7` | `#57DDD2` | Energy accent; not a success substitute |
| `success` | `#18A978` | `#55D9A5` | Positive completed/available state |
| `success-surface` | `#E2F7EE` | `#12372F` | Success background |
| `warning` | `#8A5200` | `#F6C453` | Attention, delay, or suspended state |
| `warning-surface` | `#FFF2CF` | `#3A2C12` | Warning background |
| `danger` | `#D64555` | `#FF8094` | Destructive, fault, or failed state |
| `danger-surface` | `#FDE7EC` | `#421D29` | Danger background |
| `information` | `#2589BE` | `#75B8FF` | Neutral guidance or in-progress state |
| `information-surface` | `#E6F1FF` | `#162E4C` | Information background |
| `focus` | `#45CFC7` | `#82EEE6` | Focus ring; minimum 2 px plus offset |

### Charging and operational states

These semantic tokens apply to charger, connector, command, and session projections only where the architecture maps the source state. They do not replace canonical state names.

| Token | Light | Meaning |
| --- | --- | --- |
| `state-available` | `#18A978` | Available for a permitted start |
| `state-preparing` | `#2589BE` | Preparing, authorizing, or starting |
| `state-charging` | `#12366B` | Energy transfer active |
| `state-suspended` | `#A86600` | Session active but energy transfer suspended |
| `state-finishing` | `#2589BE` | Stopping or finalizing |
| `state-reserved` | `#9747B5` | Reserved where the capability is adopted |
| `state-unavailable` | `#697187` | Intentionally unavailable or restricted |
| `state-faulted` | `#D64555` | Fault requires attention |
| `state-offline` | `#4A5164` | Connectivity evidence is outside its freshness window |
| `state-unknown` | `#8C94A8` | Insufficient or conflicting evidence |

Dark themes use lighter foregrounds derived from the same hue family and a dark tinted surface. Text and icons remain mandatory.

### Map-marker states

| State | Fill | Stroke / additional cue |
| --- | --- | --- |
| Available | `state-available` | White center bolt; solid outline |
| Mixed availability | `information` | Split inner ring plus count |
| Busy / charging | `state-charging` | Inner pulse glyph; motion is optional |
| Unavailable | `state-unavailable` | Horizontal bar glyph |
| Faulted | `state-faulted` | Triangle/exclamation glyph |
| Offline or stale | transparent neutral | Dashed outline and age label |
| Selected | underlying state | 3 px `focus` halo and raised z-order |
| Cluster | `text` / `surface` | Numeric count; expanded cluster retains state summary |

### Charger and connector indicators

- Charger connectivity is shown as `connected`, `degraded`, `offline`, or `unknown` with a labeled dot and last-seen time.
- Connector availability is shown as `available`, `preparing`, `charging`, `suspended`, `finishing`, `reserved`, `unavailable`, `faulted`, `offline`, or `unknown` only when supported by normalized evidence.
- Asset lifecycle state and live operational state must be visually separated. A commissioned asset can still be offline; a connected charger can still be restricted.
- Compact indicators use a shape convention: circle for normal/live state, diamond for attention, triangle for fault, and hollow circle for unknown/offline.

## Theme behavior

Light and dark modes carry identical hierarchy and semantics. Theme selection follows the user setting, then system preference. A theme change must not encode a state change, reduce contrast, or reset an in-progress form. Maps use theme-appropriate tiles only after a provider is selected; marker contrast is independent of map imagery.

## Required experience states

| State | Required content and behavior |
| --- | --- |
| Loading | Preserve layout with labeled skeletons or a progress indicator; do not imply live values |
| Empty | Explain why no items exist and provide a permitted next action; distinguish no data from no results |
| Error | Plain-language failure, safe retry, correlation ID when supportable, and preserved user input |
| Offline | Persistent but non-blocking banner; show cached-data age and disable network-only actions |
| Stale data | Timestamp/age beside the affected value; warning treatment and refresh action |
| Partial data | Identify which fields are missing and avoid calculated certainty |

## Assumptions and open decisions

- “Currentline” is a working internal design-system name, not a registered marketing mark.
- A licensed cross-platform font, icon family, illustration language, map tile provider, and chart palette are not selected.
- Localization expansion, right-to-left layouts, high-contrast mode, reduced-data mode, and tablet-specific mobile navigation require validation during product increments.
- Charging-state mappings must be approved with the OCPP normalization contract before product screens use them.
