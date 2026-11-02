# Driver notice push notifications

## Scope and ownership

Android staging is the first enabled target. Browser push still needs a Firebase web application, VAPID configuration, and a messaging service worker. iOS needs APNs setup. A staging credential must not be reused implicitly for production.

CMS owns the notice, publication dates and explicit `send_push` selection. Its `PushNoticeQuery` provides active content to Notifications without cross-context writes. Identity's `PushRecipientQuery` checks the mobile token, security version, enabled and verified identity, and current membership. Notifications owns encrypted installation registrations and durable delivery records. No wallet, charging or settlement behavior changes.

## Server setup

Keep the service-account JSON outside the checkout and webroot. Mount it read-only into the platform/scheduler container, readable only by the application service account. Never put the key contents in an environment variable, log, APK, Git, or image layer. Configure:

```dotenv
NOTICE_PUSH_ENABLED=false
NOTICE_PUSH_PROJECT_ID=evcs-power-solutions-staging
NOTICE_PUSH_CREDENTIALS=/run/secrets/firebase-notice-push.json
NOTICE_PUSH_ANDROID_PACKAGE=com.vtsa.vtsa_mobile
```

Run the additive migration and rebuild Laravel's configuration cache. Validate OAuth and FCM permission using validation-only mode without sending:

```sh
php artisan notifications:send-driver-notices --check
```

Only after verification, enable `NOTICE_PUSH_ENABLED=true` and rebuild the configuration cache. The scheduler definition invokes `notifications:send-driver-notices` every minute, with a shared-cache overlap lock and a separate process. Each pass sends at most 50 pending deliveries per active tenant. No new queue consumer is needed. Production scheduling remains on App01 only. The current custom staging stack uses individual command loops rather than Laravel's global scheduler; provision a staging-only runner for this command every minute without enabling unrelated scheduled tasks. Redis locks are ephemeral; the delivery ledger is PostgreSQL-backed.

For deployments using the standard cluster Compose file, append `infra/cluster/compose.notice-push.yaml` and set `NOTICE_PUSH_CREDENTIALS_HOST` to the private host file. The overlay mounts it read-only into the platform and scheduler. Do not enable a production scheduler on App02. Custom staging release configurations must preserve the equivalent read-only mount and environment flags in their generated deployment plans.

Disable sending through the environment flag to roll back. Do not roll back the migration or delete delivery evidence. Existing in-app notices work independently of Firebase.

## Android staging build

Verify the Firebase Android application uses `com.vtsa.vtsa_mobile`. Copy its client `google-services.json` to `apps/mobile/android/app/google-services.json` (ignored by Git). This is not the server service-account key. Build with `EVCS_FIREBASE_ENABLED=1` and the staging Dart definitions; ordinary builds do not apply the Google Services plugin. Never use the staging client configuration for a production build. Notification permission is requested after a verified sign-in. The `driver_notices` Android channel presents background notifications; foreground messages refresh Updates and the existing opening banner. Tapping a notification opens Updates after authentication/onboarding requirements.

Firebase Messaging 17 uses `register()` / `onRegistered` installation IDs. The HTTP v1 request uses `message.fid`, not an assumed legacy registration token. See the [Firebase message reference](https://firebase.google.com/docs/reference/fcm/rest/v1/projects.messages).

## Admin instructions

1. Open **Content studio → Driver notices**.
2. Create a maintenance notice, announcement or promotion; enter title and message.
3. Set its start and end dates (the form labels UTC explicitly).
4. Choose whether to show the in-app opening banner.
5. Enable **Send push notification**, then set status to **Published** and save.
6. Open **Push delivery** on that notice to inspect counts and the latest 100 attempts.

Existing notices default to push off. CMS edit permission permits drafts; CMS publish permission is required to publish or enable push. Delivery is tenant-scoped to signed-in, verified, active Android installations that have granted OS permission. There is no site-specific audience filter in this release. Each installation is sent at most one accepted push per notice; editing or republishing does not resend an accepted notification. Create a new notice for an intentional new announcement. Devices that register later can receive a still-active opted-in notice.

## Delivery semantics and privacy

`accepted` means FCM accepted the request, not device display or reading. `pending` is awaiting its first attempt or a retry. Explicit 401/429/500/503 responses retry at least 60 seconds later, honoring a numeric Retry-After up to a day, with five attempts maximum. `invalid` removes an unregistered installation. Revoked/expired authentication, changed device ownership, unpublished or expired notices cancel pending deliveries. Timeouts and interrupted workers become `unknown` rather than risking automatic duplicate sends. Expiring the notice also bounds the provider TTL (maximum one hour).

Network operations run after an atomic database claim, outside database transactions. Unique tenant/notice/installation hashes and conditional claims prevent concurrent workers and duplicate scheduling from sending the same delivery twice. Provider response bodies, keys and installation IDs never appear in the admin delivery log. Hashes and encrypted IDs are limited to the internal registry; delivery evidence contains IDs, timestamps, status and safe error codes. Registration is deleted on logout/token deletion or FCM unregistration. Delivery rows, including deduplication hashes, are pruned by the sender command 90 days after the notice expires, even when sending is disabled. This affects only the new notification delivery table, not the immutable audit log. Keep the command scheduled for retention cleanup; do not export registration material. Mobile permissions can be withdrawn in Android Settings.

## Acceptance checklist

- Automated tests: tenant isolation, opted-in/published dates, disabled sending, retries and rate limits, unknown outcomes, unregistration, expired authentication, and repeated delivery.
- Install the staging APK, sign in with a verified account and permit notifications.
- Publish one explicit test notice to a test tenant. Check foreground, background, terminated-app notification, tap-to-Updates, and logout.
- Confirm the admin shows `accepted`; confirm actual display on the test phone separately.
- No live test announcement is broadcast automatically by the deployment.

## Staging deployment — 3 November 2026

The staging backend runs snapshot `notice-2feeb57e21688c9b`, derived from release `b46baff94fdf4c420bb9a5e7d44f924ec392ea23`. Its source hashes, database backup location and Compose rollback commands are recorded in `/opt/vtsa-releases/notice-2feeb57e21688c9b/deployed.json` on App02. Production containers were not changed.

The private credential is stored at `/etc/vtsa-csms/secrets/staging/firebase-notice-push.json` (root-owned, application group read-only, mode 0440), with a read-only container mount. The staging-only systemd timer `vtsa-staging-notice-push.timer` runs the sender through the staging platform container every minute. Check it with `systemctl status vtsa-staging-notice-push.timer` and `journalctl -u vtsa-staging-notice-push.service`. Stop this timer before using the recorded Compose rollback commands; retain the additive migration and delivery history.

APK 1.0.38 (build 39) uses the staging endpoint and Firebase project. Its signing certificate matches APK 1.0.37. The existing `/demo/Power-Solutions-Demo.apk` download now points to this build. APK SHA-256: `006c05b9081f1bef443437fb3c3d3da81ae70951acb990b70b6f1568b8ca7c6e`.

Server OAuth/FCM validation, timer execution, database/Redis readiness, and the downloaded APK checksum were verified. A real-phone foreground/background notification test remains a user acceptance step; deployment did not publish or send a test announcement.
