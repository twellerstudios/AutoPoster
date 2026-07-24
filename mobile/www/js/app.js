/**
 * Tweller Bookings Mobile — app shell (v0.2).
 *
 * Screens:
 *   Home     — pipeline dashboard: stats, up-next shoots, needs-attention
 *   Clients  — every session, searchable, filterable by stage
 *   Session  — full detail: info, payment, culling, gallery, timeline, notes
 *            → Upload for Culling (watermarked proofs, resized on-device)
 *            → Deliver to Gallery (finished photos, full resolution)
 *   Uploads  — persistent queue with exact data estimates + Wi-Fi guard
 *   Settings — site URL + API key (same as the Lightroom plugin)
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
        clients: [],          // cached full session list
        clientFilter: { search: '', stage: '' },
        uploading: false
    };

    TwellerApi.configure(state.settings.siteUrl, state.settings.apiKey);

    // ── Stage + payment presentation ─────────────────────────────

    var STAGE_META = {
        booked:    { label: 'Reserved',        pill: 'gray' },
        confirmed: { label: 'Confirmed',       pill: 'blue' },
        imported:  { label: 'Imported',        pill: 'purple' },
        culling:   { label: 'Client choosing', pill: 'gold' },
        culled:    { label: 'Selections in',   pill: 'gold' },
        editing:   { label: 'Editing',         pill: 'purple' },
        edited:    { label: 'Edited',          pill: 'purple' },
        exporting: { label: 'Exporting',       pill: 'blue' },
        exported:  { label: 'Exported',        pill: 'blue' },
        uploading: { label: 'Uploading',       pill: 'blue' },
        uploaded:  { label: 'Gallery ready',   pill: 'blue' },
        delivered: { label: 'Delivered',       pill: 'green' }
    };
    var PAY_META = {
        pending:   { label: 'Unpaid',        pill: 'red' },
        verifying: { label: 'Verify receipt',pill: 'gold' },
        deposit:   { label: 'Deposit paid',  pill: 'blue' },
        paid:      { label: 'Paid in full',  pill: 'green' }
    };
    function stageMeta(key) { return STAGE_META[key] || { label: key || '—', pill: 'gray' }; }
    function payMeta(key)   { return PAY_META[key]   || { label: key || '—', pill: 'gray' }; }

    function stagePill(key) {
        var m = stageMeta(key);
        return '<span class="pill pill--' + m.pill + '"><span class="pill__dot"></span>' + esc(m.label) + '</span>';
    }
    function payPill(key) {
        var m = payMeta(key);
        return '<span class="pill pill--' + m.pill + '">' + esc(m.label) + '</span>';
    }

    // ── Tiny IndexedDB queue (blobs survive app restarts) ────────

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

    // ── Helpers ──────────────────────────────────────────────────

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
    function initials(name) {
        var parts = String(name || '?').trim().split(/\s+/);
        return ((parts[0] || '')[0] || '?').toUpperCase() + ((parts[1] || '')[0] || '').toUpperCase();
    }
    function money(n) {
        n = parseFloat(n || 0);
        if (!n) return '—';
        return 'TT$' + n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }
    function fmtDate(ymd) {
        if (!ymd) return 'No date';
        var d = new Date(ymd + 'T12:00:00');
        if (isNaN(d)) return ymd;
        return d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
    }
    function fmtDateShort(ymd) {
        if (!ymd) return 'No date';
        var d = new Date(ymd + 'T12:00:00');
        if (isNaN(d)) return ymd;
        return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    }
    function fmtClock(hms) {
        if (!hms) return '';
        var d = new Date('2000-01-01T' + hms);
        if (isNaN(d)) return hms;
        return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }
    function fmtStamp(mysql) {
        if (!mysql) return '';
        var d = new Date(mysql.replace(' ', 'T'));
        if (isNaN(d)) return mysql;
        return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' · ' +
               d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }
    function fmtTime(d) {
        return d ? d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '?';
    }
    function openExternal(url) {
        if (url) window.open(url, '_blank');
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

    var ICONS = {
        chev: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>',
        back: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>',
        search: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
        filter: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>',
        gallery: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
        phone: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
        mail: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
        pin: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
        cal: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        users: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>',
        upload: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
        star: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        link: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>'
    };

    // ── Navigation (tab roots + pushed sub-screens) ──────────────

    var navStack = [];

    function setActiveTab(name) {
        document.querySelectorAll('.tab').forEach(function (b) {
            b.classList.toggle('tab--active', b.getAttribute('data-tab') === name);
        });
    }

    function renderRoot(name) {
        navStack = [];
        setActiveTab(name);
        ({ home: renderHome, clients: renderClients, queue: renderQueue, settings: renderSettings })[name]();
        window.scrollTo(0, 0);
    }

    function push(renderFn) {
        navStack.push(renderFn);
        history.pushState({ depth: navStack.length }, '');
        renderFn();
        window.scrollTo(0, 0);
    }

    function currentScreen() {
        return navStack.length ? navStack[navStack.length - 1] : null;
    }

    window.addEventListener('popstate', function () {
        if (navStack.length) {
            navStack.pop();
            var s = currentScreen();
            if (s) s(); else renderRoot(activeTabName());
            window.scrollTo(0, 0);
        }
    });

    function activeTabName() {
        var t = document.querySelector('.tab--active');
        return t ? t.getAttribute('data-tab') : 'home';
    }

    function back() { history.back(); }

    document.querySelectorAll('.tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            // Collapse any pushed sub-screens without replaying them
            var depth = navStack.length;
            navStack = [];
            if (depth) history.go(-depth);
            renderRoot(btn.getAttribute('data-tab'));
        });
    });

    function topbar(title) {
        var bar = el(
            '<div class="topbar">' +
                '<button class="topbar__back">' + ICONS.back + '</button>' +
                '<div class="topbar__title">' + esc(title) + '</div>' +
            '</div>'
        );
        bar.querySelector('.topbar__back').addEventListener('click', back);
        return bar;
    }

    // ═════════════════════════════════════════════════════════════
    //  HOME — dashboard
    // ═════════════════════════════════════════════════════════════

    async function renderHome() {
        view.innerHTML =
            '<div class="screen">' +
                '<header class="hdr"><div class="hdr__kicker">Tweller Studios</div>' +
                '<div class="hdr__title">Bookings</div>' +
                '<div class="hdr__sub">' + new Date().toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' }) + '</div></header>' +
                '<div class="wrap"><div class="skeleton"></div><div class="skeleton"></div></div>' +
            '</div>';

        var data = null, offline = false;
        try {
            data = await TwellerApi.fetchOverview();
        } catch (e) {
            var cache = TwellerApi.cachedOverview();
            if (cache) { data = cache.data; offline = true; }
        }

        var screen = el('<div class="screen"></div>');
        screen.appendChild(el(
            '<header class="hdr"><div class="hdr__kicker">Tweller Studios</div>' +
            '<div class="hdr__title">Bookings</div>' +
            '<div class="hdr__sub">' + new Date().toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' }) +
            (offline ? ' · offline (cached)' : '') + '</div></header>'
        ));
        var wrap = el('<div class="wrap"></div>');
        screen.appendChild(wrap);

        if (!data) {
            wrap.appendChild(el(
                '<div class="card"><p class="empty">Couldn\'t reach the studio website.<br>' +
                'Check <strong>Settings</strong> (site URL + API key) and your connection.</p></div>'
            ));
            view.innerHTML = '';
            view.appendChild(screen);
            return;
        }

        var counts = data.stage_counts || {};
        function sum(keys) {
            return keys.reduce(function (a, k) { return a + (counts[k] || 0); }, 0);
        }
        wrap.appendChild(el(
            '<div class="stats">' +
                '<div class="stat stat--accent"><div class="stat__num">' + (data.active_count || 0) + '</div><div class="stat__label">Active sessions</div></div>' +
                '<div class="stat"><div class="stat__num">' + sum(['culling']) + '</div><div class="stat__label">Clients choosing</div></div>' +
                '<div class="stat"><div class="stat__num">' + sum(['culled', 'editing', 'edited']) + '</div><div class="stat__label">In editing</div></div>' +
                '<div class="stat"><div class="stat__num">' + (counts.delivered || 0) + '</div><div class="stat__label">Delivered</div></div>' +
            '</div>'
        ));

        // Needs attention
        if ((data.attention || []).length) {
            var att = el('<div class="card"><div class="card__title">Needs your attention</div></div>');
            data.attention.slice(0, 6).forEach(function (s) {
                var row = el(
                    '<div class="row">' +
                        '<span class="avatar avatar--sm">' + esc(initials(s.client_name)) + '</span>' +
                        '<span class="row__body"><div class="row__name">' + esc(s.client_name) + '</div>' +
                        '<div class="row__meta">' + esc((s.reasons || []).join(' · ')) + '</div></span>' +
                        '<span class="row__chev">' + ICONS.chev + '</span>' +
                    '</div>'
                );
                row.addEventListener('click', function () { openSession(s.tracking_code); });
                att.appendChild(row);
            });
            wrap.appendChild(att);
        }

        // Up next
        var up = el('<div class="card"><div class="card__title">Up next</div></div>');
        if ((data.upcoming || []).length) {
            data.upcoming.forEach(function (s) {
                var when = fmtDateShort(s.session_date) + (s.session_time ? ' · ' + fmtClock(s.session_time) : '');
                var row = el(
                    '<div class="row">' +
                        '<span class="avatar avatar--sm">' + esc(initials(s.client_name)) + '</span>' +
                        '<span class="row__body"><div class="row__name">' + esc(s.client_name) + '</div>' +
                        '<div class="row__meta">' + esc(when) + (s.location ? ' · ' + esc(s.location) : '') + '</div></span>' +
                        '<span class="row__end">' + stagePill(s.current_stage) + '</span>' +
                    '</div>'
                );
                row.addEventListener('click', function () { openSession(s.tracking_code); });
                up.appendChild(row);
            });
        } else {
            up.appendChild(el('<p class="empty">No upcoming shoots on the calendar.</p>'));
        }
        wrap.appendChild(up);

        var all = el('<button class="btn btn--ghost">View all clients</button>');
        all.addEventListener('click', function () { renderRoot('clients'); });
        wrap.appendChild(all);

        view.innerHTML = '';
        view.appendChild(screen);
    }

    // ═════════════════════════════════════════════════════════════
    //  CLIENTS — searchable session list
    // ═════════════════════════════════════════════════════════════

    var STAGE_FILTERS = [
        { key: '',          label: 'All' },
        { key: 'booked',    label: 'Reserved' },
        { key: 'confirmed', label: 'Confirmed' },
        { key: 'culling',   label: 'Choosing' },
        { key: '_editing',  label: 'Editing' },
        { key: 'uploaded',  label: 'Gallery ready' },
        { key: 'delivered', label: 'Delivered' }
    ];
    var EDITING_KEYS = ['imported', 'culled', 'editing', 'edited', 'exporting', 'exported', 'uploading'];

    async function renderClients() {
        view.innerHTML =
            '<div class="screen">' +
                '<header class="hdr"><div class="hdr__kicker">Tweller Studios</div><div class="hdr__title">Clients</div></header>' +
                '<div class="wrap"><div class="skeleton"></div><div class="skeleton"></div></div>' +
            '</div>';

        var offline = false;
        try {
            state.clients = await TwellerApi.fetchSessions({ range: 'all' });
        } catch (e) {
            var cache = TwellerApi.cachedSessions();
            if (cache) { state.clients = cache.sessions || []; offline = true; }
            else state.clients = [];
        }
        paintClients(offline);
    }

    function paintClients(offline) {
        var f = state.clientFilter;

        var screen = el('<div class="screen"></div>');
        screen.appendChild(el(
            '<header class="hdr"><div class="hdr__kicker">Tweller Studios</div><div class="hdr__title">Clients</div>' +
            '<div class="hdr__sub">' + state.clients.length + ' sessions' + (offline ? ' · offline (cached)' : '') + '</div></header>'
        ));
        var wrap = el('<div class="wrap"></div>');
        screen.appendChild(wrap);

        var search = el(
            '<div class="search">' + ICONS.search +
            '<input type="search" placeholder="Search name, email or shoot code" value="' + esc(f.search) + '"></div>'
        );
        search.querySelector('input').addEventListener('input', function () {
            f.search = this.value;
            paintList();
        });
        wrap.appendChild(search);

        var chips = el('<div class="chips"></div>');
        STAGE_FILTERS.forEach(function (sf) {
            var c = el('<button class="chip' + (f.stage === sf.key ? ' chip--on' : '') + '">' + esc(sf.label) + '</button>');
            c.addEventListener('click', function () {
                f.stage = sf.key;
                chips.querySelectorAll('.chip').forEach(function (x) { x.classList.remove('chip--on'); });
                c.classList.add('chip--on');
                paintList();
            });
            chips.appendChild(c);
        });
        wrap.appendChild(chips);

        var listCard = el('<div class="card" id="client-list"></div>');
        wrap.appendChild(listCard);

        function paintList() {
            var q = (f.search || '').toLowerCase();
            var rows = state.clients.filter(function (s) {
                if (f.stage === '_editing') {
                    if (EDITING_KEYS.indexOf(s.current_stage) === -1) return false;
                } else if (f.stage && s.current_stage !== f.stage) return false;
                if (!q) return true;
                return (s.client_name || '').toLowerCase().indexOf(q) !== -1 ||
                       (s.client_email || '').toLowerCase().indexOf(q) !== -1 ||
                       (s.tracking_code || '').toLowerCase().indexOf(q) !== -1;
            });

            listCard.innerHTML = '';
            if (!rows.length) {
                listCard.appendChild(el('<p class="empty">No sessions match.</p>'));
                return;
            }
            rows.forEach(function (s) {
                var row = el(
                    '<div class="row">' +
                        '<span class="avatar">' + esc(initials(s.client_name)) + '</span>' +
                        '<span class="row__body"><div class="row__name">' + esc(s.client_name) + '</div>' +
                        '<div class="row__meta">' + esc(fmtDateShort(s.session_date)) +
                        (s.package_type ? ' · ' + esc(s.package_type) : '') + '</div></span>' +
                        '<span class="row__end">' + stagePill(s.current_stage) + '</span>' +
                        '<span class="row__chev">' + ICONS.chev + '</span>' +
                    '</div>'
                );
                row.addEventListener('click', function () { openSession(s.tracking_code); });
                listCard.appendChild(row);
            });
        }
        paintList();

        view.innerHTML = '';
        view.appendChild(screen);
    }

    // ═════════════════════════════════════════════════════════════
    //  SESSION DETAIL
    // ═════════════════════════════════════════════════════════════

    function openSession(code) {
        push(function () { renderSession(code); });
    }

    async function renderSession(code) {
        view.innerHTML = '';
        view.appendChild(topbar('Session'));
        view.appendChild(el('<div class="wrap"><div class="skeleton" style="height:130px;"></div><div class="skeleton"></div></div>'));

        var d;
        try {
            d = await TwellerApi.fetchSessionDetail(code);
        } catch (e) {
            view.innerHTML = '';
            view.appendChild(topbar('Session'));
            view.appendChild(el('<div class="wrap"><div class="card"><p class="empty">Could not load this session.<br>' + esc(e.message) + '</p></div></div>'));
            return;
        }
        // Only paint if this screen is still on top (user may have gone back)
        var top = currentScreen();
        if (!top) return;

        var s = d.session;
        var screen = el('<div class="screen"></div>');
        screen.appendChild(topbar(s.client_name));
        var wrap = el('<div class="wrap"></div>');
        screen.appendChild(wrap);

        // Hero
        var when = fmtDate(s.session_date) + (s.session_time ? ' · ' + fmtClock(s.session_time) : '');
        wrap.appendChild(el(
            '<div class="hero">' +
                '<div class="hero__top">' +
                    '<span class="avatar">' + esc(initials(s.client_name)) + '</span>' +
                    '<div><div class="hero__name">' + esc(s.client_name) + '</div>' +
                    '<div class="hero__meta">' + esc((s.package_type || 'Session') + ' · ' + when) + '</div></div>' +
                '</div>' +
                '<div class="hero__pills">' +
                    '<span class="pill pill--stage"><span class="pill__dot"></span>' + esc(stageMeta(s.current_stage).label) + '</span>' +
                    '<span class="pill pill--onblack">' + esc(payMeta(s.payment_status).label) + '</span>' +
                    '<span class="pill pill--onblack">' + esc(s.tracking_code) + '</span>' +
                '</div>' +
            '</div>'
        ));

        // Primary actions
        var actions = el(
            '<div class="actions">' +
                '<button class="action" id="act-cull">' +
                    '<span class="action__icon action__icon--gold">' + ICONS.filter + '</span>' +
                    '<div class="action__name">Upload for Culling</div>' +
                    '<div class="action__sub">Watermarked proofs for selection</div>' +
                '</button>' +
                '<button class="action" id="act-deliver">' +
                    '<span class="action__icon action__icon--dark">' + ICONS.gallery + '</span>' +
                    '<div class="action__name">Deliver to Gallery</div>' +
                    '<div class="action__sub">Finished photos · full quality</div>' +
                '</button>' +
            '</div>'
        );
        actions.querySelector('#act-cull').addEventListener('click', function () {
            push(function () { renderCullingUpload(d); });
        });
        actions.querySelector('#act-deliver').addEventListener('click', function () {
            push(function () { renderGalleryUpload(d); });
        });
        wrap.appendChild(actions);

        // Contact & logistics
        var contact = el('<div class="card"><div class="card__title">Contact &amp; shoot</div></div>');
        function infoRow(icon, label, value, href) {
            if (!value) return null;
            var tag = href ? 'a' : 'div';
            var r = el(
                '<' + tag + ' class="info"' + (href ? ' href="' + esc(href) + '"' : '') + '>' +
                    '<span class="info__icon">' + icon + '</span>' +
                    '<span><div class="info__label">' + esc(label) + '</div>' +
                    '<div class="info__value">' + esc(value) + '</div></span>' +
                '</' + tag + '>'
            );
            return r;
        }
        [
            infoRow(ICONS.cal, 'Date & time', when),
            infoRow(ICONS.pin, 'Location', s.location),
            infoRow(ICONS.phone, 'Phone', s.client_phone, s.client_phone ? 'tel:' + s.client_phone : null),
            infoRow(ICONS.mail, 'Email', s.client_email, s.client_email ? 'mailto:' + s.client_email : null),
            infoRow(ICONS.users, 'People', s.members_count > 1 ? s.members_count + ' people' : null)
        ].forEach(function (r) { if (r) contact.appendChild(r); });
        wrap.appendChild(contact);

        // Payment
        var pay = el(
            '<div class="card"><div class="card__title">Payment</div>' +
                '<div class="kv"><span class="kv__k">Package total</span><span class="kv__v kv__v--big">' + esc(money(s.total_amount)) + '</span></div>' +
                '<div class="kv"><span class="kv__k">Deposit</span><span class="kv__v">' + esc(money(s.deposit_amount)) + '</span></div>' +
                '<div class="kv"><span class="kv__k">Status</span><span class="kv__v">' + payPill(s.payment_status) + '</span></div>' +
            '</div>'
        );
        if (d.receipt && d.receipt.url) {
            pay.appendChild(el(
                '<div class="kv"><span class="kv__k">Receipt' + (d.receipt.ocr ? ' · detected ' + esc(d.receipt.ocr) : '') + '</span>' +
                '<span class="kv__v"><a href="' + esc(d.receipt.url) + '" target="_blank">View ↗</a></span></div>'
            ));
        }
        if (s.payment_status !== 'paid') {
            var markPaid = el('<button class="btn btn--sm btn--ghost" style="margin-top:12px;">Mark as fully paid</button>');
            markPaid.addEventListener('click', async function () {
                if (!confirm('Mark ' + s.client_name + ' as fully paid?')) return;
                markPaid.disabled = true;
                try {
                    await TwellerApi.updateSession(code, { payment_status: 'paid' });
                    toast('Payment marked as paid.');
                    renderSession(code);
                } catch (e) {
                    markPaid.disabled = false;
                    toast('Could not update: ' + e.message, 5000);
                }
            });
            pay.appendChild(markPaid);
        }
        wrap.appendChild(pay);

        // Culling status
        var cul = el('<div class="card"><div class="card__title">Photo selection (culling)</div></div>');
        if (d.culling && d.culling.enabled) {
            cul.appendChild(el(
                '<div class="kv"><span class="kv__k">Proofs online</span><span class="kv__v">' + (d.culling.proof_count || 0) + '</span></div>'
            ));
            if (d.culling.submitted) {
                cul.appendChild(el(
                    '<div class="kv"><span class="kv__k">Client selected</span><span class="kv__v">' +
                    (d.selections || []).length + ' photos · ' + esc(fmtStamp(String(d.culling.submitted))) + '</span></div>'
                ));
                if ((d.selections || []).length) {
                    var selBtn = el('<button class="btn btn--sm btn--ghost" style="margin-top:12px;">View selected filenames</button>');
                    var selList = el('<div style="display:none; margin-top:10px; font-size:12.5px; color:var(--ink-2); line-height:1.8;"></div>');
                    selList.innerHTML = d.selections.map(function (x) {
                        return (x.star >= 2 ? '★★ ' : '★ ') + esc(x.filename);
                    }).join('<br>');
                    selBtn.addEventListener('click', function () {
                        var show = selList.style.display === 'none';
                        selList.style.display = show ? 'block' : 'none';
                        selBtn.textContent = show ? 'Hide selected filenames' : 'View selected filenames';
                    });
                    cul.appendChild(selBtn);
                    cul.appendChild(selList);
                }
            } else if (d.culling.ready) {
                cul.appendChild(el('<p class="hint" style="margin-bottom:0;">Gallery is live — waiting on the client\'s picks.</p>'));
            } else if (d.culling.proof_count > 0) {
                var notifyBtn = el('<button class="btn btn--dark btn--sm" style="margin-top:12px;">✉ Notify client — proofs are ready</button>');
                notifyBtn.addEventListener('click', async function () {
                    notifyBtn.disabled = true;
                    try {
                        await TwellerApi.markCullingReady(code);
                        toast('Client notified — selection gallery is live.');
                        renderSession(code);
                    } catch (e) {
                        notifyBtn.disabled = false;
                        toast('Could not notify: ' + e.message, 5000);
                    }
                });
                cul.appendChild(notifyBtn);
            }
            if (d.links && d.links.culling) {
                var openCul = el('<button class="btn btn--sm btn--ghost" style="margin-top:8px;">Open selection portal ' + ICONS.link + '</button>');
                openCul.addEventListener('click', function () { openExternal(d.links.culling); });
                cul.appendChild(openCul);
            }
        } else {
            cul.appendChild(el('<p class="empty" style="padding:10px 0;">No proofs yet.<br>Use <strong>Upload for Culling</strong> above to start.</p>'));
        }
        wrap.appendChild(cul);

        // Gallery status
        var gal = el('<div class="card"><div class="card__title">Delivery gallery</div></div>');
        if (d.gallery && d.gallery.photo_count > 0) {
            gal.appendChild(el(
                '<div class="kv"><span class="kv__k">Photos</span><span class="kv__v">' + d.gallery.photo_count + '</span></div>' +
                ''
            ));
            gal.appendChild(el(
                '<div class="kv"><span class="kv__k">Size on site</span><span class="kv__v">' + esc(String(d.gallery.total_size_mb)) + ' MB</span></div>'
            ));
            if (s.current_stage !== 'delivered') {
                var deliverBtn = el('<button class="btn btn--dark btn--sm" style="margin-top:12px;">✉ Mark delivered &amp; send gallery email</button>');
                deliverBtn.addEventListener('click', async function () {
                    if (!confirm('Send ' + s.client_name + ' the delivery email with their gallery link?')) return;
                    deliverBtn.disabled = true;
                    try {
                        await TwellerApi.advanceStage(code, 'delivered', 'Delivered from mobile app');
                        toast('Delivered — client has the gallery email.');
                        renderSession(code);
                    } catch (e) {
                        deliverBtn.disabled = false;
                        toast('Could not mark delivered: ' + e.message, 5000);
                    }
                });
                gal.appendChild(deliverBtn);
            }
            if (d.links && d.links.tracker) {
                var openGal = el('<button class="btn btn--sm btn--ghost" style="margin-top:8px;">Open client gallery ' + ICONS.link + '</button>');
                openGal.addEventListener('click', function () { openExternal(d.links.tracker); });
                gal.appendChild(openGal);
            }
        } else {
            gal.appendChild(el('<p class="empty" style="padding:10px 0;">Nothing delivered yet.<br>Use <strong>Deliver to Gallery</strong> when the edit is done.</p>'));
        }
        wrap.appendChild(gal);

        // Timeline
        wrap.appendChild(buildTimeline(d));

        // Pipeline control
        var stageCard = el('<div class="card"><div class="card__title">Move stage</div><p class="hint" style="margin-top:0;">Stages marked ✉ email the client automatically.</p></div>');
        var sel = el('<select id="stage-select"></select>');
        (d.stages || []).forEach(function (st) {
            var opt = document.createElement('option');
            opt.value = st.key;
            opt.textContent = st.label + (st.notify ? '  ✉' : '');
            if (st.key === s.current_stage) opt.selected = true;
            sel.appendChild(opt);
        });
        var selWrap = el('<div class="field"></div>');
        selWrap.appendChild(sel);
        stageCard.appendChild(selWrap);
        var moveBtn = el('<button class="btn btn--ghost btn--sm">Set stage</button>');
        moveBtn.addEventListener('click', async function () {
            var target = sel.value;
            if (target === s.current_stage) { toast('Already at that stage.'); return; }
            var st = (d.stages || []).filter(function (x) { return x.key === target; })[0];
            if (st && st.notify && !confirm('"' + st.label + '" sends the client an email. Continue?')) return;
            moveBtn.disabled = true;
            try {
                await TwellerApi.advanceStage(code, target, 'Set from mobile app');
                toast('Stage updated.');
                renderSession(code);
            } catch (e) {
                moveBtn.disabled = false;
                toast('Could not move stage: ' + e.message, 5000);
            }
        });
        stageCard.appendChild(moveBtn);
        wrap.appendChild(stageCard);

        // Notes
        var notes = el(
            '<div class="card"><div class="card__title">Notes</div>' +
                '<div class="field"><textarea id="notes-input" placeholder="Shoot notes, requests, reminders...">' + esc(s.notes || '') + '</textarea></div>' +
                '<button class="btn btn--ghost btn--sm" id="notes-save">Save notes</button>' +
            '</div>'
        );
        notes.querySelector('#notes-save').addEventListener('click', async function () {
            var btn = this;
            btn.disabled = true;
            try {
                await TwellerApi.updateSession(code, { notes: notes.querySelector('#notes-input').value });
                toast('Notes saved.');
            } catch (e) {
                toast('Could not save: ' + e.message, 5000);
            }
            btn.disabled = false;
        });
        wrap.appendChild(notes);

        view.innerHTML = '';
        view.appendChild(screen);
    }

    function buildTimeline(d) {
        var s = d.session;
        var card = el('<div class="card"><div class="card__title">Timeline</div></div>');
        var tl = el('<div class="tl"></div>');

        // Latest history entry per stage (for timestamps + notes)
        var hist = {};
        (d.timeline || []).forEach(function (h) {
            hist[h.stage] = h;
        });

        var currentIdx = parseInt(s.current_stage_index, 10) || 0;
        (d.stages || []).forEach(function (st, idx) {
            var h = hist[st.key];
            var cls = idx < currentIdx ? 'tl__item--done' : (idx === currentIdx ? 'tl__item--current' : 'tl__item--future');
            var note = h && h.notes && idx >= currentIdx - 1 ? h.notes : '';
            tl.appendChild(el(
                '<div class="tl__item ' + cls + '">' +
                    '<div class="tl__rail"><div class="tl__dot"></div><div class="tl__line"></div></div>' +
                    '<div class="tl__body">' +
                        '<div class="tl__stage">' + esc(st.label) + '</div>' +
                        (h ? '<div class="tl__time">' + esc(fmtStamp(h.timestamp)) + '</div>' : '') +
                        (note ? '<div class="tl__note">' + esc(note) + '</div>' : '') +
                    '</div>' +
                '</div>'
            ));
        });
        card.appendChild(tl);
        return card;
    }

    // ═════════════════════════════════════════════════════════════
    //  UPLOAD FOR CULLING (proofs — resized, watermarked server-side)
    // ═════════════════════════════════════════════════════════════

    function renderCullingUpload(d) {
        var s = d.session;
        view.innerHTML = '';
        var screen = el('<div class="screen"></div>');
        screen.appendChild(topbar('Upload for Culling — ' + s.client_name));
        var wrap = el('<div class="wrap"></div>');
        screen.appendChild(wrap);

        var card = el(
            '<div class="card"><div class="card__title">Add proofs</div>' +
                '<p class="hint" style="margin-top:0;">Photos are resized on this phone, then the website compresses further and adds the Tweller watermark. Grouped by capture time so a card with several shoots on it sorts itself out.</p>' +
                '<button class="btn btn--dark" id="btn-phone">' + ICONS.gallery + ' Pick from this phone</button>' +
                '<button class="btn btn--ghost" id="btn-wd">Scan WD Wireless Pro (192.168.60.1)</button>' +
                '<input type="file" id="file-input" accept="image/jpeg,image/jpg" multiple style="display:none;">' +
                '<div class="field" style="margin-top:14px; margin-bottom:0;"><label>Or jump to a WD folder path</label>' +
                    '<div style="display:flex; gap:8px;">' +
                        '<input type="text" id="wd-path-input" placeholder="/DCIM/100MSDCF" style="flex:1;">' +
                        '<button class="btn btn--ghost btn--sm" id="wd-path-go" style="margin-top:0; flex-shrink:0;">Go</button>' +
                    '</div>' +
                '</div>' +
                '<button class="btn btn--ghost btn--sm" id="btn-wd-diag" style="margin-top:10px;">Diagnose WD connection</button>' +
            '</div>'
        );
        wrap.appendChild(card);
        var host = el('<div></div>');
        wrap.appendChild(host);

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
            renderGroups(host, TwellerExif.groupByGap(items, 120), s);
        });

        card.querySelector('#btn-wd').addEventListener('click', async function () {
            var btn = this;
            btn.disabled = true;
            btn.textContent = 'Looking for the WD box...';
            var found = await TwellerWD.detect();
            btn.disabled = false;
            btn.textContent = 'Scan WD Wireless Pro (192.168.60.1)';
            if (!found) {
                toast('WD not reachable. Join the WD’s Wi-Fi first. (Browser builds can’t reach it — use the installed app.)', 5000);
                return;
            }
            try {
                await renderWdBrowser(host, '/', s);
            } catch (e) {
                toast('Could not list WD files: ' + e.message, 5000);
            }
        });

        card.querySelector('#wd-path-go').addEventListener('click', async function () {
            var p = card.querySelector('#wd-path-input').value.trim();
            if (!p) return;
            if (p.charAt(0) !== '/') p = '/' + p;
            try {
                await renderWdBrowser(host, p, s);
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
            renderWdDiagnostics(host, results);
        });

        view.appendChild(screen);
    }

    function renderWdDiagnostics(host, results) {
        host.innerHTML = '';
        var card = el(
            '<div class="card"><div class="card__title">WD diagnostics</div>' +
            '<p class="hint" style="margin-top:0;">Raw response from each candidate endpoint. Screenshot this and send it over so the right one can be wired up for good.</p></div>'
        );
        results.forEach(function (r) {
            var statusLabel = r.error ? 'ERROR' : (r.status + '');
            var ok = !r.error && r.status >= 200 && r.status < 300;
            card.appendChild(el(
                '<div class="grp">' +
                    '<div class="grp__head"><span class="grp__title" style="font-size:12.5px;">' + esc(r.path) + '</span>' +
                        '<span class="grp__time" style="color:' + (ok ? 'var(--green)' : 'var(--red)') + ';">' + esc(statusLabel) + '</span></div>' +
                    '<div style="font-size:11px; color:var(--ink-3); word-break:break-all; white-space:pre-wrap; max-height:80px; overflow:auto;">' +
                        esc(r.error || r.snippet || '(empty body)') +
                    '</div>' +
                '</div>'
            ));
        });
        host.appendChild(card);
    }

    async function renderWdBrowser(host, path, session) {
        host.innerHTML = '<div class="card"><div class="spin"></div></div>';
        var listing = await TwellerWD.list(path);

        var card = el('<div class="card"><div class="card__title">WD: ' + esc(path) + '</div></div>');
        if (path !== '/') {
            var up = el('<div class="row"><span class="row__body"><div class="row__name">.. (up)</div></span></div>');
            up.addEventListener('click', function () {
                renderWdBrowser(host, path.replace(/\/[^/]+\/?$/, '') || '/', session);
            });
            card.appendChild(up);
        }
        listing.dirs.forEach(function (dname) {
            var row = el('<div class="row"><span class="row__body"><div class="row__name">📁 ' + esc(dname) + '</div></span><span class="row__chev">' + ICONS.chev + '</span></div>');
            row.addEventListener('click', function () { renderWdBrowser(host, (path.replace(/\/+$/, '') || '') + '/' + dname, session); });
            card.appendChild(row);
        });

        if (!listing.dirs.length && !listing.files.length) {
            card.appendChild(el(
                '<p class="empty">Nothing found at this path via the endpoints this app knows.<br>' +
                'Try a specific folder path, or run <strong>Diagnose WD connection</strong>.</p>'
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
                renderGroups(host, TwellerExif.groupByGap(items, 120), session);
            });
            card.appendChild(btn);
        }
        host.innerHTML = '';
        host.appendChild(card);
    }

    function matchLabel(group, session) {
        if (!session || !group.start) return '';
        var d = group.start;
        var dateStr = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        if (session.session_date === dateStr) {
            return '<div class="grp__match">✓ Matches <strong>' + esc(session.client_name) + '</strong> (' + esc(session.session_date) + ')</div>';
        }
        return '<div class="grp__match">⚠ Taken ' + esc(dateStr) + ' but this shoot is ' + esc(session.session_date || '?') + ' — double-check before queueing.</div>';
    }

    function renderGroups(host, groups, session) {
        host.innerHTML = '';
        var card = el('<div class="card"><div class="card__title">Detected shoots</div><p class="hint" style="margin-top:0;">Photos are grouped when there’s more than a 2-hour gap between captures.</p></div>');

        groups.forEach(function (g, gi) {
            var grp = el(
                '<div class="grp">' +
                    '<div class="grp__head">' +
                        '<span class="grp__title">Group ' + (gi + 1) + ' — ' + g.items.length + ' photos</span>' +
                        '<span class="grp__time">' + fmtTime(g.start) + '–' + fmtTime(g.end) + '</span>' +
                    '</div>' +
                    matchLabel(g, session) +
                    '<button class="btn btn--sm" style="margin-top:10px;">Queue these ' + g.items.length + ' as proofs</button>' +
                '</div>'
            );
            grp.querySelector('button').addEventListener('click', function () {
                queueProofGroup(g, this, session);
            });
            card.appendChild(grp);
        });
        host.appendChild(card);
    }

    async function queueProofGroup(group, btn, session) {
        var code = session.tracking_code;
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
                type: 'proof',
                code: code,
                client: session.client_name,
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
        toast(added + ' proofs queued — open Uploads to send them.');
    }

    // ═════════════════════════════════════════════════════════════
    //  DELIVER TO GALLERY (finished photos — untouched bytes)
    // ═════════════════════════════════════════════════════════════

    function renderGalleryUpload(d) {
        var s = d.session;
        view.innerHTML = '';
        var screen = el('<div class="screen"></div>');
        screen.appendChild(topbar('Deliver to Gallery — ' + s.client_name));
        var wrap = el('<div class="wrap"></div>');
        screen.appendChild(wrap);

        var card = el(
            '<div class="card"><div class="card__title">Finished photos</div>' +
                '<p class="hint" style="margin-top:0;">For the final delivery gallery. Files upload <strong>exactly as exported — no compression</strong>, so export from Lightroom Mobile at full quality first.</p>' +
                '<button class="btn btn--dark" id="btn-pick">' + ICONS.gallery + ' Pick finished photos</button>' +
                '<input type="file" id="gal-input" accept="image/jpeg,image/jpg,image/png,image/webp" multiple style="display:none;">' +
            '</div>'
        );
        wrap.appendChild(card);
        var host = el('<div></div>');
        wrap.appendChild(host);

        card.querySelector('#btn-pick').addEventListener('click', function () {
            card.querySelector('#gal-input').click();
        });
        card.querySelector('#gal-input').addEventListener('change', async function () {
            var files = Array.from(this.files || []);
            if (!files.length) return;

            var existing = [];
            try { existing = await TwellerApi.galleryFilenames(s.tracking_code); } catch (e) {}

            var fresh = files.filter(function (f) { return existing.indexOf(f.name) === -1; });
            var skipped = files.length - fresh.length;
            var totalBytes = fresh.reduce(function (a, f) { return a + f.size; }, 0);

            host.innerHTML = '';
            var sum = el(
                '<div class="card"><div class="card__title">Ready to queue</div>' +
                    '<div class="kv"><span class="kv__k">Photos</span><span class="kv__v">' + fresh.length +
                        (skipped ? ' <span style="color:var(--ink-3); font-weight:600;">(' + skipped + ' already delivered)</span>' : '') + '</span></div>' +
                    '<div class="kv"><span class="kv__k">Exact upload size</span><span class="kv__v kv__v--big">' + fmtMB(totalBytes) + '</span></div>' +
                '</div>'
            );
            if (fresh.length) {
                var qbtn = el('<button class="btn">Queue ' + fresh.length + ' for full-quality upload</button>');
                qbtn.addEventListener('click', async function () {
                    qbtn.disabled = true;
                    for (var i = 0; i < fresh.length; i++) {
                        qbtn.textContent = 'Queueing ' + (i + 1) + '/' + fresh.length + '...';
                        await qPut({
                            type: 'gallery',
                            code: s.tracking_code,
                            client: s.client_name,
                            filename: fresh[i].name,
                            blob: fresh[i],
                            size: fresh[i].size,
                            status: 'pending',
                            addedAt: Date.now()
                        });
                    }
                    qbtn.textContent = fresh.length + ' queued ✓';
                    refreshBadge();
                    toast(fresh.length + ' photos queued — open Uploads to send them.');
                });
                sum.appendChild(qbtn);
            }
            host.appendChild(sum);
        });

        view.appendChild(screen);
    }

    // ═════════════════════════════════════════════════════════════
    //  UPLOADS — persistent queue
    // ═════════════════════════════════════════════════════════════

    async function renderQueue() {
        view.innerHTML =
            '<div class="screen">' +
                '<header class="hdr"><div class="hdr__kicker">Tweller Studios</div><div class="hdr__title">Uploads</div></header>' +
                '<div class="wrap"><div class="skeleton"></div></div>' +
            '</div>';

        var items = await qAll();
        var pending = items.filter(function (i) { return i.status !== 'done'; });
        var proofBytes = pending.filter(function (i) { return i.type !== 'gallery'; }).reduce(function (a, i) { return a + (i.size || 0); }, 0);
        var galleryBytes = pending.filter(function (i) { return i.type === 'gallery'; }).reduce(function (a, i) { return a + (i.size || 0); }, 0);
        var conn = await connectionType();

        var screen = el('<div class="screen"></div>');
        screen.appendChild(el(
            '<header class="hdr"><div class="hdr__kicker">Tweller Studios</div><div class="hdr__title">Uploads</div></header>'
        ));
        var wrap = el('<div class="wrap"></div>');
        screen.appendChild(wrap);

        if (!items.length) {
            wrap.appendChild(el(
                '<div class="card"><p class="empty">Nothing queued.<br>Open a client and use <strong>Upload for Culling</strong> or <strong>Deliver to Gallery</strong>.</p></div>'
            ));
            view.innerHTML = '';
            view.appendChild(screen);
            return;
        }

        var connLabel = conn === 'wifi' ? 'Wi-Fi' : (conn === 'cellular' ? 'MOBILE DATA' : (conn === 'none' ? 'offline' : 'unknown'));
        wrap.appendChild(el(
            '<div class="q-summary">' +
                '<strong>' + pending.length + '</strong> files waiting · exactly <strong>' + fmtMB(proofBytes + galleryBytes) + '</strong>' +
                (proofBytes && galleryBytes ? '<br>' + fmtMB(proofBytes) + ' proofs · ' + fmtMB(galleryBytes) + ' full-quality gallery' : '') +
                '<br>Connection: <strong>' + connLabel + '</strong>' +
                (state.settings.wifiOnly ? ' · Wi-Fi-only is ON' : '') +
            '</div>'
        ));

        // Group items by session + type
        var groups = {};
        items.forEach(function (i) {
            var key = i.code + '|' + (i.type || 'proof');
            (groups[key] = groups[key] || []).push(i);
        });

        var listCard = el('<div class="card"><div class="progress"><div class="progress__bar" id="q-bar"></div></div></div>');
        Object.keys(groups).forEach(function (key) {
            var arr = groups[key];
            var first = arr[0];
            var typeLabel = (first.type === 'gallery') ? 'Gallery · full quality' : 'Culling proofs';
            listCard.appendChild(el(
                '<div class="card__title" style="margin:10px 0 4px;">' + esc(first.client || first.code) +
                ' — ' + esc(typeLabel) + ' (' + arr.length + ')</div>'
            ));
            arr.slice(0, 150).forEach(function (i) {
                listCard.appendChild(el(
                    '<div class="q-item">' +
                        '<span class="q-item__name">' + esc(i.filename) + '<br><span class="q-item__size">' + fmtMB(i.size || 0) + '</span></span>' +
                        '<span class="q-item__st q-item__st--' + i.status + '">' + i.status.toUpperCase() + '</span>' +
                    '</div>'
                ));
            });
        });
        wrap.appendChild(listCard);

        if (pending.length) {
            var startBtn = el('<button class="btn" id="q-start">Upload ' + pending.length + ' files now</button>');
            startBtn.addEventListener('click', function () { startUpload(pending, conn, proofBytes + galleryBytes, startBtn); });
            wrap.appendChild(startBtn);
        }

        // Post-upload actions per finished batch
        var doneProofs = items.filter(function (i) { return i.status === 'done' && (i.type || 'proof') === 'proof'; });
        var doneGallery = items.filter(function (i) { return i.status === 'done' && i.type === 'gallery'; });

        if (doneProofs.length && !pending.length) {
            var readyBtn = el('<button class="btn btn--dark">✉ Notify ' + esc(doneProofs[0].client || 'client') + ' — proofs ready to choose</button>');
            readyBtn.addEventListener('click', async function () {
                readyBtn.disabled = true;
                readyBtn.textContent = 'Sending...';
                try {
                    await TwellerApi.markCullingReady(doneProofs[0].code);
                    readyBtn.textContent = '✓ Client notified — selection gallery live';
                    for (var i = 0; i < doneProofs.length; i++) await qDelete(doneProofs[i].id);
                    refreshBadge();
                } catch (e) {
                    readyBtn.disabled = false;
                    readyBtn.textContent = 'Retry: notify client';
                    toast('Could not mark ready: ' + e.message, 5000);
                }
            });
            wrap.appendChild(readyBtn);
        }

        if (doneGallery.length && !pending.length) {
            var delivBtn = el('<button class="btn btn--dark">✉ Mark delivered — send ' + esc(doneGallery[0].client || 'client') + ' the gallery</button>');
            delivBtn.addEventListener('click', async function () {
                if (!confirm('Send the delivery email with the gallery link?')) return;
                delivBtn.disabled = true;
                delivBtn.textContent = 'Sending...';
                try {
                    await TwellerApi.advanceStage(doneGallery[0].code, 'delivered', 'Delivered from mobile app');
                    delivBtn.textContent = '✓ Delivered — client emailed';
                    for (var i = 0; i < doneGallery.length; i++) await qDelete(doneGallery[i].id);
                    refreshBadge();
                } catch (e) {
                    delivBtn.disabled = false;
                    delivBtn.textContent = 'Retry: mark delivered';
                    toast('Could not mark delivered: ' + e.message, 5000);
                }
            });
            wrap.appendChild(delivBtn);
        }

        var clearBtn = el('<button class="btn btn--danger">Clear queue</button>');
        clearBtn.addEventListener('click', async function () {
            if (!confirm('Remove everything from the queue? Files already uploaded stay on the website.')) return;
            for (var i = 0; i < items.length; i++) await qDelete(items[i].id);
            refreshBadge();
            renderQueue();
        });
        wrap.appendChild(clearBtn);

        view.innerHTML = '';
        view.appendChild(screen);
    }

    async function startUpload(pending, conn, totalBytes, btn) {
        if (state.uploading) return;

        if (conn === 'none') { toast('No connection — connect to Wi-Fi or mobile data first.'); return; }
        if (conn === 'cellular') {
            var msg = state.settings.wifiOnly
                ? 'Wi-Fi-only is ON and you are on MOBILE DATA.\n\nThis upload will use exactly ' + fmtMB(totalBytes) + ' of data. Upload anyway?'
                : 'You are on MOBILE DATA. This upload will use exactly ' + fmtMB(totalBytes) + '. Continue?';
            if (!confirm(msg)) return;
        }

        state.uploading = true;
        btn.disabled = true;
        var bar = document.getElementById('q-bar');
        var ok = 0, failed = 0;

        for (var i = 0; i < pending.length; i++) {
            var item = pending[i];
            btn.textContent = 'Uploading ' + (i + 1) + ' / ' + pending.length + '...';
            if (bar) bar.style.width = Math.round((i / pending.length) * 100) + '%';
            try {
                if (item.type === 'gallery') {
                    await TwellerApi.uploadGalleryPhoto(item.code, item.blob, item.filename);
                } else {
                    await TwellerApi.uploadProof(item.code, item.blob, item.filename, item.filename);
                }
                item.status = 'done';
                ok++;
            } catch (e) {
                item.status = 'error';
                item.error = e.message;
                failed++;
            }
            await qPut(item);
        }

        if (bar) bar.style.width = '100%';
        state.uploading = false;
        refreshBadge();
        toast(ok + ' uploaded' + (failed ? ', ' + failed + ' failed (tap Upload again to retry)' : '') + '.');
        renderQueue();
    }

    // ═════════════════════════════════════════════════════════════
    //  SETTINGS
    // ═════════════════════════════════════════════════════════════

    function renderSettings() {
        view.innerHTML = '';
        var screen = el(
            '<div class="screen">' +
                '<header class="hdr"><div class="hdr__kicker">Tweller Studios</div><div class="hdr__title">Settings</div></header>' +
            '</div>'
        );
        var wrap = el('<div class="wrap"></div>');
        screen.appendChild(wrap);

        var card = el(
            '<div class="card"><div class="card__title">Connection</div>' +
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
        wrap.appendChild(card);

        wrap.appendChild(el(
            '<div class="card"><div class="card__title">The flow</div><p class="hint" style="margin:0;">' +
            '<strong>Home</strong> shows what needs you. Open a client to see everything about the shoot.<br><br>' +
            '<strong>Upload for Culling</strong> — after the shoot: watermarked proofs the client picks from.<br><br>' +
            '<strong>Deliver to Gallery</strong> — after editing: finished photos at full quality (export from Lightroom Mobile first).<br><br>' +
            'Every upload sits in <strong>Uploads</strong> with an exact MB count before anything moves — Wi-Fi-only by default.</p></div>'
        ));

        view.innerHTML = '';
        view.appendChild(screen);
    }

    // ── Boot ─────────────────────────────────────────────────────

    renderRoot('home');
    refreshBadge();
})();
