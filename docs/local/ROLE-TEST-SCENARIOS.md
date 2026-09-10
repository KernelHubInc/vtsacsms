# Role UAT scenarios

Use [DEMO-CREDENTIALS.md](DEMO-CREDENTIALS.md), open a private browser window between roles, and record the UTC time, role, result, and correlation ID for failures.

1. **Super administrator:** open dashboard, organizations, users, roles, master data, audit, integrations, and all 15 locations.
2. **Platform administrator:** manage ordinary platform/CMS/site data; verify privileged role assignment is absent.
3. **Auditor:** view/export audits, reports and locations; verify operational and finance edit actions are absent.
4. **Operator administrator:** manage Amihan users/sites/assets/tariffs; search for “Northern Gateway” and verify it is inaccessible.
5. **Operations manager:** review status map, stale connectors, faults and maintenance; verify platform configuration is inaccessible.
6. **Site host and site manager:** inspect only Bayside Exchange; verify a second site URL returns forbidden/not found.
7. **Requester:** create lines and submit; verify self-approval is unavailable.
8. **Approver:** approve/reject/return submitted requests without changing requester fields.
9. **Procurement officer:** inspect the partial PO, supplier, goods receipt and submitted vendor invoice.
10. **Inventory manager:** review balances/movements, start physical count, review discrepancy and approve adjustment.
11. **Warehouse staff:** receive/issue/transfer/count; verify adjustment approval is unavailable.
12. **Maintenance manager:** review SLA warning, assign work, preventive plan and closure review.
13. **Technician:** at 390 × 844 open assigned work, checklist, time, parts and notes; verify unrelated work is absent.
14. **Finance manager:** view/export labelled demo reports and verify capture/refund/settlement cannot call a provider.
15. **Support agent:** view permitted support/station context, respond/escalate, and verify sensitive financial fields are absent.
16. **Fleet manager:** manage fleet context and assigned discovery without consumer/operator access.
17. **Executive:** filter/export allowed reports; verify every mutation is absent or forbidden.
18. **Consumer:** log in to Flutter, browse bounded map/list, search/filter/open details, add vehicle, check compatibility, toggle favorite, then open charging and confirm the exact Milestone 2 message.

Cross-cutting failures: record expected versus actual scope, URL, entity public ULID, correlation ID, viewport, and screenshot. Never add real personal, charger, card, bank, or tax data.
