# Local Payments and Reconciliation

The fake provider is the default local adapter and needs no external account. Do not put real payment credentials or personal card data in local configuration, fixtures, logs, or screenshots.

## Configure

1. Keep `PAYMENT_PROVIDER=fake`.
2. Set a local-only `FAKE_PAYMENT_WEBHOOK_SECRET` in `apps/platform/.env`; do not commit its value.
3. Run migrations and seeders with `php artisan migrate --seed` from `apps/platform`.

The fake provider accepts only token references such as `fake_success` and the scenarios documented in `docs/architecture/financial-implementation.md`. It has no PAN or CVV input.

## Verify

From `apps/platform`:

```powershell
vendor/bin/phpunit --do-not-cache-result tests/Feature/Finance/PaymentsBillingAndReconciliationTest.php
vendor\bin\phpstan analyse --memory-limit=1G
vendor\bin\pint --test
```

Validate the versioned API contract from the repository root:

```powershell
npm.cmd --prefix packages\contracts run lint
```

## Stripe sandbox opt-in

Set `PAYMENT_PROVIDER=stripe_sandbox`, `STRIPE_TEST_SECRET_KEY`, and `STRIPE_TEST_WEBHOOK_SECRET` through local environment injection. The adapter rejects live-mode keys. Configure the provider dashboard webhook destination with the tenant and provider-configuration ULIDs shown by the local administrative data; never treat those IDs as the signature secret.

Stripe reconciliation export and automated settlement are intentionally unsupported. Use the fake provider to exercise those local workflows.

## Recovery rules

- For `outcome_unknown`, retrieve status before retrying authorization, capture, void, or refund.
- Review open `finance_reviews` and reconciliation mismatch lines in the operator panel.
- Never edit payment attempts, webhook evidence, issued document content, ledger entries, or reconciliation lines to clear a queue. Resolve through a new compensating workflow and retain the original evidence.
