from types import SimpleNamespace
from unittest.mock import MagicMock

import pytest

from app import deployment_check


@pytest.mark.parametrize(
    ("dependency", "stage"),
    [
        ("settings", "configuration"),
        ("providers", "models"),
        ("engine", "database"),
        ("storage", "storage"),
        ("http", "Laravel readiness"),
    ],
)
def test_failure_identifies_stage_without_exposing_exception_values(
    monkeypatch, capsys, dependency, stage
):
    config = SimpleNamespace(
        s3_bucket="test", callback_url="https://platform.test/api/v1/webhooks/kyc"
    )
    store = MagicMock(spec=deployment_check.S3Storage)
    store.client = MagicMock()
    mocks = {
        "settings": MagicMock(return_value=config),
        "providers": MagicMock(),
        "engine": MagicMock(),
        "storage": MagicMock(return_value=store),
        "http": MagicMock(),
    }
    for name in ("settings", "providers", "engine", "storage"):
        monkeypatch.setattr(deployment_check, name, mocks[name])
    monkeypatch.setattr(deployment_check.httpx, "Client", mocks["http"])
    mocks[dependency].side_effect = RuntimeError("private-password-and-applicant-data")
    assert deployment_check.main() == 1
    output = capsys.readouterr().err
    assert f"stage={stage}" in output
    assert "RuntimeError" in output
    assert "private-password-and-applicant-data" not in output
    later = list(mocks)[list(mocks).index(dependency) + 1 :]
    for name in later:
        mocks[name].assert_not_called()


def test_success_checks_every_dependency(monkeypatch, capsys):
    config = SimpleNamespace(
        s3_bucket="test", callback_url="https://platform.test/api/v1/webhooks/kyc"
    )
    store = MagicMock(spec=deployment_check.S3Storage)
    store.client = MagicMock()
    engine = MagicMock()
    client = MagicMock()
    monkeypatch.setattr(deployment_check, "settings", lambda: config)
    monkeypatch.setattr(deployment_check, "providers", MagicMock())
    monkeypatch.setattr(deployment_check, "engine", lambda: engine)
    monkeypatch.setattr(deployment_check, "storage", lambda: store)
    monkeypatch.setattr(deployment_check.httpx, "Client", client)
    assert deployment_check.main() == 0
    engine.connect.return_value.__enter__.return_value.execute.assert_called_once()
    store.client.head_bucket.assert_called_once_with(Bucket="test")
    client.return_value.__enter__.return_value.get.assert_called_once_with(
        "https://platform.test/health/ready"
    )
    assert "Laravel readiness" in capsys.readouterr().out
