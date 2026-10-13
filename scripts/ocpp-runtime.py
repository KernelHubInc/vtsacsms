#!/usr/bin/env python3
"""Switch an installed staging gateway between native systemd and Docker."""

import argparse
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import urllib.error
import urllib.request


def run(*args):
    result = subprocess.run(args, capture_output=True, text=True, check=False)
    if result.returncode:
        raise RuntimeError(f"{args[0]} failed; inspect service logs on the VPS.")
    return result.stdout.strip()


def atomic_write(path, text):
    path = Path(path)
    with tempfile.NamedTemporaryFile(mode="w", dir=path.parent, delete=False) as file:
        file.write(text)
        temporary = Path(file.name)
    try:
        temporary.chmod(0o644)
        temporary.replace(path)
    finally:
        temporary.unlink(missing_ok=True)


def upstream(mode, port):
    if mode not in {"native", "docker"} or not 1024 <= int(port) <= 65535:
        raise ValueError("Invalid runtime or private port.")
    return (
        f"# Managed by ocpp-runtime: {mode}\n"
        f"upstream vtsa_ocpp_active {{ server 127.0.0.1:{int(port)}; }}\n"
        f'map "" $vtsa_ocpp_runtime {{ default {mode}; }}\n'
    )


def active_mode(config):
    text = Path(config["upstream_file"]).read_text()
    for mode in ("native", "docker"):
        if text == upstream(mode, config["ports"][mode]):
            return mode
    raise RuntimeError("Unrecognized upstream configuration; refusing to overwrite it.")


def ready(port, mode=None):
    with urllib.request.urlopen(
        f"http://127.0.0.1:{port}/health/ready", timeout=3
    ) as response:
        payload = json.load(response)
        if (
            payload.get("status") != "ready"
            or payload.get("checks", {}).get("redis") != "ok"
        ):
            raise RuntimeError("Gateway or Redis is not ready.")
        if mode and response.headers.get("X-VTSA-OCPP-Runtime") != mode:
            raise RuntimeError("Nginx has not switched to the requested runtime.")
        return payload


def wait_ready(port, mode=None):
    deadline = time.monotonic() + 45
    while True:
        try:
            return ready(port, mode)
        except (OSError, ValueError, RuntimeError):
            if time.monotonic() >= deadline:
                raise RuntimeError("Gateway readiness timed out.") from None
            time.sleep(1)


def check_internal(config):
    # An invalid body verifies the authenticated command route without sending a command.
    token = json.loads(Path(config["environment_file"]).read_text())[
        "GATEWAY_INTERNAL_API_TOKEN"
    ]
    metrics = urllib.request.Request(
        f"http://127.0.0.1:{config['internal_port']}/internal/metrics",
        headers={"Authorization": f"Bearer {token}"},
    )
    with urllib.request.urlopen(metrics, timeout=5) as response:
        if response.status != 200:
            raise RuntimeError("Internal service authentication failed.")
    url = f"http://127.0.0.1:{config['internal_port']}/internal/v1/commands"
    request = urllib.request.Request(
        url,
        data=b"{}",
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {token}",
        },
    )
    try:
        urllib.request.urlopen(request, timeout=5).close()
    except urllib.error.HTTPError as error:
        if error.code == 422:
            return
    raise RuntimeError("Internal command route validation failed.")


def start(config, mode):
    if mode == "native":
        run("systemctl", "start", config["service"])
    else:
        run("docker", "start", config["container"])
    wait_ready(config["ports"][mode])


def select_boot_runtime(config, mode):
    if mode == "native":
        run("systemctl", "enable", config["service"])
        run("docker", "update", "--restart=no", config["container"])
    else:
        run("docker", "update", "--restart=unless-stopped", config["container"])
        run("systemctl", "disable", config["service"])


def stop(config, mode):
    if mode == "native":
        run("systemctl", "stop", config["service"])
    else:
        run("docker", "stop", "--time", "30", config["container"])


def switch(config, target):
    previous = active_mode(config)
    original = Path(config["upstream_file"]).read_text()
    start(config, target)
    try:
        atomic_write(config["upstream_file"], upstream(target, config["ports"][target]))
        run("nginx", "-t")
        run("systemctl", "reload", "nginx")
        wait_ready(config["internal_port"], target)
        check_internal(config)
        select_boot_runtime(config, target)
        # Closing the old process makes long-lived sockets reconnect to the new gateway.
        stop(config, "docker" if target == "native" else "native")
    except Exception:
        atomic_write(config["upstream_file"], original)
        start(config, previous)
        run("nginx", "-t")
        run("systemctl", "reload", "nginx")
        select_boot_runtime(config, previous)
        if target != previous:
            stop(config, target)
        raise
    print(f"Active OCPP runtime: {target}. Charger sockets reconnect after switching.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=["native", "docker", "status"])
    parser.add_argument("--config", default="/etc/vtsa-ocpp/runtime.json")
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise RuntimeError("Run with sudo.")
    import fcntl

    with open("/run/lock/vtsa-ocpp-runtime.lock", "w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        config = json.loads(Path(args.config).read_text())
        if args.action == "status":
            mode = active_mode(config)
            payload = ready(config["internal_port"], mode)
            print(json.dumps({"runtime": mode, "health": payload}, indent=2))
        else:
            switch(config, args.action)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, RuntimeError):
        print(
            "OCPP runtime operation failed. Check systemd, Nginx and Docker status; no secrets were printed."
        )
        raise SystemExit(1) from None
