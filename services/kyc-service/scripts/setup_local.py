"""Generate ignored local-only secrets without printing them or touching deployed environments."""

import secrets
from pathlib import Path

from cryptography.fernet import Fernet

root = Path(__file__).resolve().parents[1]
target = root / ".env"
if target.exists():
    raise SystemExit("Existing .env preserved. Edit it explicitly to change local settings.")
password = secrets.token_hex(24)
values = {
    "KYC_ENVIRONMENT": "local",
    "KYC_MODE": "local",
    "KYC_DATABASE_URL": f"postgresql+psycopg://kyc:{password}@kyc-postgres:5432/kyc",
    "KYC_POSTGRES_PASSWORD": password,
    "KYC_REDIS_URL": "redis://kyc-redis:6379/0",
    "KYC_REQUEST_SECRET": secrets.token_hex(32),
    "KYC_CALLBACK_SECRET": secrets.token_hex(32),
    "KYC_ENCRYPTION_KEY": Fernet.generate_key().decode(),
    "KYC_CALLBACK_URL": "http://host.docker.internal:8000/api/v1/webhooks/kyc",
    "KYC_STORAGE": "local",
    "KYC_STORAGE_ROOT": "/data/private",
    "KYC_RETENTION_DAYS": "30",
    "KYC_MOCK_OUTCOME": "NEEDS_REVIEW",
}
target.write_text("\n".join(f"{key}={value}" for key, value in values.items()) + "\n")
target.chmod(0o600)
print("Created ignored local KYC environment. No secrets printed.")
