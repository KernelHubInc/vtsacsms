from datetime import UTC, datetime
from functools import lru_cache
from typing import Any

from sqlalchemy import JSON, DateTime, Index, Integer, String, Text, create_engine
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, sessionmaker

from app.config import settings


def utcnow() -> datetime:
    return datetime.now(UTC)


class Base(DeclarativeBase):
    pass


class Verification(Base):
    __tablename__ = "verifications"
    __table_args__ = (
        Index("ix_verification_tenant_subject", "tenant_id", "subject_id"),
        Index("ix_verification_jobs", "status", "lease_until"),
        Index("ix_verification_retention", "delete_after"),
        Index("ix_verification_live_expiry", "live_expires_at"),
    )
    id: Mapped[str] = mapped_column(String(26), primary_key=True)
    tenant_id: Mapped[str] = mapped_column(String(26))
    subject_id: Mapped[str] = mapped_column(String(26))
    fingerprint: Mapped[str] = mapped_column(String(64))
    status: Mapped[str] = mapped_column(String(24), default="PENDING_UPLOAD")
    version: Mapped[int] = mapped_column(Integer, default=1)
    document: Mapped[dict[str, Any]] = mapped_column(JSON)
    assurance_profile: Mapped[str] = mapped_column(String(24), default="issuer_v1")
    live_state: Mapped[str | None] = mapped_column(Text, nullable=True)
    live_expires_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    personal: Mapped[str | None] = mapped_column(Text)
    extracted: Mapped[str | None] = mapped_column(Text, nullable=True)
    evidence: Mapped[dict[str, Any]] = mapped_column(JSON, default=dict)
    result: Mapped[dict[str, Any] | None] = mapped_column(JSON, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    lease_until: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    delete_after: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    evidence_deleted: Mapped[bool] = mapped_column(default=False)
    attempts: Mapped[int] = mapped_column(default=0)


class Outbox(Base):
    __tablename__ = "callback_outbox"
    __table_args__ = (Index("ix_callback_due", "delivered_at", "next_attempt"),)
    id: Mapped[str] = mapped_column(String(26), primary_key=True)
    tenant_id: Mapped[str] = mapped_column(String(26), index=True)
    payload: Mapped[dict[str, Any]] = mapped_column(JSON)
    attempts: Mapped[int] = mapped_column(default=0)
    next_attempt: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    delivered_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)


@lru_cache
def engine():
    return create_engine(settings().database_url.get_secret_value(), pool_pre_ping=True)


def session_factory():
    return sessionmaker(engine(), expire_on_commit=False)
