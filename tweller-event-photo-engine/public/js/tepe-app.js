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
        var state = { category: null, photos: [], selectMode: false, selected: {}, lightIdx: 0, reward: loadReward() };

        var masonry = $('#tepe-masonry');
        var loading = $('#tepe-loading');
        var empty = $('#tepe-empty');

        renderCats();
        load();

        // Share
        var shareBtn = $('#tepe-share-btn');
        if (shareBtn) shareBtn.addEventListener('click', doShare);
        if (state.reward) showReward(state.reward, true);

        function load() {
            loading.hidden = false; empty.hidden = true;
            var q = 'event/' + CFG.slug + '/photos?fingerprint=' + encodeURIComponent(IDENT.fingerprint) + '&guest_uid=' + encodeURIComponent(IDENT.uid);
            // null = All (no filter); '' = Public Album (uncategorised) is a real filter.
            if (state.category !== null && state.category !== undefined) q += '&category=' + encodeURIComponent(state.category);
            q += '&_=' + Date.now(); // never serve a cached list right after an upload
            api(q).then(function (r) {
                loading.hidden = true;
                state.photos = r.photos || [];
                if (r.categories) { CFG.categories = r.categories; renderCats(); }
                var cnt = $('#tepe-count'); if (cnt) cnt.textContent = r.count;
                render();
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
            masonry.innerHTML = '';
            if (!state.photos.length) { empty.hidden = false; return; }
            empty.hidden = true;
            var promoAt = CFG.promoEveryN || 11, pi = 0;
            state.photos.forEach(function (p, i) {
                masonry.appendChild(tile(p, i));
                if (CFG.freeTier && CFG.promoCards && CFG.promoCards.length && (i + 1) % promoAt === 0) {
                    masonry.appendChild(promoCard(CFG.promoCards[pi % CFG.promoCards.length])); pi++;
                }
            });
            document.body.classList.toggle('tepe-selectmode', state.selectMode);
            renderSelBar();
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
            if (p.mine) t.appendChild(el('span', 'tepe-tile__badge', 'Yours'));
            var chk = el('span', 'tepe-tile__check', SVG_CHECK); t.appendChild(chk);
            if (CFG.allowPrints) {
                var pb = el('button', 'tepe-tile__print', 'Order print'); pb.type = 'button';
                pb.addEventListener('click', function (ev) { ev.stopPropagation(); openDrawer([p]); });
                t.appendChild(pb);
            }
            t.addEventListener('click', function () {
                if (state.selectMode) { toggleSel(p); }
                else { state.lightIdx = i; openLightbox(); }
            });
            t.addEventListener('contextmenu', function (ev) { ev.preventDefault(); state.selectMode = true; toggleSel(p); render(); });
            return t;
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
        if (lb) {
            // Tapping the photo keeps it open; the nav/action buttons do their
            // own thing; a tap anywhere else (backdrop, padding, X) closes.
            lb.addEventListener('click', function (e) {
                if (e.target.closest('[data-prev],[data-next],.tepe-lightbox__bar')) return;
                if (e.target.id === 'tepe-lightbox-img') return;
                closeLightbox();
            });
            lb.querySelector('[data-prev]').addEventListener('click', function () { navLight(-1); });
            lb.querySelector('[data-next]').addEventListener('click', function () { navLight(1); });
            $('#tepe-lightbox-print').addEventListener('click', function () { closeLightbox(); openDrawer([state.photos[state.lightIdx]]); });
            document.addEventListener('keydown', function (e) {
                if (lb.hidden) return;
                if (e.key === 'Escape') closeLightbox();
                if (e.key === 'ArrowLeft') navLight(-1);
                if (e.key === 'ArrowRight') navLight(1);
            });
        }
        function openLightbox() {
            var p = state.photos[state.lightIdx]; if (!p) return;
            $('#tepe-lightbox-img').src = p.url;
            var dl = $('#tepe-lightbox-dl'); dl.href = p.url;
            $('#tepe-lightbox-print').style.display = CFG.allowPrints ? '' : 'none';
            lb.hidden = false;
        }
        function navLight(d) { state.lightIdx = (state.lightIdx + d + state.photos.length) % state.photos.length; openLightbox(); }
        function closeLightbox() { lb.hidden = true; }

        // ── Print drawer ──
        function openDrawer(photos) {
            photos = (photos || []).filter(Boolean);
            if (!photos.length) { toast('Select at least one photo.'); return; }
            var drawer = $('#tepe-drawer'); var body = $('#tepe-drawer-body');
            body.innerHTML = '';

            // Per-photo size/qty grid
            var cart = {}; // key photoId::productId -> qty
            photos.forEach(function (p) {
                var line = el('div', 'tepe-pline');
                line.appendChild((function () { var i = el('img'); i.src = p.thumb_url; i.onerror = function () { i.src = p.url; }; return i; })());
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
        function doShare() {
            var url = CFG.galleryUrl, text = (CFG.strings && CFG.strings.shareText) || 'Event photos';
            var platform = 'web';
            var after = function () { if (CFG.shareReward) claimReward(platform); };
            if (navigator.share) {
                navigator.share({ title: CFG.eventTitle, text: text, url: url })
                    .then(after)
                    .catch(function () { /* user cancelled — no reward */ });
            } else {
                // Fallback: copy link + open a share intent, then reward.
                var fb = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url);
                window.open(fb, '_blank', 'noopener');
                try { navigator.clipboard && navigator.clipboard.writeText(url); } catch (e) {}
                platform = 'facebook';
                after();
            }
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
                    '<a href="' + esc(r.gallery_url) + '">View gallery: ' + esc(r.gallery_url) + '</a>' +
                    '<a href="' + esc(r.upload_url) + '">Guest upload link: ' + esc(r.upload_url) + '</a>' +
                    '<img src="' + esc(r.qr_svg) + '" alt="QR code" width="160" height="160" style="display:block;margin:1.25rem auto 0;border:8px solid #fff;border-radius:12px;box-shadow:0 2px 10px rgba(16,16,16,.12)">';
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
