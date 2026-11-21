# ADR 0038: Confirmed charging stops and pending billing review

Status: implemented for staging validation.
Date: 2026-11-21. Owner: Charging.

## Context

A short time-priced session can end before the charger sends two aggregate power samples. ADR 0026 correctly requires billing review rather than inventing energy-flow time. The customer active-session lookup and connector start guard also treated that physically stopped review as an active charging transaction. Mobile recovery then trapped the driver on the charging screen.

## Decision

A `review_required` session with non-null authoritative `stopped_at` no longer blocks the customer charging flow or a new connector start. A current Available/Preparing connector, all existing permissions, KYC, quote checks, command idempotency and sufficient unreserved wallet balance are still required. Reviews without stop evidence remain blocking. Finalizing sessions, including managed idle-fee windows, retain their existing behavior.

Canonical session/CDR review state, transaction evidence and financial reservations remain unchanged. There is no automatic waiver, estimated charge, use of elapsed time as energy-flow time, or release of the old hold. Billing continues to exclude that hold from available funds; a new session cannot spend it. An audited review decision or new sufficient evidence is still needed to finalize the old bill.

Mobile distinguishes the confirmed stop from billing completion, presents a native app popup once when the stopped review appears, provides Home and Activity actions, and keeps the review in history with a pending final amount. It clears the old active recovery reference and subscription. On restore, a saved stopped review cannot hide a newer live session. A delayed refresh for an old session cannot overwrite a newer session.

## Compatibility and operations

The existing API fields and financial state machine are unchanged. The customer `active` endpoint now omits only physically stopped billing reviews; owner-scoped detail and history continue to return them. Older clients can retain a local old reference, so distribute the updated staging APK after the compatible API release. No migration or financial backfill is required. Existing stopped reviews benefit immediately from the backend guard correction without editing their records.

Deploy and validate on staging only. Verify short sessions, missing-stop evidence, retained holds, remaining balance, tenant/subject isolation, recovery, popup dismissal and history. Do not use a simulated connection with a physical charger identity or initiate customer charging as a deployment smoke test. Roll back the application image and APK alias together if necessary; preserve all session/CDR/ledger evidence.

## Limits

This prevents the driver flow from being locked by a stopped billing review. It does not manufacture missing charging time or guarantee every session can be billed automatically. Configure and physically validate frequent Wh/W readings before commercial time pricing. Available funds can still be insufficient while a prior reservation awaits review.
