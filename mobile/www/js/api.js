/**
 * Tweller Bookings WP API client — the same tweller-flow-2/v1 endpoints
 * the Lightroom plugin uses. The server compresses + watermarks culling
 * proofs, so this client just ships reasonably-sized JPEGs.
 */
var TwellerApi = (function () {
    'use strict';

    var cfg = { siteUrl: '', apiKey: '' };

    function configure(siteUrl, apiKey) {
        cfg.siteUrl = (siteUrl || '').replace(/\/+$/, '');
        cfg.apiKey = apiKey || '';
    }

    function base() {
        return cfg.siteUrl + '/wp-json/tweller-flow-2/v1';
    }

    function authHeaders() {
        return cfg.apiKey ? { 'Authorization': 'Bearer ' + cfg.apiKey } : {};
    }

    async function getJson(url) {
        var res = await fetch(url, { headers: authHeaders() });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    /** Latest sessions (newest first) — cached for offline use on the WD LAN. */
    async function fetchSessions() {
        var url = base() + '/automation/sessions?range=recent&api_key=' + encodeURIComponent(cfg.apiKey);
        var sessions = await getJson(url);
        try {
            localStorage.setItem('tb_sessions_cache', JSON.stringify({ at: Date.now(), sessions: sessions }));
        } catch (e) {}
        return sessions;
    }

    function cachedSessions() {
        try {
            var raw = localStorage.getItem('tb_sessions_cache');
            if (!raw) return null;
            return JSON.parse(raw);
        } catch (e) { return null; }
    }

    /** Existing proof filenames — used to skip re-uploads. */
    async function proofFilenames(code) {
        var url = base() + '/culling/' + encodeURIComponent(code) + '/filenames?api_key=' + encodeURIComponent(cfg.apiKey);
        var data = await getJson(url);
        return data.filenames || [];
    }

    /** Upload one culling proof (server watermarks + compresses further). */
    async function uploadProof(code, blob, filename, originalFilename) {
        var fd = new FormData();
        fd.append('photo', blob, filename);
        fd.append('original_filename', originalFilename || filename);
        fd.append('api_key', cfg.apiKey);

        var res = await fetch(base() + '/culling/' + encodeURIComponent(code) + '/upload', {
            method: 'POST',
            body: fd,
            headers: authHeaders()
        });
        var data = await res.json().catch(function () { return {}; });
        if (!res.ok || !data.ok) {
            throw new Error(data.message || ('HTTP ' + res.status));
        }
        return data;
    }

    /** Mark the selection gallery ready (sends the client email). */
    async function markCullingReady(code) {
        var res = await fetch(base() + '/culling/' + encodeURIComponent(code) + '/ready', {
            method: 'POST',
            headers: Object.assign({ 'Content-Type': 'application/x-www-form-urlencoded' }, authHeaders()),
            body: 'api_key=' + encodeURIComponent(cfg.apiKey)
        });
        var data = await res.json().catch(function () { return {}; });
        if (!res.ok || !data.ok) throw new Error(data.message || ('HTTP ' + res.status));
        return data;
    }

    /**
     * Resize a photo to proof size on-device (2048px long edge, JPEG q0.6 —
     * the same numbers the Lightroom culling export uses) so uploads are
     * small and the data estimate is exact.
     */
    async function resizeForProof(fileOrBlob) {
        var bmp;
        try {
            bmp = await createImageBitmap(fileOrBlob);
        } catch (e) {
            return fileOrBlob; // not decodable — upload as-is
        }

        var MAX = 2048;
        var scale = Math.min(1, MAX / Math.max(bmp.width, bmp.height));
        var w = Math.round(bmp.width * scale);
        var h = Math.round(bmp.height * scale);

        var canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        canvas.getContext('2d').drawImage(bmp, 0, 0, w, h);
        bmp.close && bmp.close();

        return new Promise(function (resolve) {
            canvas.toBlob(function (blob) {
                resolve(blob || fileOrBlob);
            }, 'image/jpeg', 0.6);
        });
    }

    return {
        configure: configure,
        fetchSessions: fetchSessions,
        cachedSessions: cachedSessions,
        proofFilenames: proofFilenames,
        uploadProof: uploadProof,
        markCullingReady: markCullingReady,
        resizeForProof: resizeForProof
    };
})();
