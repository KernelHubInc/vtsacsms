"""Download versioned upstream models; no identity data is sent or model code executed."""

import argparse
import hashlib
import urllib.request
from pathlib import Path

COMMIT = "47534e27c9851bb1128ccc0102f1145e27f23f98"
MODELS = {
    "yunet.onnx": (
        "face_detection_yunet",
        "face_detection_yunet_2023mar.onnx",
        "8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4",
        232589,
    ),
    "sface.onnx": (
        "face_recognition_sface",
        "face_recognition_sface_2021dec.onnx",
        "0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79",
        38696353,
    ),
}


def install(directory: Path):
    directory.mkdir(parents=True, exist_ok=True)
    for filename, (folder, remote, digest, size) in MODELS.items():
        destination = directory / filename
        if destination.exists():
            with destination.open("rb") as stream:
                if hashlib.file_digest(stream, "sha256").hexdigest() != digest:
                    raise ValueError(
                        "Existing model differs from the pinned artifact; refusing to overwrite"
                    )
        else:
            url = f"https://media.githubusercontent.com/media/opencv/opencv_zoo/{COMMIT}/models/{folder}/{remote}"
            with urllib.request.urlopen(url, timeout=120) as response:  # noqa: S310 - fixed HTTPS upstream
                content = response.read(size + 1)
            if len(content) != size or hashlib.sha256(content).hexdigest() != digest:
                raise ValueError("Downloaded model does not match the pinned size and checksum")
            with destination.open("xb") as stream:
                stream.write(content)
        license_url = (
            f"https://raw.githubusercontent.com/opencv/opencv_zoo/{COMMIT}/models/{folder}/LICENSE"
        )
        with urllib.request.urlopen(license_url, timeout=30) as response:  # noqa: S310 - fixed HTTPS upstream
            license_text = response.read(100_000)
        (directory / f"{filename}.LICENSE").write_bytes(license_text)
        print(f"Verified {filename}: {digest}")
    print(
        "Models installed. Calibrate face thresholds before deployment; "
        "these models do not provide liveness or ID authenticity."
    )


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("directory", type=Path)
    install(parser.parse_args().directory)
