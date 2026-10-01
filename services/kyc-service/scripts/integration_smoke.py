"""Exercise real Laravel -> FastAPI -> worker -> signed callback using synthetic evidence."""

import os
import subprocess
import time
from io import BytesIO
from pathlib import Path

import httpx
from dotenv import load_dotenv
from PIL import Image, ImageDraw

from app.service import new_ulid

root = Path(__file__).resolve().parents[1]
load_dotenv(root / ".env")
password = os.environ["KYC_SMOKE_PASSWORD"]
tenant = "01J00000000000000000000001"
compose = [
    "docker",
    "compose",
    "-p",
    "vtsa-kyc-integration",
    "--env-file",
    ".env",
    "-f",
    "docker-compose.yml",
    "-f",
    "docker-compose.integration.yml",
]


def login(client: httpx.Client, name: str) -> dict[str, str]:
    response = client.post(
        "/api/v1/auth/login",
        json={
            "email": f"kyc.{name}@example.test",
            "password": password,
            "tenant_id": tenant,
            "device_name": "Synthetic integration test",
        },
    )
    assert response.status_code == 200, f"Login failed ({response.status_code})"
    return {"Authorization": "Bearer " + response.json()["data"]["token"], "X-Tenant-ID": tenant}


def picture() -> bytes:
    image = Image.new("RGB", (1400, 900), "white")
    draw = ImageDraw.Draw(image)
    for y in range(30, 860, 80):
        draw.text(
            (30, y), "SYNTHETIC KYC FIXTURE - NOT A REAL ID OR SELFIE", fill="navy", font_size=32
        )
    output = BytesIO()
    image.save(output, "JPEG")
    return output.getvalue()


class SmokeClient(httpx.Client):
    def request(self, method, url, **kwargs):
        for _ in range(3):
            response = super().request(method, url, **kwargs)
            if response.status_code != 429:
                return response
            time.sleep(min(60, max(1, int(response.headers.get("Retry-After", "60")))))
        return response


with SmokeClient(
    base_url="http://127.0.0.1:8091", timeout=45, headers={"Accept": "application/json"}
) as client:
    owner = login(client, "driver")
    other = login(client, "other")
    previous = client.get("/api/v1/kyc/status", headers=owner).json()["data"]
    if previous["status"] not in {
        "NOT_STARTED",
        "CANCELLED",
        "REJECTED",
        "ACTION_REQUIRED",
        "EXPIRED",
    }:
        cancelled = client.post(f"/api/v1/kyc/verification/{previous['id']}/cancel", headers=owner)
        assert cancelled.status_code == 200, "Could not reset the synthetic test user's attempt"
    for decision in ("REJECTED", "ACTION_REQUIRED", "APPROVED"):
        status = client.get("/api/v1/kyc/status", headers=owner).json()["data"]
        data = {
            "idempotency_key": new_ulid(),
            "consent": True,
            "consent_version": status["consent"]["version"],
            "document_type": "passport",
            "personal": {
                "full_name": "SYNTHETIC PERSON",
                "birth_date": "1990-01-01",
                "document_number": "TEST-123456",
                "expiration_date": "2035-01-01",
                "nationality": "PH",
                "issuing_country": "PH",
            },
        }
        route = "/api/v1/kyc/" + ("start" if status["status"] == "NOT_STARTED" else "resubmit")
        created = client.post(route, json=data, headers=owner)
        assert created.status_code == 201, f"Start failed ({created.status_code})"
        id = created.json()["data"]["id"]
        assert client.post(route, json=data, headers=owner).json()["data"]["id"] == id
        path = f"/api/v1/kyc/verification/{id}"
        assert client.get(path, headers=other).status_code == 404
        for kind in ("front", "selfie"):
            response = client.post(
                path + ("/selfie" if kind == "selfie" else "/document"),
                headers=owner,
                data={"kind": kind},
                files={"image": ("synthetic.jpg", picture(), "image/jpeg")},
            )
            assert response.status_code == 200, f"Upload failed ({response.status_code})"
        for _ in range(2):
            assert client.post(path + "/submit", headers=owner).status_code == 202
        deadline = time.monotonic() + 90
        while time.monotonic() < deadline:
            # show does not reconcile: reaching NEEDS_REVIEW proves actual callback delivery.
            response = client.get(path, headers=owner).json()["data"]
            if response["status"] == "NEEDS_REVIEW":
                break
            time.sleep(5)
        assert response["status"] == "NEEDS_REVIEW", "Signed callback was not applied"
        subprocess.run(  # noqa: S603 - fixed command, generated ID and enum; no shell
            [*compose, "exec", "-T", "kyc-platform", "php", "tests/kyc-smoke.php", decision, id],
            cwd=root,
            check=True,
            capture_output=True,
        )
        assert client.get(path, headers=owner).json()["data"]["status"] == decision
        print(f"Passed real upload, idempotency, ownership, worker, callback and {decision} flow.")
    assert client.get("/health/live").status_code == 200
    assert client.get("/api/v1/public/stations").status_code == 200
    assert client.get("/charging-map").status_code == 200
    print("Platform health, authentication and map endpoints remain available.")
