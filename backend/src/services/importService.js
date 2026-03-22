/**
 * Import Service — Sorts and copies photos into organized folders based on booking info.
 *
 * Folder format: dd/mm/yyyy-CLIENTS-NAME
 * Flow: SD Card → importService → sorted folders → Imagen AI → Lightroom → AutoPoster
 */
const fs = require('fs');
const path = require('path');
const calendarService = require('./googleCalendarService');

class ImportService {
  constructor() {
    // Configurable via env vars
    this.basePath = process.env.IMPORT_DESTINATION || 'D:\\Photography\\Sessions';
    this.importHistory = []; // In-memory history (most recent first)
    this.maxHistory = 100;
    this.activeImport = null;
  }

  /**
   * Format a date as dd-mm-yyyy (using dashes for folder-safe names).
   */
  formatDate(date) {
    const dd = String(date.getDate()).padStart(2, '0');
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const yyyy = date.getFullYear();
    return `${dd}-${mm}-${yyyy}`;
  }

  /**
   * Format client name for folder: uppercase, spaces to dashes.
   * "John Smith" → "JOHN-SMITH"
   */
  formatClientName(name) {
    return name
      .toUpperCase()
      .replace(/[^A-Z0-9\s-]/g, '')
      .replace(/\s+/g, '-')
      .trim();
  }

  /**
   * Build the destination folder name.
   * Format: dd-mm-yyyy-CLIENTS-NAME
   * @param {Date} sessionDate
   * @param {string} clientName
   * @returns {string} Full folder path
   */
  buildFolderPath(sessionDate, clientName) {
    const dateStr = this.formatDate(sessionDate);
    const nameStr = this.formatClientName(clientName);
    const folderName = `${dateStr}-${nameStr}`;
    return path.join(this.basePath, folderName);
  }

  /**
   * Get the earliest photo date from a set of images (using file modified time).
   * In production, EXIF data would be more accurate.
   */
  getPhotoDate(images) {
    if (images.length === 0) return new Date();
    // Use the earliest modified date
    const sorted = [...images].sort((a, b) => a.modified - b.modified);
    return sorted[0].modified;
  }

  /**
   * Import photos from a source (SD card) into organized folders.
   * 1. Determine photo date from file metadata
   * 2. Look up matching booking in Google Calendar
   * 3. Create destination folder: dd-mm-yyyy-CLIENTS-NAME
   * 4. Copy all images into the folder
   *
   * @param {Array} images - Array of { filePath, fileName, size, modified }
   * @param {string} sourceDrive - Source drive/path
   * @param {Object} options - Override options
   * @param {string} options.clientName - Manual client name override
   * @param {string} options.sessionDate - Manual date override (ISO string)
   * @returns {Object} Import result
   */
  async importPhotos(images, sourceDrive, options = {}) {
    const importId = Date.now().toString(36);
    const startTime = Date.now();

    this.activeImport = {
      id: importId,
      status: 'in_progress',
      sourceDrive,
      totalFiles: images.length,
      copiedFiles: 0,
      startTime,
    };

    try {
      // 1. Determine session date
      let sessionDate;
      if (options.sessionDate) {
        sessionDate = new Date(options.sessionDate);
      } else {
        sessionDate = this.getPhotoDate(images);
      }

      // 2. Find matching booking
      let clientName = options.clientName || null;
      let booking = null;

      if (!clientName) {
        booking = await calendarService.findBookingForDate(sessionDate);
        if (booking) {
          clientName = booking.clientName;
          console.log(`[Import] Matched to booking: "${booking.eventTitle}" → ${clientName}`);
        } else {
          // Fallback: use date-based name
          clientName = 'Unmatched Session';
          console.log('[Import] No matching booking found, using fallback name');
        }
      }

      // 3. Create destination folder
      const destFolder = this.buildFolderPath(sessionDate, clientName);
      fs.mkdirSync(destFolder, { recursive: true });
      console.log(`[Import] Destination: ${destFolder}`);

      // 4. Copy files
      const results = [];
      let copied = 0;
      let skipped = 0;
      let errors = 0;

      for (const image of images) {
        const destPath = path.join(destFolder, image.fileName);

        try {
          // Skip if file already exists and same size
          if (fs.existsSync(destPath)) {
            const existingStat = fs.statSync(destPath);
            if (existingStat.size === image.size) {
              skipped++;
              results.push({ file: image.fileName, status: 'skipped', reason: 'already exists' });
              continue;
            }
          }

          // Copy file
          fs.copyFileSync(image.filePath, destPath);

          // Verify copy
          const copiedStat = fs.statSync(destPath);
          if (copiedStat.size !== image.size) {
            throw new Error(`Size mismatch: source ${image.size} vs copy ${copiedStat.size}`);
          }

          copied++;
          results.push({ file: image.fileName, status: 'copied' });
        } catch (err) {
          errors++;
          results.push({ file: image.fileName, status: 'error', error: err.message });
          console.error(`[Import] Error copying ${image.fileName}:`, err.message);
        }

        this.activeImport.copiedFiles = copied + skipped;
      }

      const duration = Date.now() - startTime;

      const importResult = {
        id: importId,
        status: errors === 0 ? 'completed' : 'completed_with_errors',
        sourceDrive,
        destination: destFolder,
        clientName,
        sessionDate: sessionDate.toISOString(),
        booking: booking ? {
          eventTitle: booking.eventTitle,
          eventId: booking.eventId,
        } : null,
        stats: {
          total: images.length,
          copied,
          skipped,
          errors,
          durationMs: duration,
        },
        files: results,
        timestamp: new Date().toISOString(),
      };

      // Add to history
      this.importHistory.unshift(importResult);
      if (this.importHistory.length > this.maxHistory) {
        this.importHistory.pop();
      }

      this.activeImport = null;
      console.log(`[Import] Complete: ${copied} copied, ${skipped} skipped, ${errors} errors (${duration}ms)`);
      return importResult;

    } catch (err) {
      this.activeImport = null;
      const errorResult = {
        id: importId,
        status: 'failed',
        error: err.message,
        sourceDrive,
        timestamp: new Date().toISOString(),
      };
      this.importHistory.unshift(errorResult);
      throw err;
    }
  }

  /**
   * Get import status and history.
   */
  getStatus() {
    return {
      basePath: this.basePath,
      activeImport: this.activeImport,
      recentImports: this.importHistory.slice(0, 20),
      googleCalendarConfigured: !!process.env.GOOGLE_CALENDAR_API_KEY,
    };
  }

  /**
   * Get a specific import by ID.
   */
  getImport(id) {
    return this.importHistory.find(i => i.id === id) || null;
  }
}

module.exports = new ImportService();
