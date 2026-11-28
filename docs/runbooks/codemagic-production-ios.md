# EVCS Power Solutions — production iOS build

Use the existing Codemagic Flutter workflow editor and its connected App Store Connect integration. The Apple APNs key uploaded to Firebase and the App Store Connect API key uploaded to Codemagic are different keys. Neither belongs in Git or the app bundle.

## Workflow settings

| Setting | Value |
| --- | --- |
| Repository / branch | `KernelHubInc/vtsacsms` / `main` |
| Platform | iOS |
| Shorebird | Disabled |
| Instance | macOS M2 or a supported newer Mac instance |
| Flutter | `3.47.9`, stable |
| Xcode | `27.2` stable; select this version explicitly, rather than `edge` or an older default. Record Xcode and iPhoneOS SDK versions in build evidence |
| CocoaPods | Default |
| Native plugin manager | CocoaPods, enforced by `flutter.config.enable-swift-package-manager: false` in `pubspec.yaml` |
| Project path | `apps/mobile` |
| Mode | Release |
| Target | `lib/main.dart` |
| Flavor | Leave empty; this project has no `ios-production` flavor |
| Minimum iOS | `15.0` (already configured in source) |
| Bundle identifier | `com.vtsa.vtsaMobile` (capital `M`) |
| Signing | Automatic; select the existing App Store Connect integration |
| Provisioning profile | App Store distribution, suitable for both TestFlight and public App Store distribution |
| Publishing | Enable App Store Connect upload with the same integration; leave automatic App Review submission off |

The Apple Developer App ID must have **Push Notifications** enabled. Regenerate an older provisioning profile after enabling the capability; it must include `aps-environment = production`. TestFlight also uses production APNs, even for a staging API build. Background fetch and remote notifications are configured in the app's Info.plist. Firebase method swizzling remains enabled.

## Firebase client configuration

Download the iOS **GoogleService-Info.plist** for Firebase project `evcs-power-solutions`, registered to `com.vtsa.vtsaMobile`. This differs from Android's `google-services.json` and from the backend service-account JSON. The local production copy supplied by the owner has matching project and bundle identifiers.

Create a **secure** Codemagic environment variable named `FIREBASE_IOS_PLIST_BASE64`, scoped to this production workflow. Store the Base64 encoding of the entire plist as its value. Produce it locally and paste it directly into Codemagic; do not paste it into chat, logs or source. Keep shell tracing (`set -x`) disabled. No APNs `.p8` or Firebase service-account key is needed in Codemagic.

Use this **pre-build script** (repository root is explicit):

```bash
set -euo pipefail
cd "$CM_BUILD_DIR"
xcodebuild -version
xcrun --sdk iphoneos --show-sdk-version
cd apps/mobile
flutter clean
flutter pub get --enforce-lockfile
cd "$CM_BUILD_DIR"
python3 scripts/prepare-ios-firebase.py --environment production
```

The script rejects a staging project, a mismatched bundle identifier, incomplete client settings, or service-account material. It writes the ignored native plist and `.codemagic/ios-defines.json` inside `apps/mobile`. Production uses the public settings tracked in `apps/mobile/dart_defines.production.json`, including the approved Google Web/server and iOS OAuth client IDs. It adds the matching Google callback scheme to Runner's Info.plist while retaining the `vtsa` app link. A conflicting OAuth client in the supplied Firebase plist fails preparation. Xcode copies the plist into the app bundle. Ordinary simulator builds without a plist still compile; Firebase remains unavailable until a matching client file is provided.

## Build arguments

Set **Release** in the UI. In the iOS build arguments field use:

```text
--target lib/main.dart --dart-define-from-file=.codemagic/ios-defines.json
```

Set your release version and a new build number in Codemagic's versioning configuration. Keep the version consistent with the App Store Connect version record; each upload needs a build number not previously used for that version. Do not reuse an already uploaded build number. If versioning is managed in build arguments instead, append `--build-name=<your-version> --build-number=<unused-build-number>` with real values before starting the build.

The generated production definitions use `https://evcspowersolutions.com`, Power Solutions' production tenant, live remote charging and real payments. Simulation flags are false. Do not override them with staging-demo arguments. Client build flags do not replace backend authorization or server-side feature settings.

## Foreground location and App Store processing warnings

The app requests location while open to center the map and find nearby chargers. `NSLocationWhenInUseUsageDescription` explains this use; background location and Always authorization are not part of the current feature. The Podfile sets `BYPASS_PERMISSION_LOCATION_ALWAYS=1` for every `geolocator_apple` build configuration while preserving existing compiler definitions and Flutter's build settings. This excludes the plugin's unused Always authorization code that can trigger `ITMS-90683`. Do not add an Always-location purpose string or background location mode to conceal that warning.

Flutter 3.47.9 can resolve plugins through Swift Package Manager, which bypasses the CocoaPods hook. The pinned `geolocator_apple` 2.3.14 Swift package does not define the foreground-only bypass, even though newer upstream documentation describes an environment-variable option. The project therefore explicitly disables Swift Package Manager in `pubspec.yaml`, so both local and Codemagic builds apply the existing CocoaPods hook. A runner's global Flutter configuration is insufficient as the release contract. Keep the generated Swift package integration references in Xcode; Flutter retains that scaffolding when SwiftPM is disabled. Re-enable SwiftPM only after the pinned plugin supports the bypass and a signed build passes Apple's processing checks.

For the first build containing this project configuration correction, clear the Codemagic dependency/build cache so plugin resolution and CocoaPods settings are regenerated. The pre-build script also cleans generated Flutter artifacts before restoring the locked dependencies and preparing Firebase. Build from the updated `main` commit and use an unused iOS build number. Do not rerun the old Build 2 commit. Test nearby-station location permission when allowed and denied on an iPhone; denial must leave manual station discovery available. The Windows regression checks and Ruby Podfile-hook test do not prove a signed IPA or Apple's binary processing. Confirm that the new upload no longer reports `ITMS-90683` before selecting it for review. No backend deployment or Android store upload is required for this change.

`ITMS-90725` on the earlier iOS 26.5 SDK upload is an advance SDK deadline notice. Apple's April 2027 requirement is the iOS 27 SDK or newer; the Xcode 27.2 Codemagic image meets that SDK generation while retaining the application's iOS 15.0 deployment target. These are build-time changes and require a new iOS binary, with no backend deployment or Android rebuild.

Add this **post-build script** to fail the workflow before upload if the signed archive has the wrong Firebase project, bundle ID, or APNs signing:

```bash
set -euo pipefail
cd "$CM_BUILD_DIR"
python3 scripts/verify-ios-archive.py \
  --environment production \
  --app apps/mobile/build/ios/archive/Runner.xcarchive/Products/Applications/Runner.app
```

Keep Flutter analysis and tests enabled. Preserve build logs and the signed IPA privately. Archive validation also rejects incorrect Google client IDs or a missing Google callback scheme. Successful macOS simulator compilation is separate evidence from a signed archive and physical-device push delivery.

Google sign-in on a public iOS release requires an equivalent privacy-focused login option under [App Review guideline 4.8](https://developer.apple.com/app-store/review/guidelines/#login-services), normally Sign in with Apple. The Google integration does not implement that additional provider. Internal TestFlight can validate Google sign-in, but do not submit this change for public App Review until the login-service requirement is satisfied.

## Upload and acceptance

1. Save the workflow; start a `main` build. Require a successful signing check and App Store Connect upload.
2. Wait for processing in App Store Connect, complete export-compliance questions accurately, and add the build to TestFlight.
3. On a physical iPhone, install through TestFlight, sign in with a verified account and allow notifications. Initial APNs registration can take several seconds; returning to the app retries registration. Denying notifications must not prevent sign-in, wallet access or charging.
4. With an approved test audience, explicitly publish a short-lived driver notice with **Send push notification** selected. Check foreground, background and locked-screen display, sound, and tap-to-Updates. A tenant-wide notice reaches that tenant's eligible installations; use an isolated test tenant unless a production announcement has been approved. Do not broadcast a test to all production users.
5. Check logout and account switching: the old user's registration must be revoked and notices must not cross tenant boundaries. An `accepted` delivery means Firebase accepted the request, not that the phone displayed it.
6. Before public App Review, verify the published privacy policy, App Privacy answers, support/deletion URLs, screenshots, review login and store metadata against the tested build.
7. Select the tested build on the public App Store version, submit for App Review, then release after approval using the chosen release option. A separate rebuild is unnecessary when the same signed build passes testing and its configuration remains correct.

For a staging iOS workflow use `test_env`, a separate secure plist from `evcs-power-solutions-staging` and `--environment staging` in both scripts. Do not use the production plist for staging. The Apple bundle/profile must match the chosen Firebase iOS app. The supplied production plist does not establish that staging's iOS Firebase app or APNs key has been configured.

Official references: [Firebase Flutter setup](https://firebase.google.com/docs/cloud-messaging/flutter/get-started), [Codemagic iOS signing](https://docs.codemagic.io/flutter-code-signing/ios-code-signing/), [App Store Connect upload](https://docs.codemagic.io/flutter-publishing/publishing-to-app-store/).

Location/SDK references: [Geolocator iOS configuration](https://github.com/Baseflow/flutter-geolocator/blob/main/geolocator/README.md#ios), [Codemagic Xcode 27.2 image](https://docs.codemagic.io/specs-macos/xcode-27-2/), [Apple's April 2027 SDK requirement](https://developer.apple.com/news/?id=k1mtkt1k).

Dependency-manager references: [Flutter project configuration](https://docs.flutter.dev/tools/pubspec#config-field), [disabling Swift Package Manager](https://docs.flutter.dev/packages-and-plugins/swift-package-manager/for-app-developers#how-to-turn-off-swift-package-manager), [Codemagic cache reset](https://docs.codemagic.io/knowledge-codemagic/caching/).
