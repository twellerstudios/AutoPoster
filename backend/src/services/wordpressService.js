/**
 * WordPress REST API service.
 * Uses Application Passwords (WP 5.6+) — no plugin needed.
 *
 * How to create a WordPress Application Password:
 * WordPress Admin → Users → Your Profile → Application Passwords → Add New
 */
const axios = require('axios');
const FormData = require('form-data');

/**
 * Build an authenticated axios instance for a WordPress site.
 */
function wpClient(wpConfig) {
  const { url, username, appPassword } = wpConfig;
  const credentials = Buffer.from(`${username}:${appPassword}`).toString('base64');

  return axios.create({
    baseURL: `${url.replace(/\/$/, '')}/wp-json/wp/v2`,
    headers: {
      Authorization: `Basic ${credentials}`,
      'Content-Type': 'application/json',
    },
    timeout: 30000,
  });
}

/**
 * Upload an image to the WordPress media library.
 * @returns {Promise<number>} WordPress media attachment ID
 */
async function uploadImage(wpConfig, imageBuffer, filename, altText) {
  const { url, username, appPassword } = wpConfig;
  const credentials = Buffer.from(`${username}:${appPassword}`).toString('base64');

  const form = new FormData();
  form.append('file', imageBuffer, {
    filename: filename || 'featured-image.jpg',
    contentType: 'image/jpeg',
  });

  // Alt text via title field
  if (altText) form.append('title', altText);
  if (altText) form.append('alt_text', altText);

  const response = await axios.post(
    `${url.replace(/\/$/, '')}/wp-json/wp/v2/media`,
    form,
    {
      headers: {
        ...form.getHeaders(),
        Authorization: `Basic ${credentials}`,
      },
      timeout: 30000,
    }
  );

  return response.data.id;
}

/**
 * Resolve or create WordPress tag IDs by name.
 */
async function resolveTagIds(client, tagNames) {
  const ids = [];
  for (const name of tagNames) {
    try {
      // Try to find existing
      const search = await client.get('/tags', { params: { search: name, per_page: 5 } });
      const existing = search.data.find(t => t.name.toLowerCase() === name.toLowerCase());
      if (existing) {
        ids.push(existing.id);
      } else {
        // Create new tag
        const created = await client.post('/tags', { name });
        ids.push(created.data.id);
      }
    } catch {
      // Non-fatal — skip this tag
    }
  }
  return ids;
}

/**
 * Resolve or create WordPress category IDs by name.
 */
async function resolveCategoryIds(client, categoryNames) {
  const ids = [];
  for (const name of categoryNames) {
    try {
      const search = await client.get('/categories', { params: { search: name, per_page: 5 } });
      const existing = search.data.find(c => c.name.toLowerCase() === name.toLowerCase());
      if (existing) {
        ids.push(existing.id);
      } else {
        const created = await client.post('/categories', { name });
        ids.push(created.data.id);
      }
    } catch {
      // Non-fatal
    }
  }
  return ids.length > 0 ? ids : [1]; // fallback to Uncategorized (ID 1)
}

/**
 * Publish a full blog post to WordPress.
 *
 * @param {object} wpConfig        - { url, username, appPassword }
 * @param {object} post            - Post data from generateBlogPost()
 * @param {number|null} mediaId    - Featured image media ID (optional)
 * @returns {Promise<{id: number, url: string, editUrl: string}>}
 */
async function publishPost(wpConfig, post, mediaId = null) {
  const client = wpClient(wpConfig);

  // Resolve tags and categories in parallel
  const [tagIds, categoryIds] = await Promise.all([
    resolveTagIds(client, post.tags || []),
    resolveCategoryIds(client, post.categories || []),
  ]);

  const payload = {
    title: post.title,
    content: post.htmlContent,
    status: 'publish',
    slug: post.slug,
    tags: tagIds,
    categories: categoryIds,
    ...(mediaId && { featured_media: mediaId }),
    meta: {
      // Yoast SEO fields (works if Yoast is installed)
      _yoast_wpseo_metadesc: post.metaDescription,
      _yoast_wpseo_focuskw: post.focusKeyphrase,
    },
    // Rank Math SEO fields (works if Rank Math is installed)
    rank_math_description: post.metaDescription,
    rank_math_focus_keyword: post.focusKeyphrase,
  };

  const response = await client.post('/posts', payload);
  const created = response.data;

  return {
    id: created.id,
    url: created.link,
    editUrl: `${wpConfig.url}/wp-admin/post.php?post=${created.id}&action=edit`,
  };
}

/**
 * Test WordPress connection — verifies credentials are valid.
 */
async function testConnection(wpConfig) {
  const client = wpClient(wpConfig);
  const response = await client.get('/users/me');
  return { ok: true, user: response.data.name, roles: response.data.roles };
}

module.exports = { publishPost, uploadImage, testConnection };
