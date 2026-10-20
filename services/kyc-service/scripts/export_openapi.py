"""Export the development contract without credentials or external services."""

import json
import os
import secrets
from pathlib import Path

from cryptography.fernet import Fernet

os.environ.update(
    {
        "KYC_ENVIRONMENT": "testing",
        "KYC_MODE": "local",
        "KYC_DATABASE_URL": "sqlite://",
        "KYC_REDIS_URL": "redis://localhost:6379/15",
        "KYC_REQUEST_SECRET": secrets.token_hex(32),
        "KYC_CALLBACK_SECRET": secrets.token_hex(32),
        "KYC_ENCRYPTION_KEY": Fernet.generate_key().decode(),
        "KYC_CALLBACK_URL": "http://platform.test/api/v1/webhooks/kyc",
    }
)

from app.main import app  # noqa: E402

root = Path(__file__).resolve().parents[3]
target = root / "packages/contracts/openapi/kyc.internal.v1.json"
target.write_text(json.dumps(app.openapi(), indent=2, sort_keys=True) + "\n")
print("Exported KYC internal v1 OpenAPI contract.")
