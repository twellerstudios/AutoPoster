# AutoPoster — Publish a blog post to WordPress using Claude Code

Generate a complete SEO-optimised blog post and publish it directly to WordPress — no Anthropic API credits needed, everything runs through Claude Code.

## Usage

```
/autoposter <businessId> "<topic>" [--tone <tone>] [--length <words>] [--keywords "<kw1, kw2">] [--draft]
```

**Arguments:**
- `businessId` — matches `BUSINESS_N_ID` in `backend/.env` (e.g. `journey-to`, `tweller-studios`)
- `topic` — the blog post subject (be specific for better results)
- `--tone` — writing tone: `professional` | `adventurous` | `casual` | `informative` (default: `professional`)
- `--length` — target word count, e.g. `800`, `1000`, `1500` (default: `1000`)
- `--keywords` — comma-separated SEO keywords to weave in naturally
- `--draft` — save as WordPress draft instead of publishing immediately

**Examples:**
```
/autoposter journey-to "Hidden waterfalls in Bali" --tone adventurous --keywords "Bali waterfalls, off the beaten path Bali"
/autoposter tweller-studios "How to brief a web designer" --tone professional --length 1200
/autoposter journey-to "Best street food in Bangkok" --draft
```

---

## What I will do

When this skill is invoked, I will:

1. **Read** the WordPress credentials from `backend/.env` for the specified business
2. **Generate** the full blog post (HTML + SEO metadata) using my own intelligence — no external API calls
3. **Publish** the post directly to WordPress REST API using `curl`
4. **Report** the live URL back to you

---

## Instructions for Claude

When `/autoposter` is called with arguments, follow these steps exactly:

### Step 1 — Parse arguments

Extract from the args string:
- `businessId` (first positional argument)
- `topic` (second argument, quoted string)
- `--tone` value (default: `professional`)
- `--length` value (default: `1000`)
- `--keywords` value (default: empty)
- `--draft` flag (default: false → publish immediately)

### Step 2 — Load credentials

Read `backend/.env` (relative to the AutoPoster project root, i.e. `/home/user/AutoPoster/backend/.env`).

Find the block where `BUSINESS_N_ID` matches the given `businessId`. Extract:
- `BUSINESS_N_NAME` → businessName
- `BUSINESS_N_WP_URL` → wpUrl (strip trailing slash)
- `BUSINESS_N_WP_USERNAME` → wpUser
- `BUSINESS_N_WP_APP_PASSWORD` → wpPass

If the .env file doesn't exist or the businessId isn't found, stop and tell the user clearly.

Build the Basic Auth token:
```bash
AUTH=$(echo -n "${wpUser}:${wpPass}" | base64)
```

### Step 3 — Generate the blog post

Use your own language model capabilities to write the blog post. Output it in the following exact format so you can parse it in the next step:

---

Write a complete, publication-ready blog post for **"${businessName}"** about: **"${topic}"**

Requirements:
- Tone: ${tone}
- Target word count: ~${length} words
- Fully formatted in HTML (use `<h2>`, `<h3>`, `<p>`, `<ul>`, `<li>`, `<strong>`, `<em>` — NO `<html>`, `<head>`, or `<body>` tags)
- Compelling introduction, well-structured body sections, clear conclusion
- Write naturally — no keyword stuffing
- If keywords were provided: naturally weave in: ${keywords}

After the HTML, output a JSON block in `<seo_data>` tags:

```
<seo_data>
{
  "title": "SEO-optimised post title (50-60 chars)",
  "metaDescription": "Compelling meta description (150-160 chars)",
  "focusKeyphrase": "primary target keyword phrase",
  "tags": ["tag1", "tag2", "tag3", "tag4", "tag5"],
  "categories": ["Category Name"],
  "slug": "url-friendly-slug"
}
</seo_data>
```

---

### Step 4 — Publish to WordPress via curl

After generating the content, use the Bash tool to publish to WordPress.

**4a. Resolve/create tags** (loop over each tag in the SEO data):
```bash
# For each tag, find or create it and collect the ID
curl -s -X GET "${wpUrl}/wp-json/wp/v2/tags?search=<tagname>&per_page=5" \
  -H "Authorization: Basic ${AUTH}"
# If not found, create:
curl -s -X POST "${wpUrl}/wp-json/wp/v2/tags" \
  -H "Authorization: Basic ${AUTH}" \
  -H "Content-Type: application/json" \
  -d '{"name":"<tagname>"}'
```

**4b. Resolve/create category**:
```bash
curl -s -X GET "${wpUrl}/wp-json/wp/v2/categories?search=<catname>&per_page=5" \
  -H "Authorization: Basic ${AUTH}"
```

**4c. Create the post**:
```bash
curl -s -X POST "${wpUrl}/wp-json/wp/v2/posts" \
  -H "Authorization: Basic ${AUTH}" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "<title>",
    "content": "<htmlContent — properly JSON-escaped>",
    "status": "<publish or draft>",
    "slug": "<slug>",
    "tags": [<tag ids>],
    "categories": [<category ids>],
    "meta": {
      "_yoast_wpseo_metadesc": "<metaDescription>",
      "_yoast_wpseo_focuskw": "<focusKeyphrase>"
    }
  }'
```

**Important:** Escape the HTML content properly for JSON. Use a temporary file to avoid shell quoting issues:
```bash
# Write JSON payload to a temp file, then post it
cat > /tmp/wp_post.json << 'JSONEOF'
{ ... }
JSONEOF
curl -s -X POST "${wpUrl}/wp-json/wp/v2/posts" \
  -H "Authorization: Basic ${AUTH}" \
  -H "Content-Type: application/json" \
  --data @/tmp/wp_post.json
```

### Step 5 — Report the result

Parse the curl response. Extract `link` (the public URL) and `id`.

Tell the user:
```
✅ Published!
Title: <title>
URL: <link>
WP Admin: <wpUrl>/wp-admin/post.php?post=<id>&action=edit
Tags: <tags>
Category: <categories>
```

If there's an error, show the full error message and the WP API response so the user can debug.

---

## Notes

- This skill uses **zero Anthropic API credits** — content is generated by Claude Code itself
- WordPress Application Passwords format: `xxxx xxxx xxxx xxxx xxxx xxxx` (spaces are fine, curl handles them)
- If Yoast SEO is installed, meta fields will be populated automatically
- Always prefer `--data @file` over inline JSON to avoid shell escaping issues with long HTML content
