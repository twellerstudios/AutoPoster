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
        link: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
        scan: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="3" y1="12" x2="21" y2="12"/></svg>'
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
        ({ home: renderHome, clients: renderClients, prints: renderPrints, queue: renderQueue, settings: renderSettings })[name]();
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
        // Cache-first: paint instantly from the last snapshot, refresh behind
        var cache = TwellerApi.cachedOverview();
        if (cache && cache.data) {
            paintHome(cache.data, true);
        } else {
            view.innerHTML =
                '<div class="screen">' +
                    '<header class="hdr"><div class="hdr__kicker">Tweller Studios</div>' +
                    '<div class="hdr__title">Bookings</div>' +
                    '<div class="hdr__sub">' + new Date().toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' }) + '</div></header>' +
                    '<div class="wrap"><div class="skeleton"></div><div class="skeleton"></div></div>' +
                '</div>';
        }

        try {
            var data = await TwellerApi.fetchOverview();
            state.overview = data;
            if (activeTabName() === 'home' && !navStack.length) paintHome(data, false);
        } catch (e) {
            if (!cache) {
                view.innerHTML = '';
                var screen = el(
                    '<div class="screen"><header class="hdr"><div class="hdr__kicker">Tweller Studios</div>' +
                    '<div class="hdr__title">Bookings</div></header>' +
                    '<div class="wrap"><div class="card"><p class="empty">Couldn\'t reach the studio website.<br>' +
                    'Check <strong>Settings</strong> (site URL + API key) and your connection.</p></div></div></div>'
                );
                view.appendChild(screen);
            }
        }
    }

    function paintHome(data, isStale) {
        state.overview = data;
        var screen = el('<div class="screen"></div>');
        screen.appendChild(el(
            '<header class="hdr"><div class="hdr__kicker">Tweller Studios</div>' +
            '<div class="hdr__title">Bookings</div>' +
            '<div class="hdr__sub">' + new Date().toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' }) +
            (isStale ? ' · refreshing…' : '') + '</div></header>'
        ));
        var wrap = el('<div class="wrap"></div>');
        screen.appendChild(wrap);

        // Quick actions
        var qa = el(
            '<div class="btn-row">' +
                '<button class="btn btn--dark" id="qa-new">＋ New booking</button>' +
                '<button class="btn btn--ghost" id="qa-share">Share booking link</button>' +
            '</div>'
        );
        qa.querySelector('#qa-new').addEventListener('click', function () {
            push(function () { renderNewBooking(); });
        });
        qa.querySelector('#qa-share').addEventListener('click', shareBookingLink);
        wrap.appendChild(qa);

        var counts = data.stage_counts || {};
        function sum(keys) {
            return keys.reduce(function (a, k) { return a + (counts[k] || 0); }, 0);
        }
        var stats = el(
            '<div class="stats">' +
                '<button class="stat stat--accent" data-filter="_active"><div class="stat__num">' + (data.active_count || 0) + '</div><div class="stat__label">Active sessions</div></button>' +
                '<button class="stat" data-filter="culling"><div class="stat__num">' + sum(['culling']) + '</div><div class="stat__label">Clients choosing</div></button>' +
                '<button class="stat" data-filter="_editing"><div class="stat__num">' + sum(['imported', 'culled', 'editing', 'edited', 'exporting', 'exported', 'uploading']) + '</div><div class="stat__label">In editing</div></button>' +
                '<button class="stat" data-filter="delivered"><div class="stat__num">' + (counts.delivered || 0) + '</div><div class="stat__label">Delivered</div></button>' +
            '</div>'
        );
        stats.querySelectorAll('.stat').forEach(function (tile) {
            tile.addEventListener('click', function () {
                state.clientFilter.stage = tile.getAttribute('data-filter');
                state.clientFilter.search = '';
                renderRoot('clients');
            });
        });
        wrap.appendChild(stats);

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

    function shareBookingLink() {
        var url = (state.overview && state.overview.booking_url) || '';
        if (!url) {
            var cache = TwellerApi.cachedOverview();
            url = (cache && cache.data && cache.data.booking_url) || '';
        }
        if (!url) { toast('No booking page found on the website yet.', 4500); return; }

        if (navigator.share) {
            navigator.share({
                title: 'Book a session with Tweller Studios',
                text: 'Book your photo session with Tweller Studios here:',
                url: url
            }).catch(function () {});
        } else if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () {
                toast('Booking link copied — paste it to your client.');
            });
        } else {
            prompt('Booking link (copy it):', url);
        }
    }

    // ═════════════════════════════════════════════════════════════
    //  CLIENTS — searchable session list
    // ═════════════════════════════════════════════════════════════

    var STAGE_FILTERS = [
        { key: '',          label: 'All' },
        { key: '_active',   label: 'Active' },
        { key: 'booked',    label: 'Reserved' },
        { key: 'confirmed', label: 'Confirmed' },
        { key: 'culling',   label: 'Choosing' },
        { key: '_editing',  label: 'Editing' },
        { key: 'uploaded',  label: 'Gallery ready' },
        { key: 'delivered', label: 'Delivered' }
    ];
    var EDITING_KEYS = ['imported', 'culled', 'editing', 'edited', 'exporting', 'exported', 'uploading'];

    async function renderClients() {
        // Cache-first: show the last list instantly, refresh behind
        var cache = TwellerApi.cachedSessions();
        if (cache && (cache.sessions || []).length) {
            state.clients = cache.sessions;
            paintClients(true);
        } else {
            view.innerHTML =
                '<div class="screen">' +
                    '<header class="hdr"><div class="hdr__kicker">Tweller Studios</div><div class="hdr__title">Clients</div></header>' +
                    '<div class="wrap"><div class="skeleton"></div><div class="skeleton"></div></div>' +
                '</div>';
        }

        try {
            state.clients = await TwellerApi.fetchSessions({ range: 'all' });
            var searchFocused = document.activeElement && document.activeElement.type === 'search';
            if (activeTabName() === 'clients' && !navStack.length && !searchFocused) paintClients(false);
        } catch (e) {
            if (!cache) { state.clients = []; paintClients(false); }
        }
    }

    function paintClients(isStale) {
        var f = state.clientFilter;

        var screen = el('<div class="screen"></div>');
        screen.appendChild(el(
            '<header class="hdr"><div class="hdr__kicker">Tweller Studios</div><div class="hdr__title">Clients</div>' +
            '<div class="hdr__sub">' + state.clients.length + ' sessions' + (isStale ? ' · refreshing…' : '') + '</div></header>'
        ));
        var wrap = el('<div class="wrap"></div>');
        screen.appendChild(wrap);

        var newBtn = el('<button class="btn btn--dark">＋ New booking</button>');
        newBtn.addEventListener('click', function () {
            push(function () { renderNewBooking(); });
        });
        wrap.appendChild(newBtn);

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
                } else if (f.stage === '_active') {
                    if (s.current_stage === 'delivered') return false;
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
        var myScreen = currentScreen();

        // Cache-first: show the last snapshot instantly, refresh behind
        var cached = TwellerApi.cachedSessionDetail(code);
        if (cached && cached.data) {
            paintSession(cached.data, code);
        } else {
            view.innerHTML = '';
            view.appendChild(topbar('Session'));
            view.appendChild(el('<div class="wrap"><div class="skeleton" style="height:130px;"></div><div class="skeleton"></div></div>'));
        }

        try {
            var d = await TwellerApi.fetchSessionDetail(code);
            // Only repaint if this screen is still the one on top
            if (currentScreen() === myScreen) paintSession(d, code);
        } catch (e) {
            if (!cached && currentScreen() === myScreen) {
                view.innerHTML = '';
                view.appendChild(topbar('Session'));
                view.appendChild(el('<div class="wrap"><div class="card"><p class="empty">Could not load this session.<br>' + esc(e.message) + '</p></div></div>'));
            }
        }
    }

    function paintSession(d, code) {
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
                    '<div class="action__name">Upload to Gallery</div>' +
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
            var manageBtn = el('<button class="btn btn--sm" style="margin-top:12px;">' + ICONS.gallery + ' Manage gallery — view, cover, delete</button>');
            manageBtn.addEventListener('click', function () {
                push(function () { renderGalleryManage(code, s.client_name); });
            });
            gal.appendChild(manageBtn);
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

        // Danger zone
        var danger = el('<button class="btn btn--danger">Delete this session…</button>');
        danger.addEventListener('click', async function () {
            if (!confirm('Delete ' + s.client_name + '\'s session (' + code + ')?\n\nThis removes the session, its proofs AND its delivery gallery from the website. This cannot be undone.')) return;
            if (!confirm('Really delete EVERYTHING for ' + s.client_name + '? Last chance.')) return;
            danger.disabled = true;
            danger.textContent = 'Deleting…';
            try {
                await TwellerApi.deleteSession(code);
                toast(s.client_name + '\'s session deleted.');
                renderRoot('clients');
            } catch (e) {
                danger.disabled = false;
                danger.textContent = 'Delete this session…';
                toast('Could not delete: ' + e.message, 5000);
            }
        });
        wrap.appendChild(danger);

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
        screen.appendChild(topbar('Upload to Gallery — ' + s.client_name));
        var wrap = el('<div class="wrap"></div>');
        screen.appendChild(wrap);

        var card = el(
            '<div class="card"><div class="card__title">Finished photos</div>' +
                '<p class="hint" style="margin-top:0;">For the final delivery gallery. Files upload <strong>exactly as exported — no compression</strong>, so export from Lightroom Mobile at full quality first. Photos are saved to the queue the moment you pick them — nothing is lost if you leave this screen.</p>' +
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

            var pickBtn = card.querySelector('#btn-pick');
            pickBtn.disabled = true;

            var existing = [];
            try { existing = await TwellerApi.galleryFilenames(s.tracking_code); } catch (e) {}

            var fresh = files.filter(function (f) { return existing.indexOf(f.name) === -1; });
            var skipped = files.length - fresh.length;
            var totalBytes = fresh.reduce(function (a, f) { return a + f.size; }, 0);

            // Queue immediately — the photos survive app restarts and navigation
            for (var i = 0; i < fresh.length; i++) {
                pickBtn.textContent = 'Saving to queue ' + (i + 1) + '/' + fresh.length + '…';
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
            pickBtn.disabled = false;
            pickBtn.innerHTML = ICONS.gallery + ' Pick more photos';
            refreshBadge();

            host.innerHTML = '';
            var sum = el(
                '<div class="card"><div class="card__title">Queued ✓</div>' +
                    '<div class="kv"><span class="kv__k">Photos queued</span><span class="kv__v">' + fresh.length +
                        (skipped ? ' <span style="color:var(--ink-3); font-weight:600;">(' + skipped + ' already delivered)</span>' : '') + '</span></div>' +
                    '<div class="kv"><span class="kv__k">Exact upload size</span><span class="kv__v kv__v--big">' + fmtMB(totalBytes) + '</span></div>' +
                '</div>'
            );
            if (fresh.length) {
                var goBtn = el('<button class="btn">Go to Uploads — send ' + fresh.length + ' now</button>');
                goBtn.addEventListener('click', function () { renderRoot('queue'); });
                sum.appendChild(goBtn);
            } else if (skipped) {
                sum.appendChild(el('<p class="hint" style="margin-bottom:0;">All the photos you picked are already in the gallery.</p>'));
            }
            host.appendChild(sum);
        });

        view.appendChild(screen);
    }

    // ═════════════════════════════════════════════════════════════
    //  GALLERY MANAGER — view, cover, delete (before the client sees it)
    // ═════════════════════════════════════════════════════════════

    function sheet(title, buttons) {
        var veil = el('<div class="sheet-veil"></div>');
        var sh = el('<div class="sheet"><div class="sheet__grip"></div>' +
            (title ? '<div class="sheet__title">' + esc(title) + '</div>' : '') + '</div>');
        function close() { veil.remove(); sh.remove(); }
        veil.addEventListener('click', close);
        buttons.forEach(function (b) {
            var btn = el('<button class="btn ' + (b.cls || 'btn--ghost') + '">' + b.label + '</button>');
            btn.addEventListener('click', function () { close(); if (b.fn) b.fn(); });
            sh.appendChild(btn);
        });
        document.body.appendChild(veil);
        document.body.appendChild(sh);
    }

    async function renderGalleryManage(code, clientName) {
        var myScreen = currentScreen();
        view.innerHTML = '';
        view.appendChild(topbar('Gallery — ' + clientName));
        view.appendChild(el('<div class="wrap"><div class="skeleton" style="height:180px;"></div><div class="skeleton"></div></div>'));

        var g;
        try {
            g = await TwellerApi.fetchGallery(code);
        } catch (e) {
            if (currentScreen() !== myScreen) return;
            view.innerHTML = '';
            view.appendChild(topbar('Gallery — ' + clientName));
            view.appendChild(el('<div class="wrap"><div class="card"><p class="empty">Could not load the gallery.<br>' + esc(e.message) + '</p></div></div>'));
            return;
        }
        if (currentScreen() !== myScreen) return;
        paintGalleryManage(code, clientName, g);
    }

    function paintGalleryManage(code, clientName, g) {
        var photos = g.photos || [];
        var screen = el('<div class="screen"></div>');
        screen.appendChild(topbar('Gallery — ' + clientName));
        var wrap = el('<div class="wrap"></div>');
        screen.appendChild(wrap);

        // ── Cover editor ──
        var coverCard = el('<div class="card"><div class="card__title">Cover photo</div></div>');
        if (g.cover && g.cover.url) {
            coverCard.appendChild(el('<p class="hint" style="margin-top:0;">Tap the photo where the focus should sit — that point stays visible however the cover is cropped.</p>'));
            var ed = el(
                '<div class="cover-editor">' +
                    '<img src="' + esc(g.cover.url) + '" alt="">' +
                    '<div class="cover-editor__dot" style="left:' + (g.cover.pos_x || 50) + '%; top:' + (g.cover.pos_y || 50) + '%;"></div>' +
                '</div>'
            );
            var pos = { x: g.cover.pos_x || 50, y: g.cover.pos_y || 50 };
            var saveBtn = el('<button class="btn btn--sm" style="margin-top:10px; display:none;">Save focus point</button>');
            ed.addEventListener('click', function (e) {
                var r = ed.getBoundingClientRect();
                pos.x = Math.round(((e.clientX - r.left) / r.width) * 1000) / 10;
                pos.y = Math.round(((e.clientY - r.top) / r.height) * 1000) / 10;
                var dot = ed.querySelector('.cover-editor__dot');
                dot.style.left = pos.x + '%';
                dot.style.top = pos.y + '%';
                saveBtn.style.display = '';
            });
            saveBtn.addEventListener('click', async function () {
                saveBtn.disabled = true;
                saveBtn.textContent = 'Saving…';
                try {
                    await TwellerApi.setGalleryCover(code, g.cover.photo_id, pos.x, pos.y);
                    saveBtn.textContent = '✓ Saved';
                    setTimeout(function () { saveBtn.style.display = 'none'; saveBtn.disabled = false; saveBtn.textContent = 'Save focus point'; }, 1200);
                } catch (e) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save focus point';
                    toast('Could not save: ' + e.message, 5000);
                }
            });
            coverCard.appendChild(ed);
            coverCard.appendChild(saveBtn);
        } else {
            coverCard.appendChild(el('<p class="empty" style="padding:10px 0;">No photos yet — the first upload becomes the cover.</p>'));
        }
        wrap.appendChild(coverCard);

        // ── Photo grid ──
        var selMode = false;
        var selected = {};
        var gridCard = el(
            '<div class="card"><div class="card__title"><span>Photos (' + photos.length + ')</span>' +
            '<button class="link" id="sel-toggle">Select</button></div><div class="gal-grid"></div></div>'
        );
        var grid = gridCard.querySelector('.gal-grid');
        var delBar = el('<button class="btn btn--danger" style="display:none;">Delete selected</button>');

        function refreshDelBar() {
            var n = Object.keys(selected).length;
            delBar.style.display = (selMode && n) ? '' : 'none';
            delBar.textContent = 'Delete ' + n + ' selected photo' + (n === 1 ? '' : 's');
        }

        photos.forEach(function (p) {
            var isCover = g.cover && g.cover.photo_id === p.id;
            var t = el(
                '<div class="gal-thumb' + (isCover ? ' gal-thumb--cover' : '') + '">' +
                    '<img src="' + esc(p.thumb_url) + '" loading="lazy" alt="">' +
                    '<span class="gal-thumb__check">✓</span>' +
                '</div>'
            );
            t.addEventListener('click', function () {
                if (selMode) {
                    if (selected[p.id]) { delete selected[p.id]; t.classList.remove('gal-thumb--selected'); }
                    else { selected[p.id] = p; t.classList.add('gal-thumb--selected'); }
                    refreshDelBar();
                    return;
                }
                sheet(p.filename, [
                    { label: 'Set as cover photo', cls: '', fn: async function () {
                        try {
                            await TwellerApi.setGalleryCover(code, p.id);
                            toast('Cover updated.');
                            renderGalleryManage(code, clientName);
                        } catch (e) { toast('Could not set cover: ' + e.message, 5000); }
                    } },
                    { label: 'Delete this photo', cls: 'btn--danger', fn: async function () {
                        if (!confirm('Delete ' + p.filename + ' from the gallery?')) return;
                        try {
                            await TwellerApi.deleteGalleryPhotos(code, [p.id]);
                            toast('Photo deleted.');
                            renderGalleryManage(code, clientName);
                        } catch (e) { toast('Could not delete: ' + e.message, 5000); }
                    } },
                    { label: 'Cancel', cls: 'btn--ghost', fn: null }
                ]);
            });
            grid.appendChild(t);
        });

        gridCard.querySelector('#sel-toggle').addEventListener('click', function () {
            selMode = !selMode;
            selected = {};
            this.textContent = selMode ? 'Done' : 'Select';
            grid.querySelectorAll('.gal-thumb').forEach(function (x) { x.classList.remove('gal-thumb--selected'); });
            grid.classList.toggle('gal-grid--select', selMode);
            refreshDelBar();
        });

        delBar.addEventListener('click', async function () {
            var ids = Object.keys(selected);
            if (!ids.length) return;
            if (!confirm('Delete ' + ids.length + ' photo' + (ids.length === 1 ? '' : 's') + ' from the gallery? This cannot be undone.')) return;
            delBar.disabled = true;
            delBar.textContent = 'Deleting…';
            try {
                await TwellerApi.deleteGalleryPhotos(code, ids);
                toast(ids.length + ' deleted.');
                renderGalleryManage(code, clientName);
            } catch (e) {
                delBar.disabled = false;
                toast('Could not delete: ' + e.message, 5000);
            }
        });

        if (!photos.length) {
            gridCard.appendChild(el('<p class="empty">Nothing in the gallery yet.</p>'));
        }
        wrap.appendChild(gridCard);
        wrap.appendChild(delBar);

        view.innerHTML = '';
        view.appendChild(screen);
    }

    // ═════════════════════════════════════════════════════════════
    //  NEW BOOKING
    // ═════════════════════════════════════════════════════════════

    function renderNewBooking() {
        var ov = state.overview || (TwellerApi.cachedOverview() || {}).data || {};
        var packages = ov.packages || [];

        view.innerHTML = '';
        var screen = el('<div class="screen"></div>');
        screen.appendChild(topbar('New booking'));
        var wrap = el('<div class="wrap"></div>');
        screen.appendChild(wrap);

        var pkgOptions = packages.length
            ? packages.map(function (p) {
                return '<option value="' + esc(p.key) + '" data-price="' + (p.price || 0) + '">' +
                    esc(p.name) + (p.price ? ' — TT$' + p.price : '') + '</option>';
              }).join('')
            : '<option value="mini">Mini</option><option value="family">Family</option><option value="portrait">Portrait</option>';

        var form = el(
            '<div class="card">' +
                '<div class="field"><label>Client name *</label><input type="text" id="nb-name" autocomplete="name"></div>' +
                '<div class="field"><label>Email</label><input type="email" id="nb-email" autocomplete="email"></div>' +
                '<div class="field"><label>Phone</label><input type="tel" id="nb-phone" autocomplete="tel"></div>' +
                '<div class="field"><label>Package</label><select id="nb-pkg">' + pkgOptions + '</select></div>' +
                '<div class="field"><label>Date *</label><input type="date" id="nb-date" value="' + new Date().toISOString().slice(0, 10) + '"></div>' +
                '<div class="field"><label>Time</label><input type="time" id="nb-time"></div>' +
                '<div class="field"><label>Location</label><input type="text" id="nb-loc"></div>' +
                '<div class="field"><label>People</label><input type="number" id="nb-members" min="1" value="1"></div>' +
                '<div class="field"><label>Total (TT$)</label><input type="number" id="nb-total" min="0" step="0.01" value="0"></div>' +
                '<div class="field"><label>Deposit received (TT$)</label><input type="number" id="nb-deposit" min="0" step="0.01" value="0"></div>' +
                '<div class="field"><label>Payment status</label><select id="nb-pay">' +
                    '<option value="pending">Unpaid</option><option value="deposit">Deposit paid</option><option value="paid">Paid in full</option>' +
                '</select></div>' +
                '<div class="field"><label>Notes</label><textarea id="nb-notes" placeholder="Anything worth remembering"></textarea></div>' +
                '<label class="toggle"><input type="checkbox" id="nb-email-toggle" checked><span>Send the client the welcome email (with payment details + calendar hold)</span></label>' +
                '<button class="btn" id="nb-save">Create booking</button>' +
            '</div>'
        );

        // Auto-fill total from the chosen package
        form.querySelector('#nb-pkg').addEventListener('change', function () {
            var opt = this.options[this.selectedIndex];
            var price = parseFloat(opt.getAttribute('data-price') || 0);
            if (price > 0) form.querySelector('#nb-total').value = price;
        });
        var firstOpt = form.querySelector('#nb-pkg').options[0];
        if (firstOpt && parseFloat(firstOpt.getAttribute('data-price') || 0) > 0) {
            form.querySelector('#nb-total').value = parseFloat(firstOpt.getAttribute('data-price'));
        }

        form.querySelector('#nb-save').addEventListener('click', async function () {
            var btn = this;
            var name = form.querySelector('#nb-name').value.trim();
            if (!name) { toast('Client name is required.'); return; }

            btn.disabled = true;
            btn.textContent = 'Creating…';
            try {
                var res = await TwellerApi.createSession({
                    client_name: name,
                    client_email: form.querySelector('#nb-email').value.trim(),
                    client_phone: form.querySelector('#nb-phone').value.trim(),
                    package_type: form.querySelector('#nb-pkg').value,
                    session_date: form.querySelector('#nb-date').value,
                    session_time: form.querySelector('#nb-time').value,
                    location: form.querySelector('#nb-loc').value.trim(),
                    members_count: form.querySelector('#nb-members').value || 1,
                    total_amount: form.querySelector('#nb-total').value || 0,
                    deposit_amount: form.querySelector('#nb-deposit').value || 0,
                    payment_status: form.querySelector('#nb-pay').value,
                    notes: form.querySelector('#nb-notes').value.trim(),
                    send_email: form.querySelector('#nb-email-toggle').checked ? '1' : ''
                });
                toast(res.existing ? 'That client already has a session on that date — opening it.' : 'Booking created for ' + name + '.');
                TwellerApi.fetchSessions({ range: 'all' }).catch(function () {});
                TwellerApi.fetchOverview().catch(function () {});
                openSession(res.tracking_code);
            } catch (e) {
                btn.disabled = false;
                btn.textContent = 'Create booking';
                toast('Could not create: ' + e.message, 5000);
            }
        });

        wrap.appendChild(form);
        view.appendChild(screen);
    }

    // ═════════════════════════════════════════════════════════════
    //  PRINT ORDERS
    // ═════════════════════════════════════════════════════════════

    // The order's life in the order it actually happens.
    var ORDER_LADDER = ['new', 'confirmed', 'printing', 'ready', 'completed'];
    var ORDER_STATUSES = ORDER_LADDER.concat(['cancelled']);
    var ORDER_META = {
        new:       { label: 'Order received',    pill: 'gold',   action: 'Confirm payment' },
        confirmed: { label: 'Payment confirmed', pill: 'blue',   action: 'Start printing' },
        printing:  { label: 'In printing',       pill: 'purple', action: 'Printing complete' },
        ready:     { label: 'Printing complete', pill: 'green',  action: 'Mark delivered' },
        completed: { label: 'Delivered',         pill: 'gray',   action: '' },
        cancelled: { label: 'Cancelled',         pill: 'red',    action: '' }
    };
    function orderMeta(st) { return ORDER_META[st] || { label: st || '—', pill: 'gray', action: '' }; }
    function ladderIndex(st) { return ORDER_LADDER.indexOf(st); }

    function orderPill(status) {
        var m = orderMeta(status);
        return '<span class="pill pill--' + m.pill + '">' + esc(m.label) + '</span>';
    }

    function orderItems(order) {
        var items = order.items;
        if (typeof items === 'string') {
            try { items = JSON.parse(items); } catch (e) { items = []; }
        }
        return Array.isArray(items) ? items : [];
    }

    async function refreshPrintsBadge(data) {
        if (!data) {
            var cache = TwellerApi.cachedPrintOrders();
            data = cache && cache.data;
            // Also refresh from the network quietly
            TwellerApi.fetchPrintOrders().then(function (fresh) {
                paintPrintsBadge(fresh);
            }).catch(function () {});
        }
        paintPrintsBadge(data);
    }

    function paintPrintsBadge(data) {
        var badge = document.getElementById('prints-badge');
        if (!badge || !data) return;
        var n = 0;
        if (data.counts && data.counts.new !== undefined) n = parseInt(data.counts.new, 10) || 0;
        else n = (data.orders || []).filter(function (o) { return o.status === 'new'; }).length;
        badge.style.display = n ? '' : 'none';
        badge.textContent = n;
    }

    async function renderPrints() {
        // Cache-first: show the last orders instantly, refresh behind
        var cache = TwellerApi.cachedPrintOrders();
        if (cache && cache.data) {
            paintPrints(cache.data, true);
        } else {
            view.innerHTML =
                '<div class="screen">' +
                    '<header class="hdr"><div class="hdr__kicker">Tweller Studios</div><div class="hdr__title">Print Store</div></header>' +
                    '<div class="wrap"><div class="skeleton"></div><div class="skeleton"></div></div>' +
                '</div>';
        }

        try {
            var data = await TwellerApi.fetchPrintOrders();
            paintPrintsBadge(data);
            if (activeTabName() === 'prints' && !navStack.length) paintPrints(data, false);
        } catch (e) {
            if (!cache && activeTabName() === 'prints' && !navStack.length) {
                view.innerHTML = '';
                view.appendChild(el(
                    '<div class="screen"><header class="hdr"><div class="hdr__kicker">Tweller Studios</div><div class="hdr__title">Print Store</div></header>' +
                    '<div class="wrap"><div class="card"><p class="empty">Could not load print orders.<br>' + esc(e.message) +
                    '<br><br>Make sure the website plugin is up to date.</p></div></div></div>'
                ));
            }
        }
    }

    function paintPrints(data, isStale) {
        var orders = data.orders || (Array.isArray(data) ? data : []);
        var pendingNew = data.counts && data.counts.new !== undefined
            ? parseInt(data.counts.new, 10) || 0
            : orders.filter(function (o) { return o.status === 'new'; }).length;

        var screen = el('<div class="screen"></div>');
        screen.appendChild(el(
            '<header class="hdr"><div class="hdr__kicker">Tweller Studios</div><div class="hdr__title">Print Store</div>' +
            '<div class="hdr__sub">' + orders.length + ' orders' +
            (pendingNew ? ' · <strong style="color:var(--gold-deep);">' + pendingNew + ' awaiting payment</strong>' : '') +
            (isStale ? ' · refreshing…' : '') + '</div></header>'
        ));
        var wrap = el('<div class="wrap"></div>');
        screen.appendChild(wrap);

        var scanBtn = el('<button class="btn btn--dark">' + ICONS.scan + ' Scan delivery</button>');
        scanBtn.addEventListener('click', function () {
            push(function () { renderScanner(); });
        });
        wrap.appendChild(scanBtn);

        var filter = '';
        var chips = el('<div class="chips"></div>');
        [''].concat(ORDER_STATUSES).forEach(function (st) {
            var c = el('<button class="chip' + (st === '' ? ' chip--on' : '') + '">' + esc(st ? orderMeta(st).label : 'All') + '</button>');
            c.addEventListener('click', function () {
                filter = st;
                chips.querySelectorAll('.chip').forEach(function (x) { x.classList.remove('chip--on'); });
                c.classList.add('chip--on');
                paintList();
            });
            chips.appendChild(c);
        });
        wrap.appendChild(chips);

        var listCard = el('<div class="card"></div>');
        wrap.appendChild(listCard);

        function paintList() {
            var rows = orders.filter(function (o) { return !filter || o.status === filter; });
            listCard.innerHTML = '';
            if (!rows.length) {
                listCard.appendChild(el('<p class="empty">No print orders here yet.<br>Orders from client galleries and the public print page land in this tab.</p>'));
                return;
            }
            rows.forEach(function (o) {
                var items = orderItems(o);
                var hasReceipt = !!o.receipt_url;
                var row = el(
                    '<div class="row">' +
                        '<span class="avatar avatar--sm">' + esc(initials(o.customer_name)) + '</span>' +
                        '<span class="row__body"><div class="row__name">' + esc(o.customer_name || o.order_ref) +
                        (hasReceipt && o.status === 'new' ? ' <span class="pill pill--red" style="font-size:10px;">Receipt in — verify</span>' : '') + '</div>' +
                        '<div class="row__meta">' + esc(o.order_ref) + ' · ' + items.length + ' item' + (items.length === 1 ? '' : 's') +
                        ' · ' + esc(money(o.subtotal)) + '</div></span>' +
                        '<span class="row__end">' + orderPill(o.status) + '</span>' +
                    '</div>'
                );
                row.addEventListener('click', function () {
                    push(function () { renderPrintOrderDetail(o); });
                });
                listCard.appendChild(row);
            });
        }
        paintList();

        view.innerHTML = '';
        view.appendChild(screen);
    }

    function renderPrintOrderDetail(o) {
        var items = orderItems(o);
        view.innerHTML = '';
        var screen = el('<div class="screen"></div>');
        screen.appendChild(topbar(o.order_ref));
        var wrap = el('<div class="wrap"></div>');
        screen.appendChild(wrap);

        // Customer
        var cust = el(
            '<div class="card"><div class="card__title">Customer</div>' +
                '<div class="kv"><span class="kv__k">Name</span><span class="kv__v">' + esc(o.customer_name || '—') + '</span></div>' +
                (o.customer_phone ? '<div class="kv"><span class="kv__k">Phone</span><span class="kv__v"><a href="tel:' + esc(o.customer_phone) + '">' + esc(o.customer_phone) + '</a></span></div>' : '') +
                (o.customer_email ? '<div class="kv"><span class="kv__k">Email</span><span class="kv__v"><a href="mailto:' + esc(o.customer_email) + '">' + esc(o.customer_email) + '</a></span></div>' : '') +
                '<div class="kv"><span class="kv__k">Source</span><span class="kv__v">' + esc(o.source === 'public' ? 'Public print page' : 'Client gallery' + (o.session_code ? ' · ' + o.session_code : '')) + '</span></div>' +
                '<div class="kv"><span class="kv__k">Placed</span><span class="kv__v">' + esc(fmtStamp(o.created_at)) + '</span></div>' +
            '</div>'
        );
        wrap.appendChild(cust);

        // Items
        var itemsCard = el('<div class="card"><div class="card__title">Items</div><div class="order-items"></div></div>');
        var host = itemsCard.querySelector('.order-items');
        items.forEach(function (it) {
            host.appendChild(el(
                '<div class="order-item">' +
                    (it.thumb_url || it.photo_url ? '<img src="' + esc(it.thumb_url || it.photo_url) + '" loading="lazy" alt="">' : '') +
                    '<span class="order-item__body">' +
                        '<div class="order-item__name">' + esc(it.product_name || '') + '</div>' +
                        '<div class="order-item__meta">' + esc(it.filename || '') + ' · ×' + (it.qty || 1) + '</div>' +
                    '</span>' +
                    '<span class="order-item__price">' + esc(money((it.price || 0) * (it.qty || 1))) + '</span>' +
                '</div>'
            ));
        });
        itemsCard.appendChild(el('<div class="kv" style="margin-top:8px;"><span class="kv__k">Total</span><span class="kv__v kv__v--big">' + esc(money(o.subtotal)) + '</span></div>'));

        // Copy the print list (for sending to the lab)
        var copyBtn = el('<button class="btn btn--ghost btn--sm" style="margin-top:10px;">Copy print list</button>');
        copyBtn.addEventListener('click', function () {
            var text = 'Print order ' + o.order_ref + ' — ' + (o.customer_name || '') + '\n' +
                items.map(function (it) {
                    return (it.qty || 1) + '× ' + (it.product_name || '') + ' — ' + (it.filename || '');
                }).join('\n');
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () { toast('Print list copied.'); });
            } else {
                prompt('Copy the print list:', text);
            }
        });
        itemsCard.appendChild(copyBtn);
        wrap.appendChild(itemsCard);

        // Payment
        var payCard = el('<div class="card"><div class="card__title">Payment</div></div>');
        var txnId = o.transaction_id || (o.payment && o.payment.transaction_id) || '';
        if (txnId) {
            payCard.appendChild(el(
                '<div class="kv"><span class="kv__k">Paid by card (WiPay)</span><span class="kv__v" style="font-size:12px;">' + esc(txnId) + '</span></div>'
            ));
        }
        if (o.receipt_url) {
            payCard.appendChild(el(
                '<div class="kv"><span class="kv__k">Bank transfer receipt</span><span class="kv__v"><a href="' + esc(o.receipt_url) + '" target="_blank">View ↗</a></span></div>'
            ));
            if (o.receipt_confirmed_amount || o.receipt_ocr) {
                var claimed = parseFloat(o.receipt_confirmed_amount || 0) || 0;
                var mismatch = claimed && Math.abs(claimed - parseFloat(o.subtotal || 0)) > 0.5;
                payCard.appendChild(el(
                    '<div class="kv"><span class="kv__k">Amount on receipt</span><span class="kv__v"' +
                    (mismatch ? ' style="color:var(--red);"' : '') + '>' +
                    esc(claimed ? money(claimed) : o.receipt_ocr || '') + (mismatch ? ' ≠ ' + esc(money(o.subtotal)) : '') + '</span></div>'
                ));
            }
        }
        if (!txnId && !o.receipt_url) {
            payCard.appendChild(el('<p class="hint" style="margin:0;">Nothing received yet — the customer can pay by card or upload a transfer receipt from their order page.</p>'));
        }
        if (o.status === 'new') {
            var confirmBtn = el('<button class="btn" style="margin-top:12px;">✓ Confirm payment</button>');
            confirmBtn.addEventListener('click', async function () {
                if (!confirm('Confirm you received ' + money(o.subtotal) + ' for ' + o.order_ref + '? The customer is emailed that printing starts.')) return;
                confirmBtn.disabled = true;
                try {
                    await TwellerApi.setPrintOrderStatus(o.id, 'confirmed', true);
                    o.status = 'confirmed';
                    toast('Payment confirmed.');
                    refreshPrintsBadge();
                    renderPrintOrderDetail(o);
                } catch (e) {
                    confirmBtn.disabled = false;
                    toast('Could not update: ' + e.message, 5000);
                }
            });
            payCard.appendChild(confirmBtn);
        }
        if (o.portal_url) {
            var portalBtn = el('<button class="btn btn--ghost btn--sm" style="margin-top:8px;">Open customer order page ' + ICONS.link + '</button>');
            portalBtn.addEventListener('click', function () { openExternal(o.portal_url); });
            payCard.appendChild(portalBtn);
        }
        wrap.appendChild(payCard);

        // Progress — the order's life, in order
        wrap.appendChild(buildOrderStepper(o));

        view.innerHTML = '';
        view.appendChild(screen);
    }

    /**
     * The order ladder as a stepper: past steps ticked, the current step
     * highlighted, and one primary button that advances exactly one step.
     */
    function buildOrderStepper(o) {
        var card = el('<div class="card"><div class="card__title">Progress</div></div>');

        if (o.status === 'cancelled') {
            card.appendChild(el('<p class="empty" style="padding:10px 0;">This order was cancelled.</p>'));
            return card;
        }

        var current = ladderIndex(o.status);
        var tl = el('<div class="tl"></div>');
        ORDER_LADDER.forEach(function (st, idx) {
            var cls = idx < current ? 'tl__item--done' : (idx === current ? 'tl__item--current' : 'tl__item--future');
            tl.appendChild(el(
                '<div class="tl__item ' + cls + '">' +
                    '<div class="tl__rail"><div class="tl__dot"></div><div class="tl__line"></div></div>' +
                    '<div class="tl__body"><div class="tl__stage">' + esc(orderMeta(st).label) + '</div></div>' +
                '</div>'
            ));
        });
        card.appendChild(tl);

        var notifyToggle = el('<label class="toggle"><input type="checkbox" checked><span>Email the customer about this update</span></label>');
        card.appendChild(notifyToggle);

        function advanceTo(target, label) {
            return async function () {
                var btn = this;
                btn.disabled = true;
                btn.textContent = 'Updating…';
                try {
                    await TwellerApi.setPrintOrderStatus(o.id, target, notifyToggle.querySelector('input').checked);
                    o.status = target;
                    toast('Order updated: ' + label + '.');
                    refreshPrintsBadge();
                    renderPrintOrderDetail(o);
                } catch (e) {
                    btn.disabled = false;
                    btn.textContent = label;
                    toast('Could not update: ' + e.message, 5000);
                }
            };
        }

        // One forward step at a time — no jumping the queue.
        var next = ORDER_LADDER[current + 1];
        if (current >= 0 && next) {
            var actionLabel = orderMeta(o.status).action || ('Mark ' + orderMeta(next).label.toLowerCase());
            var nextBtn = el('<button class="btn">' + esc(actionLabel) + '</button>');
            nextBtn.addEventListener('click', advanceTo(next, orderMeta(next).label));
            card.appendChild(nextBtn);
        }

        // Step back one, for mistakes
        var prev = current > 0 ? ORDER_LADDER[current - 1] : null;
        if (prev) {
            var backBtn = el('<button class="btn btn--ghost btn--sm" style="margin-right:8px;">← Back to ' + esc(orderMeta(prev).label.toLowerCase()) + '</button>');
            backBtn.addEventListener('click', advanceTo(prev, orderMeta(prev).label));
            card.appendChild(backBtn);
        }

        if (o.status !== 'completed') {
            var cancelBtn = el('<button class="btn btn--danger btn--sm">Cancel order</button>');
            cancelBtn.addEventListener('click', function () {
                if (!confirm('Cancel ' + o.order_ref + '?')) return;
                advanceTo('cancelled', 'Cancelled').call(this);
            });
            card.appendChild(cancelBtn);
        }

        return card;
    }

    // ═════════════════════════════════════════════════════════════
    //  DELIVERY SCANNER — scan a shipping label to close an order
    // ═════════════════════════════════════════════════════════════

    function renderScanner() {
        view.innerHTML = '';
        var screen = el('<div class="screen"></div>');
        screen.appendChild(topbar('Scan delivery'));
        var wrap = el('<div class="wrap"></div>');
        screen.appendChild(wrap);

        var card = el(
            '<div class="card"><div class="card__title">Point at the shipping label</div>' +
                '<p class="hint" style="margin-top:0;">Scanning the barcode marks the order delivered and emails the customer.</p>' +
                '<div class="scanbox"><video id="scan-video" playsinline muted></video><div class="scanbox__frame"></div></div>' +
                '<div id="scan-state" class="hint" style="text-align:center; margin:10px 0 0;">Starting camera…</div>' +
            '</div>'
        );
        wrap.appendChild(card);

        var manual = el(
            '<div class="card"><div class="card__title">Or type the code</div>' +
                '<p class="hint" style="margin-top:0;">The code printed under the barcode on the label.</p>' +
                '<div class="field" style="margin-bottom:8px;"><input type="text" id="scan-manual" placeholder="TS-PRINT-XXXXXX|…" autocapitalize="characters"></div>' +
                '<button class="btn btn--ghost" id="scan-submit">Mark delivered</button>' +
            '</div>'
        );
        wrap.appendChild(manual);

        var stateEl = card.querySelector('#scan-state');
        var video = card.querySelector('#scan-video');
        var stream = null;
        var stopped = false;
        var busy = false;

        function stop() {
            stopped = true;
            if (stream) {
                stream.getTracks().forEach(function (t) { t.stop(); });
                stream = null;
            }
        }

        // Leaving the screen must always release the camera.
        window.addEventListener('popstate', stop, { once: true });
        document.querySelectorAll('.tab').forEach(function (b) {
            b.addEventListener('click', stop, { once: true });
        });

        async function submitCode(code) {
            if (busy) return;
            code = String(code || '').trim();
            if (!code) return;
            busy = true;
            stateEl.textContent = 'Checking ' + code.split('|')[0] + '…';
            try {
                var res = await TwellerApi.scanPrintDelivery(code);
                stop();
                var ref = res.order_ref || code.split('|')[0];
                if (res.already_delivered) {
                    toast(ref + ' was already marked delivered.', 4500);
                } else {
                    toast('✓ ' + ref + ' delivered — customer emailed.', 4500);
                }
                refreshPrintsBadge();
                stateEl.innerHTML = '<strong style="color:var(--green);">✓ ' + esc(ref) + ' — delivered</strong>';
                busy = false;
            } catch (e) {
                busy = false;
                stateEl.innerHTML = '<span style="color:var(--red);">' + esc(e.message) + '</span>';
                toast('Could not mark delivered: ' + e.message, 5000);
            }
        }

        manual.querySelector('#scan-submit').addEventListener('click', function () {
            submitCode(manual.querySelector('#scan-manual').value);
        });

        // Camera + barcode detection (Chrome/Android WebView ships BarcodeDetector)
        (async function startCamera() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                stateEl.textContent = 'No camera available — type the code below.';
                return;
            }
            var detector = null;
            if (window.BarcodeDetector) {
                try {
                    var formats = await window.BarcodeDetector.getSupportedFormats();
                    var want = ['code_128', 'qr_code'].filter(function (f) { return formats.indexOf(f) !== -1; });
                    if (want.length) detector = new window.BarcodeDetector({ formats: want });
                } catch (e) {}
            }
            if (!detector) {
                stateEl.textContent = 'This device can’t scan barcodes — type the code below.';
                return;
            }

            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment' }, audio: false
                });
            } catch (e) {
                stateEl.textContent = 'Camera blocked — allow camera access, or type the code below.';
                return;
            }
            if (stopped) { stop(); return; }

            video.srcObject = stream;
            await video.play().catch(function () {});
            stateEl.textContent = 'Looking for a barcode…';

            (function tick() {
                if (stopped || busy) {
                    if (!stopped) setTimeout(tick, 400);
                    return;
                }
                detector.detect(video).then(function (codes) {
                    if (codes && codes.length && codes[0].rawValue) {
                        submitCode(codes[0].rawValue);
                    }
                }).catch(function () {}).then(function () {
                    if (!stopped) setTimeout(tick, 350);
                });
            })();
        })();

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
            var viewBtn = el('<button class="btn btn--ghost">' + ICONS.gallery + ' View gallery before sending</button>');
            viewBtn.addEventListener('click', function () {
                push(function () { renderGalleryManage(doneGallery[0].code, doneGallery[0].client || 'Gallery'); });
            });
            wrap.appendChild(viewBtn);

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
    refreshPrintsBadge();
})();
