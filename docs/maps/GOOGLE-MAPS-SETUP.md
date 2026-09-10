# Google Maps Setup

Google mode is optional. Create separate restricted development, staging, and production keys.

## Laravel web

Enable only **Maps JavaScript API** for the browser key and restrict it to exact approved HTTP referrers. Set:

```env
GOOGLE_MAPS_BROWSER_API_KEY=
GOOGLE_MAPS_MAP_ID=
MAP_PROVIDER_PUBLIC=google
MAP_PROVIDER_ADMIN=google
MAP_PROVIDER_OPERATOR=google
```

The SDK is loaded only after Google is resolved. Missing configuration falls back to OpenStreetMap. Places, Geocoding, Routes, and unrelated APIs are not required by this implementation.

Set budget alerts, quotas, per-key restrictions, and environment separation in Google Cloud. Never use a server key as a browser key.

## Flutter

- Android: enable Maps SDK for Android; inject `GOOGLE_MAPS_API_KEY` as a Gradle property or environment variable. Restrict by package and signing certificate.
- iOS: enable Maps SDK for iOS; inject `GOOGLE_MAPS_API_KEY` through an untracked xcconfig/build setting. Restrict by bundle ID.
- Web: enable Maps JavaScript API and load its script with an HTTP-referrer-restricted browser key before Flutter boot, as required by `google_maps_flutter_web`.

Run with `MAP_PROVIDER=google` and `GOOGLE_MAPS_READY=true` only after the relevant platform is configured. Web keys are public identifiers and still require origin/API restrictions.

## Verification

Credential-free CI runs the Google adapter/factory contract with mocked builders and SDK failure. A live claim requires an actual restricted local key and rendered platform:

```powershell
$env:GOOGLE_MAPS_BROWSER_API_KEY = '<restricted-development-key>'
$env:MAP_PROVIDER_PUBLIC = 'google'
make demo-up
```

Do not paste keys into screenshots, logs, issue reports, or committed `.env` files.
