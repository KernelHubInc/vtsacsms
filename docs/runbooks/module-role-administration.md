# Module and function access for custom roles

## Configure a role

1. Sign in to `/admin` with platform-panel access, `identity.roles.view` and tenant-wide `identity.roles.manage`. You must already hold every permission you intend to include.
2. Open **Identity and audit → Roles**. Create a custom role or edit an existing custom role. System roles are protected.
3. Set a readable name and a tenant-unique key. Expand **Portal access** and select the operator or administration panel if this role should permit panel entry. A separate assigned role may also supply panel entry.
4. Expand each required module and select its individual functions. Opening a section grants no access. Select **View** where available to open that module's lists and navigation; action grants may need additional view permissions to use their portal screens. Functions reflect the permissions actually enforced by the application; some modules currently have one broad **Manage** function rather than separate create, update and delete grants.
5. Use **Remove module** to clear that module's functions. Refunds, remote commands, approvals, publishing and exports are individually selected; no module-wide enable action automatically grants them.
6. Before saving an edit, review the assigned membership/assignment counts beneath the page title. The edit applies to everyone assigned that role, including expired assignments if later renewed. Retain a separate administrator grant if you need continued role-management access.
7. Save. The audit trail records the previous and resulting permission lists. A role with zero grants is valid and supplies no access.

## Assign the role

Open **Operator users → Assign role** in the existing user-management screen. Select the membership, custom role and supported tenant or site scope. The grantor must hold assignment authority and all role permissions at that scope. Existing assignments need no replacement after a role edit.

Users can have multiple roles. Grants combine: removing Inventory from one role does not hide Inventory while another active assignment still grants it. Review every assigned role when removing access. Panel entry and the business modules are independent grants.

## Verify the result

With a representative affected user, reload the panel. Modules without authorized navigation entries disappear, including empty navigation groups. Try a bookmarked URL for a removed module: the server must deny it. Check view, mutation and export access separately, and verify a site-scoped user cannot see another site's records.

The landing dashboard remains available to panel users and shows its existing no-access state when reporting permissions are absent. Permission groups can include capabilities reserved for future workflows; selecting them does not create an unimplemented screen. Removing grants blocks subsequent authorized requests; it does not erase already downloaded files or screen content.

## Deployment and recovery

This change uses the existing `roles`, `role_permissions` and `role_assignments` tables. No migration or reseeding is required, and existing role grants are preserved when the editor opens them. Deploy through the normal application release procedure; do not edit production role tables directly.

To undo a role change, an authorized administrator restores the intended functions from the audit evidence and saves another audited change. Restoring application code does not undo permission changes already saved. Keep normal database backups and a separately authorized administrator account available.

Regression coverage: `RoleModulePermissionsTest` verifies explicit grants, removal from both panels, direct URL denial, additive roles, non-escalation, system-role protection, tenant isolation and catalog completeness.
