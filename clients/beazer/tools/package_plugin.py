"""Finish the BEAZER Demo plugin with the T-shirt images and zip it.

usage: python package_plugin.py <tee_images_dir> <output_dir> [--price 250] [--names names.json]

Runs build_merch.py on the images (black-stage + white shots, product list),
then writes <output_dir>/beazer-demo-<version>.zip, ready for
Plugins > Add New > Upload Plugin. Needs Python 3 with Pillow and NumPy:
    pip install pillow numpy
"""
import argparse
import re
import sys
import zipfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
PLUGIN = HERE.parent / "demo-plugin" / "beazer-demo"
sys.path.insert(0, str(HERE))
import build_merch  # noqa: E402


def main(images, output, price, names):
    images, output = Path(images), Path(output)
    if not any(p.suffix.lower() in {".png", ".webp"} for p in images.iterdir()):
        sys.exit(f"No PNG/WebP T-shirt images found in {images}")
    build_merch.main(images, PLUGIN, price, names)
    version = re.search(r"Version:\s*([\d.]+)", (PLUGIN / "beazer-demo.php").read_text(encoding="utf-8")).group(1)
    output.mkdir(parents=True, exist_ok=True)
    dest = output / f"beazer-demo-{version}.zip"
    with zipfile.ZipFile(dest, "w", zipfile.ZIP_DEFLATED) as z:
        for f in sorted(PLUGIN.rglob("*")):
            if f.is_file() and f.name != ".DS_Store":
                z.write(f, Path("beazer-demo") / f.relative_to(PLUGIN))
    print(f"\nPlugin ready: {dest}")


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("images")
    ap.add_argument("output")
    ap.add_argument("--price", default="250")
    ap.add_argument("--names")
    a = ap.parse_args()
    main(a.images, a.output, a.price, a.names)
