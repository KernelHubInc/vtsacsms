from functools import lru_cache
from typing import Literal
from urllib.parse import urlsplit

from cryptography.fernet import Fernet
from pydantic import Field, SecretStr, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_prefix="KYC_", env_file=".env", extra="ignore", env_ignore_empty=True
    )
    environment: Literal["local", "testing", "staging", "production"] = "local"
    mode: Literal["mock", "local", "self_hosted", "provider"] = "local"
    database_url: SecretStr
    redis_url: SecretStr
    request_secret: SecretStr
    callback_secret: SecretStr
    encryption_key: SecretStr
    callback_url: str
    storage: Literal["local", "s3"] = "local"
    storage_root: str = "/data/private"
    s3_bucket: str = ""
    s3_endpoint: str | None = None
    s3_region: str = "ap-southeast-1"
    s3_kms_key: str | None = None
    retention_days: int = Field(default=30, ge=1, le=365)
    max_file_bytes: int = Field(default=6 * 1024 * 1024, ge=1024, le=10 * 1024 * 1024)
    min_dimension: int = Field(default=480, ge=100)
    min_sharpness: float = Field(default=18, ge=0)
    mock_outcome: Literal["APPROVED", "REJECTED", "NEEDS_REVIEW", "PROCESSING_ERROR"] = (
        "NEEDS_REVIEW"
    )
    provider_factory: str = ""
    face_detector_model: str = ""
    face_detector_sha256: str = ""
    face_recognizer_model: str = ""
    face_recognizer_sha256: str = ""
    face_accept_threshold: float | None = Field(default=None, gt=0, le=1)
    face_reject_threshold: float | None = Field(default=None, ge=-1, lt=1)
    liveness_model: str = ""
    liveness_sha256: str = ""
    liveness_accept_threshold: float | None = Field(default=None, gt=0.5, le=1)
    liveness_reject_threshold: float | None = Field(default=None, ge=0, lt=0.5)
    liveness_center_tolerance: float | None = Field(default=None, gt=0, lt=0.3)
    liveness_turn_threshold: float | None = Field(default=None, gt=0, lt=0.5)
    requests_per_minute: int = Field(default=120, ge=1)

    @model_validator(mode="after")
    def secure_configuration(self) -> "Settings":
        for secret in (self.request_secret, self.callback_secret):
            if len(secret.get_secret_value()) < 32:
                raise ValueError("Directional service secrets must contain at least 32 characters")
        if self.request_secret == self.callback_secret:
            raise ValueError("Use separate request and callback secrets")
        Fernet(self.encryption_key.get_secret_value().encode())
        if self.mode == "mock" and self.environment not in {"local", "testing"}:
            raise ValueError("Mock KYC is forbidden outside local/testing")
        if self.mode == "provider" and not self.provider_factory:
            raise ValueError("Provider mode requires an installed provider factory")
        if self.mode == "self_hosted":
            if not all(
                (
                    self.face_detector_model,
                    self.face_detector_sha256,
                    self.face_recognizer_model,
                    self.face_recognizer_sha256,
                )
            ):
                raise ValueError("Self-hosted face matching requires pinned local model files")
            if (
                self.face_accept_threshold is None
                or self.face_reject_threshold is None
                or self.face_reject_threshold >= self.face_accept_threshold
            ):
                raise ValueError(
                    "Self-hosted face matching requires calibrated decision thresholds"
                )
            if any((self.liveness_model, self.liveness_sha256)):
                if (
                    not self.liveness_model
                    or not self.liveness_sha256
                    or self.liveness_accept_threshold is None
                    or self.liveness_reject_threshold is None
                    or self.liveness_center_tolerance is None
                    or self.liveness_turn_threshold is None
                    or self.liveness_center_tolerance >= self.liveness_turn_threshold
                ):
                    raise ValueError("Liveness requires pinned models and evaluated thresholds")
        callback = urlsplit(self.callback_url)
        if callback.username or callback.password or callback.query or callback.fragment:
            raise ValueError("Callback URL must not contain credentials, query or fragment")
        if callback.scheme not in {"http", "https"} or not callback.hostname:
            raise ValueError("Invalid callback URL")
        if self.environment in {"staging", "production"}:
            if callback.scheme != "https" or self.storage != "s3":
                raise ValueError(
                    "Staging/production require HTTPS callbacks and private S3 storage"
                )
            if self.s3_endpoint and not self.s3_endpoint.startswith("https://"):
                raise ValueError("S3 transport must use TLS")
        if self.storage == "s3" and not self.s3_bucket:
            raise ValueError("S3 bucket is required")
        return self


@lru_cache
def settings() -> Settings:
    return Settings()
