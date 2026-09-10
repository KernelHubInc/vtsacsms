from __future__ import annotations

import asyncio
from datetime import UTC, datetime, timedelta

import pytest

from vtsa_ocpp_gateway.config import Settings
from vtsa_ocpp_gateway.connections import ConnectionRegistry, RegisteredConnection
from vtsa_ocpp_gateway.models import ChargerIdentity, CommandRequest, JsonObject
from vtsa_ocpp_gateway.runtime import GatewayRuntime
from vtsa_ocpp_gateway.store import MemoryGatewayStore
from vtsa_ocpp_gateway.telemetry import EventPublisher, GatewayMetrics

TENANT_ID = "01K0M0AA1A0M0AA1A0M0AA1A0M"
CHARGER_ID = "01K0M0BB2B0M0BB2B0M0BB2B0M"
OTHER_TENANT_ID = "01K0M0CC3C0M0CC3C0M0CC3C0M"


class FakeTransport:
    def __init__(self) -> None:
        self.closed: list[tuple[int, str]] = []

    async def close(self, code: int = 1000, reason: str = "") -> None:
        self.closed.append((code, reason))


class FakeChargePoint:
    async def start(self) -> None:
        return None

    async def send_command(
        self, command_id: str, action: str, payload: JsonObject, timeout_seconds: float
    ) -> JsonObject:
        return {"status": "Accepted"}


def _connection(
    identity: str,
    connection_id: str,
    transport: FakeTransport,
    store: MemoryGatewayStore,
    settings: Settings,
    metrics: GatewayMetrics,
) -> RegisteredConnection:
    charger_identity = ChargerIdentity(identity, TENANT_ID, CHARGER_ID, "basic")
    return RegisteredConnection(
        charge_point_identity=identity,
        connection_id=connection_id,
        protocol="ocpp1.6",
        identity=charger_identity,
        transport=transport,
        charge_point=FakeChargePoint(),
        publisher=EventPublisher(
            store, settings, charger_identity, "ocpp1.6", connection_id, metrics
        ),
    )


@pytest.mark.asyncio
async def test_latest_connection_wins_and_stale_unregister_cannot_release_it() -> None:
    settings = Settings(connection_lease_seconds=9)
    store = MemoryGatewayStore()
    metrics = GatewayMetrics()
    registry = ConnectionRegistry(store, settings, metrics)
    old_transport = FakeTransport()
    new_transport = FakeTransport()
    old = _connection("CP-1", "old", old_transport, store, settings, metrics)
    new = _connection("CP-1", "new", new_transport, store, settings, metrics)

    await registry.register(old)
    await registry.register(new)
    await registry.unregister("CP-1", "old")

    assert old_transport.closed == [(1012, "Superseded by a newer charger connection")]
    assert await registry.get("CP-1") is new
    assert store.connections["CP-1"][1] == "new"
    await registry.unregister("CP-1", "new")


@pytest.mark.asyncio
async def test_cross_node_command_lookup_fences_stale_connection_immediately() -> None:
    settings = Settings(connection_lease_seconds=9)
    store = MemoryGatewayStore()
    old_metrics = GatewayMetrics()
    new_metrics = GatewayMetrics()
    old_registry = ConnectionRegistry(store, settings, old_metrics)
    new_registry = ConnectionRegistry(store, settings, new_metrics)
    old_transport = FakeTransport()
    new_transport = FakeTransport()
    old = _connection("CP-1", "old", old_transport, store, settings, old_metrics)
    new = _connection("CP-1", "new", new_transport, store, settings, new_metrics)

    await old_registry.register(old)
    await new_registry.register(new)

    assert await old_registry.get("CP-1") is None
    assert old_transport.closed[-1] == (1012, "Connection ownership was lost")
    assert await new_registry.get("CP-1") is new
    await new_registry.unregister("CP-1", "new")


@pytest.mark.asyncio
async def test_concurrent_duplicate_claim_has_exactly_one_winner() -> None:
    store = MemoryGatewayStore()

    claims = await asyncio.gather(
        *(store.claim_message("CP-1", "same-message", 60) for _ in range(25))
    )

    assert claims.count(True) == 1
    assert claims.count(False) == 24


@pytest.mark.asyncio
async def test_connection_lease_loss_closes_stale_node_connection() -> None:
    settings = Settings(connection_lease_seconds=9)
    store = MemoryGatewayStore()
    metrics = GatewayMetrics()
    registry = ConnectionRegistry(store, settings, metrics)
    old_transport = FakeTransport()
    old = _connection("CP-1", "old", old_transport, store, settings, metrics)

    await registry.register(old)
    await store.claim_connection("CP-1", "different-node", "new", 9)
    assert old.lease_task is not None
    old.lease_task.cancel()
    await asyncio.gather(old.lease_task, return_exceptions=True)
    await registry._renew_lease(old)

    assert old_transport.closed[-1] == (1012, "Connection ownership was lost")
    await registry.unregister("CP-1", "old")


@pytest.mark.asyncio
async def test_command_target_must_match_enrolled_tenant_and_charger() -> None:
    settings = Settings(allowed_commands=("TriggerMessage",))
    store = MemoryGatewayStore()
    runtime = GatewayRuntime(settings, store=store)
    transport = FakeTransport()
    connection = _connection("CP-1", "connection", transport, store, settings, runtime.metrics)
    await runtime.registry.register(connection)

    result = await runtime.dispatch_command(
        CommandRequest(
            command_id="01K0M0AA1A0M0AA1A0M0AA1A0M",
            tenant_id=OTHER_TENANT_ID,
            charger_id=CHARGER_ID,
            charge_point_identity="CP-1",
            action="TriggerMessage",
            payload={"requested_message": "Heartbeat"},
            correlation_id="01K0M0BB2B0M0BB2B0M0BB2B0M",
            actor_id="01K0M0CC3C0M0CC3C0M0CC3C0M",
            reason_code="operator.status_check",
            expected_state={"connection": "online"},
            deadline_at=datetime.now(UTC) + timedelta(seconds=30),
            timeout_seconds=2,
        )
    )

    assert result.status == "rejected"
    assert result.error == "Command target does not match the enrolled connection"
    await runtime.registry.unregister("CP-1", "connection")


@pytest.mark.asyncio
async def test_expired_command_is_never_sent_to_charger() -> None:
    settings = Settings(allowed_commands=("TriggerMessage",))
    store = MemoryGatewayStore()
    runtime = GatewayRuntime(settings, store=store)
    transport = FakeTransport()
    connection = _connection("CP-1", "connection", transport, store, settings, runtime.metrics)
    await runtime.registry.register(connection)

    result = await runtime.dispatch_command(
        CommandRequest(
            command_id="01K0M0AA1A0M0AA1A0M0AA1A0M",
            tenant_id=TENANT_ID,
            charger_id=CHARGER_ID,
            charge_point_identity="CP-1",
            action="TriggerMessage",
            payload={"requested_message": "Heartbeat"},
            correlation_id="01K0M0BB2B0M0BB2B0M0BB2B0M",
            actor_id="01K0M0CC3C0M0CC3C0M0CC3C0M",
            reason_code="operator.status_check",
            expected_state={"connection": "online"},
            deadline_at=datetime.now(UTC) - timedelta(seconds=1),
            timeout_seconds=2,
        )
    )

    assert result.status == "rejected"
    assert result.error == "Command deadline has expired"
    await runtime.registry.unregister("CP-1", "connection")


@pytest.mark.asyncio
async def test_completed_command_publishes_correlated_result_event() -> None:
    settings = Settings(allowed_commands=("TriggerMessage",))
    store = MemoryGatewayStore()
    runtime = GatewayRuntime(settings, store=store)
    transport = FakeTransport()
    connection = _connection("CP-1", "connection", transport, store, settings, runtime.metrics)
    await runtime.registry.register(connection)
    command_id = "01K0M0AA1A0M0AA1A0M0AA1A0M"
    correlation_id = "01K0M0BB2B0M0BB2B0M0BB2B0M"

    result = await runtime.dispatch_command(
        CommandRequest(
            command_id=command_id,
            tenant_id=TENANT_ID,
            charger_id=CHARGER_ID,
            charge_point_identity="CP-1",
            action="TriggerMessage",
            payload={"requested_message": "Heartbeat"},
            correlation_id=correlation_id,
            actor_id="01K0M0CC3C0M0CC3C0M0CC3C0M",
            reason_code="operator.status_check",
            expected_state={"connection": "online"},
            deadline_at=datetime.now(UTC) + timedelta(seconds=30),
            timeout_seconds=2,
        )
    )

    assert result.status == "completed"
    event = store.streams[settings.event_stream][-1]
    assert event["correlation_id"] == correlation_id
    assert event["causation_id"] == command_id
    assert event["data"]["status"] == "completed"
    await runtime.registry.unregister("CP-1", "connection")
