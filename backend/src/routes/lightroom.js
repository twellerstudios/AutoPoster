/**
 * Lightroom Export routes.
 * Receives photos from the Lightroom Classic export plugin,
 * optionally generates an AI blog post with Gemini, and publishes to WordPress.
 */
const express = require('express');
const multer = require('multer');
const { config } = require('../config');
const { generateBlogFromPhoto } = require('../services/geminiService');
const { publishPost, uploadImage } = require('../services/wordpressService');

const router = express.Router();

// Store uploads in memory (photos are forwarded to WordPress, not kept on disk)
const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: 50 * 1024 * 1024 }, // 50 MB max
});

/**
 * POST /api/lightroom/upload
 *
 * Multipart form fields:
 *   photo        - The image file
 *   metadata     - JSON string with EXIF/Lightroom metadata
 *   businessId   - Which business/WordPress site to post to
 *   generateBlog - "true" to generate an AI blog post
 *   tone         - Writing tone (default: professional)
 *   wordCount    - Target word count (default: 1000)
 *   publish      - "true" to publish to WordPress
 *   customPrompt - Optional extra instructions for AI
 */
router.post('/upload', upload.single('photo'), async (req, res) => {
  try {
    const file = req.file;
    if (!file) {
      return res.status(400).json({ success: false, error: 'No photo uploaded' });
    }

    const {
      businessId,
      generateBlog = 'true',
      tone = 'professional',
      wordCount = '1000',
      publish = 'true',
      customPrompt = '',
    } = req.body;

    // Parse metadata JSON
    let metadata = {};
    if (req.body.metadata) {
      try {
        metadata = JSON.parse(req.body.metadata);
      } catch {
        console.warn('[Lightroom] Could not parse metadata JSON');
      }
    }

    // Validate business
    if (!businessId || !config.businesses[businessId]) {
      const available = Object.keys(config.businesses).join(', ');
      return res.status(400).json({
        success: false,
        error: `Unknown businessId "${businessId}". Available: ${available}`,
      });
    }

    const business = config.businesses[businessId];
    const shouldGenerate = generateBlog === 'true';
    const shouldPublish = publish === 'true';

    console.log(`[Lightroom] Received photo: ${file.originalname} (${(file.size / 1024).toFixed(0)} KB)`);
    console.log(`[Lightroom] Business: ${business.name} | Generate: ${shouldGenerate} | Publish: ${shouldPublish}`);

    let post = null;
    let wpResult = null;

    if (shouldGenerate) {
      // Generate AI blog post from the photo
      console.log('[Lightroom] Generating AI blog post with Gemini...');
      post = await generateBlogFromPhoto({
        imageBuffer: file.buffer,
        mimeType: file.mimetype,
        metadata,
        businessName: business.name,
        tone,
        wordCount: parseInt(wordCount, 10) || 1000,
        customPrompt,
      });
      console.log(`[Lightroom] Generated: "${post.title}"`);
    }

    if (shouldPublish) {
      const wpConfig = business.wordpress;

      // Upload the photo to WordPress media library
      console.log('[Lightroom] Uploading photo to WordPress...');
      const altText = metadata.title || metadata.caption || file.originalname;
      const mediaId = await uploadImage(wpConfig, file.buffer, file.originalname, altText);
      console.log(`[Lightroom] Photo uploaded — media ID: ${mediaId}`);

      if (post) {
        // Publish the full blog post with featured image
        wpResult = await publishPost(wpConfig, post, mediaId);
        console.log(`[Lightroom] Published: ${wpResult.url}`);
      } else {
        // Just upload the photo (no blog post)
        wpResult = { mediaId, message: 'Photo uploaded to media library (no blog post generated)' };
      }
    }

    res.json({
      success: true,
      action: shouldPublish ? 'published' : 'generated',
      post: post
        ? {
            title: post.title,
            slug: post.slug,
            metaDescription: post.metaDescription,
            focusKeyphrase: post.focusKeyphrase,
            tags: post.tags,
            categories: post.categories,
            ...(wpResult && wpResult.id ? { id: wpResult.id, url: wpResult.url, editUrl: wpResult.editUrl } : {}),
          }
        : null,
      media: wpResult && wpResult.mediaId ? { mediaId: wpResult.mediaId } : null,
    });
  } catch (err) {
    console.error('[Lightroom] Error:', err.message || err);
    res.status(500).json({
      success: false,
      error: err.message || 'Failed to process photo',
    });
  }
});

/**
 * GET /api/lightroom/status
 * Returns plugin status and available businesses.
 */
router.get('/status', (req, res) => {
  const geminiConfigured = !!process.env.GEMINI_API_KEY;
  res.json({
    ok: true,
    geminiConfigured,
    businesses: Object.entries(config.businesses).map(([id, b]) => ({
      id,
      name: b.name,
      wordpressUrl: b.wordpress.url,
    })),
  });
});

module.exports = router;
