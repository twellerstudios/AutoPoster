# BEAZER: official website

Built from the approved homepage in the *BEAZER Official Website Developer Package*. It's a real, responsive website with live text, links and forms, not a clickable screenshot.

| Deliverable | What it is |
|---|---|
| `dist/beazer-demo-<version>.zip` (current: 1.1.1) | **WordPress plugin for twellerstudios.com.** Serves the full demo at /beazertheband/ and sells the BEAZER T-shirts through WooCommerce. |
| `dist/beazer-wp-theme.zip` | WordPress theme for the band's own server later. **Don't install it on twellerstudios.com**, because it would replace the site's theme. |
| `screenshots/` | Every page at desktop (1536), tablet (768) and iPhone (390), plus a side-by-side comparison with the mockup. |
| `brand-assets/` | Transparent PNGs of the red and silver logos keyed from the files supplied, plus a 512 px icon. |

Pages: **Home · Merch (+ a page per T-shirt) · Tour · Fan Club · Media · About · Contact**. The content comes from the BEAZER concept page at twellerstudios.com/beazer/: the name story, the line-up (Joel Beazer, Obasi Springer, Jerome), the team, the Hutt Shutts show and Titan Music management.

## 1. Demo on twellerstudios.com/beazertheband/ (plugin)

1. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose `beazer-demo-<version>.zip`, then **Install Now** and **Activate**. To update later, upload the newer zip and choose **Replace current with uploaded**.
2. Open **https://www.twellerstudios.com/beazertheband/**.

**What the plugin changes, and what it doesn't:**
- It only answers addresses under `/beazertheband/`. It creates no pages and doesn't change the theme, menus or settings, so the rest of the site, including `/beazer/`, stays as it is.
- **Forms:** fan club sign-ups are listed under **BEAZER Demo** in the admin, with CSV export. Sign-ups and contact messages are emailed to **titanmusicgroupltd@gmail.com** through the site's own mailer (change this under BEAZER Demo → Settings).
- **Merch (needs WooCommerce):** on first admin page load after activation, the plugin creates the T-shirts as variable products: TT$250, sizes S–2XL, category "BEAZER Merch".
  - They're **hidden from the main shop and search**, so the Tweller store looks the same.
  - They're sold from `/beazertheband/merch/` and their own themed product pages, through the store's normal cart and checkout.
  - Each shirt has two shots: on the site's dark red stage (used on the demo) and on white (the store image for cart, checkout and emails). Product pages have an On black / On white switch.
  - BEAZER Demo → Settings → **Re-sync merch products** repairs them if they get edited.
- **Social links:** Facebook, Instagram, TikTok, YouTube and Spotify go to the official BEAZER profiles. Apple Music shows "coming soon".
- Deactivating the plugin takes the demo offline. The products stay in WooCommerce, still hidden.

Source: `demo-plugin/beazer-demo/`. To rebuild:
- `tools/build_merch.py <tee images> demo-plugin/beazer-demo` makes the black and white shots and the product list
- `tools/export_static.py <wp> wp-theme/beazer demo-plugin/beazer-demo/site --mode plugin` rebuilds the pages

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

- [ ] Apple Music / iTunes link (currently "coming soon")
- [ ] **LISTEN NOW**: point it at the debut record when released. Until then it goes to the Media page.
- [ ] Homepage feature panel: switch from the Hutt Shutts live recap to the debut record when it's out
- [ ] Featured video link (currently a first-jam still marked "film coming soon")
- [ ] T-shirt names, and confirm TT$250 and sizes S–2XL
- [ ] Higher-resolution originals of the band photos (current ones are 480–760 px), and Jerome's surname
- [ ] Domain / DNS for beazertheband.com when ready

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
