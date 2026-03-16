# AutoPoster

AI-powered content publishing hub. Generate professional, SEO-optimised blog posts with Claude AI and publish them directly to WordPress — in one click.

## What it does (Phase 1)

1. You describe a topic
2. Claude AI writes a complete, SEO-ready blog post
3. A relevant feature image is fetched from Pexels and uploaded
4. The post is published live to your WordPress site with full SEO metadata

## Stack

- **Backend**: Node.js + Express
- **AI**: Anthropic Claude (claude-opus-4-6)
- **Images**: Pexels API (free)
- **Publishing**: WordPress REST API (Application Passwords — no plugin needed)
- **Frontend**: React

---

## Setup

### 1. Prerequisites

- Node.js 18+
- A WordPress site (5.6+) with Application Passwords enabled
- An [Anthropic API key](https://console.anthropic.com/)
- (Optional) A [Pexels API key](https://www.pexels.com/api/) for feature images

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
| `PEXELS_API_KEY` | Pexels API key (optional, for images) |
| `BUSINESS_1_ID` | Short slug, e.g. `journey-to` |
| `BUSINESS_1_NAME` | Display name, e.g. `Journey To` |
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

### 5. Run

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

---

## Roadmap

- [x] Claude AI → WordPress publishing
- [x] Pexels feature images
- [x] Multi-business support
- [x] SEO metadata (Yoast + Rank Math compatible)
- [ ] Social media posting (Facebook, Instagram, TikTok)
- [ ] Post scheduling
- [ ] Post history / dashboard
- [ ] Mobile APK (Capacitor)
- [ ] Image generation (DALL-E / Stability AI)
