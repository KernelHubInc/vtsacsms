# Power Solutions email templates

The October 2026 email refresh follows Figma file `V8iWxN6ryAitszGnxpLkIs`, frames `3:760` (receipt), `3:836` (welcome), and `3:897` (everyday journeys). Notifications owns the shared email views and assets. Identity keeps ownership of verification links and delivery triggers.

## What sends today

- **Charging receipt:** the existing settled-session delivery workflow uses `ChargingSummaryMail`, now with the new HTML and plain-text designs. The PDF remains attached and uses the same frozen payment snapshot. Tenant isolation, verified recipient lookup, retries and deduplication are unchanged. The receipt uses the actual currency, minor-unit total, payment method, station, energy and times. Figma's illustrative card, tax, fee and USD values are not used. Itemized rating lines are not present in this snapshot, so no cost breakdown is invented.
- **Welcome / verification:** `VerifyEmailNotification` uses the welcome design for registration and verification resends. Its signed link still expires after 60 minutes. The verification action is added ahead of the Figma onboarding steps. There is no additional welcome message or change to registration eligibility.
- **Promotional email:** `PromotionalMail` is a reusable rendering template for the everyday-journeys design, not a campaign sender. It requires an HTTPS unsubscribe URL and optionally accepts an HTTPS preferences URL. It provides a `List-Unsubscribe` header but does not claim one-click POST support. Before integrating delivery, the campaign system must enforce consent, resolve recipient-specific working preference links, suppress unsubscribed recipients and deduplicate deliveries. No public placeholder unsubscribe route or automatic customer mailing is added.

## Deployment and checks

No migrations, environment flags or new workers are required. Deploy the Blade/PHP changes and `public/branding/email` assets together. Set `APP_URL` to the environment's HTTPS load-balancer hostname; links and assets use this configured base, not the incoming request host. Existing SMTP configuration remains necessary.

The dependency check identified CVE-2026-45070 in the existing `symfony/mime` 7.4.13 lock entry. This change updates only that package to 7.4.22, the patched version identified by Composer's advisory feed. Build/install from the updated lockfile; no Composer manifest constraints were changed.

The 640px table layout uses inline styles, web-safe font fallbacks and narrow-screen stacking. Figma icons and the journey illustration are locally stored PNGs for email compatibility. Essential content and actions remain readable with images blocked. The established logo replaces Figma's text-only brand. The receipt PDF keeps local assets and remote execution disabled.

The receipt button opens the browser app, with instructions to sign in and find the session under Activity using its reference. The current mobile router does not preserve session deep links through bootstrap/login, so the template does not promise a direct authenticated session landing.

Run `php vendor/bin/phpunit tests/Feature/Notifications/EmailDesignTest.php tests/Feature/Security/EmailVerificationTest.php tests/Feature/Charging/CustomerChargingTest.php`. Preview HTML at desktop and 320–390px widths and inspect the generated PDF. Browser preview does not certify every Outlook/Gmail/Apple Mail version; perform a mailbox rendering check with an approved test recipient after deployment.
