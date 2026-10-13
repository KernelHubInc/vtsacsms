# Mobile staging demo: registration and email verification

## Download links for every mobile release

Every requested mobile release must return both links after verification:

- Versioned APK, retained unchanged: `https://staging.evcspowersolutions.com/demo/Power-Solutions-Staging-Lumina-<version>.apk`
- Latest demo APK: `https://staging.evcspowersolutions.com/demo/Power-Solutions-Demo.apk`

The demo URL points to the latest verified versioned APK and requires cache revalidation. Both downloads use the same staging build and backend; the demo link is not a separate simulated app.

On 2026-10-13 both links serve version 1.0.16 (79,496,802 bytes). Both returned HTTP 200 with `Cache-Control: no-cache`. Nginx configuration passed validation before its graceful reload; older versioned APKs remain available.

Package the APK and its `.sha256` file under `.cache/mobile-staging-lumina/`, then run:

```powershell
.\scripts\publish-staging-apk.ps1 -Version 1.0.16 -Publish
```

The publisher refuses to replace an existing version with different bytes and advances the demo alias only after verifying the versioned download. Omit `-Publish` for local checksum verification. Historical instructions below describe their original releases; use this version parameter for new releases.

## QR Ph payment label and recovery (1.0.11 / Android code 12)

The payment card labels the code QR Ph and gives scan/import guidance. The text
sits outside the QR with a white margin; no image is overlaid and the provider
payload is unchanged. Unknown/review requests without a code explain recovery
and stop automatic polling. A manual refresh checks the same request.

Build from `apps/mobile` after completing tests:

```powershell
flutter build apk --release --no-pub --build-name=1.0.11 --build-number=12 --dart-define-from-file=dart_defines.staging-demo.json
```

Package as `.cache/mobile-staging-lumina/Power-Solutions-Staging-Lumina-1.0.11.apk`
with its `.sha256` file, then use `scripts/publish-staging-apk.ps1 -Publish`.
The staging build retains real-wallet capability and disables remote charging.
See the dated review in [charging transaction flow](../architecture/mobile-qr-charging-transaction-flow.md)
for the missing customer endpoints, wallet/session integration and worker recovery.

On 2026-10-12 the backend contained a paid PHP 1 top-up and exactly one matching
100-centavo wallet credit. The earlier merchant-configuration blockers below
describe the previous release, not the current live wallet configuration.

Published on 2026-10-12 at
`https://staging.evcspowersolutions.com/demo/Power-Solutions-Staging-Lumina-1.0.11.apk`.
HTTP 200 and 78,989,050 bytes verified; SHA256
`45840839195b9188857bbd2eceea2cefff1b18359335eae1e701043123fe3f8a`.
Version/package and the unchanged staging certificate were verified. Validation:
159 mobile tests, 209 backend tests, the focused payment recovery tests after
test-typing cleanup, Flutter analysis, PHPStan, formatting and release build.
Run Flutter tests/analysis before the release build; stale integration-test plugin
registration was resolved by rebuilding with dependency refresh after those tasks
finished. No dependency versions were changed. Phone installation of this build
is still an acceptance step.

## Map and wallet update (1.0.10 / Android code 11)

This build disables rotation in both OpenStreetMap and Google Maps. Selected
station previews have an accessible close button; tapping the map also dismisses
the preview and the same pin can be selected again. Google station pins use the
app preview without a second native information window.

The missing prepaid wallet migration was applied on staging on 2026-10-11.
Balance and history reads now work. The app permits real wallet payments when the
server enables them; simulated payments and remote charging remain disabled.
Real collection is still blocked by missing AUB merchant configuration and
activation. See [wallet deployment](prepaid-wallet-staging.md) before enabling it.

Build from `apps/mobile` after completing Flutter tests:

```powershell
flutter build apk --release --build-name=1.0.10 --build-number=11 --dart-define-from-file=dart_defines.staging-demo.json
```

The packaged artifact is `.cache/mobile-staging-lumina/Power-Solutions-Staging-Lumina-1.0.10.apk`.
Publish the packaged, checksum-verified artifact from the repository root:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\publish-staging-apk.ps1 -Publish
```

Target download: `https://staging.evcspowersolutions.com/demo/Power-Solutions-Staging-Lumina-1.0.10.apk`.
Physical phone acceptance and live AUB payment/settlement remain separate checks.

Published and verified on 2026-10-11: HTTP 200, 78,972,666 bytes, matching remote
SHA256 `dadd47561aea5b10324aa4e60d1089453ef3e62926759c31b5474d8ac1da6fba`.
Package `com.vtsa.vtsa_mobile` retains the existing staging signing certificate.
Validation: 155 mobile tests, 205 backend tests, five Compose tests, Flutter
analysis, PHPStan, Pint and the Android release build passed. Composer audit
reported no advisories. The existing mobile_scanner Kotlin migration warning is
non-blocking for this pinned Flutter release.

This runbook uses `infra/compose.yaml` and a private root `.env.staging` on the
existing staging host. It is separate from the cluster Compose deployment.
No database migration is introduced by the verification fix.

If `MobileRegistrationTest::test_staging_mobile_registration_creates_a_scoped_consumer_account`
returns 404 instead of 201, ensure the deployed registration controller includes
`staging` in its environment allowlist. Keep `APP_ENV=staging`: registration still
requires `FEATURE_DEMO_MODE=true` and the demo tenant ID. Regression tests cover
successful scoped registration, disabled demo mode, a different tenant, and denied
production registration.

## Configuration

Use `.env.staging.example` as a checklist. For an existing deployment, merge
settings into its existing private file; retain the project name, APP_KEY,
database/storage locations, credentials and assigned ports. A different project
name selects different containers and volumes. Never commit `.env.staging`.

- `APP_ENV=staging`, `APP_DEBUG=false`, and `APP_URL=https://staging.evcspowersolutions.com`.
- `FEATURE_DEMO_MODE=true` enables registration only for the existing demo tenant
  `01J0000000VTSADEMA00000000`. Its active membership scope requires the existing
  fleet organization and `consumer-driver` role. Production registration stays disabled.
- Set `TRUSTED_PROXIES` to the reverse proxy's address/CIDR as observed by the
  container. The proxy must forward the original `Host`, `X-Forwarded-Host`,
  `X-Forwarded-Proto: https`, and `X-Forwarded-Port: 443`. Otherwise an HTTPS email
  signature can fail when the internal request arrives over HTTP. Trust `*` only
  when network rules ensure the application is reachable exclusively through your proxy.
- Set the actual `DB_*`, `REDIS_*`, and storage connection values. `POSTGRES_*`
  values remain supported as database defaults. The bundled Redis now requires
  `REDIS_PASSWORD`; supply it consistently to the service and application.
  If enabling the optional `milestone2` profile later, also set
  `GATEWAY_REDIS_URL` to an authenticated Redis URL, percent-encoding its password.
  The gateway's unauthenticated default cannot connect to the bundled Redis.
- For Workspace: `MAIL_MAILER=smtp`, `MAIL_SCHEME=smtp`, `MAIL_HOST=smtp.gmail.com`,
  `MAIL_PORT=587`, full mailbox `MAIL_USERNAME`, its App Password as `MAIL_PASSWORD`,
  and a matching/authorized `MAIL_FROM_ADDRESS`. Keep `MAIL_URL` empty when using
  these individual fields. Use a replacement for the App Password previously pasted.
- `MOBILE_API_BASE_URL` is the HTTPS origin without `/api`;
  `MOBILE_APP_ENVIRONMENT=staging`. Keep charging/payment simulation and real
  charging/payment flags false for this demo.

Use `--env-file .env.staging` on every Compose command. Select `.env` explicitly
for local development. Setting `APP_ENV` does not select a file; host shell
variables take precedence over file values. See
[Docker interpolation](https://docs.docker.com/compose/how-tos/environment-variables/variable-interpolation/).
`config --quiet` validates configuration without printing credentials.

## Update the existing staging application

Transfer the complete change or update the server checkout to a commit containing
it. Both the verification route and Identity application/controller changes are
required. Updating only Compose or clearing routes cannot install application code.
The Docker context excludes cached Laravel routes/configuration and private env
files so stale host caches cannot be copied into a new image.

```bash
cd /opt/vtsa-csms
sudo docker compose --env-file .env.staging -f infra/compose.yaml config --quiet
sudo docker compose --env-file .env.staging -f infra/compose.yaml build platform worker scheduler flutter-web
sudo docker compose --env-file .env.staging -f infra/compose.yaml up -d --no-deps --force-recreate platform worker scheduler flutter-web
sudo docker compose --env-file .env.staging -f infra/compose.yaml exec -T platform php artisan route:list --path=api/v1/auth/email -vv
```

These update commands assume dependencies already run. If changing the bundled
Redis password, recreate `redis` too before the application services. For a new
bundled stack, use `up -d --build` without `--no-deps` so its database, Redis,
storage and mail services also start. Existing external infrastructure requires
its normal access/network configuration; this change does not provision it.

The GET verification route must have `ValidateSignature` and `auth.verify`
throttling, without `Authenticate:sanctum` or `EstablishSanctumTenantContext`.
The POST resend route must retain authentication and tenant middleware.

## Acceptance flow

Registration commits the account before attempting verification email delivery. A mail transport failure now returns HTTP 201 with `data.verification_email_sent=false`; the mobile app explains that the account exists and offers sign-in to request another email. The flag is additive: older successful responses without it retain the existing check-email screen. Verification and tenant authorization remain required. The server logs `identity.registration.verification_email_failed` with the exception class and correlation ID, without the SMTP exception message or credentials.

If staging still reports HTTP 500 while the email becomes registered, obtain the matching server error class before changing mail settings. Confirm the existing account can sign in and inspect the mail transport configuration; creating more email aliases does not repair delivery. Deploy both the registration controller change and the updated mobile client for the recovery message. This response fix does not repair SMTP connectivity or authentication, and no migration is required.

1. Open the newly built web app or install the newly built APK. Create an account.
2. Open the delivered email in a separate browser while logged out. Expect the
   **Email verified** page, then return to the app and sign in.
3. Alternatively, sign in before verification. The app offers **Resend verification
   email** and **I've verified my email**. The latter checks the backend and only
   proceeds after verification succeeds. Reopening the app preserves an unverified
   session so resend remains available.
4. Confirm account access and the station map. Reopening a valid verification
   link succeeds without a duplicate verification audit entry.
5. An expired or altered link shows an unavailable-link page and leaves the
   identity unverified. Resend requires login and an active tenant membership.

Verification remains a global identity operation. The signed link identifies the
subject by public ULID and email hash; the service checks enabled identity and
active, unexpired membership in an active tenant. It records evidence in the
first active tenant by ULID, ignores caller-supplied tenant selectors, locks the
identity for idempotency, and commits the audit alongside the verification.

Build the Android demo from `apps/mobile`:

```bash
flutter build apk --release --dart-define-from-file=dart_defines.staging-demo.json
```

The APK uses the staging origin and demo tenant and the project's existing debug
signing configuration for demo installation. It is not a Play Store release.
Its credentials are entered by
the tester; SMTP/server secrets are never included. Device installation, delivery
through Workspace and the live reverse proxy still require the staging smoke test
above. Automated tests use isolated databases, notifications and credentials.

## Checks

The verification update also patches existing Composer advisories: Filament
5.7.8, Livewire 4.3.5, Guzzle 7.15.2 and CommonMark 2.10.0. The declared dependency
constraints are unchanged. Deploy the updated `composer.lock` together with the
source; a fresh Docker build installs those reviewed versions.

- `python tests/infra/test_compose_environment.py` at repository root.
- `composer lint` and `composer test` in `apps/platform`.
- `flutter analyze`, `flutter test`, and the staging release build in `apps/mobile`.

Local bootstrap generates missing Redis credentials without replacing existing
ones. For an existing staging server, edit its private env file directly instead
of running local bootstrap with `-Force`.

## LUMINA SUBIC staging handoff — 2026-10-10

### Live status update — 2026-10-11

The 1.0.9 (Android version code 10) staging build adds detailed connector labels:
Available, Preparing, Charging, Paused by vehicle, Paused by charger, Finishing,
Reserved, Unavailable and Fault reported. Connection Online/Offline remains a
separate indication. Both derive from gateway evidence; an online heartbeat never
implies the connector is available. Foreground refresh is every five seconds;
stale phone data becomes unconfirmed rather than pretending the charger is online.

Build only after Flutter tests finish. Concurrent `flutter test` and Android
release builds can rewrite the generated plugin registrant with test-only plugins.

```powershell
cd "C:\Users\Migz\Documents\VSTA Charging\apps\mobile"
flutter test --concurrency=1 --timeout=2m
flutter build apk --release --build-name=1.0.9 --build-number=10 --dart-define-from-file=dart_defines.staging-demo.json
```

The longer test-runner budget accommodates the existing 600,000-iteration PIN
checks on a busy workstation; security parameters and assertions are unchanged.
The packaged artifact and SHA256 file are in `.cache/mobile-staging-lumina/`.
Use `scripts/publish-staging-apk.ps1` for checksum-verified publication, retaining
the older APK for rollback. Installing the new APK is required for the new labels.
This build does not enable remote charging or real payments.

Published and verified on October 11:
`https://staging.evcspowersolutions.com/demo/Power-Solutions-Staging-Lumina-1.0.9.apk`.
The 78,972,662-byte APK has SHA256
`6ca09e242f8e8ff12af0ca9f2c9e0f90cfcfa9915132f3f728f814ec39ecd9b3`.
Package `com.vtsa.vtsa_mobile`, version 1.0.9/code 10, retains the previous staging
signing certificate. Remote checksum and HTTP 200/content length were verified.
Phone installation and a physical charging session remain acceptance steps.

The retained physical charge point is `202607010021`, named **LUMINA SUBIC**.
Its site is Block 10 Lot 47, Lumina Homes, Pamatawan, Subic, Zambales, at
`14.926222, 120.223833`, timezone `Asia/Manila`. It has one EVSE and one
**Type 2 AC, 7 kW** connector. The operator organization is still the existing
demo organization; a real operator name has not been supplied.

| Record | ULID |
| --- | --- |
| Tenant | `01J0000000VTSADEMA00000000` |
| Site | `01m4jngt8b3gsafma5bh6tzf07` |
| Charging station | `01m4jb64y306ryxy47e74zj7bf` |
| EVSE 1 | `01m4jngt9bvfzkpthpb8vw89kg` |
| Connector 1 | `01m4jngt9vpr14zaa2hdvzkt0w` |
| Test tariff | `01m4jpw5a8gjpwxendw5jjghcf` |
| Published tariff version | `01m4jpw5behhvt2n406sygpram` |

The published connector-specific tariff is **PHP 1 per session**:
`dimension=session`, `price_minor=100`, `unit_quantity=1`. It has no energy,
time, parking or idle component, no discount and no minimum or maximum fee.
It retains the staging tariff convention of inclusive treatment with no tax
rate configured; this is not a production tax decision. The actual selector
and rating engine were checked with 0 Wh / 0 seconds, 1,000 Wh / 600 seconds
and 7,000 Wh / 3,600 seconds: each rated to 100 centavos. This was a calculation
check, not a real charging session or payment. The tariff was created and
published through `TariffManagementService`, including its audit and outbox.

### Mobile artifact and upload

Packaged artifact: `.cache/mobile-staging-lumina/Power-Solutions-Staging-Lumina-1.0.8.apk`
(78,972,662 bytes; version 1.0.8 / code 9; Android API 24 or newer).
SHA256: `6f76a3ab4b546bce903b5b231f77c1409a8faa5eb3822d522e21bc71325dea1d`.
Android signature verification passed and the certificate matches the existing
1.0.7 staging APK. The upload script passed syntax and local checksum checks;
its upload mode has not been executed. The build is not uploaded or physically
installed on a phone by this handoff. The associated source passed 149 Flutter
tests, analyzer checks and 204 backend tests in the preceding status-polish
verification; this release build completed successfully.

Build the upgradeable staging release from Windows PowerShell:

```powershell
cd "C:\Users\Migz\Documents\VSTA Charging\apps\mobile"
flutter build apk --release --build-name=1.0.8 --build-number=9 --dart-define-from-file=dart_defines.staging-demo.json
```

This release uses the existing staging debug signing certificate. It is a
sideloadable staging APK, not a production store release. It targets the staging
API and keeps remote charging and payment simulation disabled. It includes
station names, separate connection and connector status, last-heard timestamps,
and stale-status handling. The public discovery API does not yet supply a tariff
preview; this APK does not display a verified PHP 1 checkout or enable paid
charging. Customer charging prepare/start/stop integration remains unfinished.

The verified Nginx mapping is `/demo/` to `/var/www/staging-apk/` on the application
VPS. Upload the packaged artifact after checking its companion SHA256 file:

```powershell
cd "C:\Users\Migz\Documents\VSTA Charging"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\publish-staging-apk.ps1 -Publish
```

Omit `-Publish` for local checksum verification only. The script uploads over
SSH, verifies the remote checksum, atomically publishes the APK and checks the
public HTTP response and file size. The execution-policy override applies only
to this PowerShell process. Equivalent manual commands:

```powershell
cd "C:\Users\Migz\Documents\VSTA Charging"
$apk = ".cache\mobile-staging-lumina\Power-Solutions-Staging-Lumina-1.0.8.apk"
$checksum = (Get-FileHash -Algorithm SHA256 $apk).Hash.ToLowerInvariant()
scp $apk "root@187.53.134.122:/root/Power-Solutions-Staging-Lumina-1.0.8.apk"
if ($LASTEXITCODE -ne 0) { throw "APK upload failed" }
ssh root@187.53.134.122 "printf '%s  %s\n' '$checksum' '/root/Power-Solutions-Staging-Lumina-1.0.8.apk' | sha256sum -c - && install -m 0644 /root/Power-Solutions-Staging-Lumina-1.0.8.apk /var/www/staging-apk/Power-Solutions-Staging-Lumina-1.0.8.apk.part && mv /var/www/staging-apk/Power-Solutions-Staging-Lumina-1.0.8.apk.part /var/www/staging-apk/Power-Solutions-Staging-Lumina-1.0.8.apk"
if ($LASTEXITCODE -ne 0) { throw "APK publication failed" }
curl.exe --fail --head "https://staging.evcspowersolutions.com/demo/Power-Solutions-Staging-Lumina-1.0.8.apk"
```

The versioned URL works only after that upload succeeds. This does not replace
an older versioned link on a website. Existing APKs remain available. Enter SSH
credentials interactively; never put them in a script. Nginx needs no restart.

On the phone, install over the existing staging app signed with the same key,
sign in, move the map to Subic, and open LUMINA SUBIC. Check Type 2 / 7 kW and
the last-heard timestamp. A disconnected charger must remain offline. Check
again after an idle charger reconnects and sends BootNotification, Heartbeat
and StatusNotification; this does not require starting energy delivery.

### Setting up a location and charging station

Use this order. Some menus currently edit existing records only; their presence
in the sidebar does not mean the whole provisioning workflow is implemented.
The existing LUMINA SUBIC hierarchy has already been provisioned.

1. **Sites → Create:** enter the site name, unique code, actual operator,
   address, latitude/longitude and IANA timezone. Choose the actual site type,
   Active lifecycle and Public visibility when appropriate. For LUMINA SUBIC,
   use the values above. Do not assign a fake municipality to make search work.
   Public discovery also requires `published_at` at or before now. The current
   site form does not expose publication or public slug, so a backend
   administrative operation is still required for a newly created public site.
   Active + Public alone is not a complete publication workflow.
2. **Charging Stations → Create:** choose that site; enter the station name,
   the exact hardware charge-point identity, real serial number and a unique QR
   identifier. Select OCPP 1.6J for this charger, Active and Public. The current
   form locks the site, identity, serial and QR after creation. The saved active
   identity is resolved dynamically by the gateway; no per-station Nginx route
   is needed. A new station does not automatically create its EVSE or connector.
3. **EVSEs:** provision the independently controllable charging outlet, then
   inspect/edit it here. For this charger it is EVSE number 1, Active. There is
   currently no Create action in this menu; initial provisioning needs a
   backend administrative operation. Do not create another site for an EVSE.
4. **Connectors:** provision connector number 1 under that EVSE, standard
   `TYPE_2`, current type `AC`, maximum power `7000` watts, Active. This menu
   currently edits number, power and lifecycle; it cannot create a connector
   or change its standard. The connector number must match the number reported
   by OCPP. `AKR_SINGLE` is a device label, not the catalog connector standard.
5. **Operating hours:** enter the real schedule in the site's timezone, one
   record per weekday (0 = Monday through 6 = Sunday). Mark closed days closed.
   Initial rows need backend provisioning; the menu edits existing rows. No
   hours were supplied for LUMINA SUBIC, so it correctly reports hours unknown.
   Do not enable an Open now filter when checking a site with unknown hours.
6. **Tariffs:** Create makes the tariff header, not an effective price. A
   version must be added with its scope, effective time, components and tax
   settings, then published. The current supported staff API is
   `POST /api/v1/tariffs/{tariff}/versions`, followed by
   `POST /api/v1/tariff-versions/{version}/publish`. These require a staff token
   with tariff-manage / tariff-publish permissions in the correct tenant.
   The current admin list has no version editor/publish action. LUMINA's
   published version above is already complete; do not add a duplicate.
7. **Device configuration:** retain the manufacturer's working OCPP settings.
   If firmware appends its separate ID field, use base URL
   `wss://staging.evcspowersolutions.com/ocpp` and identity `202607010021`.
   The final request path must be `/ocpp/202607010021`, with the identity once.
   The staging gateway uses the explicitly requested URL-only enrollment.
   Use `ocpp1.6` as WebSocket subprotocol. Do not infer firmware URL behavior
   from a simulator or run two clients with this same identity.
8. **Status and faults:** inspect device-reported connector status and faults.
   Active lifecycle is an administrative setting; it does not prove Online or
   Available. Online means recent gateway presence; Available additionally
   requires a current connector status. Offline, stale/unknown, busy,
   unavailable and faulted must remain distinct.
9. **Session diagnostics / Command diagnostics:** inspect actual session events,
   command delivery and responses. An accepted command is not proof that
   energy flowed. A session needs device transaction and meter evidence.
10. **Session review queue:** investigate ambiguous or incomplete transactions;
    do not resolve them as successful solely because the charger is online.
11. **Network map:** verify location, visibility and live status, then verify
    the same station in the mobile map. Public, published location and active,
    public station settings are required. A fresh connector report is needed
    after the initial connector record is created.

The remaining admin provisioning gaps (site publication, EVSE/connector/hour
creation and tariff version publication) need a follow-up implementation before
the full process can be completed entirely through admin. Do not describe the
current sidebar as a finished self-service commissioning workflow.

### Proposed deletion scope — not executed

The user requested a deletion plan rather than retirement. Inventory on
2026-10-10 found all 17 sites and 34 stations in the staging demo tenant. Keep
the single site/station/EVSE/connector listed above and its tariff, identity,
connection evidence and QR values. Remove the following only after approval
of the expanded history scope and a tested backup/restore procedure:

| Proposed removal | Observed count |
| --- | ---: |
| Other sites | 16 |
| Other charging stations | 33 |
| Other EVSEs | 45 |
| Other connectors | 60 |
| Old connector status projections | 60 |
| Old station connection projections | 8 |
| Old site amenity links | 30 |
| Old site operating-hour rows | 105 |
| Maintenance work orders at old sites | 2,956 |
| Preventive plans at old sites | 4 |
| Preventive occurrences | 2,953 |
| Work-order transition records | 2,953 |
| Work-order assignment | 1 |
| Incident at an old site | 1 |
| Support tickets / messages | 2 / 2 |

There were zero charging sessions at inspection. Recheck immediately before
execution; stop if new sessions or unexpected dependencies appear. One work
order was in progress and one assigned; their operational legitimacy must be
confirmed before purging them. The four preventive plans are active, so their
scheduler can create more rows while a cleanup is being prepared.

The `CENTRAL` warehouse is linked to an old site and has bins, stock locations,
a goods receipt, a count plan, an adjustment request and a reorder point. The
proposal is to retain the warehouse and all stock/financial records and detach
only its nullable site association. Do not silently move its stock to LUMINA.
Retain organizations, people/accounts, catalog reference data, wallet/payment
books, audit history and the existing operator tariff. The new connector tariff
takes precedence for LUMINA without deleting shared pricing.

Execution procedure after expanded-scope approval:

1. Snapshot the remote PostgreSQL database, record a checksum, and verify
   restoration into an isolated database. Export the exact affected rows and
   attachments to a private root-only archive. Never put that archive in Git
   or `/demo/`. A VPS filesystem snapshot alone does not back up the separate
   database server.
2. Re-inventory IDs, tenant boundaries, polymorphic asset references and child
   rows. Compare with the reviewed manifest. Stop on new sessions, additional
   financial dependencies or records outside this tenant.
3. Disable the four old preventive plans through an audited administrative
   operation and drain their pending generation work. Keep the live charger's
   gateway and event ingestion running. Do not rerun demo seeders afterward.
4. PostgreSQL deliberately blocks deletion of maintenance transition evidence.
   An explicit, reviewed staging-purge procedure is needed for those records;
   an ordinary Delete action or cascade is insufficient. Preserve the audit
   chain and financial/stock immutability controls. Do not disable triggers
   broadly or run `migrate:fresh` / `db:wipe`.
5. In a tenant-scoped transaction, detach the warehouse's old site link,
   remove approved child history in dependency order, then connectors, EVSEs,
   stations and sites. Record a cleanup audit entry with manifest and backup
   references. The preserved hierarchy must be asserted before commit.
6. Verify admin and public map contain the retained network; old identities
   fail gateway resolution; `202607010021` still resolves; the test tariff
   rates to PHP 1; inventory balances, accounts and financial books are
   unchanged. Confirm plans/jobs do not recreate removed demo data.
7. Before commit, rollback is transactional. After commit, restore the archived
   subset through a reviewed recovery operation; do not overwrite newly
   received live charger data with an indiscriminate whole-database restore.

No records were deleted or retired while preparing this plan. No backup or
restore test has yet been performed for this proposed purge. Permanent deletion
is pending because its scope extends into protected maintenance evidence and
support history, beyond simply removing station listings.
