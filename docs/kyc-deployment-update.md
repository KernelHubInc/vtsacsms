# KYC deployment and policy update — 24 September 2026

Historical checkpoint: the later owner decisions and live-capture implementation are documented in [ADR 0019](architecture/decisions/0019-optical-kyc-and-live-capture.md) and the updated [Hostinger runbook](runbooks/kyc-hostinger.md). The unresolved choices described below have since been answered.

## Implemented

- `scripts/deploy-kyc.sh` and `scripts/validate-kyc-deployment.py`: explicit production/staging commands, immutable release/source guards, shared deployment lock, protected environment validation, private address/DNS/TLS checks, distinct KYC database/role, app-secret matching, read-only connectivity preflight, backup, migration and health-checked startup. The command does not change the firewall or deploy core Laravel.
- `infra/cluster/compose.kyc.yaml`, `kyc.Caddyfile`, `kyc.env.example`: one isolated KYC stack per environment. API/Redis have no published ports; TLS ingress binds only to a private interface and restricts source addresses. Dedicated Redis and external private PostgreSQL/S3-compatible storage follow the requested topology. The backup container receives only its database configuration, not evidence-encryption or storage secrets.
- `infra/cluster/compose.app.yaml` now forwards KYC settings to application services. The release workflow publishes a matching KYC image; KYC deployment is still an explicit separate command.
- Identity `KycSettings` application service, tenant model, additive migration, permission, Filament page and Blade view: default manual policy, readiness-gated automatic policy, server-side authorization, tenant-context lock and audit. Verification attempts retain the policy chosen at creation. Automatic approval requires retained evidence and passed mandatory checks; processor results cannot override manual policy or reviewer decisions.
- `services/kyc-service/app/self_hosted.py`: local OpenCV YuNet/SFace comparison with model integrity checks, calibrated acceptance/rejection bands, no persisted face templates, and separate optical data consistency. Model installation/loading helpers pin official artifacts and retain licenses. Runtime dependencies and tests include OpenCV/NumPy.
- [ADR 0018](architecture/decisions/0018-private-kyc-deployment-and-review-policy.md), requirements `KYC-009`–`KYC-011`, ownership/event documentation and the [Hostinger runbook](runbooks/kyc-hostinger.md).

## Verification completed locally

- Laravel: **148 tests, 1,184 assertions**, Pint, PHPStan, Composer validation and audit passed.
- Python: **29 tests passed in Linux**, including real Tesseract OCR. Ruff and mypy passed. The pinned dependency audit found no known vulnerabilities.
- Deployment configuration: **8 tests passed** across existing app configuration and new private KYC configuration; public/wildcard binds and public/mixed DNS fail validation.
- ShellCheck and Bash syntax checks passed. Caddy validated using isolated synthetic TLS material.
- All Laravel migrations, including the new policy migration, applied successfully to a disposable PostgreSQL 18/PostGIS instance with no published ports. The test instance was removed afterwards.
- The web production asset build passed. The KYC Linux test image built successfully. Pinned face models loaded and executed on blank synthetic input; this proves runtime compatibility, **not biometric accuracy**.
- The Python suite reports two existing FastAPI/Starlette deprecation warnings. No tests were suppressed to obtain a passing result.

## Unfinished or deployment-dependent work

**Complete unattended self-hosted KYC is not implemented.** A secure live challenge/PAD pipeline and issuer-authenticity checks for National ID, driver's license and passport remain missing. The current adapter returns unavailable for those checks, so it cannot automatically approve. The admin policy option is intentionally disabled until an accepted implementation is deployed. A decision is pending on whether automatic mode requires issuer-backed authenticity or only optical consistency plus face/liveness; no weaker interpretation has been silently selected.

No Hostinger server was modified, no production image was published from this workspace, and no real identity data was processed. Real private network addresses, certificate trust, private storage/SSE/lifecycle permissions, database roles, backups, consent policy, model evaluation and physical-device acceptance still need environment provisioning/validation. In particular, the stated VPS4 firewall allows only VPS1/VPS2: VPS3's authorized private database connection path is unresolved.

Roll out core migrations and the expanded callback validator to all app nodes before enabling the new processor checks. Run `security:sync-permissions` and grant the new settings permission deliberately. Existing mobile environment definitions already select the appropriate Laravel API; mobile builds never receive an internal KYC URL or service secret.
