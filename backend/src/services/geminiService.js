/**
 * Google Gemini AI Service — generates blog posts from photo metadata + image.
 *
 * Free tier: Get your API key at https://aistudio.google.com/apikey
 * Generous free usage: 15 RPM, 1M tokens/min, 1500 req/day
 */
const axios = require('axios');
const fs = require('fs');

const GEMINI_API_KEY = process.env.GEMINI_API_KEY || '';
const GEMINI_MODEL = 'gemini-2.0-flash'; // Free, fast, multimodal

/**
 * Generate a blog post from a photo and its metadata using Gemini.
 *
 * @param {object} options
 * @param {Buffer} options.imageBuffer    - The photo file as a Buffer
 * @param {string} options.mimeType       - Image MIME type (e.g. image/jpeg)
 * @param {object} options.metadata       - EXIF/Lightroom metadata
 * @param {string} options.businessName   - Business name for branding
 * @param {string} options.tone           - Writing tone
 * @param {number} options.wordCount      - Target word count
 * @param {string} options.customPrompt   - Optional additional instructions
 * @returns {Promise<object>}             - Blog post object
 */
async function generateBlogFromPhoto({
  imageBuffer,
  mimeType = 'image/jpeg',
  metadata = {},
  businessName = '',
  tone = 'professional',
  wordCount = 1000,
  customPrompt = '',
}) {
  if (!GEMINI_API_KEY) {
    throw new Error(
      'GEMINI_API_KEY is not set. Get a free key at https://aistudio.google.com/apikey'
    );
  }

  const base64Image = imageBuffer.toString('base64');

  // Build the metadata context string
  const metaParts = [];
  if (metadata.title) metaParts.push(`Title: ${metadata.title}`);
  if (metadata.caption) metaParts.push(`Caption: ${metadata.caption}`);
  if (metadata.keywords && metadata.keywords.length > 0)
    metaParts.push(`Keywords: ${metadata.keywords.join(', ')}`);
  if (metadata.camera) metaParts.push(`Camera: ${metadata.camera}`);
  if (metadata.lens) metaParts.push(`Lens: ${metadata.lens}`);
  if (metadata.exposure) metaParts.push(`Exposure: ${metadata.exposure}`);
  if (metadata.focalLength) metaParts.push(`Focal Length: ${metadata.focalLength}`);
  if (metadata.iso) metaParts.push(`ISO: ${metadata.iso}`);
  if (metadata.gps) metaParts.push(`Location: ${metadata.gps}`);
  if (metadata.dateTime) metaParts.push(`Date Taken: ${metadata.dateTime}`);

  const metaContext = metaParts.length > 0
    ? `\n\nPhoto Metadata:\n${metaParts.join('\n')}`
    : '';

  const prompt = `You are a professional blog writer for "${businessName}".
Analyze the attached photo and write a compelling blog post about it.

Writing style: ${tone}
Target word count: ~${wordCount} words
${metaContext}
${customPrompt ? `\nAdditional instructions: ${customPrompt}` : ''}

Your response MUST follow this exact format:

1. Write the blog post in HTML using these tags only: h2, h3, p, ul, li, strong, em, blockquote.
   Do NOT include an h1 tag — the title is set separately.
   Start directly with the content (an engaging opening paragraph).

2. After the HTML content, include a metadata block in this exact format:

<seo_data>
{
  "title": "SEO-optimized blog post title (50-60 characters)",
  "metaDescription": "Compelling meta description for search results (150-160 characters)",
  "focusKeyphrase": "primary keyword or phrase",
  "tags": ["tag1", "tag2", "tag3", "tag4", "tag5"],
  "categories": ["Category1", "Category2"],
  "slug": "url-friendly-slug"
}
</seo_data>

If the photo shows a location, weave in travel/location details.
If the photo is artistic, discuss composition, lighting, and technique.
Always write naturally and engagingly — never sound robotic or generic.`;

  const url = `https://generativelanguage.googleapis.com/v1beta/models/${GEMINI_MODEL}:generateContent?key=${GEMINI_API_KEY}`;

  const response = await axios.post(
    url,
    {
      contents: [
        {
          parts: [
            {
              inlineData: {
                mimeType,
                data: base64Image,
              },
            },
            {
              text: prompt,
            },
          ],
        },
      ],
      generationConfig: {
        maxOutputTokens: 8192,
        temperature: 0.8,
      },
    },
    {
      headers: { 'Content-Type': 'application/json' },
      timeout: 60000,
    }
  );

  // Extract the generated text
  const candidates = response.data.candidates;
  if (!candidates || candidates.length === 0) {
    throw new Error('Gemini returned no candidates');
  }

  const text = candidates[0].content.parts
    .map((p) => p.text || '')
    .join('');

  // Parse HTML content and SEO data
  return parseGeminiResponse(text);
}

/**
 * Parse Gemini's response into a structured blog post object.
 */
function parseGeminiResponse(text) {
  // Extract SEO data block
  const seoMatch = text.match(/<seo_data>\s*([\s\S]*?)\s*<\/seo_data>/);
  let seoData = {};
  let htmlContent = text;

  if (seoMatch) {
    htmlContent = text.replace(/<seo_data>[\s\S]*?<\/seo_data>/, '').trim();
    try {
      seoData = JSON.parse(seoMatch[1]);
    } catch (e) {
      console.warn('[Gemini] Failed to parse SEO data JSON:', e.message);
    }
  }

  // Clean up any markdown code fences that Gemini might add
  htmlContent = htmlContent
    .replace(/^```html?\s*/i, '')
    .replace(/\s*```\s*$/, '')
    .trim();

  const title = seoData.title || 'Untitled Photo Post';
  const slug =
    seoData.slug ||
    title
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '');

  return {
    htmlContent,
    title,
    metaDescription: seoData.metaDescription || '',
    focusKeyphrase: seoData.focusKeyphrase || '',
    tags: seoData.tags || [],
    categories: seoData.categories || ['Photography'],
    slug,
  };
}

module.exports = { generateBlogFromPhoto };
