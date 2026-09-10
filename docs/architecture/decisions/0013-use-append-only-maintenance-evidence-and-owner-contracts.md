# ADR 0013: Use Append-only Maintenance Evidence and Owner Contracts

- **Status:** Accepted
- **Date:** 2026-07-28
- **Owners:** Maintenance, Assets, Inventory, Charging

## Context

Corrective and preventive work combines safety decisions, charger observations, labor, parts, asset availability, vendor activity, and financial evidence. Treating a work order as a mutable status row would make state history, SLA measurement, safety verification, tenant investigations, and warranty recovery difficult to prove.

Maintenance also needs to affect assets and stock without becoming the owner of charger lifecycle or inventory quantity. OCPP evidence originates at the gateway and is normalized by Charging before it reaches a business workflow.

## Decision

1. A work order has an explicit guarded state machine and an append-only transition stream with aggregate version, actor/service, UTC occurrence time, reason, notes, and correlation ULID.
2. `CLOSED` and `CANCELED` are terminal. A recurrence after closure creates a new `REPORTED` work order linked through `reopened_from_id`; prior evidence is never rewritten.
3. Selected normalized OCPP fault observations create or update an incident through `FaultObservationContract`. Active incidents deduplicate by tenant, asset type, asset ULID, and normalized fault code. Recovery requires a configured stable interval.
4. Maintenance requests lifecycle changes through `AssetMaintenanceContract`. The Assets implementation validates tenant/site ownership and owns the lifecycle mutation and resulting event.
5. Maintenance reserves, issues, and returns parts through Inventory's `StockReservationService` and `WorkOrderPartsService`. Quantity and valuation remain derived from immutable Inventory movements.
6. Preventive occurrences are idempotent checkpoints. Date triggers use UTC instants plus integer-second recurrence; runtime, session-count, and energy triggers read Charging facts without editing them.
7. SLA policies are effective-dated. Targets and allowed pause evidence are retained on the work order. Breach notifications are deduplicated by persisted notification instants.
8. Labor and travel use integer seconds. All cost values use integer minor units and an ISO 4217 currency. Attachments remain private and quarantined until a selected scanner marks them safe.

## Consequences

- Audits can reconstruct every accepted work-order state without relying on mutable application logs.
- Assets and Inventory retain their invariants and can later be extracted behind the same contracts.
- The modular monolith may use one local database transaction today, but callers cannot import owner internals to bypass the contract.
- More evidence tables and workflow services are required than a status-column implementation.
- Recovery, SLA, and preventive automation must run under an explicit tenant service context and remain safe under retries.

## Rejected Alternatives

- **Let Maintenance update asset and stock tables directly:** rejected because it creates competing ownership and bypasses safety and custody rules.
- **Reopen a closed aggregate by changing its status:** rejected because it destroys the meaning of prior closure, repair duration, and verification evidence.
- **Create an incident for every charger status message:** rejected because repeated OCPP evidence would flood operations and hide a persistent active condition.
- **Store computed quantity-on-hand on the work order:** rejected because Inventory's movement ledger is authoritative.

## Unresolved Decisions

- Production malware-scanning provider and evidence-retention periods.
- Tenant SLA calendars beyond elapsed UTC seconds, including holidays and market-specific working hours.
- Approved fault taxonomy, rule defaults, recurrence windows, and vendor-specific diagnostic mappings.
- Technician offline operation, location capture, and a possible future dedicated technician application.
- Warranty accounting treatment and production vendor/RMA integration adapters.
