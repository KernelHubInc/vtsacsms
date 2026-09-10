# ADR-0012: Use an Immutable Stock Movement Ledger

**Status:** Accepted  
**Date:** 2026-07-26  
**Contexts:** Inventory, Procurement, Maintenance, Assets, Reporting

## Context

Inventory quantities are changed by receipts, quarantine, issues, work-order consumption, unused-parts returns, transfers, supplier returns, counts, and manual adjustments. Storing a mutable quantity-on-hand field as the primary fact would allow a retry, import, administrator, or race to change stock without durable evidence.

The architecture also requires partial delivery, explicit in-transit custody, serialized and lot tracking, moving-average or configured valuation, tenant isolation, correction evidence, and reliable reporting.

## Decision

Inventory records every physical quantity change as an immutable `inventory_stock_movements` row:

- tenant, item, base unit, positive integer base quantity;
- source and/or destination bin;
- lot or serial where required;
- movement type, reason, typed source reference, actor, correlation through audit/outbox, and UTC occurrence/posting times;
- integer unit and total cost minor units with currency; and
- a tenant-scoped idempotency key plus optional reversal reference.

Quantity on hand is derived as inbound quantity minus outbound quantity. Available stock is the available-custody subset minus active reservation balance. Quarantine, damaged, returns, and in-transit custody remain on hand but are not available for ordinary allocation.

Corrections post a linked compensating movement. Eloquent rejects update/delete in every supported database. PostgreSQL additionally installs a trigger that rejects update/delete outside the application.

Transfers move source to a dedicated in-transit bin at dispatch and in-transit to destination at receipt. Goods inspection moves accepted stock to available custody and rejected stock to controlled custody. Counts produce approved adjustment movements; they never rewrite a balance.

## Consequences

### Positive

- Every stock result can be reconstructed and attributed.
- Duplicate retries are safe through tenant-scoped idempotency.
- Quarantine, transfer, reservation, and work-order facts share one custody model.
- Historical valuation and source evidence remain available.
- A cache or future balance projection can be rebuilt from durable facts.

### Costs

- Availability queries aggregate movements and reservations until a rebuildable projection is introduced.
- High-volume warehouses will require representative query/load testing, projection checkpoints, and reconciliation.
- Backdated movement and moving-average correction policy needs finance approval.
- Concurrent posting needs row locks and retry handling.

## Rejected alternatives

- **Mutable quantity-on-hand as source:** too easy to change without evidence and unsafe under retries.
- **Delete or edit an incorrect movement:** destroys audit and valuation history.
- **Inventory status only on receipt/transfer documents:** cannot prove bin, custody, or partial quantity effects.
- **Redis balance as authority:** violates durable-data requirements.

## Follow-up decisions

- Projection/checkpoint design and reconciliation cadence at production volume.
- Fractional fixed-precision units, if any.
- Landed cost and retrospective moving-average policy.
- Negative-stock exceptions; none are enabled by this decision.
