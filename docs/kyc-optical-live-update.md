# Optical KYC and live capture implementation — 24 September 2026

The owner chose optical ID checks + face matching + liveness and confirmed that VPS3 already has private access to VPS4. No product/network clarification remains pending for this implementation.

## Changed behavior

- New attempts snapshot `optical_v1`; existing attempts retain issuer requirements. Laravel requires passed optical structure, matching identity fields, face match and live challenge checks before automatic approval. Manual policy still requires an administrator. The readiness switch defaults off.
- Python parses conservative Philippine National ID/license text profiles and Philippine passport TD3 MRZ/check digits. Unknown or unclear formats fall back to review. `document` still means issuer authenticity and is unavailable; no government verification is implied.
- Flutter uses the official camera plugin for a front-camera preview and server-issued center/left/right prompts. The private processor validates timing, rotates tokens, rejects replay, checks face continuity with SFace and runs a pinned local anti-spoof-mn3 model. Audio is disabled. Only the reference selfie is retained; other frames are processed in memory.
- Challenge state is encrypted and expires. Replacement, cancellation, retries and inference races fail safely. Abandoned references are erased by beat with retry on storage failure. Completed live sessions must be submitted within ten minutes.
- Admin pages show the selected assurance level. Private deployment preflight requires live model settings for enabled optical environments. Production uses VPS2; staging uses VPS3; both reuse private database access.

Key files: `services/kyc-service/app/live_capture.py`, `app/optical.py`, `app/worker.py`; Flutter `lib/features/kyc/presentation/live_capture_screen.dart`; Identity `KycService.php`, `KycClient.php`, the live request/controller routes; both Compose overlays and the deployment validator. The updated internal OpenAPI contract includes challenge requests/responses. [ADR 0019](architecture/decisions/0019-optical-kyc-and-live-capture.md) records privacy, limits and rollout behavior.

## Verification

- Laravel: **151 tests, 1,280 assertions**, Pint, PHPStan, Composer validation and dependency audit passed.
- Flutter: **73 tests**, analyzer, web build and Android debug APK build passed. The debug APK uses `dart_defines.example.json` and the emulator API address `http://10.0.2.2:8000`; it is not a production mobile release.
- Python: **42 tests passed in Linux**, including real Tesseract OCR and synthetic challenge-to-worker approval. Ruff formatting/lint and mypy passed. Pinned Python dependencies and the five added Flutter camera packages had no known advisories in the checks performed.
- Deployment: **10 tests** across existing environment selection and KYC isolation/app setting propagation. Cluster and local overlay Compose rendering passed. Changed GitHub Actions workflows passed actionlint; `git diff --check` passed.
- All Laravel migrations succeeded in a disposable PostgreSQL 18/PostGIS instance. Alembic upgraded an existing synthetic issuer attempt without lowering its profile and created timezone-aware live expiry. The temporary database instance was removed afterwards.
- Official YuNet, SFace and anti-spoof-mn3 artifacts were checksum-verified and executed on synthetic input inside Linux. This validates compatibility, not face/PAD accuracy. Web admin production assets built successfully.
- Existing FastAPI/Starlette deprecation notices remain. Android reports an existing `mobile_scanner` Kotlin Gradle migration warning; the APK build succeeds. No tests were suppressed.

## Deployment and remaining acceptance

No Hostinger host was accessed or changed, no image was published, and no real identity images were processed. Follow the [Hostinger runbook](runbooks/kyc-hostinger.md): roll out all Laravel validators/migrations with the issuer profile during mixed versions, deploy processor Alembic 0002/models, release the updated mobile client, then select the optical profile. New core migration: `2026_09_24_000002_add_kyc_assurance_profile.php`; existing review-settings migration and permission synchronization also remain prerequisites.

Actual hostnames, private DNS/certificates, separate databases/buckets/secrets and storage/backup acceptance remain operator configuration. Face, PAD and pose thresholds intentionally have no production defaults. Test consented users, supported phones, lighting, mirroring, printed/screen/video attacks and digital injection threats, and document accepted operating thresholds before enabling automatic production approval. There is no certified-liveness, government-authenticity or measured-accuracy claim. Current manual policy and all existing KYC enforcement defaults remain unchanged.
