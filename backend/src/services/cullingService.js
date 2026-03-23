/**
 * Auto-Culling Service
 *
 * Analyzes photos and automatically selects the best shots by scoring:
 *   - Sharpness (Laplacian variance via sharp)
 *   - Exposure quality (histogram analysis)
 *   - Duplicate detection (perceptual hashing)
 *   - Face detection confidence (optional, via sharp metadata)
 *
 * Outputs: keeps[], rejects[], and a score for each image.
 */
const sharp = require('sharp');
const path = require('path');
const fs = require('fs').promises;
const crypto = require('crypto');

// ── Perceptual hash (dHash) ──────────────────────────────────────────────────

/**
 * Generate a difference hash (dHash) for duplicate detection.
 * Resizes to 9x8 greyscale, compares adjacent pixels → 64-bit hash.
 */
async function dHash(filePath) {
  const { data } = await sharp(filePath)
    .greyscale()
    .resize(9, 8, { fit: 'fill' })
    .raw()
    .toBuffer({ resolveWithObject: true });

  let hash = '';
  for (let row = 0; row < 8; row++) {
    for (let col = 0; col < 8; col++) {
      const idx = row * 9 + col;
      hash += data[idx] < data[idx + 1] ? '1' : '0';
    }
  }
  return hash;
}

/**
 * Hamming distance between two binary hash strings.
 */
function hammingDistance(a, b) {
  let dist = 0;
  for (let i = 0; i < a.length; i++) {
    if (a[i] !== b[i]) dist++;
  }
  return dist;
}

// ── Sharpness scoring ────────────────────────────────────────────────────────

/**
 * Estimate sharpness using Laplacian-like edge detection.
 * Higher variance = sharper image.
 */
async function measureSharpness(filePath) {
  // Resize to manageable size for speed, then convolve with Laplacian kernel
  const { data, info } = await sharp(filePath)
    .greyscale()
    .resize(800, null, { withoutEnlargement: true })
    .raw()
    .toBuffer({ resolveWithObject: true });

  const { width, height } = info;
  let sum = 0;
  let sumSq = 0;
  let count = 0;

  // Simple Laplacian: for each pixel, compute 4-neighbor difference
  for (let y = 1; y < height - 1; y++) {
    for (let x = 1; x < width - 1; x++) {
      const idx = y * width + x;
      const laplacian =
        4 * data[idx] -
        data[(y - 1) * width + x] -
        data[(y + 1) * width + x] -
        data[y * width + (x - 1)] -
        data[y * width + (x + 1)];
      sum += laplacian;
      sumSq += laplacian * laplacian;
      count++;
    }
  }

  const mean = sum / count;
  const variance = sumSq / count - mean * mean;
  return variance;
}

// ── Exposure scoring ─────────────────────────────────────────────────────────

/**
 * Analyze histogram to score exposure quality.
 * Penalizes blown highlights, crushed shadows, and extreme over/underexposure.
 * Returns 0-100 score.
 */
async function measureExposure(filePath) {
  const { data } = await sharp(filePath)
    .greyscale()
    .resize(400, null, { withoutEnlargement: true })
    .raw()
    .toBuffer({ resolveWithObject: true });

  // Build histogram
  const histogram = new Array(256).fill(0);
  for (let i = 0; i < data.length; i++) {
    histogram[data[i]]++;
  }

  const totalPixels = data.length;

  // Calculate mean brightness
  let meanBrightness = 0;
  for (let i = 0; i < 256; i++) {
    meanBrightness += i * histogram[i];
  }
  meanBrightness /= totalPixels;

  // Percentage of clipped shadows (0-5) and highlights (250-255)
  let clippedShadows = 0;
  let clippedHighlights = 0;
  for (let i = 0; i <= 5; i++) clippedShadows += histogram[i];
  for (let i = 250; i <= 255; i++) clippedHighlights += histogram[i];
  clippedShadows /= totalPixels;
  clippedHighlights /= totalPixels;

  // Score: ideal mean brightness ~128, penalize clipping
  let score = 100;

  // Brightness penalty: distance from ideal midpoint
  const brightnessDelta = Math.abs(meanBrightness - 128) / 128;
  score -= brightnessDelta * 30; // Up to -30 for extreme

  // Clipping penalties
  score -= clippedShadows * 200;    // Heavy penalty for crushed blacks
  score -= clippedHighlights * 200;  // Heavy penalty for blown whites

  return Math.max(0, Math.min(100, score));
}

// ── Main culling function ────────────────────────────────────────────────────

/**
 * Cull a folder of photos.
 *
 * @param {string} inputDir - Directory containing photos
 * @param {object} options
 * @param {number} options.keepPercent - Percentage of photos to keep (default: 40)
 * @param {number} options.duplicateThreshold - dHash hamming distance threshold (default: 10)
 * @param {number} options.minSharpness - Minimum sharpness variance to keep (default: 50)
 * @returns {{ keeps: Array, rejects: Array, duplicates: Array, stats: object }}
 */
async function cullPhotos(inputDir, options = {}) {
  const {
    keepPercent = 40,
    duplicateThreshold = 10,
    minSharpness = 50,
  } = options;

  const supportedExts = new Set([
    '.jpg', '.jpeg', '.png', '.tif', '.tiff', '.webp',
    '.cr2', '.cr3', '.nef', '.arw', '.dng', '.orf', '.rw2', '.raf',
  ]);

  // List all image files
  const entries = await fs.readdir(inputDir, { withFileTypes: true });
  const imageFiles = entries
    .filter(e => e.isFile() && supportedExts.has(path.extname(e.name).toLowerCase()))
    .map(e => path.join(inputDir, e.name));

  if (imageFiles.length === 0) {
    return { keeps: [], rejects: [], duplicates: [], stats: { total: 0 } };
  }

  console.log(`[Culling] Analyzing ${imageFiles.length} photos in ${inputDir}`);

  // Phase 1: Score all images
  const scored = [];
  for (const file of imageFiles) {
    try {
      const [sharpness, exposure, hash] = await Promise.all([
        measureSharpness(file),
        measureExposure(file),
        dHash(file),
      ]);

      // Combined score: 60% sharpness (normalized), 40% exposure
      const sharpnessNorm = Math.min(sharpness / 500, 1) * 100;
      const combinedScore = sharpnessNorm * 0.6 + exposure * 0.4;

      scored.push({
        file,
        filename: path.basename(file),
        sharpness,
        sharpnessNorm,
        exposure,
        score: combinedScore,
        hash,
      });
    } catch (err) {
      console.warn(`[Culling] Skipping ${path.basename(file)}: ${err.message}`);
    }
  }

  // Phase 2: Detect duplicates (keep highest-scored in each group)
  const duplicateGroups = [];
  const isDuplicate = new Set();

  for (let i = 0; i < scored.length; i++) {
    if (isDuplicate.has(i)) continue;
    const group = [i];
    for (let j = i + 1; j < scored.length; j++) {
      if (isDuplicate.has(j)) continue;
      if (hammingDistance(scored[i].hash, scored[j].hash) <= duplicateThreshold) {
        group.push(j);
        isDuplicate.add(j);
      }
    }
    if (group.length > 1) {
      // Keep the best one in the group
      group.sort((a, b) => scored[b].score - scored[a].score);
      duplicateGroups.push({
        kept: scored[group[0]].filename,
        removed: group.slice(1).map(idx => scored[idx].filename),
      });
      // Mark all but the best as duplicate rejects
      for (let k = 1; k < group.length; k++) {
        scored[group[k]].duplicateOf = scored[group[0]].filename;
      }
    }
  }

  // Phase 3: Filter and sort
  const nonDuplicates = scored.filter(s => !s.duplicateOf);
  nonDuplicates.sort((a, b) => b.score - a.score);

  // Keep top N% of non-duplicates, plus enforce minimum sharpness
  const keepCount = Math.max(1, Math.ceil(nonDuplicates.length * (keepPercent / 100)));
  const keeps = [];
  const rejects = [];

  for (let i = 0; i < nonDuplicates.length; i++) {
    const photo = nonDuplicates[i];
    if (i < keepCount && photo.sharpness >= minSharpness) {
      keeps.push(photo);
    } else {
      rejects.push({ ...photo, reason: photo.sharpness < minSharpness ? 'too_blurry' : 'below_cutoff' });
    }
  }

  // Add duplicate rejects
  const duplicateRejects = scored
    .filter(s => s.duplicateOf)
    .map(s => ({ ...s, reason: 'duplicate' }));

  const stats = {
    total: imageFiles.length,
    analyzed: scored.length,
    kept: keeps.length,
    rejected: rejects.length,
    duplicatesFound: duplicateRejects.length,
    duplicateGroups: duplicateGroups.length,
    avgSharpness: scored.reduce((sum, s) => sum + s.sharpness, 0) / scored.length,
    avgExposure: scored.reduce((sum, s) => sum + s.exposure, 0) / scored.length,
  };

  console.log(`[Culling] Results: ${keeps.length} kept, ${rejects.length} rejected, ${duplicateRejects.length} duplicates`);

  return {
    keeps,
    rejects: [...rejects, ...duplicateRejects],
    duplicates: duplicateGroups,
    stats,
  };
}

/**
 * Move culled photos into keep/reject subfolders.
 */
async function organizeCulledPhotos(inputDir, cullResult) {
  const keepDir = path.join(inputDir, '_keeps');
  const rejectDir = path.join(inputDir, '_rejects');

  await fs.mkdir(keepDir, { recursive: true });
  await fs.mkdir(rejectDir, { recursive: true });

  // Copy keeps
  for (const photo of cullResult.keeps) {
    const dest = path.join(keepDir, photo.filename);
    await fs.copyFile(photo.file, dest);
  }

  // Copy rejects
  for (const photo of cullResult.rejects) {
    const dest = path.join(rejectDir, photo.filename);
    await fs.copyFile(photo.file, dest);
  }

  console.log(`[Culling] Organized: ${cullResult.keeps.length} → _keeps/, ${cullResult.rejects.length} → _rejects/`);

  return { keepDir, rejectDir };
}

module.exports = {
  cullPhotos,
  organizeCulledPhotos,
  measureSharpness,
  measureExposure,
  dHash,
  hammingDistance,
};
