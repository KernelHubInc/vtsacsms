from __future__ import annotations

import time

from vtsa_ocpp_gateway.adapters import Ocpp16ChargePoint, Ocpp201ChargePoint
from vtsa_ocpp_gateway.config import Settings
from vtsa_ocpp_gateway.connections import (
    CommandCapableChargePoint,
    ConnectionRegistry,
    WebSocketOcppConnection,
)
from vtsa_ocpp_gateway.models import (
    ChargerIdentity,
    CommandRequest,
    CommandResult,
    JsonObject,
    utc_now,
)
from vtsa_ocpp_gateway.security import ChargerIdentityValidator
from vtsa_ocpp_gateway.store import GatewayStore, MemoryGatewayStore, RedisGatewayStore
from vtsa_ocpp_gateway.telemetry import EventPublisher, GatewayMetrics, MessageRedactor


class GatewayRuntime:
    def __init__(
        self,
        settings: Settings,
        *,
        store: GatewayStore | None = None,
        identity_validator: ChargerIdentityValidator | None = None,
    ) -> None:
        self.settings = settings
        self.store = store or (
            RedisGatewayStore(settings.redis_url, settings.redis_key_prefix)
            if settings.redis_url is not None
            else MemoryGatewayStore()
        )
        self.metrics = GatewayMetrics()
        self.redactor = MessageRedactor(settings.redacted_fields)
        self.identity_validator = identity_validator or ChargerIdentityValidator(
            settings.charger_registry_json,
            settings.allow_unauthenticated_development,
            settings.trusted_client_certificate_fingerprint_header,
        )
        self.registry = ConnectionRegistry(self.store, settings, self.metrics)

    def publisher(
        self, identity: ChargerIdentity, protocol: str, connection_id: str
    ) -> EventPublisher:
        return EventPublisher(
            self.store,
            self.settings,
            identity,
            protocol,
            connection_id,
            self.metrics,
        )

    def charge_point(
        self,
        protocol: str,
        identity: ChargerIdentity,
        connection_id: str,
        connection: WebSocketOcppConnection,
        publisher: EventPublisher,
    ) -> CommandCapableChargePoint:
        charge_point_type: type[Ocpp16ChargePoint] | type[Ocpp201ChargePoint]
        if protocol == "ocpp1.6":
            charge_point_type = Ocpp16ChargePoint
        elif protocol == "ocpp2.0.1":
            charge_point_type = Ocpp201ChargePoint
        else:
            raise ValueError("Unsupported OCPP protocol")
        return charge_point_type(
            identity.charge_point_identity,
            connection,
            store=self.store,
            settings=self.settings,
            identity=identity,
            publisher=publisher,
            metrics=self.metrics,
        )

    async def dispatch_command(self, request: CommandRequest) -> CommandResult:
        try:
            connection = await self.registry.get(request.charge_point_identity)
        except Exception:
            return CommandResult(
                request.command_id,
                request.action,
                "failed",
                error="Connection ownership could not be verified",
            )
        if connection is None:
            return CommandResult(
                request.command_id,
                request.action,
                "not_connected",
                error="Charger is not connected",
            )
        if connection.identity.is_bound and (
            connection.identity.tenant_id != request.tenant_id
            or connection.identity.charger_id != request.charger_id
        ):
            return CommandResult(
                request.command_id,
                request.action,
                "rejected",
                error="Command target does not match the enrolled connection",
            )
        remaining_seconds = (request.deadline_at - utc_now()).total_seconds()
        if remaining_seconds <= 0:
            return CommandResult(
                request.command_id,
                request.action,
                "rejected",
                error="Command deadline has expired",
            )
        started = time.perf_counter()
        try:
            response = await connection.charge_point.send_command(
                request.command_id,
                request.action,
                request.payload,
                min(
                    request.timeout_seconds,
                    self.settings.command_timeout_seconds,
                    remaining_seconds,
                ),
            )
        except TimeoutError:
            result = CommandResult(
                request.command_id, request.action, "timed_out", error="Charger response timed out"
            )
        except ValueError as error:
            result = CommandResult(request.command_id, request.action, "rejected", error=str(error))
        except Exception:
            result = CommandResult(
                request.command_id, request.action, "failed", error="Command delivery failed"
            )
        else:
            result = CommandResult(
                request.command_id, request.action, "completed", response=response
            )

        elapsed = time.perf_counter() - started
        self.metrics.commands.labels(
            protocol=connection.protocol, action=request.action, status=result.status
        ).inc()
        self.metrics.command_duration.labels(
            protocol=connection.protocol, action=request.action
        ).observe(elapsed)
        await connection.publisher.emit(
            "CommandResponse",
            {
                "command_id": request.command_id,
                "tenant_id": request.tenant_id,
                "charger_id": request.charger_id,
                "actor_id": request.actor_id,
                "reason_code": request.reason_code,
                "expected_state": request.expected_state,
                "action": request.action,
                "status": result.status,
                "response": result.response,
                "error": result.error,
            },
            correlation_id=request.correlation_id,
            causation_id=request.command_id,
        )
        return result

    async def readiness(self) -> JsonObject:
        store_ready = await self.store.ping()
        return {
            "redis": "ok" if store_ready else "unavailable",
            "connections": await self.registry.count(),
            "tls": "configured"
            if self.settings.tls_certificate_file
            else "development_or_external_termination",
        }

    async def close(self) -> None:
        await self.registry.close_all()
        await self.store.close()
