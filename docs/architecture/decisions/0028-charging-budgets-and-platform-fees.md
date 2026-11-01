# ADR 0028: Customer charging budgets and disclosed platform fees

Status: Accepted; platform fee activation pending accounting.

Date: 2026-11-01. Owners: Charging, Tariffs, Billing, Notifications and Reporting.

Charging owns tenant-wide `charging_budget_settings`, validated customer budgets and quote/start enforcement. Settings are distinct from Payments' top-up policy. The selected reservation cannot exceed available wallet funds or Billing's driver/vehicle cap. Preset lists have no product count limit; the client initially displays six. Custom amounts are optional. A missing policy preserves the deployment cap and legacy one-cent minimum; deployment explicitly saves the approved commercial settings. The default budget is clamped to available funds, or the largest eligible preset when custom amounts are disabled. Below-minimum budgets cannot start.

Tariffs owns append-only `platform_fee_policies` and deterministic inclusive-fee calculations. A policy has an effective UTC instant, gross PHP fee, waiver threshold, included tax rate and platform beneficiary display name. Active policy selection is latest effective instant then ULID. No tax treatment or beneficiary is inferred. Enabling requires both. The fee remains disabled in staging and production pending accounting confirmation, per the release owner's instruction.

The waiver compares the charging total including its own tax but excluding the platform fee. Below threshold: waived. At or above threshold: one gross fee. No metered energy or energy-flow time: no fee. Failed/cancelled starts do not settle a fee. A zero threshold removes the waiver. The customer-selected budget includes the fee; it is never added above the wallet hold.

Preparation freezes the fee and budget policy. Start checks price/policy changes and rechecks wallet/account restrictions. Before after-commit command dispatch, Charging copies the approved quote's tariff snapshot and hash to its session. Meter estimates, the existing 90-second stop forecast and final CDR all use TariffEngine with that same snapshot. Later settings changes never reprice active or historical sessions. Finalized CDRs retain the fee/tax/beneficiary breakdown; Charging also projects final fee amounts on the session for reports. Legacy snapshots contain no fee and remain valid. Clients declare `pricing_version: 2` before preparing with an enabled fee; older clients must update rather than accept an undisclosed charge.

Billing reserves and settles through its existing public wallet contract. Over-budget or incomplete final evidence retains the hold for review; no automatic overdraft or invented completion. Invoice revenue allocation treats the explicit platform fee as platform revenue and applies the existing operator/site-host percentages only to the remaining charging amount. This is gross allocation evidence, not tax remittance or an automatic beneficiary payout. Notifications receives the itemized settled summary through its existing contract; Reporting reads tenant/site-authorized session projections. No source context is written through a reporting query.

Refund execution remains the existing reviewed financial workflow. Policy publication does not refund history or initiate a payout. Keep the fee disabled until accounting confirms tax, beneficiary and commercial refund terms. Existing tax-invoice legal-review gates remain in place.

Migration is additive. Back up before deployment, migrate once, then update web and workers on the same release before enabling a fee. Production workers/scheduler remain on App01 only. Do not roll back to a fee-unaware tariff engine while fee-bearing sessions are active: preserve Stop/read/settlement services and roll forward. Keep policy tables and session columns on rollback.
