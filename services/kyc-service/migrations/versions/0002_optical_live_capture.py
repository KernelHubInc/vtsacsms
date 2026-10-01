"""Snapshot assurance policy and encrypt short-lived live-capture state."""

import sqlalchemy as sa
from alembic import op

revision = "0002"
down_revision = "0001"


def upgrade() -> None:
    op.add_column(
        "verifications",
        sa.Column("assurance_profile", sa.String(24), nullable=False, server_default="issuer_v1"),
    )
    op.add_column("verifications", sa.Column("live_state", sa.Text, nullable=True))
    op.add_column(
        "verifications", sa.Column("live_expires_at", sa.DateTime(timezone=True), nullable=True)
    )
    op.create_index("ix_verification_live_expiry", "verifications", ["live_expires_at"])


def downgrade() -> None:
    raise RuntimeError("Retain assurance policy history; roll forward")
