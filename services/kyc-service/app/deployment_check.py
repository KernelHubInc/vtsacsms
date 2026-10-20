"""Read-only connectivity checks without exposing connection details."""

import sys
from urllib.parse import urlsplit

import httpx
from sqlalchemy import text

from app.config import settings
from app.database import engine
from app.providers import providers
from app.storage import S3Storage, storage


def main():
    stage = "configuration"
    try:
        settings()
        stage = "models"
        providers()
        stage = "database"
        with engine().connect() as connection:
            connection.execute(text("SELECT 1"))
        stage = "storage"
        store = storage()
        if not isinstance(store, S3Storage):
            raise ValueError("Private S3 storage is required")
        store.client.head_bucket(Bucket=settings().s3_bucket)
        stage = "Laravel readiness"
        callback = urlsplit(settings().callback_url)
        with httpx.Client(timeout=10, follow_redirects=False) as client:
            response = client.get(f"{callback.scheme}://{callback.netloc}/health/ready")
            response.raise_for_status()
    except Exception as error:
        print(
            f"KYC connectivity failed: stage={stage}; error={type(error).__name__}. "
            "Connection details withheld.",
            file=sys.stderr,
        )
        return 1
    print("KYC database, models, private storage and Laravel readiness checks passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
