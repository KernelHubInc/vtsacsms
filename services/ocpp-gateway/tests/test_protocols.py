from __future__ import annotations

import asyncio
import base64
import json
import socket
from collections.abc import AsyncIterator
from contextlib import AsyncExitStack, asynccontextmanager
from typing import Any, Literal, cast

import pytest
import uvicorn
from argon2 import PasswordHasher
from websockets.asyncio.client import ClientConnection, connect
from websockets.typing import Subprotocol

from vtsa_ocpp_gateway.adapters.v16 import Ocpp16ChargePoint
from vtsa_ocpp_gateway.app import create_app
from vtsa_ocpp_gateway.config import Settings
from vtsa_ocpp_gateway.models import AuthorizationDecision, ChargerIdentity, JsonObject
from vtsa_ocpp_gateway.runtime import GatewayRuntime
from vtsa_ocpp_gateway.simulator import ChargerSimulator
from vtsa_ocpp_gateway.store import MemoryGatewayStore
from vtsa_ocpp_gateway.telemetry import EventPublisher, GatewayMetrics


class HangingConnection:
    def __init__(self) -> None:
        self.sent: list[str] = []

    async def send(self, message: str) -> None:
        self.sent.append(message)

    async def recv(self) -> str:
        await asyncio.Event().wait()
        raise AssertionError("unreachable")


class FailingAuthorizationStore(MemoryGatewayStore):
    async def authorize(
        self, stream: str, request: JsonObject, timeout_seconds: float, max_length: int
    ) -> AuthorizationDecision:
        raise ConnectionError("synthetic core outage")


def _development_gateway(
    **overrides: Any,
) -> tuple[Settings, MemoryGatewayStore, GatewayRuntime]:
    settings = Settings(
        allow_unauthenticated_development=True,
        require_tls=False,
        raw_message_logging=False,
        **overrides,
    )
    store = MemoryGatewayStore()
    runtime = GatewayRuntime(settings, store=store)
    return settings, store, runtime


@asynccontextmanager
async def _live_gateway(settings: Settings, runtime: GatewayRuntime) -> AsyncIterator[str]:
    listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    listener.bind(("127.0.0.1", 0))
    listener.listen()
    listener.setblocking(False)
    port = cast(tuple[str, int], listener.getsockname())[1]
    server = uvicorn.Server(
        uvicorn.Config(
            create_app(settings, runtime),
            host="127.0.0.1",
            port=port,
            access_log=False,
            log_config=None,
            lifespan="on",
        )
    )
    task = asyncio.create_task(server.serve(sockets=[listener]), name="test-ocpp-gateway")
    for _ in range(500):
        if server.started:
            break
        if task.done():
            await task
            raise RuntimeError("Test gateway exited before startup")
        await asyncio.sleep(0.01)
    else:
        task.cancel()
        await asyncio.gather(task, return_exceptions=True)
        raise TimeoutError("Test gateway did not start")
    try:
        yield f"ws://127.0.0.1:{port}"
    finally:
        server.should_exit = True
        async with asyncio.timeout(5):
            await task


@asynccontextmanager
async def _charger_socket(
    settings: Settings,
    runtime: GatewayRuntime,
    path: str,
    subprotocol: str,
    *,
    headers: dict[str, str] | None = None,
) -> AsyncIterator[ClientConnection]:
    async with AsyncExitStack() as stack:
        endpoint = await stack.enter_async_context(_live_gateway(settings, runtime))
        websocket = await stack.enter_async_context(
            connect(
                f"{endpoint}{path}",
                subprotocols=[Subprotocol(subprotocol)],
                additional_headers=headers,
            )
        )
        yield websocket


async def _send_frame(websocket: ClientConnection, frame: list[Any]) -> None:
    await websocket.send(json.dumps(frame, separators=(",", ":")))


async def _receive_text(websocket: ClientConnection) -> str:
    raw = await websocket.recv()
    if not isinstance(raw, str):
        raise TypeError("Expected an OCPP text frame")
    return raw


async def _receive_frame(websocket: ClientConnection) -> list[Any]:
    decoded: Any = json.loads(await _receive_text(websocket))
    if not isinstance(decoded, list):
        raise TypeError("Expected an OCPP JSON array")
    return decoded


@pytest.mark.asyncio
async def test_ocpp16_lifecycle_is_normalized_and_duplicate_response_is_replayed() -> None:
    settings, store, runtime = _development_gateway()
    boot = [
        2,
        "boot-1",
        "BootNotification",
        {"chargePointVendor": "VTSA Simulator", "chargePointModel": "Protocol Lab"},
    ]

    async with _charger_socket(settings, runtime, "/ocpp/SIM-16", "ocpp1.6") as websocket:
        await websocket.send(json.dumps(boot))
        first_response = await _receive_text(websocket)
        await websocket.send(json.dumps(boot))
        duplicate_response = await _receive_text(websocket)
        await _send_frame(
            websocket,
            [
                2,
                "status-1",
                "StatusNotification",
                {
                    "connectorId": 1,
                    "errorCode": "NoError",
                    "status": "Available",
                    "timestamp": "2026-07-22T00:00:00Z",
                },
            ],
        )
        status_response = await _receive_frame(websocket)
        await _send_frame(
            websocket,
            [
                2,
                "start-1",
                "StartTransaction",
                {
                    "connectorId": 1,
                    "idTag": "TEST-TOKEN",
                    "meterStart": 0,
                    "timestamp": "2026-07-22T00:00:01Z",
                },
            ],
        )
        start_response = await _receive_frame(websocket)
        await _send_frame(
            websocket,
            [
                2,
                "meter-1",
                "MeterValues",
                {
                    "connectorId": 1,
                    "transactionId": start_response[2]["transactionId"],
                    "meterValue": [
                        {
                            "timestamp": "2026-07-22T00:01:00Z",
                            "sampledValue": [{"value": "1.5", "unit": "kWh"}],
                        }
                    ],
                },
            ],
        )
        assert await _receive_frame(websocket) == [3, "meter-1", {}]
        await _send_frame(
            websocket,
            [
                2,
                "stop-1",
                "StopTransaction",
                {
                    "meterStop": 1500,
                    "timestamp": "2026-07-22T00:02:00Z",
                    "transactionId": start_response[2]["transactionId"],
                },
            ],
        )
        assert await _receive_frame(websocket) == [3, "stop-1", {}]

    assert first_response == duplicate_response
    assert status_response == [3, "status-1", {}]
    assert start_response[2]["idTagInfo"]["status"] == "Invalid"
    events = store.streams[settings.quarantine_stream]
    event_types = [event["event_type"] for event in events]
    assert event_types.count("gateway.ocpp.boot_notification.received.v1") == 1
    meter_event = next(
        event for event in events if event["event_type"] == "gateway.ocpp.meter_values.received.v1"
    )
    assert meter_event["data"]["samples"][0]["value"] == 1500


@pytest.mark.asyncio
async def test_ocpp201_transaction_event_and_security_event_are_supported() -> None:
    settings, store, runtime = _development_gateway()

    async with _charger_socket(settings, runtime, "/ocpp/SIM-201", "ocpp2.0.1") as websocket:
        await _send_frame(
            websocket,
            [
                2,
                "boot-201",
                "BootNotification",
                {
                    "reason": "PowerUp",
                    "chargingStation": {
                        "model": "Protocol Lab",
                        "vendorName": "VTSA Simulator",
                    },
                },
            ],
        )
        assert (await _receive_frame(websocket))[2]["status"] == "Accepted"
        await _send_frame(
            websocket,
            [
                2,
                "transaction-201",
                "TransactionEvent",
                {
                    "eventType": "Started",
                    "timestamp": "2026-07-22T00:00:00Z",
                    "triggerReason": "Authorized",
                    "seqNo": 0,
                    "transactionInfo": {"transactionId": "tx-201"},
                    "evse": {"id": 1, "connectorId": 1},
                    "idToken": {"idToken": "TEST-TOKEN", "type": "Central"},
                },
            ],
        )
        transaction_response = await _receive_frame(websocket)
        await _send_frame(
            websocket,
            [
                2,
                "security-201",
                "SecurityEventNotification",
                {
                    "type": "TamperDetected",
                    "timestamp": "2026-07-22T00:00:01Z",
                    "techInfo": "must not enter normalized events",
                },
            ],
        )
        assert await _receive_frame(websocket) == [3, "security-201", {}]

    assert transaction_response[2]["idTokenInfo"]["status"] == "Invalid"
    security_event = next(
        event
        for event in store.streams[settings.quarantine_stream]
        if event["event_type"] == "gateway.ocpp.security_event_notification.received.v1"
    )
    assert security_event["data"]["technical_info_present"] is True
    assert "tech_info" not in security_event["data"]


@pytest.mark.asyncio
async def test_registered_charger_is_bound_to_its_enrolled_tenant() -> None:
    password = "local-test-password"
    registry = json.dumps(
        {
            "CP-BOUND": {
                "tenant_id": "01K0M0AA1A0M0AA1A0M0AA1A0M",
                "charger_id": "01K0M0BB2B0M0BB2B0M0BB2B0M",
                "enabled": True,
                "basic_password_hash": PasswordHasher().hash(password),
            }
        }
    )
    settings = Settings(
        require_tls=False,
        charger_registry_json=registry,
        raw_message_logging=False,
    )
    store = MemoryGatewayStore()
    runtime = GatewayRuntime(settings, store=store)
    authorization = base64.b64encode(f"CP-BOUND:{password}".encode()).decode()

    async with _charger_socket(
        settings,
        runtime,
        "/ocpp/CP-BOUND",
        "ocpp1.6",
        headers={"Authorization": f"Basic {authorization}"},
    ) as websocket:
        await _send_frame(
            websocket,
            [
                2,
                "heartbeat-bound",
                "Heartbeat",
                {},
            ],
        )
        assert (await _receive_frame(websocket))[0:2] == [3, "heartbeat-bound"]

    assert settings.quarantine_stream not in store.streams
    assert all(
        event["tenant_id"] == "01K0M0AA1A0M0AA1A0M0AA1A0M"
        for event in store.streams[settings.event_stream]
    )


@pytest.mark.asyncio
async def test_internal_command_uses_command_id_to_correlate_wire_response() -> None:
    command_id = "01K0M0JJ5X0M0JJ5X0M0JJ5X0M"
    settings = Settings(allowed_commands=("TriggerMessage",), raw_message_logging=False)
    store = MemoryGatewayStore()
    metrics = GatewayMetrics()
    identity = ChargerIdentity(
        "CP-1",
        "01K0M0AA1A0M0AA1A0M0AA1A0M",
        "01K0M0BB2B0M0BB2B0M0BB2B0M",
        "basic",
    )
    connection = HangingConnection()
    charge_point = Ocpp16ChargePoint(
        "CP-1",
        connection,
        store=store,
        settings=settings,
        identity=identity,
        publisher=EventPublisher(store, settings, identity, "ocpp1.6", "connection", metrics),
        metrics=metrics,
    )
    pending = asyncio.create_task(
        charge_point.send_command(
            command_id,
            "TriggerMessage",
            {"requested_message": "Heartbeat"},
            2,
        )
    )
    for _ in range(100):
        if connection.sent:
            break
        await asyncio.sleep(0.001)
    command_frame = json.loads(connection.sent[0])
    assert command_frame[0:3] == [2, command_id, "TriggerMessage"]

    await charge_point.route_message(json.dumps([3, command_id, {"status": "Accepted"}]))
    response = await pending

    assert response["status"] == "Accepted"


@pytest.mark.asyncio
async def test_command_timeout_cancels_protocol_wait() -> None:
    settings = Settings(
        allowed_commands=("TriggerMessage",),
        command_timeout_seconds=0.05,
        raw_message_logging=False,
    )
    store = MemoryGatewayStore()
    metrics = GatewayMetrics()
    identity = ChargerIdentity(
        "CP-1",
        "01K0M0AA1A0M0AA1A0M0AA1A0M",
        "01K0M0BB2B0M0BB2B0M0BB2B0M",
        "basic",
    )
    connection = HangingConnection()
    charge_point = Ocpp16ChargePoint(
        "CP-1",
        connection,
        store=store,
        settings=settings,
        identity=identity,
        publisher=EventPublisher(store, settings, identity, "ocpp1.6", "connection", metrics),
        metrics=metrics,
    )

    with pytest.raises(TimeoutError):
        await charge_point.send_command(
            "01K0M0AA1A0M0AA1A0M0AA1A0M",
            "TriggerMessage",
            {"requested_message": "Heartbeat"},
            1,
        )

    assert len(connection.sent) == 1


@pytest.mark.asyncio
async def test_authorization_transport_failure_returns_invalid() -> None:
    settings = Settings(raw_message_logging=False)
    store = FailingAuthorizationStore()
    metrics = GatewayMetrics()
    identity = ChargerIdentity(
        "CP-1",
        "01K0M0AA1A0M0AA1A0M0AA1A0M",
        "01K0M0BB2B0M0BB2B0M0BB2B0M",
        "basic",
    )
    charge_point = Ocpp16ChargePoint(
        "CP-1",
        HangingConnection(),
        store=store,
        settings=settings,
        identity=identity,
        publisher=EventPublisher(store, settings, identity, "ocpp1.6", "connection", metrics),
        metrics=metrics,
    )

    decision = await charge_point.authorize_token({"id_tag": "synthetic-token"})

    assert decision.status == "Invalid"


@pytest.mark.parametrize("protocol", ["ocpp1.6", "ocpp2.0.1"])
@pytest.mark.asyncio
async def test_simulator_exercises_supported_status_and_transaction_messages(
    protocol: Literal["ocpp1.6", "ocpp2.0.1"],
) -> None:
    settings, store, runtime = _development_gateway()

    async with _live_gateway(settings, runtime) as endpoint:
        simulator = ChargerSimulator(f"{endpoint}/ocpp", f"SIM-{protocol}", protocol)
        await simulator.connect()
        try:
            await simulator.boot()
            await simulator.heartbeat()
            await simulator.change_status("Available")
            await simulator.start_session()
            await simulator.send_meter_value(500)
            data_transfer = await simulator.send_data_transfer(
                "unapproved.vendor", "Synthetic", "private vendor payload"
            )
            await simulator.send_diagnostics_status()
            await simulator.send_firmware_status()
            await simulator.send_security_event()
            await simulator.simulate_fault()
            await simulator.stop_session(1_000)
        finally:
            await simulator.close()

    assert data_transfer["status"] == "UnknownVendorId"
    event_types = {event["event_type"] for event in store.streams[settings.quarantine_stream]}
    assert "gateway.ocpp.heartbeat.received.v1" in event_types
    assert "gateway.ocpp.meter_values.received.v1" in event_types
    assert "gateway.ocpp.data_transfer.received.v1" in event_types
    assert "gateway.ocpp.diagnostics_status_notification.received.v1" in event_types
    assert "gateway.ocpp.firmware_status_notification.received.v1" in event_types
    assert "gateway.ocpp.security_event_notification.received.v1" in event_types


@pytest.mark.asyncio
async def test_simulator_reboots_and_reconnects_during_ocpp201_transaction() -> None:
    settings, store, runtime = _development_gateway()

    async with _live_gateway(settings, runtime) as endpoint:
        simulator = ChargerSimulator(f"{endpoint}/ocpp", "SIM-REBOOT", "ocpp2.0.1")
        await simulator.connect()
        try:
            await simulator.boot()
            await simulator.reboot_during_charging(500)
            await simulator.stop_session(1_000)
        finally:
            await simulator.close()

    events = store.streams[settings.quarantine_stream]
    connected_count = sum(
        event["event_type"] == "gateway.ocpp.charger_connected.received.v1" for event in events
    )
    assert connected_count == 2
    assert any(
        event["event_type"] == "gateway.ocpp.transaction_event.received.v1"
        and event["data"]["event_type"] == "Updated"
        for event in events
    )
