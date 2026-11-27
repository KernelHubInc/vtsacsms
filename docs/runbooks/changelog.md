# Environment changelog

Requirement **FR-CMS-005**: Provide `/changelog` on staging and production, linked from the public footer and both workforce panel sidebars. Release notes are public, escaped text; they contain no customer records, credentials, private infrastructure or security incident detail.

CMS owns the publication and the reviewed catalogue in `apps/platform/config/changelog.php`. Entries have a stable ID, a calendar date in Asia/Manila, explicit environments, title and typed changes. The application selects its own environment; request parameters cannot preview another environment. Future entries remain hidden until their date. Entries describe verified deployments, not every commit or unverified physical charging/payment outcome. Initial history deliberately covers recent verified releases only.

For each release, add concise user-facing notes to the catalogue. Include production only after staging acceptance and approval for promotion. Keep staging-only changes out of the production list. If a rollout fails, roll back the candidate and its notes together. The date is a release date, not a mobile version or an exact deployment timestamp. Never link the production page to staging.

After deploying, run `php artisan cms:publish-changelog PLATFORM_TENANT_ULID` once on each environment's primary platform container, using its existing platform tenant. This creates only the new CMS publication and SEO record and records `cms.changelog.published` in the tenant's audit chain atomically; reruns preserve existing editor changes, metadata and deliberate withdrawal. It does not seed or alter other pages. No new schema migration is needed for this page. The inherited Level 3 support release has its own additive migration; follow its existing runbook if not already applied.

Verify `/changelog`, footer links and the Admin/Operator sidebar links. Staging sends `X-Robots-Tag: noindex, nofollow` and an equivalent meta tag even if CMS SEO is edited. Retain the existing environment-wide search exclusion. Production must contain only production entries and use its own canonical URL. Verify the Vite manifest and hashed assets on every web node during rolling deployment; retain prior hashed assets for rollback/cached pages.

Automated coverage: `tests/Feature/ChangelogTest.php` checks publication idempotency/editor preservation, environment isolation, staging search exclusion, future dates, escaping, empty state, links and sitemap inclusion. `PublicWebsiteTest` retains the existing query/payload budgets.
