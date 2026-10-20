"""Prepare private staging configuration and package an allowlisted source snapshot."""

import argparse
import base64
import hashlib
import importlib.util
import io
import os
import secrets
import socket
import tarfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = Path("services/kyc-service")
spec = importlib.util.spec_from_file_location(
    "kyc_validator", ROOT / "scripts/validate-kyc-deployment.py"
)
validator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(validator)


def runtime_files(root):
    files = [
        SERVICE / name
        for name in ("Dockerfile", "pyproject.toml", "requirements.lock", "alembic.ini")
    ]
    for directory in ("app", "migrations"):
        files.extend(
            path.relative_to(root)
            for path in (root / SERVICE / directory).rglob("*.py")
        )
    return sorted(files)


def write_archive(root, destination, files, *, context=False):
    with tarfile.open(destination, "w" if context else "w:gz") as archive:
        for relative in sorted(set(files)):
            source = root / relative
            if (
                source.is_symlink()
                or not source.is_file()
                or not source.resolve().is_relative_to(root.resolve())
            ):
                raise ValueError(
                    "Archive inputs must be regular files inside the source directory."
                )
            # Normalize Windows checkouts so bash and content tags agree on Linux.
            content = source.read_bytes().replace(b"\r\n", b"\n")
            name = (
                relative.relative_to(SERVICE)
                if context
                else Path("vtsa-kyc-staging") / relative
            )
            info = tarfile.TarInfo(name.as_posix())
            info.size = len(content)
            info.mode = 0o644
            archive.addfile(info, io.BytesIO(content))
    with destination.open("rb") as stream:
        digest = hashlib.sha256()
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def package(root, destination):
    files = runtime_files(root)
    files.extend(
        Path(name)
        for name in (
            "scripts/kyc-staging.sh",
            "scripts/kyc-staging.py",
            "scripts/deploy-kyc.sh",
            "scripts/validate-kyc-deployment.py",
            "infra/cluster/compose.kyc.yaml",
            "infra/cluster/kyc.Caddyfile",
            "infra/cluster/kyc.env.example",
            "docs/runbooks/kyc-staging-quickstart.md",
            "docs/runbooks/kyc-hostinger.md",
        )
    )
    files.extend(
        SERVICE / "scripts" / name
        for name in (
            "install_face_models.py",
            "install_liveness_model.py",
            "anti-spoof-mn3.LICENSE",
            "check_face_models.py",
            "check_liveness_model.py",
        )
    )
    if destination.exists():
        raise ValueError("Bundle already exists; choose a new output filename.")
    destination.parent.mkdir(parents=True, exist_ok=True)
    digest = write_archive(root, destination, files)
    with destination.with_name(destination.name + ".sha256").open(
        "w", encoding="utf-8", newline="\n"
    ) as stream:
        stream.write(f"{digest}  {destination.name}\n")
    return digest


def prepare(root, path):
    if path.exists() or path.is_symlink():
        raise ValueError("Staging environment already exists; it was not changed.")
    if path.name != "kyc-staging.env":
        raise ValueError("Use a dedicated kyc-staging.env file.")
    model_directory = path.parent / "kyc-models-staging"
    values = {
        "KYC_ENVIRONMENT": "staging",
        "KYC_DEPLOY_HOSTNAME": socket.gethostname(),
        "KYC_MODEL_DIRECTORY": str(model_directory),
        "KYC_REQUEST_SECRET": secrets.token_hex(32),
        "KYC_CALLBACK_SECRET": secrets.token_hex(32),
        "KYC_ENCRYPTION_KEY": base64.urlsafe_b64encode(
            secrets.token_bytes(32)
        ).decode(),
        "KYC_FACE_DETECTOR_MODEL": "/models/yunet.onnx",
        "KYC_FACE_DETECTOR_SHA256": "8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4",
        "KYC_FACE_RECOGNIZER_MODEL": "/models/sface.onnx",
        "KYC_FACE_RECOGNIZER_SHA256": "0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79",
        "KYC_LIVENESS_MODEL": "/models/anti-spoof-mn3.onnx",
        "KYC_LIVENESS_SHA256": "c4c99af04603b62d7e44f6f4daeb33e0daeccc696008c0b1d62f6f5cebbb3262",
        # Exploratory manual-review staging values, not calibrated acceptance criteria.
        "KYC_FACE_ACCEPT_THRESHOLD": "0.5",
        "KYC_FACE_REJECT_THRESHOLD": "0.2",
        "KYC_LIVENESS_ACCEPT_THRESHOLD": "0.9",
        "KYC_LIVENESS_REJECT_THRESHOLD": "0.1",
        "KYC_LIVENESS_CENTER_TOLERANCE": "0.1",
        "KYC_LIVENESS_TURN_THRESHOLD": "0.3",
    }
    lines = [
        "# Staging only. Fill remaining blanks and synchronize KYC settings with staging.env.",
        "# Thresholds below are UNCALIBRATED trial values for manual-review testing only.",
        "# Keep KYC_AUTOMATIC_VERIFICATION_ENABLED=false in Laravel. Never copy to production.",
    ]
    for line in (root / "infra/cluster/kyc.env.example").read_text().splitlines():
        key = line.partition("=")[0]
        if key in values:
            value = values[key]
            if any(char in value for char in ("'", "\n", "\r")):
                raise ValueError(
                    "Configuration path or hostname contains unsupported characters."
                )
            line = f"{key}='{value}'"
        lines.append(line)
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    model_directory.mkdir(exist_ok=True, mode=0o755)
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as stream:
        stream.write("\n".join(lines) + "\n")


def guard(path, app_path):
    values = validator.read_env(path)
    app = validator.read_env(app_path)
    validator.require(
        values.get("KYC_ENVIRONMENT") == "staging"
        and app.get("DEPLOY_ENVIRONMENT") == "staging",
        "Source builds are staging-only.",
    )
    validator.require(
        app.get("KYC_AUTOMATIC_VERIFICATION_ENABLED", "false").lower()
        in {"false", "0"},
        "Staging source testing requires KYC_AUTOMATIC_VERIFICATION_ENABLED=false.",
    )
    validator.require(
        values.get("KYC_MODE") == "self_hosted"
        and app.get("KYC_ASSURANCE_PROFILE") == "optical_v1",
        "Staging source testing requires self_hosted mode and optical_v1.",
    )
    for key in (
        "KYC_LIVENESS_MODEL",
        "KYC_LIVENESS_SHA256",
        "KYC_FACE_ACCEPT_THRESHOLD",
        "KYC_FACE_REJECT_THRESHOLD",
        "KYC_LIVENESS_ACCEPT_THRESHOLD",
        "KYC_LIVENESS_REJECT_THRESHOLD",
        "KYC_LIVENESS_CENTER_TOLERANCE",
        "KYC_LIVENESS_TURN_THRESHOLD",
    ):
        validator.require(
            bool(values.get(key)),
            f"Configure {key}; no biometric threshold is assumed.",
        )


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "action", choices=("prepare", "model-directory", "guard", "context", "package")
    )
    parser.add_argument("path", type=Path)
    parser.add_argument("app_path", type=Path, nargs="?")
    args = parser.parse_args()
    if args.action == "prepare":
        prepare(ROOT, args.path)
        print(
            "Created protected staging configuration. Fill its blanks, then follow the staging quickstart."
        )
    elif args.action == "model-directory":
        values = validator.read_env(args.path)
        validator.require(
            values.get("KYC_ENVIRONMENT") == "staging",
            "Staging configuration required.",
        )
        directory = Path(values.get("KYC_MODEL_DIRECTORY", ""))
        validator.require(
            directory.is_absolute() and directory.is_dir(),
            "Configured model directory is missing.",
        )
        print(directory)
    elif args.action == "guard":
        validator.require(
            args.app_path is not None,
            "Supply the protected Laravel staging environment.",
        )
        guard(args.path, args.app_path)
    elif args.action == "context":
        print(write_archive(ROOT, args.path, runtime_files(ROOT), context=True))
    else:
        print(package(ROOT, args.path))


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError) as error:
        # OSError may contain sensitive filesystem paths; only deliberate messages are shown.
        raise SystemExit(
            str(error)
            if isinstance(error, ValueError)
            else "Staging setup failed; check protected paths and permissions."
        ) from None
