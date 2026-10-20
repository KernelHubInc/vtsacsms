import importlib
import re
import subprocess
from collections.abc import Mapping
from dataclasses import dataclass
from datetime import date
from io import BytesIO
from typing import Literal, Protocol

from PIL import Image, ImageOps

from app.config import settings
from app.schemas import Status

Check = Literal["passed", "failed", "unavailable", "mock"]


class OCRProvider(Protocol):
    def extract(self, image: bytes) -> dict[str, str | None]: ...


class LivenessProvider(Protocol):
    def verify(self, selfie: bytes) -> Check: ...


class FaceMatchProvider(Protocol):
    def compare(self, document: bytes, selfie: bytes) -> Check: ...


class DocumentVerificationProvider(Protocol):
    def verify(self, images: dict[str, bytes], personal: dict) -> Check: ...


class DocumentDataProvider(Protocol):
    def verify(self, extracted: Mapping[str, str | None], personal: dict) -> Check: ...


class TesseractOCR:
    def text(self, image: bytes) -> str:
        with Image.open(BytesIO(image)) as source:
            preprocessed = ImageOps.autocontrast(source.convert("L"))
            output = BytesIO()
            preprocessed.save(output, "PNG")
        result = subprocess.run(  # noqa: S603 - fixed executable/arguments; bytes only on stdin
            ["tesseract", "stdin", "stdout", "-l", "eng", "--psm", "6"],  # noqa: S607
            input=output.getvalue(),
            capture_output=True,
            timeout=45,
            check=True,
        )
        return result.stdout.decode("utf-8", errors="replace")[:20000]

    def extract(self, image: bytes) -> dict[str, str | None]:
        text = self.text(image)
        fields: dict[str, str | None] = {}
        labels = {
            "full_name": r"(?:FULL NAME|NAME)",
            "first_name": r"FIRST NAME",
            "middle_name": r"MIDDLE NAME",
            "last_name": r"(?:LAST NAME|SURNAME)",
            "birth_date": r"(?:DATE OF BIRTH|DOB)",
            "document_number": r"(?:DOCUMENT NUMBER|ID NUMBER|PASSPORT NO)",
            "expiration_date": r"(?:EXPIRATION DATE|EXPIRY|VALID UNTIL)",
            "nationality": r"NATIONALITY",
            "issuing_country": r"ISSUING COUNTRY",
        }
        for field, label in labels.items():
            match = re.search(rf"^\s*{label}\s*[:#]\s*([^\r\n]+)", text, re.I | re.M)
            fields[field] = match.group(1).strip()[:180] if match else None
        return fields


class UnavailableAssurance:
    def verify(self, *args) -> Check:
        return "unavailable"

    def compare(self, *args) -> Check:
        return "unavailable"


@dataclass(frozen=True)
class Providers:
    ocr: OCRProvider
    liveness: LivenessProvider
    face_match: FaceMatchProvider
    document: DocumentVerificationProvider
    name: str = "local-tesseract"
    document_data: DocumentDataProvider | None = None


def providers() -> Providers:
    config = settings()
    if config.mode == "self_hosted":
        from app.self_hosted import self_hosted_providers

        return self_hosted_providers()
    if config.mode == "provider":
        module, attribute = config.provider_factory.split(":", 1)
        adapter = getattr(importlib.import_module(module), attribute)()
        if not isinstance(adapter, Providers) or adapter.name in {"mock", "local-tesseract"}:
            raise ValueError("Invalid production provider bundle")
        return adapter
    unavailable = UnavailableAssurance()
    return Providers(TesseractOCR(), unavailable, unavailable, unavailable)


def decide(
    checks: dict[str, Check], personal: dict, profile: str = "issuer_v1"
) -> tuple[Status, str]:
    expiry = personal.get("expiration_date")
    if expiry and date.fromisoformat(expiry) < date.today():
        return Status.ACTION_REQUIRED, "DOCUMENT_EXPIRED"
    if "failed" in checks.values():
        return Status.NEEDS_REVIEW, "ASSURANCE_CHECK_FAILED"
    required = {"ocr", "document", "face_match", "liveness"}
    if profile == "optical_v1":
        required = {"ocr", "optical_document", "document_data", "face_match", "liveness"}
    elif profile != "issuer_v1":
        return Status.NEEDS_REVIEW, "UNKNOWN_ASSURANCE_PROFILE"
    if (
        settings().mode in {"provider", "self_hosted"}
        and required.issubset(checks)
        and all(checks[key] == "passed" for key in required)
    ):
        return (
            Status.APPROVED,
            "OPTICAL_VERIFIED" if profile == "optical_v1" else "PROVIDER_VERIFIED",
        )
    return Status.NEEDS_REVIEW, "MANUAL_REVIEW_REQUIRED"
