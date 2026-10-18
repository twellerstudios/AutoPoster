"""Generate the ragged brush-stroke textures used behind buttons and labels.

usage: python brushes.py <out_dir>
"""
import sys
from pathlib import Path

import numpy as np
from PIL import Image, ImageFilter

SS = 2  # supersample for clean anti-aliased edges


def smooth_noise(rng, n, k):
    v = rng.standard_normal(n + 4 * k)
    ker = np.hanning(2 * k + 1)
    return np.convolve(v, ker / ker.sum(), "same")[2 * k: 2 * k + n]


def brush_mask(w, h, seed, ragged=0.05, tail=0.0, streak=0.025):
    """Alpha mask of a horizontal brush stroke, w x h (final pixels)."""
    rng = np.random.default_rng(seed)
    W, H = w * SS, h * SS
    ys = np.arange(H)[:, None]
    xs = np.arange(W)[None, :]
    # ragged left / right ends: per-row extents with fine horizontal streaks
    rows_l = smooth_noise(rng, H, 3 * SS) * ragged * W * 0.35 + smooth_noise(rng, H, 18 * SS) * ragged * W
    rows_r = smooth_noise(rng, H, 3 * SS) * ragged * W * 0.35 + smooth_noise(rng, H, 18 * SS) * ragged * W
    left = ragged * W * 1.6 + rows_l
    right = W - ragged * W * 1.6 - rows_r - tail * W * np.clip(smooth_noise(rng, H, 2 * SS) * 3 + 0.5, 0, 1)
    # slightly wavy top / bottom
    top = H * 0.06 + smooth_noise(rng, W, 25 * SS) * H * 0.05 + np.abs(smooth_noise(rng, W, 2 * SS)) * H * 0.04
    bot = H * 0.94 + smooth_noise(rng, W, 25 * SS) * H * 0.05 - np.abs(smooth_noise(rng, W, 2 * SS)) * H * 0.04
    m = (xs > left[:, None]) & (xs < right[:, None]) & (ys > top[None, :]) & (ys < bot[None, :])
    m = m.astype(np.float32)
    # dry-brush gaps near the ends
    gaps = (smooth_noise(rng, H, SS)[:, None] > 1.1 - streak * 10) & (
        (xs < left[:, None] + W * 0.06) | (xs > right[:, None] - W * 0.08))
    m[gaps] *= 0.15
    img = Image.fromarray((m * 255).astype(np.uint8)).resize((w, h), Image.LANCZOS)
    return np.asarray(img, np.float32) / 255, rng


def streak_texture(rng, w, h, amount):
    t = smooth_noise(rng, h, 4)[:, None] * 0.7 + smooth_noise(rng, h, 2)[:, None] * 0.3
    t = t + smooth_noise(rng, w, 40)[None, :] * 0.4
    return 1 - amount * np.clip(t, 0, None)


def red(w, h, seed, out, tail=0.0):
    a, rng = brush_mask(w, h, seed, tail=tail)
    grad = np.linspace(1.05, 0.8, h)[:, None]
    tex = streak_texture(rng, w, h, 0.1) * grad
    rgb = np.stack([235 * tex, 10 * tex + 2, 20 * tex + 4], -1)
    Image.fromarray(np.dstack([np.clip(rgb, 0, 255), a * 255]).astype(np.uint8), "RGBA").save(out)


def dark(w, h, seed, out):
    a, rng = brush_mask(w, h, seed, ragged=0.012, streak=-1)
    inner = np.asarray(Image.fromarray((a * 255).astype(np.uint8)).filter(ImageFilter.MinFilter(5)), np.float32) / 255
    edge = np.clip(a - inner, 0, 1)
    flicker = np.clip(0.55 + smooth_noise(rng, w, 6)[None, :] * 1.5, 0.15, 1) * np.clip(
        0.55 + smooth_noise(rng, h, 4)[:, None] * 1.5, 0.15, 1)
    edge = edge * flicker
    tex = streak_texture(rng, w, h, 0.3) * 14
    base = np.stack([tex, tex, tex], -1)
    rgb = base * (1 - edge[..., None]) + 215 * edge[..., None]
    alpha = np.clip(a * 0.94 + edge * 0.06, 0, 1)
    Image.fromarray(np.dstack([np.clip(rgb, 0, 255), alpha * 255]).astype(np.uint8), "RGBA").save(out)


if __name__ == "__main__":
    out = Path(sys.argv[1])
    out.mkdir(parents=True, exist_ok=True)
    red(520, 132, 7, out / "brush-red.png")
    red(440, 80, 11, out / "brush-label.png", tail=0.06)
    dark(580, 120, 3, out / "brush-dark.png")
