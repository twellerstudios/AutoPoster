/**
 * Facebook Graph API service for publishing posts to Facebook Pages.
 *
 * Requirements:
 * - A Facebook Page (not personal profile)
 * - A Facebook App with pages_manage_posts permission
 * - A Page Access Token (long-lived recommended)
 *
 * How to get a Page Access Token:
 * 1. Go to developers.facebook.com → Create App → Business type
 * 2. Add "Facebook Login for Business" product
 * 3. In Graph API Explorer: select your app, choose your Page, request pages_manage_posts
 * 4. Generate a User Access Token, then exchange it for a long-lived Page Access Token
 *    via: GET /oauth/access_token?grant_type=fb_exchange_token&client_id={app-id}&client_secret={app-secret}&fb_exchange_token={short-lived-token}
 *    then: GET /{page-id}?fields=access_token&access_token={long-lived-user-token}
 */
const axios = require('axios');

const GRAPH_API_BASE = 'https://graph.facebook.com/v21.0';

/**
 * Build an axios instance for the Facebook Graph API.
 */
function fbClient(fbConfig) {
  return axios.create({
    baseURL: GRAPH_API_BASE,
    timeout: 30000,
  });
}

/**
 * Publish a post to a Facebook Page.
 *
 * @param {object} fbConfig - { pageId, pageAccessToken }
 * @param {object} post     - { title, htmlContent, metaDescription, slug }
 * @param {object} options  - { linkUrl?, imageUrl? } - optional link/image to attach
 * @returns {Promise<{id: string, url: string}>}
 */
async function publishToFacebook(fbConfig, post, options = {}) {
  const { pageId, pageAccessToken } = fbConfig;
  const client = fbClient(fbConfig);

  // Convert HTML content to plain text for Facebook
  const plainText = htmlToPlainText(post.htmlContent);

  // Build the Facebook post message
  const message = buildFacebookMessage(post.title, plainText, options.linkUrl);

  let result;

  if (options.imageUrl) {
    // Post with photo
    result = await client.post(`/${pageId}/photos`, {
      url: options.imageUrl,
      caption: message,
      access_token: pageAccessToken,
    });
  } else if (options.linkUrl) {
    // Post with link preview
    result = await client.post(`/${pageId}/feed`, {
      message,
      link: options.linkUrl,
      access_token: pageAccessToken,
    });
  } else {
    // Text-only post
    result = await client.post(`/${pageId}/feed`, {
      message,
      access_token: pageAccessToken,
    });
  }

  const postId = result.data.id || result.data.post_id;

  return {
    id: postId,
    url: `https://www.facebook.com/${postId}`,
  };
}

/**
 * Test Facebook Page connection — verifies the token and page access.
 */
async function testFacebookConnection(fbConfig) {
  const { pageId, pageAccessToken } = fbConfig;
  const client = fbClient(fbConfig);

  const response = await client.get(`/${pageId}`, {
    params: {
      fields: 'name,id,access_token',
      access_token: pageAccessToken,
    },
  });

  return {
    ok: true,
    pageName: response.data.name,
    pageId: response.data.id,
  };
}

/**
 * Strip HTML tags and convert to plain text for Facebook posts.
 */
function htmlToPlainText(html) {
  if (!html) return '';

  let text = html
    // Replace <br> and block-level elements with newlines
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/?(p|div|h[1-6]|li|blockquote|section|article|header|footer|figure|figcaption)[^>]*>/gi, '\n')
    // Replace <a> tags with the link text + URL
    .replace(/<a\s[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/gi, '$2 ($1)')
    // Replace list items
    .replace(/<li[^>]*>/gi, '- ')
    // Strip all remaining HTML tags
    .replace(/<[^>]+>/g, '')
    // Decode common HTML entities
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
    // Clean up excessive whitespace
    .replace(/\n{3,}/g, '\n\n')
    .trim();

  return text;
}

/**
 * Build a Facebook-friendly post message from the blog post.
 * Truncates long content and adds a "Read more" link if available.
 */
function buildFacebookMessage(title, plainText, linkUrl) {
  const MAX_LENGTH = 2000; // Keep it reasonable for FB

  let message = '';

  // Add title as a bold-like header
  if (title) {
    message += `${title}\n\n`;
  }

  // Truncate the body text if too long
  const availableLength = MAX_LENGTH - message.length - (linkUrl ? 100 : 0);
  if (plainText.length > availableLength) {
    message += plainText.substring(0, availableLength - 20).trim() + '...\n\n';
  } else {
    message += plainText + '\n\n';
  }

  // Add link
  if (linkUrl) {
    message += `Read the full post: ${linkUrl}`;
  }

  return message.trim();
}

module.exports = { publishToFacebook, testFacebookConnection };
