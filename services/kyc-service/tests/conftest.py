import base64
import json
import os
import secrets
from io import BytesIO

import pytest
from cryptography.fernet import Fernet
from fastapi.testclient import TestClient
from PIL import Image, ImageDraw
from pydantic import SecretStr

os.environ.update(
    {
        "KYC_ENVIRONMENT": "testing",
        "KYC_MODE": "mock",
        "KYC_DATABASE_URL": "sqlite://",
        "KYC_REDIS_URL": "redis://localhost:6379/15",
        "KYC_REQUEST_SECRET": secrets.token_hex(32),
        "KYC_CALLBACK_SECRET": secrets.token_hex(32),
        "KYC_ENCRYPTION_KEY": Fernet.generate_key().decode(),
        "KYC_CALLBACK_URL": "http://platform.test/api/v1/webhooks/kyc",
    }
)

from app.config import settings  # noqa: E402
from app.database import Base, engine  # noqa: E402
from app.main import create_app  # noqa: E402
from app.security import signed_headers  # noqa: E402


class MemoryNonces:
    def __init__(self):
        self.seen = set()

    def consume(self, value):
        if value in self.seen:
            return False
        self.seen.add(value)
        return True

    def allowed(self):
        return True


@pytest.fixture(autouse=True)
def isolated(tmp_path):
    config = settings()
    config.database_url = SecretStr(f"sqlite:///{tmp_path / 'test.db'}")
    config.storage_root = str(tmp_path / "private")
    config.mode = "mock"
    config.mock_outcome = "NEEDS_REVIEW"
    config.max_file_bytes = 6 * 1024 * 1024
    engine.cache_clear()
    Base.metadata.create_all(engine())
    yield config
    engine().dispose()
    engine.cache_clear()


@pytest.fixture
def client():
    app = create_app()
    app.state.nonces = MemoryNonces()
    with TestClient(app) as client:
        yield client


@pytest.fixture
def scope():
    return {"tenant_id": "01J00000000000000000000001", "subject_id": "01J00000000000000000000002"}


@pytest.fixture
def payload(scope):
    return {
        **scope,
        "document": {"key": "passport", "front": True, "birth_date": True, "issuing_country": True},
        "personal": {
            "full_name": "SYNTHETIC TEST PERSON",
            "birth_date": "1990-01-01",
            "issuing_country": "PH",
        },
        "consent_version": "test-v1",
    }


def synthetic_image(size=(900, 600)) -> bytes:
    image = Image.new("RGB", size, "white")
    draw = ImageDraw.Draw(image)
    for x in range(20, size[0] - 20, 30):
        draw.line((x, 20, x, size[1] - 20), fill="navy", width=3)
    draw.text((40, 40), "SYNTHETIC TEST IMAGE - NOT A REAL ID", fill="black", font_size=24)
    output = BytesIO()
    image.save(output, "JPEG")
    return output.getvalue()


@pytest.fixture
def upload(scope):
    return {
        **scope,
        "kind": "front",
        "mime": "image/jpeg",
        "content": base64.b64encode(synthetic_image()).decode(),
    }


def signed(client, method, path, payload):
    body = json.dumps(payload).encode()
    return client.request(
        method,
        path,
        content=body,
        headers=signed_headers(settings().request_secret.get_secret_value(), method, path, body),
    )
