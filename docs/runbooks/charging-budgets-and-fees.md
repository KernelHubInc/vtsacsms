# Charging budgets and platform fees

Open **Admin > Platform governance > System settings**. Viewing requires `TenantSettingsView`; saving requires `TenantSettingsManage`. Changes are tenant-wide across Personal, Business and Fleet wallets and are audited. Operator roles without these permissions cannot change them.

## Charging reservation settings

1. Set minimum, default and maximum charging budgets in PHP.
2. Add or remove budget buttons. Amounts must be unique and within the limits. Enable Other amount to allow custom entry.
3. Save. New quotes use the new policy. Existing sessions retain their budget; an unused quote must be refreshed if policy changes.

Proposed launch settings: minimum PHP 100, default PHP 500, maximum PHP 2,000; buttons PHP 200/500/1,000/2,000 plus Other amount. These are operating choices, not market or regulatory requirements. Validate a suitable minimum against each charger's power, tariff and stopping latency before introducing high-power DC equipment. The maximum is editable; no wallet can reserve more than its available funds or applicable driver/vehicle cap.

Customers scan/select a connector, review **Charging budget**, select **Change budget**, choose an amount, and review the refreshed quote before Request start. This reserves funds; it does not add wallet credit or take an extra payment. Final usage is settled once and unused funds released. The remote stop monitor remains a request to the charger, not a guaranteed physical cutoff.

## Platform fee

Deployment default: **Disabled**, PHP 5 amount, waived below PHP 100 charging total. No customer fee is collected until an administrator explicitly enables a confirmed policy.

1. Confirm the beneficiary and tax treatment with accounting. Enter the platform beneficiary's name and confirmed included tax percentage; leave them empty while undecided.
2. Set the gross fee and waiver threshold. The amount includes configured tax. The threshold compares charging cost including charging tax, before this fee. The fee applies exactly at the threshold; zero disables the waiver.
3. Optionally choose an effective date/time in the Philippines. Blank applies immediately. Scheduled policies are listed below the form; a replacement with the same effective time supersedes the earlier entry. Disabling immediately does not silently cancel a future scheduled policy—also supersede that scheduled date if necessary.
4. Publish. Enabled policies require a beneficiary and tax rate. All policies remain in history. Changes apply to new quotes only.

An enabled fee is shown before starting, included inside the reservation, and itemized after settlement in Activity, the email and attached PDF. It applies once to completed charging with positive metered energy or energy-flow time. Failed starts and top-ups have no platform fee. Customers using an older app that cannot display the fee must update before starting; active sessions remain controllable.

Example: PHP 500 reserved; PHP 320 charging + PHP 5 fee = PHP 325 deducted, PHP 175 released. This assumes fee enabled and threshold met. With the deployment's disabled fee, only PHP 320 is deducted.

## Reports and financial handling

Session and vehicle exports include final charging total and platform fee in integer centavos; existing final amount includes both. PDF session reports display the split. A zero historical fee is inferred only for a finalized legacy session; incomplete costs remain unknown. These amounts describe rated sessions: filter payment state/settlement when reporting collected money. Fee beneficiary, policy ID and included tax remain in the immutable CDR snapshot. Existing invoice revenue-share calculations assign the explicit fee to the platform before splitting charging revenue.

No automatic refund, tax remittance or payout is introduced. Use reviewed financial workflows and confirm refund terms before enabling the fee commercially.

## Release checks

Apply `2026_11_01_000002_create_charging_commercial_settings` after a database backup. It adds two policy tables and nullable final fee projections to charging sessions. No old financial rows are rewritten. Release matching backend/worker code before the updated browser bundle and APK. Save budgets independently per environment and verify the platform fee is disabled. Do not enable fees during a mixed-version backend rollout.

Tests cover tenant isolation, permissions, amount validation, scheduled policies, stale quotes, inclusive fee/waiver boundaries, older clients, frozen session pricing, settlement retries and itemized receipts. Physical stop latency still requires a supervised charger acceptance test.
