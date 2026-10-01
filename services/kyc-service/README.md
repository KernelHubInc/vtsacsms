# Power Solutions KYC processing service

FastAPI API, Celery worker/beat, PostgreSQL jobs/outbox, Redis replay/transport state, encrypted private local or S3 evidence, and Tesseract OCR. Laravel remains authoritative for identity, tenant access, consent, verification status, manual decisions and feature eligibility. Flutter communicates only with Laravel.

Read [the inspection report](../../docs/architecture/kyc-inspection.md), [ADR 0017](../../docs/architecture/decisions/0017-isolate-kyc-evidence-processing.md), and [the integration/runbook](../../docs/runbooks/kyc.md). This implementation does not assert regulatory compliance or certified biometric assurance.

For dedicated VPS2 production / VPS3 staging commands, private networking, models and the tenant admin review-mode setting, read [Hostinger KYC deployment](../../docs/runbooks/kyc-hostinger.md) and [ADR 0019](../../docs/architecture/decisions/0019-optical-kyc-and-live-capture.md). The owner selected optical ID checks plus self-hosted face matching and a live camera/PAD challenge. Issuer authenticity is not implemented or claimed. Automatic approval stays disabled until device/spoof evaluation, reviewed thresholds and operational acceptance.

## Local setup

From this directory, using Python 3.12+:

```powershell
python -m venv .venv
.venv/Scripts/python.exe -m pip install -r requirements.lock
.venv/Scripts/python.exe -m pip install -e '.[dev]'
.venv/Scripts/python.exe scripts/setup_local.py
docker compose --env-file .env build kyc-api kyc-test
docker compose --env-file .env up -d kyc-api kyc-worker kyc-beat
docker compose --env-file .env ps
```

`setup_local.py` creates ignored random local secrets without printing them. It refuses to overwrite an existing `.env`. API: `http://127.0.0.1:8090`; development docs: `/docs`; health: `/healthz`; readiness (database/schema and Redis): `/readyz`. PostgreSQL and Redis have no published ports. The service binds only to loopback for development. Migrations run once through `kyc-migrate` before API/worker startup. Never use these generated local secrets in staging.

For a host API/worker, use provisioned PostgreSQL/Redis URLs reachable from the host, then run `alembic upgrade head`, `uvicorn app.main:app --port 8090 --no-access-log`, `celery -A app.worker.celery worker`, and `celery -A app.worker.celery beat`. Tesseract and its English language data must be on PATH. Celery's supported deployment is Linux/Docker; do not use a Windows worker in staging.

## Isolated full-stack smoke test

After local Python dependencies and `.env` are prepared, run the following from this directory. This creates a separate `vtsa-kyc-integration` Compose project, a disposable core PostgreSQL database, and synthetic users. It does not connect to existing staging/production databases. The test platform is available at `http://127.0.0.1:8091`.

```powershell
.venv/Scripts/python.exe scripts/prepare_integration.py
$kycCompose = @('compose', '-p', 'vtsa-kyc-integration', '--env-file', '.env', '-f', 'docker-compose.yml', '-f', 'docker-compose.integration.yml')
docker @kycCompose build kyc-api kyc-platform
docker @kycCompose up -d kyc-api kyc-worker kyc-beat kyc-platform
docker @kycCompose exec -T kyc-platform php artisan migrate --force
docker @kycCompose exec -T kyc-platform php tests/kyc-smoke.php seed
.venv/Scripts/python.exe scripts/integration_smoke.py
```

The script signs in as `kyc.driver@example.test` and checks another synthetic user's denial, duplicate starts/submits, document/selfie uploads, actual worker processing, signed callbacks, manual rejection, resubmission, action required and approval. It also checks authentication, health, public stations and the map page. It respects API rate limits; a run can take several minutes. The synthetic reviewer is `kyc.reviewer@example.test` at `/admin/login`. The generated password is `KYC_SMOKE_PASSWORD` in the ignored local `.env`; no fixed password is committed or printed. Use the existing mobile environment settings to point Flutter at this Laravel instance for synthetic device tests. Never use a real ID here.

Stop this test project with `docker @kycCompose stop`. Its core database uses tmpfs: rerun migrations and the seed after stopping/restarting or recreating that database container. Persistent KYC evidence is isolated by Compose project and follows the same retention policy. Do not reuse this harness for staging.

## Configuration reference

The synthetic integration harness explicitly retains `issuer_v1` with manual review to test the legacy still-image flow. It does not claim to exercise real live-camera accuracy. `tests/test_live_capture.py` tests the new optical live protocol and its worker decision with synthetic images and injected inference results. To exercise real models, use the updated Flutter client, `self_hosted` mode and the pinned models/threshold configuration in the Hostinger runbook.

| Variable | Meaning |
| --- | --- |
| `KYC_ENVIRONMENT` | `local`, `testing`, `staging`, `production`. |
| `KYC_MODE` | `local` (OCR + manual review), `self_hosted` (OCR, optical profiles, local face comparison and live camera/PAD), `mock` (local/testing only), or `provider` (existing extension point). |
| `KYC_DATABASE_URL` | Dedicated PostgreSQL SQLAlchemy/psycopg connection URL. Never point it at core platform tables. |
| `KYC_REDIS_URL` | Dedicated Redis URL; shared deployments need a distinct DB/key namespace and network access control. |
| `KYC_REQUEST_SECRET` / `KYC_CALLBACK_SECRET` | Distinct directional secrets of at least 32 characters. Must match Laravel configuration. |
| `KYC_ENCRYPTION_KEY` | Fernet key; protects private image bytes and stored personal/extracted fields. Keep separately from database/storage backups. |
| `KYC_CALLBACK_URL` | Exact Laravel `/api/v1/webhooks/kyc` URL; no query, fragment, credentials or redirects. HTTPS required outside local/testing. |
| `KYC_STORAGE` | `local` or `s3`; staging/production require S3. |
| `KYC_STORAGE_ROOT` | Private local volume, default `/data/private`; never a webroot. |
| `KYC_S3_BUCKET`, `KYC_S3_ENDPOINT`, `KYC_S3_REGION`, `KYC_S3_KMS_KEY` | Dedicated private bucket/endpoint and optional KMS key. HTTPS for custom staging/production endpoints. Standard AWS credential chain/IAM supplies credentials. |
| `KYC_RETENTION_DAYS` | Finite evidence/PII retention from initial creation, default 30; range 1–365. Match Laravel consent text. This development default is not a legal retention decision. |
| `KYC_MAX_FILE_BYTES` | Default 6 MiB; JPEG/PNG only, at most 20 million pixels. |
| `KYC_MIN_DIMENSION`, `KYC_MIN_SHARPNESS` | Default 480 px and 18 edge-variance units. Tune with approved synthetic/consented examples. |
| `KYC_MOCK_OUTCOME` | `APPROVED`, `REJECTED`, `NEEDS_REVIEW`, `PROCESSING_ERROR`; server-side only. |
| `KYC_PROVIDER_FACTORY` | Installed `package.module:factory` returning a `Providers` bundle; required in provider mode. |
| `KYC_REQUESTS_PER_MINUTE` | Aggregate authenticated internal rate limit, default 120. |
| `KYC_LIVENESS_MODEL`, `KYC_LIVENESS_SHA256` | Installed anti-spoof-mn3 ONNX path and reviewed checksum; required for optical live capture. |
| `KYC_LIVENESS_ACCEPT_THRESHOLD`, `KYC_LIVENESS_REJECT_THRESHOLD` | Evaluated real-person probability bands; no production default. |
| `KYC_LIVENESS_CENTER_TOLERANCE`, `KYC_LIVENESS_TURN_THRESHOLD` | Evaluated nose/eye-line ratio bands for the active prompt; no production default. |

## Processing and safety

The API authenticates the exact request bytes before Pydantic validation. Image uploads are bounded by actual body bytes, base64-decoded with strict validation, checked by content/MIME, decoded with decompression-bomb protection, checked for minimum resolution and blur, EXIF-oriented, normalized and stripped of metadata. UUID object names are generated server-side. Images and PII are encrypted; storage paths never appear in mobile responses.

OCR runs in a worker using a fixed Tesseract command, bounded execution time, no shell, and no saved raw OCR text. Tesseract is distributed under [Apache-2.0](https://github.com/tesseract-ocr/tesseract/blob/main/LICENSE); the container's real OCR test verifies compatibility with this Python pipeline. The extraction adapter recognizes labeled fields and is deliberately conservative: OCR is evidence, not proof of identity or document authenticity. Local face match and liveness return `unavailable`; local mode always needs manual review. No face model weights or biometric templates are included. A single uploaded selfie does not certify liveness.

Submitted jobs live in PostgreSQL. Beat rediscovers them every ten seconds, so a lost broker message does not lose a job. Workers claim short database transactions with a five-minute lease, process outside transactions, and reject stale lease results. Abrupt worker death is recovered after lease expiry. Explicit processing failures become `ACTION_REQUIRED` with a safe reason and a durable callback. Workers never update Laravel tables.

Callbacks are inserted with the result transaction. Failed deliveries retry with bounded exponential delay; they remain discoverable until delivered. Worker health uses Celery inspect/ping; failed jobs and processing duration are visible through persisted safe results and the Laravel admin page. Readiness does not imply provider readiness; monitor worker health and pending callback age separately.

## Tests and synthetic workflows

```powershell
.venv/Scripts/python.exe -m pytest -q
.venv/Scripts/python.exe -m ruff check app tests scripts migrations
.venv/Scripts/python.exe -m ruff format --check app tests scripts migrations
.venv/Scripts/python.exe -m mypy app
.venv/Scripts/python.exe -m pip_audit -r requirements.lock
docker compose --env-file .env run --rm kyc-test
.venv/Scripts/python.exe scripts/synthetic_fixture.py
.venv/Scripts/python.exe scripts/export_openapi.py
```

Host tests skip actual OCR only if Tesseract is absent. Container/CI tests include a real synthetic-image OCR assertion. Fixtures are clearly fake and generated into ignored `private/fixtures`; they are not real IDs or faces. The API tests cover signatures, replay, expiry, tenant/subject ownership, idempotency conflicts, image validation, encrypted storage, worker outcomes, stuck-job recovery, callbacks, retention and fail-closed configuration.

Set `KYC_MODE=mock` and choose `KYC_MOCK_OUTCOME` in the ignored local `.env`, then recreate API/worker/beat. Each new submission uses that configured processor outcome. `NEEDS_REVIEW` enables admin review and `PROCESSING_ERROR` exercises safe failure. Under the default manual policy, processor `REJECTED` and `APPROVED` outcomes also require a reviewer decision; use the admin decision to exercise resubmission or the verified UI. Mock checks never satisfy automatic assurance. Never change outcomes through client identity values. Use only synthetic documents with mock mode. In local mode, use a synthetic OCR fixture and expect manual review.

## Provider integration

Implement `OCRProvider`, `DocumentVerificationProvider`, `FaceMatchProvider`, and `LivenessProvider` from `app/providers.py`, returning typed evidence/check results. Configure a factory returning `Providers` with a vendor-specific name and bounded network calls. The document provider must verify claimed identity against evidence, expiry and document authenticity; a certified liveness integration may require a vendor challenge/session extension to the versioned mobile contract. Do not mark a static selfie as certified liveness. Any missing check keeps the result in manual review; automatic approval in provider mode requires all checks to pass.

Review provider data regions, licensing, DPA, deletion API, replay/idempotency behavior, callbacks, failure mapping and security before enabling real identities. No vendor credentials, unlicensed models, hardcoded bank/tax details, or mock production adapter are included.

## Retention and erasure

Beat tombstones expired drafts/jobs/review queues, clears encrypted personal/extracted data, deletes private objects, then records deletion completion and emits a callback. User withdrawal immediately hides sensitive details and schedules the same deletion; a running worker cannot overwrite a cancelled/expired job. Storage failure preserves object references for retry and does not stop other processing. Private local storage also removes UUID objects older than the retention period, covering a crash before their database reference was committed. Local/S3 storage must be dedicated to KYC. Configure an S3 lifecycle rule as a second deletion boundary, including orphan objects, noncurrent versions and failed multipart uploads; apply retention to backups and replicas too. Verification status and hash-chained audit records are retained separately by Laravel; the organization must decide their lawful retention before production.

Do not delete the encryption key as an ordinary rotation procedure. Use a reviewed decrypt/re-encrypt migration with dual-key support before rotating it; otherwise existing evidence becomes unreadable. Alembic downgrade intentionally refuses destructive evidence deletion. Roll forward or restore an approved snapshot.

Troubleshooting: signature failures usually mean different directional secrets, clock skew, a changed proxy path, or altered body bytes. A 503 readiness means schema/PostgreSQL/Redis is unavailable. Pending jobs require worker and beat. Callback failures can be repaired by `php artisan kyc:reconcile`; inspect safe job/outbox fields, never dump identity payloads. Blurry/low-resolution images must be retaken. Mock mode failing to boot in staging is intentional.
