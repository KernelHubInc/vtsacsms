from __future__ import annotations

import pytest

from vtsa_ocpp_gateway.ids import ULID_PATTERN, incoming_ulid


def test_incoming_ulid_preserves_a_valid_identifier() -> None:
    identifier = "01K0M0JJ5X0M0JJ5X0M0JJ5X0M"

    assert incoming_ulid(identifier.lower()) == identifier


def test_incoming_ulid_replaces_invalid_input() -> None:
    identifier = incoming_ulid("not-a-valid-id")

    assert ULID_PATTERN.fullmatch(identifier)


@pytest.mark.parametrize("value", ["01J00000000000000000000002", "01j00000000000000000000002"])
def test_ulid_accepts_platform_identifier_casing(value: str) -> None:
    assert ULID_PATTERN.fullmatch(value)


@pytest.mark.parametrize("character", ["i", "l", "o", "u", "I", "L", "O", "U", "\u017f", "/", " "])
def test_ulid_rejects_non_crockford_characters(character: str) -> None:
    assert ULID_PATTERN.fullmatch("01" + character + "0" * 23) is None
