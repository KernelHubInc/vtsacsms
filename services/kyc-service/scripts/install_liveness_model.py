"""Install the Open Model Zoo anti-spoof-mn3 artifact after upstream integrity verification."""

import argparse
import hashlib
import urllib.request
from pathlib import Path

URL = "https://storage.openvinotoolkit.org/repositories/open_model_zoo/public/2022.1/anti-spoof-mn3/anti-spoof-mn3.onnx"
SHA384 = (
    "6de4534964b723397b3e8c995cadcf43bc007cc2f9930b95ae25f76adccece5d1d"
    "4d058d0b15117b9e4a9f758424f92a"
)
SIZE = 12270179


def install(directory: Path) -> None:
    directory.mkdir(parents=True, exist_ok=True)
    path = directory / "anti-spoof-mn3.onnx"
    if path.exists():
        content = path.read_bytes()
    else:
        with urllib.request.urlopen(URL, timeout=60) as response:  # noqa: S310 - fixed HTTPS upstream
            content = response.read(SIZE + 1)
    if len(content) != SIZE or hashlib.sha384(content).hexdigest() != SHA384:
        raise ValueError("Liveness model differs from the upstream checksum")
    if not path.exists():
        with path.open("xb") as stream:
            stream.write(content)
    license_path = Path(__file__).with_name("anti-spoof-mn3.LICENSE")
    (directory / "anti-spoof-mn3.onnx.LICENSE").write_bytes(license_path.read_bytes())
    print(f"KYC_LIVENESS_SHA256={hashlib.sha256(content).hexdigest()}")
    print(
        "Model installed. Evaluate presentation attacks and calibrate thresholds "
        "before enabling automatic approval."
    )


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("directory", type=Path)
    install(parser.parse_args().directory)
