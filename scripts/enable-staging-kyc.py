"""Apply the staging notice patch, preflight Laravel, and recreate only its app services."""

import json
import os
from pathlib import Path
import re
import runpy
import shutil
import subprocess
import sys
import tempfile
import time
from urllib.parse import urlsplit


def require(condition, message):
    if not condition:
        raise ValueError(message)


def update_env(path, updates):
    lines = path.read_text().splitlines()
    seen = set()
    result = []
    for line in lines:
        key = line.partition("=")[0]
        if key in updates:
            require(key not in seen, "Duplicate KYC configuration key; resolve it first.")
            seen.add(key)
            result.append(f"{key}='{updates[key]}'")
        else:
            result.append(line)
    result.extend(f"{key}='{value}'" for key, value in updates.items() if key not in seen)
    require(all("'" not in str(value) and "\n" not in str(value) for value in updates.values()),
            "Unsupported configuration value.")
    with tempfile.NamedTemporaryFile(mode="w", dir=path.parent, delete=False) as temporary:
        temporary.write("\n".join(result) + "\n")
        name = temporary.name
    os.chmod(name, 0o600)
    os.replace(name, path)


def policy_updates(origin, version, consent, retention):
    url = urlsplit(origin)
    require(url.scheme == "https" and url.hostname and not url.username and not url.password
            and not url.query and not url.fragment and url.path in {"", "/"},
            "APP_URL must be the staging HTTPS origin.")
    require(version.startswith("staging-") and len(version) <= 80, "Invalid staging consent version.")
    require(1 <= int(retention) <= 365, "Invalid processor retention setting.")
    return {
        "KYC_ENABLED": "true",
        "KYC_AUTOMATIC_VERIFICATION_ENABLED": "false",
        "KYC_ASSURANCE_PROFILE": "optical_v1",
        "KYC_CONSENT_VERSION": version,
        "KYC_CONSENT_TEXT": consent,
        "KYC_RETENTION_DAYS": str(retention),
        **{f"KYC_{page.upper()}_URL": origin.rstrip("/") + "/staging/kyc/" + page
           for page in ("privacy", "terms", "consent")},
    }


def main():
    require(os.name == "posix" and os.geteuid() == 0, "Run this on VPS3 as root.")
    root = Path(sys.argv[1] if len(sys.argv) > 1 else "/opt/vtsa-csms").resolve()
    bundle = Path(__file__).resolve().parent
    env_file = root / ".env.staging"
    read_env = runpy.run_path(str(bundle / "validate-kyc-deployment.py"))["read_env"]
    app = read_env(env_file)
    require(app.get("DEPLOY_ENVIRONMENT") == "staging", "This tool only supports DEPLOY_ENVIRONMENT=staging.")
    require(app.get("APP_ENV", "staging") in {"staging", "demo"}, "APP_ENV must be staging or demo.")
    kyc = read_env(Path("/etc/vtsa-csms/kyc-staging.env"))
    require(kyc.get("KYC_ENVIRONMENT") == "staging", "Processor environment is not staging.")

    def run(args, **kwargs):
        return subprocess.run(args, cwd=root, check=True, **kwargs)

    def capture(args):
        return run(args, capture_output=True, text=True).stdout.strip()

    container = "vtsa-csms-staging-platform-1"
    labels = json.loads(capture(["docker", "inspect", "--format", "{{json .Config.Labels}}", container]))
    require(labels.get("com.docker.compose.project") == "vtsa-csms-staging", "Unexpected application project.")
    files = labels.get("com.docker.compose.project.config_files", "").split(",")
    require(files and all(Path(file).is_file() for file in files), "The existing Compose files are unavailable.")
    require(str(root / "infra/compose.yaml") in files, "This helper expects the existing VPS3 infra/compose.yaml deployment.")
    overlay = str(root / "infra/compose.kyc.yaml")
    require(Path(overlay).is_file(), "The application KYC overlay is missing.")
    if overlay not in files:
        files.append(overlay)
    compose = ["docker", "compose", "--project-name", "vtsa-csms-staging", "--env-file", str(env_file)]
    for file in files:
        compose += ["-f", file]

    # A checked patch preserves unrelated server edits and refuses conflicting source.
    patch = bundle / "staging-kyc.patch"
    check = subprocess.run(["git", "apply", "--check", str(patch)], cwd=root, capture_output=True)
    if check.returncode == 0:
        run(["git", "apply", str(patch)])
    else:
        reverse = subprocess.run(["git", "apply", "--reverse", "--check", str(patch)], cwd=root, capture_output=True)
        require(reverse.returncode == 0, "Source differs from the patch. Stopped without overwriting server code.")

    controller = (root / "apps/platform/app/Http/Controllers/StagingKycPolicyController.php").read_text()
    version = re.search(r"public const VERSION = '([^']+)';", controller)
    consent = re.search(r"public const CONSENT = '([^']+)';", controller)
    require(version and consent, "Staging notice constants were not found.")
    updates = policy_updates(app.get("APP_URL", ""), version[1], consent[1], kyc.get("KYC_RETENTION_DAYS", "30"))
    for key in ("KYC_REQUEST_SECRET", "KYC_CALLBACK_SECRET"):
        require(len(app.get(key, "")) >= 32 and app.get(key) == kyc.get(key), "Service secrets must match the processor.")
    expected = "https://" + kyc["KYC_INTERNAL_HOST"] + ":" + kyc.get("KYC_PRIVATE_PORT", "8443")
    require(app.get("KYC_SERVICE_URL", "").rstrip("/") == expected, "Laravel must target the private KYC TLS ingress.")

    # Compose must read the protected file, not stale values exported in the SSH session.
    for key in app.keys() | updates.keys():
        os.environ.pop(key, None)
    backup = env_file.with_name(env_file.name + ".before-staging-notices-" + str(time.time_ns()))
    shutil.copy2(env_file, backup)
    os.chmod(backup, 0o600)
    update_env(env_file, updates)
    print("Protected environment backup:", backup, flush=True)

    try:
        run(compose + ["config", "--quiet"])
        print("Building only the staging Laravel services...", flush=True)
        run(compose + ["build", "platform", "worker", "scheduler"])
        preflight = """require '/app/vendor/autoload.php';
try {
    $app = require '/app/bootstrap/app.php';
    $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
    if (!config('kyc.enabled') || config('kyc.automatic_verification_enabled')
        || config('kyc.assurance_profile') !== 'optical_v1'
        || !in_array(config('app.env'), ['demo', 'staging'], true)) {
        throw new LogicException('Configuration mismatch');
    }
    echo "Staging Laravel KYC bootstrap passed.\\n";
} catch (Throwable $error) { echo "Bootstrap failed: ".get_class($error)."; details withheld.\\n"; exit(1); }
"""
        run(compose + ["run", "--rm", "--no-deps", "-T", "platform", "php", "-r", preflight])
        run(compose + ["up", "-d", "--no-deps", "--no-build", "--pull", "never", "--force-recreate",
                       "--wait", "--wait-timeout", "180", "platform", "worker", "scheduler"])
        for service in ("platform", "worker", "scheduler"):
            run(["docker", "exec", f"vtsa-csms-staging-{service}-1", "php", "-r", preflight])
        for page in ("privacy", "terms", "consent"):
            body = capture(["docker", "exec", container, "curl", "--fail", "--silent", "--show-error", "--max-time", "15",
                            app["APP_URL"].rstrip("/") + "/staging/kyc/" + page])
            require(version[1] in body and "STAGING ONLY" in body, "The HTTPS URL did not return the deployed staging notice.")
            print("HTTPS staging notice passed:", page, flush=True)
        run(["docker", "exec", container, "curl", "--fail", "--silent", "--show-error", "--max-time", "15",
             app["KYC_SERVICE_URL"].rstrip("/") + "/readyz"])
    except (subprocess.CalledProcessError, ValueError):
        print("Deployment check failed. Restoring Laravel with KYC disabled; processor remains running.", flush=True)
        update_env(env_file, {"KYC_ENABLED": "false"})
        subprocess.run(compose + ["up", "-d", "--no-deps", "--no-build", "--pull", "never", "--force-recreate",
                                 "--wait", "--wait-timeout", "180", "platform", "worker", "scheduler"], cwd=root, check=False)
        raise
    print("\nStaging notices deployed; KYC enabled with automatic approval OFF. Test submission and callback next.")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        print(str(error) if isinstance(error, ValueError) else "Operation failed; no configuration values displayed.", file=sys.stderr)
        sys.exit(1)
