# KYC implementation report — 2026-09-23

KYC is integrated into the existing Laravel/Flutter application, with a separately deployable Python processor. Existing environments keep KYC and all enforcement flags disabled by default. The isolated synthetic integration stack uses actual PostgreSQL, Redis, a worker, and signed callbacks. No staging or production deployment was performed.

## 1. Existing architecture discovered

Laravel 13, PHP ^8.3 with an 8.4 container runtime, Livewire 4, Filament 5, Tailwind 4, PostgreSQL/PostGIS and Redis; Flutter 3.44.6 pinned with Dio, GoRouter and ChangeNotifier. Identity uses public ULIDs, Sanctum tokens, active tenant membership and security-version checks. Organizations owns permissions; the existing audit recorder maintains a hash chain. See the [pre-implementation inspection](architecture/kyc-inspection.md) and [ADR 0017](architecture/decisions/0017-isolate-kyc-evidence-processing.md).

## 2. Files created

- `services/kyc-service/`: typed API, validation/security, SQLAlchemy persistence, providers, OCR/image pipeline, encrypted storage, worker/outbox/retention, Alembic, tests, Docker and developer scripts.
- `apps/platform/app/Modules/Identity/`: KYC enum/model, use cases, eligibility contract, HTTP client and HMAC signer; additional KYC requests/controllers, reconciliation command and admin page.
- `apps/mobile/lib/features/kyc/`: domain/repository, controller, capture cleanup and themed onboarding/status screens.
- Laravel migration, Blade admin view, PHP/mobile tests, internal OpenAPI contract, opt-in Compose overlay, architecture and operating documentation.

The [file inventory](kyc-file-inventory.txt) lists each new and modified file exactly.

## 3. Files modified

Laravel routes, exception handling, request cache headers, permission catalog, startup configuration guards, scheduler, Charging/Payments enforcement boundaries and environment example; Flutter dependencies, navigation, dependency wiring, account/profile links and iOS camera usage text; GitHub Actions, project README, architecture ownership/event catalog, requirements and admin registration test. Map, charger, OCPP and authentication implementations retain their existing behavior.

## 4. Migrations

- Laravel: `2026_09_23_000001_create_kyc_verifications.php` creates tenant-indexed verification metadata and idempotent event history. Users are unchanged; sensitive review notes are encrypted.
- Python: `0001_evidence_jobs.py` creates private verification/evidence metadata and callback outbox tables with timezone-aware instants.

Both ran successfully in isolated PostgreSQL databases. Roll forward; destructive down migrations intentionally refuse to erase evidence/history. Enable the module only after migrations, service configuration and permission synchronization.

## 5–7. Dependencies

Python runtime: FastAPI, Uvicorn, Pydantic Settings, SQLAlchemy, Alembic, psycopg, Redis, Celery, HTTPX, Pillow, cryptography and boto3. Development: pytest, Ruff, mypy and pip-audit. Exact runtime versions are in `requirements.lock`. Tesseract plus English data are installed in the container; its [Apache-2.0 license](https://github.com/tesseract-ocr/tesseract/blob/main/LICENSE) and real OCR behavior were checked. No biometric model weights were added.

No Laravel Composer dependencies were added. Flutter adds `image_picker` 1.2.3 and its locked platform dependencies; existing packages were preserved.

## 8. Environment variables

Laravel: `KYC_ENABLED`, `KYC_REQUIRED_FOR_CHARGING`, `KYC_REQUIRED_FOR_PAYMENT`, `KYC_REQUIRED_FOR_WALLET`, `KYC_SERVICE_URL`, `KYC_REQUEST_SECRET`, `KYC_CALLBACK_SECRET`, `KYC_CONSENT_VERSION`, `KYC_CONSENT_TEXT`, `KYC_PRIVACY_URL`, `KYC_TERMS_URL`, `KYC_CONSENT_URL`, `KYC_RETENTION_DAYS`, `KYC_VALIDITY_DAYS`.

Python adds environment/mode, dedicated database/Redis URLs, encryption key, callback URL, storage/S3 settings, file/quality limits, provider factory, mock outcome and internal rate limit. See the complete [configuration reference](../services/kyc-service/README.md#configuration-reference). Compose additionally uses a generated local PostgreSQL password and operator-supplied staging DNS/certificates/private bind. The synthetic harness generates its own application key/password and requires `KYC_SMOKE=true` with `APP_ENV=testing`.

## 9. API endpoints

Laravel `/api/v1/kyc`: `GET status`, `POST start`, `POST resubmit`, `GET verification/{id}`, and `POST verification/{id}/{document|selfie|submit|cancel}`. Signed callback: `POST /api/v1/webhooks/kyc`.

Internal Python `/api/v1/verifications/{id}`: signed `PUT` creation and signed `POST` routes for `snapshot`, `details`, `image`, `evidence`, `submit`, and `erase`. Health/readiness expose only safe status. The [generated internal OpenAPI](../packages/contracts/openapi/kyc.internal.v1.json) defines strict schemas. Mobile communicates exclusively with Laravel.

## 10. Security and privacy controls

Authenticated tenant/subject ownership, separate metadata/review/sensitive permissions, self-review denial, reasoned audited decisions, distinct directional HMAC secrets, body signatures, timestamp/replay protection, rate limits, bounded uploads and HTTP timeouts, safe errors and no-store evidence responses. Files undergo MIME/magic-byte validation, resolution/blur checks, orientation/metadata removal and normalization. UUID objects and identity fields are encrypted privately; Laravel does not retain raw identity fields or documents. Retention, retryable deletion and local orphan cleanup are implemented; S3 lifecycle/backup erasure is an explicit deployment requirement.

Mock mode is restricted to local/testing. Local OCR never auto-approves. Provider approval requires all OCR/document/face/liveness checks. Missing assurance remains manual review. Images are temporary in mobile memory, picker files are removed, and lost picker data is discarded. No real IDs, shared passwords or generated secrets are included in the change.

## 11. Tests created

Python tests exercise signature/replay/tamper, ownership, lowercase repository ULIDs, schema validation, idempotency, upload rejection/normalization, private preview, encrypted persistence, mock outcomes, worker leases, callback retry, deletion/retention/orphans, provider fail-closed behavior and actual synthetic Tesseract OCR.

Ten Laravel KYC feature tests exercise consent, idempotency, ownership/tenant isolation, callbacks/replay/reordering, review permissions, admin rendering/masking/auditing, outages, upload rejection, feature flags, expiry/resubmission and rate-limit headers. Ten Flutter KYC tests cover consent through document/selfie preview and submission, capture denial, retry/restart, pending/approved/rejected/action/expired states and service failures. A separate real-service smoke script covers the full integration.

## 12. Verification results

| Check | Result |
| --- | --- |
| Full Laravel PHPUnit suite | 142 tests, 1,114 assertions passed |
| PHP formatting, PHPStan, Composer validation/audit | Passed; no Composer vulnerability advisories |
| KYC Python Ruff/mypy | Passed |
| KYC container tests including actual OCR | 25 passed |
| Python runtime dependency audit | No known vulnerabilities; cryptography updated to 50.0.1 following the initial audit |
| Full Flutter suite / analysis | 71 tests passed; no analysis issues |
| Flutter web release / Android debug APK | Both built successfully |
| Platform container / production Vite assets | Built successfully |
| Real Laravel → Python → worker → callback | Uploads, duplicate starts/submits, ownership denial, review, rejection, action-required/resubmission and approval passed |
| Existing auth, map and station discovery | Full tests and real HTTP smoke checks passed |
| OCPP gateway regression | 41 tests passed plus the separate real Redis integration test passed; Ruff/mypy passed |
| Shared contracts | Lint/schema validation and generation passed; existing generated files unchanged |
| Existing infrastructure environment selection | Three tests passed |
| Compose and workflow configuration | Local integration and staging application overlays parsed successfully; actionlint 1.7.12 passed |
| Changed-file / integration-log scan | No generated credentials/private keys in changed files; no known synthetic identity markers or generated secrets in integration logs |

Physical Android/iOS camera acceptance and an iOS build were not performed on this Windows host. Camera behavior was exercised with a synthetic picker in Flutter; real permission/background behavior remains device acceptance work. The Android build reports an existing `mobile_scanner` Kotlin migration warning. Python test dependencies report deprecation warnings; tests pass without suppressing them.

## 13. Docker services

KYC API, Celery worker, Celery beat, one-shot Alembic migration, private PostgreSQL, private Redis and an opt-in test image. Staging adds a private Caddy TLS ingress compatible with the existing deployment approach. The isolated smoke overlay adds its own Laravel instance and disposable PostGIS database. Database/Redis ports are not published; local API/application ports are loopback-only.

## 14–15. Local startup and synthetic test workflow

Use the exact commands in the [service README](../services/kyc-service/README.md#local-setup), then its [isolated full-stack smoke test](../services/kyc-service/README.md#isolated-full-stack-smoke-test). `setup_local.py` and `prepare_integration.py` generate ignored credentials without printing them. The smoke driver/reviewer use `kyc.driver@example.test` and `kyc.reviewer@example.test`; their generated password stays in `KYC_SMOKE_PASSWORD` in the ignored service `.env`.

The isolated Laravel demo is `http://127.0.0.1:8091`; review is `/admin/kyc-verifications`. Run `scripts/synthetic_fixture.py` for clearly fake OCR images. The smoke script proves `NEEDS_REVIEW`, rejection, action required, resubmission and approval. Explicit mock outcomes also cover automatic approval/rejection and processing failure in the Python tests. No approval depends on an email address or identity value.

## 16. Remaining production decisions

Review actual document acceptance, consent text/URLs/version, verification validity, evidence/audit/backup retention and erasure, reviewer privileges/MFA, regional data handling, rate/capacity limits and operational alerts. Provision private networking, TLS, dedicated databases, S3 IAM/lifecycle, encryption-key custody and coordinated rotation. Existing wallet mutations are not implemented in this repository; future wallet operations must call Identity's provided eligibility contract. Enforcement flags remain false until the business chooses to enable them.

## 17. Actual provider requirements

Commercial document authenticity/identity checks, certified liveness and production face matching need an approved provider adapter and credentials. Vendor challenge/session flows may require an extension to the versioned mobile contract. The provided interfaces avoid vendor coupling; local OCR and synthetic mocks make no certification or regulatory-compliance claim.

## 18. Exact staging deployment instructions

Follow the [staging sequence](runbooks/kyc.md#exact-staging-deployment-sequence): provision the named real infrastructure and reviewed policy values, configure the private KYC environment and trusted TLS ingress, migrate, start API/worker/beat, apply the opt-in Laravel overlay on staging nodes, synchronize/assign permissions, reconcile and run synthetic acceptance checks. The runbook includes the precise Compose commands, required ingress limits, health checks and rollback procedure. No existing production configuration or database was replaced.
