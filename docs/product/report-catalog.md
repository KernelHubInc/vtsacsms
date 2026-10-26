# CSMS report catalog

The portal currently offers reusable PDF summaries and full CSV downloads for these three reports. Exports run in a tenant-aware queue and include only the requester's authorized sites. An administrator with a broad role does not receive another tenant's records. The underlying permission grants, not the role label, determine access.

| Report available now | Operational use | Required permissions | Typical readers |
| --- | --- | --- | --- |
| Charging sessions | Check start/end times, delivered energy, duration, final recorded amount, and failure reason | Reporting view/export + charging session view | Super admin, admin, site operator |
| Sites and charging stations | Check active sites, publication state, address, and charger counts | Reporting view/export + location view | Super admin, admin, site operator |
| Payment reconciliation | Compare session payment intents, captured amounts, and refunds | Reporting view/export + payment view | Super admin, finance admin, finance-authorized operator |

The PDF shows totals and up to 200 detail rows. For every matching row, use CSV. Date fields are UTC; the end date is exclusive. Asset inventory is a current snapshot. Generated files are requester-only for seven days and become inaccessible if permissions or site scope change. The portal uses the currently selected tenant even for super admins.

## Recommended next reports

These are a prioritized backlog, **not yet implemented PDF exports**. Each requires its own governed query, permission review, and tests before it appears in the portal.

| Report | Why clients/operators need it | Suggested access |
| --- | --- | --- |
| Charger uptime, offline time, and fault history | Locate unreliable devices and distinguish planned maintenance from downtime | Super admin, admin, assigned-site operator |
| Utilization and energy by site, charger, and connector | Plan capacity and compare performance across locations | Super admin, admin, assigned-site operator |
| Session exceptions and delayed starts | Investigate failed starts, stuck finalizing, and remote-stop outcomes | Super admin, admin, assigned-site operator |
| Tariff and charging revenue | Check billed units, tariff versions, and revenue by site | Super admin, finance admin, finance-authorized operator |
| Wallet top-ups and refunds | Reconcile QR payments and customer wallet postings | Super admin, finance admin; operator only if payment grants allow it |
| Settlement and payout | Reconcile provider collections to site/operator payouts | Super admin, settlement admin |
| Maintenance work orders and response times | Track faults, assignments, closure, and service quality | Super admin, admin, assigned-site maintenance operator |
| Customer and KYC funnel | Measure registrations and verification outcomes without exposing sensitive documents | Super admin, authorized identity admin |
| Role and audit activity | Review permission changes, exports, manual overrides, and privileged actions | Super admin, authorized security admin |

Before adding a financial report, define whether it represents gross charges, captured money, refunds, tax, fees, or settled proceeds. Those numbers must not be mixed. Reporting should use recorded values and clear freshness notes; estimates and incomplete sessions should be labeled separately.
