/*!
 * Tweller Event Photo Engine — front-end app (vanilla ES6, no build step).
 * Routes on tepeConfig.view: 'gallery' | 'upload' | 'create'.
 */
(function () {
    'use strict';

    var CFG = window.tepeConfig || {};
    if (!CFG.view) return;

    var CHUNK_SIZE = 1024 * 1024 * 1.5;   // 1.5 MB chunks
    var CHUNK_THRESHOLD = 1024 * 1024 * 4; // files above this stream in chunks

    // Crisp, symmetric check icon reused for selection + done states.
    var SVG_CHECK = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
    // Heart used on tiles, top-liked and the lightbox. fill:currentColor so the
    // "liked" state can flip it to gold/red purely via CSS.
    var HEART_SVG = '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M12 21C6.7 16.3 3 13 3 8.9 3 6.1 5.1 4 7.8 4c1.6 0 3.1.8 4.2 2 1.1-1.2 2.6-2 4.2-2C18.9 4 21 6.1 21 8.9c0 4.1-3.7 7.4-9 12.1z"/></svg>';

    // ── tiny helpers ────────────────────────────────────────────────────────
    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
    var el = function (tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; };
    var esc = function (s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; };
    var money = function (n) { return CFG.currency + ' ' + (Math.round(n * 100) / 100).toFixed(2); };

    var IDENT = { fingerprint: '', uid: '' };
    function ready() {
        return (window.tepeFingerprint ? window.tepeFingerprint() : Promise.resolve({ fingerprint: '', uid: '' }))
            .then(function (id) { IDENT = id; return id; });
    }

    function api(path, opts) {
        opts = opts || {};
        var headers = opts.headers || {};
        headers['X-WP-Nonce'] = CFG.nonce;
        if (!(opts.body instanceof FormData) && opts.body) {
            headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(opts.body);
        }
        return fetch(CFG.restBase + path, {
            method: opts.method || 'GET',
            headers: headers,
            body: opts.body,
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json().then(function (j) {
                if (!r.ok) throw new Error((j && j.message) || 'Something went wrong.');
                return j;
            });
        });
    }

    /** Put a button into a busy state with an inline spinner. */
    function btnBusy(btn, text) {
        if (!btn) return;
        if (btn._idleHtml == null) btn._idleHtml = btn.innerHTML;
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        btn.innerHTML = '<span class="tepe-spin" aria-hidden="true"></span>' + esc(text || 'Working…');
    }
    /** Return a button to an interactive state. */
    function btnIdle(btn, text) {
        if (!btn) return;
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        if (text != null) btn.innerHTML = esc(text);
        else if (btn._idleHtml != null) btn.innerHTML = btn._idleHtml;
    }

    function toast(msg) {
        var t = $('#tepe-toast'); if (!t) { alert(msg); return; }
        t.textContent = msg; t.hidden = false;
        clearTimeout(t._t); t._t = setTimeout(function () { t.hidden = true; }, 3200);
    }

    function identFields(fd) {
        fd.append('fingerprint', IDENT.fingerprint);
        fd.append('guest_uid', IDENT.uid);
        return fd;
    }

    // ── XHR upload with progress ────────────────────────────────────────────
    function xhrPost(path, formData, onProgress) {
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', CFG.restBase + path, true);
            xhr.setRequestHeader('X-WP-Nonce', CFG.nonce);
            xhr.withCredentials = true;
            if (onProgress && xhr.upload) {
                xhr.upload.onprogress = function (e) { if (e.lengthComputable) onProgress(e.loaded / e.total); };
            }
            xhr.onload = function () {
                var j; try { j = JSON.parse(xhr.responseText); } catch (e) { j = {}; }
                if (xhr.status >= 200 && xhr.status < 300) resolve(j);
                else reject(new Error(j.message || 'Upload failed.'));
            };
            xhr.onerror = function () { reject(new Error('Network error during upload.')); };
            xhr.send(formData);
        });
    }

    /* =======================================================================
       GALLERY VIEW
    ======================================================================= */
    function initGallery() {
        var state = { category: null, mode: 'recent', photos: [], notes: [], selectMode: false, selected: {}, lightIdx: 0, reward: loadReward() };

        var masonry = $('#tepe-masonry');
        var groupsEl = $('#tepe-groups');
        var loading = $('#tepe-loading');
        var empty = $('#tepe-empty');

        renderCats();
        load();

        // Share — branded per-platform buttons in the share bar.
        $$('[data-share]').forEach(function (btn) {
            btn.addEventListener('click', function () { shareTo(btn.getAttribute('data-share')); });
        });
        if (state.reward) showReward(state.reward, true);

        // View toggle (Recent / By person)
        $$('#tepe-viewtoggle .tepe-viewtoggle__btn').forEach(function (b) {
            b.addEventListener('click', function () {
                state.mode = b.getAttribute('data-mode');
                $$('#tepe-viewtoggle .tepe-viewtoggle__btn').forEach(function (x) { x.classList.toggle('tepe-viewtoggle__btn--on', x === b); });
                render();
            });
        });

        // Note modal wiring
        wireNoteModal();

        function load() {
            loading.hidden = false; empty.hidden = true;
            var q = 'event/' + CFG.slug + '/photos?fingerprint=' + encodeURIComponent(IDENT.fingerprint) + '&guest_uid=' + encodeURIComponent(IDENT.uid);
            // null = All (no filter); '' = Public Album (uncategorised) is a real filter.
            if (state.category !== null && state.category !== undefined) q += '&category=' + encodeURIComponent(state.category);
            q += '&_=' + Date.now(); // never serve a cached list right after an upload
            api(q).then(function (r) {
                loading.hidden = true;
                state.photos = r.photos || [];
                state.notes = r.notes || [];
                if (r.categories) { CFG.categories = r.categories; renderCats(); }
                var cnt = $('#tepe-count'); if (cnt) cnt.textContent = r.count;
                var vw = $('#tepe-views'); if (vw && typeof r.views !== 'undefined') vw.textContent = r.views;
                render();
                renderTop();
                renderGuestbook();
            }).catch(function (e) { loading.textContent = e.message; });
        }

        function renderCats() {
            var nav = $('#tepe-cats'); if (!nav) return;
            nav.innerHTML = '';
            // "All" (null) first, then Public Album ('') and every bucket.
            var chips = [{ slug: null, label: 'All photos' }].concat(CFG.categories || []);
            chips.forEach(function (c) {
                var active = (state.category === c.slug);
                var chip = el('button', 'tepe-chip' + (active ? ' tepe-chip--active' : ''), esc(c.label));
                chip.type = 'button';
                chip.addEventListener('click', function () { state.category = c.slug; renderCats(); load(); });
                nav.appendChild(chip);
            });
        }

        function render() {
            masonry.innerHTML = ''; groupsEl.innerHTML = '';
            if (!state.photos.length) { empty.hidden = false; masonry.hidden = true; groupsEl.hidden = true; return; }
            empty.hidden = true;

            if (state.mode === 'people') {
                masonry.hidden = true; groupsEl.hidden = false;
                renderPeople();
            } else {
                groupsEl.hidden = true; masonry.hidden = false;
                var promoAt = CFG.promoEveryN || 11, pi = 0;
                state.photos.forEach(function (p, i) {
                    masonry.appendChild(tile(p, i));
                    if (CFG.freeTier && CFG.promoCards && CFG.promoCards.length && (i + 1) % promoAt === 0) {
                        masonry.appendChild(promoCard(CFG.promoCards[pi % CFG.promoCards.length])); pi++;
                    }
                });
            }
            document.body.classList.toggle('tepe-selectmode', state.selectMode);
            renderSelBar();
        }

        // Group photos by the name of who uploaded them.
        function renderPeople() {
            var groups = {}, order = [];
            state.photos.forEach(function (p) {
                var key = (p.uploader && p.uploader.trim()) ? p.uploader.trim() : ' '; // unnamed sinks last
                if (!groups[key]) { groups[key] = []; order.push(key); }
                groups[key].push(p);
            });
            order.sort(function (a, b) {
                if (a === ' ') return 1; if (b === ' ') return -1;
                return a.localeCompare(b);
            });
            order.forEach(function (key) {
                var named = key !== ' ';
                var wrap = el('div', 'tepe-group');
                var head = el('div', 'tepe-group__head');
                head.innerHTML = '<span class="tepe-group__avatar">' + esc(named ? key.charAt(0).toUpperCase() : '★') + '</span>' +
                    '<span class="tepe-group__name">' + esc(named ? key : 'Other guests') + '</span>' +
                    '<span class="tepe-group__count">' + groups[key].length + '</span>';
                wrap.appendChild(head);
                var grid = el('div', 'tepe-group__grid');
                groups[key].forEach(function (p) { grid.appendChild(tile(p, state.photos.indexOf(p))); });
                wrap.appendChild(grid);
                groupsEl.appendChild(wrap);
            });
        }

        function tile(p, i) {
            var t = el('div', 'tepe-tile' + (state.selected[p.id] ? ' tepe-tile--sel' : ''));
            t.dataset.id = p.id;
            var isHeic = /heic|heif/i.test(p.thumb_url) || (p.width === 0 && /heic/i.test(p.url));
            if (isHeic && !p.thumb_ok) {
                var h = el('div', 'tepe-tile__inner tepe-tile--heic', '📷<br>HEIC photo<br><small>tap to view</small>');
                t.appendChild(h);
            } else {
                var img = el('img');
                img.loading = 'lazy';
                img.src = p.thumb_url;
                img.alt = p.caption || 'Event photo';
                img.onerror = function () { img.onerror = null; img.src = p.url; };
                t.appendChild(img);
            }
            // Uploader name badge (bottom-left)
            if (p.uploader && p.uploader.trim()) {
                t.appendChild(el('span', 'tepe-tile__by', esc(p.uploader.trim())));
            }
            if (p.note) t.appendChild(el('span', 'tepe-tile__noteflag', '✎'));
            var chk = el('span', 'tepe-tile__check', SVG_CHECK); t.appendChild(chk);

            // Heart (top-right)
            var heart = el('button', 'tepe-heart' + (p.liked ? ' tepe-heart--on' : ''));
            heart.type = 'button';
            heart.innerHTML = HEART_SVG + '<span>' + (p.likes || 0) + '</span>';
            heart.addEventListener('click', function (ev) { ev.stopPropagation(); like(p); });
            t.appendChild(heart);

            if (CFG.allowPrints) {
                var pb = el('button', 'tepe-tile__print', 'Order print'); pb.type = 'button';
                pb.addEventListener('click', function (ev) { ev.stopPropagation(); openDrawer([p]); });
                t.appendChild(pb);
            }

            // Tap = open; double-tap = like; long-press/right-click = select.
            var lastTap = 0;
            t.addEventListener('click', function () {
                if (state.selectMode) { toggleSel(p); return; }
                var now = Date.now();
                if (now - lastTap < 300) { lastTap = 0; like(p, true); return; }
                lastTap = now;
                setTimeout(function () {
                    if (lastTap && Date.now() - lastTap >= 280) { state.lightIdx = state.photos.indexOf(p); openLightbox(); lastTap = 0; }
                }, 300);
            });
            t.addEventListener('contextmenu', function (ev) { ev.preventDefault(); state.selectMode = true; toggleSel(p); render(); });
            return t;
        }

        // ── Likes ──
        function like(p, forceOn) {
            // Optimistic toggle (double-tap only ever turns it ON).
            var turningOn = forceOn ? true : !p.liked;
            if (forceOn && p.liked) { heartBurst(p); return; }
            p.liked = turningOn;
            p.likes = Math.max(0, (p.likes || 0) + (turningOn ? 1 : -1));
            syncHearts(p); if (turningOn) heartBurst(p);
            var fd = new FormData(); identFields(fd);
            xhrPost('event/' + CFG.slug + '/like/' + p.id, fd).then(function (r) {
                p.likes = r.likes; p.liked = r.liked; syncHearts(p); renderTop();
            }).catch(function () { /* keep optimistic state */ });
        }
        function syncHearts(p) {
            $$('.tepe-tile[data-id="' + p.id + '"] .tepe-heart').forEach(function (h) {
                h.classList.toggle('tepe-heart--on', !!p.liked);
                var s = h.querySelector('span'); if (s) s.textContent = p.likes;
            });
            if (!lb.hidden && state.photos[state.lightIdx] && state.photos[state.lightIdx].id === p.id) {
                $('#tepe-lightbox-like').classList.toggle('tepe-heartbtn--on', !!p.liked);
                $('#tepe-lightbox-likes').textContent = p.likes;
            }
        }
        function heartBurst(p) {
            var host = $$('.tepe-tile[data-id="' + p.id + '"]')[0]; if (!host) return;
            var b = el('span', 'tepe-heartburst', HEART_SVG); host.appendChild(b);
            setTimeout(function () { b.remove(); }, 700);
        }

        // ── Top liked ──
        function renderTop() {
            var sec = $('#tepe-top'), strip = $('#tepe-top-strip'); if (!sec) return;
            var top = state.photos.filter(function (p) { return (p.likes || 0) > 0; })
                .sort(function (a, b) { return b.likes - a.likes; }).slice(0, 10);
            if (!top.length) { sec.hidden = true; return; }
            sec.hidden = false; strip.innerHTML = '';
            top.forEach(function (p) {
                var c = el('button', 'tepe-topcard'); c.type = 'button';
                c.innerHTML = '<img loading="lazy" src="' + esc(p.thumb_url) + '" alt=""><span class="tepe-topcard__likes">' + HEART_SVG + (p.likes) + '</span>';
                c.addEventListener('click', function () { state.lightIdx = state.photos.indexOf(p); openLightbox(); });
                strip.appendChild(c);
            });
        }

        // ── Guestbook ──
        function renderGuestbook() {
            var sec = $('#tepe-guestbook'), feed = $('#tepe-guestbook-feed'); if (!sec) return;
            if (!state.notes.length) { sec.hidden = true; return; }
            sec.hidden = false; feed.innerHTML = '';
            state.notes.forEach(function (n) {
                var card = el('figure', 'tepe-note');
                card.innerHTML =
                    '<img class="tepe-note__photo" loading="lazy" src="' + esc(n.thumb_url) + '" alt="">' +
                    '<figcaption class="tepe-note__body">' +
                        '<p class="tepe-note__msg">' + esc(n.message) + '</p>' +
                        '<p class="tepe-note__by">— ' + esc(n.author || 'A guest') + '</p>' +
                    '</figcaption>';
                card.addEventListener('click', function () {
                    var idx = state.photos.map(function (p) { return p.id; }).indexOf(n.upload_id);
                    if (idx > -1) { state.lightIdx = idx; openLightbox(); }
                });
                feed.appendChild(card);
            });
        }

        function promoCard(c) {
            var card = el('a', 'tepe-promo');
            card.href = c.url; card.target = '_blank'; card.rel = 'noopener';
            card.innerHTML = '<div class="tepe-promo__eyebrow">Tweller Studios</div>' +
                '<div class="tepe-promo__title">' + esc(c.title) + '</div>' +
                (c.blurb ? '<div class="tepe-promo__blurb">' + esc(c.blurb) + '</div>' : '') +
                (c.price ? '<div class="tepe-promo__price">' + esc(c.price) + '</div>' : '') +
                '<span class="tepe-btn tepe-btn--gold">' + esc(c.cta || 'Book') + '</span>';
            return card;
        }

        function toggleSel(p) {
            if (state.selected[p.id]) delete state.selected[p.id]; else state.selected[p.id] = p;
            var t = masonry.querySelector('.tepe-tile[data-id="' + p.id + '"]');
            if (t) t.classList.toggle('tepe-tile--sel', !!state.selected[p.id]);
            renderSelBar();
        }

        function selectedList() { return Object.keys(state.selected).map(function (k) { return state.selected[k]; }); }

        function renderSelBar() {
            var bar = $('#tepe-selbar');
            var list = selectedList();
            if (!state.selectMode || !list.length) { if (bar) bar.remove(); return; }
            if (!bar) {
                bar = el('div', 'tepe-selbar'); bar.id = 'tepe-selbar';
                var cnt = el('span', 'tepe-selbar__count'); cnt.id = 'tepe-selbar-count';
                var order = el('button', 'tepe-btn tepe-btn--gold', 'Order prints'); order.type = 'button';
                order.addEventListener('click', function () { openDrawer(selectedList()); });
                var cancel = el('button', 'tepe-btn tepe-btn--ghost', 'Cancel'); cancel.type = 'button';
                cancel.style.color = '#fff';
                cancel.addEventListener('click', function () { state.selectMode = false; state.selected = {}; render(); });
                bar.appendChild(cnt); bar.appendChild(order); bar.appendChild(cancel);
                document.body.appendChild(bar);
            }
            $('#tepe-selbar-count').textContent = list.length + ' selected';
        }

        // ── Lightbox ──
        var lb = $('#tepe-lightbox');
        var fadeTimer = null, slideTimer = null;
        if (lb) {
            // Tap the image → toggle the chrome; tap the bare backdrop → close.
            lb.addEventListener('click', function (e) {
                if (e.target.closest('.tepe-lightbox__chrome, .tepe-lightbox__bar, .tepe-lightbox__caption')) return;
                if (e.target.id === 'tepe-lightbox-img') { toggleChrome(); return; }
                closeLightbox();
            });
            lb.querySelector('[data-prev]').addEventListener('click', function () { navLight(-1); armFade(); });
            lb.querySelector('[data-next]').addEventListener('click', function () { navLight(1); armFade(); });
            lb.querySelector('[data-close]').addEventListener('click', closeLightbox);
            $('#tepe-lightbox-print').addEventListener('click', function () { closeLightbox(); openDrawer([state.photos[state.lightIdx]]); });
            $('#tepe-lightbox-like').addEventListener('click', function () { like(state.photos[state.lightIdx]); armFade(); });
            $('#tepe-lightbox-note').addEventListener('click', function () { openNoteModal(state.photos[state.lightIdx]); });
            // Wake the chrome on any interaction.
            ['mousemove', 'touchstart', 'keydown'].forEach(function (ev) { lb.addEventListener(ev, armFade, { passive: true }); });
            document.addEventListener('keydown', function (e) {
                if (lb.hidden) return;
                if (e.key === 'Escape') closeLightbox();
                if (e.key === 'ArrowLeft') { navLight(-1); }
                if (e.key === 'ArrowRight') { navLight(1); }
                if (e.key === ' ') { e.preventDefault(); toggleSlideshow(); }
            });
        }

        function openLightbox() {
            var p = state.photos[state.lightIdx]; if (!p) return;
            $('#tepe-lightbox-img').src = p.url;
            var dl = $('#tepe-lightbox-dl'); dl.href = p.url;
            $('#tepe-lightbox-print').style.display = CFG.allowPrints ? '' : 'none';

            // Caption: note text on top, author underneath. Falls back to a
            // simple "Shared by …" line for photos without a note.
            var noteEl = $('#tepe-lightbox-note-text'), byEl = $('#tepe-lightbox-by'), cap = $('#tepe-lightbox-cap');
            var uploader = (p.uploader && p.uploader.trim()) ? p.uploader.trim() : '';
            if (p.note) {
                noteEl.textContent = '“' + p.note + '”'; noteEl.style.display = '';
                byEl.textContent = uploader ? ('— ' + uploader) : ''; byEl.style.display = uploader ? '' : 'none';
                cap.hidden = false;
            } else if (uploader) {
                noteEl.textContent = ''; noteEl.style.display = 'none';
                byEl.textContent = 'Shared by ' + uploader; byEl.style.display = '';
                cap.hidden = false;
            } else {
                cap.hidden = true;
            }

            $('#tepe-lightbox-like').classList.toggle('tepe-heartbtn--on', !!p.liked);
            $('#tepe-lightbox-likes').textContent = p.likes || 0;
            $('#tepe-lightbox-note').hidden = !p.mine; // "Leave note" only on own photos
            lb.hidden = false;
            document.documentElement.style.overflow = 'hidden'; // lock background scroll
            armFade();
        }
        function navLight(d) { state.lightIdx = (state.lightIdx + d + state.photos.length) % state.photos.length; openLightbox(); }
        function closeLightbox() { lb.hidden = true; stopSlideshow(); clearTimeout(fadeTimer); lb.classList.remove('tepe-lightbox--idle'); document.documentElement.style.overflow = ''; }

        // Auto-hide the chrome after a few seconds of stillness.
        function armFade() {
            lb.classList.remove('tepe-lightbox--idle');
            clearTimeout(fadeTimer);
            fadeTimer = setTimeout(function () { if (!lb.hidden) lb.classList.add('tepe-lightbox--idle'); }, 3000);
        }
        function toggleChrome() { if (lb.classList.contains('tepe-lightbox--idle')) armFade(); else lb.classList.add('tepe-lightbox--idle'); }

        // Classic slideshow (auto-advance), like the Bookings galleries.
        function toggleSlideshow() { slideTimer ? stopSlideshow() : startSlideshow(); }
        function startSlideshow() {
            if (state.photos.length < 2) return;
            lb.classList.add('tepe-lightbox--playing');
            $('#tepe-lightbox-play').setAttribute('aria-pressed', 'true');
            slideTimer = setInterval(function () { navLight(1); }, 3500);
            armFade();
        }
        function stopSlideshow() {
            clearInterval(slideTimer); slideTimer = null;
            lb.classList.remove('tepe-lightbox--playing');
            var pb = $('#tepe-lightbox-play'); if (pb) pb.setAttribute('aria-pressed', 'false');
        }
        var playBtn = $('#tepe-lightbox-play');
        if (playBtn) playBtn.addEventListener('click', function () { toggleSlideshow(); armFade(); });

        // ── Photo-note modal ──
        function wireNoteModal() {
            var m = $('#tepe-notemodal'); if (!m) return;
            m.addEventListener('click', function (e) { if (e.target.hasAttribute('data-noteclose')) closeNoteModal(); });
            $('#tepe-note-save').addEventListener('click', saveNote);
        }
        function openNoteModal(p) {
            if (!p || !p.mine) { toast('You can add a note to your own photos.'); return; }
            var m = $('#tepe-notemodal');
            m.dataset.id = p.id;
            $('#tepe-note-thumb').src = p.thumb_url;
            $('#tepe-note-msg').value = p.note || '';
            var a = $('#tepe-note-author'); if (!a.value) a.value = (loadMyName() || '');
            $('#tepe-note-error').hidden = true;
            m.hidden = false;
        }
        function closeNoteModal() { $('#tepe-notemodal').hidden = true; }
        function saveNote() {
            var m = $('#tepe-notemodal'), id = m.dataset.id;
            var msg = $('#tepe-note-msg').value.trim(), author = $('#tepe-note-author').value.trim();
            var err = $('#tepe-note-error');
            if (($('#tepe-note-website') || {}).value) return; // honeypot
            if (!msg) { err.textContent = 'Please write a short note first.'; err.hidden = false; return; }
            var btn = $('#tepe-note-save'); btnBusy(btn, 'Saving…');
            var fd = new FormData(); identFields(fd);
            fd.append('upload_id', id); fd.append('message', msg); fd.append('author_name', author); fd.append('website', '');
            xhrPost('event/' + CFG.slug + '/note', fd).then(function (r) {
                if (author) saveMyName(author);
                var p = state.photos.filter(function (x) { return String(x.id) === String(id); })[0];
                if (p) p.note = msg;
                state.notes = r.notes || state.notes;
                renderGuestbook(); render();
                closeNoteModal(); btnIdle(btn, 'Add to the guestbook');
                toast(r.moderated ? 'Thanks! Your note will appear once approved.' : 'Added to the guestbook 💛');
            }).catch(function (e) { err.textContent = e.message; err.hidden = false; btnIdle(btn, 'Add to the guestbook'); });
        }

        // ── Print drawer ──
        function openDrawer(photos) {
            photos = (photos || []).filter(Boolean);
            if (!photos.length) { toast('Select at least one photo.'); return; }

            // Print sizes must be loaded, or the drawer would show empty rows.
            if (!CFG.catalog || !CFG.catalog.length) {
                api('print-catalog').then(function (r) {
                    CFG.catalog = (r && r.products) || [];
                    if (typeof r.delivery_fee !== 'undefined') CFG.deliveryFee = r.delivery_fee;
                    if (r.bank_instructions) CFG.bankInstructions = r.bank_instructions;
                    if (CFG.catalog.length) openDrawer(photos);
                    else toast('Print sizes are being set up — please try again shortly.');
                }).catch(function () { toast('Could not load print sizes. Please try again.'); });
                return;
            }

            var drawer = $('#tepe-drawer'); var body = $('#tepe-drawer-body');
            body.innerHTML = '';

            // Per-photo size/qty grid
            var cart = {}; // key photoId::productId -> qty
            photos.forEach(function (p) {
                var line = el('div', 'tepe-pline');
                line.appendChild((function () {
                    var i = el('img'); i.alt = '';
                    i.src = p.thumb_url;
                    // thumb -> full -> neutral placeholder, so it never shows a broken box.
                    i.onerror = function () {
                        if (i.src !== p.url && !/heic|heif/i.test(p.url)) { i.src = p.url; return; }
                        i.onerror = null; i.classList.add('tepe-pline__ph');
                        i.removeAttribute('src');
                    };
                    return i;
                })());
                var sizes = el('div'); sizes.style.flex = '1';
                CFG.catalog.forEach(function (prod) {
                    var row = el('div', 'tepe-psize');
                    row.appendChild(el('label', null, esc(prod.name) + ' — ' + money(prod.price)));
                    var qty = el('div', 'tepe-qty');
                    var minus = el('button', null, '−'); minus.type = 'button';
                    var input = el('input'); input.type = 'text'; input.inputMode = 'numeric'; input.value = '0';
                    var plus = el('button', null, '+'); plus.type = 'button';
                    var key = p.id + '::' + prod.id;
                    function setQ(v) { v = Math.max(0, Math.min(99, v | 0)); input.value = v; if (v) cart[key] = { photo: p, prod: prod, qty: v }; else delete cart[key]; recompute(); }
                    minus.addEventListener('click', function () { setQ((parseInt(input.value, 10) || 0) - 1); });
                    plus.addEventListener('click', function () { setQ((parseInt(input.value, 10) || 0) + 1); });
                    input.addEventListener('change', function () { setQ(parseInt(input.value, 10) || 0); });
                    qty.appendChild(minus); qty.appendChild(input); qty.appendChild(plus);
                    row.appendChild(qty); sizes.appendChild(row);
                });
                line.appendChild(sizes);
                body.appendChild(line);
            });

            // Fulfilment + payment + promo + summary
            var extra = el('div');
            extra.innerHTML =
                '<div class="tepe-field" style="margin-top:1rem"><span>Getting it to you</span>' +
                '<select id="tepe-delivery">' +
                '<option value="event">Hand over on the event date</option>' +
                '<option value="meetup">Meet-up (plaza)</option>' +
                '<option value="delivery">Delivery (+' + money(CFG.deliveryFee) + ')</option>' +
                '</select></div>' +
                '<div class="tepe-field"><span>Your name</span><input type="text" id="tepe-o-name" autocomplete="name"></div>' +
                '<div class="tepe-field"><span>Phone</span><input type="tel" id="tepe-o-phone" autocomplete="tel"></div>' +
                '<div class="tepe-field"><span>Email <em>(optional)</em></span><input type="email" id="tepe-o-email" autocomplete="email"></div>' +
                '<div id="tepe-promo-slot"></div>' +
                '<div class="tepe-field"><span>Payment</span><div class="tepe-pay">' +
                '<label><input type="radio" name="tepe-pay" value="bank" checked><span>Bank transfer</span></label>' +
                '<label><input type="radio" name="tepe-pay" value="cash"><span>Cash on site</span></label>' +
                '</div><div class="tepe-bankbox" id="tepe-bankbox">' + esc(CFG.bankInstructions || '') + '</div></div>' +
                '<input type="text" name="website" id="tepe-o-website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">' +
                '<div class="tepe-summary" id="tepe-summary"></div>' +
                '<p class="tepe-error" id="tepe-o-error" hidden></p>' +
                '<button type="button" class="tepe-btn tepe-btn--gold tepe-btn--full" id="tepe-o-go" style="margin-top:.75rem">Place order</button>';
            body.appendChild(extra);

            // Promo pill
            if (state.reward && CFG.shareReward) {
                var slot = $('#tepe-promo-slot');
                slot.innerHTML = '<div class="tepe-rewardpill">🎁 ' + esc(state.reward.label) + ' — code <code>' + esc(state.reward.code) + '</code> applied</div>';
            }

            $('#tepe-delivery').addEventListener('change', recompute);
            $$('input[name="tepe-pay"]').forEach(function (r) {
                r.addEventListener('change', function () { $('#tepe-bankbox').style.display = (this.value === 'bank') ? '' : 'none'; });
            });
            $('#tepe-o-go').addEventListener('click', function () { submitOrder(cart); });

            drawer.hidden = false;
            drawer.addEventListener('click', function h(e) { if (e.target.hasAttribute('data-close')) { drawer.hidden = true; drawer.removeEventListener('click', h); } });
            recompute();

            function recompute() {
                var sub = 0, items = 0;
                Object.keys(cart).forEach(function (k) { sub += cart[k].prod.price * cart[k].qty; items += cart[k].qty; });
                var deliv = $('#tepe-delivery') ? $('#tepe-delivery').value : 'event';
                if (deliv === 'delivery') sub += CFG.deliveryFee;
                var disc = 0;
                if (state.reward && CFG.shareReward) {
                    // Free 4x6 → discount one 4x6 unit price if present.
                    Object.keys(cart).forEach(function (k) {
                        if (/4x6/i.test(cart[k].prod.id) || /4×6|4x6/.test(cart[k].prod.size)) {
                            disc = Math.max(disc, cart[k].prod.price);
                        }
                    });
                }
                var total = Math.max(0, sub - disc);
                var s = $('#tepe-summary');
                s.innerHTML =
                    '<div class="tepe-summary__row"><span>' + items + ' print' + (items === 1 ? '' : 's') + '</span><span>' + money(sub) + '</span></div>' +
                    (disc > 0 ? '<div class="tepe-summary__row tepe-summary__row--discount"><span>' + esc(state.reward.label) + '</span><span>−' + money(disc) + '</span></div>' : '') +
                    '<div class="tepe-summary__row tepe-summary__row--total"><span>Total</span><span>' + money(total) + '</span></div>';
            }

            function submitOrder(cart) {
                var items = Object.keys(cart).map(function (k) { return { upload_id: cart[k].photo.id, product_id: cart[k].prod.id, qty: cart[k].qty }; });
                if (!items.length) { showErr('Add at least one print.'); return; }
                var name = $('#tepe-o-name').value.trim(), phone = $('#tepe-o-phone').value.trim();
                if (!name || !phone) { showErr('Please add your name and phone number.'); return; }
                var btn = $('#tepe-o-go'); btnBusy(btn, 'Placing order…');
                var fd = new FormData();
                identFields(fd);
                fd.append('items', JSON.stringify(items));
                fd.append('customer_name', name);
                fd.append('customer_phone', phone);
                fd.append('customer_email', $('#tepe-o-email').value.trim());
                fd.append('payment_method', (document.querySelector('input[name="tepe-pay"]:checked') || {}).value || 'bank');
                fd.append('delivery_option', $('#tepe-delivery').value);
                fd.append('guest_name', name);
                fd.append('website', $('#tepe-o-website').value);
                if (state.reward && CFG.shareReward) fd.append('promo_code', state.reward.code);
                xhrPost('event/' + CFG.slug + '/print-order', fd).then(function (r) {
                    if (state.reward) { clearReward(); state.reward = null; }
                    body.innerHTML = '<div style="text-align:center;padding:1rem">' +
                        '<h3>Order placed 🎉</h3><p>' + esc(r.message) + '</p>' +
                        '<p><strong>Ref:</strong> ' + esc(r.order_ref) + '<br><strong>Total:</strong> ' + money(r.total) + '</p>' +
                        (r.bank_instructions ? '<div class="tepe-bankbox">' + esc(r.bank_instructions) + '</div>' : '') +
                        '<button type="button" class="tepe-btn tepe-btn--dark tepe-btn--full" data-close style="margin-top:1rem">Done</button></div>';
                    state.selectMode = false; state.selected = {}; render();
                }).catch(function (e) { showErr(e.message); btnIdle(btn, 'Place order'); });
            }
            function showErr(m) { var e = $('#tepe-o-error'); e.textContent = m; e.hidden = false; }
        }

        // ── Share flow ──
        function shareTo(platform) {
            var url = CFG.galleryUrl;
            var text = (CFG.strings && CFG.strings.shareText) || 'Event photos';
            var enc = encodeURIComponent, u = enc(url), t = enc(text + ' ');
            var reward = function () { if (CFG.shareReward) claimReward(platform); };

            if (platform === 'whatsapp') {
                window.open('https://wa.me/?text=' + t + u, '_blank', 'noopener');
                reward();
            } else if (platform === 'facebook') {
                window.open('https://www.facebook.com/sharer/sharer.php?u=' + u, '_blank', 'noopener,width=640,height=640');
                reward();
            } else {
                // Instagram / TikTok have no web link-share intent. Use the native
                // share sheet where available (best on phones), otherwise copy the
                // link and open the app so the guest can paste it.
                if (navigator.share) {
                    navigator.share({ title: CFG.eventTitle, text: text, url: url }).then(reward).catch(function () {});
                } else {
                    copyLink(url);
                    toast('Link copied — paste it into your ' + (platform === 'tiktok' ? 'TikTok' : 'Instagram') + ' post or story.');
                    window.open(platform === 'tiktok' ? 'https://www.tiktok.com' : 'https://www.instagram.com', '_blank', 'noopener');
                    reward();
                }
            }
        }

        function copyLink(url) {
            try {
                if (navigator.clipboard) navigator.clipboard.writeText(url);
                else { var i = document.createElement('input'); i.value = url; document.body.appendChild(i); i.select(); document.execCommand('copy'); i.remove(); }
            } catch (e) {}
        }

        function claimReward(platform) {
            var fd = new FormData(); identFields(fd); fd.append('platform', platform);
            xhrPost('event/' + CFG.slug + '/share', fd).then(function (r) {
                if (r.rewarded && r.code) {
                    var reward = { code: r.code, label: r.label, value: r.value };
                    saveReward(reward); state.reward = reward; showReward(reward, false);
                } else {
                    toast('Thanks for sharing!');
                }
            }).catch(function () { toast('Thanks for sharing!'); });
        }

        function showReward(reward, quiet) {
            var box = $('#tepe-reward'); if (!box) return;
            box.hidden = false;
            box.innerHTML = '🎁 Thanks for sharing! Here’s a <strong>' + esc(reward.label) + '</strong> — use code <code>' + esc(reward.code) + '</code> at checkout.';
            if (!quiet) toast('You earned a ' + reward.label + '!');
        }
    }

    function loadMyName() { try { return localStorage.getItem('tepe_name') || ''; } catch (e) { return ''; } }
    function saveMyName(n) { try { if (n) localStorage.setItem('tepe_name', n); } catch (e) {} }
    function loadReward() { try { return JSON.parse(localStorage.getItem('tepe_reward_' + CFG.slug) || 'null'); } catch (e) { return null; } }
    function saveReward(r) { try { localStorage.setItem('tepe_reward_' + CFG.slug, JSON.stringify(r)); } catch (e) {} }
    function clearReward() { try { localStorage.removeItem('tepe_reward_' + CFG.slug); } catch (e) {} }

    /* =======================================================================
       UPLOAD VIEW
    ======================================================================= */
    function initUpload() {
        var dropzone = $('#tepe-dropzone'), fileInput = $('#tepe-file'), queue = $('#tepe-queue');
        var goBtn = $('#tepe-upload-go'), dest = $('#tepe-dest');
        if (!dropzone) return; // locked
        var category = '';
        var files = [];

        var nameInput = $('#tepe-guest-name');
        if (nameInput && !nameInput.value) nameInput.value = loadMyName();

        renderDest();

        dropzone.addEventListener('click', function () { fileInput.click(); });
        dropzone.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') fileInput.click(); });
        fileInput.addEventListener('change', function () { addFiles(fileInput.files); fileInput.value = ''; });
        ['dragenter', 'dragover'].forEach(function (ev) { dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.add('tepe-dropzone--over'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.remove('tepe-dropzone--over'); }); });
        dropzone.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files); });
        goBtn.addEventListener('click', startUpload);

        var newAlbumBtn = $('#tepe-new-album');
        if (CFG.allowAlbums && newAlbumBtn) {
            newAlbumBtn.hidden = false;
            newAlbumBtn.addEventListener('click', function () {
                var label = prompt('Name your album (e.g. "Table 5")');
                if (!label) return;
                var fd = new FormData(); identFields(fd); fd.append('label', label);
                xhrPost('event/' + CFG.slug + '/album', fd).then(function (r) {
                    CFG.categories = r.categories; category = r.slug; renderDest(); toast('Album created.');
                }).catch(function (e) { toast(e.message); });
            });
        }

        function renderDest() {
            dest.innerHTML = '';
            (CFG.categories || []).forEach(function (c) {
                var chip = el('button', 'tepe-chip' + (category === c.slug ? ' tepe-chip--active' : ''), esc(c.label));
                chip.type = 'button';
                chip.addEventListener('click', function () { category = c.slug; renderDest(); });
                dest.appendChild(chip);
            });
        }

        function addFiles(list) {
            Array.prototype.forEach.call(list, function (f) {
                if (files.length >= CFG.maxFiles) { toast('Up to ' + CFG.maxFiles + ' photos at a time.'); return; }
                if (f.size > CFG.maxFileSize) { toast('“' + f.name + '” is over 30MB and was skipped.'); return; }
                var item = { file: f, done: false, err: false };
                files.push(item);
                renderQueueItem(item);
            });
            goBtn.hidden = files.length === 0;
            goBtn.textContent = 'Upload ' + files.length + ' photo' + (files.length === 1 ? '' : 's');
        }

        function renderQueueItem(item) {
            var q = el('div', 'tepe-qitem');
            var img = el('img');
            if (/image\/(jpeg|png)/.test(item.file.type)) {
                var url = URL.createObjectURL(item.file); img.src = url; img.onload = function () { URL.revokeObjectURL(url); };
            } else {
                q.classList.add('tepe-tile--heic'); img.alt = 'HEIC';
            }
            q.appendChild(img);
            var bar = el('div', 'tepe-qitem__bar'); q.appendChild(bar);
            item.node = q; item.bar = bar;
            queue.appendChild(q);
        }

        /** Gallery URL with a cache-buster so freshly added photos always show. */
        function galleryHref() {
            var u = CFG.galleryUrl || '/';
            return u + (u.indexOf('?') > -1 ? '&' : '?') + 'uploaded=' + Date.now();
        }
        function goToGallery() { window.location.href = galleryHref(); }

        function startUpload() {
            var website = ($('#tepe-website') || {}).value || '';
            if (website) { return; } // honeypot
            var name = ($('#tepe-guest-name') || {}).value || '';
            saveMyName(name.trim());
            var pending = files.filter(function (f) { return !f.done; });
            if (!pending.length) { goToGallery(); return; }

            var i = 0, ok = 0, failed = 0;
            (function next() {
                if (i >= pending.length) { return finish(ok, failed); }
                btnBusy(goBtn, 'Uploading ' + (i + 1) + ' of ' + pending.length + '…');
                var item = pending[i];
                // Clear any styling from a previous failed attempt.
                item.node.classList.remove('tepe-qitem--err');
                item.bar.style.width = '0%';
                uploadOne(item, name).then(function () {
                    item.done = true; ok++;
                    item.node.classList.add('tepe-qitem--done');
                }).catch(function (e) {
                    item.err = true; failed++;
                    item.node.classList.add('tepe-qitem--err');
                    item.node.title = e.message;
                }).then(function () { i++; next(); });
            })();
        }

        /**
         * Always leave the guest with a visible way into the gallery — the
         * auto-redirect is a convenience, never the only exit.
         */
        function finish(ok, failed) {
            // Count every photo landed so far, not just this attempt, so a
            // retry doesn't under-report what the guest actually uploaded.
            var totalOk = files.filter(function (f) { return f.done; }).length;
            if (totalOk && !failed) {
                goBtn.hidden = true;
                showDone(totalOk, 0);
                setTimeout(goToGallery, 1200);
                return;
            }
            if (totalOk && failed) {
                btnIdle(goBtn, 'Retry ' + failed + ' photo' + (failed === 1 ? '' : 's'));
                showDone(totalOk, failed);
                return;
            }
            btnIdle(goBtn, 'Try again');
            toast('Upload failed — check your connection and try again.');
        }

        function showDone(ok, failed) {
            var panel = $('#tepe-done');
            if (!panel) {
                panel = el('div', 'tepe-done'); panel.id = 'tepe-done';
                goBtn.parentNode.insertBefore(panel, goBtn);
            }
            panel.innerHTML =
                '<div class="tepe-done__tick">' + SVG_CHECK + '</div>' +
                '<p class="tepe-done__msg"><strong>' + ok + ' photo' + (ok === 1 ? '' : 's') + ' added</strong>' +
                (failed ? ' · ' + failed + " didn't upload" : '') + '</p>' +
                '<a class="tepe-btn tepe-btn--gold tepe-btn--full" href="' + esc(galleryHref()) + '">View the gallery →</a>';
        }

        function uploadOne(item, name) {
            var f = item.file;
            var setBar = function (p) { item.bar.style.width = Math.round(p * 100) + '%'; };
            if (f.size > CHUNK_THRESHOLD) return uploadChunked(f, name, setBar);
            var fd = new FormData();
            identFields(fd);
            fd.append('photos[]', f, f.name);
            fd.append('category', category);
            fd.append('guest_name', name);
            fd.append('website', '');
            return xhrPost('event/' + CFG.slug + '/upload', fd, setBar).then(function (r) {
                if (r.errors && r.errors.length) throw new Error(r.errors[0]);
            });
        }

        function uploadChunked(f, name, setBar) {
            var uploadId = (Date.now().toString(16) + Math.random().toString(16).slice(2)).slice(0, 24);
            var total = Math.ceil(f.size / CHUNK_SIZE);
            var idx = 0;
            function sendNext() {
                if (idx >= total) return Promise.resolve();
                var start = idx * CHUNK_SIZE;
                var blob = f.slice(start, Math.min(start + CHUNK_SIZE, f.size));
                var fd = new FormData();
                identFields(fd);
                fd.append('chunk', blob, f.name);
                fd.append('upload_id', uploadId);
                fd.append('index', idx);
                fd.append('total', total);
                fd.append('filename', f.name);
                fd.append('category', category);
                fd.append('guest_name', name);
                return xhrPost('event/' + CFG.slug + '/upload-chunk', fd).then(function () {
                    idx++; setBar(idx / total); return sendNext();
                });
            }
            return sendNext();
        }
    }

    /* =======================================================================
       CREATE VIEW
    ======================================================================= */
    function initCreate() {
        renderMyEvents();

        var go = $('#tepe-c-go');
        if (go) go.addEventListener('click', submit);

        var another = $('#tepe-create-another');
        if (another) another.addEventListener('click', function () {
            $('#tepe-create-done').hidden = true;
            var form = $('#tepe-create-form'); form.hidden = false;
            $('#tepe-c-title').value = ''; $('#tepe-c-welcome').value = ''; $('#tepe-c-date').value = '';
            btnIdle(go, 'Create gallery');
            window.scrollTo({ top: form.offsetTop - 20, behavior: 'smooth' });
        });

        var findGo = $('#tepe-find-go');
        if (findGo) findGo.addEventListener('click', function () {
            var msg = $('#tepe-find-msg'); msg.hidden = true;
            var email = ($('#tepe-find-email').value || '').trim();
            if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { msg.textContent = 'Please enter a valid email.'; msg.hidden = false; return; }
            btnBusy(findGo, 'Sending…');
            var fd = new FormData(); fd.append('email', email);
            xhrPost('find-events', fd).then(function (r) {
                msg.style.color = '#2a2521'; msg.textContent = r.message || 'Check your inbox.'; msg.hidden = false;
            }).catch(function (e) { msg.textContent = e.message; msg.hidden = false; })
              .then(function () { btnIdle(findGo, 'Email me my links'); });
        });

        function submit() {
            var errBox = $('#tepe-c-error'); errBox.hidden = true;
            var title = $('#tepe-c-title').value.trim();
            var email = $('#tepe-c-email').value.trim();
            if (!title) { return showErr('Please name your event.'); }
            if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { return showErr('Please enter a valid email.'); }
            btnBusy(go, 'Creating…');
            var fd = new FormData();
            fd.append('title', title);
            fd.append('host_email', email);
            fd.append('host_name', $('#tepe-c-name').value.trim());
            fd.append('event_date', $('#tepe-c-date').value);
            fd.append('welcome', $('#tepe-c-welcome').value.trim());
            fd.append('categories', $('#tepe-c-cats').value);
            fd.append('website', ($('#tepe-website') || {}).value || '');
            xhrPost('create-event', fd).then(function (r) {
                saveMyEvent({ title: r.title, gallery_url: r.gallery_url, upload_url: r.upload_url, qr: r.qr_svg });
                renderMyEvents();
                $('#tepe-create-form').hidden = true;
                $('#tepe-create-done').hidden = false;
                $('#tepe-create-links').innerHTML =
                    '<div class="tepe-donebtns">' +
                        '<a class="tepe-btn tepe-btn--gold tepe-btn--full" href="' + esc(r.gallery_url) + '">View gallery</a>' +
                        '<a class="tepe-btn tepe-btn--dark tepe-btn--full" href="' + esc(r.upload_url) + '">Guest upload page</a>' +
                        '<a class="tepe-btn tepe-btn--outline tepe-btn--full" href="' + esc(r.qr_svg) + '" download>Download QR code</a>' +
                    '</div>' +
                    '<img class="tepe-doneqr" src="' + esc(r.qr_svg) + '" alt="QR code for this gallery" width="170" height="170">';
            }).catch(function (e) { showErr(e.message); btnIdle(go, 'Create gallery'); });

            function showErr(m) { errBox.textContent = m; errBox.hidden = false; }
        }

        function showErr(m) { var e = $('#tepe-c-error'); e.textContent = m; e.hidden = false; }
    }

    function loadMyEvents() { try { return JSON.parse(localStorage.getItem('tepe_my_events') || '[]'); } catch (e) { return []; } }
    function saveMyEvent(ev) {
        if (!ev || !ev.gallery_url) return;
        var list = loadMyEvents().filter(function (e) { return e.gallery_url !== ev.gallery_url; });
        list.unshift(ev);
        try { localStorage.setItem('tepe_my_events', JSON.stringify(list.slice(0, 30))); } catch (e) {}
    }
    function renderMyEvents() {
        var wrap = $('#tepe-myevents'), list = $('#tepe-myevents-list');
        if (!wrap || !list) return;
        var events = loadMyEvents();
        if (!events.length) { wrap.hidden = true; return; }
        wrap.hidden = false;
        list.innerHTML = '';
        events.forEach(function (ev) {
            var card = el('div', 'tepe-myevent');
            card.innerHTML =
                '<div class="tepe-myevent__title">' + esc(ev.title || 'Event gallery') + '</div>' +
                '<div class="tepe-myevent__links">' +
                    '<a class="tepe-btn tepe-btn--gold" href="' + esc(ev.gallery_url) + '">View gallery</a>' +
                    '<a class="tepe-btn tepe-btn--dark" href="' + esc(ev.upload_url) + '">Add photos</a>' +
                    (ev.qr ? '<a class="tepe-btn tepe-btn--ghost" style="color:#2a2521;border-color:#e7e1d8" href="' + esc(ev.qr) + '" download>QR code</a>' : '') +
                '</div>';
            list.appendChild(card);
        });
    }

    // ── boot ────────────────────────────────────────────────────────────────
    ready().then(function () {
        if (CFG.view === 'gallery') initGallery();
        else if (CFG.view === 'upload') initUpload();
        else if (CFG.view === 'create') initCreate();
    });
})();
