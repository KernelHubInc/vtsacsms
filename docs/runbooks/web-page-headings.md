# Web page headings

Use a clear page or section heading followed by relevant explanatory text. Do not add decorative eyebrow labels above headings. Keep status badges, form labels, equipment identifiers, legal notices, and staging warnings when they convey necessary information.

This convention applies to the public website, error pages, admin/operator monitoring, account recovery, KYC screens, technician workboard, and shared web components. Article types appear with publication metadata instead of above the article title.

CMS page and section eyebrow inputs are removed from the editor and public rendering. Existing nullable database fields and historical values remain intact for compatibility; no migration or content deletion is required. Page titles, body content, links, authorization, and operational data are unchanged.

Deploy updated Blade templates and the Vite build together. No mobile APK or OCPP gateway changes are involved.

## Staging verification — 2026-10-16

Web image: `vtsa-prepaid-platform:20261016-readable-headings`, based on the branded 404 image. Four background workers remain on the monitoring image. The release directory `/opt/vtsa-releases/headings-20261016/` holds the file manifest, build and test results, previous compose override, and deployment marker.

The candidate passed 259 backend tests (2,217 assertions). Formatting, PHPStan and the production Vite build passed. Live checks confirmed 16 public pages, HTML/JSON 404 behavior, portal login pages, readiness, and authorized dashboard rendering against staging data. The native gateway PID and four worker container identities were unchanged. Browser screenshot verification was attempted but the preview webview failed to attach; a fresh post-deployment visual check remains outstanding.

Rollback is presentation-only: change only `services.platform.image` in the staging platform compose override to `vtsa-prepaid-platform:20261016-branded-404` and recreate the platform service with its existing compose configuration. Do not overwrite subsequent configuration changes with the saved backup.
