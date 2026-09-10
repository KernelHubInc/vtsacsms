# Procurement State Machines

**Status:** Phase 12 implemented baseline  
**Owner:** Procurement bounded context

## Invariants

- Requisitions, quotations, purchase orders, and vendor invoices use integer minor units plus an ISO 4217 currency.
- An approval decision is retained against the document revision, sequence, role key, actor, reason, and UTC decision time.
- The requesting or preparing actor cannot approve their own requisition or purchase order.
- Approval proceeds in configured sequence and the acting user must hold the exact approval-matrix role in the active tenant.
- Issued purchase orders publish expected-receipt facts; they never post inventory.
- Inventory owns physical receipt, inspection, quarantine, and stock movement. It updates Procurement only through `PurchaseOrderReceiptContract`.
- Three-way-match executions are immutable evidence. A repeat match creates new evidence and supersedes open discrepancies without rewriting the prior match.
- Supplier bank details, payment instructions, tax-registration rules, and production accounting credentials are not modeled.

## Purchase request

```mermaid
stateDiagram-v2
    [*] --> DRAFT
    DRAFT --> UNDER_APPROVAL: submit and build matrix
    REVISION_REQUESTED --> UNDER_APPROVAL: resubmit revision
    REJECTED --> UNDER_APPROVAL: resubmit revision
    UNDER_APPROVAL --> UNDER_APPROVAL: sequential approval remains
    UNDER_APPROVAL --> APPROVED: final approval
    UNDER_APPROVAL --> REJECTED: role-bound rejection
    UNDER_APPROVAL --> REVISION_REQUESTED: return with reason
    APPROVED --> SOURCING: issue RFQ
    SOURCING --> ORDERED: approved comparison creates PO
    ORDERED --> CLOSED: procurement completion
    DRAFT --> CANCELED
    APPROVED --> CANCELED
    CLOSED --> [*]
    CANCELED --> [*]
```

Revision requests increment `revision`; approval evidence from earlier revisions remains retained. A request cannot be submitted unless an active approval rule covers its document type, currency, amount, department, and cost center.

## Request for quotation and comparison

```mermaid
stateDiagram-v2
    [*] --> RFQ_DRAFT
    RFQ_DRAFT --> RFQ_ISSUED: invite active suppliers
    RFQ_ISSUED --> RFQ_ISSUED: record supplier quotation
    RFQ_ISSUED --> COMPARISON_PENDING: freeze comparison snapshot
    COMPARISON_PENDING --> COMPARISON_APPROVED: procurement approval
    COMPARISON_APPROVED --> PO_DRAFT: create selected PO
    RFQ_ISSUED --> RFQ_CLOSED: close without award
    PO_DRAFT --> [*]
    RFQ_CLOSED --> [*]
```

The comparison snapshot retains every considered quotation's supplier reference, currency, total minor units, lead time, validity date, and line prices. Selection is explicit and reasoned; lowest price is not assumed to be the winner.

## Purchase order

```mermaid
stateDiagram-v2
    [*] --> DRAFT
    DRAFT --> SUBMITTED: build approval matrix
    SUBMITTED --> SUBMITTED: sequential approval remains
    SUBMITTED --> APPROVED: final approval
    APPROVED --> ISSUED: publish commitment
    ISSUED --> PARTIALLY_RECEIVED: accepted quantity below ordered
    PARTIALLY_RECEIVED --> PARTIALLY_RECEIVED: replacement or partial delivery
    ISSUED --> RECEIVED: all ordered quantity accepted
    PARTIALLY_RECEIVED --> RECEIVED: all ordered quantity accepted
    RECEIVED --> CLOSED: procurement close
    DRAFT --> CANCELED
    APPROVED --> CANCELED
    ISSUED --> CANCELED: controlled outstanding cancellation
    CLOSED --> [*]
    CANCELED --> [*]
```

`received_quantity_base` is cumulative physical delivery and may exceed ordered quantity when rejected goods are replaced. Completion is determined by accepted quantity, not raw delivery quantity.

## Vendor invoice and three-way matching

```mermaid
stateDiagram-v2
    [*] --> SUBMITTED
    SUBMITTED --> MATCHED: PO price and accepted quantity agree
    SUBMITTED --> DISCREPANCY: price, quantity, amount, currency, or line differs
    DISCREPANCY --> MATCHED: corrected document creates new match evidence
    DISCREPANCY --> REJECTED: finance rejects
    MATCHED --> APPROVED: authorized approval
    APPROVED --> EXPORTED: configured accounting adapter accepts export
    REJECTED --> [*]
    EXPORTED --> [*]
```

The default match tolerance is zero minor units. A caller may pass an explicit non-negative tolerance; it is stored in the evidence snapshot. Launch-market tax and landed-cost matching rules remain unresolved.

## Accounting-export state

```text
not_ready -> ready | blocked
blocked -> ready        (after a successful new match)
ready -> exported       (approved adapter export)
```

The local adapter returns deterministic synthetic references only. Production provider credentials and destination configuration remain outside source control.

## Failure behavior

- Duplicate document numbers and idempotency keys are tenant-aware database conflicts.
- Invalid state transitions fail atomically and produce no stock or financial side effect.
- Approval-role mismatch, inactive membership, expiry, or self-approval is denied in the application workflow even if a UI action is forged.
- Notification dispatch occurs after transaction commit.
- Cross-context receipt and supplier-return changes use narrow Procurement-owned contracts; Inventory never writes Procurement tables.

## Open decisions

- Material PO revision thresholds and whether an issued PO amendment retains one number or a new document number.
- Required RFQ supplier count and exception approval.
- Quotation scoring dimensions beyond price and lead time.
- Tax, freight, landed-cost, and partial-invoice allocation rules by launch market.
- Three-way-match tolerances by item/category/supplier and who may override them.
- Accounting adapter payload, retry, reconciliation, and external acknowledgement semantics.
- Supplier onboarding, compliance, performance scoring, and approved-data retention.
