import base64
import binascii
import warnings
from io import BytesIO

from PIL import Image, ImageFilter, ImageOps, ImageStat, UnidentifiedImageError

from app.config import settings
from app.schemas import Evidence, Upload
from app.security import KycError

Image.MAX_IMAGE_PIXELS = 20_000_000


def normalize(upload: Upload) -> tuple[bytes, Evidence]:
    config = settings()
    try:
        raw = base64.b64decode(upload.content, validate=True)
    except (ValueError, binascii.Error) as exc:
        raise KycError("INVALID_IMAGE") from exc
    if len(raw) > config.max_file_bytes:
        raise KycError("FILE_TOO_LARGE", 413)
    try:
        with warnings.catch_warnings():
            warnings.simplefilter("error", Image.DecompressionBombWarning)
            with Image.open(BytesIO(raw)) as source:
                if source.format not in {"JPEG", "PNG"} or Image.MIME[source.format] != upload.mime:
                    raise KycError("INVALID_IMAGE_TYPE")
                if getattr(source, "n_frames", 1) != 1:
                    raise KycError("INVALID_IMAGE_TYPE")
                source.verify()
            with Image.open(BytesIO(raw)) as source:
                normalized = ImageOps.exif_transpose(source).convert("RGB")
                if min(normalized.size) < config.min_dimension:
                    raise KycError("IMAGE_RESOLUTION_TOO_LOW")
                normalized.thumbnail((2400, 2400))
                sample = normalized.convert("L")
                sample.thumbnail((800, 800))
                edges = sample.filter(ImageFilter.FIND_EDGES)
                sharpness = round(
                    ImageStat.Stat(edges.crop((2, 2, edges.width - 2, edges.height - 2))).var[0], 2
                )
                if sharpness < config.min_sharpness:
                    raise KycError("DOCUMENT_IMAGE_TOO_BLURRY")
                # Copy pixel data into a fresh image so EXIF/ICC/user metadata cannot survive.
                clean = Image.new("RGB", normalized.size)
                clean.paste(normalized)
                output = BytesIO()
                clean.save(output, format="JPEG", quality=90)
                data = output.getvalue()
                return data, Evidence(
                    kind=upload.kind,
                    width=clean.width,
                    height=clean.height,
                    bytes=len(data),
                    sharpness=sharpness,
                )
    except (
        UnidentifiedImageError,
        OSError,
        ValueError,
        Image.DecompressionBombError,
        Image.DecompressionBombWarning,
    ) as exc:
        raise KycError("INVALID_IMAGE") from exc
