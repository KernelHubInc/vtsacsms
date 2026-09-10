from __future__ import annotations

import base64
import hashlib
import hmac
import json
import re
from dataclasses import dataclass
from typing import Any

from argon2 import PasswordHasher
from argon2.exceptions import InvalidHashError, VerifyMismatchError
from fastapi import WebSocket

from vtsa_ocpp_gateway.ids import ULID_PATTERN
from vtsa_ocpp_gateway.models import ChargerIdentity

CHARGE_POINT_ID_PATTERN = re.compile(r"^[A-Za-z0-9._:-]{1,120}$")


class ChargerIdentityError(ValueError):
    pass


@dataclass(frozen=True, slots=True)
class ChargerRegistration:
    tenant_id: str
    charger_id: str
    enabled: bool
    basic_password_hash: str | None = None
    certificate_fingerprint_sha256: str | None = None


class ChargerIdentityValidator:
    def __init__(
        self,
        registry_json: str,
        allow_development: bool,
        trusted_certificate_fingerprint_header: str | None = None,
    ) -> None:
        self._registry = self._parse_registry(registry_json)
        self._allow_development = allow_development
        self._trusted_certificate_fingerprint_header = trusted_certificate_fingerprint_header
        self._password_hasher = PasswordHasher()

    async def validate(self, websocket: WebSocket, charge_point_identity: str) -> ChargerIdentity:
        if not CHARGE_POINT_ID_PATTERN.fullmatch(charge_point_identity):
            raise ChargerIdentityError("Invalid charger identity format")

        registration = self._registry.get(charge_point_identity)
        if registration is None:
            if self._allow_development:
                return ChargerIdentity(charge_point_identity, None, None, "development")
            raise ChargerIdentityError("Charger is not enrolled")
        if not registration.enabled:
            raise ChargerIdentityError("Charger is disabled")

        fingerprint = self._certificate_fingerprint(websocket)
        if registration.certificate_fingerprint_sha256 and fingerprint:
            if hmac.compare_digest(
                registration.certificate_fingerprint_sha256.casefold(), fingerprint.casefold()
            ):
                return ChargerIdentity(
                    charge_point_identity, registration.tenant_id, registration.charger_id, "mtls"
                )
            raise ChargerIdentityError("Client certificate does not match charger enrollment")

        credentials = self._basic_credentials(websocket)
        if registration.basic_password_hash and credentials is not None:
            username, password = credentials
            if not hmac.compare_digest(username, charge_point_identity):
                raise ChargerIdentityError("Basic credential identity does not match path")
            try:
                self._password_hasher.verify(registration.basic_password_hash, password)
            except (InvalidHashError, VerifyMismatchError):
                pass
            else:
                return ChargerIdentity(
                    charge_point_identity, registration.tenant_id, registration.charger_id, "basic"
                )

        raise ChargerIdentityError("Charger authentication failed")

    @staticmethod
    def _parse_registry(raw: str) -> dict[str, ChargerRegistration]:
        try:
            decoded: Any = json.loads(raw)
        except json.JSONDecodeError as error:
            raise ValueError("OCPP_CHARGER_REGISTRY_JSON must be valid JSON") from error
        if not isinstance(decoded, dict):
            raise ValueError("OCPP charger registry must be a JSON object")

        registrations: dict[str, ChargerRegistration] = {}
        for identity, entry in decoded.items():
            if not isinstance(identity, str) or not isinstance(entry, dict):
                raise ValueError("Each charger registration must be an object keyed by identity")
            tenant_id = entry.get("tenant_id")
            charger_id = entry.get("charger_id")
            if not isinstance(tenant_id, str) or not isinstance(charger_id, str):
                raise ValueError("Registered chargers require tenant_id and charger_id")
            if not ULID_PATTERN.fullmatch(tenant_id) or not ULID_PATTERN.fullmatch(charger_id):
                raise ValueError("Registered charger tenant_id and charger_id must be ULIDs")
            password_hash = entry.get("basic_password_hash")
            fingerprint = entry.get("certificate_fingerprint_sha256")
            if password_hash is not None and not isinstance(password_hash, str):
                raise ValueError("Registered charger basic_password_hash must be a string")
            if fingerprint is not None and (
                not isinstance(fingerprint, str)
                or not re.fullmatch(r"[0-9A-Fa-f]{64}", fingerprint)
            ):
                raise ValueError(
                    "Registered charger certificate fingerprint must be a SHA-256 hex value"
                )
            registrations[identity] = ChargerRegistration(
                tenant_id=tenant_id,
                charger_id=charger_id,
                enabled=entry.get("enabled") is True,
                basic_password_hash=password_hash,
                certificate_fingerprint_sha256=fingerprint.casefold() if fingerprint else None,
            )
        return registrations

    @staticmethod
    def _basic_credentials(websocket: WebSocket) -> tuple[str, str] | None:
        authorization = websocket.headers.get("authorization", "")
        if not authorization.startswith("Basic "):
            return None
        try:
            decoded = base64.b64decode(authorization[6:], validate=True).decode("utf-8")
            username, password = decoded.split(":", 1)
        except (ValueError, UnicodeDecodeError):
            return None
        return username, password

    def _certificate_fingerprint(self, websocket: WebSocket) -> str | None:
        ssl_object = websocket.scope.get("ssl_object")
        if ssl_object is not None and hasattr(ssl_object, "getpeercert"):
            certificate = ssl_object.getpeercert(binary_form=True)
            if certificate:
                return hashlib.sha256(certificate).hexdigest()
        if self._trusted_certificate_fingerprint_header is None:
            return None
        fingerprint = websocket.headers.get(
            self._trusted_certificate_fingerprint_header, ""
        ).strip()
        if not fingerprint:
            return None
        if not re.fullmatch(r"[0-9A-Fa-f]{64}", fingerprint):
            raise ChargerIdentityError("Client certificate fingerprint header is invalid")
        return fingerprint.casefold()
