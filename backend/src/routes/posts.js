/**
 * POST /api/posts/generate  — Generate a blog post preview (with optional user images)
 * POST /api/posts/publish   — Publish a previously generated post to WordPress
 */
const express = require('express');
const router = express.Router();
const multer = require('multer');
const path = require('path');
const fs = require('fs');
const { v4: uuidv4 } = require('uuid');
const { config } = require('../config');
const { generateBlogPost } = require('../services/claudeService');
const { findImage, findMultipleImages, downloadImage } = require('../services/imageService');
const { publishPost, uploadImage } = require('../services/wordpressService');

// ── Multer config for user image uploads ──────────────────────────────────────

const uploadsDir = path.join(__dirname, '..', '..', 'uploads');
if (!fs.existsSync(uploadsDir)) fs.mkdirSync(uploadsDir, { recursive: true });

const storage = multer.diskStorage({
  destination: (req, file, cb) => {
    const sessionDir = path.join(uploadsDir, req.uploadSessionId || 'tmp');
    if (!fs.existsSync(sessionDir)) fs.mkdirSync(sessionDir, { recursive: true });
    cb(null, sessionDir);
  },
  filename: (req, file, cb) => {
    const ext = path.extname(file.originalname).toLowerCase() || '.jpg';
    cb(null, `${uuidv4()}${ext}`);
  },
});

const upload = multer({
  storage,
  limits: { fileSize: 10 * 1024 * 1024, files: 10 },
  fileFilter: (req, file, cb) => {
    const allowed = /\.(jpg|jpeg|png|gif|webp)$/i;
    if (allowed.test(path.extname(file.originalname))) {
      cb(null, true);
    } else {
      cb(new Error('Only image files (jpg, png, gif, webp) are allowed'));
    }
  },
});

// ── In-memory store for generated previews (keyed by previewId) ───────────────

const previews = new Map();

// Clean up old previews every 30 minutes
setInterval(() => {
  const cutoff = Date.now() - 60 * 60 * 1000; // 1 hour
  for (const [id, preview] of previews) {
    if (preview.createdAt < cutoff) {
      // Clean up uploaded files
      if (preview.sessionDir && fs.existsSync(preview.sessionDir)) {
        fs.rmSync(preview.sessionDir, { recursive: true, force: true });
      }
      previews.delete(id);
    }
  }
}, 30 * 60 * 1000);

/**
 * Step 1: Generate a blog post preview.
 * Accepts multipart/form-data with images and form fields.
 */
router.post('/generate', (req, res, next) => {
  // Set session ID before multer processes files
  req.uploadSessionId = uuidv4();
  next();
}, upload.array('images', 10), async (req, res) => {
  const {
    businessId,
    topic,
    tone,
    wordCount,
    keywords,
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
    // Parse uploaded images
    const userImages = (req.files || []).map((file, index) => ({
      filename: file.filename,
      originalName: file.originalname,
      path: file.path,
      url: `/uploads/${req.uploadSessionId}/${file.filename}`,
      caption: req.body[`imageCaption_${index}`] || '',
    }));

    // Parse keywords
    let parsedKeywords = [];
    if (keywords) {
      parsedKeywords = typeof keywords === 'string'
        ? keywords.split(',').map(k => k.trim()).filter(Boolean)
        : keywords;
    }

    // Step 1: Generate content with Claude
    const post = await generateBlogPost({
      topic,
      businessName: business.name,
      tone,
      wordCount: parseInt(wordCount) || 1000,
      keywords: parsedKeywords,
      userImages,
    });

    console.log(`[Post] Generated: "${post.title}"`);

    // Step 2: Find stock images from Pexels
    let stockImages = [];
    try {
      const mainImage = await findImage(post.imageSearchQuery);
      if (mainImage) {
        stockImages.push({ ...mainImage, role: 'featured' });
      }
      // Find additional in-content stock images
      if (post.additionalImageQueries && post.additionalImageQueries.length > 0) {
        const additional = await findMultipleImages(post.additionalImageQueries);
        stockImages.push(...additional.map(img => ({ ...img, role: 'inline' })));
      }
    } catch (imgErr) {
      console.warn('[Post] Image search failed (non-fatal):', imgErr.message);
    }

    // Store preview for later publishing
    const previewId = uuidv4();
    const sessionDir = path.join(uploadsDir, req.uploadSessionId);

    previews.set(previewId, {
      post,
      stockImages,
      userImages,
      businessId,
      sessionDir,
      uploadSessionId: req.uploadSessionId,
      createdAt: Date.now(),
    });

    return res.json({
      success: true,
      action: 'preview',
      previewId,
      post: {
        ...post,
        userImages: userImages.map(img => ({
          url: img.url,
          originalName: img.originalName,
          caption: img.caption,
        })),
        stockImages: stockImages.map(img => ({
          url: img.url,
          photographer: img.photographer,
          photographerUrl: img.photographerUrl,
          role: img.role,
        })),
      },
    });
  } catch (err) {
    console.error('[Post] Error:', err.message);
    return res.status(500).json({ error: err.message });
  }
});

/**
 * Step 2: Publish a previously generated preview to WordPress.
 * Body: { previewId, title?, htmlContent? }
 * Title and htmlContent allow the user to make edits before publishing.
 */
router.post('/publish', async (req, res) => {
  const { previewId, title, htmlContent } = req.body;

  if (!previewId) {
    return res.status(400).json({ error: 'previewId is required' });
  }

  const preview = previews.get(previewId);
  if (!preview) {
    return res.status(404).json({ error: 'Preview not found or expired. Please generate again.' });
  }

  const business = config.businesses[preview.businessId];
  if (!business) {
    return res.status(404).json({ error: 'Business configuration not found' });
  }

  try {
    const post = { ...preview.post };

    // Apply user edits if provided
    if (title) post.title = title;
    if (htmlContent) post.htmlContent = htmlContent;

    console.log(`[Post] Publishing: "${post.title}" to ${business.name}`);

    // Step 1: Upload user images to WordPress
    const wpImageUrls = {};
    for (const img of preview.userImages) {
      try {
        const buffer = fs.readFileSync(img.path);
        const mediaId = await uploadImage(
          business.wordpress,
          buffer,
          img.originalName,
          img.caption || post.title
        );
        // Get the WP URL for the uploaded image
        wpImageUrls[img.url] = { mediaId, filename: img.originalName };
        console.log(`[Post] User image uploaded: ${img.originalName} → media ID ${mediaId}`);
      } catch (imgErr) {
        console.warn(`[Post] Failed to upload user image ${img.originalName}:`, imgErr.message);
      }
    }

    // Step 2: Upload featured stock image
    let featuredMediaId = null;
    const featuredImage = preview.stockImages.find(img => img.role === 'featured');
    if (featuredImage) {
      try {
        console.log(`[Post] Uploading featured image: ${featuredImage.url}`);
        const buffer = await downloadImage(featuredImage.url);
        featuredMediaId = await uploadImage(
          business.wordpress,
          buffer,
          `${post.slug}-featured.jpg`,
          post.title
        );
        console.log(`[Post] Featured image uploaded, media ID: ${featuredMediaId}`);

        // Append photographer credit
        post.htmlContent += `\n<p class="photo-credit" style="font-size:0.75em;color:#999;">Featured photo by <a href="${featuredImage.photographerUrl}" target="_blank" rel="noopener">${featuredImage.photographer}</a> on <a href="https://www.pexels.com" target="_blank" rel="noopener">Pexels</a></p>`;
      } catch (imgErr) {
        console.warn('[Post] Featured image upload failed (non-fatal):', imgErr.message);
      }
    }

    // Step 3: Replace local user image URLs with WordPress media URLs
    // For user images that were uploaded, replace the local preview URLs
    // The HTML contains placeholder references that we replace with actual WP-hosted images
    for (const [localUrl, wpData] of Object.entries(wpImageUrls)) {
      // Replace local URLs in the HTML content
      post.htmlContent = post.htmlContent.replace(
        new RegExp(localUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'),
        `${business.wordpress.url}/wp-content/uploads/${new Date().getFullYear()}/${String(new Date().getMonth() + 1).padStart(2, '0')}/${wpData.filename}`
      );
    }

    // Step 4: Publish to WordPress
    const result = await publishPost(business.wordpress, post, featuredMediaId);
    console.log(`[Post] Published! ID: ${result.id} → ${result.url}`);

    // Clean up preview and temp files
    if (preview.sessionDir && fs.existsSync(preview.sessionDir)) {
      fs.rmSync(preview.sessionDir, { recursive: true, force: true });
    }
    previews.delete(previewId);

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
    console.error('[Post] Publish error:', err.message);

    if (err.response?.data) {
      return res.status(502).json({
        error: 'WordPress API error',
        detail: err.response.data,
      });
    }

    return res.status(500).json({ error: err.message });
  }
});

module.exports = router;
