"""Key the client's logo files onto transparency.

usage: python logos.py <red_logo.jpg> <silver_logo.mp4> <out_dir>
  - red logo (red chrome wordmark on black)  -> logo-red.png
  - silver logo video (animated, on black)   -> logo-silver.png (one still, sparkle removed)
"""
import subprocess
import sys
import tempfile
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw, ImageFilter


def key_on_black(rgb, gain):
    """Black background -> alpha; un-premultiply so edges keep their colour."""
    rgb = rgb.astype(np.float32)
    a = np.clip(rgb.max(-1) / gain, 0, 1)
    col = np.clip(rgb / np.maximum(a[..., None], 1e-3), 0, 255)
    a[a < 0.04] = 0
    out = np.dstack([col, a * 255]).astype(np.uint8)
    img = Image.fromarray(out, "RGBA")
    return img.crop(img.getchannel("A").point(lambda v: 255 if v > 12 else 0).getbbox())


def with_backing(logo, pad=24):
    """Lay a soft dark halo under the chrome so the sky never shows through the metal."""
    w, h = logo.size
    canvas = Image.new("RGBA", (w + 2 * pad, h + 2 * pad), (0, 0, 0, 0))
    a = Image.new("L", canvas.size, 0)
    a.paste(logo.getchannel("A").point(lambda v: 255 if v > 50 else 0), (pad, pad))
    halo = a.filter(ImageFilter.MaxFilter(9)).filter(ImageFilter.GaussianBlur(7)).point(lambda v: int(v * 0.82))
    canvas.putalpha(halo)
    canvas.alpha_composite(logo, (pad, pad))
    return canvas


def frame(video, t, tmp):
    p = Path(tmp) / f"f{t}.png"
    subprocess.run(["ffmpeg", "-v", "error", "-y", "-ss", str(t), "-i", video, "-frames:v", "1", str(p)], check=True)
    return np.asarray(Image.open(p).convert("RGB"))


def main(red_jpg, video, out_dir):
    out = Path(out_dir)
    out.mkdir(parents=True, exist_ok=True)
    key_on_black(np.asarray(Image.open(red_jpg).convert("RGB")), 150).save(out / "logo-red.png")

    with tempfile.TemporaryDirectory() as tmp:
        base, alt = frame(video, 3.0, tmp), frame(video, 4.6, tmp)
    # the 3.0s frame has one sparkle above the R; borrow that patch from the 4.6s frame
    m = Image.new("L", (base.shape[1], base.shape[0]), 0)
    ImageDraw.Draw(m).ellipse((622, 128, 718, 222), fill=255)
    m = np.asarray(m.filter(ImageFilter.GaussianBlur(6)), np.float32)[..., None] / 255
    still = base * (1 - m) + alt * m
    with_backing(key_on_black(still, 170)).save(out / "logo-silver.png")


if __name__ == "__main__":
    main(*sys.argv[1:4])
