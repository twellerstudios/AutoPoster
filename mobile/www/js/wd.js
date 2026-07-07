/**
 * WD My Passport Wireless Pro adapter.
 *
 * When the phone joins the WD's Wi-Fi, the drive serves its filesystem
 * over HTTP at 192.168.60.1. This adapter browses folders and streams
 * files. In the installed Android app, requests go through Capacitor's
 * native HTTP (no CORS); in a plain browser the WD blocks cross-origin
 * reads, so the device photo picker is the fallback there.
 */
var TwellerWD = (function () {
    'use strict';

    var HOST = 'http://192.168.60.1';

    function isNative() {
        return !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform());
    }

    async function nativeGet(url, responseType) {
        var http = window.Capacitor.Plugins.CapacitorHttp;
        var res = await http.get({ url: url, responseType: responseType || 'text', readTimeout: 20000, connectTimeout: 8000 });
        if (res.status < 200 || res.status >= 300) throw new Error('HTTP ' + res.status);
        return res.data;
    }

    /** Is the WD box reachable right now? */
    async function detect() {
        try {
            if (isNative()) {
                await nativeGet(HOST + '/', 'text');
            } else {
                // no-cors probe: resolves if the host answered at all
                await fetch(HOST + '/', { mode: 'no-cors', signal: AbortSignal.timeout(5000) });
            }
            return true;
        } catch (e) {
            return false;
        }
    }

    /**
     * List a directory. Tries the WD REST API first (the same one the
     * drive's own web UI calls), then falls back to parsing an HTML index.
     * Returns { dirs: [names], files: [{name, path, size}] }.
     */
    async function list(path) {
        path = path || '/';

        // WD REST API
        try {
            var api = HOST + '/api/2.1/rest/dir_contents?path=' + encodeURIComponent(path) + '&format=json';
            var data = isNative() ? await nativeGet(api) : await (await fetch(api)).json();
            if (typeof data === 'string') data = JSON.parse(data);
            var items = (data && (data.items || (data.dir_contents && data.dir_contents.item))) || [];
            if (!Array.isArray(items)) items = [items];

            var out = { dirs: [], files: [] };
            items.forEach(function (it) {
                var name = it.name || it.title || '';
                if (!name) return;
                var isDir = (it.is_dir === true) || it.mime_type === 'application/x-folder' || it.type === 'dir';
                if (isDir) out.dirs.push(name);
                else out.files.push({ name: name, path: joinPath(path, name), size: parseInt(it.size || 0, 10) });
            });
            return out;
        } catch (e) { /* fall through */ }

        // Plain HTML directory index fallback
        var html = isNative() ? await nativeGet(HOST + path) : await (await fetch(HOST + path)).text();
        var out2 = { dirs: [], files: [] };
        var re = /href="([^"?#]+)"/gi, m;
        while ((m = re.exec(html))) {
            var href = decodeURIComponent(m[1]);
            if (href === '../' || href.charAt(0) === '/') continue;
            if (href.slice(-1) === '/') out2.dirs.push(href.slice(0, -1));
            else out2.files.push({ name: href, path: joinPath(path, href), size: 0 });
        }
        return out2;
    }

    function joinPath(dir, name) {
        return (dir.replace(/\/+$/, '') || '') + '/' + name;
    }

    /** Fetch the first `bytes` of a file (enough for EXIF). */
    async function fetchHead(path, bytes) {
        var url = HOST + encodeURI(path);
        if (isNative()) {
            var b64 = await nativeGet(url, 'blob'); // Capacitor returns base64
            var raw = atob(String(b64).slice(0, Math.ceil((bytes || 131072) * 4 / 3)));
            var arr = new Uint8Array(raw.length);
            for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
            return arr.buffer;
        }
        var res = await fetch(url, { headers: { 'Range': 'bytes=0-' + ((bytes || 131072) - 1) } });
        return res.arrayBuffer();
    }

    /** Fetch a whole file as a Blob. */
    async function fetchFile(path) {
        var url = HOST + encodeURI(path);
        if (isNative()) {
            var b64 = await nativeGet(url, 'blob');
            var raw = atob(b64);
            var arr = new Uint8Array(raw.length);
            for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
            return new Blob([arr], { type: 'image/jpeg' });
        }
        var res = await fetch(url);
        return res.blob();
    }

    function isJpeg(name) {
        return /\.(jpe?g)$/i.test(name);
    }

    return { detect: detect, list: list, fetchHead: fetchHead, fetchFile: fetchFile, isJpeg: isJpeg, HOST: HOST };
})();
