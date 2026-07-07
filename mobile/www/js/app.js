/**
 * Tweller Bookings Mobile — app shell.
 * Tabs: Sessions (pick the shoot) → Import (phone or WD) → Queue (upload
 * with data controls) → Settings (site + API key, same as the LR plugin).
 */
(function () {
    'use strict';

    var view = document.getElementById('view');
    var state = {
        settings: {
            siteUrl: localStorage.getItem('tb_site') || 'https://www.twellerstudios.com',
            apiKey: localStorage.getItem('tb_key') || '',
            wifiOnly: localStorage.getItem('tb_wifi_only') !== '0'
        },
        sessions: [],
        selectedSession: JSON.parse(localStorage.getItem('tb_selected_session') || 'null'),
        groups: [],           // import groups awaiting queueing
        uploading: false
    };

    TwellerApi.configure(state.settings.siteUrl, state.settings.apiKey);

    // ── Tiny IndexedDB queue (proof blobs survive app restarts) ──
    var DB;
    function db() {
        if (DB) return Promise.resolve(DB);
        return new Promise(function (resolve, reject) {
            var req = indexedDB.open('tweller-queue', 1);
            req.onupgradeneeded = function () {
                req.result.createObjectStore('queue', { keyPath: 'id', autoIncrement: true });
            };
            req.onsuccess = function () { DB = req.result; resolve(DB); };
            req.onerror = function () { reject(req.error); };
        });
    }
    function qAll() {
        return db().then(function (d) {
            return new Promise(function (resolve) {
                var out = [];
                d.transaction('queue').objectStore('queue').openCursor().onsuccess = function (e) {
                    var c = e.target.result;
                    if (c) { out.push(c.value); c.continue(); } else resolve(out);
                };
            });
        });
    }
    function qPut(item) {
        return db().then(function (d) {
            return new Promise(function (resolve) {
                var tx = d.transaction('queue', 'readwrite');
                tx.objectStore('queue').put(item).onsuccess = function (e) { resolve(e.target.result); };
            });
        });
    }
    function qDelete(id) {
        return db().then(function (d) {
            d.transaction('queue', 'readwrite').objectStore('queue').delete(id);
        });
    }

    // ── Helpers ──
    function el(html) {
        var t = document.createElement('template');
        t.innerHTML = html.trim();
        return t.content.firstChild;
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function toast(msg, ms) {
        var t = document.getElementById('toast');
        t.textContent = msg;
        t.style.display = 'block';
        clearTimeout(t._h);
        t._h = setTimeout(function () { t.style.display = 'none'; }, ms || 3200);
    }
    function fmtMB(bytes) {
        return (bytes / 1048576).toFixed(1) + ' MB';
    }
    function fmtTime(d) {
        return d ? d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '?';
    }
    async function connectionType() {
        try {
            if (window.Capacitor && window.Capacitor.Plugins.Network) {
                var s = await window.Capacitor.Plugins.Network.getStatus();
                return s.connectionType; // 'wifi' | 'cellular' | 'none' | 'unknown'
            }
        } catch (e) {}
        if (navigator.connection && navigator.connection.type) return navigator.connection.type;
        return navigator.onLine ? 'unknown' : 'none';
    }

    async function refreshBadge() {
        var items = await qAll();
        var pending = items.filter(function (i) { return i.status !== 'done'; }).length;
        var badge = document.getElementById('queue-badge');
        badge.style.display = pending ? '' : 'none';
        badge.textContent = pending;
    }

    // ── Tab routing ──
    var tabs = { sessions: renderSessions, import: renderImport, queue: renderQueue, settings: renderSettings };
    document.querySelectorAll('.tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.tab').forEach(function (b) { b.classList.remove('tab--active'); });
            btn.classList.add('tab--active');
            tabs[btn.getAttribute('data-tab')]();
        });
    });

    // ── Sessions ──
    async function renderSessions() {
        view.innerHTML = '<div class="card"><h2>Pick the shoot</h2><div class="spin"></div></div>';

        var sessions = [];
        try {
            sessions = await TwellerApi.fetchSessions();
        } catch (e) {
            var cache = TwellerApi.cachedSessions();
            if (cache) {
                sessions = cache.sessions;
                toast('Offline — showing sessions cached ' + new Date(cache.at).toLocaleString());
            }
        }
        state.sessions = sessions || [];

        var card = el('<div class="card"><h2>Pick the shoot</h2></div>');
        if (!state.sessions.length) {
            card.appendChild(el('<p class="empty">No sessions found.<br>Check Settings (site URL + API key) and that you have internet.</p>'));
        }
        state.sessions.forEach(function (s) {
            var sel = state.selectedSession && state.selectedSession.tracking_code === s.tracking_code;
            var row = el(
                '<div class="sess' + (sel ? ' sess--selected' : '') + '">' +
                    '<span class="sess__dot' + (s.current_stage === 'delivered' ? ' sess__dot--delivered' : (s.current_stage === 'booked' ? ' sess__dot--early' : '')) + '"></span>' +
                    '<span class="sess__info">' +
                        '<div class="sess__name">' + esc(s.client_name) + '</div>' +
                        '<div class="sess__meta">' + esc(s.session_date || 'no date') + ' · ' + esc(s.tracking_code) + '</div>' +
                    '</span>' +
                    '<span class="sess__stage">' + esc(s.current_stage || '') + '</span>' +
                '</div>'
            );
            row.addEventListener('click', function () {
                state.selectedSession = s;
                localStorage.setItem('tb_selected_session', JSON.stringify(s));
                toast('Shoot selected: ' + s.client_name);
                renderSessions();
            });
            card.appendChild(row);
        });
        view.innerHTML = '';
        view.appendChild(card);

        if (state.selectedSession) {
            view.appendChild(el(
                '<div class="q-summary">Active shoot: <strong>' + esc(state.selectedSession.client_name) + '</strong><br>' +
                'Code: <strong>' + esc(state.selectedSession.tracking_code) + '</strong> — imports and uploads go here.</div>'
            ));
        }
    }

    // ── Import ──
    async function renderImport() {
        view.innerHTML = '';

        if (!state.selectedSession) {
            view.appendChild(el('<div class="card"><h2>Import photos</h2><p class="empty">Pick a shoot on the Sessions tab first.</p></div>'));
            return;
        }

        var card = el(
            '<div class="card"><h2>Import photos</h2>' +
                '<p class="hint">Grab the JPEGs from your card. They are grouped by capture time and matched to the shoot — proofs are resized on the phone, then the website adds the watermark.</p>' +
                '<button class="btn btn--dark" id="btn-wd">Scan WD Wireless Pro (192.168.60.1)</button>' +
                '<button class="btn btn--ghost" id="btn-phone">Pick from this phone</button>' +
                '<input type="file" id="file-input" accept="image/jpeg,image/jpg" multiple style="display:none;">' +
                '<div class="field" style="margin-top:12px;"><label>Or jump to a folder path on the WD</label>' +
                    '<div style="display:flex; gap:8px;">' +
                        '<input type="text" id="wd-path-input" placeholder="/DCIM/100MSDCF" style="flex:1;">' +
                        '<button class="btn btn--ghost btn--sm" id="wd-path-go" style="width:auto; flex-shrink:0;">Go</button>' +
                    '</div>' +
                '</div>' +
                '<button class="btn btn--ghost btn--sm" id="btn-wd-diag" style="margin-top:6px;">Diagnose WD connection</button>' +
            '</div>'
        );
        view.appendChild(card);
        var groupsHost = el('<div id="groups-host"></div>');
        view.appendChild(groupsHost);

        card.querySelector('#wd-path-go').addEventListener('click', async function () {
            var p = card.querySelector('#wd-path-input').value.trim();
            if (!p) return;
            if (p.charAt(0) !== '/') p = '/' + p;
            try {
                await renderWdBrowser(groupsHost, p);
            } catch (e) {
                toast('Could not open that path: ' + e.message, 5000);
            }
        });

        card.querySelector('#btn-wd-diag').addEventListener('click', async function () {
            var btn = this;
            btn.disabled = true;
            btn.textContent = 'Probing WD endpoints...';
            var results = await TwellerWD.diagnose();
            btn.disabled = false;
            btn.textContent = 'Diagnose WD connection';
            renderWdDiagnostics(groupsHost, results);
        });

        card.querySelector('#btn-phone').addEventListener('click', function () {
            card.querySelector('#file-input').click();
        });
        card.querySelector('#file-input').addEventListener('change', async function () {
            var files = Array.from(this.files || []);
            if (!files.length) return;
            toast('Reading capture times from ' + files.length + ' photos...');
            var items = [];
            for (var i = 0; i < files.length; i++) {
                items.push({ name: files[i].name, date: await TwellerExif.fromFile(files[i]), file: files[i] });
            }
            state.groups = TwellerExif.groupByGap(items, 120);
            renderGroups(groupsHost);
        });

        card.querySelector('#btn-wd').addEventListener('click', async function () {
            var btn = this;
            btn.disabled = true;
            btn.textContent = 'Looking for the WD box...';
            var found = await TwellerWD.detect();
            if (!found) {
                btn.disabled = false;
                btn.textContent = 'Scan WD Wireless Pro (192.168.60.1)';
                toast('WD not reachable. Join the WD’s Wi-Fi first. (Browser builds can’t reach it — use the installed app.)', 5000);
                return;
            }
            btn.textContent = 'Connected — browsing...';
            try {
                await renderWdBrowser(groupsHost, '/');
            } catch (e) {
                toast('Could not list WD files: ' + e.message, 5000);
            }
            btn.disabled = false;
            btn.textContent = 'Scan WD Wireless Pro (192.168.60.1)';
        });
    }

    function renderWdDiagnostics(host, results) {
        host.innerHTML = '';
        var card = el(
            '<div class="card"><h2>WD connection diagnostics</h2>' +
            '<p class="hint">None of these paths are confirmed to match your drive\'s firmware — this just shows exactly what each one returns. ' +
            'Screenshot this and send it over so the right one can be wired up for good.</p></div>'
        );
        results.forEach(function (r) {
            var statusLabel = r.error ? 'ERROR' : (r.status + '');
            var ok = !r.error && r.status >= 200 && r.status < 300;
            card.appendChild(el(
                '<div class="grp">' +
                    '<div class="grp__head"><span class="grp__title" style="font-size:12.5px;">' + esc(r.path) + '</span>' +
                        '<span class="grp__time" style="color:' + (ok ? '#16A34A' : '#DC2626') + ';">' + esc(statusLabel) + '</span></div>' +
                    '<div style="font-size:11px; color:#8A8178; word-break:break-all; white-space:pre-wrap; max-height:80px; overflow:auto;">' +
                        esc(r.error || r.snippet || '(empty body)') +
                    '</div>' +
                '</div>'
            ));
        });
        host.appendChild(card);
    }

    async function renderWdBrowser(host, path) {
        host.innerHTML = '<div class="card"><div class="spin"></div></div>';
        var listing = await TwellerWD.list(path);

        var card = el('<div class="card"><h2>WD: ' + esc(path) + '</h2></div>');
        if (path !== '/') {
            var up = el('<div class="sess"><span class="sess__info"><div class="sess__name">.. (up)</div></span></div>');
            up.addEventListener('click', function () {
                renderWdBrowser(host, path.replace(/\/[^/]+\/?$/, '') || '/');
            });
            card.appendChild(up);
        }
        listing.dirs.forEach(function (d) {
            var row = el('<div class="sess"><span class="sess__info"><div class="sess__name">📁 ' + esc(d) + '</div></span></div>');
            row.addEventListener('click', function () { renderWdBrowser(host, (path.replace(/\/+$/, '') || '') + '/' + d); });
            card.appendChild(row);
        });

        if (!listing.dirs.length && !listing.files.length) {
            card.appendChild(el(
                '<p class="empty">No folders or files found at this path via the endpoints this app knows about.<br>' +
                'Try a specific path above (e.g. the folder your file manager app shows for the SD card), or tap ' +
                '<strong>Diagnose WD connection</strong> below to see raw responses.</p>'
            ));
        }

        var jpegs = listing.files.filter(function (f) { return TwellerWD.isJpeg(f.name); });
        if (jpegs.length) {
            var btn = el('<button class="btn">Import ' + jpegs.length + ' JPEG(s) from this folder</button>');
            btn.addEventListener('click', async function () {
                btn.disabled = true;
                var items = [];
                for (var i = 0; i < jpegs.length; i++) {
                    btn.textContent = 'Reading EXIF ' + (i + 1) + '/' + jpegs.length + '...';
                    var dt = null;
                    try {
                        dt = TwellerExif.dateTimeOriginal(await TwellerWD.fetchHead(jpegs[i].path, 131072));
                    } catch (e) {}
                    items.push({ name: jpegs[i].name, date: dt, wdPath: jpegs[i].path });
                }
                state.groups = TwellerExif.groupByGap(items, 120);
                renderGroups(host);
            });
            card.appendChild(btn);
        }
        host.innerHTML = '';
        host.appendChild(card);
    }

    function matchLabel(group) {
        // Does this group's date line up with the selected session?
        var s = state.selectedSession;
        if (!s || !group.start) return '';
        var d = group.start;
        var dateStr = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        if (s.session_date === dateStr) {
            return '<div class="grp__match">✓ Matches <strong>' + esc(s.client_name) + '</strong> (' + esc(s.session_date) + ')</div>';
        }
        return '<div class="grp__match">⚠ Taken ' + esc(dateStr) + ' but the selected shoot is ' + esc(s.session_date || '?') + ' — double-check before queueing.</div>';
    }

    function renderGroups(host) {
        host.innerHTML = '';
        var card = el('<div class="card"><h2>Detected shoots</h2><p class="hint">Photos are grouped when there’s more than a 2-hour gap between captures.</p></div>');

        state.groups.forEach(function (g, gi) {
            var grp = el(
                '<div class="grp">' +
                    '<div class="grp__head">' +
                        '<span class="grp__title">Group ' + (gi + 1) + ' — ' + g.items.length + ' photos</span>' +
                        '<span class="grp__time">' + fmtTime(g.start) + '–' + fmtTime(g.end) + '</span>' +
                    '</div>' +
                    matchLabel(g) +
                    '<button class="btn btn--sm" style="margin-top:10px;">Queue these ' + g.items.length + ' for upload</button>' +
                '</div>'
            );
            grp.querySelector('button').addEventListener('click', function () {
                queueGroup(g, this);
            });
            card.appendChild(grp);
        });
        host.appendChild(card);
    }

    async function queueGroup(group, btn) {
        var code = state.selectedSession.tracking_code;
        btn.disabled = true;

        var existing = [];
        try { existing = await TwellerApi.proofFilenames(code); } catch (e) {}

        var added = 0, skipped = 0;
        for (var i = 0; i < group.items.length; i++) {
            var item = group.items[i];
            btn.textContent = 'Preparing ' + (i + 1) + '/' + group.items.length + '...';

            if (existing.indexOf(item.name) !== -1) { skipped++; continue; }

            var source = item.file || (item.wdPath ? await TwellerWD.fetchFile(item.wdPath) : null);
            if (!source) continue;

            var proof = await TwellerApi.resizeForProof(source);
            await qPut({
                code: code,
                client: state.selectedSession.client_name,
                filename: item.name,
                blob: proof,
                size: proof.size,
                status: 'pending',
                addedAt: Date.now()
            });
            added++;
        }

        btn.textContent = added + ' queued' + (skipped ? ' (' + skipped + ' already on site)' : '');
        refreshBadge();
        toast(added + ' proofs queued — open the Queue tab to upload.');
    }

    // ── Queue ──
    async function renderQueue() {
        view.innerHTML = '<div class="card"><div class="spin"></div></div>';
        var items = await qAll();
        var pending = items.filter(function (i) { return i.status !== 'done'; });
        var totalBytes = pending.reduce(function (a, i) { return a + (i.size || 0); }, 0);
        var conn = await connectionType();

        view.innerHTML = '';

        if (!items.length) {
            view.appendChild(el('<div class="card"><h2>Upload queue</h2><p class="empty">Nothing queued.<br>Import photos from the Import tab.</p></div>'));
            return;
        }

        var connLabel = conn === 'wifi' ? 'Wi-Fi' : (conn === 'cellular' ? 'MOBILE DATA' : (conn === 'none' ? 'offline' : 'unknown connection'));
        view.appendChild(el(
            '<div class="q-summary">' +
                '<strong>' + pending.length + '</strong> proofs waiting · ≈ <strong>' + fmtMB(totalBytes) + '</strong> to upload<br>' +
                'Connection: <strong>' + connLabel + '</strong>' +
                (state.settings.wifiOnly ? ' · Wi-Fi-only is ON' : '') +
            '</div>'
        ));

        var card = el('<div class="card"><h2>Upload queue</h2><div class="progress"><div class="progress__bar" id="q-bar"></div></div></div>');
        items.slice(0, 200).forEach(function (i) {
            card.appendChild(el(
                '<div class="q-item">' +
                    '<span class="q-item__name">' + esc(i.filename) + '<br><span class="q-item__size">' + esc(i.client || i.code) + ' · ' + fmtMB(i.size || 0) + '</span></span>' +
                    '<span class="q-item__st q-item__st--' + i.status + '">' + i.status.toUpperCase() + '</span>' +
                '</div>'
            ));
        });
        view.appendChild(card);

        if (pending.length) {
            var startBtn = el('<button class="btn" id="q-start">Upload ' + pending.length + ' proofs now</button>');
            startBtn.addEventListener('click', function () { startUpload(pending, conn, totalBytes, startBtn); });
            view.appendChild(startBtn);
        }

        var doneForCode = items.filter(function (i) { return i.status === 'done'; });
        if (doneForCode.length && !pending.length) {
            var readyBtn = el('<button class="btn btn--dark">✉ Notify client — selection gallery is ready</button>');
            readyBtn.addEventListener('click', async function () {
                readyBtn.disabled = true;
                readyBtn.textContent = 'Sending...';
                try {
                    await TwellerApi.markCullingReady(doneForCode[0].code);
                    readyBtn.textContent = '✓ Client notified — gallery is live';
                    // Clear finished items so the next shoot starts clean
                    for (var i = 0; i < doneForCode.length; i++) await qDelete(doneForCode[i].id);
                    refreshBadge();
                } catch (e) {
                    readyBtn.disabled = false;
                    readyBtn.textContent = 'Retry: notify client';
                    toast('Could not mark ready: ' + e.message, 5000);
                }
            });
            view.appendChild(readyBtn);
        }

        var clearBtn = el('<button class="btn btn--danger">Clear queue</button>');
        clearBtn.addEventListener('click', async function () {
            if (!confirm('Remove everything from the queue? Uploaded proofs stay on the website.')) return;
            for (var i = 0; i < items.length; i++) await qDelete(items[i].id);
            refreshBadge();
            renderQueue();
        });
        view.appendChild(clearBtn);
    }

    async function startUpload(pending, conn, totalBytes, btn) {
        if (state.uploading) return;

        if (conn === 'none') { toast('No connection — connect to Wi-Fi or mobile data first.'); return; }
        if (conn === 'cellular') {
            var msg = state.settings.wifiOnly
                ? 'Wi-Fi-only is ON and you are on MOBILE DATA.\n\nThis upload will use about ' + fmtMB(totalBytes) + ' of data. Upload anyway?'
                : 'You are on MOBILE DATA. This upload will use about ' + fmtMB(totalBytes) + '. Continue?';
            if (!confirm(msg)) return;
        }

        state.uploading = true;
        btn.disabled = true;
        var bar = document.getElementById('q-bar');
        var ok = 0, failed = 0;

        for (var i = 0; i < pending.length; i++) {
            var item = pending[i];
            btn.textContent = 'Uploading ' + (i + 1) + ' / ' + pending.length + '...';
            bar.style.width = Math.round((i / pending.length) * 100) + '%';
            try {
                await TwellerApi.uploadProof(item.code, item.blob, item.filename, item.filename);
                item.status = 'done';
                ok++;
            } catch (e) {
                item.status = 'error';
                item.error = e.message;
                failed++;
            }
            await qPut(item);
        }

        bar.style.width = '100%';
        state.uploading = false;
        refreshBadge();
        toast(ok + ' uploaded' + (failed ? ', ' + failed + ' failed (will retry from queue)' : '') + '.');
        renderQueue();
    }

    // ── Settings ──
    function renderSettings() {
        view.innerHTML = '';
        var card = el(
            '<div class="card"><h2>Connection</h2>' +
                '<div class="field"><label>Website URL</label><input type="url" id="set-site" value="' + esc(state.settings.siteUrl) + '"></div>' +
                '<div class="field"><label>API Key (same as the Lightroom plugin)</label><input type="password" id="set-key" value="' + esc(state.settings.apiKey) + '"></div>' +
                '<label class="toggle"><input type="checkbox" id="set-wifi" ' + (state.settings.wifiOnly ? 'checked' : '') + '><span>Upload on Wi-Fi only (ask before using mobile data)</span></label>' +
                '<button class="btn" id="set-save">Save</button>' +
            '</div>'
        );
        card.querySelector('#set-save').addEventListener('click', function () {
            state.settings.siteUrl = card.querySelector('#set-site').value.trim();
            state.settings.apiKey = card.querySelector('#set-key').value.trim();
            state.settings.wifiOnly = card.querySelector('#set-wifi').checked;
            localStorage.setItem('tb_site', state.settings.siteUrl);
            localStorage.setItem('tb_key', state.settings.apiKey);
            localStorage.setItem('tb_wifi_only', state.settings.wifiOnly ? '1' : '0');
            TwellerApi.configure(state.settings.siteUrl, state.settings.apiKey);
            toast('Saved.');
        });
        view.appendChild(card);
        view.appendChild(el(
            '<div class="card"><h2>How it works</h2><p class="hint">' +
            '1. Pick the shoot on <strong>Sessions</strong> (cached for use on the WD’s Wi-Fi).<br>' +
            '2. <strong>Import</strong> JPEGs from the WD box or this phone — grouped by capture time.<br>' +
            '3. <strong>Queue</strong> uploads them as proofs; the website compresses further and adds the Tweller Studios watermark.<br>' +
            '4. Notify the client — they start choosing photos while you pack up.</p></div>'
        ));
    }

    // Boot
    renderSessions();
    refreshBadge();
})();
