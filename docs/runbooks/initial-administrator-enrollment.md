# Initial administrator enrollment

Run the bootstrap only for an active tenant with no existing platform-panel assignment and two new, owner-approved identities. Obtain approval for the exact email recipients, organization, roles, tenant scope and persistent access before running the command. Existing pending enrollments can use the issuance command below. Use ordinary access management for active accounts.

## Release checklist

1. Review ADR-0032 and the exact 67/27 templates in `AdministratorBootstrap`. Run the security feature tests, full configured quality suite, dependency checks and Vite production build.
2. Merge into `test_env`, wait for CI, deploy and verify health and the isolated `/workforce/setup` page. Promote the same Git tree to `main`, wait for CI, then deploy production through the cluster release helper.
3. Verify a restorable database backup. Apply the additive `2026_11_11_000001_create_administrator_enrollment` migration once on the primary node. Preserve existing runtime secrets, feature flags, workers and tenant data.
4. Confirm `APP_URL` is the intended HTTPS origin. For email enrollment, confirm private SMTP delivery and that a worker consumes `invitations`.
5. Recheck target tenant activity, absent email identities, organization state and absence of platform-panel assignments. Execute the approved command on the production primary application container.

```sh
php artisan security:bootstrap-administrators \
  --tenant=<approved-tenant-ulid> \
  --super-admin=<approved-super-email> \
  --admin=<approved-admin-email> \
  --organization='<approved-platform-organization>' \
  --approval-reference='<owner-approval-reference>' \
  --confirm-bootstrap --manual-setup
```

The command prints only public result identifiers. It creates an immutable `production-super-administrator` role with 67 permissions and an editable `production-administrator` role with 27 permissions, both tenant-scoped. The restricted template includes membership/invitation/role/session management, audit access and module views. A vocabulary change requires review before bootstrap can run. Use `--manual-setup` for a new approved tenant pair when setup email is unnecessary. For a pair already bootstrapped, skip straight to issuance. Use the existing staging accounts for training. Production is reserved for approved operational accounts and live data.

## Private owner setup

For each pending account, run this in your own private interactive terminal inside the application container for the intended environment. Use `docker exec -it` to enter the container or run the command directly with it. Redirected and noninteractive use is rejected.

```sh
php artisan security:issue-workforce-temporary-password \
  --tenant=<approved-tenant-ulid> \
  --email=<approved-account-email> \
  --approval-reference='<owner-approval-reference>'
```

The command displays one generated temporary password once. Deliver it to that recipient using your approved private credential-sharing channel. Issuance replaces the previous setup credential and works only for a pending, enabled enrollment in the selected active tenant. The recipient opens `/workforce/setup` on that environment, enters their account email and temporary password, and sets a different personal password of at least 12 characters with upper/lowercase, a number and a symbol. The account activates only after this step. Use the personal password at `/admin/login`. Manual setup does not establish email verification. Issue a replacement if the credential expires or is misplaced.

### Email enrollment

Each recipient receives **Set up your Power Solutions administrator password**. Open the link from the intended email inbox within 24 hours, enter a display name, choose a unique password of at least 12 characters with upper/lowercase, a number and a symbol, and confirm it. Complete those steps privately; never send the password or link to an operator or chat. The setup page does not sign you in. Open `/admin/login` on the same production origin and sign in with that email and password.

Accounts remain inactive until successful setup. Used, expired or replaced links cannot activate an account. Existing MFA requirements remain enforced; an unavailable MFA provider is not worked around by clearing flags. SMTP acceptance is a delivery signal, not a guarantee that the message reached the inbox. Check spam/quarantine if necessary.

## Verify and recover

Read back only public subject IDs, email recipients, role keys, 67/27 grant counts, tenant scope, activation/verification booleans, delivery timestamps and audit references. Verify the two memberships, two assignments and approved platform organization. Never query or display password hashes, token verifiers or raw job/failed-job payloads for a report.

Repeating the exact command is idempotent and sends no duplicate email. Add `--resend-pending` only when a new email is needed; this replaces unfinished links and skips completed or disabled identities without recreating or broadening grants. A changed plan is rejected. If enqueue/SMTP fails, repair delivery first and resend. Do not recover tokens manually.

`security:expire-workforce-enrollments` clears expired verifiers and runs daily under the scheduler. Used verifiers are cleared immediately. Retain enrollment, bootstrap and audit evidence when rolling back application images. Do not drop the additive tables or silently revoke/restore grants as a rollback substitute. After enrollment, manage revocation through audited membership and role controls.

## Using editable roles

After signing in as an authorized administrator, open **Identity and audit → Roles**, create or edit a custom role, and select the required functions under each module. Clear every permission in a module to remove that module's access. Save, then assign the role under the user's membership with the intended tenant, organization or site scope. Review the assignment impact before saving. System roles are immutable; create a custom role when different access is needed. Multiple role assignments combine permissions, so remove other assignments that still grant an unwanted module. Server authorization enforces the same permissions as navigation visibility. The restricted administrator can delegate only permissions already held at the applicable scope.
