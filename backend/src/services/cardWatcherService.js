/**
 * SD Card Watcher Service — Detects when an SD card or USB drive is inserted (Windows).
 *
 * Polls for new drive letters and triggers the import pipeline when a card
 * with photos is detected.
 */
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const EventEmitter = require('events');

// Common raw + jpeg image extensions
const IMAGE_EXTENSIONS = new Set([
  '.jpg', '.jpeg', '.cr2', '.cr3', '.nef', '.arw', '.raf',
  '.dng', '.rw2', '.orf', '.srw', '.pef', '.tif', '.tiff',
  '.heic', '.heif', '.png',
]);

class CardWatcherService extends EventEmitter {
  constructor() {
    super();
    this.knownDrives = new Set();
    this.pollInterval = null;
    this.pollMs = 3000; // Check every 3 seconds
    this.watching = false;
    this.ignoredDrives = new Set(['C:\\']); // Don't import from system drive
  }

  /**
   * Get currently mounted drive letters (Windows).
   * Falls back to scanning /media or /mnt on Linux.
   */
  getActiveDrives() {
    try {
      if (process.platform === 'win32') {
        // Use wmic to list logical drives
        const output = execSync(
          'wmic logicaldisk get DeviceID,DriveType /format:csv',
          { encoding: 'utf8', timeout: 5000 }
        );
        const drives = [];
        for (const line of output.split('\n')) {
          const parts = line.trim().split(',');
          // DriveType 2 = Removable, 3 = Local, 4 = Network
          if (parts.length >= 3 && parts[1]) {
            const driveLetter = parts[1].trim();
            const driveType = parseInt(parts[2], 10);
            // Only watch removable drives (type 2) by default
            if (driveType === 2 && driveLetter) {
              drives.push(driveLetter + '\\');
            }
          }
        }
        return drives;
      } else {
        // Linux/macOS: check /media and /mnt for mounted volumes
        const mountPoints = [];
        for (const base of ['/media', '/mnt']) {
          if (fs.existsSync(base)) {
            const entries = fs.readdirSync(base, { withFileTypes: true });
            for (const entry of entries) {
              if (entry.isDirectory()) {
                const fullPath = path.join(base, entry.name);
                // Check subdirectories too (e.g., /media/username/SDCARD)
                try {
                  const subEntries = fs.readdirSync(fullPath, { withFileTypes: true });
                  for (const sub of subEntries) {
                    if (sub.isDirectory()) {
                      mountPoints.push(path.join(fullPath, sub.name));
                    }
                  }
                } catch {
                  mountPoints.push(fullPath);
                }
              }
            }
          }
        }
        return mountPoints;
      }
    } catch (err) {
      console.error('[CardWatcher] Error detecting drives:', err.message);
      return [];
    }
  }

  /**
   * Scan a drive/path for image files.
   * @param {string} drivePath - Root path to scan
   * @param {number} maxDepth - Max directory depth to scan
   * @returns {Array<{ filePath: string, size: number, modified: Date }>}
   */
  scanForImages(drivePath, maxDepth = 3) {
    const images = [];

    function scan(dir, depth) {
      if (depth > maxDepth) return;
      try {
        const entries = fs.readdirSync(dir, { withFileTypes: true });
        for (const entry of entries) {
          const fullPath = path.join(dir, entry.name);
          if (entry.isDirectory() && !entry.name.startsWith('.')) {
            scan(fullPath, depth + 1);
          } else if (entry.isFile()) {
            const ext = path.extname(entry.name).toLowerCase();
            if (IMAGE_EXTENSIONS.has(ext)) {
              try {
                const stat = fs.statSync(fullPath);
                images.push({
                  filePath: fullPath,
                  fileName: entry.name,
                  size: stat.size,
                  modified: stat.mtime,
                });
              } catch { /* skip unreadable files */ }
            }
          }
        }
      } catch { /* skip unreadable directories */ }
    }

    scan(drivePath, 0);
    return images;
  }

  /**
   * Start watching for new drives.
   */
  start() {
    if (this.watching) return;
    this.watching = true;

    // Initialize with currently known drives
    this.knownDrives = new Set(this.getActiveDrives());
    console.log(`[CardWatcher] Watching for new drives. Currently known: ${[...this.knownDrives].join(', ') || 'none'}`);

    this.pollInterval = setInterval(() => {
      const currentDrives = this.getActiveDrives();

      for (const drive of currentDrives) {
        if (!this.knownDrives.has(drive) && !this.ignoredDrives.has(drive)) {
          console.log(`[CardWatcher] New drive detected: ${drive}`);
          this.knownDrives.add(drive);

          // Scan for images
          const images = this.scanForImages(drive);
          if (images.length > 0) {
            console.log(`[CardWatcher] Found ${images.length} images on ${drive}`);
            this.emit('card-inserted', { drive, images });
          } else {
            console.log(`[CardWatcher] No images found on ${drive}`);
          }
        }
      }

      // Detect removed drives
      for (const known of this.knownDrives) {
        if (!currentDrives.includes(known)) {
          console.log(`[CardWatcher] Drive removed: ${known}`);
          this.knownDrives.delete(known);
          this.emit('card-removed', { drive: known });
        }
      }
    }, this.pollMs);
  }

  /**
   * Stop watching.
   */
  stop() {
    if (this.pollInterval) {
      clearInterval(this.pollInterval);
      this.pollInterval = null;
    }
    this.watching = false;
    console.log('[CardWatcher] Stopped watching for drives');
  }

  /**
   * Get current status.
   */
  getStatus() {
    return {
      watching: this.watching,
      knownDrives: [...this.knownDrives],
      ignoredDrives: [...this.ignoredDrives],
    };
  }
}

module.exports = new CardWatcherService();
