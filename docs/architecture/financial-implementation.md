# Payments, Billing, Ledger, and Reconciliation Implementation

**Status:** Phase 8 implemented baseline  
**Normative inputs:** ADR-0006, `payment-state-machine.md`, `data-ownership.md`, and `security-model.md`

## 1. Context boundaries

- **Payments** owns provider configuration references, token references, payment intents, provider attempts, refunds, disputes, webhook evidence, polling, and provider capability adapters.
- **Billing** owns billing profiles, immutable rated-charge evidence, invoices, invoice lines, receipts, credit notes, allocations, revenue-share rules, and the double-entry ledger.
- **Settlements** owns provider-report reconciliation runs and lines, beneficiary settlement batches/items, manual adjustments, maker-checker approval evidence, and settlement status.
- Billing reads finalized CDR facts through `FinalizedChargeDetailRecordQuery`. Billing and Settlements read or command Payments only through Payments-owned application contracts. They do not import Payments Eloquent models.
- Cross-context identifiers are ULIDs without cross-context foreign keys. Each context validates referenced facts through its owner contract.

## 2. Canonical financial representation

- Every amount is a non-negative integer minor-unit value paired with an uppercase ISO 4217 currency code. Signed integers are used only for explicit differences and settlement adjustments.
- Provider attempts, processed webhook evidence, rated charges, issued document content, receipts, credit notes, ledger transactions/entries, reconciliation lines, settlement items/adjustments, and exports are append-only.
- Provider state never substitutes for ledger state. A captured payment is allocated by Billing and posted as a separate balanced ledger transaction.
- Internal document references such as `INV-{ULID}` are tracking references, not legally approved invoice numbers. Legal invoice and credit-note numbers remain null until jurisdictional rules are approved.

## 3. Provider abstraction

The Payments application defines capability-specific ports for hosted checkout, token attachment, authorization, incremental authorization, capture, void, refund, webhook verification, status retrieval, reconciliation export, and settlement. `PaymentProvider` composes these ports; its capability map allows orchestration to reject unsupported flows before launch configuration.

### Fake provider

The database-backed fake provider is the local and automated-test default. It persists provider-side state independently from VTSA payment intents and supports deterministic safe token references:

| Token reference | Behavior |
| --- | --- |
| `fake_success` | Authorization, capture, refund, polling, reconciliation, and settlement succeed. |
| `fake_fail_authorization` | Authorization is declined. |
| `fake_requires_action` | Authorization requires an external customer action. |
| `fake_timeout_after_authorization` | Authorization succeeds provider-side but returns an unknown transport outcome. |
| `fake_fail_capture` | Authorization succeeds and capture fails without consuming the hold. |
| `fake_timeout_after_capture` | Capture succeeds provider-side but the caller receives an unknown outcome; polling converges it. |

These are test fixtures, not charger- or bank-specific production behavior.

### Stripe sandbox adapter

`stripe_sandbox` uses Stripe test-mode HTTP endpoints and rejects any key that does not begin with `sk_test_`. It sends idempotency headers, requests manual capture, verifies the raw webhook body with a timestamped HMAC replay window, and maps provider statuses to the provider-neutral state machine. Reconciliation export and settlement submission are intentionally unavailable until separately approved adapters are designed. No Stripe SDK types escape the adapter.

## 4. Collection workflow

1. Create a unique tenant-scoped payment intent for a billable ULID and integer amount/currency.
2. Store only an encrypted provider token reference plus safe display metadata; PAN and CVV are rejected by design because no fields exist for them.
3. Authorize before charging when the applicable product policy requires it. A provider acceptance is not a capture.
4. A finalized immutable CDR is read through the Charging contract. Billing issues its immutable rated charge and customer invoice.
5. `ChargingPaymentCollector` incrementally authorizes if supported and required, then captures the final amount. Network calls occur outside database transactions.
6. Billing allocates confirmed captured funds to the invoice, issues a receipt, and posts provider clearing debit / accounts-receivable credit.
7. Refunds are idempotent, may be partial, are audited, and emit provider facts. Credit-note issuance remains a separate Billing decision.

A timeout becomes `outcome_unknown`, creates a review item, and requires status retrieval. A retry is not made until retrieval establishes the provider result.

## 5. Webhooks and polling

- The HTTP destination binds both tenant and frozen provider-configuration ULIDs, verifies the provider signature over the unmodified body, enforces the replay window, and applies an IP rate limit.
- Provider event IDs are unique per tenant/configuration. Duplicate events return an accepted duplicate outcome without changing state.
- Events apply monotonic financial facts. A delayed cancellation cannot reverse a captured payment. A delayed success may advance an intent that was left pending after an app timeout.
- Only normalized, allow-listed status/amount/currency facts plus a SHA-256 body hash are retained. The raw provider body and signature are not persisted.
- Polling uses the same state convergence rules and an independent idempotency key.

## 6. Ledger and revenue allocation

`DoubleEntryLedger` refuses a posting unless it has at least two entries, every entry has exactly one positive side, all accounts use the transaction currency, and total debits equal total credits. PostgreSQL check constraints reinforce entry shape. Posted transactions and entries cannot be updated or deleted through Eloquent.

| Fact | Debit | Credit |
| --- | --- | --- |
| Invoice issued | Accounts receivable | Gross charging revenue |
| Captured payment allocated | Provider clearing | Accounts receivable |
| Revenue share allocated | Gross charging revenue | Platform fee revenue, operator payable, and site-host payable |
| Credit note issued | Sales returns/allowances | Accounts receivable |

Revenue-share percentages are effective-dated integer basis points and must total 10,000. No default percentage is seeded. Minor-unit rounding gives the final residual to the site-host share so postings always balance; the rule must identify a site host when that share is non-zero.

## 7. Reconciliation and settlement

- Reconciliation imports normalized provider report records through the provider port, compares provider net against captured-minus-refunded platform facts, and creates immutable matched/mismatch lines. Mismatches remain in a Settlements-owned review queue; source payment and invoice facts are never edited to force a match.
- Settlement preparation accepts explicit beneficiary/source evidence, gross, fee, adjustment, and net values. PostgreSQL enforces the net equation.
- The preparer cannot approve the same batch. Adjustments are append-only with actor and reason. Submission is available only for a provider that explicitly supports it; the Stripe sandbox adapter does not.
- No beneficiary bank account, production payout credential, or production execution policy is modeled in this phase.

## 8. Accounting and electronic invoicing

- `AccountingExportAdapter` exposes balanced ledger transactions to a configured accounting integration. The local fake adapter records count and a content hash without performing an external write.
- `BirElectronicInvoicingAdapter` is an explicit interface. The default adapter returns `not_configured` and explains that legal, registration, and endpoint configuration is required.
- Customer invoices, receipts, and credit notes remain flagged `legal_review_required` until launch-market rules, BIR registration details, numbering, and approved transmission behavior are supplied.

## 9. Assumptions and open decisions

- Preauthorization amount and when it is required are product/operator policy inputs; this phase does not invent a default hold amount.
- Provider fees may arrive in reconciliation after capture and are not guessed during authorization.
- Disputes have a durable placeholder model but evidence submission and representment workflows remain open.
- Legal invoice/receipt classification, tax registration facts, VAT handling outside the immutable tariff snapshot, withholding, and BIR endpoint details require legal review.
- Settlement beneficiary banking, payout rails, approval thresholds, reserve policy, negative settlement handling, and production provider selection remain open.
- Stripe reconciliation and settlement need separately approved reporting/payout adapters before production use.
