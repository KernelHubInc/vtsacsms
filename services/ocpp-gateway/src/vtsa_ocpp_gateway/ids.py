from __future__ import annotations

import re

from ulid import ULID

# Platform IDs may use lowercase; validate both without changing database key casing.
ULID_PATTERN = re.compile(r"^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$")


def new_ulid() -> str:
    return str(ULID())


def incoming_ulid(value: str | None) -> str:
    candidate = value.strip().upper() if value else ""
    return candidate if ULID_PATTERN.fullmatch(candidate) else new_ulid()
