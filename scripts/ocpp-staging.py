"""Prepare and operate the OCPP overlay for the existing single-VPS staging stack."""

import argparse
import getpass
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import tempfile
from urllib.parse import quote, urlsplit

ROOT = Path(__file__).resolve().parents[1]
PRIVATE = ROOT / ".env.staging.ocpp"
SERVICES = ["ocpp-gateway", "ocpp-event-consumer", "ocpp-authorization-consumer"]
CORE = ["platform", "worker", "scheduler"]
ULID = re.compile(r"[0-7][0-9A-HJKMNP-TV-Z]{25}")
# ':' is valid in a gateway path but cannot be a Basic authentication username.
IDENTITY = re.compile(r"[A-Za-z0-9._-]{1,120}")


def compose(overlay=True):
    command = ["docker", "compose", "--env-file", str(ROOT / ".env.staging")]
    if overlay:
        command += ["--env-file", str(PRIVATE)]
    command += ["-f", str(ROOT / "infra/compose.yaml")]
    command += ["-f", str(ROOT / "infra/compose.kyc.yaml")]
    if overlay:
        command += ["-f", str(ROOT / "infra/compose.ocpp-staging.yaml")]
    return command


def run(arguments, *, capture=False, input_text=None):
    result = subprocess.run(
        arguments,
        cwd=ROOT,
        text=True,
        input=input_text,
        capture_output=capture,
        check=False,
    )
    if result.returncode:
        # Rendered Compose configuration and hash subprocesses contain secrets.
        raise ValueError(
            "Command failed; private configuration output was withheld. Check Docker and the env files."
        )
    return result.stdout if capture else None


def configuration(overlay=True):
    if overlay and not PRIVATE.is_file():
        raise ValueError(
            "Preparation is incomplete: run 'python3 scripts/ocpp-staging.py prepare' first."
        )
    return runtime_configuration(
        json.loads(
            run(
                compose(overlay)
                + ["--profile", "milestone2", "config", "--format", "json"],
                capture=True,
            )
        )
    )


def runtime_configuration(config):
    # Compose escapes dollars for re-use as Compose input, even in JSON output.
    for service in config["services"].values():
        for key, value in service.get("environment", {}).items():
            if isinstance(value, str):
                service["environment"][key] = value.replace("$$", "$")
    return config


def require_staging(config):
    environment = config["services"]["platform"]["environment"]
    if environment.get("APP_ENV") != "staging":
        raise ValueError("APP_ENV must be staging. No services were changed.")
    if environment.get("APP_DEBUG", "").lower() != "false":
        raise ValueError("Staging requires APP_DEBUG=false.")
    if not environment.get("APP_URL", "").startswith("https://"):
        raise ValueError("Staging requires an HTTPS APP_URL.")
    return environment


def dotenv(values):
    lines = []
    for key, value in values.items():
        if any(character in value for character in "'\r\n"):
            raise ValueError("Unsupported quote or newline in generated configuration.")
        # Single quotes preserve Argon2's dollar signs during Compose interpolation.
        lines.append(f"{key}='{value}'")
    return "\n".join(lines) + "\n"


def settings(environment, identity, tenant, charger, password_hash):
    if not IDENTITY.fullmatch(identity):
        raise ValueError(
            "Charge-point ID must contain only letters, digits, '.', '_' or '-'."
        )
    if not ULID.fullmatch(tenant) or not ULID.fullmatch(charger):
        raise ValueError(
            "Use the existing tenant and charging-station ULIDs from staging Assets."
        )
    password = environment.get("REDIS_PASSWORD", "")
    if not password:
        raise ValueError("The existing staging Redis password is missing.")
    host = environment.get("REDIS_HOST", "redis")
    port = int(environment.get("REDIS_PORT", "6379"))
    if not re.fullmatch(r"[A-Za-z0-9.-]+", host) or not 1 <= port <= 65535:
        raise ValueError("Expected a Redis DNS hostname/IPv4 address and valid port.")
    registry = {
        identity: {
            "tenant_id": tenant,
            "charger_id": charger,
            "enabled": True,
            "basic_password_hash": password_hash,
        }
    }
    return {
        "GATEWAY_REDIS_URL": f"redis://:{quote(password, safe='')}@{host}:{port}/0",
        "GATEWAY_INTERNAL_API_TOKEN": environment.get("OCPP_GATEWAY_INTERNAL_TOKEN")
        or secrets.token_hex(32),
        "OCPP_CHARGER_REGISTRY_JSON": json.dumps(registry, separators=(",", ":")),
    }


def prepare():
    if PRIVATE.exists():
        raise ValueError(
            ".env.staging.ocpp already exists; it was preserved. See the runbook for credential rotation."
        )
    environment = require_staging(configuration(False))
    identity = input("Existing staging charge-point ID: ").strip()
    tenant = input("Existing staging tenant ULID: ").strip()
    charger = input("Existing staging charging-station ULID: ").strip()
    settings(environment, identity, tenant, charger, "validation-only")
    password = getpass.getpass("Device's Basic authentication password (hidden): ")
    if len(password) < 16 or password != getpass.getpass("Confirm device password: "):
        raise ValueError("Passwords must match and contain at least 16 characters.")
    run(compose(False) + ["build", "ocpp-gateway"])
    password_hash = run(
        compose(False)
        + [
            "run",
            "--rm",
            "--no-deps",
            "-T",
            "ocpp-gateway",
            "python",
            "-c",
            "import sys; from argon2 import PasswordHasher; print(PasswordHasher().hash(sys.stdin.read()))",
        ],
        capture=True,
        input_text=password,
    ).strip()
    if not password_hash.startswith("$argon2id$"):
        raise ValueError("Gateway did not return an Argon2id hash.")
    content = dotenv(settings(environment, identity, tenant, charger, password_hash))
    descriptor = os.open(PRIVATE, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as output:
        output.write(content)
    print(
        "Created private .env.staging.ocpp. Existing staging credentials and data were retained."
    )
    print(
        f"Charger URL: {environment['APP_URL'].rstrip('/')}/ocpp/{identity}".replace(
            "https://", "wss://"
        )
    )


def add_registration(registry, identity, entry):
    if identity in registry:
        raise ValueError(
            "That charge-point ID is already enrolled; no credential was replaced."
        )
    if any(item.get("charger_id") == entry["charger_id"] for item in registry.values()):
        raise ValueError(
            "That charging-station ULID is already enrolled under another identity."
        )
    return {**registry, identity: entry}


def save_registry(registry, expected):
    lock = PRIVATE.with_name(PRIVATE.name + ".lock")
    descriptor = os.open(lock, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    os.close(descriptor)
    temporary = None
    try:
        if PRIVATE.is_symlink() or PRIVATE.read_text(encoding="utf-8") != expected:
            raise ValueError(
                "Private configuration changed during enrollment; rerun enroll."
            )
        replacement = dotenv(
            {"OCPP_CHARGER_REGISTRY_JSON": json.dumps(registry, separators=(",", ":"))}
        ).rstrip("\n")
        content, count = re.subn(
            r"^OCPP_CHARGER_REGISTRY_JSON=.*$",
            lambda _: replacement,
            expected,
            flags=re.MULTILINE,
        )
        if count != 1:
            raise ValueError(
                "Expected one single-line registry in the generated private configuration."
            )
        backup = PRIVATE.with_name(PRIVATE.name + ".backup." + secrets.token_hex(6))
        with os.fdopen(
            os.open(backup, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600),
            "w",
            encoding="utf-8",
            newline="\n",
        ) as output:
            output.write(expected)
        with tempfile.NamedTemporaryFile(
            mode="w",
            encoding="utf-8",
            newline="\n",
            dir=PRIVATE.parent,
            prefix=".env.staging.ocpp.",
            delete=False,
        ) as output:
            temporary = Path(output.name)
            os.chmod(temporary, 0o600)
            output.write(content)
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, PRIVATE)
        return backup
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)
        lock.unlink()


def enroll(config):
    expected = PRIVATE.read_text(encoding="utf-8")
    environment = require_staging(config)
    registry = json.loads(
        config["services"]["ocpp-gateway"]["environment"]["OCPP_CHARGER_REGISTRY_JSON"]
    )
    identity = input("Additional staging charge-point ID: ").strip()
    tenant = input("Existing staging tenant ULID: ").strip()
    charger = input("Existing staging charging-station ULID: ").strip()
    entry = json.loads(
        settings(environment, identity, tenant, charger, "validation-only")[
            "OCPP_CHARGER_REGISTRY_JSON"
        ]
    )[identity]
    add_registration(registry, identity, entry)
    password = getpass.getpass("Device's unique Basic password (hidden): ")
    if len(password) < 16 or password != getpass.getpass("Confirm device password: "):
        raise ValueError("Passwords must match and contain at least 16 characters.")
    entry["basic_password_hash"] = run(
        compose()
        + [
            "run",
            "--rm",
            "--no-deps",
            "-T",
            "ocpp-gateway",
            "python",
            "-c",
            "import sys; from argon2 import PasswordHasher; print(PasswordHasher().hash(sys.stdin.read()))",
        ],
        capture=True,
        input_text=password,
    ).strip()
    if not entry["basic_password_hash"].startswith("$argon2id$"):
        raise ValueError("Gateway did not return an Argon2id hash.")
    backup = save_registry(add_registration(registry, identity, entry), expected)
    print(f"Added {identity}; existing devices and service credentials were preserved.")
    print(f"Protected configuration backup: {backup.name}")
    print(
        "Run 'python3 scripts/ocpp-staging.py gateway-up' to apply. Existing devices will reconnect."
    )


def validate_service_status(raw):
    if raw.lstrip().startswith("["):
        rows = json.loads(raw)
    else:
        rows = [json.loads(line) for line in raw.splitlines() if line.strip()]
    for service in SERVICES:
        matches = [row for row in rows if row.get("Service") == service]
        if (
            len(matches) != 1
            or matches[0].get("State") != "running"
            or matches[0].get("Health") != "healthy"
        ):
            raise ValueError(
                f"{service} is missing, stopped or unhealthy. Run status/logs before connecting devices."
            )


def verify(config):
    states = run(
        compose() + ["ps", "--all", "--format", "json"] + SERVICES, capture=True
    )
    validate_service_status(states)
    print("Gateway and both core consumers are running and healthy.")
    run(
        compose()
        + [
            "exec",
            "-T",
            "ocpp-gateway",
            "python",
            "-c",
            "import urllib.request; print(urllib.request.urlopen('http://127.0.0.1:9000/health/ready', timeout=3).read().decode())",
        ]
    )
    origin = urlsplit(config["services"]["platform"]["environment"]["APP_URL"])
    if (
        origin.scheme != "https"
        or not origin.hostname
        or origin.username
        or origin.password
    ):
        raise ValueError("Expected an HTTPS APP_URL without credentials.")
    url = f"wss://{origin.netloc}/ocpp/VTSA-PROBE-{secrets.token_hex(8)}"
    # No registered identity or command is used, so this cannot replace a device socket.
    probe = """import asyncio, sys
from websockets.asyncio.client import connect
from websockets.exceptions import InvalidStatus
async def main():
    try:
        async with connect(sys.argv[1], subprotocols=['ocpp1.6'], proxy=None, open_timeout=15):
            print('FAIL: an unenrolled device was accepted. No OCPP messages were sent.')
            return 1
    except InvalidStatus as error:
        code = error.response.status_code
        if code == 403:
            print('TLS handshake succeeded; the unregistered device was rejected (403).')
            print('This does not prove authenticated device boot or core event processing.')
            return 0
        print(f'FAIL: WebSocket returned HTTP {code}. Check the staging HTTPS /ocpp/ proxy route and gateway startup.')
        return 1
    except Exception as error:
        print('FAIL: TLS/WebSocket connection failed: ' + type(error).__name__)
        return 1
sys.exit(asyncio.run(main()))
"""
    run(compose() + ["exec", "-T", "ocpp-gateway", "python", "-c", probe, url])


def validate(config):
    require_staging(config)
    services = config["services"]
    gateway = services["ocpp-gateway"]["environment"]
    expected = {
        "GATEWAY_ENVIRONMENT": "staging",
        "GATEWAY_SUPPORTED_SUBPROTOCOLS": "ocpp1.6",
        "OCPP_REQUIRE_TLS": "true",
        "OCPP_DEVELOPMENT_ALLOW_UNAUTHENTICATED": "false",
        "OCPP_ALLOWED_COMMANDS": "",
        "OCPP_DATA_TRANSFER_ALLOWLIST": "",
    }
    for key, value in expected.items():
        if gateway.get(key) != value:
            raise ValueError(f"Unsafe gateway setting: {key}")
    ports = services["ocpp-gateway"].get("ports", [])
    if (
        len(ports) != 1
        or ports[0].get("host_ip") != "127.0.0.1"
        or str(ports[0].get("published")) != "9002"
    ):
        raise ValueError("Gateway must publish only on 127.0.0.1:9002.")
    registry = json.loads(gateway["OCPP_CHARGER_REGISTRY_JSON"])
    if not isinstance(registry, dict) or not registry:
        raise ValueError("Enroll an existing staging charger first.")
    for identity, entry in registry.items():
        if not IDENTITY.fullmatch(identity) or not isinstance(entry, dict):
            raise ValueError("Invalid charger registration.")
        if not ULID.fullmatch(entry.get("tenant_id", "")) or not ULID.fullmatch(
            entry.get("charger_id", "")
        ):
            raise ValueError("Invalid registry tenant or charging-station ULID.")
        if entry.get("enabled") is not True or not entry.get(
            "basic_password_hash", ""
        ).startswith("$argon2id$"):
            raise ValueError(
                "Enrollment requires an enabled charger and an Argon2id password hash."
            )
    for service in CORE + SERVICES[1:]:
        environment = services[service]["environment"]
        if (
            environment.get("FEATURE_OCPP") != "true"
            or environment.get("FEATURE_REMOTE_CHARGING") != "false"
        ):
            raise ValueError(f"Unexpected OCPP feature gates on {service}.")
        for core_key, gateway_key in (
            ("OCPP_GATEWAY_REDIS_URL", "GATEWAY_REDIS_URL"),
            ("OCPP_GATEWAY_INTERNAL_TOKEN", "GATEWAY_INTERNAL_API_TOKEN"),
            ("OCPP_GATEWAY_REDIS_KEY_PREFIX", "GATEWAY_REDIS_KEY_PREFIX"),
            ("OCPP_GATEWAY_EVENT_STREAM", "GATEWAY_EVENT_STREAM"),
            ("OCPP_GATEWAY_AUTHORIZATION_STREAM", "GATEWAY_AUTHORIZATION_STREAM"),
        ):
            if (
                not gateway.get(gateway_key)
                or environment.get(core_key) != gateway[gateway_key]
            ):
                raise ValueError(f"Gateway/core mismatch: {service} {core_key}")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "action",
        choices=[
            "prepare",
            "enroll",
            "check",
            "up",
            "gateway-up",
            "verify",
            "status",
            "logs",
        ],
    )
    action = parser.parse_args().action
    if action == "prepare":
        prepare()
        return
    config = configuration()
    validate(config)
    if action == "enroll":
        enroll(config)
    elif action == "verify":
        verify(config)
    elif action == "gateway-up":
        run(
            compose()
            + [
                "up",
                "-d",
                "--no-deps",
                "--no-build",
                "--pull",
                "never",
                "--force-recreate",
                "--wait",
                "--wait-timeout",
                "120",
                "ocpp-gateway",
            ]
        )
    elif action == "up":
        container = run(compose() + ["ps", "-q", "platform"], capture=True).strip()
        if not container or "\n" in container:
            raise ValueError("Expected one running staging platform container.")
        labels = json.loads(
            run(
                ["docker", "inspect", "--format", "{{json .Config.Labels}}", container],
                capture=True,
            )
        )
        allowed_files = {
            str(ROOT / "infra" / filename)
            for filename in (
                "compose.yaml",
                "compose.kyc.yaml",
                "compose.ocpp-staging.yaml",
            )
        }
        active_files = set(
            labels.get("com.docker.compose.project.config_files", "").split(",")
        )
        if not active_files or not active_files.issubset(allowed_files):
            raise ValueError(
                "The running platform uses other Compose files. Preserve those overrides before deploying OCPP."
            )
        # Consumers use the deployed application image; container-only edits are not an image.
        platform_image = run(
            compose() + ["images", "-q", "platform"], capture=True
        ).strip()
        if not platform_image or "\n" in platform_image:
            raise ValueError(
                "Start the existing staging platform first; expected one application image."
            )
        run(
            [
                "docker",
                "image",
                "tag",
                platform_image,
                config["services"]["ocpp-event-consumer"]["image"],
            ]
        )
        run(compose() + ["build", "ocpp-gateway"])
        run(
            compose()
            + [
                "up",
                "-d",
                "--no-deps",
                "--no-build",
                "--pull",
                "never",
                "--wait",
                "--wait-timeout",
                "120",
            ]
            + CORE
            + SERVICES
        )
        print(
            "Services started. Complete the Nginx include and physical charger acceptance checks in the runbook."
        )
    elif action == "status":
        run(compose() + ["ps"] + SERVICES)
        run(
            compose()
            + [
                "exec",
                "-T",
                "ocpp-gateway",
                "python",
                "-c",
                "import urllib.request; print(urllib.request.urlopen('http://127.0.0.1:9000/health/ready', timeout=3).read().decode())",
            ]
        )
    elif action == "logs":
        run(compose() + ["logs", "--tail=100", "-f"] + SERVICES)
    else:
        print(
            "Staging configuration passed. TLS routing, asset binding and live hardware still require acceptance checks."
        )


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, KeyError) as error:
        # Do not print decoded registry values, URLs containing passwords or subprocess output.
        print(
            f"OCPP staging setup failed: {type(error).__name__}. {error if isinstance(error, ValueError) and not isinstance(error, json.JSONDecodeError) else 'Check private configuration and Docker availability.'}"
        )
        raise SystemExit(1) from None
