/**
 * Claude AI service — generates blog posts with SEO metadata.
 *
 * Two modes:
 * 1. API mode — uses Anthropic API key (paid)
 * 2. Manual mode — generates the prompt for the user to paste into claude.ai (free with $20 sub)
 */
const { config } = require('../config');

let client = null;

function getClient() {
  if (!config.anthropicApiKey) return null;
  if (!client) {
    const Anthropic = require('@anthropic-ai/sdk');
    client = new Anthropic({ apiKey: config.anthropicApiKey });
  }
  return client;
}

/**
 * Build the prompt for blog post generation.
 * Used by both API mode and manual mode.
 */
function buildPrompt({ topic, businessName, tone = 'professional', wordCount = 1000, keywords = [], userImages = [] }) {
  const keywordsNote = keywords.length > 0
    ? `Naturally weave in these keywords: ${keywords.join(', ')}.`
    : '';

  let userImagesNote = '';
  if (userImages.length > 0) {
    const imageList = userImages.map((img, i) => {
      const caption = img.caption ? ` — "${img.caption}"` : '';
      return `  ${i + 1}. ${img.originalName}${caption} (use src="${img.url}")`;
    }).join('\n');

    userImagesNote = `
The user has uploaded ${userImages.length} image(s) to include in the blog post.
Place them naturally within the content using <img> tags at relevant points in the article.
Use descriptive alt text and wrap each in a <figure> with a <figcaption>.
Style figures with: style="margin: 24px 0; text-align: center;"
Style images with: style="max-width: 100%; height: auto; border-radius: 8px;"

User images:
${imageList}
`;
  }

  return `You are an expert content writer and SEO specialist for "${businessName}".

Write a complete, publication-ready blog post about: "${topic}"

Requirements:
- Tone: ${tone}
- Target word count: ~${wordCount} words
- Fully formatted in HTML (use <h2>, <h3>, <p>, <ul>, <li>, <strong>, <em> — NO <html>, <head>, or <body> tags)
- Include a compelling introduction, well-structured body sections, and a clear conclusion
- Write naturally — no keyword stuffing
${keywordsNote}
${userImagesNote}
Also suggest 2-3 places in the post where stock photos would enhance the content. For each, suggest a specific descriptive search query.

After the blog post HTML, output a JSON block wrapped in <seo_data> tags with this exact structure:
<seo_data>
{
  "title": "SEO-optimised post title (50-60 chars)",
  "metaDescription": "Compelling meta description (150-160 chars)",
  "focusKeyphrase": "primary target keyword phrase",
  "tags": ["tag1", "tag2", "tag3", "tag4", "tag5"],
  "categories": ["Category Name"],
  "imageSearchQuery": "specific descriptive search term for the main featured image",
  "additionalImageQueries": ["search query for inline image 1", "search query for inline image 2"],
  "slug": "url-friendly-slug"
}
</seo_data>

Write the full HTML blog post now, then the <seo_data> block.`;
}

/**
 * Check if API mode is available.
 */
function isApiModeAvailable() {
  return Boolean(config.anthropicApiKey);
}

/**
 * Generate a blog post using the Anthropic API.
 * Throws a clear error if the API key is not set.
 */
async function generateBlogPost(params) {
  const apiClient = getClient();

  if (!apiClient) {
    throw new Error(
      'Anthropic API key is not configured. Either add your API key in Settings, or use "Manual AI" mode ' +
      'which lets you generate content for free using your Claude subscription at claude.ai.'
    );
  }

  const prompt = buildPrompt(params);

  let message;
  try {
    message = await apiClient.messages.create({
      model: 'claude-opus-4-6',
      max_tokens: 4096,
      messages: [{ role: 'user', content: prompt }],
    });
  } catch (err) {
    // Provide helpful error messages for common API issues
    if (err.status === 401) {
      throw new Error(
        'Your Anthropic API key is invalid or expired. Go to Settings to update it, ' +
        'or use Manual AI mode (free with your Claude subscription).'
      );
    }
    if (err.status === 429) {
      throw new Error(
        'You have hit the Anthropic API rate limit. Please wait a minute and try again, ' +
        'or switch to Manual AI mode.'
      );
    }
    if (err.status === 402 || err.status === 400) {
      throw new Error(
        'Anthropic API error: ' + (err.message || 'billing issue or invalid request') +
        '. Check your API account at console.anthropic.com, or use Manual AI mode for free.'
      );
    }
    throw new Error(`AI generation failed: ${err.message}`);
  }

  const raw = message.content[0].text;
  return parseGeneratedContent(raw, params.topic);
}

/**
 * Generate the prompt text for manual mode.
 * User copies this to claude.ai, gets the response, and pastes it back.
 */
function getManualPrompt(params) {
  return buildPrompt(params);
}

/**
 * Parse content that was pasted back from Claude (manual mode).
 * Same parser as API mode.
 */
function parseManualContent(rawContent, topic) {
  return parseGeneratedContent(rawContent, topic);
}

function parseGeneratedContent(raw, topic) {
  const seoMatch = raw.match(/<seo_data>([\s\S]*?)<\/seo_data>/);

  let seoData = {};
  let htmlContent = raw;

  if (seoMatch) {
    htmlContent = raw.slice(0, raw.indexOf('<seo_data>')).trim();
    try {
      seoData = JSON.parse(seoMatch[1].trim());
    } catch {
      console.warn('[Claude] Could not parse SEO data JSON, using defaults');
    }
  }

  // Clean up any markdown code fences that Claude sometimes wraps HTML in
  htmlContent = htmlContent
    .replace(/^```html?\s*\n?/i, '')
    .replace(/\n?```\s*$/i, '')
    .trim();

  return {
    htmlContent,
    title: seoData.title || topic,
    metaDescription: seoData.metaDescription || '',
    focusKeyphrase: seoData.focusKeyphrase || '',
    tags: seoData.tags || [],
    categories: seoData.categories || ['Uncategorized'],
    imageSearchQuery: seoData.imageSearchQuery || topic,
    additionalImageQueries: seoData.additionalImageQueries || [],
    slug: seoData.slug || slugify(topic),
  };
}

function slugify(text) {
  return text
    .toLowerCase()
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .trim();
}

module.exports = { generateBlogPost, getManualPrompt, parseManualContent, isApiModeAvailable };
