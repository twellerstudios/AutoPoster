# BEAZER: official website

Built from the approved homepage in the *BEAZER Official Website Developer Package*. It's a real, responsive website with live text, links and forms, not a clickable screenshot.

| Deliverable | What it is |
|---|---|
| `dist/beazer-staging-site.zip` | Static preview for the client. Upload to `twellerstudios.com/beazer/`. |
| `dist/beazer-wp-theme.zip` | WordPress theme for the client's own server. Install it once and the whole site sets itself up. |
| `screenshots/` | Desktop (1536), tablet (768) and iPhone (390) captures, plus a side-by-side comparison with the mockup. |
| `brand-assets/` | Transparent PNGs of the red and silver logos keyed from the files supplied, plus a 512 px icon. |

## 1. Staging preview on twellerstudios.com

1. Unzip `beazer-staging-site.zip`. You get a folder called `beazer/`.
2. Upload that folder into the web root of twellerstudios.com (usually `public_html/`) with cPanel File Manager or FTP.
3. Open **https://twellerstudios.com/beazer/**.

The preview is plain HTML/CSS/JS. All links are relative, so any sub-folder name works. Every page carries `noindex`, so search engines won't list it. The forms show a "preview only" notice because they need WordPress to store sign-ups and send email.

> If twellerstudios.com runs WordPress with a page or permalink already called `beazer`, use a different folder name, such as `beazer-preview/`.

## 2. Live site on the client's server (one-time setup)

Requirements: WordPress 6.3+ (tested on 7.1) and PHP 7.4+.

1. **Appearance → Themes → Add New → Upload Theme** and choose `beazer-wp-theme.zip`, then **Activate**.
2. Activation automatically:
   - creates the pages **Home, Merch, Tour, Fan Club, Media, About, Contact** (existing pages are never overwritten)
   - builds the main menu in the approved order: `HOME | MERCH | TOUR | FAN CLUB | MEDIA | ABOUT | CONTACT`
   - sets Home as the front page and turns on pretty permalinks
3. **Appearance → Customize → BEAZER** holds all homepage content: release title, cover, copy, streaming links, featured video (any YouTube link), the six platform links, button text and notification email.
4. **Appearance → BEAZER Setup** re-runs the setup at any time and shows the launch checklist.

Built-in features (no plugins needed):

- **Fan club sign-ups** are saved under the **Fan Club** admin menu, with a one-click CSV export, and emailed to the notification address.
- **Contact form** emails the notification address with Reply-To set to the sender.
- **Featured video** plays in a lightbox via `youtube-nocookie.com`, loaded only when pressed.
- **Cart icon** goes to the WooCommerce cart if WooCommerce is installed, otherwise to the Merch page.
- Shortcodes for any page: `[beazer_fan_club_form]`, `[beazer_contact_form]`, `[beazer_featured_video]`.

If the host doesn't send email reliably, add an SMTP plugin (e.g. WP Mail SMTP). The contact form tells visitors when sending fails instead of pretending it worked.

## Confirm with BEAZER before launch

These placeholders come from the mockup or are safe defaults. The spec says not to publish them without approval:

- [ ] Release: **STORM SEASON** title, cover art, copy, release date, and the Spotify / Apple Music / YouTube links for it
- [ ] Featured video link and thumbnail (currently the sample still from the mockup)
- [ ] Official profile URLs for Spotify, Apple Music/iTunes, YouTube, Instagram, TikTok and Facebook (currently platform searches for "BEAZER")
- [ ] Merch, Tour, About and Contact page copy (marked "Draft content")
- [ ] Notification email address for forms
- [ ] Full-resolution photos of Joel and a vector (SVG/AI) logo. The site uses the mockup artwork plus the keyed logo files supplied so far
- [ ] Domain / DNS access when ready to connect the owned domain

## Design notes and deliberate choices

- **Desktop (≥ 1100 px)** reproduces the 1536 × 1024 composition exactly. Every element is positioned in mockup pixels and scales with the window, so it lines up at any desktop width.
- **Below 1100 px** it switches to a stacked layout, as the spec asks:
  - collapsible menu
  - Joel and the silver logo kept visible
  - buttons and panels stacked
  - icons in a 3 × 2 grid on phones and a single row on tablets
- The artwork behind the page is the approved mockup with every flattened button, label and icon removed by AI inpainting. The live HTML elements sit where the baked-in ones were.
- **Top-left brand mark** uses the client's official red logo file instead of the low-resolution brush lettering in the mockup.
- **Platform icons** are official brand glyphs (Simple Icons, CC0) in each brand's colours. Facebook uses its current round logo; the mockup showed the older square one.
- **Fonts** are self-hosted Google Fonts (OFL), so nothing loads from Google: Noto Serif Condensed for the nav, Barlow Condensed for buttons and labels, Barlow for the tagline, Sedgwick Ave Display for the release title.

## Rebuilding the assets (`tools/`)

The image pipeline is scripted and repeatable. It needs Python with `torch`, `opencv-contrib-python-headless`, `pillow` and `numpy`, plus the LaMa weights (`big-lama.pt`):

```bash
python tools/inpaint.py  <mockup_1536.png> big-lama.pt work/inp       # remove flattened UI from the art
python tools/logos.py    <red_logo.jpg> <silver_logo.mp4> work/logos   # key logos onto transparency
python tools/brushes.py  work/brush                                    # brush-stroke button textures
python tools/export_web.py work wp-theme/beazer/assets/img             # responsive WebP/JPG set
python tools/gen_icons.py <simple-icons>/icons wp-theme/beazer/parts/icons.php
python tools/export_static.py http://localhost:8080 wp-theme/beazer staging-site [public_url]
```

`export_static.py` crawls a running WordPress install that uses this theme, so the static preview always matches the theme exactly.
