/**
 * Buffer GraphQL API service for publishing posts via Buffer.
 *
 * Buffer acts as a middleman — you connect your social accounts (Facebook, Instagram, etc.)
 * through Buffer's dashboard, then publish to them via Buffer's API.
 *
 * Requirements:
 * - A Buffer account (free plan: 3 channels, 10 scheduled posts each)
 * - A Buffer API token (Settings → API Token in Buffer dashboard)
 * - Social accounts connected through Buffer's dashboard
 *
 * How to get started:
 * 1. Sign up at buffer.com (free)
 * 2. Connect your Facebook, Instagram, etc. accounts in Buffer
 * 3. Go to account settings → generate an API token
 * 4. Add the token in AutoPoster Settings
 * 5. Select which Buffer channels to use per business
 */
const axios = require('axios');
const fs = require('fs');
const path = require('path');

const BUFFER_API_URL = 'https://api.buffer.com';
const LOG_FILE = path.join(__dirname, '..', '..', 'buffer-debug.log');

/**
 * Append a human-readable entry to the Buffer debug log file.
 */
function logBuffer(label, data) {
  const timestamp = new Date().toISOString();
  const line = `[${timestamp}] ${label}\n${typeof data === 'string' ? data : JSON.stringify(data, null, 2)}\n${'─'.repeat(60)}\n`;
  try {
    fs.appendFileSync(LOG_FILE, line);
  } catch { /* ignore write errors */ }
}

/**
 * Create an axios-like helper for Buffer GraphQL requests.
 */
function bufferRequest(apiToken, query, variables = {}) {
  return axios.post(
    BUFFER_API_URL,
    { query, variables },
    {
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${apiToken}`,
      },
      timeout: 30000,
    }
  );
}

/**
 * Fetch the first organization ID for the authenticated account.
 */
async function getOrganizationId(apiToken) {
  const query = `
    query GetOrganizations {
      account {
        organizations {
          id
          name
        }
      }
    }
  `;

  const response = await bufferRequest(apiToken, query);

  if (response.data.errors && response.data.errors.length > 0) {
    throw new Error(response.data.errors[0].message || 'Failed to fetch Buffer account');
  }

  const orgs = response.data.data?.account?.organizations || [];
  if (orgs.length === 0) {
    throw new Error('No organizations found in your Buffer account. Please set up your Buffer account first.');
  }

  return orgs[0];
}

/**
 * Fetch all channels (connected social accounts) from Buffer.
 * Returns array of { id, name, service, avatar }.
 */
async function getChannels(apiToken) {
  // First get the organization ID (required by the channels query)
  const org = await getOrganizationId(apiToken);

  const query = `
    query GetChannels($input: ChannelsInput!) {
      channels(input: $input) {
        id
        name
        service
        avatar
      }
    }
  `;

  const variables = {
    input: {
      organizationId: org.id,
    },
  };

  const response = await bufferRequest(apiToken, query, variables);

  if (response.data.errors && response.data.errors.length > 0) {
    throw new Error(response.data.errors[0].message || 'Failed to fetch Buffer channels');
  }

  return response.data.data.channels || [];
}

/**
 * Test the Buffer API connection by fetching the account info and channels.
 */
async function testBufferConnection(apiToken) {
  // First get the organization
  const org = await getOrganizationId(apiToken);

  // Then fetch channels using the org ID
  const channelQuery = `
    query GetChannels($input: ChannelsInput!) {
      channels(input: $input) {
        id
        name
        service
      }
    }
  `;

  const response = await bufferRequest(apiToken, channelQuery, {
    input: { organizationId: org.id },
  });

  if (response.data.errors && response.data.errors.length > 0) {
    throw new Error(response.data.errors[0].message || 'Buffer API error');
  }

  const channels = response.data.data.channels || [];

  return {
    ok: true,
    channelCount: channels.length,
    channels: channels.map(c => ({ id: c.id, name: c.name, service: c.service })),
    organization: org.name || null,
  };
}

/**
 * Platform-specific character limits and requirements.
 */
const PLATFORM_CONFIG = {
  facebook:  { maxChars: 2000, needsPostType: true, needsMedia: false },
  instagram: { maxChars: 2200, needsPostType: true, needsMedia: true },
  tiktok:    { maxChars: 150,  needsPostType: false, needsMedia: true, videoOnly: true },
  twitter:   { maxChars: 280,  needsPostType: false, needsMedia: false },
  linkedin:  { maxChars: 3000, needsPostType: false, needsMedia: false },
  threads:   { maxChars: 500,  needsPostType: false, needsMedia: false },
  pinterest: { maxChars: 500,  needsPostType: false, needsMedia: true },
  mastodon:  { maxChars: 500,  needsPostType: false, needsMedia: false },
  bluesky:   { maxChars: 300,  needsPostType: false, needsMedia: false },
  googlebusiness: { maxChars: 1500, needsPostType: false, needsMedia: false },
  youtube:   { maxChars: 5000, needsPostType: false, needsMedia: true, videoOnly: true },
};

/**
 * Publish a post to one or more Buffer channels.
 *
 * @param {string} apiToken - Buffer API token
 * @param {string[]} channelIds - Array of Buffer channel IDs to publish to
 * @param {object} post - { title, htmlContent, metaDescription }
 * @param {object} options - { linkUrl?, imageUrl? } - optional link and featured image
 * @returns {Promise<Array<{channelId, success, postId?, error?}>>}
 */
async function publishToBuffer(apiToken, channelIds, post, options = {}) {
  // Fetch channels to get service types for each channel ID
  let channelMap = {};
  try {
    const allChannels = await getChannels(apiToken);
    for (const ch of allChannels) {
      channelMap[ch.id] = ch.service;
    }
  } catch (err) {
    logBuffer('CHANNEL LOOKUP FAILED', { error: err.message });
  }

  logBuffer('PUBLISH START', {
    channelIds,
    title: post.title,
    imageUrl: options.imageUrl || null,
    channelServices: channelIds.map(id => `${id} → ${channelMap[id] || 'unknown'}`),
  });
  const results = [];

  for (const channelId of channelIds) {
    const service = (channelMap[channelId] || 'unknown').toLowerCase();
    const platformCfg = PLATFORM_CONFIG[service] || { maxChars: 2000, needsPostType: false, needsMedia: false };

    // Skip video-only platforms (TikTok, YouTube) — we only have text/image posts
    if (platformCfg.videoOnly) {
      const msg = `Skipped: ${service} requires video content`;
      logBuffer(`PUBLISH SKIP → channel ${channelId} (${service})`, msg);
      results.push({ channelId, service, success: false, error: msg });
      continue;
    }

    // Skip platforms that require media if we don't have an image (e.g. Instagram without featured image)
    if (platformCfg.needsMedia && !options.imageUrl) {
      const msg = `Skipped: ${service} requires an image but none available`;
      logBuffer(`PUBLISH SKIP → channel ${channelId} (${service})`, msg);
      results.push({ channelId, service, success: false, error: msg });
      continue;
    }

    // Build platform-tailored caption
    const text = buildBufferMessage(post.title, post.htmlContent, options.linkUrl, platformCfg.maxChars);

    try {
      const result = await createBufferPost(apiToken, channelId, text, {
        postType: platformCfg.needsPostType ? 'post' : null,
        imageUrl: options.imageUrl || null,
      });
      logBuffer(`PUBLISH OK → channel ${channelId} (${service})`, result);
      results.push({
        channelId,
        service,
        success: true,
        postId: result.postId,
      });
    } catch (err) {
      logBuffer(`PUBLISH FAIL → channel ${channelId} (${service})`, { error: err.message });
      results.push({
        channelId,
        service,
        success: false,
        error: err.message,
      });
    }
  }

  logBuffer('PUBLISH DONE', { total: results.length, succeeded: results.filter(r => r.success).length });
  return results;
}

/**
 * Create a single post on a Buffer channel.
 * Uses inline GraphQL args with enum values as required by Buffer's API.
 */
async function createBufferPost(apiToken, channelId, text, extras = {}) {
  // Escape text for inline GraphQL string literal
  const escapedText = text.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n');

  // Build optional input fields
  let extraFields = '';
  if (extras.postType) {
    extraFields += `\n        postType: ${extras.postType},`;
  }
  if (extras.imageUrl) {
    const escapedUrl = extras.imageUrl.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    extraFields += `\n        assets: [{ uri: "${escapedUrl}" }],`;
  }

  // Buffer's schema uses inline input args with enum values (no quotes on schedulingType/mode/postType)
  const query = `
    mutation CreatePost {
      createPost(input: {
        text: "${escapedText}",
        channelId: "${channelId}",
        schedulingType: automatic,
        mode: shareNext,${extraFields}
      }) {
        ... on PostActionSuccess {
          post {
            id
            text
          }
        }
        ... on MutationError {
          message
        }
      }
    }
  `;

  logBuffer(`CREATE POST → channel ${channelId}`, { query: query.trim(), textLength: text.length });

  let response;
  try {
    response = await bufferRequest(apiToken, query);
  } catch (httpErr) {
    // Capture the actual response body from 4xx/5xx errors
    const detail = httpErr.response?.data || httpErr.message;
    logBuffer(`CREATE POST HTTP ERROR → channel ${channelId}`, detail);
    const msg = typeof detail === 'object' ? JSON.stringify(detail) : String(detail);
    throw new Error(msg);
  }

  logBuffer(`CREATE POST RESPONSE → channel ${channelId}`, response.data);

  if (response.data.errors && response.data.errors.length > 0) {
    throw new Error(response.data.errors[0].message || 'Buffer API error');
  }

  const result = response.data.data?.createPost;

  // Check for typed mutation error
  if (result?.message) {
    throw new Error(result.message);
  }

  return {
    postId: result?.post?.id || null,
  };
}

/**
 * Convert HTML to plain text and build a platform-tailored message.
 */
function buildBufferMessage(title, htmlContent, linkUrl, maxLength = 2000) {
  const plainText = htmlToPlainText(htmlContent);

  let message = '';

  if (title) {
    message += `${title}\n\n`;
  }

  const linkSuffix = linkUrl ? `\n\nRead the full post: ${linkUrl}` : '';
  const availableLength = maxLength - message.length - linkSuffix.length;

  if (availableLength > 0 && plainText.length > availableLength) {
    message += plainText.substring(0, availableLength - 4).trim() + '...';
  } else if (availableLength > 0) {
    message += plainText;
  }

  message += linkSuffix;

  return message.trim();
}

/**
 * Strip HTML tags and convert to plain text.
 */
function htmlToPlainText(html) {
  if (!html) return '';

  return html
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/?(p|div|h[1-6]|li|blockquote|section|article|header|footer|figure|figcaption)[^>]*>/gi, '\n')
    .replace(/<a\s[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/gi, '$2 ($1)')
    .replace(/<li[^>]*>/gi, '- ')
    .replace(/<[^>]+>/g, '')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&nbsp;/g, ' ')
    .replace(/&rsquo;/g, "'")
    .replace(/&lsquo;/g, "'")
    .replace(/&rdquo;/g, '"')
    .replace(/&ldquo;/g, '"')
    .replace(/&mdash;/g, '—')
    .replace(/&ndash;/g, '–')
    .replace(/&hellip;/g, '...')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

module.exports = { getChannels, testBufferConnection, publishToBuffer };
