"""Private evidence jobs and durable callback outbox."""

import sqlalchemy as sa
from alembic import op

revision = "0001"
down_revision = None


def upgrade() -> None:
    op.create_table(
        "verifications",
        sa.Column("id", sa.String(26), primary_key=True),
        sa.Column("tenant_id", sa.String(26), nullable=False),
        sa.Column("subject_id", sa.String(26), nullable=False),
        sa.Column("fingerprint", sa.String(64), nullable=False),
        sa.Column("status", sa.String(24), nullable=False),
        sa.Column("version", sa.Integer, nullable=False),
        sa.Column("document", sa.JSON, nullable=False),
        sa.Column("personal", sa.Text),
        sa.Column("extracted", sa.Text),
        sa.Column("evidence", sa.JSON, nullable=False),
        sa.Column("result", sa.JSON),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("lease_until", sa.DateTime(timezone=True)),
        sa.Column("delete_after", sa.DateTime(timezone=True), nullable=False),
        sa.Column("evidence_deleted", sa.Boolean, nullable=False),
        sa.Column("attempts", sa.Integer, nullable=False),
        sa.CheckConstraint("version > 0 AND attempts >= 0", name="valid_job_counters"),
    )
    op.create_index("ix_verification_tenant_subject", "verifications", ["tenant_id", "subject_id"])
    op.create_index("ix_verification_jobs", "verifications", ["status", "lease_until"])
    op.create_index("ix_verification_retention", "verifications", ["delete_after"])
    op.create_table(
        "callback_outbox",
        sa.Column("id", sa.String(26), primary_key=True),
        sa.Column("tenant_id", sa.String(26), nullable=False),
        sa.Column("payload", sa.JSON, nullable=False),
        sa.Column("attempts", sa.Integer, nullable=False),
        sa.Column("next_attempt", sa.DateTime(timezone=True), nullable=False),
        sa.Column("delivered_at", sa.DateTime(timezone=True)),
    )
    op.create_index("ix_callback_outbox_tenant_id", "callback_outbox", ["tenant_id"])
    op.create_index("ix_callback_due", "callback_outbox", ["delivered_at", "next_attempt"])


def downgrade() -> None:
    raise RuntimeError("Evidence deletion requires an approved retention/backup plan; roll forward")
