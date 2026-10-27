# CSMS report catalog

The portal offers reusable PDF summaries and full CSV downloads for the reports below. Exports run in a tenant-aware queue. An administrator with a broad role does not receive another tenant's records. The underlying permission grants, not the role label, determine access.

| Report available now | Operational use | Required permissions | Typical readers |
| --- | --- | --- | --- |
| Charging sessions | Check start/end times, delivered energy, duration, final recorded amount, and failure reason | Reporting view/export + charging session view | Super admin, admin, site operator |
| Sites and charging stations | Check active sites, publication state, address, and charger counts | Reporting view/export + location view | Super admin, admin, site operator |
| Payment reconciliation | Compare session payment intents, captured amounts, and refunds | Reporting view/export + payment view | Super admin, finance admin, finance-authorized operator |
| Vehicle register | Current vehicles, brand/model, personal owner or shared account, registration date | Reporting view/export + asset view + charging session view | Tenant-wide admin; site operators see only vehicles that visited their sites |
| Vehicle charging details | Vehicle, session driver, site, charger, start/end times, kWh, duration, estimated/final costs, payment state | Reporting view/export + asset view + charging session view | Super admin, admin, assigned-site operator |
| Vehicle usage summary | Completed sessions, energy, duration, known final charges, unpriced sessions and last stop per vehicle/currency | Reporting view/export + asset view + charging session view | Super admin, admin, assigned-site operator |
| Brand and model comparison | Completed usage and distinct recorded vehicle counts by current brand/model/currency | Reporting view/export + asset view + charging session view | Super admin, admin, assigned-site operator |

The PDF shows totals and up to 200 detail rows. For every matching row, use CSV. Date fields are UTC; the end date is exclusive. Asset inventory is a current snapshot. Generated files are requester-only for seven days and become inaccessible if permissions or site scope change. The portal uses the currently selected tenant even for super admins.

## Using the vehicle reports

1. Open **Reports > Queued exports** in the admin/operator portal.
2. Set the main date range for session **request time**. For one day, use that day as From and the next day as To. All boundaries and exported times are UTC.
3. Refine the vehicle filters: exact brand/model, vehicle ULID, partial owner/account name, partial session-driver name, account type, authorized location, and registration dates. Brand/model ignore case; punctuation remains meaningful. Blank filters mean all authorized records.
4. Choose **Create PDF** or **Export all rows as CSV**. Recent exports refresh every ten seconds. Download when completed.
5. Use charging details for transactions and summaries for completed usage comparisons. A final recorded charge is not proof of collection; check payment state/settled time and the financial reports.

The register ignores session dates, driver and state. Registration dates refer to the vehicle, not the user's registration. A location filter restricts the register to vehicles that visited it at any time. Summaries ignore state because they include completed sessions only. Vehicle filters do not affect the three original reports.

Brands, models and garage owners reflect the **current** vehicle register. Historical session attribution uses the saved vehicle, driver and charging-account IDs, never today's default vehicle. Removed vehicles retain their session attribution IDs with blank vehicle details. Unassigned sessions remain visible; distinct vehicle counts exclude null IDs. Spelling differences remain separate until explicitly corrected; no fuzzy merging is performed.

Personal vehicles show their owner; business/fleet vehicles show the owning account rather than an arbitrary member. Charging details identify the attributed session driver. Contact details, plates, birth dates and KYC records are excluded. Only tenant-wide grants for all four required permissions allow the register to include vehicles without sessions. Scoped grants intersect at each site for every required permission. Download fingerprints include tenant-wide register capability, so narrowing an admin to sites invalidates older broad exports.

CSV retains integer minor amounts, Wh and seconds, plus presentation kWh. PDFs display kWh, minutes and currency amounts. Summaries separate currencies, exclude non-completed sessions and count unpriced sessions. Known final charges are subtotals of priced rows, not settlement totals. Recorded session duration is not a claim about billable energy-flow time or vehicle efficiency.

### Existing free-text data

New and edited brands/models are uppercased, trimmed and have repeated whitespace collapsed by Assets. API validation happens after normalization. Free text and punctuation remain supported. No schema migration or new dependency is needed.

After deploying matching platform/report-worker code, normalize existing records per tenant:

```sh
php artisan assets:normalize-vehicle-names TENANT_ULID
php artisan assets:normalize-vehicle-names TENANT_ULID --apply
```

The first command previews a count; the second saves in batches of 250 and audits the updated count. It is idempotent and uses compare-and-update predicates to avoid overwriting concurrent edits. Rerun after concurrent edits if necessary. Before backfill, repeated internal whitespace in legacy names can still create separate groups. No financial amounts or session attributions change.

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
