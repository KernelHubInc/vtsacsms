# CSMS production release checklist

Use the current [release promotion runbook](release-promotion.md). The older two-staging-node deployment instructions do not match the current topology. This checklist describes an existing installation; new-server provisioning needs the separate infrastructure runbooks.

## 1. Release identity and readiness

- Record full source SHA, tree SHA, baseline production release, CI URL and change owner.
- Fetch `test_env` and `main`. Review the complete difference and all migrations. Never force push or include unrelated workspace files.
- Require successful platform, mobile, gateway, contracts, KYC, container and deployment-configuration jobs, plus all four release-image publish jobs, for the exact SHA.
- Review Composer/npm/Python advisories, dependency diffs, image SBOM and OS/base-image scan results. Distinguish runtime findings from development-only tooling.
- Verify tenant isolation, authorization, duplicate callbacks, monetary arithmetic and charging retry behavior in the changed paths. Retain the before-fix regression result for security fixes.
- Keep pending device/OAuth/payment acceptance visible; never equate a green build with business-launch approval.

## 2. Infrastructure and recovery

- Use the existing load-balancer console and pinned cluster SSH keys. Never copy private keys or print protected environment files.
- Confirm both production nodes are healthy and serving the baseline release; App01 owns worker, charging-worker and scheduler. App02 production is web-only; its separate staging consumers stay running.
- Review disk space, image availability, queue lag, failed jobs, active charging sessions and worker retry/timeout settings. Avoid deployments during a supervised physical charge.
- Verify environment separation: distinct databases/roles, Redis ports/prefixes, keys, buckets, sessions and integration tokens; same production key/session configuration across production nodes.
- Validate TLS and Docker-aware firewall rules. Data services and KYC ingress must not be publicly accessible. Check actual service binds rather than assuming a host firewall covers published Docker ports.
- Retain the previous images, source revision and mobile symlink target. Confirm independent encrypted off-host backups and the latest isolated restore evidence. A new pg_dump alone is not a tested recovery plan.
- Before the MFA fix, review counts of MFA-required panel accounts and approved recovery access. Do not weaken account requirements.

## 3. Staging deployment

On the load balancer, replace the example below with the verified full source SHA:

```bash
SOURCE_SHA=FULL_VERIFIED_TEST_ENV_COMMIT
sudo vtsa-release-cluster staging "sha-$SOURCE_SHA" --check
sudo vtsa-release-cluster staging "sha-$SOURCE_SHA"
```

The placeholder is intentionally not executable as a valid release. The script rejects malformed SHAs. It preserves legacy staging consumers. The deployer takes a pre-migration custom-format PostgreSQL dump, applies migrations and permissions, then replaces the application. Verify its nonzero exit and state marker, not only browser output.

- Check readiness, normal login, MFA-required denial, operator scoping, driver recovery and private export download. Run tenant-A/tenant-B negative tests using synthetic accounts.
- Verify the additive Google subject migration without enabling Google. Production needs its own approved OAuth clients and Android signing fingerprints. Preserve the new column when rolling application code back.
- Test new charging behavior with the authorized physical procedure before enabling it in production. Do not create fabricated meter/settlement evidence.
- Verify staging backend SHA and the mobile release manifest, bundle hash and API origin. A mobile source SHA can differ from a backend marker; investigate instead of assuming parity.

## 4. Merge and image publishing

```bash
git fetch origin main test_env
git diff --stat origin/main...origin/test_env
gh pr create --base main --head test_env --title "Promote audited CSMS release" --body-file RELEASE_DESCRIPTION_FILE
```

Use the actual prepared PR description file. Resolve required reviews/checks through normal repository controls; do not bypass branch protection. Merge only the staged tree. A merge commit can have a different SHA, so wait for main CI/image publishing and compare source trees before deployment. Record the merge SHA and PR URL.

## 5. Production deployment

Run on the load balancer after final main CI/image publishing:

```bash
MAIN_SHA=FULL_VERIFIED_MAIN_COMMIT
sudo vtsa-release-cluster production "sha-$MAIN_SHA" --check
sudo vtsa-release-cluster production "sha-$MAIN_SHA"
```

The cluster script compares the staged tree, checks both nodes and public readiness, deploys/migrates App01 once, verifies it, then deploys App02 without production jobs. It uses a shared lock. Stop on any failure. The app deploy helper and source checkout paths must match the reviewed release scripts. Never reset App02's legacy staging checkout.

Preserve existing feature flags and secrets. The app release does not automatically update the edge-hosted Flutter browser bundle, native Android/iOS distribution, separate gateway runtime or KYC processor. Each changed surface needs its own reviewed deployment/compatibility decision. No KYC/gateway source changed in this audit fix.

## 6. Flutter browser release

Build from the reviewed source with production configuration, an approved production tenant and `/mobile/` base path. Obtain the real tenant from protected existing configuration; do not guess or copy staging values.

```bash
flutter pub get
flutter build web --release --base-href /mobile/ --dart-define-from-file=PROTECTED_PRODUCTION_DEFINES_FILE
```

Confirm the defines include `APP_ENVIRONMENT=production` and the approved production API origin. Include required existing map/push settings and leave unapproved Google clients disabled. Package only the built files and a safe release manifest containing source/backend SHA, environment and main.dart.js SHA256; include no credentials. Transfer to the edge using existing authorized access, validate archive paths/types/checksum, unpack into a new release directory, test Nginx and atomically replace the `current` symlink. Retain the previous target.

## 7. Post-deployment acceptance

- Confirm exact backend image tags/digests on both nodes and the staging/production tree comparison. Confirm only App01 owns production jobs.
- Read `/health/live` and `/health/ready` on public endpoints and both direct nodes from the load balancer. Readiness includes configured Redis and PostgreSQL; it does not prove SMTP, queue consumers, object storage or charging.
- Verify admin/operator login and scoping, rejected MFA-required access, public stations, mobile assets/nested routes, account recovery and private report authorization. Use synthetic accounts for any mutations.
- Check the mobile manifest and served main.dart.js hash; verify production API origin and absence of staging tenant/client settings. A release manifest alone is not binary provenance.
- Inspect redacted errors, queue lag, failed jobs and resource health after activation. Never drain/replay unrelated queues or force a charging session to complete.
- Record backup path, prior/current SHAs and image IDs, migration result, timestamps, evidence and unresolved decisions in the release record.

## 8. Rollback and stop conditions

Stop if backups fail, migrations fail, staged/main trees differ, required checks fail, a node is unhealthy, wrong-environment config appears, MFA access is weakened or wallet/charger evidence is inconsistent. Retain the healthy peer; inspect failed-node rollback before changing it again.

The app deployer attempts prior-image restoration on replacement health failure. It does not automatically reverse database migrations. Keep additive schema compatible, roll forward where required and never run migrate:fresh or a destructive down migration on production. If the browser release fails, atomically restore the prior known-good symlink and verify its assets. Database restore requires incident ownership, impact analysis and an approved recovery procedure; it is not a routine release command.
