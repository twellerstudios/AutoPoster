/**
 * Claude AI service — generates blog posts with SEO metadata and image search terms.
 */
const Anthropic = require('@anthropic-ai/sdk');
const { config } = require('../config');

const client = new Anthropic({ apiKey: config.anthropicApiKey });

/**
 * Generate a complete blog post for the given topic and business context.
 *
 * @param {object} params
 * @param {string} params.topic         - The blog post topic
 * @param {string} params.businessName  - e.g. "Journey To" — used to tailor tone/voice
 * @param {string} [params.tone]        - e.g. "adventurous", "professional", "casual"
 * @param {string} [params.wordCount]   - Target word count, default 1000
 * @param {string[]} [params.keywords]  - Optional seed keywords for SEO
 * @returns {Promise<BlogPost>}
 */
async function generateBlogPost({ topic, businessName, tone = 'professional', wordCount = 1000, keywords = [] }) {
  const keywordsNote = keywords.length > 0
    ? `Naturally weave in these keywords: ${keywords.join(', ')}.`
    : '';

  const prompt = `You are an expert content writer and SEO specialist for "${businessName}".

Write a complete, publication-ready blog post about: "${topic}"

Requirements:
- Tone: ${tone}
- Target word count: ~${wordCount} words
- Fully formatted in HTML (use <h2>, <h3>, <p>, <ul>, <li>, <strong>, <em> — NO <html>, <head>, or <body> tags)
- Include a compelling introduction, well-structured body sections, and a clear conclusion
- Write naturally — no keyword stuffing
${keywordsNote}

After the blog post HTML, output a JSON block wrapped in <seo_data> tags with this exact structure:
<seo_data>
{
  "title": "SEO-optimised post title (50-60 chars)",
  "metaDescription": "Compelling meta description (150-160 chars)",
  "focusKeyphrase": "primary target keyword phrase",
  "tags": ["tag1", "tag2", "tag3", "tag4", "tag5"],
  "categories": ["Category Name"],
  "imageSearchQuery": "specific descriptive search term for a relevant feature image",
  "slug": "url-friendly-slug"
}
</seo_data>

Write the full HTML blog post now, then the <seo_data> block.`;

  const message = await client.messages.create({
    model: 'claude-opus-4-6',
    max_tokens: 4096,
    messages: [{ role: 'user', content: prompt }],
  });

  const raw = message.content[0].text;
  return parseGeneratedContent(raw, topic);
}

function parseGeneratedContent(raw, topic) {
  // Split HTML content from SEO data block
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

  return {
    htmlContent,
    title: seoData.title || topic,
    metaDescription: seoData.metaDescription || '',
    focusKeyphrase: seoData.focusKeyphrase || '',
    tags: seoData.tags || [],
    categories: seoData.categories || ['Uncategorized'],
    imageSearchQuery: seoData.imageSearchQuery || topic,
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

module.exports = { generateBlogPost };
