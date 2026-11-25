# Google sign-in resume correction

The client correction in ADR 0040 prevents a guest notice request or an old
session's delayed unauthorized response from expiring a newly established Google
login. Genuine rejection of the current session still requires sign-in. It does
not change Google token verification, account-linking policy or tenant scope.

## Release scope

Distribute new Android and iOS binaries and replace both browser bundles. Native
artifacts built from `c7193a7c1da392799dac1dc9e7d8c2d93ebfe480` may be reused only
when their recorded `apps/mobile` Git tree matches the promoted release. Record
their actual build commit, signing certificate and SHA-256; do not relabel their
build provenance as a later commit.

The release audit also identified the existing `league/csv` 9.28.0 dependency as
affected by [GHSA-m9j4-54gj-jvf8](https://github.com/thephpleague/csv/security/advisories/GHSA-m9j4-54gj-jvf8).
The minimal vendor patch is 9.28.1. Its charset converter bounds buffering of
invalid multibyte input, addressing a denial-of-service risk. No advisory is
suppressed. This lock-file update requires a platform image rollout as well as
the client release. No application schema migration or authentication setting
change is introduced.

## Verification and rollout

1. Require Composer audit, platform checks, Flutter regressions, emulator
   journeys and the repository CI/image-publishing gates to pass. A failed SDK
   download requires a successful fresh run; it does not justify skipping tests.
2. Follow the staging-first procedure in `release-promotion.md`. Preserve the
   existing production worker ownership and staging consumers, Google sign-in,
   remote charging and live QR Ph configuration.
3. Verify public browser release metadata and JavaScript hashes, and the staging
   APK download hash. Keep prior files and image references for rollback.
4. Verify Google sign-in with an actual account on both browser origins, a
   Play-installed Android build and a newly signed production iOS build. Include
   chooser cancellation, return to the app, registration, password-confirmed
   linking, logout/re-login and switching accounts. Fixtures cannot confirm
   external OAuth registration or signing-certificate settings.

Staging iOS requires its own approved iOS OAuth client before enabling Google
sign-in there. Never substitute production client identifiers. Windows-built
artifacts do not verify an iOS archive; rebuild with the recorded Codemagic
settings and run the archive verifier.

If rollback is required, restore the preceding client assets and platform image
without restoring the financial database or changing charging/payment flags.
Retain the audit findings and schedule a roll-forward security patch.
