#!/usr/bin/env python3
"""Render TWO's app icons from the vendored Archivo font (fonttools, brotli, Pillow)."""

from io import BytesIO
from pathlib import Path

from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont
from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / "public/icons"
FONT = TTFont(ROOT / "public/fonts/archivo-latin.woff2")
FONT = instantiateVariableFont(FONT, {"wght": 800, "wdth": 100}, inplace=True)
FONT.flavor = None
FONT_BYTES = BytesIO()
FONT.save(FONT_BYTES)

# Canvas, text-primary and brand tokens from resources/css/two.css.
BACKGROUND = "#0b0714"
FOREGROUND = "#f3eeff"
ACCENT = "#c80154"


def render(size, safe_width):
    scale = 4
    edge = size * scale
    image = Image.new("RGB", (edge, edge), BACKGROUND)
    draw = ImageDraw.Draw(image)
    font_size = edge // 2
    while True:
        font = ImageFont.truetype(BytesIO(FONT_BYTES.getvalue()), font_size)
        left, top, right, bottom = draw.textbbox((0, 0), "TWO", font=font)
        if right - left <= edge * safe_width:
            break
        font_size -= 1
    x = (edge - (right - left)) / 2 - left
    y = (edge - (bottom - top)) / 2 - top - edge * 0.025
    draw.text((x, y), "TWO", fill=FOREGROUND, font=font)
    draw.rectangle(
        (edge * 0.38, edge * 0.64, edge * 0.62, edge * 0.67), fill=ACCENT
    )
    return image.resize((size, size), Image.Resampling.LANCZOS)


if __name__ == "__main__":
    OUTPUT.mkdir(parents=True, exist_ok=True)
    for size in (192, 512):
        render(size, 0.80).save(OUTPUT / f"icon-{size}.png", optimize=True)
    # Keep all non-background pixels within the central 80%-diameter safe circle.
    render(512, 0.65).save(OUTPUT / "maskable-512.png", optimize=True)
    render(180, 0.65).save(OUTPUT / "apple-touch-icon.png", optimize=True)
