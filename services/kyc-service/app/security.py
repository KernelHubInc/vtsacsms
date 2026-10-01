import hashlib
import hmac
import secrets
import time
from typing import Annotated, Protocol

from fastapi import Request, Security
from fastapi.security import APIKeyHeader
from redis import Redis
from redis.exceptions import RedisError
from starlette.concurrency import run_in_threadpool

from app.config import settings


class KycError(Exception):
    def __init__(self, code: str, status: int = 422):
        self.code = code
        self.status = status


class NonceStore(Protocol):
    def consume(self, nonce: str) -> bool: ...
    def allowed(self) -> bool: ...


class RedisNonceStore:
    def __init__(self) -> None:
        self.redis = Redis.from_url(
            settings().redis_url.get_secret_value(), socket_connect_timeout=2, socket_timeout=2
        )

    def consume(self, nonce: str) -> bool:
        return bool(self.redis.set(f"kyc:nonce:{nonce}", "1", nx=True, ex=610))

    def allowed(self) -> bool:
        key = f"kyc:rate:{int(time.time()) // 60}"
        pipe = self.redis.pipeline()
        pipe.incr(key)
        pipe.expire(key, 120)
        count, _ = pipe.execute()
        return count <= settings().requests_per_minute


def signature(secret: str, method: str, path: str, timestamp: str, nonce: str, body: bytes) -> str:
    canonical = "\n".join(
        (method.upper(), path, timestamp, nonce, hashlib.sha256(body).hexdigest())
    )
    return hmac.new(secret.encode(), canonical.encode(), hashlib.sha256).hexdigest()


def signed_headers(secret: str, method: str, path: str, body: bytes) -> dict[str, str]:
    timestamp, nonce = str(int(time.time())), secrets.token_hex(16)
    return {
        "X-KYC-Timestamp": timestamp,
        "X-KYC-Nonce": nonce,
        "X-KYC-Signature": signature(secret, method, path, timestamp, nonce, body),
        "Content-Type": "application/json",
    }


async def authenticate(
    request: Request,
    _signature_header: Annotated[
        str | None,
        Security(
            APIKeyHeader(
                name="X-KYC-Signature",
                scheme_name="InternalHMAC",
                auto_error=False,
                description="HMAC-SHA256 over method, path, timestamp, nonce and body digest. "
                "Also send X-KYC-Timestamp and a fresh X-KYC-Nonce; see the integration runbook.",
            )
        ),
    ] = None,
) -> None:
    timestamp = request.headers.get("X-KYC-Timestamp", "")
    nonce = request.headers.get("X-KYC-Nonce", "")
    supplied = request.headers.get("X-KYC-Signature", "")
    if (
        not timestamp.isdigit()
        or abs(int(time.time()) - int(timestamp)) > 300
        or len(nonce) != 32
        or any(c not in "0123456789abcdef" for c in nonce)
        or request.url.query
    ):
        raise KycError("SERVICE_AUTHENTICATION_FAILED", 401)
    expected = signature(
        settings().request_secret.get_secret_value(),
        request.method,
        request.url.path,
        timestamp,
        nonce,
        await request.body(),
    )
    if not hmac.compare_digest(supplied, expected):
        raise KycError("SERVICE_AUTHENTICATION_FAILED", 401)
    try:
        store = request.app.state.nonces
        if not await run_in_threadpool(store.consume, nonce):
            raise KycError("SERVICE_REPLAY_REJECTED", 401)
        if not await run_in_threadpool(store.allowed):
            raise KycError("RATE_LIMITED", 429)
    except RedisError as exc:
        raise KycError("SERVICE_UNAVAILABLE", 503) from exc
