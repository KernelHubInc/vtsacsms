# Administrator CRUD Inventory

Status: final code inventory; runtime verification is recorded in
`ADMIN-CRUD-COVERAGE.md`.

## Discovery scope

The inventory was produced from the `admin` panel provider, its explicit
resource/page registrations, `php artisan route:list --path=admin --json`,
the resource page maps, table/header/modal actions, shared Filament actions,
policies, tenant query scopes, and the custom Livewire pages.

- Registered Filament resources: **47**
- Registered custom/application pages: **7**
- Top-level audited units: **54**
- Admin HTTP routes: **65**, including login/logout and resource create/edit
  routes
- Relation managers: **0**
- Resource clusters: **0**
- Additional admin-only controllers: **0**

Every admin route is behind Filament authentication,
`EstablishPanelTenantContext`, and `TrackPanelSession`. Tenant-owned models
also use their owning module's tenant/global scope or an explicit accessible
site/warehouse/session query. Hidden navigation is not treated as
authorization.

## Legend

- Lifecycle: **A** reference/master, **B** operational master, **C** workflow,
  **D** immutable transaction/ledger, **E** generated/integration, **N/A**
  presentation.
- Operations are shown as `C/R/U/L/Rs/B`, meaning create, read, update,
  lifecycle alternative, restore, and bulk action.
- `Y` is implemented, `N/A` is not applicable, and `API` means the owning
  module exposes the operation but the admin page remains a review queue.
- Auth: `P+T` means server policy/permission plus tenant/resource scope.
- Audit: `Y` means mutations flow through an audited application workflow or
  audited Filament action. `N/A` means the page cannot mutate data.

## Authoritative inventory

| # | Module | Resource or page | Route | Navigation group | Model or source | Tenant ownership | Lifecycle | Applicable operations (C/R/U/L/Rs/B) | Discovery gap | Authorization | Audit | Tests | Final remediation |
|---:|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | Reporting | Dashboard | `/admin` | Dashboard | Cached reporting projections | Selected tenant | N/A | N/A/Y/N/A/N/A/N/A/N/A | None | P+T | N/A | Portal + browser | Retained read-only dashboard |
| 2 | Audit | Audit events | `/admin/audit-events` | Identity and audit | `AuditEvent` | Tenant | D | N/A/Y/N/A/N/A/N/A/N/A | No detail/filter action | P+T | N/A | Foundation + audit tests | Added read detail and filters; immutable |
| 3 | Charging | Charger commands | `/admin/charger-commands` | Charging network | `ChargerCommand` | Tenant/operator/site | E | N/A/Y/N/A/N/A/N/A/N/A | No detail/filter action | P+T | N/A | Charging + foundation | Added read detail and filters; outcomes remain gateway-owned |
| 4 | Charging | Charging sessions | `/admin/charging-sessions` | Charging network | `ChargingSession` | Tenant/operator/site | D | N/A/Y/N/A/Y/N/A/N/A | No diagnostic detail/filter action | P+T | Y | Charging + foundation | Added detail/filters; remote stop remains the only active-session mutation |
| 5 | Assets | Charging stations | `/admin/charging-stations` | Charging network | `ChargingStation` | Tenant/operator/site | B | Y/Y/Y/API/N/A/N/A | Create, validation, detail, filter, audit absent | P+T | Y | Asset + foundation | Added create/edit/detail, tenant-safe selectors and audited identifiers; retirement remains module workflow/API |
| 6 | Charging | Connector statuses | `/admin/connector-statuses` | Charging network | `ConnectorStatus` | Tenant/operator/site | E | N/A/Y/N/A/N/A/N/A/N/A | No detail action | P+T | N/A | Charging + foundation | Added read detail; status is OCPP-generated |
| 7 | Assets | Connectors | `/admin/connectors` | Charging network | `Connector` | Tenant/operator/site | B | API/Y/Y/API/N/A/N/A | Missing read detail/filter and explicit site authorization | P+T | Y | Asset + foundation | Added detail, status filter, audited edit, site-scoped authorization; creation/retirement stays hierarchy workflow/API |
| 8 | CMS | App store links | `/admin/content/app-store-links` | Content | `AppStoreLink` | Tenant | A | Y/Y/Y/Y/N/A/N/A | Edits were not consistently audited | P+T | Y | CMS + foundation | Audited create/edit; deactivate/unpublish through managed status |
| 9 | CMS | Articles | `/admin/content/articles` | Content | `ContentArticle` | Tenant | A | Y/Y/Y/Y/N/A/N/A | Edits were not consistently audited | P+T | Y | CMS + foundation | Audited create/edit; publication is lifecycle alternative |
| 10 | CMS | CMS pages | `/admin/content/cms-pages` | Content | `CmsPage` | Tenant | A | Y/Y/Y/Y/N/A/N/A | Edits were not consistently audited | P+T | Y | CMS + foundation | Audited create/edit; publication is lifecycle alternative |
| 11 | CMS | CMS sections | `/admin/content/cms-sections` | Content | `CmsSection` | Tenant | A | Y/Y/Y/Y/N/A/N/A | Edits were not consistently audited | P+T | Y | CMS + foundation | Audited create/edit; active state is lifecycle alternative |
| 12 | CMS | Contact details | `/admin/content/contact-details` | Content | `ContactDetail` | Tenant | A | Y/Y/Y/Y/N/A/N/A | Edits were not consistently audited | P+T | Y | CMS + foundation | Audited create/edit; active state is lifecycle alternative |
| 13 | CMS | FAQs | `/admin/content/faqs` | Content | `Faq` | Tenant | A | Y/Y/Y/Y/N/A/N/A | Edits were not consistently audited | P+T | Y | CMS + foundation | Audited create/edit; active state is lifecycle alternative |
| 14 | CMS | Partner logos | `/admin/content/partner-logos` | Content | `PartnerLogo` | Tenant | A | Y/Y/Y/Y/N/A/N/A | Edits were not consistently audited | P+T | Y | CMS + foundation | Audited create/edit; active state is lifecycle alternative |
| 15 | CMS | Redirects | `/admin/content/redirects` | Content | `CmsRedirect` | Tenant | A | Y/Y/Y/Y/N/A/N/A | Edits were not consistently audited | P+T | Y | CMS + foundation | Audited create/edit; active state is lifecycle alternative |
| 16 | CMS | SEO metadata | `/admin/content/seo-metadata` | Content | `SeoMetadata` | Tenant | A | Y/Y/Y/Y/N/A/N/A | Edits were not consistently audited | P+T | Y | CMS + foundation | Audited create/edit; active state is lifecycle alternative |
| 17 | CMS | Testimonials | `/admin/content/testimonials` | Content | `Testimonial` | Tenant | A | Y/Y/Y/Y/N/A/N/A | Edits were not consistently audited | P+T | Y | CMS + foundation | Audited create/edit; active state is lifecycle alternative |
| 18 | Inventory | Physical count plans | `/admin/count-plans` | Inventory | `CountPlan` | Tenant/warehouse | C | API/Y/API/API/N/A/N/A | Review queue lacked details and filtering | P+T | Y via workflow | Inventory + foundation | Added detail/filter; count-sheet entry, approval and posting remain dedicated workflow/API |
| 19 | Assets | EVSEs | `/admin/evses` | Charging network | `Evse` | Tenant/operator/site | B | API/Y/Y/API/N/A/N/A | Missing detail/filter and explicit site authorization | P+T | Y | Asset + foundation | Added detail, lifecycle filter, audited edit, site scope; hierarchy creation/retirement remains API |
| 20 | Payments | Finance review queue | `/admin/finance-reviews` | Finance | `FinanceReview` | Tenant | C | N/A/Y/N/A/API/N/A/N/A | No detail/filter action | P+T | Y via payment workflow | Payment + foundation | Added detail/filter; resolution remains provider/reconciliation workflow |
| 21 | Inventory | Goods receipts | `/admin/goods-receipts` | Inventory | `GoodsReceipt` | Tenant/warehouse | C | API/Y/API/API/N/A/N/A | Review queue lacked details and filtering | P+T | Y via workflow | Inventory + foundation | Added detail/filter; receive/inspect/post/return remain validated workflow/API |
| 22 | Integrations | Integration health | `/admin/integration-health` | Platform governance | Health adapters | Selected tenant/system | E | N/A/Y/N/A/N/A/N/A/N/A | None | P+T | N/A | Portal + browser | Intentionally read-only health view |
| 23 | Inventory | Inventory adjustments | `/admin/inventory-adjustments` | Inventory | `AdjustmentRequest` | Tenant/warehouse | C | API/Y/API/API/N/A/N/A | Review queue lacked details and filtering | P+T | Y via workflow | Inventory + foundation | Added detail/filter; request/approve/reject/post remain separation-of-duty workflow/API |
| 24 | Inventory | Inventory items | `/admin/inventory-items` | Inventory | `InventoryItem` | Tenant | A | Y/Y/Y/Y/Y/N/A | Form, create, archive, restore, detail, policy methods absent | P+T | Y | Foundation + inventory | Full audited create/edit/archive/restore with tenant-aware validation |
| 25 | Billing | Invoices | `/admin/invoices` | Finance | `Invoice` | Tenant | D | N/A/Y/N/A/Y/N/A/N/A | No detail/filter action | P+T | Y via credit-note workflow | Billing + foundation | Added detail/filter; posted invoices are immutable and corrected by credit note |
| 26 | Maintenance | Maintenance incidents | `/admin/maintenance-incidents` | Maintenance | `Incident` | Tenant/site | C | API/Y/API/API/N/A/N/A | No detail/filter action | P+T | Y via workflow | Maintenance + foundation | Added detail/filter; manual intake and validated recovery remain maintenance workflow/API |
| 27 | Master data | Master lists | `/admin/master-data` | Platform governance | 14 archivable master models | Platform reference | A | Y/Y/Y/Y/Y/N/A | Page was display-only | P+T | Y | Foundation Livewire | Added search, pagination, validation, edit, archive, restore and complete audit trail |
| 28 | Identity | Memberships | `/admin/memberships` | People and access | `Membership` | Tenant/organization/scope | B | API/Y/API/Y/N/A/N/A | Missing detail; lifecycle actions already existed | P+T | Y | Identity + foundation | Added read detail; activate/suspend use audited service and revoke tokens |
| 29 | Locations | Operating hours | `/admin/operating-hours` | Charging network | `SiteOperatingHour` | Tenant/site | B | API/Y/Y/API/N/A/N/A | Missing detail and explicit site authorization | P+T | Y | Location + foundation | Added detail and audited site-scoped edit; schedule creation remains site administration API |
| 30 | Organizations | Organizations | `/admin/organizations` | Platform governance | `Organization` | Tenant | B | Y/Y/Y/Y/Y/N/A | Direct status editing, no lifecycle service/filter/detail/policy | P+T | Y | Foundation + tenancy | Added tenant-safe create/edit, detail/filter, deactivate/reactivate service and audit |
| 31 | Payments | Payment intents | `/admin/payment-intents` | Finance | `PaymentIntent` | Tenant | D | N/A/Y/N/A/Y/N/A/N/A | No detail/filter action | P+T | Y via payment service | Payment + foundation | Added detail/filter; capture/void/refund remain idempotent provider workflows |
| 32 | Payments | Payment providers | `/admin/payment-providers` | Finance | `PaymentProviderConfig` | Tenant | E | N/A/Y/N/A/API/N/A/N/A | No safe detail action | P+T | N/A | Payment + foundation | Added masked read detail; configuration mutation remains environment/secret-manager workflow |
| 33 | Identity | Permissions | `/admin/permissions` | Identity and audit | `Permission` | System catalog | E | N/A/Y/N/A/N/A/N/A/N/A | No detail action | P+T | N/A | Identity + foundation | Added read detail; permission keys are code-owned |
| 34 | Maintenance | Preventive plans | `/admin/preventive-plans` | Maintenance | `PreventivePlan` | Tenant/site | B | API/Y/API/API/N/A/N/A | No detail/filter action | P+T | Y via maintenance workflow | Maintenance + foundation | Added detail/filter; plan authoring/activation remains maintenance API pending dedicated admin form |
| 35 | Procurement | Purchase orders | `/admin/purchase-orders` | Procurement | `PurchaseOrder` | Tenant | C | API/Y/API/API/N/A/N/A | Review queue lacked details and filtering | P+T | Y via workflow | Procurement + foundation | Added detail/filter; sourcing, approval, issue and receipt remain workflow/API |
| 36 | Procurement | Purchase requests | `/admin/purchase-requests` | Procurement | `PurchaseRequest` | Tenant | C | API/Y/API/API/N/A/N/A | Review queue lacked details and filtering | P+T | Y via workflow | Procurement + foundation | Added detail/filter; line authoring and approval matrix remain workflow/API |
| 37 | Settlements | Reconciliation lines | `/admin/reconciliation-lines` | Finance | `ReconciliationLine` | Tenant | D | N/A/Y/N/A/Y/N/A/N/A | No detail/filter action | P+T | Y via reconciliation | Settlement + foundation | Added detail/filter; mismatches resolve through reconciliation adjustments |
| 38 | Inventory | Reorder points | `/admin/reorder-points` | Inventory | `ReorderPoint` | Tenant/warehouse | B | Y/Y/Y/Y/Y/N/A | Display-only resource without policy or form | P+T | Y | Foundation + inventory | Added form, create/edit/detail, activate/deactivate and policy |
| 39 | Reporting | Reports | `/admin/reports` | Platform governance | Queued report/export services | Selected tenant | N/A | N/A/Y/N/A/Y/N/A/N/A | None | P+T | Y | Reporting + browser | Export actions remain queued, tenant-scoped and audited |
| 40 | Identity | Roles | `/admin/roles` | Identity and audit | `Role` | Tenant | B | Y/Y/Y/Y/N/A/N/A | No read-only detail; system-role edit needed blocking | P+T | Y | Identity + foundation | Added detail; audited create/edit retained; system roles and deletion blocked |
| 41 | Security | Security overview | `/admin/security-overview` | Platform governance | Security/session projections | Selected tenant | E | N/A/Y/N/A/N/A/N/A/N/A | None | P+T | N/A | Security + browser | Intentionally read-only security posture view |
| 42 | Charging | Session review queue | `/admin/session-reviews` | Charging network | `ChargingSessionReview` | Tenant/operator/site | C | N/A/Y/N/A/API/N/A/N/A | No detail/filter action | P+T | Y via workflow | Charging + foundation | Added detail/filter; cost/measurement decisions remain manual-review service/API |
| 43 | Settlements | Settlement batches | `/admin/settlement-batches` | Finance | `SettlementBatch` | Tenant | D | API/Y/N/A/API/N/A/N/A | No detail/filter action | P+T | Y via settlement workflow | Settlement + foundation | Added detail/filter; prepare/approve/submit retain maker-checker workflow/API |
| 44 | Locations | Sites | `/admin/sites` | Charging network | `Site` | Tenant/operator/site | B | Y/Y/Y/API/N/A/N/A | Create, detail, tenant validation and audit absent | P+T | Y | Location + foundation | Added create/edit/detail, tenant-safe relationships and audit; retirement remains lifecycle API |
| 45 | Inventory | Stock movements | `/admin/stock-movements` | Inventory | `StockMovement` | Tenant/warehouse | D | N/A/Y/N/A/Y/N/A/N/A | No detail/filter action | P+T | N/A | Inventory + foundation | Added detail/filter; immutable trigger blocks update/delete; corrections require movement |
| 46 | Inventory | Stock transfers | `/admin/stock-transfers` | Inventory | `StockTransfer` | Tenant/warehouse | C | API/Y/API/API/N/A/N/A | Review queue lacked details and filtering | P+T | Y via workflow | Inventory + foundation | Added detail/filter; submit/approve/dispatch/receive remain validated workflow/API |
| 47 | Support | Support tickets | `/admin/support-tickets` | Support | `SupportTicket` | Tenant/site | C | API/Y/API/Y/N/A/N/A | Resource was not registered in admin; no detail/filter | P+T | Y | Support + foundation | Registered in admin and added detail/filter; respond/escalate service actions retained |
| 48 | Tenancy | System settings | `/admin/system-settings` | Platform governance | Allow-listed tenant settings | Selected tenant | B | N/A/Y/Y/N/A/N/A/N/A | None | P+T | Y | Portal + browser | Audited allow-listed settings editor; secrets excluded |
| 49 | Tariffs | Tariffs | `/admin/tariffs` | Charging network | `Tariff` | Tenant/operator/site | B | Y/Y/API/Y/N/A/N/A | No detail/filter action | P+T | Y via tariff workflow | Tariff + foundation | Added detail/filter; published tariff snapshots remain immutable |
| 50 | Identity | Users | `/admin/users` | Identity and audit | `User` through tenant memberships | Tenant membership | B | API/Y/API/Y/N/A/N/A | No detail/filter; action visibility was broader than manage permission | P+T | Y | Identity + foundation | Added detail/filter; activate/suspend now requires membership management and revokes tokens |
| 51 | Procurement | Vendor invoices | `/admin/vendor-invoices` | Procurement | `VendorInvoice` | Tenant | C | API/Y/API/API/N/A/N/A | Review queue lacked details and filtering | P+T | Y via workflow | Procurement + foundation | Added detail/filter; match/discrepancy/approve/export remain workflow/API |
| 52 | Inventory | Warehouses | `/admin/warehouses` | Inventory | `Warehouse` | Tenant/site | B | Y/Y/Y/Y/Y/N/A | Display-only resource without form/lifecycle actions | P+T | Y | Foundation + inventory | Added tenant-safe form, detail/filter and activate/deactivate service |
| 53 | Maintenance | Work orders | `/admin/work-orders` | Maintenance | `WorkOrder` | Tenant/site/assignment | C | API/Y/API/Y/N/A/N/A | Missing details/filters; unsafe enum value surfaced in UI | P+T | Y | Maintenance + foundation | Added diagnostic detail and state-machine actions; incident/work-order enum regression covered |
| 54 | Platform | Network map | `/admin/network-map` | Platform governance | Live network projection | Selected tenant/site | E | N/A/Y/N/A/N/A/N/A/N/A | None | P+T | N/A | Portal + browser | Intentionally read-only operational map with scoped detail links |

## Explicitly deferred admin authoring surfaces

The following operations are not represented as ordinary Filament CRUD because
their existing application contracts require multi-line payloads,
maker-checker separation, OCPP confirmation, or immutable compensating
transactions. They remain available through the owning, tested versioned API;
adding a partial table action would bypass domain validation.

| Resource | Missing admin operation | Existing owning contract | Risk | Recommended next action |
|---|---|---|---|---|
| Charging station, EVSE, connector, operating hour, site | Retire or create nested hierarchy records from every list | Assets/Locations administration API | Medium: administrators switch to API for less common hierarchy operations | Add nested site/station relation managers using the existing application commands |
| Purchase request/order/vendor invoice | Line authoring and approval forms | Procurement workflows and API | Medium: portal is a review queue rather than full procurement workstation | Build state-specific resource pages with line relation managers and maker-checker tests |
| Goods receipt/count/adjustment/transfer | Entry, inspection, approval, posting and receiving forms | Inventory workflows and API | Medium: warehouse staff use API clients for mutations | Build mobile-responsive wizard pages; never write stock quantities directly |
| Incident/preventive plan | Manual intake, recovery and plan authoring | Maintenance intake/scheduler API | Medium: maintenance configuration is not fully portal-managed | Add dedicated forms that reuse intake/scheduler services |
| Finance/session review/settlement | Resolve, prepare, approve, submit | Payment, charging review and settlement services/API | High if implemented casually: financial or CDR history could be mutated | Add role-separated actions with idempotency, reason capture and compensating records |

These are marked `Deferred with documented reason` in the final coverage
matrix; they are not described as complete CRUD.
