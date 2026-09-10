from __future__ import annotations

from contextvars import ContextVar
from dataclasses import dataclass

from vtsa_ocpp_gateway.ids import new_ulid


@dataclass(frozen=True, slots=True)
class InboundMessageContext:
    ocpp_unique_id: str
    action: str
    causation_id: str


_current: ContextVar[InboundMessageContext | None] = ContextVar(
    "ocpp_inbound_message", default=None
)


def set_inbound_message(unique_id: str, action: str) -> None:
    _current.set(InboundMessageContext(unique_id, action, new_ulid()))


def current_inbound_message() -> InboundMessageContext:
    context = _current.get()
    return context or InboundMessageContext("unknown", "unknown", new_ulid())
