from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, datetime
from typing import Any, Literal

from vtsa_ocpp_gateway.ids import new_ulid

JsonObject = dict[str, Any]


def utc_now() -> datetime:
    return datetime.now(UTC)


def utc_iso(value: datetime | None = None) -> str:
    return (value or utc_now()).isoformat(timespec="milliseconds").replace("+00:00", "Z")


@dataclass(frozen=True, slots=True)
class ChargerIdentity:
    charge_point_identity: str
    tenant_id: str | None
    charger_id: str | None
    authentication: Literal["basic", "mtls", "development"]

    @property
    def is_bound(self) -> bool:
        return self.tenant_id is not None and self.charger_id is not None


@dataclass(frozen=True, slots=True)
class AuthorizationDecision:
    status: str
    expires_at: str | None = None
    parent_token: str | None = None


@dataclass(frozen=True, slots=True)
class CommandRequest:
    command_id: str
    tenant_id: str
    charger_id: str
    charge_point_identity: str
    action: str
    payload: JsonObject
    correlation_id: str
    actor_id: str
    reason_code: str
    expected_state: JsonObject
    deadline_at: datetime
    timeout_seconds: float


@dataclass(frozen=True, slots=True)
class CommandResult:
    command_id: str
    action: str
    status: Literal["completed", "rejected", "timed_out", "not_connected", "failed"]
    response: JsonObject | None = None
    error: str | None = None


def integration_event(
    *,
    event_type: str,
    identity: ChargerIdentity,
    correlation_id: str,
    causation_id: str | None,
    data: JsonObject,
) -> JsonObject:
    return {
        "event_id": new_ulid(),
        "event_type": event_type,
        "schema_version": 1,
        "occurred_at": utc_iso(),
        "tenant_id": identity.tenant_id,
        "aggregate_type": "charging_station",
        "aggregate_id": identity.charger_id,
        "correlation_id": correlation_id,
        "causation_id": causation_id,
        "data": data,
    }
