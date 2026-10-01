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
    try:
        settings()
        providers()
        with engine().connect() as connection:
            connection.execute(text("SELECT 1"))
        store = storage()
        if not isinstance(store, S3Storage):
            raise ValueError("Private S3 storage is required")
        store.client.head_bucket(Bucket=settings().s3_bucket)
        callback = urlsplit(settings().callback_url)
        with httpx.Client(timeout=10, follow_redirects=False) as client:
            response = client.get(f"{callback.scheme}://{callback.netloc}/health/ready")
            response.raise_for_status()
    except Exception:
        print(
            "KYC connectivity failed. Check private database, TLS, models and bucket access.",
            file=sys.stderr,
        )
        return 1
    print("KYC database, models and private storage are reachable.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
