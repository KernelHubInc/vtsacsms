# Procurement and Inventory Operations

**Audience:** operators, inventory controllers, finance reviewers, and support engineers  
**Scope:** Phase 12 local and pre-production operation

## Operational flow

1. Create or import catalog items using the published CSV template. Existing SKUs are rejected; imports never overwrite.
2. Submit a purchase request. The service selects the active amount/currency/department/cost-center approval matrix.
3. Complete sequential role-bound approvals, issue an RFQ, record quotations, approve the comparison, approve the PO, and issue it.
4. Record each physical delivery as a goods receipt, inspect every unit, and assign rejections to quarantine/damaged/returns custody.
5. Post the inspected receipt. This creates stock movements and then registers accepted/rejected receipt facts with Procurement.
6. Return rejected custody through the supplier-return workflow.
7. Match vendor invoices only after accepted receipt evidence exists. Resolve the discrepancy queue before approval/export.

## Stock investigation

Never repair an inventory mismatch with SQL or by editing a movement.

- Compare `on_hand_base`, `reserved_base`, and `available_base` for the exact item/warehouse/bin.
- Review movement source/destination, reference type/ID, reason, actor, and UTC occurrence time.
- Review active reservations and expiry.
- For transfers, inspect source, in-transit, and destination movements separately.
- For receipt differences, inspect accepted versus rejected line quantities and controlled custody.
- If the physical count is authoritative, run a blind/recount plan and post its approved adjustment.
- If a movement is wrong, post a reasoned compensating movement through the controlled workflow.

## Reorder report

The reorder report evaluates available stock, not total on hand. Quarantine and in-transit stock do not suppress an alert. Reports are intersected with the actor's tenant/site/warehouse assignments.

## Failure and retry

- Retried stock posts must reuse the original idempotency key.
- A duplicate key returns the original movement/reservation.
- Do not create a new key until the original outcome has been queried.
- State-transition errors mean the document was not eligible; inspect its current state and audit record.
- Accounting export uses the fake adapter locally. Do not represent it as a production accounting integration.

## Security

- Procurement approval requires `procurement.approve` and the exact approval-role assignment.
- Warehouse work requires `inventory.operate`; adjustment/count approval requires `inventory.adjust`.
- A transfer is listed only if both endpoints are accessible.
- The catalog is tenant shared, but stock and operational documents are warehouse/site scoped.
- Never include supplier banking, tax-registration, payment credentials, or production endpoints in imports, notes, logs, or fixtures.

## Recovery

PostgreSQL prevents stock movement update/delete at the database layer. Restore tests must confirm the movement ledger, reservations, receipt evidence, approvals, audit chain, and integration outbox restore together. A projection may be rebuilt; movement rows are the authority.
