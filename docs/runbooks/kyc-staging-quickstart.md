# Run the KYC processor on Hostinger VPS3

This starts only `vtsa-kyc-staging`: Python API, worker, scheduler, Redis and private TLS ingress. It uses the dedicated staging KYC database on VPS4 and an existing private S3-compatible bucket. PostgreSQL, storage, certificates, Laravel and the mobile app are not installed by this command. Existing production containers are not selected.

## 1. Transfer the code

Use a checkout containing these changes, or upload the supplied **source-only** `vtsa-kyc-staging.tar.gz` and its `.sha256` file to VPS3. The bundle contains the processor, migration files, model installers, deployment tools and these instructions. It contains no `.env`, private evidence, credentials, models, Git history, Laravel or Flutter build.

On VPS3, in the directory containing the uploaded files:

```bash
sha256sum --check vtsa-kyc-staging.tar.gz.sha256
mkdir -p "$HOME/kyc-staging-test"
tar -xzf vtsa-kyc-staging.tar.gz -C "$HOME/kyc-staging-test"
cd "$HOME/kyc-staging-test/vtsa-kyc-staging"
```

Use a new extraction directory for subsequent bundles rather than overlaying older source. On a repository checkout, simply `cd` to its root instead. No Git commit or GHCR login is needed for this staging path. The current local work must be transferred; fetching an older remote branch will not include it.

## 2. Prepare configuration once

Requires a Linux VPS with Docker Engine, Docker Compose v2, Python 3.9+, Bash, `flock`, `ip`, and sudo. The model downloads run in a Python 3.12 container. Use the host's existing Docker installation; this launcher does not reinstall Docker or change its daemon/firewall configuration.

```bash
sudo bash scripts/kyc-staging.sh prepare
sudoedit /etc/vtsa-csms/kyc-staging.env
```

`prepare` creates a mode-600 environment file, records this VPS's hostname, generates independent request/callback/encryption secrets, supplies pinned model paths/digests and creates `/etc/vtsa-csms/kyc-models-staging`. It refuses to overwrite an existing file, so reruns cannot rotate secrets or lose an encryption key. Back up the encryption key securely with the database/evidence.

Fill these infrastructure values using your actual staging setup:

| Setting | Value to supply |
| --- | --- |
| `KYC_INTERNAL_HOST` | Private DNS name resolving only to VPS3's private IPv4 address |
| `KYC_PRIVATE_BIND` | That assigned private IPv4 address; never the public VPS IP |
| `KYC_PRIVATE_PORT` | Defaults to 8443; choose an unused private port |
| `KYC_ALLOWED_CIDRS` | Staging Laravel source IPs/private CIDRs, including its actual Docker NAT source if needed |
| `KYC_TLS_CERT`, `KYC_TLS_KEY` | Absolute paths to the matching private ingress certificate and key |
| `KYC_CA_BUNDLE` | Absolute path to the CA bundle trusting PostgreSQL, private Laravel, storage and ingress |
| `KYC_DATABASE_URL` | `postgresql+psycopg://vtsa_kyc_staging:<URL-encoded-password>@<private-db-DNS>:5432/vtsa_kyc_staging?sslmode=verify-full&sslrootcert=/run/kyc/ca.pem` |
| `KYC_CALLBACK_URL` | `https://<private-staging-Laravel-DNS>/api/v1/webhooks/kyc` |
| `KYC_S3_ENDPOINT`, `KYC_S3_BUCKET` | Private HTTPS self-hosted storage endpoint and dedicated staging evidence bucket |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | Credentials restricted to that bucket |

Provision the dedicated database/role and bucket first. VPS3's existing private access to VPS4 is sufficient; this command does not open the database firewall. The backup client is PostgreSQL 18, matching the repository; verify compatibility with your actual server. Configure bucket lifecycle/backup retention as described in [the full Hostinger runbook](kyc-hostinger.md). Keep the generated retention aligned with the Laravel consent policy. Single-quote dotenv values containing `$`; never `source` this file or paste its contents into logs/chat.

Trial face accept/reject values are `0.5 / 0.2`, PAD values `0.9 / 0.1`, and center/turn nose-to-eye ratios `0.1 / 0.3`. These are **uncalibrated starting values for manual-review staging experiments**, not measured accuracy or production approval criteria. Adjust using consented test subjects and supported devices. The production template still has no default thresholds.

## 3. Connect the existing Laravel staging application

Deploy the updated Laravel KYC code and additive migrations to staging through your normal app deployment. In the protected `/etc/vtsa-csms/staging.env`, set these keys once (replace existing keys; do not append duplicates):

```dotenv
DEPLOY_ENVIRONMENT=staging
KYC_ENABLED=false
KYC_ASSURANCE_PROFILE=optical_v1
KYC_AUTOMATIC_VERIFICATION_ENABLED=false
KYC_SERVICE_URL=https://<same-KYC_INTERNAL_HOST>:8443
KYC_REQUEST_SECRET=<copy from protected kyc-staging.env>
KYC_CALLBACK_SECRET=<copy from protected kyc-staging.env>
```

Match the port if changed. Keep this file mode 600. Set the existing `KYC_CONSENT_VERSION`, `KYC_CONSENT_TEXT` and `KYC_RETENTION_DAYS` to your reviewed staging policy before enabling collection. Laravel must trust the ingress certificate through its container CA trust store. Its private `/health/ready` and callback routes must be reachable from the KYC containers, with the correct TLS certificate. Do not point callbacks at the production application.

The source launcher rejects an enabled automatic-approval switch. It also checks matching secrets, staging names, private-only DNS, assigned bind address, allowed CIDRs and certificates. Existing production KYC configuration is compared when present alongside the staging file. It never edits the Laravel environment or restarts Laravel containers.

## 4. Install models, check and start

```bash
sudo bash scripts/kyc-staging.sh models
sudo bash scripts/kyc-staging.sh check
sudo bash scripts/kyc-staging.sh up
```

`models` downloads the three pinned upstream models with checksum validation and retained licenses; applicant data is never sent to the download hosts. An existing model with a different hash is rejected.

`check` builds a local Docker image from an allowlisted source snapshot, tags it with the SHA-256 of that snapshot, pulls infrastructure images, and checks model loading, verified TLS, database connectivity, bucket access and Laravel readiness. It creates disposable containers but does not migrate the database or replace running services. Failed checks stop the command; they are not bypassed. Initial building/downloading needs outbound internet and free RAM/disk alongside your production workload. Build off-peak and check capacity first.

Connectivity failures report the first failing stage (`configuration`, `models`, `database`, `storage`, or `Laravel readiness`) and exception class. Later stages have not been checked. Exception messages and tracebacks remain suppressed because they can include credentials, connection strings or sensitive configuration values. Bucket checks use `HeadBucket`; they do not establish that encrypted object uploads or HMAC callbacks succeed.

`up` repeats preflight, takes a protected PostgreSQL backup, applies Alembic migrations, starts staging containers and waits for their health checks. Both commands share the app deployment lock. A failed backup prevents migration. A failed rollout exits nonzero; it does not automatically undo additive schema changes. Backup/error files are protected under `/var/backups/vtsa-csms/kyc-staging`. Re-running `up` reuses persistent database/evidence and does not delete volumes. Docker layer caching avoids reinstalling unchanged dependencies.

Older transfer bundles may fail with `invalid mount path: 'mode=1777'`: YAML splits the unquoted comma in `tmpfs: [/tmp:size=128m,mode=1777]` into two mounts. In `infra/cluster/compose.kyc.yaml`, replace it with `tmpfs: ["/tmp:size=128m,mode=1777"]` or a block list containing that single string. The Caddy `kyc-ingress` service also needs `cap_add: [NET_BIND_SERVICE]` alongside `cap_drop: [ALL]` because the official executable carries that file capability; otherwise execution can fail with `operation not permitted`. Preserve private bind addresses, TLS verification and any deployment-specific DNS overrides when patching an extracted bundle, then rerun `check` before `up`.

For a repository checkout the equivalent low-level command is `sudo bash scripts/deploy-kyc.sh staging source`. `production source` is rejected. Production still requires a committed, published immutable release.

## 5. Exercise the flow

### Staging participation notices

For invited adult staging testers, the application provides `/staging/kyc/privacy`,
`/staging/kyc/terms` and `/staging/kyc/consent`. They are independent of CMS seed data,
available before enabling KYC, marked noindex/no-store, and return 404 outside
`APP_ENV=staging` or the existing VPS3 `APP_ENV=demo`. They do not overwrite the
production privacy/terms pages or claim production legal approval. Identity owns
this versioned test participation notice; CMS remains the owner of production
policy content. The operator authorized staging test notices on 2 October 2026.

Use consent version `staging-optical-2026-10-02-v1` and the exact consent text in
`StagingKycPolicyController::CONSENT`. This version is rejected at startup in
production and when automatic approval is enabled. The existing HTTPS and secret
checks still apply; an empty consent version is also rejected. The displayed
evidence retention comes from `kyc.retention_days`; it must match the processor.
Prefer synthetic fixtures; real-data tests require consenting invited adults and
the test coordinator's authorization. Define test-end cleanup and backup retention
before real-data collection. Cancellation schedules evidence erasure and does not
claim to delete audit records or backup copies immediately.

The supplemental `vtsa-kyc-staging-notices.tar.gz` bundle contains a checked source
patch, `enable-staging-kyc.py`, and the existing dotenv validator. Extract it in a
new directory, then run `python3 enable-staging-kyc.py /opt/vtsa-csms` as root on VPS3.
It requires the existing `vtsa-csms-staging` project using `infra/compose.yaml`,
`DEPLOY_ENVIRONMENT=staging`, matching processor secrets, and an HTTPS `APP_URL`.
It preserves the current Compose file list (including private DNS overrides),
checks the patch before touching source, backs up `.env.staging` with mode 600,
fills the three URLs/consent/version, matches processor retention, builds only
platform/worker/scheduler, and tests bootstrap in a disposable container before
recreating those three services. It checks effective configuration in each service,
the HTTPS notice bodies, and private KYC readiness. No migration, permission grant,
database/container-volume deletion or processor redeploy is performed. A source
conflict stops the deployment rather than overwriting VPS edits. Build/deployment
failures attempt to restore app availability with KYC disabled; inspect the result.

Rollback: set `KYC_ENABLED=false` in the protected app environment, then recreate
the same three services using the same Compose file list. Keep the additive notice
code and existing evidence. The protected pre-change env backup is for recovery;
do not publish it. The patch and these settings must remain in subsequent releases.
The helper does not grant admin roles or complete a real-device submission: verify
tenant permissions, manual review, request signatures, callbacks and evidence
viewing in the next staging flow test.

After checks pass, set `KYC_ENABLED=true` in **staging** and redeploy/recreate only the staging Laravel app, workers and scheduler so they receive the new settings. Select **Manual** in web admin KYC settings. Use the updated mobile client configured with the **staging Laravel API URL**, then test consent → supported ID upload → live camera prompts → submit → admin review. The mobile app never connects directly to the processor. The earlier local debug APK targets a local emulator API and must be rebuilt/reconfigured for your staging API.

Inspect container health without displaying protected environment values:

```bash
sudo docker ps --filter label=com.docker.compose.project=vtsa-kyc-staging \
  --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
```

Only the ingress should show `<private-IP>:8443->8443`; API/Redis must have no published host port. Do not add a public load-balancer upstream or open public port 8443. Verify access from the actual staging Laravel container and denial from an unauthorized source; Docker-aware host firewall policy still applies. A healthy processor is not proof of successful HMAC callbacks or biometric accuracy: complete a staging submission and review its synchronized status/admin evidence before accepting the integration.

This bundle does not deploy anything remotely by itself. Device/spoof evaluation and reviewed thresholds remain prerequisites for automatic approval. Optical checks do not establish government issuance.

## Rebuild a transfer bundle

From this repository's root on the development machine:

```bash
python scripts/kyc-staging.py package .cache/kyc-staging/vtsa-kyc-staging.tar.gz
```

The output filename must be new. To use nonstandard protected configuration locations, pass absolute `VTSA_KYC_ENV_FILE` and `VTSA_APP_ENV_FILE` values to `sudo env ... bash scripts/kyc-staging.sh ...`. Preparation requires the filename `kyc-staging.env`; host/environment checks still apply. Configuration is read as dotenv, never executed as shell code.
