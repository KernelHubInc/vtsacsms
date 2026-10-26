# Initial production web release — 2026-10-26

The production site uses `evcspowersolutions.com` on the load balancer. App 01 runs the Laravel production stack; App 02 continues to host staging independently until its production environment is configured and verified. The database VPS holds separate production PostgreSQL, Redis, and object-storage services.

## Browser app

Build Flutter web from the reviewed release commit with `--base-href /mobile/`, `APP_ENVIRONMENT=production`, `API_BASE_URL=https://evcspowersolutions.com`, and the real production tenant ULID. Install the bundle under `/var/www/production-mobile/releases/<release>` on the load balancer and atomically point `/var/www/production-mobile/current` to it. Include `infra/nginx/mobile-production-location.conf` inside the production TLS server block. Check `/mobile/`, `/mobile/main.dart.js`, and a nested client route. Preserve the previous release directory to roll back the symlink.

## Launch restrictions

Production uses HTTPS and secure session cookies. OCPP, remote charging, real payments, and settlements remain disabled until charger enrollment, payment credentials, and end-to-end tests are complete. Production email remains log-only until a verified sending service is configured; verification, password-reset delivery, and receipts must not be advertised as operational. The current registration endpoint is deliberately restricted to development/demo/staging, so production driver onboarding needs its own reviewed implementation before launch. The first tenant and administrator must be bootstrapped through an audited, secret-safe workflow, never with the demo seeder.

## Verification and rollback

Before migrations, take a verified PostgreSQL dump and confirm the active application image and Nginx configuration are backed up. Confirm the production and staging readiness endpoints independently. If application activation fails, leave the load balancer on the prior upstream; if the browser bundle fails, repoint the `current` symlink to the previous release and reload Nginx. Keep production and staging keys, sessions, Redis prefixes, buckets, and databases separate.