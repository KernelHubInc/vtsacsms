"""Fail closed before a KYC deployment; never print environment values or secrets."""

import ipaddress
import json
import os
from pathlib import Path
import re
import socket
import ssl
import stat
import subprocess
import sys
from urllib.parse import parse_qs, urlsplit

PRIVATE = tuple(ipaddress.ip_network(value) for value in ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16"))


def require(condition, message):
    if not condition:
        raise ValueError(message)


def private_ip(value):
    try:
        address = ipaddress.ip_address(value)
        return address.version == 4 and any(address in network for network in PRIVATE)
    except ValueError:
        return False


def read_env(path):
    require(path.is_file(), "A required protected environment file is missing.")
    info = path.stat()
    require(stat.S_IMODE(info.st_mode) & 0o077 == 0, "Environment files must have mode 600 or 400.")
    require(info.st_uid in {0, os.geteuid()}, "Environment file ownership is invalid.")
    result = {}
    for line in path.read_text().splitlines():
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        key, separator, value = line.partition("=")
        require(separator and re.fullmatch(r"[A-Z][A-Z0-9_]*", key), "Invalid environment file syntax.")
        require(key not in result, "Duplicate environment key.")
        value = value.strip()
        quoted = len(value) >= 2 and value[0] == value[-1] == "'"
        require("$" not in value or quoted, "Values containing dollars must be single-quoted.")
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            value = value[1:-1]
        result[key] = value
    return result


def validate(values, app, environment, hostname, resolve):
    required = ("KYC_DEPLOY_HOSTNAME", "KYC_INTERNAL_HOST", "KYC_PRIVATE_BIND", "KYC_ALLOWED_CIDRS",
                "KYC_TLS_CERT", "KYC_TLS_KEY", "KYC_CA_BUNDLE", "KYC_DATABASE_URL", "KYC_CALLBACK_URL",
                "KYC_S3_ENDPOINT", "KYC_S3_BUCKET", "KYC_REQUEST_SECRET", "KYC_CALLBACK_SECRET",
                "KYC_ENCRYPTION_KEY", "AWS_ACCESS_KEY_ID", "AWS_SECRET_ACCESS_KEY", "KYC_MODEL_DIRECTORY")
    require(environment in {"staging", "production"}, "Invalid environment.")
    require(values.get("KYC_ENVIRONMENT") == environment, "KYC environment does not match the command.")
    require(app.get("DEPLOY_ENVIRONMENT") == environment, "Laravel environment does not match the command.")
    for key in required:
        require(bool(values.get(key)) and "CHANGE_ME" not in values[key], f"Configure {key}.")
    require(values["KYC_DEPLOY_HOSTNAME"] == hostname, "This environment belongs to another VPS hostname.")
    require(values.get("KYC_MODE") in {"local", "self_hosted"}, "This deployment permits only self-hosted processing.")
    require(not values.get("KYC_PROVIDER_FACTORY"), "Third-party provider factories are not permitted here.")
    profile = app.get("KYC_ASSURANCE_PROFILE", "optical_v1")
    require(profile in {"issuer_v1", "optical_v1"}, "Unknown KYC assurance profile.")
    enabled = app.get("KYC_ENABLED", "false").lower() in {"true", "1"}
    if enabled and profile == "optical_v1":
        require(values.get("KYC_MODE") == "self_hosted", "Optical KYC requires self-hosted live verification.")
        for key in ("KYC_LIVENESS_MODEL", "KYC_LIVENESS_SHA256", "KYC_LIVENESS_ACCEPT_THRESHOLD",
                    "KYC_LIVENESS_REJECT_THRESHOLD", "KYC_LIVENESS_CENTER_TOLERANCE", "KYC_LIVENESS_TURN_THRESHOLD"):
            require(bool(values.get(key)), f"Configure {key} for the optical live capture flow.")
    require(values.get("KYC_STORAGE") == "s3", "Private S3-compatible storage is required.")
    require(private_ip(values["KYC_PRIVATE_BIND"]), "KYC must bind to an RFC1918 private/VPN IPv4 address.")
    port = values.get("KYC_PRIVATE_PORT", "8443")
    require(port.isdigit() and 1024 <= int(port) <= 65535, "Use an unprivileged private ingress port.")
    require(re.fullmatch(r"[a-zA-Z0-9.-]+", values["KYC_INTERNAL_HOST"]), "Invalid internal DNS hostname.")
    for cidr in values["KYC_ALLOWED_CIDRS"].split():
        network = ipaddress.ip_network(cidr, strict=False)
        require(network.version == 4 and network.prefixlen >= 16 and
                any(network.subnet_of(parent) for parent in PRIVATE), "Ingress allowlists must be narrow private IPv4 ranges.")
    for key in ("KYC_REQUEST_SECRET", "KYC_CALLBACK_SECRET"):
        require(len(values[key]) >= 32, f"{key} must contain at least 32 characters.")
        require(values[key] == app.get(key), f"{key} differs from Laravel configuration.")
    require(values["KYC_REQUEST_SECRET"] != values["KYC_CALLBACK_SECRET"], "Directional secrets must differ.")
    expected = f"https://{values['KYC_INTERNAL_HOST']}:{port}"
    require(app.get("KYC_SERVICE_URL", "").rstrip("/") == expected, "Laravel must target this private KYC TLS ingress.")
    for key in ("KYC_CALLBACK_URL", "KYC_S3_ENDPOINT", "KYC_DATABASE_URL"):
        url = urlsplit(values[key])
        require(bool(url.hostname) and not url.fragment, f"Invalid {key}.")
        if key == "KYC_DATABASE_URL":
            require(url.scheme == "postgresql+psycopg", "Use the psycopg PostgreSQL URL scheme.")
            require(url.path == f"/vtsa_kyc_{environment}" and url.username == f"vtsa_kyc_{environment}",
                    "Use the dedicated environment KYC database and role.")
            query = parse_qs(url.query)
            require(query.get("sslmode") == ["verify-full"] and query.get("sslrootcert") == ["/run/kyc/ca.pem"],
                    "KYC PostgreSQL must verify the server certificate and hostname.")
        else:
            require(url.scheme == "https" and not url.username and not url.password and not url.query,
                    f"{key} must use HTTPS without embedded credentials or a query.")
        if key == "KYC_CALLBACK_URL":
            require(url.path == "/api/v1/webhooks/kyc", "Invalid Laravel callback path.")
        addresses = resolve(url.hostname)
        require(addresses and all(private_ip(item) for item in addresses), f"{key} must resolve only over the private network.")
    addresses = resolve(values["KYC_INTERNAL_HOST"])
    require(addresses and all(item == values["KYC_PRIVATE_BIND"] for item in addresses),
            "Internal KYC DNS must resolve to this private bind address.")


def main():
    environment, filename, app_filename = sys.argv[1:]
    path = Path(filename)
    values = read_env(path)
    app = read_env(Path(app_filename))
    resolve = lambda host: {item[4][0] for item in socket.getaddrinfo(host, None)}
    validate(values, app, environment, socket.gethostname(), resolve)
    for key in ("KYC_TLS_CERT", "KYC_TLS_KEY", "KYC_CA_BUNDLE"):
        require(Path(values[key]).is_file(), f"{key} file is missing.")
    require(Path(values["KYC_MODEL_DIRECTORY"]).is_dir(), "Model directory is missing.")
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(values["KYC_TLS_CERT"], values["KYC_TLS_KEY"])
    ssl.create_default_context(cafile=values["KYC_CA_BUNDLE"])
    interfaces = json.loads(subprocess.check_output(["ip", "-j", "address", "show"]))
    require(any(item.get("local") == values["KYC_PRIVATE_BIND"] for interface in interfaces
                for item in interface.get("addr_info", [])), "Private bind address is not assigned on this VPS.")
    other_environment = "staging" if environment == "production" else "production"
    other_path = path.with_name(f"kyc-{other_environment}.env")
    if other_path.is_file():
        other = read_env(other_path)
        for key in ("KYC_DATABASE_URL", "KYC_S3_BUCKET", "KYC_REQUEST_SECRET", "KYC_CALLBACK_SECRET", "KYC_ENCRYPTION_KEY", "AWS_ACCESS_KEY_ID"):
            require(values[key] != other.get(key), f"{key} must differ between environments.")
    print("Private network and environment isolation configuration passed.")


if __name__ == "__main__":
    try:
        main()
    except ValueError as error:
        print(f"KYC preflight: {error}", file=sys.stderr)
        sys.exit(1)
    except Exception:
        print("KYC preflight failed: check protected files, DNS, interface and TLS configuration.", file=sys.stderr)
        sys.exit(1)
