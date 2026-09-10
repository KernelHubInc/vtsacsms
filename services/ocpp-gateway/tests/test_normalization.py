from __future__ import annotations

from vtsa_ocpp_gateway.normalization import (
    normalize_v16_meter_values,
    normalize_v201_meter_values,
)


def test_v16_normalizes_energy_and_power_to_base_units() -> None:
    values = [
        {
            "timestamp": "2026-07-22T01:00:00Z",
            "sampled_value": [
                {"value": "1.25", "unit": "kWh"},
                {"value": "7.2", "unit": "kW", "measurand": "Power.Active.Import"},
            ],
        }
    ]

    samples = normalize_v16_meter_values(values)

    assert samples[0]["value"] == 1250
    assert samples[0]["unit"] == "Wh"
    assert samples[1]["value"] == 7200
    assert samples[1]["unit"] == "W"


def test_v201_applies_unit_multiplier_before_normalization() -> None:
    values = [
        {
            "timestamp": "2026-07-22T01:00:00Z",
            "sampled_value": [
                {
                    "value": 72,
                    "measurand": "Power.Active.Import",
                    "unit_of_measure": {"unit": "W", "multiplier": 2},
                }
            ],
        }
    ]

    samples = normalize_v201_meter_values(values)

    assert samples[0]["value"] == 7200
    assert samples[0]["unit"] == "W"


def test_invalid_meter_value_is_retained_as_text_for_downstream_rejection() -> None:
    samples = normalize_v16_meter_values(
        [{"sampled_value": [{"value": "not-a-number", "unit": "Wh"}]}]
    )

    assert samples[0]["value"] == "not-a-number"
