"""Compatibility entry point for the approved artwork export pipeline."""

import shutil
import subprocess
from pathlib import Path

shell = shutil.which("pwsh") or shutil.which("powershell")
if shell is None:
    raise SystemExit("Run tools/generate_brand_assets.ps1 on Windows to export the supplied artwork.")
subprocess.run([shell, "-NoProfile", "-File", str(Path(__file__).with_suffix(".ps1"))], check=True)
