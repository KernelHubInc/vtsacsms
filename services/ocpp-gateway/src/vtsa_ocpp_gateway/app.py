from __future__ import annotations

import asyncio
import hmac
import time
from collections.abc import AsyncIterator, Awaitable, Callable
from contextlib import asynccontextmanager
from dataclasses import asdict

import structlog
from fastapi import FastAPI, HTTPException, Request, Response, WebSocket, WebSocketDisconnect
from fastapi.responses import JSONResponse
from prometheus_client import CONTENT_TYPE_LATEST
from pydantic import AwareDatetime, BaseModel, Field
from starlette.middleware.base import BaseHTTPMiddleware
from structlog.contextvars import bind_contextvars, clear_contextvars

from vtsa_ocpp_gateway.config import Settings
from vtsa_ocpp_gateway.connections import RegisteredConnection, WebSocketOcppConnection
from vtsa_ocpp_gateway.ids import incoming_ulid, new_ulid
from vtsa_ocpp_gateway.logging import configure_logging
from vtsa_ocpp_gateway.models import CommandRequest, JsonObject
from vtsa_ocpp_gateway.runtime import GatewayRuntime
from vtsa_ocpp_gateway.security import ChargerIdentityError
from vtsa_ocpp_gateway.telemetry import RawMessageLogger

logger = structlog.get_logger()


class RequestContextMiddleware(BaseHTTPMiddleware):
    async def dispatch(
        self,
        request: Request,
        call_next: Callable[[Request], Awaitable[Response]],
    ) -> Response:
        request_id = incoming_ulid(request.headers.get("x-request-id"))
        correlation_id = incoming_ulid(request.headers.get("x-correlation-id") or request_id)
        request.state.request_id = request_id
        request.state.correlation_id = correlation_id
        bind_contextvars(request_id=request_id, correlation_id=correlation_id)
        started = time.perf_counter()
        try:
            response = await call_next(request)
        except Exception:
            await logger.aexception(
                "http_request_failed",
                method=request.method,
                path=request.url.path,
                duration_ms=round((time.perf_counter() - started) * 1000, 3),
            )
            raise
        else:
            response.headers["X-Request-ID"] = request_id
            response.headers["X-Correlation-ID"] = correlation_id
            await logger.ainfo(
                "http_request_completed",
                method=request.method,
                path=request.url.path,
                status_code=response.status_code,
                duration_ms=round((time.perf_counter() - started) * 1000, 3),
            )
            return response
        finally:
            clear_contextvars()


class CommandBody(BaseModel):
    command_id: str = Field(pattern=r"^[0-9A-HJKMNP-TV-Z]{26}$")
    tenant_id: str = Field(pattern=r"^[0-9A-HJKMNP-TV-Z]{26}$")
    charger_id: str = Field(pattern=r"^[0-9A-HJKMNP-TV-Z]{26}$")
    charge_point_identity: str = Field(min_length=1, max_length=120)
    action: str = Field(min_length=1, max_length=80)
    payload: JsonObject = Field(default_factory=dict)
    correlation_id: str = Field(pattern=r"^[0-9A-HJKMNP-TV-Z]{26}$")
    actor_id: str = Field(pattern=r"^[0-9A-HJKMNP-TV-Z]{26}$")
    reason_code: str = Field(pattern=r"^[a-z][a-z0-9_.-]{1,79}$")
    expected_state: JsonObject = Field(default_factory=dict)
    deadline_at: AwareDatetime
    timeout_seconds: float = Field(gt=0, le=120)


def _health_payload(request: Request, status: str) -> dict[str, str]:
    return {
        "status": status,
        "service": "ocpp-gateway",
        "request_id": request.state.request_id,
        "correlation_id": request.state.correlation_id,
    }


def create_app(settings: Settings | None = None, runtime: GatewayRuntime | None = None) -> FastAPI:
    resolved = settings or Settings.from_environment()
    configure_logging(resolved.log_level)
    gateway = runtime or GatewayRuntime(resolved)

    @asynccontextmanager
    async def lifespan(_: FastAPI) -> AsyncIterator[None]:
        yield
        await gateway.close()

    application = FastAPI(title="VTSA OCPP Gateway", version="0.2.0", lifespan=lifespan)
    application.state.settings = resolved
    application.state.runtime = gateway
    application.add_middleware(RequestContextMiddleware)

    @application.get("/health/live", tags=["health"])
    async def live(request: Request) -> JSONResponse:
        return JSONResponse(_health_payload(request, "ok"))

    @application.get("/health/ready", tags=["health"])
    async def ready(request: Request) -> JSONResponse:
        checks = await gateway.readiness()
        is_ready = checks["redis"] == "ok"
        return JSONResponse(
            {**_health_payload(request, "ready" if is_ready else "unavailable"), "checks": checks},
            status_code=200 if is_ready else 503,
        )

    @application.get("/internal/metrics", tags=["internal"])
    async def metrics(request: Request) -> Response:
        _require_internal_token(request, resolved)
        return Response(gateway.metrics.render(), media_type=CONTENT_TYPE_LATEST)

    @application.post("/internal/v1/commands", tags=["internal"])
    async def command(request: Request, body: CommandBody) -> JSONResponse:
        _require_internal_token(request, resolved)
        result = await gateway.dispatch_command(
            CommandRequest(
                command_id=body.command_id,
                tenant_id=body.tenant_id,
                charger_id=body.charger_id,
                charge_point_identity=body.charge_point_identity,
                action=body.action,
                payload=body.payload,
                correlation_id=body.correlation_id,
                actor_id=body.actor_id,
                reason_code=body.reason_code,
                expected_state=body.expected_state,
                deadline_at=body.deadline_at,
                timeout_seconds=body.timeout_seconds,
            )
        )
        return JSONResponse({"data": asdict(result)})

    @application.websocket("/ocpp/{charge_point_identity}")
    async def ocpp_socket(websocket: WebSocket, charge_point_identity: str) -> None:
        offered = tuple(websocket.scope.get("subprotocols", ()))
        selected = next(
            (protocol for protocol in resolved.supported_subprotocols if protocol in offered),
            None,
        )
        if selected is None:
            await websocket.close(code=1002, reason="A supported OCPP subprotocol is required")
            return
        if (
            resolved.require_tls
            and not resolved.allow_unauthenticated_development
            and websocket.url.scheme != "wss"
        ):
            await websocket.close(code=1008, reason="TLS is required")
            return
        try:
            identity = await gateway.identity_validator.validate(websocket, charge_point_identity)
        except ChargerIdentityError:
            await websocket.close(code=1008, reason="Charger authentication failed")
            return

        request_id = incoming_ulid(websocket.headers.get("x-request-id"))
        correlation_id = incoming_ulid(websocket.headers.get("x-correlation-id") or request_id)
        connection_id = new_ulid()
        bind_contextvars(
            request_id=request_id,
            correlation_id=correlation_id,
            charge_point_identity=charge_point_identity,
            charger_id=identity.charger_id,
            tenant_id=identity.tenant_id,
            connection_id=connection_id,
            ocpp_subprotocol=selected,
        )
        await websocket.accept(subprotocol=selected)
        raw_logger = RawMessageLogger(resolved, gateway.redactor)
        connection = WebSocketOcppConnection(
            websocket,
            charge_point_identity=charge_point_identity,
            protocol=selected,
            store=gateway.store,
            settings=resolved,
            raw_logger=raw_logger,
            metrics=gateway.metrics,
        )
        publisher = gateway.publisher(identity, selected, connection_id)
        charge_point = gateway.charge_point(
            selected, identity, connection_id, connection, publisher
        )
        registered = RegisteredConnection(
            charge_point_identity=charge_point_identity,
            connection_id=connection_id,
            protocol=selected,
            identity=identity,
            transport=connection,
            charge_point=charge_point,
            publisher=publisher,
        )
        registered_active = False
        try:
            await gateway.registry.register(registered)
            registered_active = True
            await publisher.emit(
                "ChargerConnected",
                {"authentication": identity.authentication},
                correlation_id=correlation_id,
            )
            await logger.ainfo("ocpp_connection_opened")
            await charge_point.start()
        except WebSocketDisconnect:
            await logger.ainfo("ocpp_connection_closed")
        except asyncio.CancelledError:
            await logger.ainfo("ocpp_connection_cancelled")
        except ValueError as error:
            await logger.awarning("ocpp_message_rejected", reason=str(error))
            await _safe_close(websocket, 1009, "OCPP message rejected")
        except Exception:
            await logger.aexception("ocpp_connection_failed")
            await _safe_close(websocket, 1011, "Gateway protocol failure")
        finally:
            try:
                await publisher.emit("ChargerDisconnected", {}, correlation_id=correlation_id)
            except Exception:
                await logger.aexception("ocpp_disconnect_event_failed")
            if registered_active:
                await gateway.registry.unregister(charge_point_identity, connection_id)
            else:
                await _safe_close(websocket, 1011, "Gateway registration failed")
            clear_contextvars()

    return application


def _require_internal_token(request: Request, settings: Settings) -> None:
    if settings.internal_api_token is None:
        raise HTTPException(status_code=503, detail="Internal API authentication is not configured")
    authorization = request.headers.get("authorization", "")
    expected = f"Bearer {settings.internal_api_token}"
    if not hmac.compare_digest(authorization, expected):
        raise HTTPException(status_code=401, detail="Invalid internal service credential")


async def _safe_close(websocket: WebSocket, code: int, reason: str) -> None:
    try:
        await websocket.close(code=code, reason=reason)
    except RuntimeError:
        return


app = create_app()
