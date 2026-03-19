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

const BUFFER_API_URL = 'https://api.buffer.com';

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
 * Fetch all channels (connected social accounts) from Buffer.
 * Returns array of { id, name, service, avatar }.
 */
async function getChannels(apiToken) {
  const query = `
    query GetChannels {
      channels(input: {}) {
        id
        name
        service
        avatar
      }
    }
  `;

  const response = await bufferRequest(apiToken, query);

  if (response.data.errors && response.data.errors.length > 0) {
    throw new Error(response.data.errors[0].message || 'Failed to fetch Buffer channels');
  }

  return response.data.data.channels || [];
}

/**
 * Test the Buffer API connection by fetching the account info.
 */
async function testBufferConnection(apiToken) {
  const query = `
    query TestConnection {
      account {
        id
        organizations {
          id
          name
        }
      }
      channels(input: {}) {
        id
        name
        service
      }
    }
  `;

  const response = await bufferRequest(apiToken, query);

  if (response.data.errors && response.data.errors.length > 0) {
    throw new Error(response.data.errors[0].message || 'Buffer API error');
  }

  const data = response.data.data;
  const channels = data.channels || [];
  const org = data.account?.organizations?.[0];

  return {
    ok: true,
    channelCount: channels.length,
    channels: channels.map(c => ({ id: c.id, name: c.name, service: c.service })),
    organization: org?.name || null,
  };
}

/**
 * Publish a post to one or more Buffer channels.
 *
 * @param {string} apiToken - Buffer API token
 * @param {string[]} channelIds - Array of Buffer channel IDs to publish to
 * @param {object} post - { title, htmlContent, metaDescription }
 * @param {object} options - { linkUrl? } - optional link to include
 * @returns {Promise<Array<{channelId, success, postId?, error?}>>}
 */
async function publishToBuffer(apiToken, channelIds, post, options = {}) {
  const text = buildBufferMessage(post.title, post.htmlContent, options.linkUrl);
  const results = [];

  for (const channelId of channelIds) {
    try {
      const result = await createBufferPost(apiToken, channelId, text);
      results.push({
        channelId,
        success: true,
        postId: result.postId,
      });
    } catch (err) {
      results.push({
        channelId,
        success: false,
        error: err.message,
      });
    }
  }

  return results;
}

/**
 * Create a single post on a Buffer channel.
 */
async function createBufferPost(apiToken, channelId, text) {
  const query = `
    mutation CreatePost($input: PostCreateInput!) {
      createPost(input: $input) {
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

  const variables = {
    input: {
      text,
      channelIds: [channelId],
    },
  };

  const response = await bufferRequest(apiToken, query, variables);

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
 * Convert HTML to plain text and build a social-media-friendly message.
 */
function buildBufferMessage(title, htmlContent, linkUrl) {
  const MAX_LENGTH = 2000;

  const plainText = htmlToPlainText(htmlContent);

  let message = '';

  if (title) {
    message += `${title}\n\n`;
  }

  const availableLength = MAX_LENGTH - message.length - (linkUrl ? 100 : 0);
  if (plainText.length > availableLength) {
    message += plainText.substring(0, availableLength - 20).trim() + '...\n\n';
  } else {
    message += plainText + '\n\n';
  }

  if (linkUrl) {
    message += `Read the full post: ${linkUrl}`;
  }

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
