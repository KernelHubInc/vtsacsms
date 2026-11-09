# KH-UI-UX-v02: welcome, secure access and map home

## Scope

Apply the approved [EVCS welcome and secure-access design](https://www.figma.com/design/wmrJ4VV3zw6uZC4tHdD9Qv/EVCS?node-id=57-1621) to the shared Flutter consumer browser/native app. The Laravel workforce portals are separate surfaces.

New install: native launch screen → EVCS welcome → password sign-in or registration/verification → Explore map. Returning enrolled native session: secure welcome → local PIN or native biometrics → existing Identity session check → Explore. The extra A01–A03 introduction is excluded. Optional setup remains in Profile.

The home map uses the configured provider and real bounded station queries. It retains compatibility filtering, freshness, favorites, contextual location permission, wallet authorization and charging recovery. The bottom bar exposes Explore, Sessions, Scan, Wallet and Profile.

## Safety and operations

- Identity session validation, the persistent five-attempt PIN limit and tenant/device binding remain in the existing controller/storage workflow.
- Password fallback and account switching clear local setup and account projections; they do not send a charging stop.
- No API, database migration, payment-provider configuration or OCPP change.
- Browser/desktop builds retain password authentication. Native-only PIN/biometric setup is not offered there.
- Real Android/iOS biometric behavior still needs physical-device release verification. iOS artifacts require macOS/Xcode.

## Release sequence

1. Commit and push KH-UI-UX-v02 first. Review its changes and run the complete configured checks.
2. Promote the verified commit to test_env without force-pushing or discarding unrelated branch changes.
3. Build browser and Android releases from that committed source with the existing staging defines. Retain the existing Android signing certificate.
4. Publish the browser bundle as a new immutable directory under /var/www/staging-mobile/releases and atomically advance current. Retain the previous target for rollback.
5. Publish a new versioned APK and verify its complete checksum and public download before advancing Power-Solutions-Demo.apk. Do not replace an existing version with different bytes.
6. Verify the staging browser entry, sign-in, map, asset requests, version metadata and both APK links. Record commit, artifact checksums and actual release URLs after publication.

The prepared next APK version is 1.0.45, build 46, following the verified 1.0.44/build 45 release. Check the live target again before publication to prevent a downgrade. Deployment remains pending until staging SSH access and all checks pass.
