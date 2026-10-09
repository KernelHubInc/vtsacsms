from __future__ import annotations

from unittest.mock import AsyncMock

import pytest

from vtsa_ocpp_gateway.simulator import ChargerSimulator, build_parser, run_scenario


def test_simulator_builds_version_specific_command_responses() -> None:
    v16 = ChargerSimulator("ws://localhost/ocpp", "SIM-16", "ocpp1.6")
    v201 = ChargerSimulator("ws://localhost/ocpp", "SIM-201", "ocpp2.0.1")

    assert v16._command_response("RemoteStartTransaction") == {"status": "Accepted"}
    assert v201._command_response("RequestStartTransaction") == {
        "status": "Accepted",
        "transactionId": v201.transaction_id,
    }
    assert v201._command_response("Unsupported") is None


def test_simulator_transaction_sequence_is_monotonic() -> None:
    simulator = ChargerSimulator("ws://localhost/ocpp", "SIM-201", "ocpp2.0.1")

    started = simulator._transaction_event("Started", "Authorized", token="token")
    updated = simulator._transaction_event("Updated", "MeterValuePeriodic")

    assert started["seqNo"] == 0
    assert updated["seqNo"] == 1
    assert (
        started["transactionInfo"]["transactionId"] == updated["transactionInfo"]["transactionId"]
    )


@pytest.mark.parametrize("boot_status", ["Pending", "Rejected"])
async def test_connectivity_stops_before_heartbeat_if_boot_is_not_accepted(
    monkeypatch: pytest.MonkeyPatch, boot_status: str
) -> None:
    simulator = AsyncMock(spec=ChargerSimulator)
    simulator.boot.return_value = {"status": boot_status, "interval": 30}
    monkeypatch.setattr("vtsa_ocpp_gateway.simulator.ChargerSimulator", lambda *a, **kw: simulator)
    monkeypatch.setenv("SIMULATOR_BASIC_PASSWORD", "synthetic-test-password")
    arguments = build_parser().parse_args(["--scenario", "connectivity", "--heartbeats", "1"])

    with pytest.raises(RuntimeError, match="not Accepted"):
        await run_scenario(arguments)

    simulator.heartbeat.assert_not_awaited()
    simulator.start_session.assert_not_awaited()
    simulator.close.assert_awaited_once()


async def test_connectivity_supports_url_only_chargers(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("SIMULATOR_BASIC_PASSWORD", raising=False)
    simulator = AsyncMock(spec=ChargerSimulator)
    simulator.boot.return_value = {"status": "Accepted", "interval": 30}
    simulator.heartbeat.return_value = {"currentTime": "2026-10-10T00:00:00Z"}

    def create(*args: object, **kwargs: object) -> AsyncMock:
        assert kwargs["password"] is None
        return simulator

    monkeypatch.setattr("vtsa_ocpp_gateway.simulator.ChargerSimulator", create)
    arguments = build_parser().parse_args(["--scenario", "connectivity", "--heartbeats", "1"])
    await run_scenario(arguments)
    simulator.heartbeat.assert_awaited_once()
    simulator.start_session.assert_not_awaited()
    simulator.close.assert_awaited_once()
