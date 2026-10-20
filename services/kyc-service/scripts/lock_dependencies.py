"""Pin installed runtime dependencies, including Linux dependencies and requested extras."""

import importlib.metadata
import tomllib
from pathlib import Path

from packaging.requirements import Requirement
from packaging.utils import canonicalize_name

root = Path(__file__).resolve().parents[1]
project = tomllib.loads((root / "pyproject.toml").read_text())
pending = [Requirement(item) for item in project["project"]["dependencies"]]
seen: set[tuple[str, tuple[str, ...]]] = set()
pins: dict[str, str] = {}
while pending:
    requirement = pending.pop()
    key = canonicalize_name(requirement.name)
    identity = (key, tuple(sorted(requirement.extras)))
    if identity in seen:
        continue
    seen.add(identity)
    distribution = importlib.metadata.distribution(key)
    pins[key] = distribution.version
    for dependency in distribution.requires or []:
        parsed = Requirement(dependency)
        if not parsed.marker or any(
            parsed.marker.evaluate(
                {
                    "extra": extra,
                    "sys_platform": "linux",
                    "os_name": "posix",
                    "platform_system": "Linux",
                }
            )
            for extra in (requirement.extras or {""})
        ):
            pending.append(parsed)
(root / "requirements.lock").write_text(
    "# Generated from the installed, audited runtime dependency graph.\n"
    + "\n".join(f"{key}=={value}" for key, value in sorted(pins.items()))
    + "\n"
)
