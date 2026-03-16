# AutoPoster — Claude Code Instructions

## Generating & Publishing a Blog Post

When the user asks to generate or publish a blog post, follow these steps:

### 1. Read credentials
Read `backend/.env`. Find the matching `BUSINESS_N_ID` and extract:
- `BUSINESS_N_NAME` → businessName
- `BUSINESS_N_WP_URL` → wpUrl
- `BUSINESS_N_WP_USERNAME` → wpUser
- `BUSINESS_N_WP_APP_PASSWORD` → wpPass

### 2. Generate content
Write a full SEO-optimised blog post in HTML (no `<html>/<head>/<body>` tags).
After the HTML, output a `<seo_data>` JSON block:
```
<seo_data>
{
  "title": "...",
  "metaDescription": "...",
  "focusKeyphrase": "...",
  "tags": ["tag1","tag2","tag3"],
  "categories": ["Category"],
  "slug": "url-slug"
}
</seo_data>
```

### 3. Publish to WordPress
Use curl with `--data @/tmp/wp_post.json` to avoid shell escaping issues.
Write the JSON payload to `/tmp/wp_post.json` first, then POST to `{wpUrl}/wp-json/wp/v2/posts`.
Auth: `Authorization: Basic $(echo -n "user:pass" | base64)`

### 4. Report back
Show the live URL, post title, and WordPress edit link.

---

## Default businesses
- `journey-to` → letsjourneyto.com
- `tweller-studios` → twellerstudios.com

## Example user requests
- "Post about hidden beaches in Bali for Journey To"
- "Write a professional post about web design for Tweller Studios"
- "Generate a 1500 word post about Bangkok street food, adventurous tone"
