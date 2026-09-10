# Accessibility Standard

## Target

Power Solutions targets WCAG 2.2 Level AA for public, mobile, operator, and administrator experiences. Native mobile semantics and platform accessibility guidance are additional requirements, not substitutes. Accessibility acceptance is required for every product increment.

## Perceivable content

- Normal text requires at least 4.5:1 contrast; large text requires 3:1.
- Component boundaries, focus indicators, status icons, and meaningful graphical objects require at least 3:1 against adjacent colors.
- Color never carries state alone. Pair it with text and, where scanning benefits, icon or shape.
- Text supports 200% browser zoom without loss of content or function. Mobile layouts support platform text scaling without clipped controls.
- Images require useful alternatives; decorative imagery is ignored by assistive technology.
- Charts require a textual summary and accessible data table. Maps require a synchronized result list and textual location detail.

## Operable interaction

- All web actions work with keyboard alone. Focus order follows the visual/task order.
- Focus is always visible using the shared focus token, at least 2 px thick with separation from the component boundary.
- Minimum pointer target is 24 by 24 CSS pixels under WCAG, with a product target of 44 by 44 for primary/mobile controls.
- Do not create keyboard traps. Modal dialogs trap focus only while open, close on Escape when safe, and restore focus to the opener.
- Skip links reach main content and primary navigation. Repeated operator navigation supports landmark shortcuts.
- Drag, map pan, swipe, or scan interactions always have a non-gesture alternative.
- Timeouts warn users and offer extension where security policy permits it.

## Understandable content

- Labels remain visible; placeholders are examples, not labels.
- Required fields, formats, units, and constraints are explained before submission.
- Error summaries identify the issue and link/focus the affected field. Valid user input is preserved.
- Destructive and consequential confirmations name the action, target, effect, and whether it is reversible.
- Status copy uses plain language first and canonical/system state as supporting detail where operators need it.
- Dates and times include time zone when ambiguity matters. Money includes currency; measurements include unit.

## Robust semantics

- Use semantic HTML before ARIA. Custom controls implement the relevant ARIA Authoring Practices pattern completely.
- Every page has one descriptive `h1`; headings do not skip levels for appearance.
- Form controls have programmatic name, description, error, required, and disabled state.
- Dynamic status updates use restrained live regions. Routine polling does not repeatedly interrupt screen readers.
- Tables use headers, captions or accessible names, and announced sorting. Responsive card views preserve header/value relationships.
- ULIDs and protocol identifiers may be visually grouped but retain a copyable, unmodified accessible value.

## Component requirements

| Component | Accessibility behavior |
| --- | --- |
| Button | Native button/link semantics; visible focus; disabled and busy announced; icon-only variant has an accessible name |
| Form field | Persistent label; description and error IDs; error summary integration |
| Status chip | Text label always present; decorative dot/icon hidden from assistive technology |
| Skeleton | Hidden from assistive technology; container exposes a single loading status |
| Empty/error state | Heading, explanation, and explicit next action; no decorative icon announcement |
| Dialog | Labeled, described, initial safe focus, focus containment, Escape behavior, opener restoration |
| Toast | `status` for non-urgent updates, `alert` only for urgent failures; dismissible and not the only record of an outcome |
| Responsive navigation | Same destinations and labels at every breakpoint; current destination programmatically identified |
| Bottom navigation | Labeled destinations, selected state, minimum touch targets, safe-area padding |

## Motion, flashing, and sensory load

- Respect reduced-motion preferences. Replace shimmer, marker pulse, and large transitions with static feedback.
- No content flashes more than three times per second.
- Skeleton animation is subtle, stops when content loads, and is never required to understand progress.
- Auto-refresh does not move focus, reorder focused rows, or reset scroll without user control.

## Maps and charging status

- A “View as list” control is always available and uses the same filters and selection.
- Marker state has label, shape/icon, and color. Clusters expose count and summarized availability.
- The selected marker is announced and connected to its result card.
- Live values expose last-updated time. Offline and stale states are announced once when they materially change.

## Testing matrix

Each increment includes automated semantic/widget checks and manual testing for:

- Keyboard-only navigation at 100% and 200% zoom.
- NVDA with Firefox or Chrome on Windows; VoiceOver with Safari on Apple platforms when available.
- TalkBack and VoiceOver for Flutter releases.
- Light, dark, forced-colors/high-contrast where supported, reduced motion, and large text.
- Color-vision simulation for status palettes while verifying labels remain sufficient.
- Error, loading, empty, offline, stale, and permission-denied states.

## Open decisions

A formal VPAT/ACR, supported assistive-technology matrix, localization languages, right-to-left support schedule, transcription/caption workflow, and third-party accessibility review cadence require product and compliance approval.
