"""Export the running WordPress site as a static staging preview.

usage: python export_static.py <wp_base_url> <theme_dir> <out_dir>
                                [--public-url URL] [--notify EMAIL] [--mode static|plugin]

Every page is fetched from the real theme, so the demo is exactly what the
WordPress install will show. Links become relative, so the folder works from
any sub-path (e.g. https://www.twellerstudios.com/beazertheband/). Forms post
to a bundled form.php (emails --notify, stores fan club sign-ups), and the
pages carry noindex.
"""
import argparse
import re
import shutil
import sys
import urllib.request
from pathlib import Path

PAGES = ["", "merch/", "tour/", "fan-club/", "media/", "about/", "contact/"]


def fetch(url):
    try:
        with urllib.request.urlopen(url) as r:
            return r.read().decode("utf-8")
    except urllib.error.HTTPError as e:  # the 404 page still has a body
        return e.read().decode("utf-8")


def rewrite(html, base, depth, public, mode="static"):
    """Make a WordPress page portable.

    static: relative links/assets, forms post to the bundled form.php.
    plugin: {{BASE}} / {{ASSETS}} / {{FORM}} tokens that the BEAZER Demo
            plugin fills in when it serves the page.
    """
    rel = "../" * depth
    if mode == "plugin":
        link_prefix, asset_prefix, og_prefix, form_action = "{{BASE}}", "{{ASSETS}}", "{{ASSETS}}", "{{FORM}}"
    else:
        link_prefix, asset_prefix, og_prefix, form_action = rel, rel + "assets/", public + "assets/", rel + "form.php"
    # inline WordPress core block styles (wp-includes is not part of the export)
    html = re.sub(
        r"<link rel='stylesheet' id='([\w-]+)' href='(" + re.escape(base) + r"/wp-includes/[^']+\.css)[^']*'[^>]*>",
        lambda m: f'<style id="{m.group(1)}">{fetch(m.group(2))}</style>',
        html,
    )
    assets = f"{base}/wp-content/themes/beazer/assets/"
    # social previews need an absolute image URL
    html = html.replace(f'content="{assets}', f'content="{og_prefix}')
    html = html.replace(assets, asset_prefix)
    html = re.sub(r"\?ver=[\w.\-]+", "", html)
    # drop WordPress-only head links
    html = re.sub(r'<link rel="canonical"[^>]*>\s*', "", html)
    html = re.sub(r"<link rel=['\"](?:alternate|EditURI|https://api\.w\.org/)['\"][^>]*>\s*", "", html)
    html = re.sub(r"<meta name='robots'[^>]*>", '<meta name="robots" content="noindex, nofollow">', html)
    # forms
    html = re.sub(r'action="[^"]*admin-post\.php"', f'action="{form_action}"', html)
    html = html.replace(f'action="{base}/"', 'action="#"')
    if mode == "plugin":
        # the plugin has its own handlers; the export-time nonce would be stale anyway
        html = html.replace('value="beazer_join"', 'value="beazer_demo_join"')
        html = html.replace('value="beazer_contact"', 'value="beazer_demo_contact"')
        html = re.sub(r'<input type="hidden" id="_beazer_nonce"[^>]*>', "", html)
        html = re.sub(r'<input type="hidden" name="_wp_http_referer"[^>]*>', "", html)
    # internal page links
    if mode == "plugin":
        html = re.sub(re.escape(base) + r"/([\w\-/]*)", lambda m: link_prefix + m.group(1), html)
    else:
        html = re.sub(re.escape(base) + r"/([\w\-/]*)", lambda m: (rel + m.group(1)) or "./", html)
        html = html.replace('href=""', 'href="./"')
    return html.replace("<html ", "<html data-static ", 1)


def main(base, theme, out, public, notify, mode="static"):
    base = base.rstrip("/")
    out = Path(out)
    if out.exists():
        shutil.rmtree(out)
    shutil.copytree(Path(theme) / "assets", out / "assets")
    if mode == "static":
        handler = (Path(__file__).parent / "static" / "form.php").read_text(encoding="utf-8")
        (out / "form.php").write_text(handler.replace("{{NOTIFY_EMAIL}}", notify), encoding="utf-8")
    for page in PAGES:
        html = rewrite(fetch(f"{base}/{page}"), base, page.count("/"), public, mode)
        dest = out / page / "index.html"
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_text(html, encoding="utf-8")
    (out / "404.html").write_text(rewrite(fetch(f"{base}/this-page-does-not-exist/"), base, 0, public, mode), encoding="utf-8")
    leftovers = [p for p in out.rglob("*.html") if base in p.read_text(encoding="utf-8")]
    if leftovers:
        sys.exit(f"absolute local URLs left in: {leftovers}")
    print(f"exported {len(PAGES) + 1} pages to {out}")


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("base")
    ap.add_argument("theme")
    ap.add_argument("out")
    ap.add_argument("--public-url", default="https://www.twellerstudios.com/beazertheband/")
    ap.add_argument("--notify", default="", help="email that receives form submissions (static mode)")
    ap.add_argument("--mode", choices=["static", "plugin"], default="static")
    a = ap.parse_args()
    if a.mode == "static" and not a.notify:
        ap.error("--notify is required in static mode")
    main(a.base, a.theme, a.out, a.public_url, a.notify, a.mode)
