/**
 * Blog post generation & publishing routes.
 *
 * POST /api/posts/generate        — AI-generate a blog post preview (API mode)
 * POST /api/posts/manual-prompt   — Get the prompt to paste into claude.ai (free mode)
 * POST /api/posts/manual-parse    — Parse content pasted back from claude.ai
 * POST /api/posts/publish         — Publish a preview to WordPress
 */
const express = require('express');
const router = express.Router();
const multer = require('multer');
const path = require('path');
const fs = require('fs');
const { v4: uuidv4 } = require('uuid');
const { config } = require('../config');
const { generateBlogPost, getManualPrompt, parseManualContent, isApiModeAvailable } = require('../services/claudeService');
const { findImage, findMultipleImages, downloadImage } = require('../services/imageService');
const { publishPost, uploadImage } = require('../services/wordpressService');
const { publishToFacebook } = require('../services/facebookService');
const { publishToBuffer } = require('../services/bufferService');

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
      cb(new Error(
        `"${file.originalname}" is not a supported image format. ` +
        'Please use JPG, PNG, GIF, or WEBP files.'
      ));
    }
  },
});

// Multer error handler
function handleMulterError(err, req, res, next) {
  if (err instanceof multer.MulterError) {
    if (err.code === 'LIMIT_FILE_SIZE') {
      return res.status(400).json({
        error: 'One of your images is too large. Maximum file size is 10 MB per image.',
      });
    }
    if (err.code === 'LIMIT_FILE_COUNT') {
      return res.status(400).json({
        error: 'Too many images. You can upload up to 10 images per post.',
      });
    }
    return res.status(400).json({ error: `Upload error: ${err.message}` });
  }
  if (err) {
    return res.status(400).json({ error: err.message });
  }
  next();
}

// ── In-memory store for generated previews ────────────────────────────────────

const previews = new Map();

// Clean up old previews every 30 minutes
setInterval(() => {
  const cutoff = Date.now() - 60 * 60 * 1000;
  for (const [id, preview] of previews) {
    if (preview.createdAt < cutoff) {
      if (preview.sessionDir && fs.existsSync(preview.sessionDir)) {
        fs.rmSync(preview.sessionDir, { recursive: true, force: true });
      }
      previews.delete(id);
    }
  }
}, 30 * 60 * 1000);

// ── Helper: parse uploaded images from request ────────────────────────────────

function parseUserImages(req) {
  return (req.files || []).map((file, index) => ({
    filename: file.filename,
    originalName: file.originalname,
    path: file.path,
    url: `/uploads/${req.uploadSessionId}/${file.filename}`,
    caption: req.body[`imageCaption_${index}`] || '',
  }));
}

function parseKeywords(keywords) {
  if (!keywords) return [];
  return typeof keywords === 'string'
    ? keywords.split(',').map(k => k.trim()).filter(Boolean)
    : keywords;
}

// ── Helper: find stock images (non-fatal on failure) ──────────────────────────

async function findStockImages(post) {
  const stockImages = [];
  try {
    const mainImage = await findImage(post.imageSearchQuery);
    if (mainImage) {
      stockImages.push({ ...mainImage, role: 'featured' });
    }
    if (post.additionalImageQueries && post.additionalImageQueries.length > 0) {
      const additional = await findMultipleImages(post.additionalImageQueries);
      stockImages.push(...additional.map(img => ({ ...img, role: 'inline' })));
    }
  } catch (imgErr) {
    console.warn('[Post] Image search failed (non-fatal):', imgErr.message);
  }
  return stockImages;
}

// ── Helper: replace STOCK_IMAGE_PLACEHOLDER with real Pexels URLs ────────────

function injectStockImages(post, stockImages) {
  if (!post.htmlContent || stockImages.length === 0) return;

  // Inline images (not featured) are what the prompt asked Claude to place in the HTML
  const inlineImages = stockImages.filter(img => img.role === 'inline');
  let idx = 0;

  post.htmlContent = post.htmlContent.replace(/STOCK_IMAGE_PLACEHOLDER/g, () => {
    if (idx < inlineImages.length) {
      return inlineImages[idx++].url;
    }
    // Fallback: use the featured image if we run out of inline images
    const featured = stockImages.find(img => img.role === 'featured');
    return featured ? featured.url : '';
  });
}

// ── Helper: store a preview ───────────────────────────────────────────────────

function storePreview({ post, stockImages, userImages, businessId, sessionDir, uploadSessionId }) {
  const previewId = uuidv4();
  previews.set(previewId, {
    post,
    stockImages,
    userImages,
    businessId,
    sessionDir,
    uploadSessionId,
    createdAt: Date.now(),
  });
  return previewId;
}

function buildPreviewResponse(previewId, post, stockImages, userImages) {
  return {
    success: true,
    action: 'preview',
    previewId,
    aiMode: isApiModeAvailable() ? 'api' : 'manual',
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
  };
}

/**
 * POST /api/posts/generate — Generate using Anthropic API (requires API key).
 */
router.post('/generate', (req, res, next) => {
  req.uploadSessionId = uuidv4();
  next();
}, upload.array('images', 10), handleMulterError, async (req, res) => {
  const { businessId, topic, tone, wordCount, keywords } = req.body;

  if (!businessId) {
    return res.status(400).json({
      error: 'Please select a business before generating.',
      hint: 'If no businesses appear in the dropdown, add one in Settings first.',
    });
  }
  if (!topic || !topic.trim()) {
    return res.status(400).json({
      error: 'Please enter a topic for your blog post.',
      hint: 'Example: "World Down Syndrome Day - awareness, resources, and why inclusion matters"',
    });
  }

  const business = config.businesses[businessId];
  if (!business) {
    return res.status(404).json({
      error: `Business "${businessId}" not found.`,
      hint: 'This business may have been deleted. Go to Settings to check your businesses.',
      available: Object.keys(config.businesses),
    });
  }

  console.log(`[Post] Generating (API mode): "${topic}" for ${business.name}`);

  try {
    const userImages = parseUserImages(req);
    const parsedKeywords = parseKeywords(keywords);

    const post = await generateBlogPost({
      topic: topic.trim(),
      businessName: business.name,
      tone,
      wordCount: parseInt(wordCount) || 1000,
      keywords: parsedKeywords,
      userImages,
    });

    console.log(`[Post] Generated: "${post.title}"`);

    const stockImages = await findStockImages(post);
    injectStockImages(post, stockImages);
    const sessionDir = path.join(uploadsDir, req.uploadSessionId);
    const previewId = storePreview({ post, stockImages, userImages, businessId, sessionDir, uploadSessionId: req.uploadSessionId });

    return res.json(buildPreviewResponse(previewId, post, stockImages, userImages));
  } catch (err) {
    console.error('[Post] Error:', err.message);
    return res.status(500).json({ error: err.message });
  }
});

/**
 * POST /api/posts/manual-prompt — Get the AI prompt to paste into claude.ai (free mode).
 */
router.post('/manual-prompt', (req, res, next) => {
  req.uploadSessionId = uuidv4();
  next();
}, upload.array('images', 10), handleMulterError, async (req, res) => {
  const { businessId, topic, tone, wordCount, keywords } = req.body;

  if (!businessId) {
    return res.status(400).json({
      error: 'Please select a business before generating.',
      hint: 'If no businesses appear in the dropdown, add one in Settings first.',
    });
  }
  if (!topic || !topic.trim()) {
    return res.status(400).json({
      error: 'Please enter a topic for your blog post.',
    });
  }

  const business = config.businesses[businessId];
  if (!business) {
    return res.status(404).json({
      error: `Business "${businessId}" not found.`,
      hint: 'Go to Settings to check your businesses.',
    });
  }

  const userImages = parseUserImages(req);
  const parsedKeywords = parseKeywords(keywords);

  const prompt = getManualPrompt({
    topic: topic.trim(),
    businessName: business.name,
    tone,
    wordCount: parseInt(wordCount) || 1000,
    keywords: parsedKeywords,
    userImages,
  });

  // Store the session for later when the user pastes content back
  const sessionId = req.uploadSessionId;
  const sessionDir = path.join(uploadsDir, sessionId);

  // Store a temporary entry so we can attach images later
  previews.set(`manual_${sessionId}`, {
    businessId,
    userImages,
    sessionDir,
    uploadSessionId: sessionId,
    topic: topic.trim(),
    createdAt: Date.now(),
  });

  res.json({
    success: true,
    prompt,
    sessionId,
    instructions: [
      '1. Copy the prompt below',
      '2. Open claude.ai in a new tab (uses your free $20 subscription)',
      '3. Paste the prompt and send it',
      '4. Wait for Claude to generate the full blog post',
      '5. Copy Claude\'s entire response',
      '6. Come back here and paste it in',
    ],
  });
});

/**
 * POST /api/posts/manual-parse — Parse pasted content from Claude and create a preview.
 * Body: { sessionId, content }
 */
router.post('/manual-parse', async (req, res) => {
  const { sessionId, content } = req.body;

  if (!content || !content.trim()) {
    return res.status(400).json({
      error: 'Please paste the content from Claude.',
      hint: 'Copy Claude\'s entire response (the full HTML blog post with the <seo_data> block at the end) and paste it here.',
    });
  }

  if (!sessionId) {
    return res.status(400).json({
      error: 'Session expired. Please go back and generate a new prompt.',
    });
  }

  const session = previews.get(`manual_${sessionId}`);
  if (!session) {
    return res.status(404).json({
      error: 'Your session has expired (sessions last 1 hour). Please go back and start again.',
    });
  }

  try {
    const post = parseManualContent(content.trim(), session.topic);

    if (!post.htmlContent || post.htmlContent.length < 50) {
      return res.status(400).json({
        error: 'The pasted content doesn\'t look like a valid blog post.',
        hint: 'Make sure you copied Claude\'s entire response, including all the HTML content and the <seo_data> block at the end.',
      });
    }

    console.log(`[Post] Manual content parsed: "${post.title}"`);

    const stockImages = await findStockImages(post);
    injectStockImages(post, stockImages);
    const previewId = storePreview({
      post,
      stockImages,
      userImages: session.userImages,
      businessId: session.businessId,
      sessionDir: session.sessionDir,
      uploadSessionId: session.uploadSessionId,
    });

    // Clean up manual session
    previews.delete(`manual_${sessionId}`);

    return res.json(buildPreviewResponse(previewId, post, stockImages, session.userImages));
  } catch (err) {
    console.error('[Post] Manual parse error:', err.message);
    return res.status(500).json({
      error: 'Failed to parse the pasted content.',
      hint: 'Make sure you copied the complete response from Claude, including the <seo_data> JSON block at the end.',
    });
  }
});

/**
 * POST /api/posts/preview/:previewId/images — Add more images to an existing preview.
 */
router.post('/preview/:previewId/images', (req, res, next) => {
  req.uploadSessionId = req.params.previewId;
  next();
}, upload.array('images', 10), handleMulterError, async (req, res) => {
  const { previewId } = req.params;

  const preview = previews.get(previewId);
  if (!preview) {
    return res.status(404).json({
      error: 'Preview has expired. Please generate the post again.',
    });
  }

  const newImages = (req.files || []).map((file, index) => ({
    filename: file.filename,
    originalName: file.originalname,
    path: file.path,
    url: `/uploads/${req.uploadSessionId}/${file.filename}`,
    caption: req.body[`imageCaption_${index}`] || '',
  }));

  if (newImages.length === 0) {
    return res.status(400).json({ error: 'No images were uploaded.' });
  }

  // Add the new images to the preview
  preview.userImages = [...preview.userImages, ...newImages];

  // Build figure HTML for each new image and append to the post content
  const figureHtml = newImages.map(img => {
    const caption = img.caption || img.originalName;
    return `\n<figure style="margin: 24px 0; text-align: center;">` +
      `<img src="${img.url}" alt="${caption}" style="max-width: 100%; height: auto; border-radius: 8px;" />` +
      `<figcaption style="font-size: 0.9em; color: #666; margin-top: 8px;">${caption}</figcaption>` +
      `</figure>`;
  }).join('\n');

  // Find the last closing </p> or </section> or </ul> before the end, and insert before the conclusion
  // Simple approach: append before the last section or at the end
  preview.post.htmlContent += figureHtml;

  console.log(`[Post] Added ${newImages.length} image(s) to preview ${previewId}`);

  return res.json({
    success: true,
    userImages: preview.userImages.map(img => ({
      url: img.url,
      originalName: img.originalName,
      caption: img.caption,
    })),
    htmlContent: preview.post.htmlContent,
  });
});

/**
 * POST /api/posts/publish — Publish a preview to selected platforms.
 * Body: { previewId, title?, htmlContent?, platforms: ['wordpress', 'facebook'] }
 * If platforms is omitted, defaults to ['wordpress'] for backward compatibility.
 */
router.post('/publish', async (req, res) => {
  const { previewId, title, htmlContent, platforms } = req.body;

  // Default to WordPress only if not specified (backward compatible)
  const targetPlatforms = platforms && platforms.length > 0 ? platforms : ['wordpress'];

  if (!previewId) {
    return res.status(400).json({
      error: 'Missing preview reference. Please generate or paste your content first.',
    });
  }

  const preview = previews.get(previewId);
  if (!preview) {
    return res.status(404).json({
      error: 'Your preview has expired (previews last 1 hour). Please generate the post again.',
      hint: 'Previews are temporary. If you took a long break, just re-generate the post.',
    });
  }

  const business = config.businesses[preview.businessId];
  if (!business) {
    return res.status(404).json({
      error: `The business "${preview.businessId}" was not found. It may have been deleted from Settings.`,
      hint: 'Go to Settings to check your business configurations.',
    });
  }

  // Validate platform configs
  const publishToWp = targetPlatforms.includes('wordpress');
  const publishToFb = targetPlatforms.includes('facebook');
  const publishToBuf = targetPlatforms.includes('buffer');

  if (publishToWp) {
    if (!business.wordpress.url || !business.wordpress.username || !business.wordpress.appPassword) {
      return res.status(422).json({
        error: `"${business.name}" has incomplete WordPress settings.`,
        hint: 'Go to Settings and make sure this business has a WordPress URL, username, and application password configured.',
      });
    }
  }

  if (publishToFb) {
    if (!business.facebook.pageId || !business.facebook.pageAccessToken) {
      return res.status(422).json({
        error: `"${business.name}" has no Facebook Page configured.`,
        hint: 'Go to Settings and add a Facebook Page ID and Page Access Token for this business.',
      });
    }
  }

  if (publishToBuf) {
    if (!config.bufferApiToken) {
      return res.status(422).json({
        error: 'Buffer API token is not configured.',
        hint: 'Go to Settings and add your Buffer API token.',
      });
    }
    if (!business.buffer.channelIds || business.buffer.channelIds.length === 0) {
      return res.status(422).json({
        error: `"${business.name}" has no Buffer channels configured.`,
        hint: 'Go to Settings and select which Buffer channels to use for this business.',
      });
    }
  }

  try {
    const post = { ...preview.post };
    if (title) post.title = title;
    if (htmlContent) post.htmlContent = htmlContent;

    console.log(`[Post] Publishing: "${post.title}" to ${business.name} → [${targetPlatforms.join(', ')}]`);

    const results = { platforms: {} };
    let wpPostUrl = null;
    let featuredImageUrl = null;

    // ── WordPress publishing ──────────────────────────────────────────────
    if (publishToWp) {
      // Upload user images to WordPress and collect real URLs
      const wpImageUrls = {};
      for (const img of preview.userImages) {
        try {
          if (!fs.existsSync(img.path)) {
            console.warn(`[Post] User image file not found: ${img.path} — skipping`);
            continue;
          }
          const buffer = fs.readFileSync(img.path);
          const media = await uploadImage(
            business.wordpress,
            buffer,
            img.originalName,
            img.caption || post.title
          );
          wpImageUrls[img.url] = media.sourceUrl;
          console.log(`[Post] User image uploaded: ${img.originalName} → ${media.sourceUrl}`);
        } catch (imgErr) {
          console.warn(`[Post] Failed to upload "${img.originalName}": ${imgErr.message}`);
        }
      }

      // Upload featured stock image
      let featuredMediaId = null;
      const featuredImage = preview.stockImages.find(img => img.role === 'featured');
      if (featuredImage) {
        try {
          const buffer = await downloadImage(featuredImage.url);
          const media = await uploadImage(
            business.wordpress,
            buffer,
            `${post.slug}-featured.jpg`,
            post.title
          );
          featuredMediaId = media.id;
          featuredImageUrl = media.sourceUrl;
          post.htmlContent += `\n<p class="photo-credit" style="font-size:0.75em;color:#999;">Featured photo by <a href="${featuredImage.photographerUrl}" target="_blank" rel="noopener">${featuredImage.photographer}</a> on <a href="https://www.pexels.com" target="_blank" rel="noopener">Pexels</a></p>`;
        } catch (imgErr) {
          console.warn('[Post] Featured image upload failed (non-fatal):', imgErr.message);
        }
      }

      // Upload inline stock images and replace placeholder/broken img tags
      const inlineImages = preview.stockImages.filter(img => img.role === 'inline');
      if (inlineImages.length > 0) {
        const imgTagRegex = /<img\s+[^>]*src="([^"]*)"[^>]*>/gi;
        const brokenImgs = [];
        let match;
        while ((match = imgTagRegex.exec(post.htmlContent)) !== null) {
          const src = match[1];
          const isLocalUpload = src.startsWith('/uploads/');
          const isWpUrl = business.wordpress.url && src.startsWith(business.wordpress.url);
          if (!isLocalUpload && !isWpUrl) {
            brokenImgs.push({ fullMatch: match[0], src, index: match.index });
          }
        }

        for (let i = 0; i < Math.min(brokenImgs.length, inlineImages.length); i++) {
          const stockImg = inlineImages[i];
          try {
            const buffer = await downloadImage(stockImg.url);
            const filename = `${post.slug}-inline-${i + 1}.jpg`;
            const media = await uploadImage(
              business.wordpress,
              buffer,
              filename,
              brokenImgs[i].fullMatch.match(/alt="([^"]*)"/i)?.[1] || post.title
            );
            const fixedTag = brokenImgs[i].fullMatch.replace(brokenImgs[i].src, media.sourceUrl);
            const credit = `<p class="photo-credit" style="font-size:0.75em;color:#999;text-align:center;">Photo by <a href="${stockImg.photographerUrl}" target="_blank" rel="noopener">${stockImg.photographer}</a> on <a href="https://www.pexels.com" target="_blank" rel="noopener">Pexels</a></p>`;
            post.htmlContent = post.htmlContent.replace(brokenImgs[i].fullMatch, fixedTag + '\n' + credit);
            console.log(`[Post] Inline stock image uploaded: ${filename} → ${media.sourceUrl}`);
          } catch (imgErr) {
            post.htmlContent = post.htmlContent.replace(brokenImgs[i].fullMatch, '');
            console.warn(`[Post] Inline image upload failed, removed broken tag: ${imgErr.message}`);
          }
        }

        for (let i = inlineImages.length; i < brokenImgs.length; i++) {
          post.htmlContent = post.htmlContent.replace(brokenImgs[i].fullMatch, '');
          console.log('[Post] Removed extra broken img tag without replacement');
        }
      }

      // Replace local /uploads/ URLs with real WordPress URLs
      for (const [localUrl, wpUrl] of Object.entries(wpImageUrls)) {
        post.htmlContent = post.htmlContent.replace(
          new RegExp(localUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'),
          wpUrl
        );
      }

      // Publish to WordPress
      const wpResult = await publishPost(business.wordpress, post, featuredMediaId);
      wpPostUrl = wpResult.url;
      console.log(`[Post] WordPress published! ID: ${wpResult.id} → ${wpResult.url}`);

      results.platforms.wordpress = {
        success: true,
        id: wpResult.id,
        url: wpResult.url,
        editUrl: wpResult.editUrl,
      };
    }

    // ── Facebook publishing ───────────────────────────────────────────────
    if (publishToFb) {
      try {
        // If we published to WP, use the WP post URL as the link
        // If we have a featured stock image URL, use that too
        const fbOptions = {};
        if (wpPostUrl) fbOptions.linkUrl = wpPostUrl;

        // Use the featured stock image for the FB post if available
        const featuredImage = preview.stockImages.find(img => img.role === 'featured');
        if (featuredImageUrl) {
          fbOptions.imageUrl = featuredImageUrl;
        } else if (featuredImage) {
          fbOptions.imageUrl = featuredImage.url;
        }

        const fbResult = await publishToFacebook(business.facebook, post, fbOptions);
        console.log(`[Post] Facebook published! ID: ${fbResult.id} → ${fbResult.url}`);

        results.platforms.facebook = {
          success: true,
          id: fbResult.id,
          url: fbResult.url,
        };
      } catch (fbErr) {
        console.error('[Post] Facebook publish failed:', fbErr.message);
        results.platforms.facebook = {
          success: false,
          error: fbErr.response?.data?.error?.message || fbErr.message,
        };
      }
    }

    // ── Buffer publishing ──────────────────────────────────────────────────
    if (publishToBuf) {
      try {
        const bufferOptions = {};
        if (wpPostUrl) bufferOptions.linkUrl = wpPostUrl;

        // Pass featured image for platforms that need it (Instagram, etc.)
        const bufFeaturedImage = preview.stockImages.find(img => img.role === 'featured');
        if (featuredImageUrl) {
          bufferOptions.imageUrl = featuredImageUrl;
        } else if (bufFeaturedImage) {
          bufferOptions.imageUrl = bufFeaturedImage.url;
        }

        const bufferResults = await publishToBuffer(
          config.bufferApiToken,
          business.buffer.channelIds,
          post,
          bufferOptions
        );

        const successCount = bufferResults.filter(r => r.success).length;
        const failedResults = bufferResults.filter(r => !r.success);

        console.log(`[Post] Buffer published to ${successCount}/${bufferResults.length} channel(s)`);

        results.platforms.buffer = {
          success: successCount > 0,
          channels: bufferResults,
          summary: failedResults.length > 0
            ? `Published to ${successCount} channel(s), ${failedResults.length} failed`
            : `Published to ${successCount} channel(s)`,
        };
      } catch (bufErr) {
        console.error('[Post] Buffer publish failed:', bufErr.message);
        results.platforms.buffer = {
          success: false,
          error: bufErr.message,
        };
      }
    }

    // Clean up
    if (preview.sessionDir && fs.existsSync(preview.sessionDir)) {
      fs.rmSync(preview.sessionDir, { recursive: true, force: true });
    }
    previews.delete(previewId);

    // Build response — backward compatible
    const wpData = results.platforms.wordpress || {};
    return res.json({
      success: true,
      action: 'published',
      platforms: results.platforms,
      post: {
        id: wpData.id || null,
        title: post.title,
        url: wpData.url || null,
        editUrl: wpData.editUrl || null,
        slug: post.slug,
        tags: post.tags,
        categories: post.categories,
        metaDescription: post.metaDescription,
        focusKeyphrase: post.focusKeyphrase,
      },
    });
  } catch (err) {
    console.error('[Post] Publish error:', err.message);

    if (err.response?.status === 401 || err.response?.status === 403) {
      return res.status(502).json({
        error: 'WordPress rejected your credentials.',
        hint: 'Go to Settings and check the username and application password for this business. You may need to create a new application password in WordPress Admin > Users > Profile.',
        detail: err.response.data,
      });
    }
    if (err.response?.status === 404) {
      return res.status(502).json({
        error: 'Could not reach the WordPress REST API.',
        hint: 'Make sure the WordPress URL in Settings is correct and the REST API is enabled on your site.',
        detail: err.response.data,
      });
    }
    if (err.code === 'ENOTFOUND' || err.code === 'ECONNREFUSED') {
      return res.status(502).json({
        error: `Could not connect to "${business.wordpress.url}".`,
        hint: 'The site may be down, or the URL in Settings may be incorrect. Check your internet connection and try again.',
      });
    }

    if (err.response?.data) {
      return res.status(502).json({
        error: 'An error occurred while publishing.',
        detail: err.response.data,
        hint: 'This might be a permissions issue. Check your settings for the platforms you selected.',
      });
    }

    return res.status(500).json({ error: err.message });
  }
});

module.exports = router;
