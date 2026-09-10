# Phase 12 Acceptance Checklist

## Procurement

- [x] Tenant-owned departments, cost centers, suppliers, approval matrices, purchase requests, revisions, RFQs, quotations, comparisons, purchase orders, deliveries, vendor invoices, matches, discrepancies, returns, and accounting-export state are modeled.
- [x] Approval order, exact-role checks, separation of duties, rejection, and revision are enforced in application workflows.
- [x] Partial receipt and rejection update procurement delivery evidence only through the published Inventory contract.
- [x] Three-way matching preserves immutable line evidence and blocks unresolved discrepancies from export.
- [x] The fake accounting adapter is usable locally without credentials.

## Inventory

- [x] Warehouses, site locations, bins, items, categories, units, lots, serials, reservations, transfers, returns, reorder points, counts, and adjustments are modeled.
- [x] All custody changes create immutable stock movements; no quantity-on-hand field exists.
- [x] Stock availability derives from eligible custody movements less reservations.
- [x] Dispatch and partial receipt use explicit in-transit custody.
- [x] Goods receipt inspection routes accepted and rejected stock independently.
- [x] Blind counts, recounts, approval, and movement-backed adjustments are implemented.
- [x] Work-order issue and unused-part return reference the work order and post compensating movements.
- [x] Moving-average and configured standard-cost valuation use integer minor units.

## Delivery surfaces and evidence

- [x] Tenant/warehouse-aware APIs, Form Requests, policies, resources, Filament operational resources, notifications, reports, CSV catalog import/export, factories, and deterministic seed data are present.
- [x] OpenAPI documents the Phase 12 HTTP contracts.
- [x] Architecture, state-machine, security, tenancy, event, ADR, and operations documents reflect the implemented boundaries.
- [x] Automated tests cover duplicate movement attempts, quarantine exclusion, over-issue prevention, partial transfer/receipt, approval ordering, three-way mismatch, count adjustment, work-order parts, tenant isolation, warehouse scope, notifications, audit, outbox, CSV, and mobile-token authorization.

## Explicitly unresolved

- [ ] Production accounting adapter/provider and credentials.
- [ ] Tax-registration and jurisdiction-specific supplier invoice rules.
- [ ] Fractional stock, landed costs, and currency-conversion policy.
- [ ] Tenant approval thresholds, reservation expiry/priority, barcode standards, and serial uniqueness beyond tenant/item scope.
- [ ] Maintenance-domain work-order lifecycle and asset commissioning handoff.
