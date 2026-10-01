"""Add generated, ignored credentials for the isolated synthetic integration harness."""

import base64
import secrets
from pathlib import Path

target = Path(__file__).resolve().parents[1] / ".env"
content = target.read_text()
values = {
    "KYC_PLATFORM_APP_KEY": "base64:" + base64.b64encode(secrets.token_bytes(32)).decode(),
    "KYC_SMOKE_PASSWORD": secrets.token_urlsafe(32),
}
with target.open("a") as stream:
    for key, value in values.items():
        if f"{key}=" not in content:
            stream.write(f"{key}={value}\n")
print("Prepared isolated integration credentials without printing them.")
