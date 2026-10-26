# Initial production web release — 2026-10-26

The production site uses `evcspowersolutions.com` on the load balancer. App 01 runs the Laravel production stack; App 02 continues to host staging independently until its production environment is configured and verified. The database VPS holds separate production PostgreSQL, Redis, and object-storage services.

## Browser app

Build Flutter web from the reviewed release commit with `--base-href /mobile/`, `APP_ENVIRONMENT=production`, `API_BASE_URL=https://evcspowersolutions.com`, and the real production tenant ULID. Install the bundle under `/var/www/production-mobile/releases/<release>` on the load balancer and atomically point `/var/www/production-mobile/current` to it. Include `infra/nginx/mobile-production-location.conf` inside the production TLS server block. Check `/mobile/`, `/mobile/main.dart.js`, and a nested client route. Preserve the previous release directory to roll back the symlink.

## Launch restrictions

Production uses HTTPS and secure session cookies. OCPP, remote charging, real payments, and settlements remain disabled until charger enrollment, payment credentials, and end-to-end tests are complete. Production email remains log-only until a verified sending service is configured; verification, password-reset delivery, and receipts must not be advertised as operational. The current registration endpoint is deliberately restricted to development/demo/staging, so production driver onboarding needs its own reviewed implementation before launch. The first tenant and administrator must be bootstrapped through an audited, secret-safe workflow, never with the demo seeder.

## Verification and rollback

Before migrations, take a verified PostgreSQL dump and confirm the active application image and Nginx configuration are backed up. Confirm the production and staging readiness endpoints independently. If application activation fails, leave the load balancer on the prior upstream; if the browser bundle fails, repoint the `current` symlink to the previous release and reload Nginx. Keep production and staging keys, sessions, Redis prefixes, buckets, and databases separate.

## First release record

The first web deployment uses commit `0b05b06aec829a05353caf5fd8e1238b571f4b99` from `test_env` on App 01. The load balancer proxies the production hostname to App 01 port 80; App 02 remains the staging host and is not a production fallback. The production TLS certificate is managed by Certbot. The browser bundle is installed at `/var/www/production-mobile/current` on the load balancer and was built with production API origin and tenant ID `01M5TYA79VGM9C3AGJB4PMNZ2Q`.

The production database was backed up to `/var/backups/vtsa-csms/production/pre-first-release-20261026.dump` before migrations. The first Power Solutions tenant was created with an append-only audit event, then the production-safe seeders were run. Check that `payment_provider_configs` has no fake provider and that no sample tariff or supplier exists before enabling financial functions.

The production Nginx configuration immediately before the App 01 switch is `/root/vtsa-deploy-backups/vtsapowersolutions.com.pre-app01-switch-20261026` on the load balancer. If the application becomes unavailable, restore that exact config, run `nginx -t`, and reload Nginx. For application rollback, use the previous locally cached `sha-4865a961e6f3720b3fce5d3be1390a8d783e1d0f` platform and web images with the same production Compose project, then verify readiness and the public routes.

Production still has no sender or first administrator login. Do not treat an HTTP 200 on `/admin/login` as proof that sign-in, verification, password reset, or receipts work. Establish a verified mail sender, use an audited first-admin invitation or password setup, and complete an end-to-end registration and charging test before allowing customers to transact. Production charging, payment, and settlement feature flags remain off. The web app's registration endpoint is intentionally unavailable in production until its onboarding flow is reviewed.
