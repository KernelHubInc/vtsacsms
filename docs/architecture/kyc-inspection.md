# KYC repository inspection — 2026-09-23

Completed before implementation. The working tree was clean.

| Area | Existing implementation / integration decision |
| --- | --- |
| Platform | `apps/platform`: Laravel 13, PHP requirement ^8.3, documented runtime 8.4; Livewire 4, Filament 5, Tailwind 4. Preserve pinned versions. |
| Identity | Global `App\Models\User` has an internal integer key and public ULID; active tenant memberships and security-versioned Sanctum tokens. KYC references public subject ULIDs without expanding users. |
| API | `/api/v1`, `auth:sanctum`, `EstablishSanctumTenantContext`, verified-email middleware; correlation/request IDs. Mobile never receives internal service credentials. |
| Authorization | Organizations owns permission enums, tenant/resource-scoped role assignments and `AuthorizationService`. New KYC review permissions require explicit tenant-wide assignment. |
| Admin | Filament admin auto-discovers `Filament/Platform`; persistent panel tenant context. Add a tenant-scoped KYC page here. |
| Mobile | Flutter 3.44.6 / Dart 3.12, Dio `ApiClient`, ChangeNotifier controllers, GoRouter, secure token store, Power Solutions components. Add account/profile entry and protected route; retain map adapters. |
| Storage | Laravel local private/public disks and S3; existing local MinIO. KYC owns separate encrypted private evidence and never uses Laravel public media. |
| Infrastructure | Existing platform/queue/scheduler, PostgreSQL/PostGIS, Redis, Docker Compose, local isolated production/staging stacks, private WireGuard deployment guidance. Add an opt-in KYC Compose stack without replacing running deployments. |
| CI | GitHub Actions checks PHP formatting/static analysis/tests/audit, Flutter analysis/tests/build, Python gateway, contracts and containers. Add KYC checks. |
| Audit | Existing tenant-scoped hash-chained `AuditRecorder`; reuse it for KYC decisions and sensitive access. |
| Boundaries | Identity owns KYC status/consent/review and enforcement query contract. Separate Python service owns encrypted evidence, processing metadata, durable jobs and callback delivery. No cross-database writes. |
| Existing gaps | Customer-owned charging and wallet/payment mobile mutations are documented contract gaps. Enforce flags at existing applicable application boundaries and expose an Identity query contract for future wallet integration. |

The host PHP is 8.3.30 and host Python launcher has no registered interpreter. Docker is available with existing running project stacks. Validation will use isolated containers where host dependencies are missing, without resetting existing databases.

## Decisions and assumptions

- Public/cross-context identifiers remain ULIDs; private object filenames use random UUIDs.
- Tesseract OCR is Apache-2.0 ([upstream documentation](https://tesseract-ocr.github.io/tessdoc/)); image processing uses Pillow. OCR supplies evidence, never independent approval.
- Celery workers discover durable PostgreSQL jobs and callback outbox records. Redis is transport/replay state, never the sole durable job record. FastAPI explicitly recommends a worker system for [expensive computation](https://fastapi.tiangolo.com/tutorial/background-tasks/).
- Mock outcomes are server configuration, allowed only in local/testing. Local mode always requests human review. Production provider mode fails closed until an actual approved adapter is configured; no certification or regulatory compliance is asserted.
- Consent URLs/text/version, supported documents, retention, verification validity and feature enforcement are configuration decisions. Defaults are development policy examples and must be reviewed before staging with real identities.
- Mobile camera capture uses Flutter's maintained `image_picker` package; its [temporary cache and lost-data behavior](https://pub.dev/packages/image_picker) require explicit cleanup. No images are persisted in app state restoration.
