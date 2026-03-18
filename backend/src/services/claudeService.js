/**
 * Claude AI service — generates blog posts with SEO metadata, image placement, and search terms.
 */
const Anthropic = require('@anthropic-ai/sdk');
const { config } = require('../config');

const client = new Anthropic({ apiKey: config.anthropicApiKey });

/**
 * Generate a complete blog post for the given topic and business context.
 *
 * @param {object} params
 * @param {string} params.topic         - The blog post topic
 * @param {string} params.businessName  - e.g. "Journey To"
 * @param {string} [params.tone]        - e.g. "adventurous", "professional", "casual"
 * @param {number} [params.wordCount]   - Target word count, default 1000
 * @param {string[]} [params.keywords]  - Optional seed keywords for SEO
 * @param {Array} [params.userImages]   - User-uploaded images with URLs and captions
 * @returns {Promise<BlogPost>}
 */
async function generateBlogPost({ topic, businessName, tone = 'professional', wordCount = 1000, keywords = [], userImages = [] }) {
  const keywordsNote = keywords.length > 0
    ? `Naturally weave in these keywords: ${keywords.join(', ')}.`
    : '';

  // Build user images instruction
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

  const prompt = `You are an expert content writer and SEO specialist for "${businessName}".

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

  const message = await client.messages.create({
    model: 'claude-opus-4-6',
    max_tokens: 4096,
    messages: [{ role: 'user', content: prompt }],
  });

  const raw = message.content[0].text;
  return parseGeneratedContent(raw, topic);
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

module.exports = { generateBlogPost };
