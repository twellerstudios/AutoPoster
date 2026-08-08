# Tweller Event Photo Engine

A native WordPress companion module for **Tweller Bookings WP**. It turns any
event into a crowd-sourced photo gallery: guests scan a QR code, upload photos
from their phones with **no app and no sign-up**, order prints through a custom
checkout (no WooCommerce), and earn a free print for sharing to socials. Free
galleries carry sleek "Powered by Tweller Studios" promo branding that drives
new bookings.

Built entirely in custom PHP/JS on the existing stack. **No WooCommerce, no
third-party e-commerce plugin, no paid SaaS.**

---

## What it does

| # | Feature | Where |
|---|---------|-------|
| 1 | **Event galleries** — CPT `tweller_event_gallery`, auto-created when a Tweller booking is *confirmed*, plus a public self-service creation form (email only). Per-event `is_monetized` / `pricing_tier` meta to switch on paid features later. Host-configured category buckets + guest sub-albums. | `class-tepe-cpt.php`, `class-tepe-gallery.php`, `class-tepe-categories.php`, `class-tepe-booking-hook.php` |
| 2 | **QR + zero-signup upload** — SVG/PNG QR codes to `/event/{slug}/upload/`, guest tracking via fingerprint + persistent cookie + hashed IP, mobile drag-drop / camera picker, chunked multi-file upload (JPEG/PNG/HEIC/HEIF), destination selector. | `class-tepe-qr.php`, `class-tepe-guest.php`, `class-tepe-uploads.php`, `tepe-fingerprint.js`, `tepe-app.js` |
| 3 | **Print engine + checkout** — per-photo "Order Print" + multi-select batch, slide-out drawer with sizes (4×6, 5×7, 8×10, 11×14…) & quantities, native checkout (name + phone + delivery), payment restricted to **Bank Transfer** and **Cash on Site / event-date delivery**. Catalog & bank details bridge the existing Tweller Print Engine. | `class-tepe-prints.php`, `class-tepe-rest.php` |
| 4 | **Free-tier promo engine** — sticky "Powered by Tweller Studios — Book a session" banner and in-grid promo cards every ~11 photos, linking to `/book-us/`. Only on galleries not created from a paid booking. | `class-tepe-frontend.php` |
| 5 | **Social-share reward engine** — "Share to socials" (Web Share API / intent), single-use promo code for a **Free 4×6 Print** on confirmed share, auto-applied in the print drawer. | `class-tepe-promo.php` |

---

## File structure

```
tweller-event-photo-engine/
├── tweller-event-photo-engine.php      Bootstrap, constants, activation, module boot
├── includes/
│   ├── class-tepe-database.php         Custom tables (uploads, guests, orders, promos, shares)
│   ├── class-tepe-qr.php               Self-contained QR encoder (SVG/PNG)
│   ├── class-tepe-cpt.php              tweller_event_gallery CPT + meta schema
│   ├── class-tepe-gallery.php          Gallery model: create, lookups, files, photo layer
│   ├── class-tepe-categories.php       Category buckets + guest sub-albums
│   ├── class-tepe-guest.php            Zero-signup device tracking
│   ├── class-tepe-uploads.php          Upload engine (simple + chunked), MIME/limits, thumbnails
│   ├── class-tepe-prints.php           Print bridge + native bank/cash checkout
│   ├── class-tepe-promo.php            Share-reward promo codes
│   ├── class-tepe-booking-hook.php     Ties into the Tweller Bookings lifecycle
│   ├── class-tepe-rewrite.php          Pretty URLs + QR image endpoint
│   ├── class-tepe-frontend.php         Gallery/upload/create rendering, banner, share meta
│   └── class-tepe-rest.php             REST API (nonce-guarded, rate-limited)
├── admin/
│   ├── class-tepe-admin.php            Admin screens under the Tweller menu
│   ├── css/tepe-admin.css
│   └── js/tepe-admin.js
└── public/
    ├── css/tepe.css
    └── js/
        ├── tepe-fingerprint.js
        └── tepe-app.js
```

## URLs

| URL | View |
|-----|------|
| `/event/{slug}/` | Public gallery (masonry, print drawer, share) |
| `/event/{slug}/upload/` | Guest upload page (QR target) |
| `/event/{slug}/qr.svg` · `qr.png` | QR image of the upload URL |
| `[tepe_create_event]` | Self-service gallery creation form (any page) |

## Data model

- **CPT** `tweller_event_gallery` — one post per event; all config in post meta.
- **`{prefix}tweller_event_uploads`** — every guest photo (category, dims, ip/fingerprint hashes, status).
- **`{prefix}tweller_event_guests`** — device identities + counters.
- **`{prefix}tweller_event_print_orders`** — native print orders (bank/cash).
- **`{prefix}tweller_event_promos`** — single-use share-reward codes.
- **`{prefix}tweller_event_shares`** — share action log.

> The brief named `wp_tweller_print_orders`, but Tweller Bookings already owns
> `{prefix}tweller_print_orders`. To avoid a live-data collision this module
> namespaces its orders table as `tweller_event_print_orders` and *bridges* the
> existing engine's catalog and bank details instead of duplicating them.

## Integration points (Tweller Bookings)

- Hooks `tweller_flow_2_payment_updated` → creates a booking-linked gallery when
  a session reaches the **confirmed** stage; `tweller_flow_2_session_created`
  (optional, off by default) pre-provisions galleries for event packages.
- Reuses `TwellerFlow2_Prints` (catalog, delivery fee, bank instructions) and
  `TwellerFlow2_Notifications` when present. Every integration degrades cleanly
  when Tweller Bookings is not installed.

## Security

- Real MIME sniffing (`finfo`) on every file; strict extension allow-list;
  30 MB per-file cap; per-request file count cap; per-guest / per-event limits.
- All state-changing REST routes require a valid `wp_rest` nonce.
- Honeypot field + per-IP rate limiting on uploads, orders, shares, creation.
- IPs and fingerprints are **salted-hashed** before storage — never stored raw.
- Randomised, non-guessable upload filenames.

## Install

1. Copy `tweller-event-photo-engine/` into `wp-content/plugins/`.
2. Activate **Tweller Event Photo Engine** (after Tweller Bookings, ideally).
3. Flush permalinks once (Settings → Permalinks → Save) if `/event/…/upload/`
   404s — activation flushes automatically, this is only a fallback.
4. Manage galleries under **Tweller Bookings → Event Galleries**.

## Requirements

- WordPress 5.8+, PHP 8.x.
- GD for JPEG/PNG thumbnails (bundled with WP hosts). **Imagick with HEIC
  support** is needed to thumbnail HEIC/HEIF; without it, HEICs are still stored
  and downloadable and the grid shows a graceful "tap to view" tile.
