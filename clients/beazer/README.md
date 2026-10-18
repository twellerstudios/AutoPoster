# BEAZER: official website

Built from the approved homepage in the *BEAZER Official Website Developer Package*. It's a real, responsive website with live text, links and forms, not a clickable screenshot.

| Deliverable | What it is |
|---|---|
| `dist/beazertheband-demo.zip` | The full multi-page demo for **www.twellerstudios.com/beazertheband/**. Static pages plus a small form handler, completely separate from the Tweller WordPress site. |
| `dist/beazer-wp-theme.zip` | WordPress theme for the band's own server. Install it once and the whole site sets itself up with the same content. |
| `screenshots/` | Every page at desktop (1536), tablet (768) and iPhone (390), plus a side-by-side comparison with the mockup. |
| `brand-assets/` | Transparent PNGs of the red and silver logos keyed from the files supplied, plus a 512 px icon. |

Pages: **Home · Merch · Tour · Fan Club · Media · About · Contact**. The content comes from the BEAZER concept page at twellerstudios.com/beazer/: the name story, the line-up, the team, the Hutt Shutts show (3 Oct 2026) and Titan Music management.

## 1. Demo on twellerstudios.com/beazertheband/

1. In cPanel, open **File Manager → public_html**.
2. Upload `beazertheband-demo.zip` and choose **Extract**. You get a `beazertheband/` folder next to WordPress.
3. Open **https://www.twellerstudios.com/beazertheband/**.

This doesn't change anything in the Tweller WordPress site. WordPress only handles addresses that aren't real folders, so the existing `/beazer/` concept page stays as it is.

- All links are relative and every page carries `noindex`.
- **Fan club and contact forms work.** `form.php` emails each submission to hello@twellerstudios.com and saves fan club sign-ups to `beazertheband/data/fan-club.csv.php`, which can't be opened from a browser. Download that file in File Manager and delete its first line to open it as a spreadsheet.
- To change the email address, edit the `$notify` line at the top of `beazertheband/form.php`. If mail doesn't arrive, the host's PHP `mail()` is disabled: ask the host, or rebuild with `--notify` set to an address on the same domain.
- Social icons show "official profile coming soon" and point to the fan club until real profile links exist. No official BEAZER accounts were found online.

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

- [ ] Official Spotify, Apple Music, YouTube, Instagram, TikTok and Facebook links (currently "coming soon")
- [ ] **LISTEN NOW**: there's no released music yet, so it goes to the Media page. Point it at the debut record when it's out.
- [ ] The homepage feature panel is a live recap of Hutt Shutts, 3 Oct 2026. Switch it to the debut record in **Customize → BEAZER → Feature panel**.
- [ ] Featured video: a first-jam still marked "film coming soon". Add the YouTube link when the film is ready.
- [ ] Missing photos. Mark Dobson's portrait shows "MD" initials for now. On the concept page, the photos of Mark, Joel singing and the full band are corrupted, and three gallery images ("Two guitars", "Rhythm", "Back to the kit") never loaded. Originals at higher resolution would sharpen every photo, since the current ones are 480–760 px.
- [ ] Jerome's surname for the line-up
- [ ] Merch details when ready
- [ ] Notification email for the live site, plus full-resolution photos of Joel and a vector logo
- [ ] Domain / DNS access for beazertheband.com when ready

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
python tools/export_static.py http://localhost:8080 wp-theme/beazer beazertheband --notify you@example.com [--public-url URL]
```

`export_static.py` crawls a running WordPress install that uses this theme, so the static preview always matches the theme exactly.
