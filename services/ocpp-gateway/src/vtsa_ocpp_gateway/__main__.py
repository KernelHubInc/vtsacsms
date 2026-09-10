from __future__ import annotations

import ssl

import uvicorn

from vtsa_ocpp_gateway.config import Settings
from vtsa_ocpp_gateway.logging import configure_logging


def main() -> None:
    settings = Settings.from_environment()
    configure_logging(settings.log_level)
    uvicorn.run(
        "vtsa_ocpp_gateway.app:app",
        host=settings.host,
        port=settings.port,
        access_log=False,
        log_config=None,
        proxy_headers=True,
        forwarded_allow_ips=settings.forwarded_allow_ips,
        ws_ping_interval=settings.heartbeat_interval_seconds,
        ws_ping_timeout=settings.heartbeat_interval_seconds,
        ssl_certfile=settings.tls_certificate_file,
        ssl_keyfile=settings.tls_private_key_file,
        ssl_ca_certs=settings.tls_client_ca_file,
        ssl_cert_reqs=ssl.CERT_REQUIRED if settings.require_client_certificate else ssl.CERT_NONE,
    )


if __name__ == "__main__":
    main()
