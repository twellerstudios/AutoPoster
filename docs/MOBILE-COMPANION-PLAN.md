# Tweller Bookings Mobile — Companion App & Fast-Delivery Pipeline

The goal: **shoot → proofs in the client's hands in minutes → selections → AI edit → deliver**,
with the phone doing the on-location work and the desktop doing the heavy lifting.

## What already exists (reuse, don't rebuild)

| Piece | Where | What it does |
|---|---|---|
| Proof upload + compress + watermark | WP `POST /culling/{code}/upload` | Server downscales to 1600px q60 and stamps the watermark — ANY client (LR, watcher, phone) gets identical output |
| Session list / find-or-create | WP `GET /automation/sessions`, `/automation/create-session` | Same endpoints the LR plugin uses |
| Mark selection gallery ready | WP `POST /culling/{code}/ready` | Emails client, advances stage to culling |
| Dedup check | WP `GET /culling/{code}/filenames` | Skip already-uploaded files on retry |
| EXIF session matching | `backend/src/services/exifService.js` + `sessionMatcherService.js` | Groups photos by capture time, matches to booked sessions (±2h window) |
| Lightroom auto-import | `backend/src/services/autoImportService.js` + `watcher/watcher.js` | Watches a folder, imports/organizes |
| AI editing | `backend/src/services/imagenService.js` | Imagen AI API (trained personal profile) with sharp fallback |
| Selections → XMP | WP `GET /culling/{code}/download-xmp` | Star ratings/labels back into Lightroom |

The mobile app is a **new front door to the same API** — the pipeline behind it stays as-is.

## The app: "Tweller Bookings Mobile"

**Stack recommendation: Capacitor + a single-page web UI** (same black & gold design
language as the plugin). One codebase gives:
- an installable **Android APK** (native HTTP, no CORS restrictions — required to talk
  to the WD box at `192.168.60.1` while the phone has no internet), and
- the same UI reusable in a browser for desktop debugging.

Native HTTP via `@capacitor-community/http`; background uploads via a foreground
service plugin so a 300-photo upload survives screen-off.

### Flow 1 — Ingest from WD My Passport Wireless Pro
1. Photographer joins the WD's Wi-Fi. App detects the box (probe `http://192.168.60.1`).
2. The WD exposes the SD card over HTTP (its web filesystem API at
   `/api/2.1/rest/…` — same API its own web UI uses) and over DLNA. The app lists
   `SD Card Imports/<date>/` folders, newest first.
3. **Session matching**: the app reads EXIF `DateTimeOriginal` from each JPEG header
   (fetch first 128KB only — EXIF lives at the front of the file; cheap over Wi-Fi).
   Port of `exifService.groupBySession`: photos within a 2-hour gap = one shoot.
   It then calls `GET /automation/sessions?date=YYYY-MM-DD` (cached from before
   leaving internet) and proposes: "128 photos, 2:14–3:05 PM → **JohnDoe-Mini**".
   Photographer confirms or picks another session (or find-or-create, like LR).
4. Import: copy selected JPEGs (shoot small+RAW; JPEGs are the proof source) to app
   storage. RAWs optionally copied too (see Flow 3).

### Flow 2 — Proofs to the culling portal
1. App resizes each JPEG on-device to 2048px q60 (canvas — same numbers the LR
   culling mode uses) so upload payloads are ~0.4–0.8 MB each.
2. **Data controls** (core requirement):
   - Default: upload only on Wi-Fi with internet (not the WD's LAN-only Wi-Fi).
   - Before starting, show the bill: "142 proofs ≈ **96 MB**. Upload now on
     mobile data / wait for Wi-Fi / upload later?"
   - Queue persists; auto-resumes when allowed connectivity appears.
3. Upload to `POST /culling/{code}/upload` (server watermarks/compresses — identical
   result to desktop path), then optionally `POST /culling/{code}/ready` → client
   gets the "choose your photos" email while you're packing up gear.

### Flow 3 — RAWs back to the desktop
Options considered:
- **Google Drive app folder**: zero code, but slow API, quota noise, no LAN speed.
- **Custom upload to backend** (`backend /api/import`): works, but RAW uploads over
  mobile data are exactly what we're avoiding.
- **Syncthing (recommended)**: free, open-source, encrypted P2P sync. Phone folder
  `TwellerRAW/<shoot-code>/` pairs with the desktop's watched import folder. On home
  Wi-Fi it syncs at LAN speed with zero cloud cost; the desktop watcher/autoImport
  then picks the files up and organizes/imports into Lightroom automatically.
- Pragmatic fallback: the SD card physically comes home anyway — the watcher already
  handles card-reader import. RAW-over-network is an optimization, not a blocker.

### Flow 4 — Close the loop (already built)
1. Client submits selections → session auto-advances to **Culled**.
2. Desktop: download XMP zip → `Read Metadata from Files` in Lightroom (or the
   watcher pulls `/culling/{code}/selections` automatically).
3. Selected RAWs → Imagen AI (`imagenService`) with the personal profile → edited
   exports land in the export folder.
4. Lightroom/LR plugin "Final client gallery" export → uploaded → **Delivered**
   (delivery email + review link).

### Build order (each step ships value on its own)
1. **v0.1** — Sessions list + manual folder pick from WD + proof upload queue with
   data controls. (The core "proofs in minutes" win.)
2. **v0.2** — EXIF auto-grouping + session matching + offline session cache.
3. **v0.3** — Background service, retry/dedup via `/filenames`, upload receipts UI.
4. **v0.4** — Syncthing RAW handoff + desktop watcher integration end-to-end.
5. **v0.5** — Polish: black/gold theme audit, PWA browser build, iOS if wanted.

### Design language
Same system as the emails and portal: black `#101010`, gold `#C9A227`, ivory
`#FAF7F2`, serif display headers, letter-spaced uppercase labels, card-based layout.
