# Phase 13 Acceptance: Maintenance and Asset Management

## Delivered Scope

- Service requests plus manual and normalized-OCPP incidents.
- Deduplicated selected-fault rules, persistent escalation, and validated recovery.
- Corrective, preventive, inspection, and vendor-repair work orders with explicit guarded states.
- Tenant priorities, effective-dated SLA policies, technician/vendor assignments, skills, versioned checklists, and safety steps.
- Private photo/PDF evidence metadata, labor/travel seconds, Inventory-backed part reservation/issue/return, and asset downtime.
- Failure, root-cause, and resolution codes; warranties, RMAs, vendor repairs, inspections, approval, closure, and linked follow-up work.
- Date, runtime-seconds, session-count, and watt-hour preventive triggers.
- Responsive technician workboard, tenant-safe Filament resources, API resources, queued notifications, and governed maintenance dashboards.

## Acceptance Checklist

- [x] Every tenant-owned Maintenance table has non-null tenant ownership and tenant-leading access indexes.
- [x] Work-order transitions and OCPP observations are immutable evidence.
- [x] Closed/canceled work cannot transition; recurrence creates a new linked work order.
- [x] Required safety/checklist steps and independent verification are guarded server-side.
- [x] Asset lifecycle changes use the Assets public contract.
- [x] Stock changes use immutable Inventory movements; no quantity-on-hand field is updated.
- [x] OCPP observations are idempotent, active faults deduplicate, and recovery requires stable evidence.
- [x] Preventive generation is checkpoint-idempotent for all four requested trigger classes.
- [x] Money, energy, durations, timestamps, and public identifiers use repository canonical units.
- [x] Labor, travel, part, vendor-repair, and RMA amounts cannot enter a work-order rollup in a different currency.
- [x] APIs and Filament base queries intersect tenant and site scope.
- [x] Notifications queue after commit and omit sensitive/raw protocol data.
- [x] Dashboard metrics include open work, SLA breach, downtime, availability, MTTA, MTTR, repeat failures, asset cost, parts, and warranty recovery.
- [x] Factories, deterministic seed roles/master labels, OpenAPI, ADR, architecture notes, and operations runbook are present.
- [x] No production credential, bank detail, tax registration, or charger-vendor custom behavior was invented.

## Risks

- A tenant can configure an incorrect fault rule or SLA; defaults intentionally do not invent charger behavior or response times.
- Attachment acceptance remains blocked on selection of a production malware scanner and retention policy.
- Usage-trigger quality depends on complete Charging session facts; component usage triggers remain disabled until an owned counter contract exists.
- UTC elapsed-time SLA is implemented; business calendars and holidays require a later approved policy.
- Vendor/RMA records are internal evidence until production vendor and accounting adapters are approved.

## Open Decisions

- Fault taxonomy, rule catalog, severity mapping, and recurrence windows by approved charger profile.
- Production evidence scanner, retention, erasure, and legal-hold rules.
- SLA calendars, holiday sources, pause-state policy, and escalation routing.
- Offline technician experience and whether a dedicated technician app is justified.
- Warranty recovery accounting, vendor portal/API integration, and removed-part custody.
