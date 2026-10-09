from __future__ import annotations

import json
from typing import cast
from unittest.mock import MagicMock

import pytest
from starlette.websockets import WebSocket

from vtsa_ocpp_gateway.config import Settings
from vtsa_ocpp_gateway.security import ChargerIdentityError, ChargerIdentityValidator


@pytest.mark.parametrize("header", [None, "Basic obsolete-device-credentials"])
async def test_registered_chargers_need_no_device_credentials(
    monkeypatch: pytest.MonkeyPatch, header: str | None
) -> None:
    client = MagicMock()
    response = client.open.return_value.__enter__.return_value
    response.status = 200
    response.read.return_value = json.dumps(
        {
            "data": {
                "tenant_id": "01J00000000000000000000001",
                "charger_id": "01J00000000000000000000002",
                "charge_point_identity": "DEMO-CP-022",
                "authentication": "registered",
            }
        }
    ).encode()
    monkeypatch.setattr("urllib.request.build_opener", lambda *args: client)
    validator = ChargerIdentityValidator(
        "{}",
        False,
        core_auth_token="synthetic-service",
        core_registration_url="http://core/api/internal/v1/ocpp/resolve",
    )
    socket = cast(
        WebSocket,
        type("Socket", (), {"headers": {} if header is None else {"authorization": header}})(),
    )
    result = await validator.validate(socket, "DEMO-CP-022")
    assert result.authentication == "registered"
    assert result.is_bound
    request = client.open.call_args.args[0]
    assert json.loads(request.data) == {"identity": "DEMO-CP-022", "protocol": "ocpp1.6"}
    assert request.get_header("Authorization") == "Bearer synthetic-service"
    response.read.return_value = b'{"data":{}}'
    with pytest.raises(ChargerIdentityError):
        await validator.validate(socket, "DEMO-CP-022")
    client.open.side_effect = TimeoutError("private upstream details")
    with pytest.raises(ChargerIdentityError, match=r"^Charger registration failed$"):
        await validator.validate(socket, "DEMO-CP-022")


def test_url_only_mode_cannot_mix_with_basic_or_require_device_certificates() -> None:
    url = "https://core.example/api/internal/v1/ocpp/resolve"
    with pytest.raises(ValueError, match="service token"):
        Settings(core_registration_url=url)
    with pytest.raises(ValueError, match="either"):
        Settings(core_registration_url=url, core_auth_url=url, internal_api_token="synthetic")
    with pytest.raises(ValueError, match="client certificates"):
        Settings(
            core_registration_url=url,
            internal_api_token="synthetic",
            require_client_certificate=True,
            tls_client_ca_file="synthetic.pem",
        )
    with pytest.raises(ValueError, match="HTTPS"):
        Settings(
            environment="staging",
            core_registration_url="http://core/resolve",
            internal_api_token="synthetic",
        )
