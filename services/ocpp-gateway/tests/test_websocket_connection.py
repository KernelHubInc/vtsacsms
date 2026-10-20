from __future__ import annotations

from typing import cast
from unittest.mock import AsyncMock

import pytest
from fastapi import WebSocket, WebSocketDisconnect
from starlette.websockets import WebSocketState

from vtsa_ocpp_gateway.config import Settings
from vtsa_ocpp_gateway.connections import WebSocketOcppConnection
from vtsa_ocpp_gateway.store import MemoryGatewayStore
from vtsa_ocpp_gateway.telemetry import (
    GatewayMetrics,
    MessageRedactor,
    RawMessageLogger,
)


class StatefulWebSocket:
    def __init__(self, state: WebSocketState) -> None:
        self.application_state = state
        self.close_calls = 0

    async def close(self, code: int = 1000, reason: str = "") -> None:
        self.close_calls += 1
        self.application_state = WebSocketState.DISCONNECTED


def connection(websocket: StatefulWebSocket) -> WebSocketOcppConnection:
    settings = Settings()
    return WebSocketOcppConnection(
        cast(WebSocket, websocket),
        charge_point_identity="CP-1",
        protocol="ocpp1.6",
        store=MemoryGatewayStore(),
        settings=settings,
        raw_logger=RawMessageLogger(settings, MessageRedactor(settings.redacted_fields)),
        metrics=GatewayMetrics(),
    )


@pytest.mark.asyncio
async def test_close_is_idempotent_after_application_disconnect() -> None:
    websocket = StatefulWebSocket(WebSocketState.CONNECTED)
    transport = connection(websocket)

    await transport.close(code=1012, reason="Connection ownership was lost")
    await transport.close(code=1012, reason="Connection ownership was lost")

    assert websocket.close_calls == 1


@pytest.mark.asyncio
async def test_close_does_not_send_after_peer_is_already_disconnected() -> None:
    websocket = StatefulWebSocket(WebSocketState.DISCONNECTED)

    await connection(websocket).close(code=1012, reason="Connection ownership was lost")

    assert websocket.close_calls == 0


@pytest.mark.asyncio
async def test_silent_charger_is_closed_even_when_pong_timeout_is_disabled(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    websocket = StatefulWebSocket(WebSocketState.CONNECTED)
    transport = connection(websocket)
    transport._settings = Settings(websocket_ping_timeout_seconds=0)
    monkeypatch.setattr(
        websocket, "receive_text", AsyncMock(side_effect=TimeoutError), raising=False
    )
    with pytest.raises(WebSocketDisconnect) as error:
        await transport.recv()
    assert error.value.code == 1001
    assert websocket.close_calls == 1


@pytest.mark.asyncio
async def test_application_heartbeats_continue_without_transport_pongs(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    websocket = StatefulWebSocket(WebSocketState.CONNECTED)
    transport = connection(websocket)
    transport._settings = Settings(websocket_ping_timeout_seconds=0)
    messages = ['[2,"hb-1","Heartbeat",{}]', '[2,"hb-2","Heartbeat",{}]']
    monkeypatch.setattr(websocket, "receive_text", AsyncMock(side_effect=messages), raising=False)
    assert await transport.recv() == messages[0]
    assert await transport.recv() == messages[1]
    assert websocket.close_calls == 0
