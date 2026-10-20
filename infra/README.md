# Infrastructure

`compose.yaml` is the local development stack. It uses generated local credentials from the root `.env`.

It also accepts an explicitly selected `.env.staging` for the mobile staging demo.
Application URLs, SMTP, database/Redis/storage connections, published ports and
mobile build arguments come from that file. See
[mobile staging deployment and verification](../docs/runbooks/mobile-staging-demo.md).
Keep existing deployment identifiers and secrets when adapting `.env.staging.example`.

For a physical OCPP 1.6J charger on the existing Hostinger staging site, follow
[the OCPP staging quickstart](../docs/runbooks/ocpp-staging-quickstart.md).
`compose.ocpp-staging.yaml` adds authenticated WSS routing, matching core consumers,
private loopback publishing, and staging-only stream settings while retaining KYC.

Application containers run immutable images rather than host source bind mounts. This avoids severe cross-platform filesystem behavior and makes the local topology match CI. Rebuild `platform` and `worker` after source changes, or use the host-native Laravel development command for a hot-reload loop.

`compose.production.yaml` is the single-VPS production-mode baseline. It uses PHP-FPM behind Nginx and Caddy, automatic TLS, non-public PostgreSQL/Redis/MinIO services, production feature guards, bounded container logs, and persistent volumes. Follow [the Hostinger deployment runbook](../docs/runbooks/hostinger-vps-deployment.md); its documented sizing, monitoring, off-host backup, and high-availability decisions remain open.

`database/compose.yaml` is the PostgreSQL/PostGIS-only stack for the dedicated
data server. It provisions `vtsa_production` and `vtsa_staging` for the shared
`vtsa_app` login. `local/compose.environments.yaml` connects to that database
network and exposes isolated local production and staging web/mobile surfaces.
Follow [the PostgreSQL environments runbook](../docs/runbooks/postgresql-environments.md).
