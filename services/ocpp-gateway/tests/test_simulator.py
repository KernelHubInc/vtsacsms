from __future__ import annotations

from vtsa_ocpp_gateway.simulator import ChargerSimulator


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
