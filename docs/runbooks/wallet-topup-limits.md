# Wallet top-up settings

Payments owns the tenant-wide top-up policy used by Personal, Business and Fleet wallets. It is separate from charging tariffs, session spending caps and wallet balances.

## Configure in the admin portal

1. Open **Platform governance > System settings > Wallet top-up settings**. Viewing requires `TenantSettingsView`; saving requires `TenantSettingsManage`.
2. Set the minimum and maximum in PHP, with up to two decimal places. The minimum must be positive and no greater than the maximum. The maximum cannot exceed PHP 1,000,000.00.
3. Select **Add amount** for each preset, for example 200, 500, 1000 and 2000, within your configured limits. There is no fixed product limit on the number of presets.
4. Use **Up** and **Down** to set display order, **Enabled** to hide or show an amount, and **Remove** to delete a row. All rows, including disabled rows, must be unique and within the minimum and maximum. Adjust affected rows when changing the limits.
5. Enable **Other amount** to allow a custom amount within the limits. If disabled, at least one preset must be enabled.
6. Select **Save top-up settings**. The entire policy is saved atomically and the before/after values are recorded under `payments.wallet_topup_limits.updated`. Unsaved edits do not affect customers.

## Customer experience

Open **Wallet > Add money**, choose a preset or **Other amount**, review the amount on **Continue**, then follow the existing QR payment flow. The first six presets appear initially; **More amounts** reveals the rest. No amount is selected automatically. An empty preset list with Other amount enabled provides custom entry only.

Android and the browser app share this Flutter screen and backend policy. Settings refresh when Wallet loads or Add money opens. The updated client must first be released; subsequent admin changes need no new app build. Older clients retain their custom input, but the backend still enforces any preset-only policy.

## API and payment behavior

`GET /api/v1/wallet` adds `preset_amounts_minor` (ordered, enabled integer centavo amounts) and `allow_custom_amount`. Existing minimum and maximum fields remain unchanged. New clients accept older servers without these fields by using custom entry.

The server validates new requests before creating a payment order. Out-of-range or unlisted amounts in preset-only mode return `INVALID_TOPUP_AMOUNT`. The dialog refreshes the policy after this rejection so the customer can choose again.

Existing pending orders keep their amount, expiry and payment flow. Idempotent retries resolve existing orders before checking the new policy. An interrupted request keeps the same amount and idempotency key; a definitive pre-persistence amount rejection allows a new selection and key. Saving policy never credits or debits a wallet.

## Migration and rollout

`2026_11_01_000001_add_wallet_topup_presets` adds nullable JSONB `presets` and boolean `allow_custom_amount` (default true) to `wallet_topup_settings`. It does not rewrite balances, payments or history. Until presets are explicitly saved, defaults are PHP 200, 500, 1000 and 2000, filtered to the existing limits. An explicitly saved empty list remains empty. Existing minimum and maximum settings are preserved.

Back up the database, apply the additive migration, deploy the backend, verify the wallet API and admin page, then release the browser bundle and APK. Staging and production are configured independently. Retain the added columns on rollback. An older backend does not enforce preset-only selection, so do not roll back to it while relying on that restriction.

Normal server request-size and resource limits still apply; there is no arbitrary preset-count cap. The list is tenant-scoped and protected by the existing settings permissions and audit trail.

## Verification

`TopupLimitsTest` covers validation, ordering, enabled states, 120 presets, tenant isolation, authorization, audit and idempotent retries. `PrepaidWalletTest` checks the API response. Flutter `wallet_test.dart` covers selection, expansion, custom validation, refreshed policy and interrupted-request recovery. Tests use synthetic payment data and do not send real payments.
