from __future__ import annotations

from datetime import UTC, datetime, timedelta

import pytest
from fastapi.testclient import TestClient
from starlette.websockets import WebSocketDisconnect

from vtsa_ocpp_gateway.app import create_app
from vtsa_ocpp_gateway.config import Settings


def test_liveness_returns_structured_response_and_ids() -> None:
    client = TestClient(create_app(Settings()))

    response = client.get("/health/live")

    assert response.status_code == 200
    assert response.json()["status"] == "ok"
    assert response.json()["service"] == "ocpp-gateway"
    assert response.headers["X-Request-ID"] == response.json()["request_id"]
    assert response.headers["X-Correlation-ID"] == response.json()["correlation_id"]


def test_valid_incoming_ids_are_propagated() -> None:
    request_id = "01K0M0JJ5X0M0JJ5X0M0JJ5X0M"
    correlation_id = "01K0M0KK6Y0M0KK6Y0M0KK6Y0M"
    client = TestClient(create_app(Settings()))

    response = client.get(
        "/health/ready",
        headers={"X-Request-ID": request_id, "X-Correlation-ID": correlation_id},
    )

    assert response.status_code == 200
    assert response.headers["X-Request-ID"] == request_id
    assert response.headers["X-Correlation-ID"] == correlation_id


def test_charger_connections_are_denied_until_authentication_is_configured() -> None:
    client = TestClient(create_app(Settings()))

    with (
        pytest.raises(WebSocketDisconnect) as error,
        client.websocket_connect("/ocpp/CP-001", subprotocols=["ocpp2.0.1"]),
    ):
        pass

    assert error.value.code == 1008


def test_authenticated_internal_command_reports_disconnected_target() -> None:
    client = TestClient(create_app(Settings(internal_api_token="local-service-token")))

    response = client.post(
        "/internal/v1/commands",
        headers={"Authorization": "Bearer local-service-token"},
        json={
            "command_id": "01K0M0AA1A0M0AA1A0M0AA1A0M",
            "tenant_id": "01K0M0BB2B0M0BB2B0M0BB2B0M",
            "charger_id": "01K0M0CC3C0M0CC3C0M0CC3C0M",
            "charge_point_identity": "CP-OFFLINE",
            "action": "TriggerMessage",
            "payload": {"requested_message": "Heartbeat"},
            "correlation_id": "01K0M0DD4D0M0DD4D0M0DD4D0M",
            "actor_id": "01K0M0EE5E0M0EE5E0M0EE5E0M",
            "reason_code": "operator.status_check",
            "expected_state": {"connection": "online"},
            "deadline_at": (datetime.now(UTC) + timedelta(seconds=30)).isoformat(),
            "timeout_seconds": 2,
        },
    )

    assert response.status_code == 200
    assert response.json()["data"]["status"] == "not_connected"


def test_internal_metrics_fail_closed_without_service_credential_configuration() -> None:
    client = TestClient(create_app(Settings()))

    response = client.get("/internal/metrics")

    assert response.status_code == 503
