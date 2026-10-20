"""Load protected native settings without shell/systemd interpolation of secrets."""

import json
import os
from pathlib import Path
import sys

environment = json.loads(Path("/etc/vtsa-ocpp/gateway.json").read_text())
os.environ.update(environment)
os.environ["PYTHONDONTWRITEBYTECODE"] = "1"
os.environ["PYTHONUNBUFFERED"] = "1"
os.execv(sys.executable, [sys.executable, "-m", "vtsa_ocpp_gateway"])
