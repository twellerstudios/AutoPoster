"""Remove the flattened UI from the approved mockup with LaMa inpainting.

usage: python inpaint.py <mockup.png> <big-lama.pt> <out_dir>
Writes scene-desktop.png (wordmark kept), scene-clean.png (wordmark removed),
video-thumb.png and cover.png (sample release content).
"""
import sys
from pathlib import Path

import numpy as np
import torch
from PIL import Image, ImageDraw, ImageFilter

sys.path.insert(0, str(Path(__file__).parent))
import regions  # noqa: E402


def mask_for(rects, size, grow=0):
    m = Image.new("L", size, 0)
    d = ImageDraw.Draw(m)
    for x0, y0, x1, y1 in rects:
        d.rectangle((x0 - grow, y0 - grow, x1 + grow, y1 + grow), fill=255)
    return m


def lama(model, img, mask):
    x = torch.from_numpy(np.asarray(img, np.float32) / 255).permute(2, 0, 1)[None]
    m = torch.from_numpy((np.asarray(mask) > 127).astype(np.float32))[None, None]
    with torch.no_grad():
        out = model(x, m)[0].permute(1, 2, 0).clamp(0, 1).numpy()
    out = Image.fromarray((out * 255).round().astype(np.uint8))
    # keep untouched pixels bit-exact, feather the seam slightly
    soft = mask.filter(ImageFilter.GaussianBlur(1.5))
    return Image.composite(out, img, soft)


def main(src, weights, out_dir):
    out_dir = Path(out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    torch.set_num_threads(4)
    model = torch.jit.load(weights, map_location="cpu").eval()
    img = Image.open(src).convert("RGB")
    # small holes first so the crowd is rebuilt from its surroundings, then the panels
    hero_ui = regions.NAV + regions.TAGLINE + regions.BUTTONS + regions.ICONS
    desktop = lama(model, img, mask_for(hero_ui, img.size, grow=2))
    desktop = lama(model, desktop, mask_for(regions.RELEASE + regions.VIDEO, img.size, grow=2))
    desktop.save(out_dir / "scene-desktop.png")
    clean = lama(model, desktop, mask_for(regions.WORDMARK, img.size, grow=4))
    clean.save(out_dir / "scene-clean.png")
    # sample video still without its baked-in play button (the live one is HTML)
    cx, cy, r = regions.PLAY_BUTTON
    pm = Image.new("L", img.size, 0)
    ImageDraw.Draw(pm).ellipse((cx - r, cy - r, cx + r, cy + r), fill=255)
    lama(model, img, pm).crop(regions.VIDEO_THUMB).save(out_dir / "video-thumb.png")
    img.crop(regions.COVER).save(out_dir / "cover.png")
    mask_for(regions.UI + regions.WORDMARK, img.size).save(out_dir / "mask-debug.png")


if __name__ == "__main__":
    main(*sys.argv[1:4])
