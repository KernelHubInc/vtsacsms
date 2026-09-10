# Integration Event Catalog

**Status:** Candidate event baseline; schemas must be versioned before implementation  
**Scope:** Committed facts crossing bounded contexts or container boundaries

## 1. Event Standard

Events are immutable facts named in past tense using:

```text
<context>.<aggregate>.<fact>.v<major>
```

Example envelope:

```json
{
  "event_id": "01K...ULID",
  "event_type": "charging.session.completed.v1",
  "schema_version": 1,
  "occurred_at": "2026-07-20T03:15:22.481Z",
  "tenant_id": "01K...ULID",
  "aggregate_type": "charging_session",
  "aggregate_id": "01K...ULID",
  "aggregate_version": 7,
  "correlation_id": "01K...ULID",
  "causation_id": "01K...ULID",
  "actor": {
    "type": "identity|service|charger|system",
    "id": "01K...ULID"
  },
  "data": {}
}
```

`tenant_id` is required for tenant-scoped events and absent only for explicitly platform-global facts. IDs are ULIDs. Instants are UTC ISO 8601. Payload fields use `amount_minor` + `currency`, `energy_wh`, `power_w`, and `duration_seconds`.

## 2. Delivery Contract

- Producers commit business state and an outbox record atomically.
- Delivery is at least once. Consumers maintain an inbox/deduplication record keyed by `event_id` and make the business effect idempotent.
- Ordering is guaranteed only where the transport is explicitly partitioned by `tenant_id + aggregate_type + aggregate_id`; consumers also compare `aggregate_version` and handle gaps/reordering.
- Schemas are additive within a major version. Removing/changing meaning/type requires a new major version and parallel migration window.
- Events are not RPC responses. Consumers do not assume all listed consumers exist, nor do producers block on their completion.
- Retries are bounded with backoff. Poison events are quarantined with alert, inspection, and audited replay—not dropped.
- Payloads contain no secrets, raw card data, tokens usable as credentials, or unnecessary personal/message content.
- Event retention, broker/queue topology, and replay horizon are deployment decisions. PostgreSQL outbox state remains durable until delivery policy is satisfied.

## 3. Catalog

Consumers below are expected or illustrative. Adding a consumer does not transfer data ownership.

### OCPP protocol-edge facts

The gateway emits protocol evidence on the phase-six Redis Streams transport using `gateway.ocpp.<action>.received.v1` and the shared [`gateway-ocpp-normalized.v1` schema](../../packages/contracts/events/gateway-ocpp-normalized.v1.schema.json). These are not canonical Charging facts: Charging validates/deduplicates them and emits the `charging.*` facts below only after its own state guards. Enrolled events include tenant and Assets charger ULIDs. Explicit unauthenticated local-development events use a separate quarantine stream with null tenant/aggregate IDs and must never feed production business consumers.

Supported actions are connected/disconnected, BootNotification, Heartbeat, StatusNotification, Authorize result, 1.6 Start/StopTransaction, 2.0.1 TransactionEvent, MeterValues, allowlisted DataTransfer, diagnostics/log status, firmware status, security event, and command response. Raw token values and security technical details are excluded from normalized events. Delivery is at least once and source OCPP unique IDs plus event ULIDs support deduplication.

### Identity, Tenancy, and Organizations

| Event | Producer | Minimum data | Expected consumers |
| --- | --- | --- | --- |
| `identity.subject.created.v1` | Identity | `subject_id`, `subject_kind`, verification state | Organizations, Notifications, Reporting |
| `identity.subject.suspended.v1` | Identity | `subject_id`, reason code, effective time | Organizations, Charging, Support, Integrations |
| `identity.subject.reactivated.v1` | Identity | `subject_id`, effective time | Organizations, Support |
| `identity.authenticator.compromised.v1` | Identity | `subject_id`, authenticator kind, response status (no secret) | Notifications, Support, security operations |
| `tenancy.tenant.created.v1` | Tenancy | `tenant_id`, status, locale/timezone defaults | Organizations, Integrations, Reporting |
| `tenancy.tenant.activated.v1` | Tenancy | `tenant_id`, enabled capability codes | All tenant-operating contexts |
| `tenancy.tenant.suspended.v1` | Tenancy | `tenant_id`, reason code, effective time | All tenant-operating contexts, gateway control |
| `tenancy.entitlements.changed.v1` | Tenancy | entitlement codes/version/effective time | Admin surfaces, affected contexts, Reporting |
| `organizations.membership.activated.v1` | Organizations | `membership_id`, `subject_id`, organization/scope IDs | Identity session policy, Notifications, Reporting |
| `organizations.membership.revoked.v1` | Organizations | membership/subject/scope IDs, effective time | Identity session policy, Charging, Support, Reporting |
| `organizations.role_assignment.changed.v1` | Organizations | assignment ID, subject, permission bundle/scope version | authorization cache invalidation, audit projection |
| `organizations.organization.updated.v1` | Organizations | organization ID, changed public/business field codes, version | Billing snapshots (query on need), Procurement, Reporting |

### Locations and Assets

| Event | Producer | Minimum data | Expected consumers |
| --- | --- | --- | --- |
| `locations.location.created.v1` | Locations | location ID, organization ID, timezone, geometry | Assets, CMS/public projection, Reporting |
| `locations.location.updated.v1` | Locations | location ID, version, changed field codes | public projection, Maintenance, Reporting |
| `locations.location.published.v1` | Locations | location ID, publication version/time | public map/search, CMS, Integrations |
| `locations.location.unpublished.v1` | Locations | location ID, reason/effective time | public map/search, Integrations |
| `assets.charger.registered.v1` | Assets | charger ID, location ID, protocol identity reference, capability version | gateway registry, Charging, Maintenance, Reporting |
| `assets.charger.commissioned.v1` | Assets | charger ID, EVSE/connector IDs, commissioned time | Charging, Locations public projection, Maintenance |
| `assets.asset.restricted.v1` | Assets | asset ID/type, restriction code/scope, effective time | Charging, gateway commands, public projection, Maintenance |
| `assets.asset.returned_to_service.v1` | Assets | asset ID/type, effective time, authorizing evidence reference | Charging, public projection, Maintenance |
| `assets.asset.relocated.v1` | Assets | asset ID, from/to location IDs, effective time | Charging, Maintenance, Reporting |
| `assets.asset.retired.v1` | Assets | asset ID, retirement reason/time | Charging, gateway registry, Maintenance, Reporting |

### Charging and Tariffs

| Event | Producer | Minimum data | Expected consumers |
| --- | --- | --- | --- |
| `charging.charger.connected.v1` | Charging | charger ID, gateway node, protocol/profile, connected time | Assets operational projection, operators, Reporting |
| `charging.charger.disconnected.v1` | Charging | charger ID, last seen/disconnected time, reason category | operators, public availability, Maintenance, Reporting |
| `charging.connector.status_changed.v1` | Charging | connector ID, canonical status, observed/received times, source | public availability, Maintenance, Reporting |
| `charging.command.requested.v1` | Charging | command ID/type, charger/EVSE/connector/session IDs, deadline | OCPP gateway, audit/operations |
| `charging.command.dispatched.v1` | Charging | command ID/type, dispatch time, gateway correlation | audit/operations, Support |
| `charging.command.completed.v1` | Charging | command ID, result category, completed time, protocol reference | requesting workflow, Support, Reporting |
| `charging.command.failed.v1` | Charging | command ID, failure category, retryability, completed time | requesting workflow, Support, operations |
| `charging.session.requested.v1` | Charging | session ID, driver/token ref, connector ID, request channel | Payments policy workflow, Reporting |
| `charging.session.authorized.v1` | Charging | session ID, authorization reference, tariff version ref if known | Payments, mobile realtime, Reporting |
| `charging.session.started.v1` | Charging | session ID, asset/location IDs, started time, start meter Wh, tariff version ref | Tariffs/Billing projection, mobile, Support, Reporting |
| `charging.session.energy_updated.v1` | Charging | session ID, cumulative energy Wh, current power W if trustworthy, observed time, quality | mobile realtime, anomaly monitoring, Reporting (sampled/coalesced) |
| `charging.session.suspended.v1` | Charging | session ID, suspension actor category, observed time | mobile/operator realtime, Reporting |
| `charging.session.resumed.v1` | Charging | session ID, resumed time | mobile/operator realtime, Reporting |
| `charging.session.stopping.v1` | Charging | session ID, initiator/stop-request reason, requested time | mobile/operator realtime |
| `charging.session.completed.v1` | Charging | session ID, started/stopped times, duration seconds, energy Wh, stop reason, quality, tariff version ref | Tariffs/Billing, Payments, Notifications, Support, Reporting |
| `charging.session.failed.v1` | Charging | session ID, phase, reason category, last state/time | Payments cancellation/review, Notifications, Support, Reporting |
| `charging.session.cancelled.v1` | Charging | session ID, actor/source, reason category, canceled time | Payments release/cancellation, mobile, Reporting |
| `charging.session.expired.v1` | Charging | session ID, expired deadline/time, last state | Payments release/cancellation, mobile, Reporting |
| `charging.session.flagged_for_review.v1` | Charging | session ID, quality/exception codes, provisional facts | Billing hold, Support/operations, Reporting |
| `charging.cdr.finalized.v1` | Charging | CDR/session/version IDs, snapshot hash, Wh, seconds, amount minor/currency, quality outcome | Billing, Support, Reporting |
| `charging.cdr.review_required.v1` | Charging | CDR/session IDs, blocking anomaly codes | Billing hold, Support/operations |
| `charging.cdr.superseded.v1` | Charging | prior/new CDR version references and correction reason | Billing, Support, Reporting |
| `tariffs.tariff.published.v1` | Tariffs | tariff ID/version ID, currency, effective interval, applicability summary | Charging, public price projection, Billing, Reporting |
| `tariffs.tariff.withdrawn.v1` | Tariffs | tariff/version ID, withdrawal effective time/reason | Charging selection, public projection, Reporting |
| `tariffs.charge.calculated.v1` | Tariffs | calculation ID, session ID, tariff version ID, amount minor/currency, breakdown/checksum | Billing, Support, Reporting |
| `tariffs.charge.calculation_failed.v1` | Tariffs | session ID, tariff version/ref, reason code | Billing exception, operators, Reporting |

### Payments, Billing, and Settlements

| Event | Producer | Minimum data | Expected consumers |
| --- | --- | --- | --- |
| `payments.intent.created.v1` | Payments | intent ID, payer/billable refs, amount minor/currency, provider code | Charging authorization workflow, Reporting |
| `payments.intent.status_changed.v1` | Payments | intent ID, provider-neutral state, safe provider status, authorized/captured minor amounts and currency | Billing collection orchestration, Support, Reporting |
| `payments.intent.action_required.v1` | Payments | intent ID, action type, safe expiry/reference | mobile/API workflow, Notifications |
| `payments.authorization.succeeded.v1` | Payments | intent ID, authorization ID, amount minor/currency, expiry | Charging, Billing, Reporting |
| `payments.authorization.failed.v1` | Payments | intent ID, safe reason category, retryability | Charging, Notifications, Support, Reporting |
| `payments.capture.succeeded.v1` | Payments | intent/capture IDs, billable ref, amount minor/currency, provider reference | Billing allocation, Settlements, Notifications, Reporting |
| `payments.capture.failed.v1` | Payments | intent/attempt ID, amount/currency, reason category, ambiguity | Billing collection status, Support, Reporting |
| `payments.refund.succeeded.v1` | Payments | refund/capture IDs, amount minor/currency, reason code | Billing allocation, Settlements, Notifications, Reporting |
| `payments.refund.failed.v1` | Payments | refund ID, amount/currency, reason category, ambiguity | Finance operations, Support |
| `payments.dispute.opened.v1` | Payments | dispute ID, capture ref, amount/currency, reason category, deadline | Settlements, Billing, Support, Notifications |
| `payments.dispute.closed.v1` | Payments | dispute ID, outcome, amount impact/currency | Settlements, Billing, Reporting |
| `billing.rated_charge.finalized.v1` | Billing | rated charge ID, session/calculation refs, amount minor/currency | Payments capture workflow, Settlements, Reporting |
| `billing.invoice.issued.v1` | Billing | invoice ID/number ref, account/subject ref, total minor/currency, issued time | Notifications, Support, Settlements, Reporting |
| `billing.credit_note.issued.v1` | Billing | credit note/invoice refs, amount minor/currency, reason code | Payments refund workflow, Settlements, Notifications, Reporting |
| `billing.payment.allocated.v1` | Billing | allocation ID, payment/invoice refs, amount minor/currency | Settlements, Reporting |
| `billing.ledger_transaction.posted.v1` | Billing | transaction/reference IDs, posting event, balanced amount minor/currency | Settlements, accounting export, Reporting |
| `billing.account.balance_changed.v1` | Billing | account ID, balance minor/currency, cause reference | Support, Reporting, optional Notifications |
| `settlements.reconciliation.completed.v1` | Settlements | run ID, source period, matched/exception counts and control totals | Finance operations, Reporting |
| `settlements.exception.raised.v1` | Settlements | exception ID/type, source refs, difference minor/currency | Finance operations, Support if customer-impacting |
| `settlements.exception.resolved.v1` | Settlements | exception ID, resolution code, evidence ref | Reporting, finance audit |
| `settlements.statement.approved.v1` | Settlements | statement ID, beneficiary ref, period, net minor/currency | Notifications/Integrations after payout policy approval, Reporting |

### Procurement, Inventory, and Maintenance

| Event | Producer | Minimum data | Expected consumers |
| --- | --- | --- | --- |
| `procurement.requisition.approved.v1` | Procurement | requisition ID, organization, approved total minor/currency | buyer workflow, Reporting |
| `procurement.purchase_order.issued.v1` | Procurement | PO ID/version, supplier ref, destination, expected lines/times | Inventory expected receipts, Notifications, Reporting |
| `procurement.purchase_order.changed.v1` | Procurement | PO ID/new version, change reason, expected line deltas | Inventory, Notifications, Reporting |
| `procurement.purchase_order.cancelled.v1` | Procurement | PO/version ID, cancellation time/reason | Inventory, Notifications, Reporting |
| `procurement.purchase_order.receipt_registered.v1` | Procurement | PO ID/line, goods receipt ID, accepted/rejected base quantity | procurement delivery projection, Reporting |
| `procurement.vendor_invoice.exported.v1` | Procurement | invoice ID, adapter key, safe external reference, exported UTC time | accounting integration, Reporting |
| `inventory.goods.received.v1` | Inventory | receipt ID, PO ref, item/serial/lot quantities and warehouse | Procurement, Assets candidate handoff, Reporting |
| `inventory.goods_receipt.posted.v1` | Inventory | receipt ID, warehouse, posted UTC time, accepted/rejected line totals | Procurement, Notifications, Reporting |
| `inventory.stock.reserved.v1` | Inventory | reservation ID, purpose/work order, item/location/quantity | Maintenance, Procurement planning, Reporting |
| `inventory.stock.reservation_failed.v1` | Inventory | reservation ID/purpose, shortage quantities/reason | Maintenance, Procurement, Notifications |
| `inventory.stock.reservation_released.v1` | Inventory | reservation ID, released base quantity, reason/time | Maintenance, Procurement planning, Reporting |
| `inventory.stock.issued.v1` | Inventory | movement ID, purpose/work order, item/serial/quantity/location | Maintenance, Assets handoff, Reporting |
| `inventory.stock.returned.v1` | Inventory | movement ID, purpose ref, item/serial/quantity/location/condition | Maintenance, Reporting |
| `inventory.stock.returned_to_supplier.v1` | Inventory | movement ID, supplier return/PO ref, item/lot/serial/quantity | Procurement, Reporting |
| `inventory.transfer.dispatched.v1` | Inventory | transfer ID, from/to, item/serial/quantities, dispatched time | destination operations, Reporting |
| `inventory.transfer.received.v1` | Inventory | transfer ID, received quantities/serials, discrepancy status | origin operations, Maintenance, Reporting |
| `inventory.stock.adjusted.v1` | Inventory | movement ID, item/location/quantity delta, approved reason/ref | Inventory control, Reporting/audit |
| `inventory.serial.handed_to_assets.v1` | Inventory | serial/item ID, custody handoff ID/time/location | Assets, Procurement, Reporting |
| `maintenance.incident.opened.v1` | Maintenance | incident ID, asset/location, normalized fault, source/observed time | Assets/Charging operations, dispatcher, Reporting |
| `maintenance.incident.reoccurred.v1` | Maintenance | incident ID, occurrence count, last observation, fault/asset refs | dispatcher, Reporting |
| `maintenance.incident.escalated.v1` | Maintenance | incident ID, escalation level, fault/location | Notifications, dispatcher, Reporting |
| `maintenance.incident.recovered.v1` | Maintenance | incident ID, asset/fault refs, validated recovery time | Notifications, operations, Reporting |
| `maintenance.work_order.created.v1` | Maintenance | work order ID, fault/asset, priority/status | Inventory planning, Notifications, Reporting |
| `maintenance.work_order.state_changed.v1` | Maintenance | work order ID, from/to state, reason, asset/location, applicable UTC evidence | Notifications, Support, Reporting |
| `maintenance.work_order.assigned.v1` | Maintenance | work order ID, team/technician, schedule | Notifications, Reporting |
| `maintenance.work_order.started.v1` | Maintenance | work order ID, started time | Assets/operations, Reporting |
| `maintenance.work_order.awaiting_parts.v1` | Maintenance | work order ID, required item refs/quantities | Inventory, Procurement, Reporting |
| `maintenance.work_order.completed.v1` | Maintenance | work order ID, completion time, labor seconds, part movement refs, test summary | Assets return-to-service workflow, Notifications, Reporting |
| `maintenance.work_order.verified.v1` | Maintenance | work order ID, verifier, verified time/outcome | Assets, Support, Reporting |
| `maintenance.work_order.closed.v1` | Maintenance | work order ID, closed time/resolution | Support, Reporting |
| `maintenance.work_order.reopened.v1` | Maintenance | new work order ID, prior closed order ID, reason | Assets/operations, Notifications, Reporting |

### Notifications, Support, Reporting, CMS, and Integrations

| Event | Producer | Minimum data | Expected consumers |
| --- | --- | --- | --- |
| `notifications.notification.delivered.v1` | Notifications | notification ID, source ref, channel, delivered time | source context if delivery matters, Reporting |
| `notifications.notification.failed.v1` | Notifications | notification ID, source ref, channel, terminal reason category | source context/operations, Reporting |
| `support.case.created.v1` | Support | case ID, category/priority, linked record refs | Notifications, assigned queue, Reporting |
| `support.case.escalated.v1` | Support | case ID, escalation reason/target/time | operational owner, Notifications, Reporting |
| `support.case.resolved.v1` | Support | case ID, resolution code/time | Notifications, Reporting |
| `reporting.export.completed.v1` | Reporting | export ID, report type, safe object ref, expiry | Notifications, audit |
| `reporting.export.failed.v1` | Reporting | export ID, reason category | Notifications, operations |
| `cms.content.published.v1` | CMS | content ID/version, locale, effective time | public cache/search invalidation, Reporting |
| `cms.content.unpublished.v1` | CMS | content ID/version, effective time/reason | public cache/search invalidation |
| `integrations.webhook.delivery_failed.v1` | Integrations | subscription/delivery IDs, event ID, terminal category | tenant integration owner, Notifications, Reporting |
| `integrations.client.revoked.v1` | Integrations | client ID, effective time/reason | gateway/API authorization caches, security operations |
| `integrations.import.completed.v1` | Integrations | import ID/type, accepted/rejected counts, checksum | owning context, Notifications, Reporting |
| `integrations.import.failed.v1` | Integrations | import ID/type, reason category, quarantine ref | owning context, Notifications, operations |

## 4. Commands Are Not Events

Examples of commands that must not masquerade as facts:

| Command | Target owner | Possible resulting events |
| --- | --- | --- |
| `StartCharging` / `StopCharging` | Charging | `charging.command.*`, `charging.session.*` |
| `RestrictAsset` / `ReturnAssetToService` | Assets | `assets.asset.restricted.v1`, `assets.asset.returned_to_service.v1` |
| `CapturePayment` / `RefundPayment` | Payments | `payments.capture.*`, `payments.refund.*` |
| `ReserveStock` / `IssueStock` | Inventory | `inventory.stock.*` |
| `SendNotification` | Notifications | `notifications.notification.*` |

A command includes the requesting actor/service, tenant, target, reason, idempotency key, correlation, and deadline where relevant. Acceptance only means the owner took responsibility for processing; it is not proof of the final fact.

## 5. Privacy and Volume Classes

- **Operational control events:** low/medium volume, high urgency; do not route through reporting queues that can block them.
- **Meter/live events:** high volume; coalesce customer/reporting updates where exact samples are unnecessary while retaining source readings in Charging under policy.
- **Financial/stock events:** high integrity; preserve amounts/units/source references, longer audit/reconciliation retention, restricted consumers.
- **Identity/support/notification events:** potentially personal; minimize data and fetch authorized details only when needed.

## 6. Open Decisions

- Transport/broker, partitions, retention, replay, dead-letter storage, and schema registry technology.
- Which event payloads need regional/data-classification routing restrictions.
- Meter sample event granularity versus aggregation at expected scale.
- Consumer compatibility window and deprecation policy.
- Whether external partner events reuse internal schemas or use separately curated webhook schemas.
