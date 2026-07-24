# Tweller Bookings Mobile (v0.2)

The studio in your pocket. Manage every client and session from your phone —
pipeline dashboard, session timelines, culling proof uploads, and full-quality
gallery delivery straight from Lightroom Mobile exports.

## What it does

- **Home** — pipeline dashboard: active sessions, who's choosing photos,
  what needs your attention (receipts to verify, selections waiting on you),
  and the next shoots on the calendar.
- **Clients** — every session, searchable by name/email/shoot code, filterable
  by stage. Tap one to open the full session.
- **Session detail** — hero card, contact info (tap to call/email), payment
  summary with mark-as-paid, culling status with the client's picks, delivery
  gallery status, the full stage timeline, stage control, and notes.
- **Upload for Culling** — watermarked selection proofs. Photos are resized
  on the phone (2048px, same as the Lightroom export), grouped by capture-time
  gaps, matched against the shoot date, and the server adds the watermark.
  Sources: this phone's gallery, or the WD My Passport Wireless Pro.
- **Deliver to Gallery** — the finished photos. Files upload **exactly as
  exported — zero compression** — so export from Lightroom Mobile at full
  quality, pick them here, and they land in the client's delivery gallery.
  When the upload finishes, one tap marks the session Delivered and sends the
  gallery email.
- **Uploads** — a persistent queue (survives app restarts) showing the exact
  MB before anything moves. Wi-Fi-only by default; mobile data always asks
  first with the size shown.

## Run in a browser (quickest way to try it)

```bash
cd mobile
npm install
npm start          # opens http://localhost:8100
```

Enter your website URL + the automation API key (same one the Lightroom plugin
uses) under **Settings**. Browser builds can do everything except talk to the WD
box (browsers block cross-origin LAN reads) — use "Pick from this phone" there.

## Build the Android app (APK)

Requires Android Studio (or just its SDK + JDK 17).

```bash
cd mobile
npm install
npm run android:init    # once — creates android/
npm run android:sync    # after any change to www/
npm run android:open    # opens Android Studio; Build > Build APK
# or, with a device connected:
npm run android:run
```

The installed app uses Capacitor's native HTTP, so it can browse the WD drive at
`http://192.168.60.1` while the phone is on the WD's (internet-less) Wi-Fi.

## Field workflow

1. **Before leaving Wi-Fi**: open the app once — sessions and the dashboard cache.
2. At the shoot's end: open the client → **Upload for Culling** → pick from the
   phone or scan the WD. Photos group by capture time and check against the
   shoot date.
3. **Uploads** shows exactly how many MB the upload needs. Send it, then tap
   **Notify client** — they get the "choose your photos" email while you pack up.
4. After editing in Lightroom Mobile: export full-quality JPEGs → open the
   client → **Deliver to Gallery** → pick the exports → upload → **Mark
   delivered** sends the gallery email.

Server requirements: Tweller Bookings WP **3.13.0+** (adds the mobile
management endpoints: `/automation/overview`, `/automation/session/{code}`,
`/automation/session/{code}/update`).
