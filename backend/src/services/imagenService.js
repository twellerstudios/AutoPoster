/**
 * Imagen AI Editing Service
 *
 * Integrates with Imagen AI's API to automatically edit photos using
 * your learned editing style (Personal AI Profile).
 *
 * Imagen AI workflow:
 *   1. Upload photos to Imagen AI
 *   2. Imagen applies your trained editing profile
 *   3. Poll for completion
 *   4. Download edited photos
 *
 * Also includes a built-in fallback editor using sharp for basic
 * auto-corrections when Imagen AI is not configured.
 *
 * @see https://www.imagen-ai.com/
 */
const axios = require('axios');
const fs = require('fs').promises;
const path = require('path');
const FormData = require('form-data');
const sharp = require('sharp');

// ── Imagen AI API Client ─────────────────────────────────────────────────────

class ImagenAI {
  constructor(config = {}) {
    this.apiKey = config.apiKey || process.env.IMAGEN_AI_API_KEY || '';
    this.profileId = config.profileId || process.env.IMAGEN_AI_PROFILE_ID || '';
    this.baseUrl = config.baseUrl || process.env.IMAGEN_AI_BASE_URL || 'https://api.imagen-ai.com/v1';
    this.enabled = !!(this.apiKey && this.profileId);

    if (this.enabled) {
      this.client = axios.create({
        baseURL: this.baseUrl,
        headers: {
          'Authorization': `Bearer ${this.apiKey}`,
          'Content-Type': 'application/json',
        },
        timeout: 120000,
      });
      console.log('[Imagen AI] Configured with profile:', this.profileId);
    } else {
      console.log('[Imagen AI] Not configured — will use built-in auto-edit fallback');
    }
  }

  /**
   * Submit a batch of photos for AI editing.
   * Returns a job ID to poll for completion.
   */
  async submitBatch(photoPaths, options = {}) {
    if (!this.enabled) {
      throw new Error('Imagen AI not configured. Set IMAGEN_AI_API_KEY and IMAGEN_AI_PROFILE_ID.');
    }

    const {
      profileId = this.profileId,
      outputFormat = 'jpeg',
      outputQuality = 95,
      cropStraighten = true,
    } = options;

    // Upload photos
    const form = new FormData();
    form.append('profile_id', profileId);
    form.append('output_format', outputFormat);
    form.append('output_quality', String(outputQuality));
    form.append('crop_straighten', String(cropStraighten));

    for (const photoPath of photoPaths) {
      const fileBuffer = await fs.readFile(photoPath);
      form.append('files', fileBuffer, {
        filename: path.basename(photoPath),
        contentType: 'image/jpeg',
      });
    }

    console.log(`[Imagen AI] Submitting ${photoPaths.length} photos for editing...`);

    const response = await this.client.post('/edit/batch', form, {
      headers: form.getHeaders(),
      timeout: 300000, // 5 min for upload
    });

    const jobId = response.data.job_id;
    console.log(`[Imagen AI] Batch submitted. Job ID: ${jobId}`);

    return {
      jobId,
      photoCount: photoPaths.length,
      status: 'processing',
    };
  }

  /**
   * Check the status of an editing job.
   */
  async getJobStatus(jobId) {
    if (!this.enabled) {
      throw new Error('Imagen AI not configured');
    }

    const response = await this.client.get(`/edit/batch/${jobId}`);

    return {
      jobId,
      status: response.data.status, // 'processing', 'completed', 'failed'
      progress: response.data.progress || 0,
      photoCount: response.data.total_photos || 0,
      completedPhotos: response.data.completed_photos || 0,
      downloadUrl: response.data.download_url || null,
      error: response.data.error || null,
    };
  }

  /**
   * Download completed edited photos to a directory.
   */
  async downloadResults(jobId, outputDir) {
    if (!this.enabled) {
      throw new Error('Imagen AI not configured');
    }

    const status = await this.getJobStatus(jobId);
    if (status.status !== 'completed') {
      throw new Error(`Job ${jobId} is not complete (status: ${status.status})`);
    }

    await fs.mkdir(outputDir, { recursive: true });

    // Download the results archive
    const response = await this.client.get(`/edit/batch/${jobId}/download`, {
      responseType: 'arraybuffer',
      timeout: 300000,
    });

    // If it's a zip, extract; if individual files, save directly
    const contentType = response.headers['content-type'];

    if (contentType && contentType.includes('zip')) {
      // Save zip and extract
      const zipPath = path.join(outputDir, `${jobId}.zip`);
      await fs.writeFile(zipPath, response.data);
      // Extract using Node's built-in (Node 22+) or handle manually
      const { execSync } = require('child_process');
      execSync(`unzip -o "${zipPath}" -d "${outputDir}"`, { stdio: 'ignore' });
      await fs.unlink(zipPath);
    } else {
      // Assume it returns a list of download URLs
      const results = JSON.parse(response.data.toString());
      for (const item of results.files || []) {
        const fileResponse = await axios.get(item.url, { responseType: 'arraybuffer', timeout: 60000 });
        await fs.writeFile(path.join(outputDir, item.filename), fileResponse.data);
      }
    }

    const files = await fs.readdir(outputDir);
    const imageFiles = files.filter(f => /\.(jpe?g|png|tiff?)$/i.test(f));

    console.log(`[Imagen AI] Downloaded ${imageFiles.length} edited photos to ${outputDir}`);

    return {
      outputDir,
      files: imageFiles,
      count: imageFiles.length,
    };
  }

  /**
   * Full edit workflow: submit → poll → download.
   * Blocks until complete or timeout.
   */
  async editPhotos(photoPaths, outputDir, options = {}) {
    const { pollInterval = 10000, maxWait = 3600000 } = options; // 10s poll, 1hr max

    const batch = await this.submitBatch(photoPaths, options);
    const startTime = Date.now();

    console.log(`[Imagen AI] Waiting for job ${batch.jobId} to complete...`);

    while (Date.now() - startTime < maxWait) {
      await new Promise(resolve => setTimeout(resolve, pollInterval));

      const status = await this.getJobStatus(batch.jobId);
      console.log(`[Imagen AI] Job ${batch.jobId}: ${status.status} (${status.progress}%)`);

      if (status.status === 'completed') {
        return await this.downloadResults(batch.jobId, outputDir);
      }

      if (status.status === 'failed') {
        throw new Error(`Imagen AI job failed: ${status.error}`);
      }
    }

    throw new Error(`Imagen AI job ${batch.jobId} timed out after ${maxWait / 1000}s`);
  }
}

// ── Built-in Auto-Edit Fallback ──────────────────────────────────────────────

/**
 * Apply basic auto-corrections when Imagen AI is not available.
 * Uses sharp for:
 *   - Auto white balance (normalize)
 *   - Contrast enhancement (CLAHE-like via linear)
 *   - Subtle sharpening
 *   - Color vibrance boost
 */
async function autoEditFallback(inputDir, outputDir, options = {}) {
  const {
    sharpen = true,
    normalize = true,
    saturationBoost = 1.1,
    contrastBoost = 1.05,
    outputQuality = 92,
    outputFormat = 'jpeg',
  } = options;

  const supportedExts = new Set(['.jpg', '.jpeg', '.png', '.tif', '.tiff', '.webp']);

  await fs.mkdir(outputDir, { recursive: true });

  const entries = await fs.readdir(inputDir, { withFileTypes: true });
  const imageFiles = entries
    .filter(e => e.isFile() && supportedExts.has(path.extname(e.name).toLowerCase()))
    .map(e => e.name);

  console.log(`[Auto-Edit] Processing ${imageFiles.length} photos with built-in corrections...`);

  const results = [];

  for (const filename of imageFiles) {
    try {
      const inputPath = path.join(inputDir, filename);
      const outputName = path.parse(filename).name + '.' + outputFormat;
      const outputPath = path.join(outputDir, outputName);

      let pipeline = sharp(inputPath);

      // Auto white balance / normalize levels
      if (normalize) {
        pipeline = pipeline.normalize();
      }

      // Contrast and brightness boost via linear transform
      if (contrastBoost !== 1.0) {
        pipeline = pipeline.linear(contrastBoost, -(128 * (contrastBoost - 1)));
      }

      // Saturation boost via modulate
      if (saturationBoost !== 1.0) {
        pipeline = pipeline.modulate({ saturation: saturationBoost });
      }

      // Subtle sharpening
      if (sharpen) {
        pipeline = pipeline.sharpen({ sigma: 0.8, m1: 0.5, m2: 0.5 });
      }

      // Output
      if (outputFormat === 'jpeg') {
        pipeline = pipeline.jpeg({ quality: outputQuality, mozjpeg: true });
      } else if (outputFormat === 'png') {
        pipeline = pipeline.png({ quality: outputQuality });
      } else if (outputFormat === 'webp') {
        pipeline = pipeline.webp({ quality: outputQuality });
      }

      await pipeline.toFile(outputPath);
      results.push(outputName);
    } catch (err) {
      console.warn(`[Auto-Edit] Skipping ${filename}: ${err.message}`);
    }
  }

  console.log(`[Auto-Edit] Completed: ${results.length}/${imageFiles.length} photos edited`);

  return {
    outputDir,
    files: results,
    count: results.length,
    method: 'builtin',
  };
}

// ── Unified edit function ────────────────────────────────────────────────────

/**
 * Edit photos using Imagen AI if configured, otherwise use built-in fallback.
 */
async function editPhotos(inputDir, outputDir, options = {}) {
  const imagen = new ImagenAI();

  if (imagen.enabled) {
    // Collect photo paths from input directory
    const entries = await fs.readdir(inputDir, { withFileTypes: true });
    const supportedExts = new Set([
      '.jpg', '.jpeg', '.png', '.tif', '.tiff', '.webp',
      '.cr2', '.cr3', '.nef', '.arw', '.dng',
    ]);
    const photoPaths = entries
      .filter(e => e.isFile() && supportedExts.has(path.extname(e.name).toLowerCase()))
      .map(e => path.join(inputDir, e.name));

    return await imagen.editPhotos(photoPaths, outputDir, options);
  }

  // Fallback to built-in auto-edit
  return await autoEditFallback(inputDir, outputDir, options);
}

module.exports = {
  ImagenAI,
  editPhotos,
  autoEditFallback,
};
