"""Generate conspicuously synthetic OCR fixtures; never use real identities in tests."""

from pathlib import Path

from PIL import Image, ImageDraw

root = Path(__file__).resolve().parents[1] / "private" / "fixtures"
root.mkdir(parents=True, exist_ok=True)
image = Image.new("RGB", (1600, 1000), "white")
draw = ImageDraw.Draw(image)
draw.rectangle((15, 15, 1585, 985), outline="navy", width=8)
draw.text((55, 55), "SYNTHETIC TEST DOCUMENT - NOT VALID ID", fill="red", font_size=42)
draw.text(
    (55, 200),
    "NAME: SYNTHETIC PERSON\nDOB: 1990-01-01\nID NUMBER: TEST-123456\n"
    "EXPIRY: 2035-01-01\nISSUING COUNTRY: PH",
    fill="black",
    font_size=45,
    spacing=35,
)
image.save(root / "synthetic-document.jpg")
draw.text((55, 800), "MOCK SELFIE - NO REAL PERSON", fill="red", font_size=40)
image.save(root / "synthetic-selfie.jpg")
print("Synthetic document and mock selfie written to the ignored private/fixtures directory.")
