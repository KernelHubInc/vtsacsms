from datetime import date, datetime
from enum import StrEnum
from typing import Annotated, Literal

from pydantic import BaseModel, ConfigDict, Field, StringConstraints, model_validator

Ulid = Annotated[str, StringConstraints(pattern=r"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$")]


class Status(StrEnum):
    NOT_STARTED = "NOT_STARTED"
    IN_PROGRESS = "IN_PROGRESS"
    PENDING_UPLOAD = "PENDING_UPLOAD"
    SUBMITTED = "SUBMITTED"
    PROCESSING = "PROCESSING"
    NEEDS_REVIEW = "NEEDS_REVIEW"
    ACTION_REQUIRED = "ACTION_REQUIRED"
    APPROVED = "APPROVED"
    REJECTED = "REJECTED"
    EXPIRED = "EXPIRED"
    CANCELLED = "CANCELLED"


TRANSITIONS = {
    Status.IN_PROGRESS: {Status.PENDING_UPLOAD, Status.CANCELLED},
    Status.PENDING_UPLOAD: {Status.SUBMITTED, Status.CANCELLED, Status.EXPIRED},
    Status.SUBMITTED: {Status.PROCESSING, Status.CANCELLED, Status.EXPIRED},
    Status.PROCESSING: {
        Status.APPROVED,
        Status.REJECTED,
        Status.NEEDS_REVIEW,
        Status.ACTION_REQUIRED,
        Status.CANCELLED,
        Status.EXPIRED,
    },
}


def can_transition(current: Status, target: Status) -> bool:
    return target in TRANSITIONS.get(current, set())


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid")


class Scope(StrictModel):
    tenant_id: Ulid
    subject_id: Ulid


class DocumentType(StrictModel):
    key: Annotated[str, StringConstraints(pattern=r"^[a-z0-9_]{2,40}$")]
    front: bool = True
    back: bool = False
    expiration_date: bool = False
    document_number: bool = False
    nationality: bool = False
    birth_date: bool = True
    issuing_country: bool = True


class PersonalInfo(StrictModel):
    full_name: str = Field(min_length=2, max_length=180)
    birth_date: date | None = None
    document_number: str | None = Field(default=None, max_length=80)
    expiration_date: date | None = None
    nationality: str | None = Field(default=None, pattern=r"^[A-Z]{2}$")
    issuing_country: str | None = Field(default=None, pattern=r"^[A-Z]{2}$")


class CreateVerification(Scope):
    assurance_profile: Literal["issuer_v1", "optical_v1"] = "issuer_v1"
    document: DocumentType
    personal: PersonalInfo
    consent_version: str = Field(min_length=1, max_length=80)

    @model_validator(mode="after")
    def required_fields(self) -> "CreateVerification":
        for field in (
            "birth_date",
            "document_number",
            "expiration_date",
            "nationality",
            "issuing_country",
        ):
            if getattr(self.document, field) and not getattr(self.personal, field):
                raise ValueError("Required document field is missing")
        if self.personal.birth_date and self.personal.birth_date >= date.today():
            raise ValueError("Birth date must be in the past")
        return self


class Upload(Scope):
    kind: Literal["front", "back", "selfie"]
    mime: Literal["image/jpeg", "image/png"]
    content: str = Field(min_length=4, max_length=14 * 1024 * 1024)


class Evidence(StrictModel):
    kind: Literal["front", "back", "selfie"]
    width: int
    height: int
    bytes: int
    sharpness: float


class ReadImage(Scope):
    kind: Literal["front", "back", "selfie"]


class LiveFrame(Scope):
    token: str = Field(pattern=r"^[a-f0-9]{64}$")
    mime: Literal["image/jpeg", "image/png"]
    content: str = Field(min_length=4, max_length=3 * 1024 * 1024)


class LiveChallenge(StrictModel):
    token: str
    action: Literal["center", "left", "right", "complete"]
    step: int = Field(ge=0, le=9)
    total_steps: Literal[9] = 9
    expires_at: datetime
    complete: bool
    feedback: Literal["follow_prompt", "hold_still", "face_not_clear", "complete"]


class PrivateImage(StrictModel):
    mime: Literal["image/jpeg"] = "image/jpeg"
    content: str


class Result(StrictModel):
    provider: str = Field(max_length=80)
    reason_code: str = Field(pattern=r"^[A-Z0-9_]{1,80}$")
    checks: dict[str, Literal["passed", "failed", "unavailable", "mock"]]
    duration_ms: int = Field(ge=0)


class Snapshot(StrictModel):
    id: Ulid
    tenant_id: Ulid
    subject_id: Ulid
    status: Status
    version: int = Field(ge=1)
    evidence: list[Evidence]
    result: Result | None = None
    evidence_deleted: bool


class Details(StrictModel):
    snapshot: Snapshot
    personal: dict[str, str | None]
    extracted: dict[str, str | None]


class Callback(StrictModel):
    event_id: Ulid
    event_type: Literal["identity.kyc.processed.v1"] = "identity.kyc.processed.v1"
    schema_version: Literal[1] = 1
    occurred_at: datetime
    tenant_id: Ulid
    aggregate_type: Literal["kyc_verification"] = "kyc_verification"
    aggregate_id: Ulid
    correlation_id: Ulid
    causation_id: Ulid
    data: Snapshot
