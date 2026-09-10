from __future__ import annotations

import hashlib
import json
from typing import cast

import pytest
from starlette.websockets import WebSocket

from vtsa_ocpp_gateway.security import ChargerIdentityError, ChargerIdentityValidator


class FakeCertificate:
    def __init__(self, certificate: bytes) -> None:
        self.certificate = certificate

    def getpeercert(self, *, binary_form: bool = False) -> bytes | None:
        return self.certificate if binary_form else None


class FakeWebSocket:
    def __init__(
        self, certificate: bytes | None = None, headers: dict[str, str] | None = None
    ) -> None:
        self.headers = headers or {}
        self.scope: dict[str, object] = {}
        if certificate is not None:
            self.scope["ssl_object"] = FakeCertificate(certificate)


@pytest.mark.asyncio
async def test_matching_enrolled_certificate_binds_charger_to_tenant() -> None:
    certificate = b"synthetic-certificate-der"
    registry = json.dumps(
        {
            "CP-MTLS": {
                "tenant_id": "01K0M0AA1A0M0AA1A0M0AA1A0M",
                "charger_id": "01K0M0BB2B0M0BB2B0M0BB2B0M",
                "enabled": True,
                "certificate_fingerprint_sha256": hashlib.sha256(certificate).hexdigest(),
            }
        }
    )
    validator = ChargerIdentityValidator(registry, allow_development=False)

    identity = await validator.validate(cast(WebSocket, FakeWebSocket(certificate)), "CP-MTLS")

    assert identity.authentication == "mtls"
    assert identity.tenant_id == "01K0M0AA1A0M0AA1A0M0AA1A0M"
    assert identity.charger_id == "01K0M0BB2B0M0BB2B0M0BB2B0M"


@pytest.mark.asyncio
async def test_certificate_mismatch_fails_closed_without_basic_fallback() -> None:
    registry = json.dumps(
        {
            "CP-MTLS": {
                "tenant_id": "01K0M0AA1A0M0AA1A0M0AA1A0M",
                "charger_id": "01K0M0BB2B0M0BB2B0M0BB2B0M",
                "enabled": True,
                "certificate_fingerprint_sha256": hashlib.sha256(b"expected").hexdigest(),
            }
        }
    )
    validator = ChargerIdentityValidator(registry, allow_development=False)

    with pytest.raises(ChargerIdentityError, match="does not match"):
        await validator.validate(cast(WebSocket, FakeWebSocket(b"wrong")), "CP-MTLS")


@pytest.mark.asyncio
async def test_unknown_charger_cannot_use_development_mode_by_default() -> None:
    validator = ChargerIdentityValidator("{}", allow_development=False)

    with pytest.raises(ChargerIdentityError, match="not enrolled"):
        await validator.validate(cast(WebSocket, FakeWebSocket()), "UNKNOWN")


def test_registry_rejects_non_ulid_tenant_or_charger_identifiers() -> None:
    registry = json.dumps(
        {
            "CP-BAD": {
                "tenant_id": "not-a-ulid",
                "charger_id": "also-not-a-ulid",
                "enabled": True,
            }
        }
    )

    with pytest.raises(ValueError, match="must be ULIDs"):
        ChargerIdentityValidator(registry, allow_development=False)


@pytest.mark.asyncio
async def test_explicit_trusted_edge_fingerprint_header_can_bind_charger() -> None:
    fingerprint = hashlib.sha256(b"edge-terminated-certificate").hexdigest()
    registry = json.dumps(
        {
            "CP-EDGE": {
                "tenant_id": "01K0M0AA1A0M0AA1A0M0AA1A0M",
                "charger_id": "01K0M0BB2B0M0BB2B0M0BB2B0M",
                "enabled": True,
                "certificate_fingerprint_sha256": fingerprint,
            }
        }
    )
    validator = ChargerIdentityValidator(
        registry,
        allow_development=False,
        trusted_certificate_fingerprint_header="x-client-cert-sha256",
    )

    identity = await validator.validate(
        cast(
            WebSocket,
            FakeWebSocket(headers={"x-client-cert-sha256": fingerprint.upper()}),
        ),
        "CP-EDGE",
    )

    assert identity.authentication == "mtls"
