# Power Solutions iOS builds

The existing Flutter app supports an iOS build target. Native compilation and device acceptance are pending access to macOS and Xcode. No signed IPA has been generated. Windows and the Linux staging VPS cannot compile an iOS binary.

## Required tools and accounts

- Keep the current Flutter application and its existing plugins; no replacement framework or new Dart dependency is required.
- Use a Mac with Xcode, its iOS SDK and simulator runtime, Flutter 3.44.8, Python 3, and CocoaPods. A hosted macOS runner can supply the build machine.
- Use an Apple Developer Program account for TestFlight or App Store distribution. A free Apple account can support limited personal device testing through Xcode on a Mac; it does not provide TestFlight distribution.
- Confirm the existing bundle identifier `com.vtsa.vtsaMobile` is available to your Apple team before registration. No team, certificate, provisioning profile, or signing secret is configured in source.

Follow the current [Flutter iOS setup](https://docs.flutter.dev/platform-integration/ios/setup) and [Apple account guidance](https://developer.apple.com/help/account/basics/about-your-developer-account). Do not put Apple credentials or signing files in Git.

## Configuration prepared in this repository

The native target is iOS 14.0, matching the installed Google Maps Flutter plugin's declared minimum. CocoaPods integration uses Flutter's standard Podfile. Secure storage has the Keychain entitlements required by the installed plugin. Camera, photo library, location and Face ID descriptions already exist in Info.plist.

The default build uses `dart_defines.staging-demo.json` and the staging API. This configuration is embedded in the binary: it must contain only public application configuration, never merchant signing keys or backend credentials. OpenStreetMap is the current map provider and needs no Google Maps key.

Native pod resolution, the resulting minimum iOS version, and Xcode compilation still need validation on macOS. Generate and review Podfile.lock after the first successful pod resolution. Review native icons, launch artwork, privacy manifests and App Store metadata before release.

## First simulator build

After committing and pushing these files, open GitHub Actions, select **iOS simulator build**, and choose **Run workflow** with the app version and build number. This workflow is manual only; it may consume billable macOS runner minutes according to the repository's GitHub plan.

The workflow runs Flutter analysis and tests, builds the simulator app, and uploads a zipped Runner.app artifact retained for seven days. It does not require Apple distribution signing. It has not yet been executed for this change. The artifact runs in a compatible Mac iOS simulator, not on an iPhone.

On a configured Mac, run from the repository root:

```bash
flutter doctor -v
python3 scripts/build-ios.py --mode simulator --version 1.0.16 --build-number 17
```

The simulator app is written to `apps/mobile/build/ios/iphonesimulator/Runner.app`.

## Signed IPA export

1. Set up Apple Developer membership, your app identifier, and the intended distribution method. TestFlight is recommended for testers using their own iPhones.
2. On the Mac, run `flutter pub get` in `apps/mobile`, then `pod install` in `apps/mobile/ios`.
3. Open `apps/mobile/ios/Runner.xcworkspace` in Xcode. Select the Runner target, choose your real team in Signing & Capabilities, and verify the bundle identifier and Keychain configuration. Let Xcode manage signing where appropriate.
4. Make an archive and use Xcode's distribution/export flow to obtain real ExportOptions.plist settings for your distribution method. Save the file as the ignored `apps/mobile/ios/ExportOptions.local.plist`. Install the matching signing identity and provisioning profile on the build Mac. Never substitute a template team ID or a fake certificate.
5. From the repository root, run:

```bash
python3 scripts/build-ios.py --mode ipa \
  --version 1.0.16 --build-number 17 \
  --export-options apps/mobile/ios/ExportOptions.local.plist
```

Use a new build number for subsequent uploads. The script runs analysis and tests before export and requires a newly created IPA in `apps/mobile/build/ios/ipa/`; an old export or an archive without an IPA is not reported as success. See [Flutter iOS release instructions](https://docs.flutter.dev/deployment/ios) for Xcode export and App Store Connect upload.

Validate and upload through Xcode or Transporter, then assign TestFlight testers after Apple processes the build. An IPA download alone does not grant installation rights: its signing and provisioning method must permit the target device.

## Device acceptance and downloads

Before calling the iOS release ready, test registration, email verification, login, password recovery, Keychain persistence, PIN/Face ID, permission refusal/retry, maps/location, charger QR scanning, wallet QR display and payment return, and charging-session recovery/start/stop/history. Use the staging backend and coordinate any real payment or physical charging test with its operator. Simulator success does not verify camera, biometrics, live payment, or physical charging behavior.

For the requested two release links, retain a version-specific signed artifact for the chosen distribution method and use a stable TestFlight invitation for the current demo. Neither an IPA link nor a TestFlight invitation exists until the account, signing, build and distribution steps are completed. The Android APK links remain separate.
