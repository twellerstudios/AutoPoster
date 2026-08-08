/*!
 * Tweller Event Photo Engine — lightweight browser fingerprint + device UID.
 *
 * No external library, no network calls. We combine a handful of stable,
 * non-identifying signals (screen, timezone, platform, a canvas hash, etc.)
 * into one hash the server salts again before storage. Paired with a
 * localStorage UID + the httpOnly cookie the server sets, this lets us track
 * uploads / orders per device with zero sign-up. Best-effort by design: if a
 * signal is unavailable we just skip it.
 *
 * Exposes: window.tepeFingerprint() -> Promise<{ fingerprint, uid }>
 */
(function () {
    'use strict';

    var UID_KEY = 'tepe_uid';

    function getUID() {
        try {
            var v = localStorage.getItem(UID_KEY);
            if (v && /^[a-f0-9]{16,40}$/.test(v)) return v;
        } catch (e) {}
        var uid = '';
        try {
            if (window.crypto && crypto.getRandomValues) {
                var a = new Uint8Array(20);
                crypto.getRandomValues(a);
                uid = Array.prototype.map.call(a, function (b) {
                    return ('0' + b.toString(16)).slice(-2);
                }).join('');
            }
        } catch (e) {}
        if (!uid) uid = (Date.now().toString(16) + Math.random().toString(16).slice(2)).slice(0, 40);
        try { localStorage.setItem(UID_KEY, uid); } catch (e) {}
        return uid;
    }

    function canvasSignal() {
        try {
            var c = document.createElement('canvas');
            c.width = 200; c.height = 40;
            var ctx = c.getContext('2d');
            if (!ctx) return '';
            ctx.textBaseline = 'top';
            ctx.font = "14px 'Arial'";
            ctx.fillStyle = '#f60'; ctx.fillRect(0, 0, 100, 20);
            ctx.fillStyle = '#069'; ctx.fillText('Tweller ❤ T&T', 2, 2);
            ctx.fillStyle = 'rgba(102,204,0,0.7)'; ctx.fillText('Tweller ❤ T&T', 4, 4);
            return c.toDataURL();
        } catch (e) { return ''; }
    }

    // Fast, dependency-free string hash (FNV-1a → hex).
    function hashString(str) {
        var h = 0x811c9dc5;
        for (var i = 0; i < str.length; i++) {
            h ^= str.charCodeAt(i);
            h = (h + ((h << 1) + (h << 4) + (h << 7) + (h << 8) + (h << 24))) >>> 0;
        }
        // widen with a second pass so collisions are rarer
        var h2 = 0x1000193;
        for (var j = str.length - 1; j >= 0; j--) {
            h2 ^= str.charCodeAt(j);
            h2 = (h2 * 0x01000193) >>> 0;
        }
        return ('00000000' + h.toString(16)).slice(-8) + ('00000000' + h2.toString(16)).slice(-8);
    }

    function collect() {
        var n = navigator || {};
        var s = screen || {};
        var parts = [
            n.userAgent || '',
            n.language || '',
            (n.languages || []).join(','),
            n.platform || '',
            (n.hardwareConcurrency || '') + '',
            (n.deviceMemory || '') + '',
            (n.maxTouchPoints || '') + '',
            s.width + 'x' + s.height + 'x' + (s.colorDepth || ''),
            (window.devicePixelRatio || '') + '',
            (function () { try { return Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) { return new Date().getTimezoneOffset(); } })(),
            canvasSignal()
        ];
        return hashString(parts.join('|'));
    }

    // Public API. Async to leave room for future async signals; resolves fast.
    window.tepeFingerprint = function () {
        return new Promise(function (resolve) {
            var result = { fingerprint: '', uid: getUID() };
            try { result.fingerprint = collect(); } catch (e) {}
            resolve(result);
        });
    };
})();
