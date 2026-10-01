"""Validate artifact integrity and ONNX execution; this does not measure PAD accuracy."""

import argparse
import hashlib
from pathlib import Path

import cv2
import numpy as np
from install_liveness_model import SHA384, SIZE

parser = argparse.ArgumentParser()
parser.add_argument("directory", type=Path)
path = parser.parse_args().directory / "anti-spoof-mn3.onnx"
content = path.read_bytes()
assert len(content) == SIZE and hashlib.sha384(content).hexdigest() == SHA384
network = cv2.dnn.readNetFromONNX(str(path))
network.setInput(np.zeros((1, 3, 128, 128), dtype=np.float32))
output = network.forward().reshape(-1)
assert len(output) == 2 and np.isfinite(output).all()
assert np.all(output >= 0) and np.all(output <= 1) and abs(float(output.sum()) - 1) < 0.01
print(
    "Pinned anti-spoof model loaded and executed on synthetic input. "
    "Device/spoof evaluation remains required."
)
