import hashlib

import pytest

from app.config import settings
from app.providers import decide
from app.schemas import Status
from app.self_hosted import DocumentDataCheck, checked_model, face_decision


def test_face_decision_has_an_inconclusive_band_and_rejects_nonfinite_scores():
    assert face_decision(0.8, 0.7, 0.3) == "passed"
    assert face_decision(0.2, 0.7, 0.3) == "failed"
    for score in (0.5, float("nan"), float("inf"), 1.1, -1.1):
        assert face_decision(score, 0.7, 0.3) == "unavailable"


def test_model_integrity_is_required(tmp_path):
    model = tmp_path / "fixture.onnx"
    model.write_bytes(b"synthetic model bytes")
    digest = hashlib.sha256(model.read_bytes()).hexdigest()
    assert checked_model(str(model), digest) == str(model)
    with pytest.raises(ValueError):
        checked_model(str(model), "0" * 64)
    with pytest.raises(ValueError):
        checked_model(str(tmp_path / "missing"), digest)


def test_document_data_never_substitutes_for_authenticity_or_liveness():
    settings().mode = "self_hosted"
    check = DocumentDataCheck()
    extracted = {"full_name": "TEST PERSON"}
    assert check.verify(extracted, {"full_name": "Test Person"}) == "passed"
    assert check.verify(extracted, {"full_name": "Different Person"}) == "failed"
    assert check.verify({}, {}) == "unavailable"
    assert (
        check.verify(extracted, {"full_name": "Test Person", "birth_date": "1990-01-01"})
        == "unavailable"
    )
    state, _ = decide(
        {
            "ocr": "passed",
            "document_data": "passed",
            "face_match": "passed",
            "document": "unavailable",
            "liveness": "unavailable",
        },
        {},
    )
    assert state == Status.NEEDS_REVIEW


def test_self_hosted_decision_requires_every_authenticity_and_liveness_check():
    settings().mode = "self_hosted"
    passed = {key: "passed" for key in ("ocr", "document", "face_match", "liveness")}
    assert decide(passed, {})[0] == Status.APPROVED
    for key in passed:
        assert decide({**passed, key: "unavailable"}, {})[0] == Status.NEEDS_REVIEW
