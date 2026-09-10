from __future__ import annotations

import json
from datetime import UTC, datetime, timedelta

import pytest

from vtsa_ocpp_gateway.config import Settings
from vtsa_ocpp_gateway.models import ChargerIdentity
from vtsa_ocpp_gateway.store import MemoryGatewayStore
from vtsa_ocpp_gateway.telemetry import (
    EventPublisher,
    GatewayMetrics,
    MessageRedactor,
    RawMessageLogger,
)


def test_redactor_recurses_and_matches_field_names_case_insensitively() -> None:
    redactor = MessageRedactor(("data", "id_token", "password", "tech_info"))

    redacted = redactor.redact(
        {
            "nested": [{"Id_Token": {"idToken": "private"}}],
            "password": "private",
            "techInfo": "private",
            "data": "opaque vendor content",
        }
    )

    assert redacted == {
        "nested": [{"Id_Token": "[REDACTED]"}],
        "password": "[REDACTED]",
        "techInfo": "[REDACTED]",
        "data": "[REDACTED]",
    }


@pytest.mark.asyncio
async def test_raw_logger_rejects_oversized_messages_before_logging() -> None:
    settings = Settings(maximum_message_bytes=1024, raw_message_logging=False)
    raw_logger = RawMessageLogger(settings, MessageRedactor(settings.redacted_fields))
    raw = json.dumps([2, "id", "DataTransfer", {"data": "x" * 2_000}])

    with pytest.raises(ValueError, match="maximum size"):
        await raw_logger.log(raw, direction="charger_to_gateway", protocol="ocpp1.6")


@pytest.mark.asyncio
async def test_unbound_charger_events_are_quarantined_and_clock_skew_is_flagged() -> None:
    settings = Settings(maximum_clock_skew_seconds=60)
    store = MemoryGatewayStore()
    publisher = EventPublisher(
        store,
        settings,
        ChargerIdentity("SIM-1", None, None, "development"),
        "ocpp1.6",
        "01K0M0JJ5X0M0JJ5X0M0JJ5X0M",
        GatewayMetrics(),
    )
    timestamp = (datetime.now(UTC) - timedelta(minutes=10)).isoformat()

    event = await publisher.emit(
        "MeterValues", {}, correlation_id="01K0M0KK6Y0M0KK6Y0M0KK6Y0M", protocol_timestamp=timestamp
    )

    assert event["tenant_id"] is None
    assert event["data"]["clock_skew_detected"] is True
    assert store.streams[settings.quarantine_stream] == [event]


@pytest.mark.asyncio
async def test_bound_charger_event_carries_tenant_and_charger_identity() -> None:
    settings = Settings()
    store = MemoryGatewayStore()
    publisher = EventPublisher(
        store,
        settings,
        ChargerIdentity(
            "CP-1",
            "01K0M0AA1A0M0AA1A0M0AA1A0M",
            "01K0M0BB2B0M0BB2B0M0BB2B0M",
            "basic",
        ),
        "ocpp2.0.1",
        "connection-ulid",
        GatewayMetrics(),
    )

    event = await publisher.emit("Heartbeat", {}, correlation_id="correlation-ulid")

    assert event["tenant_id"] == "01K0M0AA1A0M0AA1A0M0AA1A0M"
    assert event["aggregate_id"] == "01K0M0BB2B0M0BB2B0M0BB2B0M"
    assert store.streams[settings.event_stream] == [event]
