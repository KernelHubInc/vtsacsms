from __future__ import annotations

import re

from ulid import ULID

ULID_PATTERN = re.compile(r"^[0-9A-HJKMNP-TV-Z]{26}$")


def new_ulid() -> str:
    return str(ULID())


def incoming_ulid(value: str | None) -> str:
    candidate = value.strip().upper() if value else ""
    return candidate if ULID_PATTERN.fullmatch(candidate) else new_ulid()
