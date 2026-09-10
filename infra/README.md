# Infrastructure

`compose.yaml` is the local development stack. It uses generated local credentials from the root `.env`.

Application containers run immutable images rather than host source bind mounts. This avoids severe cross-platform filesystem behavior and makes the local topology match CI. Rebuild `platform` and `worker` after source changes, or use the host-native Laravel development command for a hot-reload loop.

`compose.production.yaml` is the single-VPS production-mode baseline. It uses PHP-FPM behind Nginx and Caddy, automatic TLS, non-public PostgreSQL/Redis/MinIO services, production feature guards, bounded container logs, and persistent volumes. Follow [the Hostinger deployment runbook](../docs/runbooks/hostinger-vps-deployment.md); its documented sizing, monitoring, off-host backup, and high-availability decisions remain open.
