# Mobile staging demo: registration and email verification

This runbook uses `infra/compose.yaml` and a private root `.env.staging` on the
existing staging host. It is separate from the cluster Compose deployment.
No database migration is introduced by the verification fix.

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
