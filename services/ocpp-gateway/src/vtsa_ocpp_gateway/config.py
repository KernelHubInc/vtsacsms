from __future__ import annotations

import os
from dataclasses import dataclass, field

from vtsa_ocpp_gateway.ids import new_ulid

MANDATORY_REDACTED_FIELDS = (
    "authorization",
    "certificate",
    "certificate_hash_data",
    "contract_id",
    "data",
    "emaid",
    "iccid",
    "id_tag",
    "id_token",
    "imsi",
    "password",
    "public_key",
    "security_event_tech_info",
    "signed_meter_data",
    "signed_meter_value",
    "tech_info",
)


def _as_bool(value: str | None, *, default: bool = False) -> bool:
    if value is None:
        return default
    return value.casefold() in {"1", "true", "yes", "on"}


def _csv(value: str | None, default: str = "") -> tuple[str, ...]:
    return tuple(
        item.strip()
        for item in (value if value is not None else default).split(",")
        if item.strip()
    )


@dataclass(frozen=True, slots=True)
class Settings:
    host: str = "0.0.0.0"
    port: int = 9000
    log_level: str = "INFO"
    environment: str = "local"
    node_id: str = field(default_factory=new_ulid)
    supported_subprotocols: tuple[str, ...] = ("ocpp2.0.1", "ocpp1.6")
    redis_url: str | None = None
    redis_key_prefix: str = "vtsa:local:ocpp"
    event_stream: str = "vtsa:local:ocpp:events:v1"
    quarantine_stream: str = "vtsa:local:ocpp:quarantine:v1"
    authorization_stream: str = "vtsa:local:ocpp:authorization-requests:v1"
    stream_max_length: int = 100_000
    connection_lease_seconds: int = 45
    message_deduplication_seconds: int = 86_400
    command_timeout_seconds: float = 30.0
    authorization_timeout_seconds: float = 5.0
    heartbeat_interval_seconds: int = 30
    maximum_clock_skew_seconds: int = 300
    maximum_message_bytes: int = 262_144
    charger_registry_json: str = "{}"
    allow_unauthenticated_development: bool = False
    require_tls: bool = True
    tls_certificate_file: str | None = None
    tls_private_key_file: str | None = None
    tls_client_ca_file: str | None = None
    require_client_certificate: bool = False
    trusted_client_certificate_fingerprint_header: str | None = None
    internal_api_token: str | None = None
    raw_message_logging: bool = True
    redacted_fields: tuple[str, ...] = MANDATORY_REDACTED_FIELDS
    data_transfer_allowlist: tuple[str, ...] = ()
    allowed_commands: tuple[str, ...] = ()
    forwarded_allow_ips: str = "127.0.0.1"

    def __post_init__(self) -> None:
        object.__setattr__(
            self,
            "redacted_fields",
            tuple(dict.fromkeys((*MANDATORY_REDACTED_FIELDS, *self.redacted_fields))),
        )
        if self.port < 1 or self.port > 65_535:
            raise ValueError("Gateway port must be between 1 and 65535")
        if not self.supported_subprotocols or any(
            protocol not in {"ocpp1.6", "ocpp2.0.1"} for protocol in self.supported_subprotocols
        ):
            raise ValueError("Only OCPP 1.6J and OCPP 2.0.1 subprotocols are supported")
        if self.connection_lease_seconds < 9:
            raise ValueError("Connection lease must be at least 9 seconds")
        if self.maximum_message_bytes < 1024:
            raise ValueError("Maximum OCPP message size must be at least 1024 bytes")
        if bool(self.tls_certificate_file) != bool(self.tls_private_key_file):
            raise ValueError("TLS certificate and private key must be configured together")
        if self.require_client_certificate and not self.tls_client_ca_file:
            raise ValueError("A client CA file is required when client certificates are mandatory")

    @classmethod
    def from_environment(cls) -> Settings:
        environment = os.getenv("GATEWAY_ENVIRONMENT", "local").casefold()
        redis_key_prefix = os.getenv("GATEWAY_REDIS_KEY_PREFIX", f"vtsa:{environment}:ocpp").rstrip(
            ":"
        )
        return cls(
            host=os.getenv("GATEWAY_HOST", "0.0.0.0"),
            port=int(os.getenv("GATEWAY_PORT", "9000")),
            log_level=os.getenv("GATEWAY_LOG_LEVEL", "INFO").upper(),
            environment=environment,
            node_id=os.getenv("GATEWAY_NODE_ID") or new_ulid(),
            supported_subprotocols=_csv(
                os.getenv("GATEWAY_SUPPORTED_SUBPROTOCOLS"), "ocpp2.0.1,ocpp1.6"
            ),
            redis_url=os.getenv("GATEWAY_REDIS_URL", "redis://redis:6379/0"),
            redis_key_prefix=redis_key_prefix,
            event_stream=os.getenv("GATEWAY_EVENT_STREAM", f"{redis_key_prefix}:events:v1"),
            quarantine_stream=os.getenv(
                "GATEWAY_QUARANTINE_STREAM", f"{redis_key_prefix}:quarantine:v1"
            ),
            authorization_stream=os.getenv(
                "GATEWAY_AUTHORIZATION_STREAM",
                f"{redis_key_prefix}:authorization-requests:v1",
            ),
            stream_max_length=int(os.getenv("GATEWAY_STREAM_MAX_LENGTH", "100000")),
            connection_lease_seconds=int(os.getenv("GATEWAY_CONNECTION_LEASE_SECONDS", "45")),
            message_deduplication_seconds=int(
                os.getenv("GATEWAY_MESSAGE_DEDUPLICATION_SECONDS", "86400")
            ),
            command_timeout_seconds=float(os.getenv("GATEWAY_COMMAND_TIMEOUT_SECONDS", "30")),
            authorization_timeout_seconds=float(
                os.getenv("GATEWAY_AUTHORIZATION_TIMEOUT_SECONDS", "5")
            ),
            heartbeat_interval_seconds=int(os.getenv("GATEWAY_HEARTBEAT_INTERVAL_SECONDS", "30")),
            maximum_clock_skew_seconds=int(os.getenv("GATEWAY_MAXIMUM_CLOCK_SKEW_SECONDS", "300")),
            maximum_message_bytes=int(os.getenv("GATEWAY_MAXIMUM_MESSAGE_BYTES", "262144")),
            charger_registry_json=os.getenv("OCPP_CHARGER_REGISTRY_JSON", "{}"),
            allow_unauthenticated_development=_as_bool(
                os.getenv("OCPP_DEVELOPMENT_ALLOW_UNAUTHENTICATED")
            ),
            require_tls=_as_bool(os.getenv("OCPP_REQUIRE_TLS"), default=True),
            tls_certificate_file=os.getenv("OCPP_TLS_CERTIFICATE_FILE") or None,
            tls_private_key_file=os.getenv("OCPP_TLS_PRIVATE_KEY_FILE") or None,
            tls_client_ca_file=os.getenv("OCPP_TLS_CLIENT_CA_FILE") or None,
            require_client_certificate=_as_bool(os.getenv("OCPP_TLS_REQUIRE_CLIENT_CERT")),
            trusted_client_certificate_fingerprint_header=os.getenv(
                "OCPP_TRUSTED_CLIENT_CERTIFICATE_FINGERPRINT_HEADER"
            )
            or None,
            internal_api_token=os.getenv("GATEWAY_INTERNAL_API_TOKEN") or None,
            raw_message_logging=_as_bool(os.getenv("GATEWAY_RAW_MESSAGE_LOGGING"), default=True),
            redacted_fields=_csv(
                os.getenv("GATEWAY_REDACTED_FIELDS"),
                ",".join(MANDATORY_REDACTED_FIELDS),
            ),
            data_transfer_allowlist=_csv(os.getenv("OCPP_DATA_TRANSFER_ALLOWLIST")),
            allowed_commands=_csv(os.getenv("OCPP_ALLOWED_COMMANDS")),
            forwarded_allow_ips=os.getenv("GATEWAY_FORWARDED_ALLOW_IPS", "127.0.0.1"),
        )
