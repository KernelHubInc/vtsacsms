from __future__ import annotations

from typing import Any

from ocpp.routing import on
from ocpp.v201 import ChargePoint as Ocpp201Base
from ocpp.v201 import call, call_result
from ocpp.v201.datatypes import IdTokenInfoType
from ocpp.v201.enums import (
    Action,
    AuthorizationStatusEnumType,
    DataTransferStatusEnumType,
    RegistrationStatusEnumType,
)

from vtsa_ocpp_gateway.adapters.base import (
    GatewayChargePointMixin,
    suppressed_protocol_logger,
)
from vtsa_ocpp_gateway.config import Settings
from vtsa_ocpp_gateway.models import ChargerIdentity, JsonObject, utc_iso
from vtsa_ocpp_gateway.normalization import normalize_v201_meter_values
from vtsa_ocpp_gateway.store import GatewayStore
from vtsa_ocpp_gateway.telemetry import EventPublisher, GatewayMetrics


class Ocpp201ChargePoint(GatewayChargePointMixin, Ocpp201Base):
    def __init__(
        self,
        charge_point_identity: str,
        connection: Any,
        *,
        store: GatewayStore,
        settings: Settings,
        identity: ChargerIdentity,
        publisher: EventPublisher,
        metrics: GatewayMetrics,
    ) -> None:
        Ocpp201Base.__init__(
            self,
            charge_point_identity,
            connection,
            response_timeout=settings.command_timeout_seconds,
            logger=suppressed_protocol_logger,
        )
        self.configure_gateway(
            store=store,
            settings=settings,
            identity=identity,
            publisher=publisher,
            metrics=metrics,
            protocol="ocpp2.0.1",
        )
        self._command_types = {
            "TriggerMessage": call.TriggerMessage,
            "Reset": call.Reset,
            "RequestStartTransaction": call.RequestStartTransaction,
            "RequestStopTransaction": call.RequestStopTransaction,
            "UnlockConnector": call.UnlockConnector,
            "ChangeAvailability": call.ChangeAvailability,
            "GetLog": call.GetLog,
            "UpdateFirmware": call.UpdateFirmware,
        }

    @on(Action.boot_notification)
    async def on_boot_notification(
        self, charging_station: JsonObject, reason: str, **_: Any
    ) -> call_result.BootNotification:
        await self.emit(
            "BootNotification",
            {
                "vendor": charging_station.get("vendor_name"),
                "model": charging_station.get("model"),
                "firmware_version": charging_station.get("firmware_version"),
                "serial_number": charging_station.get("serial_number"),
                "reason": reason,
            },
        )
        return call_result.BootNotification(
            current_time=utc_iso(),
            interval=self._settings.heartbeat_interval_seconds,
            status=RegistrationStatusEnumType.accepted,
        )

    @on(Action.heartbeat)
    async def on_heartbeat(self, **_: Any) -> call_result.Heartbeat:
        await self.emit("Heartbeat", {})
        return call_result.Heartbeat(current_time=utc_iso())

    @on(Action.status_notification)
    async def on_status_notification(
        self,
        timestamp: str,
        connector_status: str,
        evse_id: int,
        connector_id: int,
        **_: Any,
    ) -> call_result.StatusNotification:
        await self.emit(
            "StatusNotification",
            {
                "evse_id": evse_id,
                "connector_id": connector_id,
                "status": connector_status,
            },
            timestamp,
        )
        return call_result.StatusNotification()

    @on(Action.authorize)
    async def on_authorize(self, id_token: JsonObject, **_: Any) -> call_result.Authorize:
        decision = await self.authorize_token({"id_token": id_token})
        status = _authorization_status(decision.status)
        await self.emit("Authorize", {"authorization_status": status.value})
        return call_result.Authorize(
            id_token_info=IdTokenInfoType(status=status, cache_expiry_date_time=decision.expires_at)
        )

    @on(Action.transaction_event)
    async def on_transaction_event(
        self,
        event_type: str,
        timestamp: str,
        trigger_reason: str,
        seq_no: int,
        transaction_info: JsonObject,
        meter_value: list[Any] | None = None,
        evse: JsonObject | None = None,
        id_token: JsonObject | None = None,
        **details: Any,
    ) -> call_result.TransactionEvent:
        decision = None
        if event_type == "Started" and id_token is not None:
            decision = await self.authorize_token({"id_token": id_token})
        await self.emit(
            "TransactionEvent",
            {
                "event_type": event_type,
                "trigger_reason": trigger_reason,
                "sequence_number": seq_no,
                "protocol_transaction_id": transaction_info.get("transaction_id"),
                "charging_state": transaction_info.get("charging_state"),
                "evse": evse,
                "offline": details.get("offline"),
                "samples": normalize_v201_meter_values(meter_value or []),
                "authorization_status": decision.status if decision else None,
            },
            timestamp,
        )
        if decision is None:
            return call_result.TransactionEvent()
        return call_result.TransactionEvent(
            id_token_info=IdTokenInfoType(
                status=_authorization_status(decision.status),
                cache_expiry_date_time=decision.expires_at,
            )
        )

    @on(Action.meter_values)
    async def on_meter_values(
        self, evse_id: int, meter_value: list[Any], **_: Any
    ) -> call_result.MeterValues:
        samples = normalize_v201_meter_values(meter_value)
        timestamp = next(
            (str(sample["timestamp"]) for sample in samples if sample.get("timestamp")), None
        )
        await self.emit("MeterValues", {"evse_id": evse_id, "samples": samples}, timestamp)
        return call_result.MeterValues()

    @on(Action.data_transfer)
    async def on_data_transfer(
        self,
        vendor_id: str,
        message_id: str | None = None,
        data: Any = None,
        **_: Any,
    ) -> call_result.DataTransfer:
        if vendor_id not in self._settings.data_transfer_allowlist:
            await self.emit(
                "DataTransfer",
                {"vendor_id": vendor_id, "message_id": message_id, "accepted": False},
            )
            return call_result.DataTransfer(status=DataTransferStatusEnumType.unknown_vendor_id)
        await self.emit(
            "DataTransfer",
            {
                "vendor_id": vendor_id,
                "message_id": message_id,
                "data": data,
                "accepted": True,
            },
        )
        return call_result.DataTransfer(status=DataTransferStatusEnumType.accepted)

    @on(Action.log_status_notification)
    async def on_log_status(
        self, status: str, request_id: int | None = None, **_: Any
    ) -> call_result.LogStatusNotification:
        await self.emit(
            "DiagnosticsStatusNotification", {"status": status, "request_id": request_id}
        )
        return call_result.LogStatusNotification()

    @on(Action.firmware_status_notification)
    async def on_firmware_status(
        self, status: str, request_id: int | None = None, **_: Any
    ) -> call_result.FirmwareStatusNotification:
        await self.emit("FirmwareStatusNotification", {"status": status, "request_id": request_id})
        return call_result.FirmwareStatusNotification()

    @on(Action.security_event_notification)
    async def on_security_event(
        self, type: str, timestamp: str, tech_info: str | None = None, **_: Any
    ) -> call_result.SecurityEventNotification:
        await self.emit(
            "SecurityEventNotification",
            {"type": type, "technical_info_present": tech_info is not None},
            timestamp,
        )
        return call_result.SecurityEventNotification()


def _authorization_status(value: str) -> AuthorizationStatusEnumType:
    try:
        return AuthorizationStatusEnumType(value)
    except ValueError:
        return AuthorizationStatusEnumType.invalid
