#!/usr/bin/env python3
"""Rebuild images/og-image.jpg (the link-preview card) from reference/image_preview.jpg.

Usage:
    python3 reference/build-og-image.py

Swap reference/image_preview.jpg for a different photo and re-run this to
update the card shown when the site link is shared (iMessage, WhatsApp,
Slack, etc.).

IMPORTANT — cache busting: Hostinger's CDN (and iMessage/WhatsApp/etc.)
cache the image by URL, and in testing the CDN ignores query-string cache
busters and can keep serving an old copy for a long time. So this script
writes a NEW versioned filename (og-image-v3.jpg, v4.jpg, ...) each run
instead of overwriting og-image.jpg. After running it, update the two
`https://babyjanedoe.com/images/og-image-vN.jpg` URLs in index.html
(og:image and twitter:image) to match the printed filename, then delete
the previous version's file so old copies don't pile up.
"""
import re
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont, ImageOps

REPO = Path(__file__).resolve().parent.parent
SOURCE_PHOTO = REPO / "reference" / "image_preview.jpg"
IMAGES_DIR = REPO / "images"


def next_output_path():
    existing = [p.name for p in IMAGES_DIR.glob("og-image-v*.jpg")]
    versions = [int(m.group(1)) for n in existing if (m := re.match(r"og-image-v(\d+)\.jpg", n))]
    next_version = max(versions, default=1) + 1
    return IMAGES_DIR / f"og-image-v{next_version}.jpg"


OUTPUT = next_output_path()

W, H = 1200, 630
LEFT_W = 600
BG = (144, 205, 209)     # #90CDD1
INK = (59, 46, 74)       # #3B2E4A
ACCENT = (244, 111, 48)  # #F46F30

FONT_REGULAR = "/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf"
FONT_ITALIC = "/usr/share/fonts/truetype/liberation/LiberationSerif-Italic.ttf"


def cover_crop(im, w, h, vertical_bias=1 / 3):
    """Crop im to exactly w x h, covering the frame (like CSS object-fit: cover).

    vertical_bias shifts a taller-than-needed crop up (toward faces near the
    top of a portrait photo) instead of dead-centering it; 0.5 = centered.
    """
    im = ImageOps.exif_transpose(im)
    src_w, src_h = im.size
    target_ratio = w / h
    src_ratio = src_w / src_h
    if src_ratio > target_ratio:
        new_w = int(src_h * target_ratio)
        x0 = (src_w - new_w) // 2
        im = im.crop((x0, 0, x0 + new_w, src_h))
    else:
        new_h = int(src_w / target_ratio)
        y0 = int((src_h - new_h) * vertical_bias)
        im = im.crop((0, y0, src_w, y0 + new_h))
    return im.resize((w, h), Image.LANCZOS)


def draw_tracked(draw, y, text, font, fill, tracking, center_x):
    width = sum(draw.textlength(ch, font=font) + tracking for ch in text) - tracking
    x = center_x - width / 2
    for ch in text:
        draw.text((x, y), ch, font=font, fill=fill)
        x += draw.textlength(ch, font=font) + tracking


def divider(draw, center_x, y, width=200):
    x0, x1 = center_x - width / 2, center_x + width / 2
    draw.line([(x0, y), (center_x - 13, y)], fill=(*INK, 90), width=1)
    draw.line([(center_x + 13, y), (x1, y)], fill=(*INK, 90), width=1)
    d = 6
    draw.polygon(
        [(center_x, y - d), (center_x + d, y), (center_x, y + d), (center_x - d, y)],
        fill=ACCENT,
    )


def main():
    canvas = Image.new("RGB", (W, H), BG)

    photo = Image.open(SOURCE_PHOTO).convert("RGB")
    photo = cover_crop(photo, W - LEFT_W, H)
    canvas.paste(photo, (LEFT_W, 0))

    draw = ImageDraw.Draw(canvas, "RGBA")
    f_label = ImageFont.truetype(FONT_REGULAR, 22)
    f_names = ImageFont.truetype(FONT_REGULAR, 80)
    f_amp = ImageFont.truetype(FONT_ITALIC, 27)
    f_date = ImageFont.truetype(FONT_REGULAR, 28)

    cx = LEFT_W / 2
    y = 128
    draw_tracked(draw, y, "SAVE THE DATE", f_label, INK, 6, cx)
    y += 42

    divider(draw, cx, y)
    y += 28

    justin_w = draw.textlength("Justin", font=f_names)
    draw.text((cx - justin_w / 2, y), "Justin", font=f_names, fill=INK)
    y += 84
    amp_w = draw.textlength("&", font=f_amp)
    draw.text((cx - amp_w / 2, y), "&", font=f_amp, fill=ACCENT)
    y += 40
    mik_w = draw.textlength("Mikaela", font=f_names)
    draw.text((cx - mik_w / 2, y), "Mikaela", font=f_names, fill=INK)
    y += 96

    divider(draw, cx, y)
    y += 32

    draw_tracked(draw, y, "APRIL 24, 2027", f_date, INK, 2, cx)
    y += 38
    draw_tracked(draw, y, "KNOXVILLE, TN", f_date, INK, 2, cx)

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    canvas.save(OUTPUT, "JPEG", quality=87, optimize=True)
    print(f"wrote {OUTPUT} ({OUTPUT.stat().st_size / 1024:.1f} KB)")


if __name__ == "__main__":
    main()
