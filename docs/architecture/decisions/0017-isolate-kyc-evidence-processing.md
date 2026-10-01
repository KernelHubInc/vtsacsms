# ADR 0017: Isolate KYC evidence processing

Status: Accepted for development implementation. Production provider and privacy policy remain deployment decisions.

Identity in the Laravel modular monolith owns tenant-scoped verification lifecycle, consent receipt, manual decisions and feature authorization. A separately deployable FastAPI/Celery project owns encrypted evidence, OCR and processing records in its own PostgreSQL database. Flutter calls only Laravel.

Internal versioned requests and callbacks use distinct directional HMAC secrets, body digests, timestamps and nonce replay protection. Durable event identifiers deduplicate callback delivery. A monotonic processing version prevents stale results from overwriting newer state, and terminal manual decisions remain authoritative. A scheduled reconciliation query repairs missed callbacks.

Evidence uses private encrypted local/S3 storage with random UUID names, finite configurable retention, and no biometric templates. Provider protocols separate OCR, document validation, face match and liveness. Local OCR cannot auto-approve. Development mock mode is forbidden outside local/testing. Provider mode requires an installed, explicitly selected adapter and fails closed otherwise.

No existing feature requires KYC by default. Identity exposes a narrow enforcement contract for owning Charging/Payments/Billing workflows. No service writes another context's database.
