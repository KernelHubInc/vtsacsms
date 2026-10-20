import json
import time
from pathlib import Path
from typing import Any, Protocol
from uuid import UUID, uuid4

import boto3
from cryptography.fernet import Fernet

from app.config import settings


def cipher() -> Fernet:
    return Fernet(settings().encryption_key.get_secret_value().encode())


def seal(value: dict[str, Any]) -> str:
    return cipher().encrypt(json.dumps(value).encode()).decode()


def unseal(value: str | None) -> dict[str, Any]:
    return json.loads(cipher().decrypt(value.encode())) if value else {}


class StorageProvider(Protocol):
    def put(self, data: bytes) -> str: ...
    def get(self, key: str) -> bytes: ...
    def delete(self, key: str) -> None: ...


class LocalStorage:
    def __init__(self) -> None:
        self.root = Path(settings().storage_root)
        self.root.mkdir(parents=True, exist_ok=True, mode=0o700)

    def path(self, key: str) -> Path:
        return self.root / str(UUID(key))

    def put(self, data: bytes) -> str:
        key = str(uuid4())
        with self.path(key).open("xb") as stream:
            stream.write(cipher().encrypt(data))
        self.path(key).chmod(0o600)
        return key

    def get(self, key: str) -> bytes:
        return cipher().decrypt(self.path(key).read_bytes())

    def delete(self, key: str) -> None:
        self.path(key).unlink(missing_ok=True)

    def prune_expired(self) -> int:
        # Covers objects orphaned by a crash between the storage write and database commit.
        cutoff = time.time() - settings().retention_days * 86400
        deleted = 0
        for path in self.root.iterdir():
            if deleted >= 100:
                break
            if not path.is_file() or path.stat().st_mtime > cutoff:
                continue
            try:
                key = str(UUID(path.name))
            except ValueError:
                continue
            self.delete(key)
            deleted += 1
        return deleted


class S3Storage:
    def __init__(self) -> None:
        config = settings()
        self.client = boto3.client(
            "s3", endpoint_url=config.s3_endpoint, region_name=config.s3_region
        )
        self.bucket = config.s3_bucket

    def put(self, data: bytes) -> str:
        key = str(uuid4())
        encryption = (
            {"ServerSideEncryption": "aws:kms", "SSEKMSKeyId": settings().s3_kms_key}
            if settings().s3_kms_key
            else {"ServerSideEncryption": "AES256"}
        )
        self.client.put_object(
            Bucket=self.bucket,
            Key=key,
            Body=cipher().encrypt(data),
            ContentType="application/octet-stream",
            **encryption,
        )
        return key

    def get(self, key: str) -> bytes:
        value = self.client.get_object(Bucket=self.bucket, Key=str(UUID(key)))
        return cipher().decrypt(value["Body"].read())

    def delete(self, key: str) -> None:
        self.client.delete_object(Bucket=self.bucket, Key=str(UUID(key)))


def storage() -> StorageProvider:
    return S3Storage() if settings().storage == "s3" else LocalStorage()
