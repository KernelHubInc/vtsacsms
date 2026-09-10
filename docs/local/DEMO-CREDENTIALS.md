# Milestone 1 demo credentials

All accounts use `VstaDemo!2026` and are local-only, active, verified, visibly named “Demo,” and assigned within the `Power Solutions Milestone 1 Demo` tenant. Platform users sign in at `/admin`; operational roles sign in at `/operator`; the consumer signs in through Flutter Web. These credentials must never be enabled in production.

| Role | Email | Organization | Modules and expected restriction | Suggested scenario |
|---|---|---|---|---|
| Platform Super Administrator | `superadmin@demo.vsta.local` | Power Solutions Demo Platform | All platform modules; tenant-wide | Review organizations, roles, audit and all sites |
| Platform Administrator | `admin@demo.vsta.local` | Power Solutions Demo Platform | Platform, CMS, sites, assets; no security-sensitive role assignment | Publish CMS test content |
| Security and Audit Administrator | `auditor@demo.vsta.local` | Power Solutions Demo Platform | Audit and reports read-only | Export audit history; confirm no edit actions |
| Operator Administrator | `operator.admin@demo.vsta.local` | Amihan Chargeworks | Amihan organization sites/assets/users | Confirm Habagat sites are absent |
| Operator Operations Manager | `operator.ops@demo.vsta.local` | Amihan Chargeworks | Status, sessions, maintenance, reports read-only | Review stale and faulted demo connectors |
| Site Host Manager | `sitehost@demo.vsta.local` | Sampaguita Places | Bayside Exchange site only | Review site report and maintenance status |
| Site Manager | `site.manager@demo.vsta.local` | Amihan Chargeworks | Bayside Exchange management only | Edit operating context and raise maintenance request |
| Procurement Requester | `requester@demo.vsta.local` | Amihan Chargeworks | Create/submit requests; cannot approve | Create a spare-parts request |
| Procurement Approver | `approver@demo.vsta.local` | Amihan Chargeworks | Approve/reject submitted requests | Review `DEMO-PR-0001` |
| Procurement Officer | `procurement@demo.vsta.local` | Amihan Chargeworks | RFQ, PO, receipt and invoice workflow | Inspect partial `DEMO-PO-0001` |
| Inventory Manager | `inventory.manager@demo.vsta.local` | Amihan Chargeworks | Inventory control, counts, adjustment approval | Review `DEMO-COUNT-0001` and `DEMO-ADJ-0001` |
| Warehouse Staff | `warehouse@demo.vsta.local` | Amihan Chargeworks | Receive, issue, transfer and count; no adjustment approval | Enter a blind count |
| Maintenance Manager | `maintenance.manager@demo.vsta.local` | Amihan Chargeworks | Dispatch, verify, SLA and maintenance reports | Assign/review `DEMO-WO-0001` |
| Maintenance Technician | `technician@demo.vsta.local` | Bayanihan Field Service | Assigned work only, technician-responsive UI | Use the workboard at 390 px viewport |
| Finance Manager | `finance@demo.vsta.local` | Amihan Chargeworks | Demo billing/reporting; real actions disabled | Review labelled demo financial screens |
| Customer Support Agent | `support@demo.vsta.local` | Power Solutions Demo Platform | Support and permitted station/session context | Triage fictional support context |
| Fleet Manager | `fleet.manager@demo.vsta.local` | Tala Fleet Services | Fleet membership, vehicles and assigned discovery | Review fleet access without operator data |
| Read-Only Executive | `executive@demo.vsta.local` | Power Solutions Demo Platform | Dashboards, filters, approved exports only | Confirm create/edit/approve are unavailable |
| Consumer Mobile User | `driver@demo.vsta.local` | Tala Fleet Services | Flutter profile, vehicles, locator and favorites | Search, favorite, add vehicle, open Milestone 2 placeholder |

The platform also seeds the internal tenant-scoped `operator-panel` access role. It grants panel entry only; module permissions stay on each role and scope.
