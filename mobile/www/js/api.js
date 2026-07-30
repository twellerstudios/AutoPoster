/**
 * Tweller Bookings WP API client — the same tweller-flow-2/v1 endpoints
 * the Lightroom plugin and admin use. Two upload paths:
 *   - culling proofs  → resized on-device, server compresses + watermarks
 *   - gallery photos  → original bytes untouched (final delivery quality)
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

    function keyParam() {
        return 'api_key=' + encodeURIComponent(cfg.apiKey);
    }

    async function getJson(url) {
        var res = await fetch(url, { headers: authHeaders() });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    async function postForm(url, params) {
        var body = Object.keys(params || {}).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }).concat([keyParam()]).join('&');
        var res = await fetch(url, {
            method: 'POST',
            headers: Object.assign({ 'Content-Type': 'application/x-www-form-urlencoded' }, authHeaders()),
            body: body
        });
        var data = await res.json().catch(function () { return {}; });
        if (!res.ok || data.ok === false) throw new Error(data.message || ('HTTP ' + res.status));
        return data;
    }

    // ── Sessions ────────────────────────────────────────────────

    /**
     * Fetch sessions. opts: { range: 'recent'|'all', search, stage }.
     * Cached to localStorage so the list survives offline (WD LAN) use.
     */
    async function fetchSessions(opts) {
        opts = opts || {};
        var qs = [keyParam()];
        qs.push('range=' + encodeURIComponent(opts.range || 'all'));
        if (opts.search) qs.push('search=' + encodeURIComponent(opts.search));
        if (opts.stage) qs.push('stage=' + encodeURIComponent(opts.stage));
        var sessions = await getJson(base() + '/automation/sessions?' + qs.join('&'));
        if (!opts.search && !opts.stage) {
            try {
                localStorage.setItem('tb_sessions_cache', JSON.stringify({ at: Date.now(), sessions: sessions }));
            } catch (e) {}
        }
        return sessions;
    }

    function cachedSessions() {
        try {
            var raw = localStorage.getItem('tb_sessions_cache');
            if (!raw) return null;
            return JSON.parse(raw);
        } catch (e) { return null; }
    }

    /** Full session detail: info, timeline, culling, gallery, receipt, links. */
    async function fetchSessionDetail(code) {
        var data = await getJson(base() + '/automation/session/' + encodeURIComponent(code) + '?' + keyParam());
        try {
            localStorage.setItem('tb_detail_' + code, JSON.stringify({ at: Date.now(), data: data }));
        } catch (e) {}
        return data;
    }

    function cachedSessionDetail(code) {
        try {
            var raw = localStorage.getItem('tb_detail_' + code);
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }

    /** Create a booking from the app's New Booking form. */
    async function createSession(fields) {
        return postForm(base() + '/automation/create-session', Object.assign({ source_mobile: '1' }, fields));
    }

    /** Delete a session and all its files. */
    async function deleteSession(code) {
        try { localStorage.removeItem('tb_detail_' + code); } catch (e) {}
        return postForm(base() + '/automation/session/' + encodeURIComponent(code) + '/delete', { confirm: 'DELETE' });
    }

    // ── Gallery management (app-side, API key) ──────────────────

    /** Gallery photos + cover for the in-app manager (not stage-gated). */
    async function fetchGallery(code) {
        return getJson(base() + '/automation/gallery/' + encodeURIComponent(code) + '?' + keyParam());
    }

    async function deleteGalleryPhotos(code, ids) {
        return postForm(base() + '/automation/gallery/' + encodeURIComponent(code) + '/photo-delete', {
            photo_ids: ids.join(',')
        });
    }

    async function setGalleryCover(code, photoId, posX, posY) {
        var params = {};
        if (photoId) params.photo_id = photoId;
        if (posX !== undefined && posX !== null) { params.pos_x = posX; params.pos_y = posY; }
        return postForm(base() + '/automation/gallery/' + encodeURIComponent(code) + '/cover', params);
    }

    // ── Print orders ────────────────────────────────────────────

    async function fetchPrintOrders(status) {
        var url = base() + '/prints/orders?' + keyParam() + (status ? '&status=' + encodeURIComponent(status) : '');
        var data = await getJson(url);
        if (!status) {
            try {
                localStorage.setItem('tb_orders_cache', JSON.stringify({ at: Date.now(), data: data }));
            } catch (e) {}
        }
        return data;
    }

    function cachedPrintOrders() {
        try {
            var raw = localStorage.getItem('tb_orders_cache');
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }

    /** Products, providers, meet-up points and the delivery fee. */
    async function fetchStudioPrintOptions() {
        return getJson(base() + '/prints/studio-options?' + keyParam());
    }

    /** Send a whole session gallery to the print lab from the phone. */
    async function sendSessionToPrintLab(fields) {
        return postForm(base() + '/prints/studio-order', fields);
    }

    async function setPrintOrderStatus(id, status, notify) {
        return postForm(base() + '/prints/orders/' + encodeURIComponent(id) + '/status', {
            status: status,
            notify: notify ? '1' : ''
        });
    }

    /** Scan a shipping-label barcode to mark an order delivered. */
    async function scanPrintDelivery(code) {
        return postForm(base() + '/prints/scan', { code: code });
    }

    // ── Print labs (providers), applications & economics ────────
    // Studio-only endpoints: they carry cost, payout and margin, so they are
    // gated server-side by rest_studio_permission and never exposed to a
    // provider login.

    /** Every provider record (incl. cost prices + stats) + the product catalog. */
    async function fetchProviders() {
        var data = await getJson(base() + '/prints/providers?' + keyParam());
        try {
            localStorage.setItem('tb_providers_cache', JSON.stringify({ at: Date.now(), data: data }));
        } catch (e) {}
        return data;
    }

    function cachedProviders() {
        try {
            var raw = localStorage.getItem('tb_providers_cache');
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }

    /**
     * Create (blank id) or update one provider. `fields.prices` is a
     * {product_id: value} map — a blank value clears that product back to
     * "not priced" rather than storing TT$0.
     */
    async function saveProvider(fields) {
        var params = {};
        Object.keys(fields || {}).forEach(function (k) {
            if (k === 'prices') return;
            params[k] = fields[k];
        });
        if (fields && fields.prices) params.prices = JSON.stringify(fields.prices);
        return postForm(base() + '/prints/providers/save', params);
    }

    async function deleteProvider(id) {
        return postForm(base() + '/prints/providers/delete', { id: id, confirm: 'DELETE' });
    }

    /**
     * Create (or link) the WordPress portal login for a provider.
     * A newly created account returns its one-time credentials in the
     * response — they are shown once and never stored.
     */
    async function createProviderAccount(id, opts) {
        opts = opts || {};
        var params = { id: id, mode: opts.mode || 'create' };
        if (opts.userId) params.user_id = opts.userId;
        if (opts.email) params.email = opts.email;
        if (opts.login) params.login = opts.login;
        return postForm(base() + '/prints/providers/account', params);
    }

    /** Print-partner applications, with per-status counts for the badge. */
    async function fetchProviderRequests(status) {
        var url = base() + '/prints/requests?' + keyParam() +
            (status ? '&status=' + encodeURIComponent(status) : '');
        var data = await getJson(url);
        if (!status) {
            try {
                localStorage.setItem('tb_requests_cache', JSON.stringify({ at: Date.now(), data: data }));
            } catch (e) {}
        }
        return data;
    }

    function cachedProviderRequests() {
        try {
            var raw = localStorage.getItem('tb_requests_cache');
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }

    /** One application, every field the lab submitted. */
    async function fetchProviderRequest(id) {
        return getJson(base() + '/prints/requests/' + encodeURIComponent(id) + '?' + keyParam());
    }

    /** mode: 'create' (new login) or 'link' (adopt the existing WP user). */
    async function approveProviderRequest(id, notes, mode) {
        return postForm(base() + '/prints/requests/' + encodeURIComponent(id) + '/approve', {
            notes: notes || '',
            mode: mode === 'link' ? 'link' : 'create'
        });
    }

    async function declineProviderRequest(id, notes) {
        return postForm(base() + '/prints/requests/' + encodeURIComponent(id) + '/decline', {
            notes: notes || ''
        });
    }

    /** Per-provider stats + the studio-wide economics rollup (month / all time). */
    async function fetchProviderSummary() {
        var data = await getJson(base() + '/prints/providers/summary?' + keyParam());
        try {
            localStorage.setItem('tb_economics_cache', JSON.stringify({ at: Date.now(), data: data }));
        } catch (e) {}
        return data;
    }

    function cachedProviderSummary() {
        try {
            var raw = localStorage.getItem('tb_economics_cache');
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }

    /** Dashboard: stage counts, upcoming shoots, needs-attention list. */
    async function fetchOverview() {
        var data = await getJson(base() + '/automation/overview?' + keyParam());
        try {
            localStorage.setItem('tb_overview_cache', JSON.stringify({ at: Date.now(), data: data }));
        } catch (e) {}
        return data;
    }

    function cachedOverview() {
        try {
            var raw = localStorage.getItem('tb_overview_cache');
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }

    /** Move a session to a specific pipeline stage. */
    async function advanceStage(code, targetStage, notes) {
        return postForm(base() + '/automation/advance', {
            session_code: code,
            target_stage: targetStage,
            notes: notes || 'From mobile app'
        });
    }

    /** Update payment status and/or notes. */
    async function updateSession(code, fields) {
        return postForm(base() + '/automation/session/' + encodeURIComponent(code) + '/update', fields);
    }

    // ── Culling (proofs for client selection) ───────────────────

    /** Existing proof filenames — used to skip re-uploads. */
    async function proofFilenames(code) {
        var data = await getJson(base() + '/culling/' + encodeURIComponent(code) + '/filenames?' + keyParam());
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
        return postForm(base() + '/culling/' + encodeURIComponent(code) + '/ready', {});
    }

    /** The client's submitted picks: [{filename, star}]. */
    async function getSelections(code) {
        return getJson(base() + '/culling/' + encodeURIComponent(code) + '/selections?' + keyParam());
    }

    // ── Gallery (final delivery — full resolution) ──────────────

    /** Existing gallery filenames — used to skip re-uploads. */
    async function galleryFilenames(code) {
        var data = await getJson(base() + '/gallery/' + encodeURIComponent(code) + '/filenames?' + keyParam());
        return data.filenames || [];
    }

    /**
     * Upload one finished photo to the delivery gallery. The file goes up
     * exactly as exported — no resizing, no recompression.
     */
    async function uploadGalleryPhoto(code, blob, filename) {
        var fd = new FormData();
        fd.append('photo', blob, filename);
        fd.append('session_code', code);
        fd.append('api_key', cfg.apiKey);

        var res = await fetch(base() + '/photo-upload', {
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

    // ── Image prep ──────────────────────────────────────────────

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
        fetchSessionDetail: fetchSessionDetail,
        cachedSessionDetail: cachedSessionDetail,
        createSession: createSession,
        deleteSession: deleteSession,
        fetchGallery: fetchGallery,
        deleteGalleryPhotos: deleteGalleryPhotos,
        setGalleryCover: setGalleryCover,
        fetchPrintOrders: fetchPrintOrders,
        cachedPrintOrders: cachedPrintOrders,
        fetchStudioPrintOptions: fetchStudioPrintOptions,
        sendSessionToPrintLab: sendSessionToPrintLab,
        setPrintOrderStatus: setPrintOrderStatus,
        scanPrintDelivery: scanPrintDelivery,
        fetchProviders: fetchProviders,
        cachedProviders: cachedProviders,
        saveProvider: saveProvider,
        deleteProvider: deleteProvider,
        createProviderAccount: createProviderAccount,
        fetchProviderRequests: fetchProviderRequests,
        cachedProviderRequests: cachedProviderRequests,
        fetchProviderRequest: fetchProviderRequest,
        approveProviderRequest: approveProviderRequest,
        declineProviderRequest: declineProviderRequest,
        fetchProviderSummary: fetchProviderSummary,
        cachedProviderSummary: cachedProviderSummary,
        fetchOverview: fetchOverview,
        cachedOverview: cachedOverview,
        advanceStage: advanceStage,
        updateSession: updateSession,
        proofFilenames: proofFilenames,
        uploadProof: uploadProof,
        markCullingReady: markCullingReady,
        getSelections: getSelections,
        galleryFilenames: galleryFilenames,
        uploadGalleryPhoto: uploadGalleryPhoto,
        resizeForProof: resizeForProof
    };
})();
