import base64
import json
import os
import shutil
from datetime import timedelta
from io import BytesIO

import httpx
import pytest
from PIL import Image, ImageDraw
from pydantic import ValidationError
from sqlalchemy import func, select

from app.config import Settings, settings
from app.database import Outbox, Verification, session_factory, utcnow
from app.providers import TesseractOCR, decide
from app.schemas import Status, can_transition
from app.security import signature, signed_headers
from app.storage import storage, unseal
from app.worker import deliver, process, prune
from tests.conftest import signed, synthetic_image

ID = "01J00000000000000000000003"
PATH = f"/api/v1/verifications/{ID}"


def prepare(client, payload, upload):
    assert signed(client, "PUT", PATH, payload).status_code == 200
    for kind in ("front", "selfie"):
        assert (
            signed(client, "POST", PATH + "/evidence", {**upload, "kind": kind}).status_code == 200
        )
    assert (
        signed(
            client, "POST", PATH + "/submit", {k: payload[k] for k in ("tenant_id", "subject_id")}
        ).status_code
        == 200
    )


def test_authentication_replay_tamper_timestamp(client, payload):
    assert client.put(PATH, json=payload).status_code == 401
    body = json.dumps(payload).encode()
    headers = signed_headers(settings().request_secret.get_secret_value(), "PUT", PATH, body)
    assert client.put(PATH, content=body, headers=headers).status_code == 200
    assert client.put(PATH, content=body, headers=headers).status_code == 401
    headers = signed_headers(settings().request_secret.get_secret_value(), "PUT", PATH, body)
    assert client.put(PATH, content=body + b" ", headers=headers).status_code == 401
    headers["X-KYC-Timestamp"] = "1"
    assert client.put(PATH, content=body, headers=headers).status_code == 401


def test_signature_shared_vector():
    assert signature("test-secret", "POST", "/api/v1/test", "1700000000", "a" * 32, b"{}") == (
        "d5310e3baa52afa6b7336f07962f13b48235d4780916564a89e3ca5f1518ac88"
    )


def test_repository_lowercase_ulids_are_preserved(client, payload):
    path = PATH.lower()
    payload["tenant_id"] = payload["tenant_id"].lower()
    payload["subject_id"] = payload["subject_id"].lower()
    response = signed(client, "PUT", path, payload)
    assert response.status_code == 200
    assert response.json()["id"] == ID.lower()


def test_private_image_is_authenticated_scoped_and_erased(client, payload, upload, scope):
    signed(client, "PUT", PATH, payload)
    signed(client, "POST", PATH + "/evidence", upload)
    assert client.post(PATH + "/image", json={**scope, "kind": "front"}).status_code == 401
    assert (
        signed(
            client,
            "POST",
            PATH + "/image",
            {
                **scope,
                "subject_id": "01J00000000000000000000009",
                "kind": "front",
            },
        ).status_code
        == 404
    )
    response = signed(client, "POST", PATH + "/image", {**scope, "kind": "front"})
    assert response.status_code == 200
    assert base64.b64decode(response.json()["content"]).startswith(b"\xff\xd8")
    signed(client, "POST", PATH + "/erase", scope)
    assert signed(client, "POST", PATH + "/image", {**scope, "kind": "front"}).status_code == 404


def test_idempotency_scope_and_pii_redaction(client, payload, scope):
    first = signed(client, "PUT", PATH, payload)
    assert first.status_code == 200
    assert signed(client, "PUT", PATH, payload).json() == first.json()
    changed = {**payload, "personal": {**payload["personal"], "full_name": "Different fake person"}}
    assert signed(client, "PUT", PATH, changed).status_code == 409
    for key in ("tenant_id", "subject_id"):
        response = signed(
            client, "POST", PATH + "/snapshot", {**scope, key: "01J00000000000000000000009"}
        )
        assert response.status_code == 404
    assert "SYNTHETIC" not in first.text
    with session_factory()() as db:
        assert db.scalar(select(func.count()).select_from(Verification)) == 1
        row = db.get(Verification, ID)
        assert "SYNTHETIC" not in row.personal
        assert unseal(row.personal)["full_name"] == payload["personal"]["full_name"]


@pytest.mark.parametrize("failure", ["mime", "invalid", "resolution", "blur", "size"])
def test_upload_failures(client, payload, upload, failure):
    signed(client, "PUT", PATH, payload)
    if failure == "mime":
        upload["mime"] = "image/png"
    elif failure == "invalid":
        upload["content"] = base64.b64encode(b"not an image").decode()
    elif failure == "resolution":
        upload["content"] = base64.b64encode(synthetic_image((100, 100))).decode()
    elif failure == "blur":
        image = Image.new("RGB", (800, 600), "white")
        output = BytesIO()
        image.save(output, "JPEG")
        upload["content"] = base64.b64encode(output.getvalue()).decode()
    else:
        settings().max_file_bytes = 1024
    response = signed(client, "POST", PATH + "/evidence", upload)
    assert response.status_code in {413, 422}
    assert "content" not in response.json()["error"]


def test_upload_normalizes_metadata_and_encrypts_storage(client, payload, upload):
    signed(client, "PUT", PATH, payload)
    response = signed(client, "POST", PATH + "/evidence", upload)
    assert response.status_code == 200
    assert "key" not in response.text
    with session_factory()() as db:
        row = db.get(Verification, ID)
        key = row.evidence["front"]["key"]
        stored = storage().path(key).read_bytes()
        assert not stored.startswith(b"\xff\xd8")
        with Image.open(BytesIO(storage().get(key))) as image:
            assert len(image.getexif()) == 0


@pytest.mark.parametrize("outcome", ["APPROVED", "REJECTED", "NEEDS_REVIEW", "PROCESSING_ERROR"])
def test_worker_outcomes_and_duplicate_submission(client, payload, upload, scope, outcome):
    settings().mock_outcome = outcome
    prepare(client, payload, upload)
    assert signed(client, "POST", PATH + "/submit", scope).status_code == 200
    assert process(ID)
    assert not process(ID)
    response = signed(client, "POST", PATH + "/snapshot", scope).json()
    assert response["status"] == ("ACTION_REQUIRED" if outcome == "PROCESSING_ERROR" else outcome)
    with session_factory()() as db:
        assert db.scalar(select(func.count()).select_from(Outbox)) == 1
    assert signed(client, "POST", PATH + "/evidence", upload).status_code == 409


def test_missing_evidence_and_forbidden_back(client, payload, scope, upload):
    signed(client, "PUT", PATH, payload)
    assert signed(client, "POST", PATH + "/submit", scope).status_code == 422
    assert signed(client, "POST", PATH + "/evidence", {**upload, "kind": "back"}).status_code == 422


def test_callback_signed_retry_and_idempotency(client, payload, upload):
    prepare(client, payload, upload)
    process(ID)
    with session_factory()() as db:
        event = db.scalar(select(Outbox))
        id = event.id

    def callback(request):
        expected = signature(
            settings().callback_secret.get_secret_value(),
            "POST",
            request.url.path,
            request.headers["X-KYC-Timestamp"],
            request.headers["X-KYC-Nonce"],
            request.content,
        )
        assert expected == request.headers["X-KYC-Signature"]
        assert "SYNTHETIC" not in request.content.decode()
        return httpx.Response(200)

    with httpx.Client(transport=httpx.MockTransport(lambda _: httpx.Response(503))) as http:
        assert not deliver(id, http)
    with session_factory()() as db, db.begin():
        db.get(Outbox, id).next_attempt = utcnow() - timedelta(seconds=1)
    with httpx.Client(transport=httpx.MockTransport(callback)) as http:
        assert deliver(id, http)
        assert not deliver(id, http)


def test_cancel_and_retention_prevent_processing(client, payload, upload, scope):
    prepare(client, payload, upload)
    assert signed(client, "POST", PATH + "/erase", scope).status_code == 200
    assert signed(client, "POST", PATH + "/details", scope).json()["personal"] == {}
    assert not process(ID)
    assert prune() == 1
    assert prune() == 0
    with session_factory()() as db:
        row = db.get(Verification, ID)
        assert row.personal is None and row.extracted is None and row.evidence == {}
    assert signed(client, "POST", PATH + "/submit", scope).status_code == 409


def test_failed_erasure_is_retried_without_losing_object_references(
    client, payload, upload, scope, monkeypatch
):
    prepare(client, payload, upload)
    signed(client, "POST", PATH + "/erase", scope)
    store = storage()
    original = type(store).delete

    def unavailable(self, key):
        raise OSError("synthetic storage outage")

    monkeypatch.setattr(type(store), "delete", unavailable)
    assert prune() == 0
    with session_factory()() as db:
        row = db.get(Verification, ID)
        assert row.personal is None and row.evidence and not row.evidence_deleted
    monkeypatch.setattr(type(store), "delete", original)
    assert prune() == 1


def test_local_retention_also_deletes_crash_orphans(client):
    store = storage()
    old = store.put(b"synthetic orphan")
    current = store.put(b"synthetic current object")
    expired = (utcnow() - timedelta(days=settings().retention_days + 1)).timestamp()
    os.utime(store.path(old), (expired, expired))
    assert store.prune_expired() == 1
    assert not store.path(old).exists()
    assert store.get(current) == b"synthetic current object"


def test_stuck_lease_recovery(client, payload, upload):
    prepare(client, payload, upload)
    with session_factory()() as db, db.begin():
        row = db.get(Verification, ID)
        row.status = Status.PROCESSING
        row.lease_until = utcnow() - timedelta(seconds=1)
    assert process(ID)


def test_local_cannot_approve_and_states_fail_closed():
    settings().mode = "local"
    assert decide({"ocr": "passed"}, {})[0] == Status.NEEDS_REVIEW
    assert not can_transition(Status.APPROVED, Status.PROCESSING)
    assert can_transition(Status.SUBMITTED, Status.PROCESSING)
    assert decide({}, {"expiration_date": "2000-01-01"})[1] == "DOCUMENT_EXPIRED"


def test_mock_and_unconfigured_provider_forbidden_in_production():
    with pytest.raises(ValidationError):
        Settings(environment="production", mode="mock")
    with pytest.raises(ValidationError):
        Settings(environment="production", mode="provider")


def test_provider_approval_requires_every_assurance_check():
    settings().mode = "provider"
    assert decide({}, {})[0] == Status.NEEDS_REVIEW
    assert decide({"ocr": "passed"}, {})[0] == Status.NEEDS_REVIEW
    checks = dict.fromkeys(("ocr", "document", "face_match", "liveness"), "passed")
    assert decide(checks, {})[0] == Status.APPROVED
    checks["liveness"] = "unavailable"
    assert decide(checks, {})[0] == Status.NEEDS_REVIEW


def test_tesseract_on_synthetic_document():
    if shutil.which("tesseract") is None:
        pytest.skip(
            "Host has no Tesseract; mandatory OCR integration runs in the Docker test image"
        )
    image = Image.new("RGB", (1600, 900), "white")
    draw = ImageDraw.Draw(image)
    draw.text(
        (40, 100),
        "NAME: SYNTHETIC PERSON\nDOB: 1990-01-01\nID NUMBER: TEST-123456",
        fill="black",
        font_size=48,
        spacing=30,
    )
    output = BytesIO()
    image.save(output, "PNG")
    result = TesseractOCR().extract(output.getvalue())
    assert result["full_name"] == "SYNTHETIC PERSON"
    assert result["document_number"] == "TEST-123456"
