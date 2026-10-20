import base64
from datetime import UTC, date, datetime, timedelta

import pytest

from app import live_capture
from app.database import Verification, session_factory
from app.providers import Providers, TesseractOCR, UnavailableAssurance
from app.schemas import CreateVerification, LiveFrame, Scope, Upload
from app.security import KycError
from app.service import KycService
from app.storage import storage, unseal
from tests.conftest import signed, synthetic_image
from tests.test_optical import passport_text

ID = "01J00000000000000000000003"


class FakeAnalyzer:
    check = "passed"
    ratio = 0.0

    def analyze(self, content, baseline):
        return self.check, self.ratio


@pytest.fixture
def live(monkeypatch, isolated, payload):
    now = [datetime(2026, 9, 24, tzinfo=UTC)]

    class ClockDate(date):
        @classmethod
        def today(cls):
            return now[0].date()

    monkeypatch.setattr(live_capture, "utcnow", lambda: now[0])
    monkeypatch.setattr("app.service.utcnow", lambda: now[0])
    monkeypatch.setattr("app.worker.utcnow", lambda: now[0])
    monkeypatch.setattr("app.providers.date", ClockDate)
    monkeypatch.setattr("app.optical.date", ClockDate)
    isolated.liveness_center_tolerance = 0.1
    isolated.liveness_turn_threshold = 0.25
    analyzer = FakeAnalyzer()
    monkeypatch.setattr(live_capture, "analyzer", lambda: analyzer)
    service = KycService()
    payload["personal"]["full_name"] = "TEST PERSON SYNTHETIC"
    service.create(ID, CreateVerification(**payload, assurance_profile="optical_v1"))
    return service, Scope(**{k: payload[k] for k in ("tenant_id", "subject_id")}), now, analyzer


def capture(scope, token, variant):
    return LiveFrame(
        **scope.model_dump(),
        token=token,
        mime="image/jpeg",
        content=base64.b64encode(synthetic_image((900 + variant, 600))).decode(),
    )


def test_full_live_challenge_requires_order_timing_continuity_and_one_time_frames(
    live, upload, monkeypatch, isolated
):
    service, scope, now, analyzer = live
    prompt = live_capture.start(ID, scope)
    service.upload(ID, Upload(**upload))
    with pytest.raises(KycError, match="EVIDENCE_INCOMPLETE"):
        service.submit(ID, scope)
    for variant in range(18):
        analyzer.ratio = {"center": 0.0, "left": 0.4, "right": -0.4}[prompt.action]
        now[0] += timedelta(seconds=1)
        data = capture(scope, prompt.token, variant)
        prompt = live_capture.frame(ID, data)
        assert live_capture.frame(ID, data) == prompt
        if variant < 17:
            assert not prompt.complete
            assert "selfie" not in {e.kind for e in service.read(ID, scope).evidence}
    assert prompt.complete and prompt.step == 9
    assert service.submit(ID, scope).status == "SUBMITTED"
    with session_factory()() as db:
        row = db.get(Verification, ID)
        state = unseal(row.live_state)
        assert state["selfie_key"] == row.evidence["selfie"]["key"]
        assert '"hashes"' not in row.live_state
    from app.worker import process

    class Match:
        def compare(self, document, selfie):
            return "passed"

    isolated.mode = "self_hosted"
    unavailable = UnavailableAssurance()
    monkeypatch.setattr(
        "app.worker.providers",
        lambda: Providers(
            TesseractOCR(), unavailable, Match(), unavailable, name="test-self-hosted"
        ),
    )
    monkeypatch.setattr(TesseractOCR, "text", lambda self, content: passport_text())
    assert process(ID)
    result = service.read(ID, scope)
    assert result.status == "APPROVED"
    assert result.result.reason_code == "OPTICAL_VERIFIED"
    assert result.result.checks["document"] == "unavailable"
    assert result.result.checks["liveness"] == "passed"
    with session_factory()() as db:
        assert db.get(Verification, ID).live_state is None


def test_scope_replay_wrong_token_and_expiry_fail_closed(live):
    service, scope, now, analyzer = live
    prompt = live_capture.start(ID, scope)
    frame = capture(scope, prompt.token, 1)
    accepted = live_capture.frame(ID, frame)
    with pytest.raises(KycError, match="LIVE_CHALLENGE_CONFLICT"):
        live_capture.frame(ID, capture(scope, prompt.token, 2))
    now[0] += timedelta(seconds=1)
    with pytest.raises(KycError, match="LIVE_FRAME_REPLAY"):
        live_capture.frame(ID, frame.model_copy(update={"token": accepted.token}))
    with pytest.raises(KycError, match="VERIFICATION_NOT_FOUND"):
        live_capture.frame(
            ID,
            capture(
                scope.model_copy(update={"subject_id": "01J00000000000000000000009"}),
                accepted.token,
                2,
            ),
        )
    with pytest.raises(KycError, match="VERIFICATION_NOT_FOUND"):
        live_capture.start(ID, scope.model_copy(update={"tenant_id": "01J00000000000000000000009"}))
    now[0] += timedelta(seconds=21)
    with pytest.raises(KycError, match="LIVE_CHALLENGE_EXPIRED"):
        live_capture.frame(ID, capture(scope, accepted.token, 2))


def test_failed_pad_or_face_continuity_terminates_challenge(live):
    _, scope, _, analyzer = live
    prompt = live_capture.start(ID, scope)
    analyzer.check = "failed"
    with pytest.raises(KycError, match="LIVE_CHECK_FAILED"):
        live_capture.frame(ID, capture(scope, prompt.token, 1))
    with pytest.raises(KycError, match="LIVE_CHALLENGE_EXPIRED"):
        live_capture.frame(ID, capture(scope, prompt.token, 2))


def test_unclear_wrong_pose_and_fast_frames_do_not_advance(live):
    _, scope, now, analyzer = live
    prompt = live_capture.start(ID, scope)
    analyzer.check = "unavailable"
    prompt = live_capture.frame(ID, capture(scope, prompt.token, 1))
    assert prompt.step == 0 and prompt.feedback == "face_not_clear"
    with pytest.raises(KycError, match="LIVE_CAPTURE_TOO_FAST"):
        live_capture.frame(ID, capture(scope, prompt.token, 2))
    now[0] += timedelta(seconds=1)
    analyzer.check, analyzer.ratio = "passed", 0.4
    prompt = live_capture.frame(ID, capture(scope, prompt.token, 2))
    assert prompt.step == 0


def test_restart_during_inference_discards_stale_frame_without_overwriting_new_state(
    live, monkeypatch
):
    _, scope, _, analyzer = live
    prompt = live_capture.start(ID, scope)
    replacement = []

    def analyze(content, baseline):
        replacement.append(live_capture.start(ID, scope))
        return "passed", 0.0

    monkeypatch.setattr(analyzer, "analyze", analyze)
    with pytest.raises(KycError, match="LIVE_CHALLENGE_CONFLICT"):
        live_capture.frame(ID, capture(scope, prompt.token, 0))
    with session_factory()() as db:
        assert unseal(db.get(Verification, ID).live_state)["token"] == replacement[0].token
    assert list(storage().root.iterdir()) == []


def test_restart_invalidates_previous_capture_and_erases_old_reference(live):
    _, scope, _, _ = live
    prompt = live_capture.start(ID, scope)
    live_capture.frame(ID, capture(scope, prompt.token, 1))
    with session_factory()() as db:
        key = unseal(db.get(Verification, ID).live_state)["selfie_key"]
    live_capture.start(ID, scope)
    with pytest.raises(FileNotFoundError):
        storage().get(key)
    with pytest.raises(KycError, match="LIVE_CHALLENGE_CONFLICT"):
        live_capture.frame(ID, capture(scope, prompt.token, 2))


def test_abandoned_reference_expires_and_cancelled_attempt_cannot_accept_frames(live):
    service, scope, now, _ = live
    prompt = live_capture.start(ID, scope)
    live_capture.frame(ID, capture(scope, prompt.token, 1))
    with session_factory()() as db:
        key = unseal(db.get(Verification, ID).live_state)["selfie_key"]
    now[0] += timedelta(seconds=121)
    live_capture.prune_captures()
    with pytest.raises(FileNotFoundError):
        storage().get(key)
    with session_factory()() as db:
        assert db.get(Verification, ID).live_state is None
    prompt = live_capture.start(ID, scope)
    service.erase(ID, scope)
    with pytest.raises(KycError, match="INVALID_STATE_TRANSITION"):
        live_capture.frame(ID, capture(scope, prompt.token, 2))


def test_optical_attempt_rejects_still_selfie_and_signed_api_validates_frames(live, client, upload):
    service, scope, _, _ = live
    with pytest.raises(KycError, match="LIVE_CAPTURE_REQUIRED"):
        service.upload(ID, Upload(**{**upload, "kind": "selfie"}))
    path = f"/api/v1/verifications/{ID}/live/start"
    assert client.post(path, json=scope.model_dump()).status_code == 401
    prompt = signed(client, "POST", path, scope.model_dump()).json()
    data = capture(scope, prompt["token"], 0).model_dump()
    assert signed(client, "POST", path.replace("start", "frame"), data).status_code == 200
    assert (
        signed(client, "POST", path.replace("start", "frame"), {**data, "passed": True}).status_code
        == 422
    )
    assert (
        signed(client, "POST", path.replace("start", "frame"), {**data, "token": "bad"}).status_code
        == 422
    )
