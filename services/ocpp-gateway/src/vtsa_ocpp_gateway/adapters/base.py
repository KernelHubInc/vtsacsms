from __future__ import annotations

import asyncio
import logging
from dataclasses import asdict, is_dataclass
from typing import Any, Protocol, cast

import structlog

from vtsa_ocpp_gateway.config import Settings
from vtsa_ocpp_gateway.models import AuthorizationDecision, ChargerIdentity, JsonObject
from vtsa_ocpp_gateway.protocol_context import current_inbound_message
from vtsa_ocpp_gateway.store import GatewayStore
from vtsa_ocpp_gateway.telemetry import EventPublisher, GatewayMetrics

suppressed_protocol_logger = logging.getLogger("vtsa.ocpp.protocol")
suppressed_protocol_logger.setLevel(logging.CRITICAL)
logger = structlog.get_logger()


class SupportsOcppCall(Protocol):
    async def call(
        self,
        payload: Any,
        suppress: bool = True,
        unique_id: str | None = None,
        skip_schema_validation: bool = False,
    ) -> Any: ...


class GatewayChargePointMixin:
    _store: GatewayStore
    _settings: Settings
    _identity: ChargerIdentity
    _publisher: EventPublisher
    _metrics: GatewayMetrics
    _protocol: str
    _command_types: dict[str, type[Any]]

    def configure_gateway(
        self,
        *,
        store: GatewayStore,
        settings: Settings,
        identity: ChargerIdentity,
        publisher: EventPublisher,
        metrics: GatewayMetrics,
        protocol: str,
    ) -> None:
        self._store = store
        self._settings = settings
        self._identity = identity
        self._publisher = publisher
        self._metrics = metrics
        self._protocol = protocol

    async def authorize_token(self, token: JsonObject) -> AuthorizationDecision:
        context = current_inbound_message()
        try:
            return await self._store.authorize(
                self._settings.authorization_stream,
                {
                    "tenant_id": self._identity.tenant_id,
                    "charger_id": self._identity.charger_id,
                    "charge_point_identity": self._identity.charge_point_identity,
                    "protocol": self._protocol,
                    "correlation_id": context.causation_id,
                    "token": token,
                },
                self._settings.authorization_timeout_seconds,
                self._settings.stream_max_length,
            )
        except Exception:
            await logger.aexception(
                "ocpp_core_authorization_failed",
                charge_point_identity=self._identity.charge_point_identity,
                protocol=self._protocol,
            )
            return AuthorizationDecision("Invalid")

    async def emit(
        self, action: str, data: JsonObject, protocol_timestamp: str | None = None
    ) -> JsonObject:
        context = current_inbound_message()
        return await self._publisher.emit(
            action,
            {**data, "ocpp_message_id": context.ocpp_unique_id},
            correlation_id=context.causation_id,
            causation_id=context.causation_id,
            protocol_timestamp=protocol_timestamp,
        )

    async def send_command(
        self, command_id: str, action: str, payload: JsonObject, timeout_seconds: float
    ) -> JsonObject:
        if action not in self._settings.allowed_commands:
            raise ValueError("Command action is not enabled")
        payload_type = self._command_types.get(action)
        if payload_type is None:
            raise ValueError("Command action is not supported for this protocol")
        request = payload_type(**payload)
        async with asyncio.timeout(timeout_seconds):
            response = await cast(SupportsOcppCall, self).call(
                request, suppress=False, unique_id=command_id
            )
        if response is None:
            return {}
        if is_dataclass(response) and not isinstance(response, type):
            return asdict(response)
        return {"response": str(response)}
