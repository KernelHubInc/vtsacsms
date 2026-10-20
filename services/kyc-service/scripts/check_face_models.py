"""Exercise model loading/inference on blank synthetic input; not an accuracy evaluation."""

import argparse
import hashlib
from pathlib import Path

import cv2
import numpy as np
from install_face_models import MODELS

parser = argparse.ArgumentParser()
parser.add_argument("directory", type=Path)
directory = parser.parse_args().directory
for filename, (_, _, digest, _) in MODELS.items():
    with (directory / filename).open("rb") as stream:
        assert hashlib.file_digest(stream, "sha256").hexdigest() == digest
detector = cv2.FaceDetectorYN.create(str(directory / "yunet.onnx"), "", (320, 320))
recognizer = cv2.FaceRecognizerSF.create(str(directory / "sface.onnx"), "")
_, faces = detector.detect(np.zeros((320, 320, 3), dtype=np.uint8))
assert faces is None or len(faces) == 0
features = recognizer.feature(np.zeros((112, 112, 3), dtype=np.uint8))
assert features.size > 0 and np.isfinite(features).all()
features.fill(0)
print("Pinned models loaded and executed on synthetic input. No accuracy or liveness claim.")
