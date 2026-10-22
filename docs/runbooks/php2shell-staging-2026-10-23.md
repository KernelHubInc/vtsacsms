# PHP2Shell staging patch — 2026-10-23

PHP2Shell (CVE-2026-109618 and CVE-2026-109619) affects web-facing PHP before the upstream fixed releases. PHP 8.4.27 is the first fixed PHP 8.4 release. See the [PHP security advisory](https://www.php.net/archive/2026.php#2026-10-21-5).

## Staging change

- VPS: `187.53.134.122`; environment: staging only.
- Before: the web app and four PHP consumers/workers ran PHP 8.4.26.
- After: all five services run PHP 8.4.27 on Alpine 3.24.3. The separate OCPP gateway was not restarted.
- The staging images were rebuilt using the PHP 8.4.27 CLI base and the exact `/app` contents from the previously deployed web and worker images. Application file hashes and PHP module lists were compared before replacement.
- The original images remain tagged `vtsa-prepaid-platform:rollback-before-php2shell-20261023-web` and `vtsa-prepaid-platform:rollback-before-php2shell-20261023-worker` on the VPS. The prior Compose JSON files have `.before-php2shell-20261023` copies beside them.
- The repository Dockerfile now pins both CLI and FPM stages to the first fixed 8.4.27 Alpine 3.24 release so future builds do not silently reuse 8.4.26.

## Verification

Every replaced container reported PHP 8.4.27. The web app and two OCPP consumers reported healthy; the charging worker and monitor were running. The staging `/health/live`, `/admin/login`, and `/charging-map` routes returned HTTP 200. No error, exception, or fatal-error lines appeared in the five containers' immediate post-deployment logs. Live charging and payment transactions were not initiated for this infrastructure patch.

## Remaining work

This patch addresses PHP2Shell in the inspected staging PHP containers. It is not a compromise investigation or a production-server audit. Host OS and Docker package updates remain pending and should be scheduled with a VPS snapshot and an OCPP maintenance window. Before promoting to production, verify each production PHP runtime is on a fixed vendor build and repeat the health checks. Retain the rollback images until post-deployment monitoring is complete, then remove them through normal image-retention procedures.
