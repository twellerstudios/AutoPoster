/**
 * Minimal EXIF reader — extracts DateTimeOriginal from a JPEG.
 * Only the first ~128KB of a file is needed (EXIF lives in the APP1
 * segment at the very front), which keeps WD Wi-Fi reads cheap.
 */
var TwellerExif = (function () {
    'use strict';

    /** Parse "YYYY:MM:DD HH:MM:SS" into a Date (local time). */
    function parseExifDate(str) {
        var m = /^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/.exec(str || '');
        if (!m) return null;
        return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]);
    }

    /**
     * Extract DateTimeOriginal from a JPEG ArrayBuffer.
     * Returns a Date or null.
     */
    function dateTimeOriginal(buffer) {
        var view = new DataView(buffer);
        if (view.byteLength < 12 || view.getUint16(0) !== 0xFFD8) return null; // not a JPEG

        // Walk JPEG segments looking for APP1/Exif
        var offset = 2;
        while (offset + 4 < view.byteLength) {
            if (view.getUint8(offset) !== 0xFF) break;
            var marker = view.getUint8(offset + 1);
            var size = view.getUint16(offset + 2);

            if (marker === 0xE1) { // APP1
                var start = offset + 4;
                // "Exif\0\0"
                if (view.getUint32(start) === 0x45786966 && view.getUint16(start + 4) === 0) {
                    return readTiff(view, start + 6);
                }
            }
            if (marker === 0xDA) break; // start of scan — no EXIF past here
            offset += 2 + size;
        }
        return null;
    }

    function readTiff(view, tiffStart) {
        var little;
        var byteOrder = view.getUint16(tiffStart);
        if (byteOrder === 0x4949) little = true;
        else if (byteOrder === 0x4D4D) little = false;
        else return null;

        function u16(o) { return view.getUint16(o, little); }
        function u32(o) { return view.getUint32(o, little); }

        if (u16(tiffStart + 2) !== 0x002A) return null;
        var ifd0 = tiffStart + u32(tiffStart + 4);
        if (ifd0 + 2 > view.byteLength) return null;

        // Find the Exif sub-IFD pointer (tag 0x8769) in IFD0
        var exifIfdOffset = null;
        var count = u16(ifd0);
        for (var i = 0; i < count; i++) {
            var entry = ifd0 + 2 + i * 12;
            if (entry + 12 > view.byteLength) return null;
            if (u16(entry) === 0x8769) {
                exifIfdOffset = tiffStart + u32(entry + 8);
                break;
            }
        }
        if (!exifIfdOffset || exifIfdOffset + 2 > view.byteLength) return null;

        // Find DateTimeOriginal (0x9003) in the Exif IFD
        var n = u16(exifIfdOffset);
        for (var j = 0; j < n; j++) {
            var e = exifIfdOffset + 2 + j * 12;
            if (e + 12 > view.byteLength) return null;
            if (u16(e) === 0x9003) {
                var len = u32(e + 4);
                var strOff = len > 4 ? tiffStart + u32(e + 8) : e + 8;
                if (strOff + len > view.byteLength) return null;
                var chars = '';
                for (var k = 0; k < Math.min(len, 25); k++) {
                    var c = view.getUint8(strOff + k);
                    if (c === 0) break;
                    chars += String.fromCharCode(c);
                }
                return parseExifDate(chars);
            }
        }
        return null;
    }

    /** Read DateTimeOriginal from a File/Blob (first 128KB only). */
    async function fromFile(file) {
        var head = await file.slice(0, 131072).arrayBuffer();
        var dt = dateTimeOriginal(head);
        // Fall back to file modified time when EXIF is missing (PNG etc.)
        return dt || (file.lastModified ? new Date(file.lastModified) : null);
    }

    /**
     * Group photos into shoots by capture-time gaps (same logic as the
     * desktop exifService: a gap larger than gapMinutes starts a new group).
     * items: [{ name, date, ... }] — returns array of groups sorted by time.
     */
    function groupByGap(items, gapMinutes) {
        var gapMs = (gapMinutes || 120) * 60 * 1000;
        var sorted = items.slice().sort(function (a, b) {
            return (a.date ? a.date.getTime() : 0) - (b.date ? b.date.getTime() : 0);
        });

        var groups = [];
        var current = null;
        var lastTime = null;

        sorted.forEach(function (item) {
            var t = item.date ? item.date.getTime() : null;
            if (!current || (t !== null && lastTime !== null && t - lastTime > gapMs)) {
                current = { items: [], start: item.date, end: item.date };
                groups.push(current);
            }
            current.items.push(item);
            if (item.date) {
                if (!current.start || item.date < current.start) current.start = item.date;
                if (!current.end || item.date > current.end) current.end = item.date;
                lastTime = t;
            }
        });

        return groups;
    }

    return { fromFile: fromFile, groupByGap: groupByGap, dateTimeOriginal: dateTimeOriginal };
})();
