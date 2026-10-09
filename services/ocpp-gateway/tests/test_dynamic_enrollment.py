from __future__ import annotations

import base64
import json
from typing import Any, cast
from unittest.mock import MagicMock

import pytest
from starlette.websockets import WebSocket

from vtsa_ocpp_gateway.config import Settings
from vtsa_ocpp_gateway.security import ChargerIdentityError, ChargerIdentityValidator, NoRedirect

TENANT = "01J00000000000000000000001"
CHARGER = "01J00000000000000000000002"
PASSWORD = "synthetic-device-password"


def socket(identity: str, password: str = PASSWORD) -> WebSocket:
    auth = base64.b64encode(f"{identity}:{password}".encode()).decode()
    return cast(WebSocket, type("Socket", (), {"headers": {"authorization": f"Basic {auth}"}})())


def validator(registry: str = "{}") -> ChargerIdentityValidator:
    return ChargerIdentityValidator(
        registry,
        False,
        core_auth_url="http://platform:8000/api/internal/v1/ocpp/authenticate",
        core_auth_token="synthetic-service-token",
    )


async def test_new_stations_and_password_changes_are_read_without_restarting(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    registrations: dict[str, str] = {}
    client = MagicMock()

    def open_request(request: Any, timeout: int) -> MagicMock:
        data = json.loads(request.data)
        assert timeout == 3
        assert request.get_header("Authorization") == "Bearer synthetic-service-token"
        assert data["protocol"] == "ocpp1.6"
        if registrations.get(data["identity"]) != data["password"]:
            raise OSError("Core denied")
        response = MagicMock()
        response.status = 200
        response.read.return_value = json.dumps(
            {
                "data": {
                    "charge_point_identity": data["identity"],
                    "tenant_id": TENANT,
                    "charger_id": CHARGER,
                }
            }
        ).encode()
        response.__enter__.return_value = response
        return response

    client.open.side_effect = open_request
    monkeypatch.setattr("urllib.request.build_opener", lambda *args: client)
    auth = validator()
    with pytest.raises(ChargerIdentityError):
        await auth.validate(socket("NEW-CP"), "NEW-CP")
    registrations["NEW-CP"] = PASSWORD
    result = await auth.validate(socket("NEW-CP"), "NEW-CP")
    assert (result.tenant_id, result.charger_id) == (TENANT, CHARGER)
    registrations["NEW-CP"] = "rotated"
    with pytest.raises(ChargerIdentityError):
        await auth.validate(socket("NEW-CP"), "NEW-CP")
    await auth.validate(socket("NEW-CP", "rotated"), "NEW-CP")
    registrations.clear()
    with pytest.raises(ChargerIdentityError):
        await auth.validate(socket("NEW-CP", "rotated"), "NEW-CP")


@pytest.mark.parametrize(
    "payload",
    [
        b"not-json",
        b"{}",
        b'{"data":{"charge_point_identity":"OTHER"}}',
        json.dumps(
            {"data": {"charge_point_identity": "CP", "tenant_id": "bad", "charger_id": CHARGER}}
        ).encode(),
    ],
)
async def test_invalid_core_responses_fail_closed(
    monkeypatch: pytest.MonkeyPatch, payload: bytes
) -> None:
    client = MagicMock()
    response = client.open.return_value.__enter__.return_value
    response.status = 200
    response.read.return_value = payload
    monkeypatch.setattr("urllib.request.build_opener", lambda *args: client)
    with pytest.raises(ChargerIdentityError):
        await validator().validate(socket("CP"), "CP")


async def test_timeout_does_not_fall_back_to_static_registration(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    from argon2 import PasswordHasher

    registry = json.dumps(
        {
            "CP": {
                "enabled": True,
                "tenant_id": TENANT,
                "charger_id": CHARGER,
                "basic_password_hash": PasswordHasher().hash(PASSWORD),
            }
        }
    )
    client = MagicMock()
    client.open.side_effect = TimeoutError("sensitive upstream details")
    monkeypatch.setattr("urllib.request.build_opener", lambda *args: client)
    with pytest.raises(ChargerIdentityError, match=r"^Charger authentication failed$"):
        await validator(registry).validate(socket("CP"), "CP")


async def test_path_username_mismatch_is_rejected_before_core_request(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    build = MagicMock()
    monkeypatch.setattr("urllib.request.build_opener", build)
    with pytest.raises(ChargerIdentityError):
        await validator().validate(socket("OTHER"), "CP")
    build.assert_not_called()


def test_redirects_are_not_followed() -> None:
    NoRedirect().redirect_request(None, None, 302, "redirect", {}, "https://untrusted.example")


def test_dynamic_configuration_requires_service_authentication() -> None:
    with pytest.raises(ValueError, match="service token"):
        Settings(core_auth_url="http://platform:8000/api/internal/v1/ocpp/authenticate")
    with pytest.raises(ValueError, match="service token"):
        Settings(
            core_auth_url="http://platform:8000/api/internal/v1/ocpp/authenticate",
            internal_api_token="synthetic-token",
            allow_unauthenticated_development=True,
        )
