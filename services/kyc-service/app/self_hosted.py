"""Local one-to-one face comparison. Optical consistency is not issuer authentication."""

import hashlib
import math
import re
import unicodedata
from collections.abc import Mapping
from pathlib import Path

import cv2
import numpy as np

from app.config import settings
from app.providers import Check, Providers, TesseractOCR, UnavailableAssurance


def checked_model(filename: str, expected: str) -> str:
    path = Path(filename)
    if not re.fullmatch(r"[a-f0-9]{64}", expected) or not path.is_file():
        raise ValueError("A pinned model is missing")
    with path.open("rb") as stream:
        actual = hashlib.file_digest(stream, "sha256").hexdigest()
    if actual != expected:
        raise ValueError("Model integrity check failed")
    return str(path)


def face_decision(score: float, accept: float, reject: float) -> Check:
    if not math.isfinite(score) or not -1 <= score <= 1:
        return "unavailable"
    if score >= accept:
        return "passed"
    return "failed" if score <= reject else "unavailable"


class OpenCvFaceMatch:
    def __init__(self):
        config = settings()
        cv2.setNumThreads(1)
        self.detector = cv2.FaceDetectorYN.create(
            checked_model(config.face_detector_model, config.face_detector_sha256),
            "",
            (320, 320),
            0.9,
            0.3,
            5000,
        )
        self.recognizer = cv2.FaceRecognizerSF.create(
            checked_model(config.face_recognizer_model, config.face_recognizer_sha256), ""
        )

    def feature(self, content: bytes):
        image = cv2.imdecode(np.frombuffer(content, dtype=np.uint8), cv2.IMREAD_COLOR)
        if image is None:
            return None
        height, width = image.shape[:2]
        scale = min(1.0, 1600 / max(height, width))
        if scale < 1:
            image = cv2.resize(image, (round(width * scale), round(height * scale)))
        self.detector.setInputSize((image.shape[1], image.shape[0]))
        _, faces = self.detector.detect(image)
        # Multiple portraits (including ghost images) need human selection, not a guessed face.
        if faces is None or len(faces) != 1 or min(faces[0][2:4]) < 80:
            image.fill(0)
            return None
        aligned = self.recognizer.alignCrop(image, faces[0])
        try:
            return self.recognizer.feature(aligned).copy()
        finally:
            aligned.fill(0)
            image.fill(0)

    def compare(self, document: bytes, selfie: bytes) -> Check:
        features = []
        try:
            for content in (document, selfie):
                feature = self.feature(content)
                if feature is None:
                    return "unavailable"
                features.append(feature)
            score = float(
                self.recognizer.match(features[0], features[1], cv2.FaceRecognizerSF_FR_COSINE)
            )
            config = settings()
            if config.face_accept_threshold is None or config.face_reject_threshold is None:
                return "unavailable"
            return face_decision(score, config.face_accept_threshold, config.face_reject_threshold)
        finally:
            for feature in features:
                feature.fill(0)


def normalized(value: str) -> str:
    return re.sub(r"[^A-Z0-9]", "", unicodedata.normalize("NFKD", value).upper())


class DocumentDataCheck:
    def verify(self, extracted: Mapping[str, str | None], personal: dict) -> Check:
        required = {key: value for key, value in personal.items() if value}
        if not required or any(not extracted.get(key) for key in required):
            return "unavailable"
        if any(
            not normalized(str(value)) or not normalized(str(extracted[key]))
            for key, value in required.items()
        ):
            return "unavailable"
        return (
            "passed"
            if all(
                normalized(str(value)) == normalized(str(extracted[key]))
                for key, value in required.items()
            )
            else "failed"
        )


def self_hosted_providers() -> Providers:
    if settings().liveness_model:
        from app.live_capture import analyzer

        analyzer()
    unavailable = UnavailableAssurance()
    # A still selfie is not liveness; OCR matching does not authenticate an issued document.
    return Providers(
        TesseractOCR(),
        unavailable,
        OpenCvFaceMatch(),
        unavailable,
        name="self-hosted-opencv",
        document_data=DocumentDataCheck(),
    )
