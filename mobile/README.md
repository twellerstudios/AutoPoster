# Tweller Bookings Mobile (v0.3)

The studio in your pocket. Full client management from your phone — create
bookings, track the pipeline, upload culling proofs, deliver full-quality
galleries, curate the gallery before the client sees it, and handle print
orders.

## What it does

- **Home** — pipeline dashboard: tappable stat tiles (jump straight to the
  filtered client list), needs-your-attention, upcoming shoots, print orders,
  **＋ New booking**, and **Share booking link** (send the booking page to a
  client via any app).
- **Clients** — every session, cache-first (opens instantly, refreshes in the
  background), searchable, filterable by stage, ＋ New booking.
- **Session detail** — hero card, tap-to-call/email contact info, payment
  summary with mark-as-paid, culling status with the client's picks, delivery
  gallery, full stage timeline, stage control, notes — and delete (removes the
  session, proofs and gallery from the website, double-confirmed).
- **Upload for Culling** — watermarked selection proofs. Photos are resized
  on the phone (2048px, same as the Lightroom export), grouped by capture-time
  gaps, matched against the shoot date, and the server adds the watermark.
  Sources: this phone's gallery, or the WD My Passport Wireless Pro.
- **Upload to Gallery** — the finished photos. Files upload **exactly as
  exported — zero compression** (export from Lightroom Mobile at full quality
  first). Picked photos are queued instantly, so nothing is lost if you leave
  the screen.
- **Manage gallery** — see every delivered photo in-app before the client
  does, set the cover photo, tap to place its focal point, and delete photos
  (singly or in a batch).
- **Print orders** — every order from client galleries and the public print
  page: items with thumbnails, totals, one-tap status updates (with optional
  customer email), and a copy-ready print list for the lab.
- **Uploads** — a persistent queue (survives app restarts) showing the exact
  MB before anything moves. Wi-Fi-only by default; mobile data always asks
  first with the size shown. After a gallery upload finishes: view the gallery
  first, then mark delivered.

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
