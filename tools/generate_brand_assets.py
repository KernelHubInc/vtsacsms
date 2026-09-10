"""Generate deterministic Power Solutions raster assets from the approved brand tokens."""

from pathlib import Path

from PIL import Image, ImageDraw, ImageFont


ROOT = Path(__file__).resolve().parents[1]
NAVY = "#12366B"
DARK_NAVY = "#081B35"
BLUE = "#2589BE"
TURQUOISE = "#57DDD2"
CYAN = "#45CFC7"
WHITE = "#FFFFFF"


def font(size: int, *, bold: bool = False, italic: bool = False) -> ImageFont.ImageFont:
    names = []
    if bold and italic:
        names.append("segoeuiz.ttf")
    elif bold:
        names.extend(["seguisb.ttf", "segoeuib.ttf"])
    elif italic:
        names.append("segoeuii.ttf")
    else:
        names.append("segoeui.ttf")

    for name in names:
        path = Path("C:/Windows/Fonts") / name
        if path.exists():
            return ImageFont.truetype(str(path), size=size)
    return ImageFont.load_default()


def draw_mark(draw: ImageDraw.ImageDraw, box: tuple[int, int, int, int], *, dark: bool = False) -> None:
    left, top, right, bottom = box
    scale = min((right - left) / 112, (bottom - top) / 108)
    origin_x = left + ((right - left) - (107 * scale)) / 2
    origin_y = top + ((bottom - top) - (96 * scale)) / 2
    tiles = [
        (0, 66, 30, 30, TURQUOISE),
        (8, 33, 32, 32, CYAN),
        (32, 54, 32, 32, BLUE),
        (39, 19, 34, 34, TURQUOISE),
        (65, 38, 34, 34, "#6ECFF2" if dark else BLUE),
        (73, 0, 34, 34, WHITE if dark else NAVY),
    ]
    for x, y, width, height, color in tiles:
        tile = (
            round(origin_x + x * scale),
            round(origin_y + y * scale),
            round(origin_x + (x + width) * scale),
            round(origin_y + (y + height) * scale),
        )
        draw.rounded_rectangle(tile, radius=max(2, round(8 * scale)), fill=color)


def icon(size: int) -> Image.Image:
    image = Image.new("RGB", (size, size), NAVY)
    draw = ImageDraw.Draw(image)
    margin = round(size * 0.17)
    draw_mark(draw, (margin, margin, size - margin, size - margin), dark=True)
    return image


def horizontal_logo(width: int = 1480, height: int = 300, *, dark: bool = False) -> Image.Image:
    image = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    draw = ImageDraw.Draw(image)
    draw_mark(draw, (12, 20, 254, height - 20), dark=dark)
    power_font = font(132, bold=True)
    solutions_font = font(102, italic=True)
    draw.text((274, 72), "POWER", fill=TURQUOISE if dark else CYAN, font=power_font, anchor="la")
    draw.text((750, 88), "SOLUTIONS", fill=WHITE if dark else BLUE, font=solutions_font, anchor="la")
    return image


def save(image: Image.Image, path: Path, *, size: tuple[int, int] | None = None) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    output = image.resize(size, Image.Resampling.LANCZOS) if size else image
    output.save(path, optimize=True)


def main() -> None:
    platform_branding = ROOT / "apps/platform/public/branding"
    save(icon(256), platform_branding / "power-solutions-favicon.png", size=(64, 64))
    save(icon(256), platform_branding / "power-solutions-apple-touch-icon.png", size=(180, 180))
    save(
        horizontal_logo(),
        platform_branding / "power-solutions-email-logo.png",
        size=(600, 122),
    )

    mobile_branding = ROOT / "apps/mobile/assets/branding"
    save(horizontal_logo(), mobile_branding / "power-solutions-logo-horizontal.png")
    save(horizontal_logo(dark=True), mobile_branding / "power-solutions-logo-horizontal-dark.png")
    save(icon(512), mobile_branding / "power-solutions-mark.png")

    web = ROOT / "apps/mobile/web"
    save(icon(512), web / "favicon.png", size=(64, 64))
    save(icon(512), web / "icons/Icon-192.png", size=(192, 192))
    save(icon(512), web / "icons/Icon-512.png", size=(512, 512))
    save(icon(512), web / "icons/Icon-maskable-192.png", size=(192, 192))
    save(icon(512), web / "icons/Icon-maskable-512.png", size=(512, 512))

    android_res = ROOT / "apps/mobile/android/app/src/main/res"
    for density, size in {"mdpi": 48, "hdpi": 72, "xhdpi": 96, "xxhdpi": 144, "xxxhdpi": 192}.items():
        save(icon(512), android_res / f"mipmap-{density}/ic_launcher.png", size=(size, size))

    ios_assets = ROOT / "apps/mobile/ios/Runner/Assets.xcassets"
    for path in (ios_assets / "AppIcon.appiconset").glob("*.png"):
        with Image.open(path) as current:
            target_size = current.size
        save(icon(1024), path, size=target_size)

    for path in (ios_assets / "LaunchImage.imageset").glob("*.png"):
        with Image.open(path) as current:
            target_size = current.size
        canvas = Image.new("RGBA", target_size, WHITE)
        draw = ImageDraw.Draw(canvas)
        side = min(target_size) * 0.72
        x = (target_size[0] - side) / 2
        y = (target_size[1] - side) / 2
        draw_mark(draw, (round(x), round(y), round(x + side), round(y + side)))
        save(canvas, path)


if __name__ == "__main__":
    main()
