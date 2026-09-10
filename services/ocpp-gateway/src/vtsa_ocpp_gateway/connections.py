from __future__ import annotations

import asyncio
import json
from dataclasses import dataclass
from typing import Protocol

import structlog
from fastapi import WebSocket
from starlette.websockets import WebSocketState

from vtsa_ocpp_gateway.config import Settings
from vtsa_ocpp_gateway.models import ChargerIdentity, JsonObject
from vtsa_ocpp_gateway.protocol_context import set_inbound_message
from vtsa_ocpp_gateway.store import GatewayStore
from vtsa_ocpp_gateway.telemetry import EventPublisher, GatewayMetrics, RawMessageLogger

logger = structlog.get_logger()


class CommandCapableChargePoint(Protocol):
    async def start(self) -> None: ...

    async def send_command(
        self, command_id: str, action: str, payload: JsonObject, timeout_seconds: float
    ) -> JsonObject: ...


class ConnectionTransport(Protocol):
    async def close(self, code: int = 1000, reason: str = "") -> None: ...


class WebSocketOcppConnection:
    def __init__(
        self,
        websocket: WebSocket,
        *,
        charge_point_identity: str,
        protocol: str,
        store: GatewayStore,
        settings: Settings,
        raw_logger: RawMessageLogger,
        metrics: GatewayMetrics,
    ) -> None:
        self.websocket = websocket
        self.charge_point_identity = charge_point_identity
        self.protocol = protocol
        self._store = store
        self._settings = settings
        self._raw_logger = raw_logger
        self._metrics = metrics
        self._close_lock = asyncio.Lock()

    async def recv(self) -> str:
        while True:
            raw = await self.websocket.receive_text()
            message_type, unique_id, action = await self._raw_logger.log(
                raw, direction="charger_to_gateway", protocol=self.protocol
            )
            if message_type != "2":
                self._metrics.messages.labels(
                    protocol=self.protocol,
                    direction="charger_to_gateway",
                    action=action,
                    outcome="received",
                ).inc()
                return raw

            cached = await self._store.cached_response(self.charge_point_identity, unique_id)
            if cached is not None:
                await self.websocket.send_text(cached)
                self._metrics.duplicates.labels(protocol=self.protocol, outcome="replayed").inc()
                continue

            claimed = await self._store.claim_message(
                self.charge_point_identity,
                unique_id,
                self._settings.message_deduplication_seconds,
            )
            if claimed:
                set_inbound_message(unique_id, action)
                self._metrics.messages.labels(
                    protocol=self.protocol,
                    direction="charger_to_gateway",
                    action=action,
                    outcome="received",
                ).inc()
                return raw

            replay = await self._wait_for_response(unique_id)
            if replay is None:
                replay = json.dumps(
                    [
                        4,
                        unique_id,
                        "OccurrenceConstraintViolation",
                        "Duplicate message is still processing",
                        {},
                    ],
                    separators=(",", ":"),
                )
                self._metrics.duplicates.labels(
                    protocol=self.protocol, outcome="in_flight_rejected"
                ).inc()
            else:
                self._metrics.duplicates.labels(protocol=self.protocol, outcome="replayed").inc()
            await self.websocket.send_text(replay)

    async def send(self, message: str) -> None:
        message_type, unique_id, action = await self._raw_logger.log(
            message, direction="gateway_to_charger", protocol=self.protocol
        )
        if message_type in {"3", "4"}:
            await self._store.cache_response(
                self.charge_point_identity,
                unique_id,
                message,
                self._settings.message_deduplication_seconds,
            )
        await self.websocket.send_text(message)
        self._metrics.messages.labels(
            protocol=self.protocol,
            direction="gateway_to_charger",
            action=action,
            outcome="sent",
        ).inc()

    async def close(self, code: int = 1000, reason: str = "") -> None:
        async with self._close_lock:
            if self._is_disconnected():
                return
            try:
                await self.websocket.close(code=code, reason=reason)
            except RuntimeError:
                if self._is_disconnected():
                    return
                raise

    def _is_disconnected(self) -> bool:
        return self.websocket.application_state is WebSocketState.DISCONNECTED

    async def _wait_for_response(self, unique_id: str) -> str | None:
        deadline = asyncio.get_running_loop().time() + min(
            self._settings.command_timeout_seconds, 5.0
        )
        while asyncio.get_running_loop().time() < deadline:
            await asyncio.sleep(0.05)
            cached = await self._store.cached_response(self.charge_point_identity, unique_id)
            if cached is not None:
                return cached
        return None


@dataclass(slots=True)
class RegisteredConnection:
    charge_point_identity: str
    connection_id: str
    protocol: str
    identity: ChargerIdentity
    transport: ConnectionTransport
    charge_point: CommandCapableChargePoint
    publisher: EventPublisher
    lease_task: asyncio.Task[None] | None = None


class ConnectionRegistry:
    def __init__(self, store: GatewayStore, settings: Settings, metrics: GatewayMetrics) -> None:
        self._store = store
        self._settings = settings
        self._metrics = metrics
        self._connections: dict[str, RegisteredConnection] = {}
        self._lock = asyncio.Lock()

    async def register(self, connection: RegisteredConnection) -> None:
        previous_local: RegisteredConnection | None
        async with self._lock:
            previous_local = self._connections.get(connection.charge_point_identity)
            await self._store.claim_connection(
                connection.charge_point_identity,
                self._settings.node_id,
                connection.connection_id,
                self._settings.connection_lease_seconds,
            )
            self._connections[connection.charge_point_identity] = connection
            connection.lease_task = asyncio.create_task(
                self._renew_lease(connection),
                name=f"ocpp-lease-{connection.connection_id}",
            )
            self._metrics.connections.labels(protocol=connection.protocol).inc()
        if previous_local is not None and previous_local.connection_id != connection.connection_id:
            await previous_local.transport.close(
                code=1012, reason="Superseded by a newer charger connection"
            )
            await logger.awarning(
                "ocpp_connection_superseded",
                charge_point_identity=connection.charge_point_identity,
                old_connection_id=previous_local.connection_id,
                new_connection_id=connection.connection_id,
            )

    async def unregister(self, charge_point_identity: str, connection_id: str) -> None:
        removed: RegisteredConnection | None = None
        async with self._lock:
            current = self._connections.get(charge_point_identity)
            if current is not None and current.connection_id == connection_id:
                removed = self._connections.pop(charge_point_identity)
        try:
            await self._store.release_connection(charge_point_identity, connection_id)
        except Exception:
            await logger.aexception(
                "ocpp_connection_release_failed",
                charge_point_identity=charge_point_identity,
                connection_id=connection_id,
            )
        if removed is not None:
            current_task = asyncio.current_task()
            if removed.lease_task is not None and removed.lease_task is not current_task:
                removed.lease_task.cancel()
                await asyncio.gather(removed.lease_task, return_exceptions=True)
            self._metrics.connections.labels(protocol=removed.protocol).dec()

    async def get(self, charge_point_identity: str) -> RegisteredConnection | None:
        async with self._lock:
            connection = self._connections.get(charge_point_identity)
        if connection is None:
            return None
        retained = await self._store.renew_connection(
            connection.charge_point_identity,
            connection.connection_id,
            self._settings.connection_lease_seconds,
        )
        if retained:
            return connection
        await connection.transport.close(code=1012, reason="Connection ownership was lost")
        await self.unregister(connection.charge_point_identity, connection.connection_id)
        return None

    async def count(self) -> int:
        async with self._lock:
            return len(self._connections)

    async def close_all(self) -> None:
        async with self._lock:
            connections = list(self._connections.values())
        for connection in connections:
            await connection.transport.close(code=1001, reason="Gateway shutting down")

    async def _renew_lease(self, connection: RegisteredConnection) -> None:
        interval = self._settings.connection_lease_seconds / 3
        try:
            while True:
                await asyncio.sleep(interval)
                retained = await self._store.renew_connection(
                    connection.charge_point_identity,
                    connection.connection_id,
                    self._settings.connection_lease_seconds,
                )
                if not retained:
                    await connection.transport.close(
                        code=1012, reason="Connection ownership was lost"
                    )
                    await self.unregister(
                        connection.charge_point_identity, connection.connection_id
                    )
                    return
        except asyncio.CancelledError:
            raise
        except Exception:
            await logger.aexception(
                "ocpp_connection_lease_failed",
                charge_point_identity=connection.charge_point_identity,
                connection_id=connection.connection_id,
            )
            await connection.transport.close(code=1011, reason="Connection lease failed")
            await self.unregister(connection.charge_point_identity, connection.connection_id)
