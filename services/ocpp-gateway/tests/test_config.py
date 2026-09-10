from __future__ import annotations

import pytest

from vtsa_ocpp_gateway.config import Settings


def test_forwarded_proxy_trust_defaults_to_loopback(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("GATEWAY_FORWARDED_ALLOW_IPS", raising=False)

    assert Settings.from_environment().forwarded_allow_ips == "127.0.0.1"


def test_forwarded_proxy_trust_can_be_configured(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("GATEWAY_FORWARDED_ALLOW_IPS", "10.0.0.0/8")

    assert Settings.from_environment().forwarded_allow_ips == "10.0.0.0/8"
