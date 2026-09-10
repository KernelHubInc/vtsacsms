from __future__ import annotations

import argparse
import asyncio
import base64
import json
import os
import ssl
from contextlib import suppress
from pathlib import Path
from typing import Any, Literal, cast
from urllib.parse import quote

from websockets.asyncio.client import ClientConnection, connect
from websockets.exceptions import ConnectionClosed
from websockets.typing import Subprotocol

from vtsa_ocpp_gateway.ids import new_ulid
from vtsa_ocpp_gateway.models import JsonObject, utc_iso

Protocol = Literal["ocpp1.6", "ocpp2.0.1"]


class ChargerSimulator:
    """A deterministic protocol peer for local development and integration tests."""

    def __init__(
        self,
        base_url: str,
        identity: str,
        protocol: Protocol,
        *,
        password: str | None = None,
        ca_file: Path | None = None,
        certificate_file: Path | None = None,
        private_key_file: Path | None = None,
        timeout_seconds: float = 10,
    ) -> None:
        self.base_url = base_url.rstrip("/")
        self.identity = identity
        self.protocol = protocol
        self.password = password
        self.timeout_seconds = timeout_seconds
        self.ca_file = ca_file
        self.certificate_file = certificate_file
        self.private_key_file = private_key_file
        self.connection: ClientConnection | None = None
        self.reader_task: asyncio.Task[None] | None = None
        self.pending: dict[str, asyncio.Future[JsonObject]] = {}
        self.protocol_transaction_id: int | None = None
        self.transaction_id = new_ulid()
        self.sequence_number = 0

    async def connect(self) -> None:
        if self.connection is not None:
            raise RuntimeError("Simulator is already connected")
        headers: dict[str, str] = {}
        if self.password is not None:
            credentials = base64.b64encode(f"{self.identity}:{self.password}".encode()).decode()
            headers["Authorization"] = f"Basic {credentials}"
        ssl_context = self._ssl_context() if self.base_url.startswith("wss://") else None
        endpoint = f"{self.base_url}/{quote(self.identity, safe='')}"
        self.connection = await connect(
            endpoint,
            subprotocols=[Subprotocol(self.protocol)],
            additional_headers=headers,
            ssl=ssl_context,
            max_size=262_144,
            ping_interval=20,
            ping_timeout=20,
        )
        self.reader_task = asyncio.create_task(self._read_messages(), name="simulator-reader")

    async def close(self) -> None:
        connection = self.connection
        self.connection = None
        if connection is not None:
            await connection.close(code=1000, reason="Simulator scenario completed")
        await self._finish_reader()

    async def disconnect_unexpectedly(self) -> None:
        connection = self._require_connection()
        self.connection = None
        cast(Any, connection).transport.abort()
        if self.reader_task is not None:
            self.reader_task.cancel()
        await self._finish_reader()

    async def reconnect(self) -> None:
        if self.connection is not None:
            await self.disconnect_unexpectedly()
        await self.connect()

    async def boot(self, reason: str = "PowerUp", *, unique_id: str | None = None) -> JsonObject:
        if self.protocol == "ocpp1.6":
            payload: JsonObject = {
                "chargePointVendor": "VTSA Simulator",
                "chargePointModel": "Protocol Lab",
                "firmwareVersion": "sim-1.0.0",
                "chargePointSerialNumber": self.identity,
            }
        else:
            payload = {
                "reason": reason,
                "chargingStation": {
                    "model": "Protocol Lab",
                    "vendorName": "VTSA Simulator",
                    "firmwareVersion": "sim-1.0.0",
                    "serialNumber": self.identity,
                },
            }
        return await self.call("BootNotification", payload, unique_id=unique_id)

    async def heartbeat(self, *, unique_id: str | None = None) -> JsonObject:
        return await self.call("Heartbeat", {}, unique_id=unique_id)

    async def change_status(self, status: str, *, timestamp: str | None = None) -> JsonObject:
        occurred_at = timestamp or utc_iso()
        if self.protocol == "ocpp1.6":
            payload: JsonObject = {
                "connectorId": 1,
                "errorCode": "NoError" if status != "Faulted" else "OtherError",
                "status": status,
                "timestamp": occurred_at,
            }
        else:
            connector_status = "Occupied" if status == "Charging" else status
            payload = {
                "timestamp": occurred_at,
                "connectorStatus": connector_status,
                "evseId": 1,
                "connectorId": 1,
            }
        return await self.call("StatusNotification", payload)

    async def start_session(self, token: str = "SIMULATOR-TOKEN") -> JsonObject:
        if self.protocol == "ocpp1.6":
            await self.call("Authorize", {"idTag": token})
            response = await self.call(
                "StartTransaction",
                {
                    "connectorId": 1,
                    "idTag": token,
                    "meterStart": 0,
                    "timestamp": utc_iso(),
                },
            )
            transaction_id = response.get("transactionId")
            if isinstance(transaction_id, int):
                self.protocol_transaction_id = transaction_id
            return response

        await self.call("Authorize", {"idToken": {"idToken": token, "type": "Central"}})
        self.sequence_number = 0
        return await self.call(
            "TransactionEvent",
            self._transaction_event("Started", "Authorized", token=token),
        )

    async def send_meter_value(self, energy_wh: int, power_w: int = 7_200) -> JsonObject:
        if self.protocol == "ocpp1.6":
            sampled_value: list[JsonObject] = [
                {
                    "value": str(energy_wh),
                    "measurand": "Energy.Active.Import.Register",
                    "unit": "Wh",
                },
                {
                    "value": str(power_w),
                    "measurand": "Power.Active.Import",
                    "unit": "W",
                },
            ]
        else:
            sampled_value = [
                {
                    "value": energy_wh,
                    "measurand": "Energy.Active.Import.Register",
                    "unitOfMeasure": {"unit": "Wh"},
                },
                {
                    "value": power_w,
                    "measurand": "Power.Active.Import",
                    "unitOfMeasure": {"unit": "W"},
                },
            ]
        meter_value = [{"timestamp": utc_iso(), "sampledValue": sampled_value}]
        if self.protocol == "ocpp1.6":
            payload: JsonObject = {"connectorId": 1, "meterValue": meter_value}
            if self.protocol_transaction_id is not None:
                payload["transactionId"] = self.protocol_transaction_id
        else:
            payload = {"evseId": 1, "meterValue": meter_value}
        return await self.call("MeterValues", payload)

    async def stop_session(self, energy_wh: int = 1_000) -> JsonObject:
        if self.protocol == "ocpp1.6":
            if self.protocol_transaction_id is None:
                raise RuntimeError("No OCPP 1.6 transaction has been started")
            return await self.call(
                "StopTransaction",
                {
                    "meterStop": energy_wh,
                    "timestamp": utc_iso(),
                    "transactionId": self.protocol_transaction_id,
                    "reason": "Local",
                },
            )
        return await self.call(
            "TransactionEvent",
            self._transaction_event("Ended", "StopAuthorized"),
        )

    async def send_data_transfer(self, vendor_id: str, message_id: str, data: str) -> JsonObject:
        return await self.call(
            "DataTransfer", {"vendorId": vendor_id, "messageId": message_id, "data": data}
        )

    async def send_diagnostics_status(self, status: str = "Uploaded") -> JsonObject:
        action = (
            "DiagnosticsStatusNotification"
            if self.protocol == "ocpp1.6"
            else "LogStatusNotification"
        )
        payload: JsonObject = {"status": status}
        if self.protocol == "ocpp2.0.1":
            payload["requestId"] = 1
        return await self.call(action, payload)

    async def send_firmware_status(self, status: str = "Installed") -> JsonObject:
        payload: JsonObject = {"status": status}
        if self.protocol == "ocpp2.0.1":
            payload["requestId"] = 1
        return await self.call("FirmwareStatusNotification", payload)

    async def send_security_event(self) -> JsonObject:
        return await self.call(
            "SecurityEventNotification",
            {
                "type": "SimulatorSecurityEvent",
                "timestamp": utc_iso(),
                "techInfo": "synthetic test event",
            },
        )

    async def simulate_fault(self) -> JsonObject:
        return await self.change_status("Faulted")

    async def replay_duplicate(
        self, action: str, payload: JsonObject
    ) -> tuple[JsonObject, JsonObject]:
        unique_id = new_ulid()
        first = await self.call(action, payload, unique_id=unique_id)
        second = await self.call(action, payload, unique_id=unique_id)
        return first, second

    async def reboot_during_charging(self, energy_wh: int = 500) -> None:
        await self.start_session()
        await self.send_meter_value(energy_wh)
        await self.disconnect_unexpectedly()
        await self.connect()
        await self.boot("PowerUp")
        await self.change_status("Charging")
        if self.protocol == "ocpp2.0.1":
            await self.call(
                "TransactionEvent",
                self._transaction_event("Updated", "ChargingStateChanged"),
            )
        await self.send_meter_value(energy_wh + 250)

    async def call(
        self, action: str, payload: JsonObject, *, unique_id: str | None = None
    ) -> JsonObject:
        connection = self._require_connection()
        message_id = unique_id or new_ulid()
        if message_id in self.pending:
            raise RuntimeError("A simulator call with this identifier is already pending")
        future: asyncio.Future[JsonObject] = asyncio.get_running_loop().create_future()
        self.pending[message_id] = future
        await connection.send(json.dumps([2, message_id, action, payload], separators=(",", ":")))
        try:
            async with asyncio.timeout(self.timeout_seconds):
                return await future
        finally:
            self.pending.pop(message_id, None)

    async def heartbeat_loop(self, interval_seconds: float, stop: asyncio.Event) -> None:
        while not stop.is_set():
            await self.heartbeat()
            try:
                async with asyncio.timeout(interval_seconds):
                    await stop.wait()
            except TimeoutError:
                continue

    def _transaction_event(
        self, event_type: str, trigger_reason: str, *, token: str | None = None
    ) -> JsonObject:
        payload: JsonObject = {
            "eventType": event_type,
            "timestamp": utc_iso(),
            "triggerReason": trigger_reason,
            "seqNo": self.sequence_number,
            "transactionInfo": {"transactionId": self.transaction_id},
            "evse": {"id": 1, "connectorId": 1},
        }
        self.sequence_number += 1
        if token is not None:
            payload["idToken"] = {"idToken": token, "type": "Central"}
        return payload

    async def _read_messages(self) -> None:
        connection = self._require_connection()
        try:
            async for raw in connection:
                decoded = json.loads(raw)
                if not isinstance(decoded, list) or len(decoded) < 3:
                    continue
                message_type = decoded[0]
                unique_id = str(decoded[1])
                if message_type == 2 and len(decoded) == 4:
                    await self._respond_to_command(connection, unique_id, str(decoded[2]))
                    continue
                pending = self.pending.get(unique_id)
                if pending is None or pending.done():
                    continue
                if message_type == 3:
                    payload = decoded[2] if isinstance(decoded[2], dict) else {}
                    pending.set_result(payload)
                elif message_type == 4:
                    pending.set_exception(RuntimeError(f"OCPP error {decoded[2]}: {decoded[3]}"))
        except ConnectionClosed as error:
            for future in self.pending.values():
                if not future.done():
                    future.set_exception(error)

    async def _respond_to_command(
        self, connection: ClientConnection, unique_id: str, action: str
    ) -> None:
        response = self._command_response(action)
        if response is None:
            frame: list[Any] = [
                4,
                unique_id,
                "NotSupported",
                "Simulator does not support this command",
                {},
            ]
        else:
            frame = [3, unique_id, response]
        await connection.send(json.dumps(frame, separators=(",", ":")))

    def _command_response(self, action: str) -> JsonObject | None:
        if self.protocol == "ocpp1.6":
            responses: dict[str, JsonObject] = {
                "TriggerMessage": {"status": "Accepted"},
                "Reset": {"status": "Accepted"},
                "RemoteStartTransaction": {"status": "Accepted"},
                "RemoteStopTransaction": {"status": "Accepted"},
                "UnlockConnector": {"status": "Unlocked"},
                "ChangeAvailability": {"status": "Accepted"},
                "GetDiagnostics": {"fileName": "simulator-diagnostics.txt"},
                "UpdateFirmware": {},
            }
        else:
            responses = {
                "TriggerMessage": {"status": "Accepted"},
                "Reset": {"status": "Accepted"},
                "RequestStartTransaction": {
                    "status": "Accepted",
                    "transactionId": self.transaction_id,
                },
                "RequestStopTransaction": {"status": "Accepted"},
                "UnlockConnector": {"status": "Unlocked"},
                "ChangeAvailability": {"status": "Accepted"},
                "GetLog": {"status": "Accepted", "filename": "simulator-log.txt"},
                "UpdateFirmware": {"status": "Accepted"},
            }
        return responses.get(action)

    def _require_connection(self) -> ClientConnection:
        if self.connection is None:
            raise RuntimeError("Simulator is not connected")
        return self.connection

    async def _finish_reader(self) -> None:
        if self.reader_task is not None:
            with suppress(ConnectionClosed, asyncio.CancelledError):
                await self.reader_task
            self.reader_task = None

    def _ssl_context(self) -> ssl.SSLContext:
        context = ssl.create_default_context(cafile=str(self.ca_file) if self.ca_file else None)
        if self.certificate_file is not None:
            if self.private_key_file is None:
                raise ValueError("A private key is required with a client certificate")
            context.load_cert_chain(self.certificate_file, self.private_key_file)
        return context


async def run_scenario(arguments: argparse.Namespace) -> None:
    simulator = ChargerSimulator(
        arguments.url,
        arguments.identity,
        cast(Protocol, arguments.protocol),
        password=os.getenv("SIMULATOR_BASIC_PASSWORD"),
        ca_file=arguments.ca_file,
        certificate_file=arguments.certificate_file,
        private_key_file=arguments.private_key_file,
    )
    await simulator.connect()
    try:
        await simulator.boot()
        await simulator.change_status("Available")
        if arguments.scenario == "duplicate":
            await simulator.replay_duplicate("Heartbeat", {})
        elif arguments.scenario == "fault":
            await simulator.simulate_fault()
        elif arguments.scenario == "reboot-during-charging":
            await simulator.reboot_during_charging()
            await simulator.stop_session(1_000)
        else:
            await simulator.start_session()
            await simulator.change_status("Charging")
            await simulator.send_meter_value(500)
            await simulator.stop_session(1_000)
            await simulator.change_status("Available")
    finally:
        await simulator.close()


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="VTSA OCPP charger simulator")
    parser.add_argument("--url", default="ws://127.0.0.1:9002/ocpp")
    parser.add_argument("--identity", default="VTSA-SIM-001")
    parser.add_argument("--protocol", choices=("ocpp1.6", "ocpp2.0.1"), default="ocpp1.6")
    parser.add_argument(
        "--scenario",
        choices=("standard", "duplicate", "fault", "reboot-during-charging"),
        default="standard",
    )
    parser.add_argument("--ca-file", type=Path)
    parser.add_argument("--certificate-file", type=Path)
    parser.add_argument("--private-key-file", type=Path)
    return parser


def main() -> None:
    asyncio.run(run_scenario(build_parser().parse_args()))


if __name__ == "__main__":
    main()
