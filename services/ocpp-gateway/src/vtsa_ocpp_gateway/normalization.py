from __future__ import annotations

from decimal import Decimal, InvalidOperation
from typing import Any

from vtsa_ocpp_gateway.models import JsonObject


def normalize_v16_meter_values(values: list[Any]) -> list[JsonObject]:
    samples: list[JsonObject] = []
    for meter_value in values:
        if not isinstance(meter_value, dict):
            continue
        timestamp = meter_value.get("timestamp")
        for sampled in meter_value.get("sampled_value", []):
            if not isinstance(sampled, dict):
                continue
            samples.append(
                _normalize_sample(
                    value=sampled.get("value"),
                    unit=sampled.get("unit") or "Wh",
                    measurand=sampled.get("measurand") or "Energy.Active.Import.Register",
                    timestamp=timestamp,
                    context=sampled.get("context"),
                    phase=sampled.get("phase"),
                )
            )
    return samples


def normalize_v201_meter_values(values: list[Any]) -> list[JsonObject]:
    samples: list[JsonObject] = []
    for meter_value in values:
        if not isinstance(meter_value, dict):
            continue
        timestamp = meter_value.get("timestamp")
        for sampled in meter_value.get("sampled_value", []):
            if not isinstance(sampled, dict):
                continue
            unit_measure = sampled.get("unit_of_measure") or {}
            unit = unit_measure.get("unit") or "Wh"
            multiplier = unit_measure.get("multiplier") or 0
            value = sampled.get("value")
            try:
                scaled_value: Any = Decimal(str(value)) * (Decimal(10) ** int(multiplier))
            except (InvalidOperation, TypeError, ValueError):
                scaled_value = value
            samples.append(
                _normalize_sample(
                    value=scaled_value,
                    unit=unit,
                    measurand=sampled.get("measurand") or "Energy.Active.Import.Register",
                    timestamp=timestamp,
                    context=sampled.get("context"),
                    phase=sampled.get("phase"),
                )
            )
    return samples


def _normalize_sample(
    *, value: Any, unit: str, measurand: str, timestamp: Any, context: Any, phase: Any
) -> JsonObject:
    normalized_unit = unit
    normalized_value: Any = value
    try:
        numeric = Decimal(str(value))
        if unit == "kWh":
            normalized_value = _integer_if_exact(numeric * 1000)
            normalized_unit = "Wh"
        elif unit == "Wh":
            normalized_value = _integer_if_exact(numeric)
        elif unit == "kW":
            normalized_value = _integer_if_exact(numeric * 1000)
            normalized_unit = "W"
        elif unit == "W":
            normalized_value = _integer_if_exact(numeric)
        else:
            normalized_value = float(numeric)
    except (InvalidOperation, TypeError, ValueError):
        normalized_value = str(value)

    return {
        "timestamp": timestamp,
        "measurand": measurand,
        "value": normalized_value,
        "unit": normalized_unit,
        "context": context,
        "phase": phase,
    }


def _integer_if_exact(value: Decimal) -> int | float:
    return int(value) if value == value.to_integral_value() else float(value)
