# AutoPoster

AI-powered content publishing hub for photographers. Auto-ingest photos from SD card, sort by booking, and publish SEO-optimised blog posts to WordPress.

## Tweller Flow

```
SD Card Insert → Auto-Import & Sort by Booking → Imagen AI (cull & edit) → Lightroom → AutoPoster → WordPress
```

1. **Insert SD card** — AutoPoster detects the card and imports photos automatically
2. **Auto-sort** — Photos are matched to your Google Calendar bookings (from SureCart → OttoKit) and sorted into `dd-mm-yyyy-CLIENTS-NAME` folders
3. **Imagen AI** — Culls and edits the sorted photos
4. **Lightroom** — Final review and adjustments
5. **AutoPoster** — Export from Lightroom to generate an AI blog post and publish to WordPress

## Stack

- **Backend**: Node.js + Express
- **AI**: Anthropic Claude (blog from topic) + Google Gemini (blog from photo)
- **Images**: Pexels API (free, optional)
- **Publishing**: WordPress REST API (Application Passwords — no plugin needed)
- **Ingest**: SD card watcher + Google Calendar API + file sorter
- **Frontend**: React
- **Lightroom Plugin**: Lua (Lightroom Classic SDK)

---

## Setup

### 1. Prerequisites

- Node.js 18+
- Windows PC (for SD card auto-detection)
- A WordPress site (5.6+) with Application Passwords enabled
- An [Anthropic API key](https://console.anthropic.com/)
- A [Google Calendar API key](https://console.cloud.google.com/apis/credentials) (for booking matching)
- A [Google Gemini API key](https://aistudio.google.com/apikey) (free, for photo-to-blog)
- (Optional) A [Pexels API key](https://www.pexels.com/api/) for stock feature images
- (Optional) [Imagen AI](https://www.imagen-ai.com/) for automated culling & editing

### 2. Install dependencies

```bash
npm run install:all
```

### 3. Configure environment

```bash
cp backend/.env.example backend/.env
```

Open `backend/.env` and fill in:

| Variable | Description |
|---|---|
| `ANTHROPIC_API_KEY` | Your Anthropic API key |
| `GEMINI_API_KEY` | Google Gemini API key (free — for photo-to-blog) |
| `PEXELS_API_KEY` | Pexels API key (optional, for stock images) |
| `GOOGLE_CALENDAR_API_KEY` | Google Calendar API key (for booking matching) |
| `GOOGLE_CALENDAR_ID` | Your calendar ID (see below) |
| `IMPORT_DESTINATION` | Where photos are saved, e.g. `D:\Photography\Sessions` |
| `BUSINESS_1_ID` | Short slug, e.g. `tweller-studios` |
| `BUSINESS_1_NAME` | Display name, e.g. `Tweller Studios` |
| `BUSINESS_1_WP_URL` | Your WordPress site URL |
| `BUSINESS_1_WP_USERNAME` | WordPress username |
| `BUSINESS_1_WP_APP_PASSWORD` | WordPress Application Password |

**How to create a WordPress Application Password:**
1. WordPress Admin → Users → Your Profile
2. Scroll to "Application Passwords"
3. Enter a name (e.g. "AutoPoster") → click Add New
4. Copy the generated password (spaces are fine — paste as-is)

### 4. Adding more businesses

Just add more `BUSINESS_N_*` blocks to your `.env`:

```env
BUSINESS_2_ID=tweller-studios
BUSINESS_2_NAME=Tweller Studios
BUSINESS_2_WP_URL=https://www.twellerstudios.com
BUSINESS_2_WP_USERNAME=admin
BUSINESS_2_WP_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx
```

No code changes needed.

### 5. Set up Google Calendar (for booking matching)

Your SureCart → OttoKit automation already creates calendar events for each booking. AutoPoster reads these to auto-sort photos by client name.

**Step 1: Get a Google Calendar API key**

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project (or use an existing one)
3. Go to **APIs & Services → Library**
4. Search for **Google Calendar API** → click **Enable**
5. Go to **APIs & Services → Credentials**
6. Click **Create Credentials → API Key**
7. Copy the key → paste into `GOOGLE_CALENDAR_API_KEY` in your `.env`

**Step 2: Find your Calendar ID**

1. Open [Google Calendar](https://calendar.google.com)
2. Click the three dots next to your bookings calendar → **Settings and sharing**
3. Scroll to **Integrate calendar** → copy the **Calendar ID**
   - It looks like: `abc123@group.calendar.google.com`
   - For your main calendar, use `primary`
4. Paste into `GOOGLE_CALENDAR_ID` in your `.env`

**Step 3: Make the calendar readable**

Since we're using an API key (not OAuth), the calendar needs to be accessible:

1. In Calendar settings → **Access permissions for events**
2. Enable **Make available to public** (or use "See all event details")

> **Note:** If you don't want to make your calendar public, you can skip Google Calendar setup entirely. Photos will still import — they'll just be sorted by date without the client name. You can always type the client name manually in the import form.

### 6. Set up the Lightroom Plugin

1. Copy the `lightroom-plugin` folder to your Lightroom plugins directory:
   - **Windows:** `C:\Users\YourName\AppData\Roaming\Adobe\Lightroom\Modules\`
   - Or any folder you choose
2. Open **Lightroom Classic → File → Plug-in Manager**
3. Click **Add** → navigate to the `lightroom-plugin` folder → select it
4. The plugin "AutoPoster Export" should appear and be enabled
5. To use: select photos → **File → Export** → choose **AutoPoster** from the export dropdown
6. Set your backend URL (default: `http://localhost:3001`) and business ID
7. Export → photos are uploaded and blog posts are generated automatically

### 7. Set up Imagen AI (optional)

Imagen AI handles automated culling and editing of your imported photos.

1. Install [Imagen AI](https://www.imagen-ai.com/) and train your personal AI profile
2. In Imagen AI settings, set the **watch folder** to your `IMPORT_DESTINATION` path (e.g. `D:\Photography\Sessions`)
3. When AutoPoster imports photos into a new session folder, Imagen AI will automatically detect and process them
4. After Imagen AI finishes, open the culled/edited photos in Lightroom for final review

### 8. Run

```bash
# Run both backend and frontend together
npm run dev

# Or separately:
npm run backend:dev   # Backend on :3001
npm run frontend      # Frontend on :3000
```

Open http://localhost:3000

---

## API Reference

The backend exposes a clean REST API you can call directly:

### `GET /api/businesses`
List all configured businesses.

### `GET /api/businesses/:id/test`
Test WordPress connection for a business. Returns connected user info.

### `POST /api/posts/generate`

Generate and (optionally) publish a blog post.

**Body:**
```json
{
  "businessId": "journey-to",
  "topic": "Top 10 hidden beaches in Bali",
  "tone": "adventurous",
  "wordCount": 1000,
  "keywords": ["Bali travel", "hidden beaches"],
  "publish": true
}
```

**Response:**
```json
{
  "success": true,
  "action": "published",
  "post": {
    "id": 123,
    "title": "10 Hidden Beaches in Bali You Need to Visit",
    "url": "https://www.letsjourneyto.com/hidden-beaches-bali/",
    "editUrl": "https://www.letsjourneyto.com/wp-admin/post.php?post=123&action=edit",
    "slug": "hidden-beaches-bali",
    "tags": ["Bali", "beaches", "travel tips"],
    "categories": ["Travel"],
    "metaDescription": "Discover the most secluded beaches in Bali...",
    "focusKeyphrase": "hidden beaches Bali"
  }
}
```

### Ingest Endpoints

#### `GET /api/ingest/status`
Returns watcher state, active import progress, and recent import history.

#### `POST /api/ingest/start`
Start the SD card watcher. It polls for new removable drives every 3 seconds.

#### `POST /api/ingest/stop`
Stop the SD card watcher.

#### `POST /api/ingest/import`
Manually trigger an import from a specific folder.

**Body:**
```json
{
  "sourcePath": "E:\\DCIM\\100CANON",
  "clientName": "John Smith",
  "sessionDate": "2026-03-22"
}
```
`clientName` and `sessionDate` are optional — if omitted, they're auto-detected from Google Calendar and file metadata.

#### `GET /api/ingest/bookings`
Fetch upcoming bookings from Google Calendar. Query params: `daysBack` (default 7), `daysForward` (default 7).

---

## Roadmap

- [x] Claude AI → WordPress publishing
- [x] Pexels feature images
- [x] Multi-business support
- [x] SEO metadata (Yoast + Rank Math compatible)
- [x] Lightroom Classic export plugin (Gemini AI blog from photo)
- [x] Photo auto-ingest (SD card → sort by booking → organized folders)
- [x] Google Calendar booking matching (SureCart → OttoKit → Calendar)
- [ ] Client delivery (gallery/download link for clients)
- [ ] Social media posting (Facebook, Instagram, TikTok)
- [ ] Post scheduling
- [ ] Post history / dashboard
- [ ] Mobile APK (Capacitor)
- [ ] Image generation (DALL-E / Stability AI)
