from __future__ import annotations

import json
from datetime import UTC, datetime
from typing import Any

import structlog
from prometheus_client import CollectorRegistry, Counter, Gauge, Histogram, generate_latest

from vtsa_ocpp_gateway.config import Settings
from vtsa_ocpp_gateway.models import ChargerIdentity, JsonObject, integration_event
from vtsa_ocpp_gateway.store import GatewayStore

logger = structlog.get_logger()


class GatewayMetrics:
    def __init__(self) -> None:
        self.registry = CollectorRegistry()
        self.connections = Gauge(
            "vtsa_ocpp_connections",
            "Currently connected chargers",
            ["protocol"],
            registry=self.registry,
        )
        self.messages = Counter(
            "vtsa_ocpp_messages_total",
            "OCPP messages processed",
            ["protocol", "direction", "action", "outcome"],
            registry=self.registry,
        )
        self.duplicates = Counter(
            "vtsa_ocpp_duplicate_messages_total",
            "Duplicate charger CALL messages replayed or rejected",
            ["protocol", "outcome"],
            registry=self.registry,
        )
        self.events = Counter(
            "vtsa_ocpp_events_published_total",
            "Normalized events published",
            ["event_type", "stream_kind"],
            registry=self.registry,
        )
        self.clock_skew = Counter(
            "vtsa_ocpp_clock_skew_total",
            "Protocol timestamps beyond the configured skew threshold",
            ["protocol", "action"],
            registry=self.registry,
        )
        self.commands = Counter(
            "vtsa_ocpp_commands_total",
            "Gateway command outcomes",
            ["protocol", "action", "status"],
            registry=self.registry,
        )
        self.command_duration = Histogram(
            "vtsa_ocpp_command_duration_seconds",
            "Time from command dispatch to protocol response",
            ["protocol", "action"],
            registry=self.registry,
        )

    def render(self) -> bytes:
        return generate_latest(self.registry)


class MessageRedactor:
    def __init__(self, fields: tuple[str, ...]) -> None:
        self._fields = {_canonical_field_name(field) for field in fields}

    def redact(self, value: Any) -> Any:
        if isinstance(value, dict):
            return {
                str(key): "[REDACTED]"
                if _canonical_field_name(str(key)) in self._fields
                else self.redact(item)
                for key, item in value.items()
            }
        if isinstance(value, list):
            return [self.redact(item) for item in value]
        return value


class RawMessageLogger:
    def __init__(self, settings: Settings, redactor: MessageRedactor) -> None:
        self._enabled = settings.raw_message_logging
        self._maximum_bytes = settings.maximum_message_bytes
        self._redactor = redactor
        self._actions: dict[str, str] = {}

    async def log(self, raw: str, *, direction: str, protocol: str) -> tuple[str, str, str]:
        size_bytes = len(raw.encode("utf-8"))
        if size_bytes > self._maximum_bytes:
            raise ValueError("OCPP message exceeds configured maximum size")
        decoded = json.loads(raw)
        if not isinstance(decoded, list) or len(decoded) < 3:
            raise ValueError("OCPP message must be a JSON array")
        message_type = str(decoded[0])
        unique_id = str(decoded[1])
        if message_type == "2" and len(decoded) == 4:
            action = str(decoded[2])
            self._actions[unique_id] = action
            payload = decoded[3]
        else:
            action = self._actions.get(unique_id, "response")
            payload = decoded[2:]
        if self._enabled:
            await logger.ainfo(
                "ocpp_raw_message",
                direction=direction,
                protocol=protocol,
                message_type=message_type,
                unique_id=unique_id,
                action=action,
                size_bytes=size_bytes,
                payload=self._redactor.redact(payload),
            )
        return message_type, unique_id, action


class EventPublisher:
    def __init__(
        self,
        store: GatewayStore,
        settings: Settings,
        identity: ChargerIdentity,
        protocol: str,
        connection_id: str,
        metrics: GatewayMetrics,
    ) -> None:
        self._store = store
        self._settings = settings
        self._identity = identity
        self._protocol = protocol
        self._connection_id = connection_id
        self._metrics = metrics

    async def emit(
        self,
        action: str,
        data: JsonObject,
        *,
        correlation_id: str,
        causation_id: str | None = None,
        protocol_timestamp: str | None = None,
    ) -> JsonObject:
        skew_seconds = self.clock_skew_seconds(protocol_timestamp)
        event_type = f"gateway.ocpp.{_snake_case(action)}.received.v1"
        event = integration_event(
            event_type=event_type,
            identity=self._identity,
            correlation_id=correlation_id,
            causation_id=causation_id,
            data={
                **data,
                "charge_point_identity": self._identity.charge_point_identity,
                "protocol": self._protocol,
                "gateway_node_id": self._settings.node_id,
                "connection_id": self._connection_id,
                "protocol_timestamp": protocol_timestamp,
                "clock_skew_seconds": skew_seconds,
                "clock_skew_detected": skew_seconds is not None
                and abs(skew_seconds) > self._settings.maximum_clock_skew_seconds,
            },
        )
        stream = (
            self._settings.event_stream
            if self._identity.is_bound
            else self._settings.quarantine_stream
        )
        await self._store.publish(stream, event, self._settings.stream_max_length)
        stream_kind = "events" if self._identity.is_bound else "quarantine"
        self._metrics.events.labels(event_type=event_type, stream_kind=stream_kind).inc()
        if event["data"]["clock_skew_detected"]:
            self._metrics.clock_skew.labels(protocol=self._protocol, action=action).inc()
        return event

    @staticmethod
    def clock_skew_seconds(timestamp: str | None) -> float | None:
        if timestamp is None:
            return None
        try:
            parsed = datetime.fromisoformat(timestamp.replace("Z", "+00:00"))
        except ValueError:
            return None
        if parsed.tzinfo is None:
            parsed = parsed.replace(tzinfo=UTC)
        return round((parsed.astimezone(UTC) - datetime.now(UTC)).total_seconds(), 3)


def _snake_case(value: str) -> str:
    output: list[str] = []
    for index, character in enumerate(value):
        if character.isupper() and index > 0 and value[index - 1].islower():
            output.append("_")
        output.append(character.casefold())
    return "".join(output)


def _canonical_field_name(value: str) -> str:
    return "".join(character for character in value.casefold() if character.isalnum())
