"""Export the intermediate artwork as responsive, web-optimised theme images.

usage: python export_web.py <work_dir> <theme_img_dir>
<work_dir> holds the outputs of inpaint.py, logos.py and brushes.py.
"""
import sys
from pathlib import Path

from PIL import Image

Q = 80


def save(img, out, name, widths, jpg=True, q=Q):
    for w in widths:
        h = round(img.height * w / img.width)
        im = img.resize((w, h), Image.LANCZOS) if w != img.width else img
        im.save(out / f"{name}-{w}.webp", quality=q, method=6)
        if jpg and w == widths[0]:
            im.convert("RGB").save(out / f"{name}-{w}.jpg", quality=84, optimize=True, progressive=True)


def fade_top(img, portion):
    """Darken the top of an image to pure black so it blends into the page."""
    h = round(img.height * portion)
    ramp = Image.linear_gradient("L").resize((img.width, h))  # black at top -> white
    mask = Image.new("L", img.size, 255)
    mask.paste(ramp, (0, 0))
    return Image.composite(img, Image.new("RGB", img.size, (0, 0, 0)), mask)


def main(work, out):
    work, out = Path(work), Path(out)
    out.mkdir(parents=True, exist_ok=True)
    inp = work / "inp"
    desktop = Image.open(inp / "scene-desktop.png").convert("RGB")
    clean = Image.open(inp / "scene-clean.png").convert("RGB")

    # desktop composition art, upscaled 2x for retina
    big = desktop.resize((3072, 2048), Image.LANCZOS)
    save(big, out, "scene-desktop", [1536, 2304, 3072])
    # stacked (tablet / phone) layout: Joel, then the dark tropical lower scene
    save(clean.crop((0, 30, 720, 650)).resize((1440, 1240), Image.LANCZOS), out, "scene-hero", [720, 1080, 1440])
    save(clean.crop((520, 330, 1536, 600)).resize((2032, 540), Image.LANCZOS), out, "scene-crowd", [1016, 2032])
    lower = clean.crop((0, 560, 1536, 1024)).resize((3072, 928), Image.LANCZOS)
    save(fade_top(lower, 0.45), out, "scene-lower", [1024, 1536, 3072])
    # inner-page banner: Joel + sky + crowd, without the wordmark
    save(clean.crop((0, 60, 1536, 560)).resize((3072, 1000), Image.LANCZOS), out, "scene-banner", [1024, 1536, 3072])
    # sample release content (to be replaced by the client)
    cover = Image.open(inp / "cover.png").convert("RGB")
    save(cover.resize((cover.width * 2, cover.height * 2), Image.LANCZOS), out, "sample-cover", [454])
    thumb = Image.open(inp / "video-thumb.png").convert("RGB")
    save(thumb.resize((thumb.width * 2, thumb.height * 2), Image.LANCZOS), out, "sample-video", [1190, 640])

    red = Image.open(work / "logos" / "logo-red.png")
    red = red.resize((640, round(red.height * 640 / red.width)), Image.LANCZOS)
    brand = out.parent.parent.parent.parent / "brand-assets"  # transparent PNGs for print/social use
    brand.mkdir(exist_ok=True)
    red.save(brand / "logo-red.png", optimize=True)
    red.save(out / "logo-red.webp", quality=90, method=6)
    silver = Image.open(work / "logos" / "logo-silver.png")
    silver.save(brand / "logo-silver.png", optimize=True)
    silver.save(out / "logo-silver.webp", quality=90, method=6)
    for name in ("brush-red", "brush-label", "brush-dark"):
        Image.open(work / "brush" / f"{name}.png").save(out / f"{name}.webp", quality=88, method=6)

    # social share image + icons
    desktop.crop((0, 40, 1536, 846)).resize((1200, 630), Image.LANCZOS).save(
        out / "og-image.jpg", quality=84, optimize=True, progressive=True)
    full = Image.open(work / "logos" / "logo-red.png")
    b = full.crop((0, 0, round(full.width * 0.25), full.height))  # the ".B" of the wordmark
    side = max(b.size)
    icon = Image.new("RGBA", (side, side), (0, 0, 0, 255))
    icon.alpha_composite(b, ((side - b.width) // 2, (side - b.height) // 2))
    icon.resize((512, 512), Image.LANCZOS).save(brand / "icon-512.png", optimize=True)
    icon.resize((180, 180), Image.LANCZOS).save(out / "apple-touch-icon.png", optimize=True)
    icon.resize((48, 48), Image.LANCZOS).save(out / "favicon.png", optimize=True)


if __name__ == "__main__":
    main(*sys.argv[1:3])
