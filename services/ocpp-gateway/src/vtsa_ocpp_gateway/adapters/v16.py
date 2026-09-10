from __future__ import annotations

from typing import Any

from ocpp.routing import on
from ocpp.v16 import ChargePoint as Ocpp16Base
from ocpp.v16 import call, call_result
from ocpp.v16.datatypes import IdTagInfo
from ocpp.v16.enums import (
    Action,
    AuthorizationStatus,
    DataTransferStatus,
    RegistrationStatus,
)

from vtsa_ocpp_gateway.adapters.base import (
    GatewayChargePointMixin,
    suppressed_protocol_logger,
)
from vtsa_ocpp_gateway.config import Settings
from vtsa_ocpp_gateway.models import ChargerIdentity, utc_iso
from vtsa_ocpp_gateway.normalization import normalize_v16_meter_values
from vtsa_ocpp_gateway.store import GatewayStore
from vtsa_ocpp_gateway.telemetry import EventPublisher, GatewayMetrics


class Ocpp16ChargePoint(GatewayChargePointMixin, Ocpp16Base):
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
        Ocpp16Base.__init__(
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
            protocol="ocpp1.6",
        )
        self._command_types = {
            "TriggerMessage": call.TriggerMessage,
            "Reset": call.Reset,
            "RemoteStartTransaction": call.RemoteStartTransaction,
            "RemoteStopTransaction": call.RemoteStopTransaction,
            "UnlockConnector": call.UnlockConnector,
            "ChangeAvailability": call.ChangeAvailability,
            "GetDiagnostics": call.GetDiagnostics,
            "UpdateFirmware": call.UpdateFirmware,
        }

    @on(Action.boot_notification)
    async def on_boot_notification(
        self, charge_point_vendor: str, charge_point_model: str, **details: Any
    ) -> call_result.BootNotification:
        await self.emit(
            "BootNotification",
            {
                "vendor": charge_point_vendor,
                "model": charge_point_model,
                "firmware_version": details.get("firmware_version"),
                "serial_number": details.get("charge_point_serial_number")
                or details.get("charge_box_serial_number"),
            },
        )
        return call_result.BootNotification(
            current_time=utc_iso(),
            interval=self._settings.heartbeat_interval_seconds,
            status=RegistrationStatus.accepted,
        )

    @on(Action.heartbeat)
    async def on_heartbeat(self) -> call_result.Heartbeat:
        await self.emit("Heartbeat", {})
        return call_result.Heartbeat(current_time=utc_iso())

    @on(Action.status_notification)
    async def on_status_notification(
        self,
        connector_id: int,
        error_code: str,
        status: str,
        timestamp: str | None = None,
        **details: Any,
    ) -> call_result.StatusNotification:
        await self.emit(
            "StatusNotification",
            {
                "evse_id": 1,
                "connector_id": connector_id,
                "status": status,
                "error_code": error_code,
                "vendor_error_code": details.get("vendor_error_code"),
            },
            timestamp,
        )
        return call_result.StatusNotification()

    @on(Action.authorize)
    async def on_authorize(self, id_tag: str) -> call_result.Authorize:
        decision = await self.authorize_token({"id_tag": id_tag})
        status = _authorization_status(decision.status)
        await self.emit("Authorize", {"authorization_status": status.value})
        return call_result.Authorize(
            id_tag_info=IdTagInfo(
                status=status,
                expiry_date=decision.expires_at,
                parent_id_tag=decision.parent_token,
            )
        )

    @on(Action.start_transaction)
    async def on_start_transaction(
        self,
        connector_id: int,
        id_tag: str,
        meter_start: int,
        timestamp: str,
        reservation_id: int | None = None,
    ) -> call_result.StartTransaction:
        decision = await self.authorize_token({"id_tag": id_tag})
        status = _authorization_status(decision.status)
        protocol_transaction_id = await self._store.next_protocol_transaction_id(
            self._identity.charge_point_identity
        )
        await self.emit(
            "StartTransaction",
            {
                "connector_id": connector_id,
                "meter_start_wh": meter_start,
                "reservation_id": reservation_id,
                "protocol_transaction_id": protocol_transaction_id,
                "authorization_status": status.value,
            },
            timestamp,
        )
        return call_result.StartTransaction(
            transaction_id=protocol_transaction_id,
            id_tag_info=IdTagInfo(
                status=status,
                expiry_date=decision.expires_at,
                parent_id_tag=decision.parent_token,
            ),
        )

    @on(Action.stop_transaction)
    async def on_stop_transaction(
        self,
        meter_stop: int,
        timestamp: str,
        transaction_id: int,
        reason: str | None = None,
        transaction_data: list[Any] | None = None,
        **_: Any,
    ) -> call_result.StopTransaction:
        await self.emit(
            "StopTransaction",
            {
                "protocol_transaction_id": transaction_id,
                "meter_stop_wh": meter_stop,
                "reason": reason,
                "samples": normalize_v16_meter_values(transaction_data or []),
            },
            timestamp,
        )
        return call_result.StopTransaction()

    @on(Action.meter_values)
    async def on_meter_values(
        self,
        connector_id: int,
        meter_value: list[Any],
        transaction_id: int | None = None,
    ) -> call_result.MeterValues:
        samples = normalize_v16_meter_values(meter_value)
        timestamp = next(
            (str(sample["timestamp"]) for sample in samples if sample.get("timestamp")), None
        )
        await self.emit(
            "MeterValues",
            {
                "connector_id": connector_id,
                "protocol_transaction_id": transaction_id,
                "samples": samples,
            },
            timestamp,
        )
        return call_result.MeterValues()

    @on(Action.data_transfer)
    async def on_data_transfer(
        self, vendor_id: str, message_id: str | None = None, data: str | None = None
    ) -> call_result.DataTransfer:
        if vendor_id not in self._settings.data_transfer_allowlist:
            await self.emit(
                "DataTransfer",
                {"vendor_id": vendor_id, "message_id": message_id, "accepted": False},
            )
            return call_result.DataTransfer(status=DataTransferStatus.unknown_vendor_id)
        await self.emit(
            "DataTransfer",
            {
                "vendor_id": vendor_id,
                "message_id": message_id,
                "data": data,
                "accepted": True,
            },
        )
        return call_result.DataTransfer(status=DataTransferStatus.accepted)

    @on(Action.diagnostics_status_notification)
    async def on_diagnostics_status(self, status: str) -> call_result.DiagnosticsStatusNotification:
        await self.emit("DiagnosticsStatusNotification", {"status": status})
        return call_result.DiagnosticsStatusNotification()

    @on(Action.firmware_status_notification)
    async def on_firmware_status(self, status: str) -> call_result.FirmwareStatusNotification:
        await self.emit("FirmwareStatusNotification", {"status": status})
        return call_result.FirmwareStatusNotification()

    @on(Action.security_event_notification)
    async def on_security_event(
        self, type: str, timestamp: str, tech_info: str | None = None
    ) -> call_result.SecurityEventNotification:
        await self.emit(
            "SecurityEventNotification",
            {"type": type, "technical_info_present": tech_info is not None},
            timestamp,
        )
        return call_result.SecurityEventNotification()


def _authorization_status(value: str) -> AuthorizationStatus:
    try:
        return AuthorizationStatus(value)
    except ValueError:
        return AuthorizationStatus.invalid
