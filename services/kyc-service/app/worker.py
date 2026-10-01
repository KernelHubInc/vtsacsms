import json
import logging
import time
from datetime import timedelta
from urllib.parse import urlsplit

import httpx
from celery import Celery
from sqlalchemy import or_, select

from app.config import settings
from app.database import Outbox, Verification, session_factory, utcnow
from app.logging import configure_logging
from app.providers import Check, TesseractOCR, decide, providers
from app.schemas import Result, Status
from app.security import signed_headers
from app.service import enqueue_callback
from app.storage import LocalStorage, seal, storage, unseal

config = settings()
celery = Celery("kyc", broker=config.redis_url.get_secret_value())
celery.conf.update(
    task_serializer="json",
    accept_content=["json"],
    task_ignore_result=True,
    task_acks_late=True,
    worker_prefetch_multiplier=1,
    task_reject_on_worker_lost=True,
    task_soft_time_limit=240,
    task_time_limit=270,
    beat_schedule={"discover-durable-work": {"task": "kyc.tick", "schedule": 10.0}},
    broker_connection_retry_on_startup=True,
)
logger = logging.getLogger("kyc.worker")
configure_logging()


def process(id: str) -> bool:
    now = utcnow()
    with session_factory()() as db, db.begin():
        row = db.scalar(
            select(Verification)
            .where(
                Verification.id == id,
                Verification.status.in_([Status.SUBMITTED, Status.PROCESSING]),
                or_(Verification.lease_until.is_(None), Verification.lease_until < now),
                Verification.evidence_deleted.is_(False),
            )
            .with_for_update(skip_locked=True)
        )
        if row is None:
            return False
        row.status = Status.PROCESSING
        row.version += 1
        row.attempts += 1
        row.lease_until = now + timedelta(seconds=300)
        claimed_version, attempts = row.version, row.attempts
        evidence, personal = dict(row.evidence), unseal(row.personal)
        profile, document, live = row.assurance_profile, dict(row.document), unseal(row.live_state)
    started = time.monotonic()
    extracted: dict[str, str | None] = {}
    checks: dict[str, Check] = {}
    name = "mock" if config.mode == "mock" else "local-tesseract"
    try:
        if attempts > 3:
            raise RuntimeError("Processing attempt limit reached")
        images = {kind: storage().get(value["key"]) for kind, value in evidence.items()}
        if config.mode == "mock":
            if config.mock_outcome == "PROCESSING_ERROR":
                raise RuntimeError("Explicit mock processing failure")
            status = Status(config.mock_outcome)
            code = "MOCK_" + config.mock_outcome
            checks = {"document": "mock", "face_match": "mock", "liveness": "mock"}
        else:
            bundle = providers()
            name = bundle.name
            optical = config.mode == "self_hosted" and profile == "optical_v1"
            if not optical:
                for kind in ("front", "back"):
                    if kind in images:
                        extracted.update(
                            {k: v for k, v in bundle.ocr.extract(images[kind]).items() if v}
                        )
            checks = {
                "ocr": "passed" if extracted else "unavailable",
                "document": bundle.document.verify(images, personal),
                "face_match": bundle.face_match.compare(images.get("front", b""), images["selfie"]),
                "liveness": bundle.liveness.verify(images["selfie"]),
            }
            if bundle.document_data is not None:
                checks["document_data"] = bundle.document_data.verify(extracted, personal)
            if optical:
                from app.optical import data_matches, inspect_texts

                texts = {
                    kind: TesseractOCR().text(images[kind])
                    for kind in ("front", "back")
                    if kind in images
                }
                extracted, checks["optical_document"] = inspect_texts(document["key"], texts)
                checks["ocr"] = "passed" if extracted else "unavailable"
                checks["document_data"] = data_matches(extracted, personal)
                checks["liveness"] = (
                    "passed"
                    if live.get("complete")
                    and not live.get("failed")
                    and live.get("selfie_key") == evidence["selfie"]["key"]
                    else "unavailable"
                )
            status, code = decide(checks, personal, profile)
    except Exception:
        # Deliberately omit exception messages: provider errors can contain identity evidence.
        status, code = Status.ACTION_REQUIRED, "PROCESSING_FAILED"
        logger.error("kyc_processing_failed", extra={"verification_id": id})
    result = Result(
        provider=name,
        reason_code=code,
        checks=checks,
        duration_ms=max(0, int((time.monotonic() - started) * 1000)),
    )
    with session_factory()() as db, db.begin():
        row = db.scalar(select(Verification).where(Verification.id == id).with_for_update())
        if row is None or row.version != claimed_version or row.status != Status.PROCESSING:
            return False
        row.status, row.result = status, result.model_dump()
        row.extracted = seal(extracted)
        row.live_state = None
        row.live_expires_at = None
        row.version += 1
        row.lease_until = None
        enqueue_callback(db, row)
    logger.info("kyc_processed", extra={"verification_id": id, "duration_ms": result.duration_ms})
    return True


def deliver(id: str, client: httpx.Client | None = None) -> bool:
    with session_factory()() as db, db.begin():
        event = db.scalar(
            select(Outbox)
            .where(
                Outbox.id == id,
                Outbox.delivered_at.is_(None),
                Outbox.next_attempt <= utcnow(),
            )
            .with_for_update(skip_locked=True)
        )
        if event is None:
            return False
        event.attempts += 1
        event.next_attempt = utcnow() + timedelta(
            seconds=min(3600, 10 * 2 ** min(event.attempts, 8))
        )
        payload = dict(event.payload)
    body = json.dumps(payload, separators=(",", ":")).encode()
    headers = signed_headers(
        config.callback_secret.get_secret_value(), "POST", urlsplit(config.callback_url).path, body
    )
    headers["X-Correlation-ID"] = payload["correlation_id"]
    owned_client = client is None
    client = client or httpx.Client(timeout=httpx.Timeout(10, connect=3), follow_redirects=False)
    try:
        response = client.post(config.callback_url, content=body, headers=headers)
        if not 200 <= response.status_code < 300:
            return False
        with session_factory()() as db, db.begin():
            event = db.get(Outbox, id)
            if event:
                event.delivered_at = utcnow()
        return True
    except httpx.HTTPError:
        return False
    finally:
        if owned_client:
            client.close()


def prune() -> int:
    count = 0
    with session_factory()() as db:
        ids = list(
            db.scalars(
                select(Verification.id)
                .where(
                    Verification.delete_after <= utcnow(),
                    Verification.evidence_deleted.is_(False),
                )
                .limit(100)
            )
        )
    for id in ids:
        # Tombstone blocks uploads/workers before deleting storage outside the transaction.
        with session_factory()() as db, db.begin():
            row = db.scalar(select(Verification).where(Verification.id == id).with_for_update())
            if row is None or row.evidence_deleted:
                continue
            if row.status in {
                Status.PENDING_UPLOAD,
                Status.SUBMITTED,
                Status.PROCESSING,
                Status.NEEDS_REVIEW,
            }:
                row.status = Status.EXPIRED
            row.version += 1
            live = unseal(row.live_state)
            row.personal, row.extracted = None, None
            keys = [item["key"] for item in row.evidence.values()]
            if live.get("selfie_key") and live["selfie_key"] not in keys:
                keys.append(live["selfie_key"])
        try:
            for key in keys:
                storage().delete(key)
        except Exception:
            logger.error("kyc_erasure_failed", extra={"verification_id": id})
            continue
        with session_factory()() as db, db.begin():
            row = db.get(Verification, id)
            row.evidence = {}
            row.live_state = None
            row.live_expires_at = None
            row.evidence_deleted = True
            enqueue_callback(db, row)
        count += 1
    store = storage()
    if isinstance(store, LocalStorage):
        try:
            store.prune_expired()
        except Exception:
            logger.error("kyc_orphan_erasure_failed")
    return count


@celery.task(name="kyc.process")
def process_task(id: str) -> None:
    process(id)


@celery.task(name="kyc.deliver")
def deliver_task(id: str) -> None:
    deliver(id)


@celery.task(name="kyc.tick")
def tick() -> None:
    from app.live_capture import prune_captures

    prune_captures()
    prune()
    with session_factory()() as db:
        jobs = db.scalars(
            select(Verification.id)
            .where(
                Verification.status.in_([Status.SUBMITTED, Status.PROCESSING]),
                or_(Verification.lease_until.is_(None), Verification.lease_until < utcnow()),
            )
            .limit(100)
        )
        for id in jobs:
            process_task.delay(id)
        events = db.scalars(
            select(Outbox.id)
            .where(
                Outbox.delivered_at.is_(None),
                Outbox.next_attempt <= utcnow(),
            )
            .limit(100)
        )
        for id in events:
            deliver_task.delay(id)
