/**
 * POST /api/posts/generate  — Generate and publish a blog post
 * POST /api/posts/preview   — Generate only (no publishing)
 */
const express = require('express');
const router = express.Router();
const { config } = require('../config');
const { generateBlogPost } = require('../services/claudeService');
const { findImage, downloadImage } = require('../services/imageService');
const { publishPost, uploadImage } = require('../services/wordpressService');

/**
 * Generate + optionally publish a blog post.
 * Body: { businessId, topic, tone?, wordCount?, keywords?, publish? }
 */
router.post('/generate', async (req, res) => {
  const {
    businessId,
    topic,
    tone,
    wordCount,
    keywords,
    publish = true,
  } = req.body;

  if (!businessId || !topic) {
    return res.status(400).json({ error: 'businessId and topic are required' });
  }

  const business = config.businesses[businessId];
  if (!business) {
    return res.status(404).json({
      error: `Business "${businessId}" not found`,
      available: Object.keys(config.businesses),
    });
  }

  console.log(`[Post] Generating: "${topic}" for ${business.name}`);

  try {
    // Step 1: Generate content with Claude
    const post = await generateBlogPost({
      topic,
      businessName: business.name,
      tone,
      wordCount,
      keywords: keywords || [],
    });

    console.log(`[Post] Generated: "${post.title}"`);

    if (!publish) {
      return res.json({ success: true, action: 'generated', post });
    }

    // Step 2: Stock image fetching (currently disabled — photos handled manually via Lightroom)
    let mediaId = null;
    // To re-enable, uncomment the block below:
    // try {
    //   const image = await findImage(post.imageSearchQuery);
    //   if (image) {
    //     console.log(`[Post] Uploading image: ${image.url}`);
    //     const buffer = await downloadImage(image.url);
    //     mediaId = await uploadImage(
    //       business.wordpress, buffer, `${post.slug}.jpg`, post.title
    //     );
    //     post.htmlContent += `\n<p class="photo-credit" style="font-size:0.75em;color:#999;">Photo by <a href="${image.photographerUrl}" target="_blank" rel="noopener">${image.photographer}</a> on <a href="https://www.pexels.com" target="_blank" rel="noopener">Pexels</a></p>`;
    //   }
    // } catch (imgErr) {
    //   console.warn('[Post] Image step failed (non-fatal):', imgErr.message);
    // }

    // Step 3: Publish to WordPress
    const result = await publishPost(business.wordpress, post, mediaId);
    console.log(`[Post] Published! ID: ${result.id} → ${result.url}`);

    return res.json({
      success: true,
      action: 'published',
      post: {
        id: result.id,
        title: post.title,
        url: result.url,
        editUrl: result.editUrl,
        slug: post.slug,
        tags: post.tags,
        categories: post.categories,
        metaDescription: post.metaDescription,
        focusKeyphrase: post.focusKeyphrase,
      },
    });
  } catch (err) {
    console.error('[Post] Error:', err.message);

    // Surface WP API errors clearly
    if (err.response?.data) {
      return res.status(502).json({
        error: 'WordPress API error',
        detail: err.response.data,
      });
    }

    return res.status(500).json({ error: err.message });
  }
});

/**
 * Preview only — generate but don't publish.
 */
router.post('/preview', async (req, res) => {
  req.body = { ...req.body, publish: false };
  // Delegate to the shared handler by calling next route inline
  const { businessId, topic, tone, wordCount, keywords } = req.body;

  if (!businessId || !topic) {
    return res.status(400).json({ error: 'businessId and topic are required' });
  }

  const business = config.businesses[businessId];
  if (!business) {
    return res.status(404).json({ error: `Business "${businessId}" not found` });
  }

  try {
    const post = await generateBlogPost({
      topic,
      businessName: business.name,
      tone,
      wordCount,
      keywords: keywords || [],
    });
    return res.json({ success: true, action: 'generated', post });
  } catch (err) {
    return res.status(500).json({ error: err.message });
  }
});

module.exports = router;
