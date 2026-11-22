# ADR 0039: Settle confirmed stops after suspended metering

Status: accepted for staging validation.
Date: 2026-11-22. Owners: Charging, Billing and Notifications.

## Context

Lumina staging transaction 10 recorded regular aggregate Wh/W samples, then `SuspendedEV` five seconds after its last power sample. The charger stopped periodic samples while suspended and confirmed StopTransaction 67 seconds later. Time pricing required review because the final sample-to-stop interval exceeded 60 seconds, despite the recorded suspension. The existing physical-stop recovery deliberately did not settle billing reviews (ADR 0038).

## Decision

Keep ADR 0026's conservative positive-power sampling and immutable session tariff. A stopped session's trailing metering gap is covered by a processed suspension status on the same tenant, station and connector when that status follows the last power sample within the snapshot's maximum sampling gap, or a later zero-power sample confirms the suspension. The last connector status must remain SuspendedEV or SuspendedEVSE; conflicting simultaneous statuses, clock-skew flags, intervening disconnect/reconnect evidence, resumed charging, long unobserved active gaps and insufficient preceding samples remain incomplete. No suspended or unsampled seconds are added to charged time. Interior telemetry gaps retain existing review behavior.

The prepaid monitor may re-evaluate an existing stopped time-pricing review only when its latest CDR review was caused solely by missing energy-flow evidence, the session has start/stop meters and valid start/end times, no session anomalies, and no human review record. It runs normal CDR generation with no review override. Meter, identity, tariff, budget and wallet gates remain enforced. Successful revalidation records an audited evidence-revalidation transition and updates the stored flow evidence. Finalized CDRs are never rewritten by this retry path.

Billing's existing settlement contract performs one idempotent debit and releases unused held funds. Notifications records the existing one-per-session charging receipt with PDF attachment after capture; its existing delivery worker submits it and retries transport failures. Provider acceptance is distinct from arrival in an inbox. This charging receipt is not presented as an official tax receipt.

## Completion versus suspension

SuspendedEV alone is not proof of 100% battery state or transaction completion. This change does not close a transaction on suspension, infer SoC, change charger settings, introduce a new stop timer or weaken the existing prepaid stop controls. Trusted StopTransaction/TransactionEvent Ended evidence is still required. Lumina's observed Wh/W messages did not include SoC. Its existing stale-meter spending guard requested and received the stop for transaction 10.

## Validation and operations

Regression tests cover the Lumina-style tail, EV/EVSE suspension, resumption, unobserved active gaps, different connectors/tenants, quarantined events, one-time debit/release/receipt generation, recovery of an existing automatic review, manual reviews, invalid meters and missing stop evidence. Existing Charging, Billing and Notifications tests remain required.

Deploy to staging first. Reconcile the original stopped session through the normal monitor and verify the immutable tariff, CDR, wallet entries and receipt delivery status. No schema migration, tariff edit or mobile build is required. Promote the same tested backend correction to production after staging verification. Preserve runtime flags, gateway sessions and existing public indexing configuration. Roll back the application image if necessary; never reverse settled ledger records or delete issued evidence as a code rollback.

## Limits

Missing or conflicting evidence still requires review. This does not promise that every charger reports battery percentage or that every suspended transaction is full. Offline automatic stopping and certified continuous energy-flow timing remain separate capabilities.
