"""Server-timed, single-use camera challenges with local presentation-attack checks."""

import hashlib
import logging
import math
import secrets
import threading
from datetime import UTC, datetime, timedelta
from functools import lru_cache

import cv2
import numpy as np

from app.config import settings
from app.database import session_factory, utcnow
from app.images import normalize
from app.schemas import LiveChallenge, LiveFrame, Scope, Status, Upload
from app.security import KycError
from app.self_hosted import OpenCvFaceMatch, checked_model
from app.service import scoped
from app.storage import seal, storage, unseal


class LiveAnalyzer:
    def __init__(self):
        config = settings()
        if config.mode != "self_hosted" or not all(
            (
                config.liveness_model,
                config.liveness_sha256,
                config.liveness_accept_threshold is not None,
                config.liveness_reject_threshold is not None,
                config.liveness_center_tolerance is not None,
                config.liveness_turn_threshold is not None,
            )
        ):
            raise KycError("LIVENESS_NOT_CONFIGURED", 503)
        self.face = OpenCvFaceMatch()
        self.pad = cv2.dnn.readNetFromONNX(
            checked_model(config.liveness_model, config.liveness_sha256)
        )
        self.lock = threading.Lock()

    def analyze(self, content: bytes, baseline: bytes | None) -> tuple[str, float | None]:
        with self.lock:
            return self._analyze(content, baseline)

    def _analyze(self, content: bytes, baseline: bytes | None) -> tuple[str, float | None]:
        config = settings()
        image = cv2.imdecode(np.frombuffer(content, dtype=np.uint8), cv2.IMREAD_COLOR)
        if image is None:
            return "unavailable", None
        try:
            self.face.detector.setInputSize((image.shape[1], image.shape[0]))
            _, faces = self.face.detector.detect(image)
            if faces is None or len(faces) != 1:
                return "unavailable", None
            face = faces[0]
            x, y, width, height = [int(value) for value in face[:4]]
            if (
                min(width, height) < 100
                or x < 0
                or y < 0
                or x + width > image.shape[1]
                or y + height > image.shape[0]
            ):
                return "unavailable", None
            # Original anti-spoof-mn3 ONNX expects RGB with per-channel mean/scale.
            crop = cv2.resize(image[y : y + height, x : x + width], (128, 128))
            rgb = cv2.cvtColor(crop, cv2.COLOR_BGR2RGB).astype(np.float32)
            rgb -= np.array([151.2405, 119.5950, 107.8395], dtype=np.float32)
            rgb /= np.array([63.0105, 56.4570, 55.0035], dtype=np.float32)
            tensor = np.transpose(rgb, (2, 0, 1))[None].copy()
            try:
                self.pad.setInput(tensor)
                probability = self.pad.forward().reshape(-1)
            finally:
                tensor.fill(0)
                rgb.fill(0)
                crop.fill(0)
            if (
                len(probability) != 2
                or not np.isfinite(probability).all()
                or np.any(probability < 0)
                or np.any(probability > 1)
                or abs(float(probability.sum()) - 1) > 0.01
            ):
                return "unavailable", None
            score = float(probability[0])
            assert config.liveness_reject_threshold is not None
            assert config.liveness_accept_threshold is not None
            if score <= config.liveness_reject_threshold:
                return "failed", None
            if score < config.liveness_accept_threshold:
                return "unavailable", None
            if baseline is not None and self.face.compare(baseline, content) != "passed":
                return "failed", None
            eyes = sorted((face[4:6], face[6:8]), key=lambda eye: eye[0])
            span = float(eyes[1][0] - eyes[0][0])
            if span < 35 or abs(float(eyes[1][1] - eyes[0][1])) / span > 0.2:
                return "unavailable", None
            ratio = float(face[8] - (eyes[0][0] + eyes[1][0]) / 2) / span
            return ("passed", ratio) if math.isfinite(ratio) else ("unavailable", None)
        finally:
            image.fill(0)


@lru_cache(maxsize=1)
def analyzer() -> LiveAnalyzer:
    return LiveAnalyzer()


def reply(state: dict, feedback: str = "follow_prompt") -> LiveChallenge:
    return LiveChallenge(
        token=state["token"],
        action="complete" if state["complete"] else state["action"],
        step=state["step"],
        expires_at=datetime.fromtimestamp(state["deadline"], UTC),
        complete=state["complete"],
        feedback="complete" if state["complete"] else feedback,
    )


def pending(row) -> None:
    if (
        row.status != Status.PENDING_UPLOAD
        or row.evidence_deleted
        or row.delete_after.replace(tzinfo=UTC) <= utcnow()
    ):
        raise KycError("INVALID_STATE_TRANSITION", 409)
    if row.assurance_profile != "optical_v1":
        raise KycError("LIVE_CAPTURE_NOT_SUPPORTED", 409)


def start(id: str, scope: Scope) -> LiveChallenge:
    with session_factory()() as db:
        pending(scoped(db, id, scope))
    analyzer()  # Missing models/configuration must not issue a usable challenge.
    now = utcnow().timestamp()
    state: dict = {
        "token": secrets.token_hex(32),
        "action": "center",
        "step": 0,
        "deadline": now + 120,
        "step_deadline": now + 20,
        "complete": False,
        "hits": 0,
        "frames": 0,
        "hashes": [],
        "last_at": 0,
        "selfie_key": None,
    }
    old_keys = set()
    with session_factory()() as db, db.begin():
        row = scoped(db, id, scope, lock=True)
        pending(row)
        old = unseal(row.live_state)
        if old.get("selfie_key"):
            old_keys.add(old["selfie_key"])
        if "selfie" in row.evidence:
            old_keys.add(row.evidence["selfie"]["key"])
        row.evidence = {key: value for key, value in row.evidence.items() if key != "selfie"}
        row.live_state = seal(state)
        row.live_expires_at = datetime.fromtimestamp(state["deadline"], UTC)
        row.version += 1
    for key in old_keys:
        storage().delete(key)
    return reply(state)


def frame(id: str, data: LiveFrame) -> LiveChallenge:
    now = utcnow().timestamp()
    with session_factory()() as db:
        row = scoped(db, id, data)
        pending(row)
        state = unseal(row.live_state)
    if not state or now > state["deadline"] or state.get("failed"):
        raise KycError("LIVE_CHALLENGE_EXPIRED", 409)
    content, metadata = normalize(Upload(**data.model_dump(exclude={"token"}), kind="selfie"))
    digest = hashlib.sha256(content).hexdigest()
    # An exact retry after a lost response returns the same acknowledgement, never advances.
    if data.token == state.get("last_token") and digest == state.get("last_digest"):
        return reply(state, state.get("feedback", "follow_prompt"))
    if state["complete"] or not secrets.compare_digest(data.token, state["token"]):
        raise KycError("LIVE_CHALLENGE_CONFLICT", 409)
    if now > state["step_deadline"] or state["frames"] >= 80:
        raise KycError("LIVE_CHALLENGE_EXPIRED", 409)
    if now - state["last_at"] < 0.35:
        raise KycError("LIVE_CAPTURE_TOO_FAST", 429)
    if digest in state["hashes"]:
        raise KycError("LIVE_FRAME_REPLAY", 409)
    baseline = storage().get(state["selfie_key"]) if state["selfie_key"] else None
    check, ratio = analyzer().analyze(content, baseline)
    config = settings()
    assert config.liveness_center_tolerance is not None
    assert config.liveness_turn_threshold is not None
    matches = False
    if check == "passed" and ratio is not None:
        matches = (
            abs(ratio) <= config.liveness_center_tolerance
            if state["action"] == "center"
            else ratio >= config.liveness_turn_threshold
            if state["action"] == "left"
            else ratio <= -config.liveness_turn_threshold
        )
    feedback = (
        "hold_still" if matches else "face_not_clear" if check == "unavailable" else "follow_prompt"
    )
    state["hits"] = state["hits"] + 1 if matches else 0
    state["frames"] += 1
    state["hashes"].append(digest)
    state["last_at"] = now
    state["last_token"], state["last_digest"] = data.token, digest
    state["token"] = secrets.token_hex(32)
    state["failed"] = check == "failed"
    new_key = None
    if matches and not state["selfie_key"]:
        new_key = storage().put(content)
        state["selfie_key"] = new_key
        state["selfie_metadata"] = metadata.model_dump()
    if state["hits"] >= 2:
        state["step"] += 1
        state["hits"] = 0
        state["step_deadline"] = now + 20
        state["complete"] = state["step"] == 9
        state["action"] = "center" if state["step"] % 2 == 0 else secrets.choice(("left", "right"))
        feedback = "follow_prompt"
        if state["complete"]:
            state["completed_at"] = now
    state["feedback"] = feedback
    try:
        with session_factory()() as db, db.begin():
            row = scoped(db, id, data, lock=True)
            pending(row)
            current = unseal(row.live_state)
            if current.get("token") != data.token:
                raise KycError("LIVE_CHALLENGE_CONFLICT", 409)
            if utcnow().timestamp() > min(state["deadline"], current["step_deadline"]):
                raise KycError("LIVE_CHALLENGE_EXPIRED", 409)
            row.live_state = seal(state)
            if state["complete"]:
                row.live_expires_at = utcnow() + timedelta(seconds=600)
                row.evidence = {
                    **row.evidence,
                    "selfie": {"key": state["selfie_key"], "metadata": state["selfie_metadata"]},
                }
                row.version += 1
    except Exception:
        if new_key:
            storage().delete(new_key)
        raise
    if check == "failed":
        raise KycError("LIVE_CHECK_FAILED", 422)
    return reply(state, feedback)


def prune_captures() -> None:
    from sqlalchemy import select

    from app.database import Verification

    with session_factory()() as db:
        ids = list(
            db.scalars(
                select(Verification.id)
                .where(
                    Verification.status == Status.PENDING_UPLOAD,
                    Verification.live_expires_at <= utcnow(),
                )
                .order_by(Verification.live_expires_at)
                .limit(100)
            )
        )
    for id in ids:
        with session_factory()() as db, db.begin():
            row = db.scalar(select(Verification).where(Verification.id == id).with_for_update())
            if (
                row is None
                or row.status != Status.PENDING_UPLOAD
                or row.live_expires_at is None
                or row.live_expires_at.replace(tzinfo=UTC) > utcnow()
            ):
                continue
            state = unseal(row.live_state)
            state["failed"] = True
            row.live_state = seal(state)
        try:
            if state.get("selfie_key"):
                storage().delete(state["selfie_key"])
        except Exception:
            logging.getLogger("kyc").error(
                "kyc_capture_erasure_failed", extra={"verification_id": id}
            )
            continue  # Retain the expired reference so the next sweep retries deletion.
        with session_factory()() as db, db.begin():
            row = db.scalar(select(Verification).where(Verification.id == id).with_for_update())
            if row is None or unseal(row.live_state).get("token") != state.get("token"):
                continue
            row.evidence = {
                kind: value
                for kind, value in row.evidence.items()
                if value["key"] != state.get("selfie_key")
            }
            row.live_state = None
            row.live_expires_at = None
            row.version += 1
