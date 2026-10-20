# Power Solutions error pages

The platform renders `resources/views/errors/404.blade.php` for HTML not-found responses using Laravel's normal exception renderer. Existing CMS redirects are resolved first. JSON requests retain their API error response and HTTP 404 status.

The page uses the existing Power Solutions branding assets, a decorative inline SVG, and self-contained responsive styles. Its heading is “Page not found” with the HTTP error code in the explanatory copy; decorative eyebrow labels and slogans are omitted. It does not depend on a Vite manifest, JavaScript, authentication, or additional database queries to render. The homepage link returns to `/`. Unknown paths and query parameters are not displayed.

Deploy the view with the normal platform release. If Blade views are precompiled, regenerate the view cache during deployment. No migration, dependency installation, mobile build, or OCPP gateway restart is required.

Verify an unknown web path returns the branded page with HTTP 404, an unknown JSON endpoint remains JSON, and an existing CMS redirect still redirects. Automated coverage is in `NotFoundPageTest` and `PublicWebsiteTest`.

## Staging release — 2026-10-16

The web platform uses `vtsa-prepaid-platform:20261016-branded-404`, built on `vtsa-prepaid-platform:20261016-monitoring` with only the error template and its regression test added. The four background workers remain on the monitoring image; the native OCPP gateway was not restarted. Future deployments must account for these intentionally different image tags.

Release evidence and the previous web compose override are in `/opt/vtsa-releases/404-20261016/` on the application VPS. The candidate passed seven tests (71 assertions). Live checks verified HTML/JSON 404 responses, homepage recovery, portal login pages, logo delivery, and readiness. No migrations were run.

To roll back this presentation-only release, set only `services.platform.image` in `/etc/vtsa-csms/compose.staging-prepaid-platform.json` back to `vtsa-prepaid-platform:20261016-monitoring` and recreate the platform service using the existing staging compose configuration. Preserve any later configuration edits; the saved `compose.before.json` is a reference, not a file to restore over subsequent deployments blindly.
