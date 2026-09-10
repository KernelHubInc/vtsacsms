# Permission Matrix

**Status:** Phase 3 normative RBAC baseline  
**Applies to:** Authenticated tenant workforce and future machine clients  
**Rule:** Roles are tenant-owned permission bundles. Enforcement uses permissions and scopes, never role names.

## 1. Evaluation Contract

An action is allowed only when all conditions hold:

```text
enabled identity
AND active tenant
AND active, unexpired membership
AND active, unexpired role assignment
AND exact permission
AND compatible tenant/organization/resource scope
AND token ability, when token-authenticated
AND owning-context state and high-risk controls
```

Absence, invalidity, or ambiguity denies access. Tenant-scope assignments cover tenant resources. Resource-scope assignments cover only the exact scope type and ULID; organization hierarchy inheritance is not implied in Phase 3. A token ability reduces authority and never creates it.

## 2. Permission Catalog

The code-owned catalog is [`PermissionKey`](../../apps/platform/app/Modules/Organizations/Domain/PermissionKey.php). `permissions` is a synchronized global catalog; `roles`, `role_permissions`, and `role_assignments` are tenant-owned.

| Capability group | Permission keys | Typical scope |
| --- | --- | --- |
| Identity context | `identity.context.view` | Tenant |
| Membership administration | `identity.memberships.view`, `identity.memberships.manage` | Tenant or organization |
| Role administration | `identity.roles.view`, `identity.roles.manage`, `identity.roles.assign` | Tenant or delegated resource |
| Credential lifecycle | `identity.tokens.issue`, `identity.tokens.revoke` | Tenant |
| Audit | `audit.events.view`, `audit.events.export` | Tenant |
| Tenant settings | `tenancy.settings.view`, `tenancy.settings.manage` | Tenant |
| Organizations | `organizations.view`, `organizations.manage` | Tenant or organization |
| Locations and assets | `locations.*`, `assets.*` | Tenant, organization, location, or asset group |
| Charging operations | `charging.sessions.view`, `charging.remote_commands.execute` | Tenant, organization, location, or asset group |
| Tariffs | `tariffs.view`, `tariffs.manage`, `tariffs.publish` | Tenant or finance scope |
| Payments and billing | `payments.view`, `payments.refunds.execute`, `billing.view` | Tenant or finance scope |
| Settlements | `settlements.view`, `settlements.prepare`, `settlements.approve` | Tenant or finance scope |
| Procurement | `procurement.view`, `procurement.manage`, `procurement.approve` | Tenant or organization |
| Inventory | `inventory.view`, `inventory.operate`, `inventory.adjust` | Tenant or warehouse |
| Maintenance | `maintenance.view`, `maintenance.dispatch`, `maintenance.perform`, `maintenance.verify` | Tenant, location, or asset group |
| Support | `support.view`, `support.manage`, `support.sensitive_reveal` | Tenant or support queue |
| Reporting | `reporting.view`, `reporting.export` | Same row/resource scope as source data |
| CMS | `cms.edit`, `cms.publish` | Tenant or organization |
| Integrations | `integrations.view`, `integrations.manage` | Tenant |

The catalog reserves permissions for future bounded contexts without implementing their workflows.

## 3. Persona-to-Capability Analysis

Legend: **V** view, **M** manage/operate, **A** approve/high risk, **—** no baseline grant. Each mark remains subject to the scope and separation rules below.

| Persona | Identity / tenant | Operations | Finance | Field supply / maintenance | Support / content / reports |
| --- | --- | --- | --- | --- | --- |
| Tenant owner | M | V/M by explicit assignment | V by explicit assignment | V by explicit assignment | Audit V; reports V |
| Tenant administrator | Membership M; role M/assign; settings M | Organization/location/asset M | — | — | CMS settings M; audit V if granted |
| Organization administrator | Scoped membership M; role assign within held grants | Organization/location/asset V/M | — | — | Scoped reports V |
| Network operations controller | Context V | Asset V; sessions V; remote command A | — | Maintenance V | Operational reports V |
| Location manager | Context V | Scoped location M; asset/session V | — | Incident/maintenance V | Scoped reports V |
| Tariff manager | Context V | Tariff M/publish only when separately granted | Rated-charge V | — | Reports V |
| Finance operator | Context V | Session V where needed | Payment/billing V; refund A within policy | — | Finance reports/export by grant |
| Settlement analyst | Context V | — | Settlement V/prepare | — | Finance reports V |
| Settlement approver | Context V | — | Settlement V/approve A | — | Audit evidence V |
| Procurement requester/buyer | Context V | — | Commercial values only in procurement | Procurement M; approve separate | Procurement reports V |
| Procurement approver | Context V | — | Approved commitment evidence | Procurement A | Audit evidence V |
| Warehouse operator | Context V | Asset references V | — | Inventory V/operate | Warehouse reports V |
| Inventory controller | Context V | — | Valuation only if granted | Inventory M/adjust A | Inventory reports V |
| Maintenance dispatcher | Context V | Asset/location V | — | Maintenance M/dispatch | Maintenance reports V |
| Field technician | Context V | Assigned asset V | — | Maintenance perform; inventory issue only when granted | Assigned evidence only |
| Maintenance verifier | Context V | Asset V | — | Maintenance verify A | Audit evidence V |
| Support agent | Context V | Masked session/location V | Masked payment V | — | Support M; sensitive reveal A separately |
| Support supervisor | Context V | Scoped operational V | Exception actions only by separate permission | — | Support M; reports V |
| Content editor | Context V | Published facts V | — | — | CMS edit |
| Content publisher | Context V | Published facts V | — | — | CMS publish A |
| Reporting analyst | Context V | Read projections within source scope | Read approved projections | Read approved projections | Reporting V/export A |
| Tenant auditor | Context V; membership/role V | Read-only evidence | Read-only masked evidence | Read-only evidence | Audit/report V |

These are recommended templates, not automatic grants. Tenant provisioning and template materialization are deferred until lifecycle policy is approved.

## 4. Delegation Rules

- `identity.roles.assign` permits the assignment operation but does not permit privilege creation.
- The grantor must hold every permission in the target role at the target scope.
- The target membership, role, assignment, and referenced organization must resolve under the same active tenant.
- A resource-scoped grant cannot create tenant-wide authority.
- Assignment expiry is evaluated on every authorization decision; removing or expiring an assignment invalidates subsequent API actions without reissuing the token.
- Tenant, membership, identity, assignment, and token suspension/revocation are independent denial switches.

## 5. Separation of Duties

The model supports separate permissions for tariff manage/publish, settlement prepare/approve, procurement manage/approve, maintenance perform/verify, and future refund request/approval. Exact conflicting-role rules, approval thresholds, MFA freshness, and second-actor workflow are open tenant/market decisions and are not claimed as implemented.

## 6. Platform Access

Platform operations, security, and support identities do not receive tenant roles by implication. Time-bounded platform access grants, emergency access, review, tenant notification, and platform-role permissions remain a separate future implementation. Direct use of tenant-table scope bypasses is restricted to reviewed authentication, migration, and platform scheduling boundaries.
