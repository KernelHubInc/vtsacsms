from __future__ import annotations

from vtsa_ocpp_gateway.ids import ULID_PATTERN, incoming_ulid


def test_incoming_ulid_preserves_a_valid_identifier() -> None:
    identifier = "01K0M0JJ5X0M0JJ5X0M0JJ5X0M"

    assert incoming_ulid(identifier.lower()) == identifier


def test_incoming_ulid_replaces_invalid_input() -> None:
    identifier = incoming_ulid("not-a-valid-id")

    assert ULID_PATTERN.fullmatch(identifier)
