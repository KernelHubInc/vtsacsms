from __future__ import annotations

from unittest.mock import patch

import pytest

from vtsa_ocpp_gateway.config import Settings


def test_forwarded_proxy_trust_defaults_to_loopback(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("GATEWAY_FORWARDED_ALLOW_IPS", raising=False)

    assert Settings.from_environment().forwarded_allow_ips == "127.0.0.1"


def test_forwarded_proxy_trust_can_be_configured(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("GATEWAY_FORWARDED_ALLOW_IPS", "10.0.0.0/8")

    assert Settings.from_environment().forwarded_allow_ips == "10.0.0.0/8"


def test_heartbeat_and_transport_timeouts_are_independent(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("GATEWAY_HEARTBEAT_INTERVAL_SECONDS", "30")
    monkeypatch.setenv("GATEWAY_WS_PING_TIMEOUT_SECONDS", "0")
    monkeypatch.setenv("GATEWAY_MESSAGE_IDLE_TIMEOUT_SECONDS", "180")
    settings = Settings.from_environment()
    assert settings.heartbeat_interval_seconds == 30
    assert settings.websocket_ping_timeout_seconds == 0
    assert settings.message_idle_timeout_seconds == 180


@pytest.mark.parametrize("idle", [0, 30, 89])
def test_idle_timeout_must_allow_missed_heartbeats(idle: float) -> None:
    with pytest.raises(ValueError, match="three heartbeat intervals"):
        Settings(message_idle_timeout_seconds=idle)


def test_launcher_disables_only_pong_deadline(monkeypatch: pytest.MonkeyPatch) -> None:
    from vtsa_ocpp_gateway.__main__ import main

    monkeypatch.setenv("GATEWAY_WS_PING_TIMEOUT_SECONDS", "0")
    monkeypatch.setenv("GATEWAY_HEARTBEAT_INTERVAL_SECONDS", "30")
    with patch("vtsa_ocpp_gateway.__main__.uvicorn.run") as run:
        main()
    assert run.call_args.kwargs["ws_ping_timeout"] is None
    assert run.call_args.kwargs["ws_ping_interval"] == 30
