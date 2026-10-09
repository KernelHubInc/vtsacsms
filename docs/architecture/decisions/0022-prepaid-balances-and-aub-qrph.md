# ADR-0022: Prepaid balances and AUB QR Ph collections

- Status: Accepted for simulated development; live acceptance blocked pending AUB clarification
- Date: 2026-10-08
- Owners: Payments, Billing, Identity, mobile
- Source: Merchant-Presented Payment API Specification 1.4.3, interface 2.0

## Decision

Payments owns `qr_topups`, the AUB XML adapter, signed confirmations and provider references. Billing owns prepaid accounts, immutable activity, reservations and ledger postings. `QrTopups` calls Billing's `PrepaidWallet` application contract inside a local transaction; no cross-module model writes. This is a capability-specific collection flow alongside ADR-0006's authorization/capture flow. A QR payment is not a card authorization. No fabricated authorize, capture, close or refund calls are used.

REQ-WALLET-001: All amounts are integer PHP centavos. Accounts are tenant + subject + book + currency scoped. Simulated and live books cannot mix. Simulated activity never posts to the financial ledger or accounting export. Real confirmed top-ups debit provider clearing and credit customer prepaid liability. Balance reservations serialize on the account row; spending cannot exceed the hold. Final spending debits prepaid liability and credits accounts receivable for a corresponding rated charge.

REQ-WALLET-002: The client generates a stable ULID for request retries. A persisted order precedes the provider call. Timeouts remain unknown; retries retrieve the original order without resubmission. A crash after persistence requires reconciliation rather than a second create. Provider calls occur outside transactions. Confirmation matches merchant, channel, order, currency and exact amount; account-scoped row locks plus database uniqueness prevent duplicate money movement. A transaction reference cannot credit two orders in the same tenant/book.

REQ-WALLET-003: Verify SHA256 using the PDF's sorted decoded fields, excluding `sign` and empty strings, preserving zero and extra fields, with the server-held key appended. This is not an HMAC or a raw XML hash. Parse the original body with bounded size, no DTD/entities, no nested or duplicate fields. Persist the confirmation body hash, not its raw personal/payment payload. RSA remains unsupported pending validated AUB examples. Repeat notifications return plain `success` only after the local transaction commits. Timestamp freshness alone cannot reject legitimate delayed notifications: replay protection rests on immutable order bindings and deduplication.

REQ-WALLET-004: Local QR expiry hides the QR but does not erase an order or reject late payment evidence. No client payment confirmation endpoint exists. A signed successful create only supplies a QR. The QR Ph inquiry schema does not unambiguously specify pending versus paid; queries therefore cannot credit a wallet in this version. Expired/ambiguous retrieved orders enter review. Accepted signed payment notifications can subsequently resolve review/unknown states.

REQ-WALLET-005: Live collection requires the real-payment flag, approved AUB contract flag, configured merchant tenant and server-only credentials. Defaults disable collection. Simulation requires its separate flag and local/testing/staging/demo environment. The public callback is bound to the configured tenant and remains available for old orders when new top-ups are disabled. Identity's KYC eligibility contract governs creating top-ups; already-paid funds must still be accounted for if eligibility changes afterward.

## Initial mobile scope

Balance, available/reserved amounts, cursor-paginated activity and top-ups, exact amount entry, recoverable idempotent retry, local QR rendering, expiry and status polling. Simulations have no payable QR. Do not expose secrets, provider response bodies or inferred payment success. Hosted GCash/GrabPay, cards, cash-out, transfers and automatic refunds are not implemented.

Reservation/finalization is an internal Billing contract, tested but not connected to physical remote charging yet. Integration must use final Billing/Charging facts, verify KYC and never route simulated balances into live sessions. Charging availability and collection policy remain gated separately.

## Follow-up decisions before live acceptance

1. AUB approval for prepaid charging top-ups, merchant/account/channel activation and current endpoint/version.
2. Valid signing vectors and actual QR Ph unpaid/pending/expired/success responses, including invoiceId/invoice_id mapping and create-timeout recovery without invoice ID.
3. Maximum balance/top-up policy, merchant PHP currency, query limits, expiry/late-payment behavior and duplicate-order behavior.
4. Refund/reversal process: page 38 explicitly denies QR Ph refund, refund-query and close capabilities. Generic endpoints do not override this matrix.
5. Settlement report ingestion, fees, reconciliation ownership and incident handling.
6. Retention/erasure policy for durable financial references and subject links. No new names, email addresses, bank details or card fields are collected here. Posted records cannot be edited/deleted through the application; production data retention must be approved before enabling collection.

## Alternatives and consequences

A mutable user balance without ledger evidence and a client-driven "paid" button were rejected because they permit untraceable or forged credits. Reusing card preauthorization for QR Ph was rejected because the provider capabilities differ. The capability-specific flow adds tables and operational review but preserves ownership and evidence. Application-level PostgreSQL locking must receive concurrency validation on PostgreSQL before release; SQLite tests alone do not prove lock scheduling.

## Validation

`PrepaidWalletTest`, `AubQrProtocolTest` and mobile `wallet_test.dart` cover the requirements above with local fixtures and HTTP fakes. No automated tests use production credentials or live AUB calls.
