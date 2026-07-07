# Tweller Bookings Mobile (v0.1)

On-location companion: pull JPEGs off the WD My Passport Wireless Pro (or the
phone), match them to the booked session by capture time, and upload watermarked
proofs to the client's selection portal — before you've packed the lights away.

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

1. **Before leaving Wi-Fi**: open the app once — the session list caches.
2. At the shoot's end, pop the SD card into the WD Wireless Pro, join its Wi-Fi.
3. **Sessions** → tap the shoot. **Import** → *Scan WD Wireless Pro* → open the
   card folder → *Import JPEGs*. Photos are grouped by capture-time gaps and
   checked against the shoot date.
4. **Queue** shows exactly how many MB the upload needs. Wi-Fi-only is the
   default — switching to mobile data always asks first with the size shown.
5. When the upload finishes: **Notify client** → they get the "choose your
   photos" email; the session auto-advances to Culling.

Uploads hit the same `culling/{code}/upload` endpoint as Lightroom and the
watcher — the server does the final compression and the watermark, so every
path produces identical proofs.
