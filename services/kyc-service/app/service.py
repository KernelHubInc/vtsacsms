import base64
import hashlib
import secrets
import time
from datetime import timedelta

from sqlalchemy import select
from sqlalchemy.exc import IntegrityError

from app.config import settings
from app.database import Outbox, Verification, session_factory, utcnow
from app.images import normalize
from app.schemas import (
    Callback,
    CreateVerification,
    Details,
    PrivateImage,
    ReadImage,
    Result,
    Scope,
    Snapshot,
    Status,
    Upload,
)
from app.security import KycError
from app.storage import seal, storage, unseal


def new_ulid() -> str:
    value = (int(time.time() * 1000) << 80) | secrets.randbits(80)
    alphabet = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"
    return "".join(alphabet[(value >> shift) & 31] for shift in range(125, -1, -5))


def snapshot(row: Verification) -> Snapshot:
    return Snapshot(
        id=row.id,
        tenant_id=row.tenant_id,
        subject_id=row.subject_id,
        status=Status(row.status),
        version=row.version,
        evidence=[v["metadata"] for v in row.evidence.values()],
        result=Result.model_validate(row.result) if row.result else None,
        evidence_deleted=row.evidence_deleted,
    )


def scoped(db, id: str, scope: Scope, lock: bool = False) -> Verification:
    query = select(Verification).where(
        Verification.id == id,
        Verification.tenant_id == scope.tenant_id,
        Verification.subject_id == scope.subject_id,
    )
    row = db.scalar(query.with_for_update() if lock else query)
    if row is None:
        raise KycError("VERIFICATION_NOT_FOUND", 404)
    return row


def enqueue_callback(db, row: Verification) -> None:
    event_id = new_ulid()
    event = Callback(
        event_id=event_id,
        occurred_at=utcnow(),
        tenant_id=row.tenant_id,
        aggregate_id=row.id,
        correlation_id=row.id,
        causation_id=row.id,
        data=snapshot(row),
    )
    db.add(Outbox(id=event_id, tenant_id=row.tenant_id, payload=event.model_dump(mode="json")))


class KycService:
    def image(self, id: str, data: ReadImage) -> PrivateImage:
        with session_factory()() as db:
            row = scoped(db, id, data)
            if (
                row.evidence_deleted
                or row.status == Status.CANCELLED
                or data.kind not in row.evidence
            ):
                raise KycError("EVIDENCE_EXPIRED", 404)
            key = row.evidence[data.kind]["key"]
        return PrivateImage(content=base64.b64encode(storage().get(key)).decode())

    def create(self, id: str, data: CreateVerification) -> Snapshot:
        # Preserve idempotency fingerprints of issuer attempts created before this field existed.
        exclude = {"assurance_profile"} if data.assurance_profile == "issuer_v1" else set()
        fingerprint = hashlib.sha256(data.model_dump_json(exclude=exclude).encode()).hexdigest()
        with session_factory()() as db:
            existing = db.get(Verification, id)
            if existing:
                row = scoped(db, id, data)
                if row.fingerprint != fingerprint:
                    raise KycError("IDEMPOTENCY_CONFLICT", 409)
                return snapshot(row)
            row = Verification(
                id=id,
                tenant_id=data.tenant_id,
                subject_id=data.subject_id,
                fingerprint=fingerprint,
                document=data.document.model_dump(),
                assurance_profile=data.assurance_profile,
                personal=seal(data.personal.model_dump(mode="json")),
                delete_after=utcnow() + timedelta(days=settings().retention_days),
            )
            db.add(row)
            try:
                db.commit()
            except IntegrityError:
                db.rollback()
                return self.create(id, data)
            return snapshot(row)

    def read(self, id: str, scope: Scope) -> Snapshot:
        with session_factory()() as db:
            return snapshot(scoped(db, id, scope))

    def details(self, id: str, scope: Scope) -> Details:
        with session_factory()() as db:
            row = scoped(db, id, scope)
            unavailable = row.evidence_deleted or row.status == Status.CANCELLED
            return Details(
                snapshot=snapshot(row),
                personal={} if unavailable else unseal(row.personal),
                extracted={} if unavailable else unseal(row.extracted),
            )

    def upload(self, id: str, data: Upload) -> Snapshot:
        with session_factory()() as db:
            row = scoped(db, id, data)
            if row.status != Status.PENDING_UPLOAD or row.evidence_deleted:
                raise KycError("INVALID_STATE_TRANSITION", 409)
            if data.kind == "selfie" and row.assurance_profile == "optical_v1":
                raise KycError("LIVE_CAPTURE_REQUIRED", 409)
        content, metadata = normalize(data)
        store = storage()
        key = store.put(content)
        old_key = None
        try:
            with session_factory()() as db, db.begin():
                row = scoped(db, id, data, lock=True)
                if row.status != Status.PENDING_UPLOAD or row.evidence_deleted:
                    raise KycError("INVALID_STATE_TRANSITION", 409)
                if data.kind == "back" and not row.document["back"]:
                    raise KycError("UNEXPECTED_DOCUMENT_SIDE")
                old_key = row.evidence.get(data.kind, {}).get("key")
                row.evidence = {
                    **row.evidence,
                    data.kind: {
                        "key": key,
                        "metadata": metadata.model_dump(),
                    },
                }
                row.version += 1
                result = snapshot(row)
        except Exception:
            store.delete(key)
            raise
        if old_key:
            store.delete(old_key)
        return result

    def submit(self, id: str, scope: Scope) -> Snapshot:
        with session_factory()() as db, db.begin():
            row = scoped(db, id, scope, lock=True)
            if row.evidence_deleted:
                raise KycError("EVIDENCE_EXPIRED", 409)
            if row.status != Status.PENDING_UPLOAD:
                return snapshot(row)
            required = {"selfie"} | {key for key in ("front", "back") if row.document[key]}
            if not required.issubset(row.evidence):
                raise KycError("EVIDENCE_INCOMPLETE")
            if row.assurance_profile == "optical_v1":
                live = unseal(row.live_state)
                if (
                    not live.get("complete")
                    or live.get("failed")
                    or live.get("selfie_key") != row.evidence["selfie"]["key"]
                    or utcnow().timestamp() - live.get("completed_at", 0) > 600
                ):
                    raise KycError("LIVE_CAPTURE_REQUIRED", 409)
            row.status = Status.SUBMITTED
            row.version += 1
            return snapshot(row)

    def erase(self, id: str, scope: Scope) -> Snapshot:
        # Mark first; a running worker checks this tombstone before committing its result.
        with session_factory()() as db, db.begin():
            row = scoped(db, id, scope, lock=True)
            row.status = Status.CANCELLED
            row.version += 1
            row.delete_after = utcnow()
        return self.read(id, scope)
