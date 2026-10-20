import logging
from contextlib import asynccontextmanager
from uuid import uuid4

from fastapi import Depends, FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from sqlalchemy import text

from app.config import settings
from app.database import engine
from app.logging import configure_logging
from app.providers import providers
from app.schemas import (
    CreateVerification,
    Details,
    LiveChallenge,
    LiveFrame,
    PrivateImage,
    ReadImage,
    Scope,
    Snapshot,
    Ulid,
    Upload,
)
from app.security import KycError, RedisNonceStore, authenticate
from app.service import KycService

logger = logging.getLogger("kyc")


@asynccontextmanager
async def lifespan(app: FastAPI):
    providers()
    yield


def create_app() -> FastAPI:
    configure_logging()
    config = settings()
    app = FastAPI(
        title="Power Solutions KYC internal API",
        version="1.0.0",
        lifespan=lifespan,
        docs_url="/docs" if config.environment in {"local", "testing"} else None,
        openapi_url="/openapi.json" if config.environment in {"local", "testing"} else None,
        redoc_url=None,
    )
    app.state.nonces = RedisNonceStore()
    service = KycService()

    @app.middleware("http")
    async def safe_context(request: Request, call_next):
        request.state.request_id = str(uuid4())
        # Bound the actual stream, including requests without Content-Length.
        body = bytearray()
        async for chunk in request.stream():
            body.extend(chunk)
            if len(body) > config.max_file_bytes * 4 // 3 + 16384:
                return error(request, "FILE_TOO_LARGE", 413)
        request._body = bytes(body)
        try:
            response = await call_next(request)
        except Exception:
            logger.error("kyc_request_failed", extra={"request_id": request.state.request_id})
            return error(request, "SERVICE_UNAVAILABLE", 503)
        response.headers["X-Request-ID"] = request.state.request_id
        response.headers["Cache-Control"] = "no-store"
        return response

    def error(request: Request, code: str, status: int) -> JSONResponse:
        return JSONResponse(
            status_code=status,
            content={
                "error": {
                    "code": code,
                    "message": "The verification request could not be completed.",
                    "request_id": request.state.request_id,
                }
            },
            headers={"Cache-Control": "no-store"},
        )

    @app.exception_handler(KycError)
    async def kyc_error(request, exc):
        return error(request, exc.code, exc.status)

    @app.exception_handler(RequestValidationError)
    async def validation_error(request, exc):
        return error(request, "INVALID_REQUEST", 422)

    @app.get("/healthz")
    def health() -> dict[str, str]:
        return {"status": "ok"}

    @app.get("/readyz")
    def ready() -> JSONResponse:
        try:
            with engine().connect() as db:
                db.execute(text("SELECT 1 FROM verifications LIMIT 1"))
            app.state.nonces.redis.ping()
            return JSONResponse({"status": "ready"})
        except Exception:
            return JSONResponse({"status": "unavailable"}, status_code=503)

    auth = [Depends(authenticate)]

    @app.post(
        "/api/v1/verifications/{id}/live/start", response_model=LiveChallenge, dependencies=auth
    )
    def live_start(id: Ulid, scope: Scope):
        from app.live_capture import start

        return start(id, scope)

    @app.post(
        "/api/v1/verifications/{id}/live/frame", response_model=LiveChallenge, dependencies=auth
    )
    def live_frame(id: Ulid, data: LiveFrame):
        from app.live_capture import frame

        return frame(id, data)

    @app.put("/api/v1/verifications/{id}", response_model=Snapshot, dependencies=auth)
    def create(id: Ulid, data: CreateVerification):
        return service.create(id, data)

    @app.post("/api/v1/verifications/{id}/snapshot", response_model=Snapshot, dependencies=auth)
    def read(id: Ulid, scope: Scope):
        return service.read(id, scope)

    @app.post("/api/v1/verifications/{id}/details", response_model=Details, dependencies=auth)
    def details(id: Ulid, scope: Scope):
        return service.details(id, scope)

    @app.post("/api/v1/verifications/{id}/image", response_model=PrivateImage, dependencies=auth)
    def image(id: Ulid, data: ReadImage):
        return service.image(id, data)

    @app.post("/api/v1/verifications/{id}/evidence", response_model=Snapshot, dependencies=auth)
    def upload(id: Ulid, data: Upload):
        return service.upload(id, data)

    @app.post("/api/v1/verifications/{id}/submit", response_model=Snapshot, dependencies=auth)
    def submit(id: Ulid, scope: Scope):
        return service.submit(id, scope)

    @app.post("/api/v1/verifications/{id}/erase", response_model=Snapshot, dependencies=auth)
    def erase(id: Ulid, scope: Scope):
        return service.erase(id, scope)

    return app


app = create_app()
