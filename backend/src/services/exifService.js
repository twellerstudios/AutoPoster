/**
 * EXIF Service
 *
 * Reads EXIF metadata from photos to extract capture timestamps.
 * Uses sharp for metadata access and exif-reader for parsing.
 *
 * Key capability: Groups photos by capture time window so we can
 * match batches of photos to specific session bookings.
 */
const sharp = require('sharp');
const exifReader = require('exif-reader');
const path = require('path');
const fs = require('fs').promises;

// Image extensions we can read EXIF from
const SUPPORTED_EXTS = new Set([
  '.jpg', '.jpeg', '.png', '.tif', '.tiff', '.webp',
  '.cr2', '.cr3', '.nef', '.arw', '.dng', '.orf', '.rw2', '.raf',
]);

/**
 * Read the capture timestamp from a photo's EXIF data.
 *
 * @param {string} filePath - Path to the image file
 * @returns {Date|null} - Capture timestamp, or null if unavailable
 */
async function readCaptureTime(filePath) {
  try {
    const metadata = await sharp(filePath).metadata();

    if (!metadata.exif) return null;

    const exif = exifReader(metadata.exif);

    // Try multiple EXIF date fields in priority order
    const dateOriginal = exif?.exif?.DateTimeOriginal;
    const dateDigitized = exif?.exif?.DateTimeDigitized;
    const dateModified = exif?.image?.ModifyDate;

    const timestamp = dateOriginal || dateDigitized || dateModified;

    if (!timestamp) return null;

    // exif-reader returns Date objects
    if (timestamp instanceof Date && !isNaN(timestamp.getTime())) {
      return timestamp;
    }

    return null;
  } catch (err) {
    // Some RAW formats may not parse cleanly — fall back to file mtime
    try {
      const stat = await fs.stat(filePath);
      return stat.mtime;
    } catch {
      return null;
    }
  }
}

/**
 * Read capture timestamps for all photos in a directory.
 *
 * @param {string} dir - Directory containing photos
 * @returns {Array<{file: string, filename: string, captureTime: Date|null}>}
 */
async function readAllTimestamps(dir) {
  const entries = await fs.readdir(dir, { withFileTypes: true });
  const photos = entries.filter(
    (e) => e.isFile() && SUPPORTED_EXTS.has(path.extname(e.name).toLowerCase())
  );

  const results = [];

  for (const photo of photos) {
    const filePath = path.join(dir, photo.name);
    const captureTime = await readCaptureTime(filePath);

    results.push({
      file: filePath,
      filename: photo.name,
      captureTime,
    });
  }

  return results;
}

/**
 * Group photos by capture session based on time gaps.
 *
 * Photos taken within `gapMinutes` of each other are grouped together.
 * A gap larger than the threshold starts a new group.
 *
 * @param {Array} photos - Output from readAllTimestamps()
 * @param {number} gapMinutes - Max gap between consecutive photos (default: 120 = 2 hours)
 * @returns {Array<{startTime: Date, endTime: Date, date: string, photos: Array}>}
 */
function groupBySession(photos, gapMinutes = 120) {
  // Filter to photos with valid timestamps and sort chronologically
  const withTime = photos
    .filter((p) => p.captureTime)
    .sort((a, b) => a.captureTime.getTime() - b.captureTime.getTime());

  if (withTime.length === 0) {
    // No EXIF timestamps — treat all as one group
    const noTimePhotos = photos.filter((p) => !p.captureTime);
    if (noTimePhotos.length === 0) return [];

    return [
      {
        startTime: null,
        endTime: null,
        date: null,
        photos: noTimePhotos,
      },
    ];
  }

  const gapMs = gapMinutes * 60 * 1000;
  const groups = [];
  let currentGroup = {
    startTime: withTime[0].captureTime,
    endTime: withTime[0].captureTime,
    photos: [withTime[0]],
  };

  for (let i = 1; i < withTime.length; i++) {
    const timeDiff =
      withTime[i].captureTime.getTime() - currentGroup.endTime.getTime();

    if (timeDiff > gapMs) {
      // Gap exceeded — finalize current group, start new one
      currentGroup.date = formatDate(currentGroup.startTime);
      groups.push(currentGroup);

      currentGroup = {
        startTime: withTime[i].captureTime,
        endTime: withTime[i].captureTime,
        photos: [withTime[i]],
      };
    } else {
      // Same session — extend group
      currentGroup.endTime = withTime[i].captureTime;
      currentGroup.photos.push(withTime[i]);
    }
  }

  // Finalize last group
  currentGroup.date = formatDate(currentGroup.startTime);
  groups.push(currentGroup);

  // Add any photos without timestamps to the closest group by file proximity
  const noTimePhotos = photos.filter((p) => !p.captureTime);
  if (noTimePhotos.length > 0 && groups.length > 0) {
    // Assign to the largest group (most likely the main session)
    const largest = groups.reduce((a, b) =>
      b.photos.length > a.photos.length ? b : a
    );
    largest.photos.push(...noTimePhotos);
  }

  return groups;
}

/**
 * Format a Date as YYYY-MM-DD for matching against session_date.
 */
function formatDate(date) {
  if (!date) return null;
  return date.toISOString().split('T')[0];
}

/**
 * Format a Date as HH:MM for matching against session_time.
 */
function formatTime(date) {
  if (!date) return null;
  return date.toISOString().split('T')[1].substring(0, 5);
}

module.exports = {
  readCaptureTime,
  readAllTimestamps,
  groupBySession,
  formatDate,
  formatTime,
  SUPPORTED_EXTS,
};
