/**
 * Tweller Flow — Print Store (client UI)
 *
 * Batch-first ordering flow, usable from the client gallery, the public
 * [tweller_prints] storefront and the tokenized order portal.
 *
 *   Step 1  Choose photos   (grid + select all / clear / running count)
 *   Step 2  Choose a size   (one product + qty per photo + live subtotal)
 *   Step 3  Review & crop   (per-photo crop / qty / remove)  →  add BATCH to cart
 *
 * Modes (twellerFlow2Prints.mode):
 *   'gallery' — floats over the delivered gallery; tracker.js drives it
 *               through window.TwellerPrints.
 *   'public'  — binds to the [tweller_prints] storefront shell.
 *   'portal'  — tokenized order page (receipt upload only).
 *
 * ES5 only — no arrow functions, template literals, let or const.
 */
(function() {
    'use strict';

    function noop() {}
    function zero() { return 0; }

    var cfg = window.twellerFlow2Prints;

    if (!cfg || !cfg.restUrl) {
        // The gallery must keep working when the print store is disabled.
        if (!window.TwellerPrints) {
            window.TwellerPrints = {
                ready: false,
                enabled: false,
                openStore: noop,
                openPicker: noop,
                openOrder: noop,
                openBatchOrder: noop,
                setPhotos: noop,
                getCount: zero,
                getCountFor: zero,
                onCountChange: noop
            };
        }
        return;
    }

    var mode     = cfg.mode === 'gallery' ? 'gallery' : (cfg.mode === 'portal' ? 'portal' : 'public');
    var code     = cfg.code || '';
    var currency = cfg.currency || 'TT$';
    var cartKey  = mode === 'gallery' ? 'tf2_prints_cart_' + code : '';

    var MAX_PUBLIC_FILES = 25;
    var MAX_FILE_BYTES   = 25 * 1024 * 1024;
    var MIN_DPI          = 150;

    var products       = [];
    var productsById   = {};
    var pickupNote     = '';
    var cropService    = cfg.cropService || { enabled: false, fee: 0, label: '', note: '' };
    var productsLoaded = false;

    /**
     * Cart items (flat, so getCountFor()/checkout payloads stay compatible):
     * {batch_id, product_id, product_name, category, price, qty, filename,
     *  photo_url, thumb_url, crop:{x,y,w,h,zoom}, source_w, source_h,
     *  file_index (public only), key}
     */
    var cart = [];
    var countCallbacks = [];

    // Public-mode uploads: {file, name, previewUrl}
    var publicFiles = [];

    // Every photo we've ever been handed, so "Add another size" and
    // "Continue adding" always have something to choose from.
    var photoPool     = [];
    var photoPoolKeys = {};

    // Natural pixel dimensions per photo key: {w, h}
    var photoDims = {};

    // ── Small helpers ──────────────────────────────────

    function money(n) {
        var fixed = (Math.round(n * 100) / 100).toFixed(2);
        var parts = fixed.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return currency + parts.join('.');
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function btn(className, text) {
        var b = el('button', className, text);
        b.type = 'button';
        return b;
    }

    function trim(s) {
        return String(s == null ? '' : s).replace(/^\s+|\s+$/g, '');
    }

    function sizeLabel(p) {
        if (!p || !p.width_in || !p.height_in) return '';
        return p.width_in + '×' + p.height_in + '"';
    }

    function shortSize(p) {
        if (!p || !p.width_in || !p.height_in) return p ? p.name : '';
        return p.width_in + '×' + p.height_in;
    }

    function uid(prefix) {
        return prefix + Math.random().toString(36).slice(2, 9) + Date.now().toString(36).slice(-4);
    }

    /** Stable identity for a photo across the gallery / uploads / cart. */
    function photoKey(photo) {
        if (!photo) return '';
        if (photo.key) return photo.key;
        if (photo.id !== undefined && photo.id !== null && photo.id !== '') return 'id:' + photo.id;
        if (typeof photo.file_index === 'number' && photo.file_index >= 0) return 'f:' + photo.file_index;
        return 'n:' + (photo.filename || photo.url || '');
    }

    function normalizePhoto(photo) {
        if (!photo) return null;
        var url   = photo.url || photo.photo_url || photo.thumb_url || '';
        var thumb = photo.thumb_url || url;
        var out = {
            id:         (photo.id === undefined || photo.id === null) ? '' : photo.id,
            filename:   photo.filename || '',
            url:        url,
            thumb_url:  thumb,
            file_index: typeof photo.file_index === 'number' ? photo.file_index : -1
        };
        if (!out.filename && url) {
            var bits = String(url).split('?')[0].split('/');
            out.filename = bits[bits.length - 1] || 'photo.jpg';
        }
        out.key = photoKey(out);
        return out;
    }

    /** Merge photos into the pool, newest definitions winning. */
    function addToPool(list) {
        if (!list || !list.length) return [];
        var added = [];
        for (var i = 0; i < list.length; i++) {
            var p = normalizePhoto(list[i]);
            if (!p || !p.key) continue;
            if (photoPoolKeys[p.key]) {
                var existing = photoPoolKeys[p.key];
                if (p.url) existing.url = p.url;
                if (p.thumb_url) existing.thumb_url = p.thumb_url;
                if (p.filename) existing.filename = p.filename;
                added.push(existing);
            } else {
                photoPoolKeys[p.key] = p;
                photoPool.push(p);
                added.push(p);
            }
        }
        return added;
    }

    function poolPhoto(key) {
        return photoPoolKeys[key] || null;
    }

    // ── Cart maths ─────────────────────────────────────

    function cartCount() {
        var n = 0;
        for (var i = 0; i < cart.length; i++) n += cart[i].qty;
        return n;
    }

    function countFor(filename) {
        var n = 0;
        for (var i = 0; i < cart.length; i++) {
            if (cart[i].filename === filename) n += cart[i].qty;
        }
        return n;
    }

    function subtotal() {
        var n = 0;
        for (var i = 0; i < cart.length; i++) n += cart[i].price * cart[i].qty;
        return n;
    }

    function saveCart() {
        if (!cartKey) return;
        try { localStorage.setItem(cartKey, JSON.stringify(cart)); } catch (e) {}
    }

    function loadCart() {
        if (!cartKey) return;
        try {
            var raw = localStorage.getItem(cartKey);
            if (!raw) return;
            var parsed = JSON.parse(raw);
            if (Object.prototype.toString.call(parsed) === '[object Array]') {
                cart = parsed;
                for (var i = 0; i < cart.length; i++) {
                    if (!cart[i].batch_id) cart[i].batch_id = 'legacy_' + cart[i].product_id;
                    if (!cart[i].key) cart[i].key = photoKey(cart[i]);
                    addToPool([{
                        id: cart[i].photo_id || '',
                        filename: cart[i].filename,
                        url: cart[i].photo_url,
                        thumb_url: cart[i].thumb_url,
                        file_index: cart[i].file_index
                    }]);
                }
            }
        } catch (e) { cart = []; }
    }

    function notifyCountChange() {
        saveCart();
        updateInternalUI();
        for (var i = 0; i < countCallbacks.length; i++) {
            try { countCallbacks[i](cartCount()); } catch (e) {}
        }
    }

    function indexOfItem(item) {
        for (var i = 0; i < cart.length; i++) if (cart[i] === item) return i;
        return -1;
    }

    /** Cart grouped into batches, in insertion order. */
    function cartBatches() {
        var order = [];
        var map = {};
        for (var i = 0; i < cart.length; i++) {
            var it = cart[i];
            var bid = it.batch_id || ('legacy_' + it.product_id);
            if (!map[bid]) {
                map[bid] = { id: bid, product_id: it.product_id, product_name: it.product_name, items: [], qty: 0, total: 0 };
                order.push(map[bid]);
            }
            map[bid].items.push(it);
            map[bid].qty += it.qty;
            map[bid].total += it.price * it.qty;
        }
        return order;
    }

    function removeBatch(batchId) {
        var kept = [];
        for (var i = 0; i < cart.length; i++) {
            if ((cart[i].batch_id || ('legacy_' + cart[i].product_id)) !== batchId) kept.push(cart[i]);
        }
        cart = kept;
        notifyCountChange();
        renderCart();
    }

    // ── Products ───────────────────────────────────────

    function loadProducts(cb) {
        if (productsLoaded) { if (cb) cb(); return; }
        fetch(cfg.restUrl + 'products')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.ok && data.products) {
                    products = data.products;
                    productsById = {};
                    for (var i = 0; i < products.length; i++) {
                        productsById[products[i].id] = products[i];
                    }
                    pickupNote = data.pickup_note || '';
                    productsLoaded = true;
                }
                if (cb) cb();
            })
            ['catch'](function() {
                if (cb) cb();
            });
    }

    var CATEGORY_LABELS = { print: 'Prints', canvas: 'Canvas', photobook: 'Albums' };
    /** An album only makes sense with a decent spread of photos. */
    var PHOTOBOOK_MIN = 20;
    var CATEGORY_ORDER  = ['print', 'canvas', 'photobook'];

    // ── Crop maths ─────────────────────────────────────

    /**
     * Product size oriented to match the photo, so a landscape photo gets a
     * landscape print. Returns {w, h} in inches, or null when the product
     * has no physical size (albums).
     */
    function orientedSize(product, sourceAspect) {
        if (!product || !product.width_in || !product.height_in) return null;
        var w = product.width_in;
        var h = product.height_in;
        if (sourceAspect && ((sourceAspect >= 1 && w < h) || (sourceAspect < 1 && w > h))) {
            var t = w; w = h; h = t;
        }
        return { w: w, h: h };
    }

    function targetAspect(product, sourceAspect) {
        var s = orientedSize(product, sourceAspect);
        if (!s) return sourceAspect || 1;
        return s.w / s.h;
    }

    function validCrop(crop) {
        return !!(crop && typeof crop === 'object' &&
            isFinite(crop.w) && isFinite(crop.h) && crop.w > 0 && crop.h > 0 &&
            isFinite(crop.x) && isFinite(crop.y));
    }

    /** Centred maximal fit of `aspect` inside a source of `sourceAspect`. */
    function defaultCrop(sourceAspect, aspect) {
        if (!sourceAspect || !aspect) return { x: 0, y: 0, w: 1, h: 1, zoom: 1 };
        var w, h;
        if (sourceAspect > aspect) {
            h = 1;
            w = aspect / sourceAspect;
        } else {
            w = 1;
            h = sourceAspect / aspect;
        }
        return {
            x: Math.round(((1 - w) / 2) * 1e6) / 1e6,
            y: Math.round(((1 - h) / 2) * 1e6) / 1e6,
            w: Math.round(w * 1e6) / 1e6,
            h: Math.round(h * 1e6) / 1e6,
            zoom: 1
        };
    }

    /** Effective print DPI for an item, or 0 when it can't be determined. */
    function itemDpi(item) {
        var p = productsById[item.product_id];
        if (!item.source_w || !item.source_h) return 0;
        var sourceAspect = item.source_w / item.source_h;
        var size = orientedSize(p, sourceAspect);
        if (!size) return 0;
        var crop = validCrop(item.crop) ? item.crop : defaultCrop(sourceAspect, size.w / size.h);
        var pxW = item.source_w * crop.w;
        var pxH = item.source_h * crop.h;
        return Math.floor(Math.min(pxW / size.w, pxH / size.h));
    }

    /**
     * Position an <img> inside an overflow-hidden box whose aspect ratio
     * equals the crop's, so the thumbnail shows exactly what will print.
     */
    function applyCropStyle(img, crop) {
        if (!validCrop(crop)) {
            img.style.position = '';
            img.style.width    = '100%';
            img.style.height   = '100%';
            img.style.left     = '';
            img.style.top      = '';
            img.style.maxWidth = '';
            img.style.objectFit = 'cover';
            return;
        }
        img.style.position  = 'absolute';
        img.style.width     = (100 / crop.w) + '%';
        img.style.height    = (100 / crop.h) + '%';
        img.style.left      = (-(crop.x / crop.w) * 100) + '%';
        img.style.top       = (-(crop.y / crop.h) * 100) + '%';
        img.style.maxWidth  = 'none';
        img.style.maxHeight = 'none';
        img.style.objectFit = 'fill';
    }

    /** Aspect-ratio box containing a crop-positioned image. */
    function cropThumb(className, src, crop, aspect) {
        var box = el('div', 'tf2p-cropbox' + (className ? ' ' + className : ''));
        if (aspect) box.style.aspectRatio = aspect.w + ' / ' + aspect.h;
        var img = document.createElement('img');
        img.alt = '';
        img.src = src || '';
        applyCropStyle(img, crop);
        box.appendChild(img);
        return box;
    }

    // ── Image dimension cache (never mutates shared DOM) ──

    function measurePhoto(photo, cb) {
        var key = photo.key || photoKey(photo);
        if (photoDims[key]) { cb(photoDims[key]); return; }
        var src = photo.url || photo.thumb_url;
        if (!src) { cb(null); return; }
        var probe = new Image();
        probe.onload = function() {
            if (probe.naturalWidth && probe.naturalHeight) {
                photoDims[key] = { w: probe.naturalWidth, h: probe.naturalHeight };
                cb(photoDims[key]);
            } else {
                cb(null);
            }
        };
        probe.onerror = function() { cb(null); };
        probe.src = src;
    }

    // ── Overlay DOM ────────────────────────────────────

    var root, backdrop, flowEl, drawer, cropEl, fab;

    function buildOverlay() {
        if (root) return;
        root = el('div', 'tf2p-root');

        backdrop = el('div', 'tf2p-backdrop');
        backdrop.addEventListener('click', function() {
            if (isCropOpen()) return;
            closeFlow();
            closeDrawer();
        });

        // ── Batch ordering flow ──
        flowEl = el('div', 'tf2p-flow');
        flowEl.setAttribute('role', 'dialog');
        flowEl.setAttribute('aria-label', 'Order prints');
        flowEl.innerHTML =
            '<div class="tf2p-flow__handle"></div>' +
            '<div class="tf2p-flow__head">' +
                '<button type="button" class="tf2p-close" id="tf2p-flow-close" aria-label="Close">&times;</button>' +
                '<p class="tf2p-flow__eyebrow" id="tf2p-flow-eyebrow"></p>' +
                '<h3 class="tf2p-flow__title" id="tf2p-flow-title">Order prints</h3>' +
                '<p class="tf2p-flow__sub" id="tf2p-flow-sub"></p>' +
                '<ol class="tf2p-steps" id="tf2p-steps"></ol>' +
            '</div>' +
            '<div class="tf2p-flow__scroll" id="tf2p-flow-body"></div>' +
            '<div class="tf2p-flow__foot">' +
                '<p class="tf2p-flow__summary" id="tf2p-flow-summary"></p>' +
                '<div class="tf2p-flow__actions">' +
                    '<button type="button" class="tf2p-btn tf2p-btn--ghost" id="tf2p-flow-back">Back</button>' +
                    '<button type="button" class="tf2p-btn tf2p-btn--gold tf2p-flow__next" id="tf2p-flow-next">Next</button>' +
                '</div>' +
            '</div>';

        // ── Cart drawer ──
        drawer = el('div', 'tf2p-drawer');
        drawer.setAttribute('role', 'dialog');
        drawer.setAttribute('aria-label', 'Print cart');
        drawer.innerHTML =
            '<div class="tf2p-drawer__head">' +
                '<h3 class="tf2p-drawer__title" id="tf2p-drawer-title">Checkout</h3>' +
                '<button type="button" class="tf2p-close" id="tf2p-drawer-close" aria-label="Close">&times;</button>' +
            '</div>' +
            '<div class="tf2p-drawer__body" id="tf2p-drawer-body">' +
              '<div class="tf2p-co">' +
                // ── Left column: contact + delivery ──
                '<div class="tf2p-co__main">' +
                  '<form class="tf2p-form" id="tf2p-checkout-form" novalidate>' +
                    '<h4 class="tf2p-form__title">Contact</h4>' +
                    '<label class="tf2p-field"><span>Name</span><input type="text" id="tf2p-cust-name" autocomplete="name" required maxlength="120"></label>' +
                    '<label class="tf2p-field"><span>Email</span><input type="email" id="tf2p-cust-email" autocomplete="email" required maxlength="190"></label>' +
                    '<label class="tf2p-field"><span>Phone (WhatsApp)</span><input type="tel" id="tf2p-cust-phone" autocomplete="tel" maxlength="40"></label>' +

                    '<h4 class="tf2p-form__title">How would you like it?</h4>' +
                    '<div class="tf2p-fulfil" id="tf2p-fulfil">' +
                      '<button type="button" class="tf2p-fulfil__opt tf2p-fulfil__opt--on" data-mode="pickup">' +
                        '<span class="tf2p-fulfil__radio"></span>' +
                        '<span class="tf2p-fulfil__body">' +
                          '<span class="tf2p-fulfil__title">Pick up</span>' +
                          '<span class="tf2p-fulfil__note">Collect from Tweller Studios</span>' +
                        '</span>' +
                      '</button>' +
                      '<button type="button" class="tf2p-fulfil__opt" data-mode="delivery">' +
                        '<span class="tf2p-fulfil__radio"></span>' +
                        '<span class="tf2p-fulfil__body">' +
                          '<span class="tf2p-fulfil__title">Deliver to me</span>' +
                          '<span class="tf2p-fulfil__note">Across Trinidad &amp; Tobago</span>' +
                        '</span>' +
                      '</button>' +
                    '</div>' +

                    '<div class="tf2p-ship" id="tf2p-ship" style="display:none;">' +
                      '<label class="tf2p-field"><span>Street address</span><input type="text" id="tf2p-ship-addr1" autocomplete="address-line1" maxlength="160"></label>' +
                      '<label class="tf2p-field"><span>Apartment, unit (optional)</span><input type="text" id="tf2p-ship-addr2" autocomplete="address-line2" maxlength="160"></label>' +
                      '<div class="tf2p-field-row">' +
                        '<label class="tf2p-field"><span>Town / City</span><input type="text" id="tf2p-ship-city" autocomplete="address-level2" maxlength="80"></label>' +
                        '<label class="tf2p-field"><span>Island / Region</span><input type="text" id="tf2p-ship-region" autocomplete="address-level1" maxlength="80" placeholder="Trinidad"></label>' +
                      '</div>' +
                      '<label class="tf2p-field"><span>Delivery notes (optional)</span><input type="text" id="tf2p-ship-note" maxlength="200" placeholder="Landmark, gate colour, best time…"></label>' +
                    '</div>' +

                    '<label class="tf2p-field"><span>Order notes (optional)</span><textarea id="tf2p-cust-notes" rows="2" maxlength="1000"></textarea></label>' +
                    '<input type="text" name="website" id="tf2p-hp" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="tf2p-hp">' +
                    '<p class="tf2p-error" id="tf2p-checkout-error" style="display:none;"></p>' +
                  '</form>' +
                '</div>' +

                // ── Right column: order summary ──
                '<aside class="tf2p-co__aside">' +
                  '<div class="tf2p-co__card">' +
                    '<h4 class="tf2p-co__title">Order summary</h4>' +
                    '<div id="tf2p-cart-items"></div>' +
                    '<p class="tf2p-empty" id="tf2p-cart-empty" style="display:none;">Your cart is empty.<br>Choose your photos, pick a size, and they’ll appear here.</p>' +
                    '<div class="tf2p-subtotal" id="tf2p-subtotal-row"><span>Total</span><strong id="tf2p-subtotal"></strong></div>' +
                    '<p class="tf2p-pickup" id="tf2p-pickup-note" style="display:none;"></p>' +
                    '<div class="tf2p-drawer__addrow">' +
                      '<button type="button" class="tf2p-btn tf2p-btn--ghost tf2p-btn--full" id="tf2p-add-size">&#65291; Add another size</button>' +
                      '<button type="button" class="tf2p-btn tf2p-btn--ghost tf2p-btn--full" id="tf2p-continue">Continue adding photos</button>' +
                    '</div>' +
                    '<button type="submit" form="tf2p-checkout-form" class="tf2p-btn tf2p-btn--gold tf2p-btn--full" id="tf2p-checkout-btn">Place Order</button>' +
                    '<p class="tf2p-payhint">Pay after checkout from your order page — by card (via WiPay) or bank transfer.</p>' +
                  '</div>' +
                '</aside>' +
              '</div>' +
            '</div>' +
            '<div class="tf2p-success" id="tf2p-success" style="display:none;">' +
                '<div class="tf2p-success__icon"><svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>' +
                '<h3>Order received!</h3>' +
                '<p class="tf2p-success__refline">Your order reference</p>' +
                '<p class="tf2p-success__ref" id="tf2p-success-ref"></p>' +
                '<div class="tf2p-success__pay" id="tf2p-success-pay" style="display:none;">' +
                    '<h4>Payment — Bank Transfer</h4>' +
                    '<pre id="tf2p-success-pay-text"></pre>' +
                '</div>' +
                '<p class="tf2p-success__note" id="tf2p-success-note"></p>' +
                '<p class="tf2p-success__bye">We’ll be in touch shortly to confirm everything. A confirmation email is on its way to you.</p>' +
                '<button type="button" class="tf2p-btn tf2p-btn--dark tf2p-btn--full" id="tf2p-success-done">Done</button>' +
            '</div>';

        // ── Crop editor ──
        cropEl = el('div', 'tf2p-cropmodal');
        cropEl.setAttribute('role', 'dialog');
        cropEl.setAttribute('aria-label', 'Adjust crop');
        cropEl.innerHTML =
            '<div class="tf2p-cropmodal__panel">' +
                '<div class="tf2p-cropmodal__head">' +
                    '<h4 class="tf2p-cropmodal__title" id="tf2p-crop-title">Adjust crop</h4>' +
                    '<button type="button" class="tf2p-close" id="tf2p-crop-close" aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="tf2p-cropmodal__stage">' +
                    '<div class="tf2p-crop__frame" id="tf2p-crop-frame">' +
                        '<img class="tf2p-crop__img" id="tf2p-crop-img" alt="" draggable="false">' +
                        '<div class="tf2p-crop__grid" aria-hidden="true"><span></span><span></span><span></span><span></span></div>' +
                    '</div>' +
                '</div>' +
                '<p class="tf2p-crop__warn" id="tf2p-crop-warn" style="display:none;"></p>' +
                '<div class="tf2p-crop__zoom">' +
                    '<span class="tf2p-crop__zoom-ico">&minus;</span>' +
                    '<input type="range" id="tf2p-crop-range" min="1" max="4" step="0.01" value="1" aria-label="Zoom">' +
                    '<span class="tf2p-crop__zoom-ico">&#65291;</span>' +
                '</div>' +
                '<p class="tf2p-crop__hint">Drag the photo to reposition it · pinch or use the slider to zoom.</p>' +
                '<div class="tf2p-cropmodal__foot">' +
                    '<button type="button" class="tf2p-btn tf2p-btn--ghost" id="tf2p-crop-reset">Reset</button>' +
                    '<button type="button" class="tf2p-btn tf2p-btn--gold" id="tf2p-crop-save">Save crop</button>' +
                '</div>' +
            '</div>';

        // ── Persistent cart FAB ──
        fab = btn('tf2p-fab', '');
        fab.setAttribute('aria-label', 'Open your print cart');
        fab.innerHTML =
            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>' +
            '<span class="tf2p-fab__badge" id="tf2p-fab-badge">0</span>';
        fab.style.display = 'none';
        fab.addEventListener('click', function() { openDrawer(); });

        // Pickup vs delivery — reveals the shipping fields
        var fulfilWrap = drawer.querySelector('#tf2p-fulfil');
        if (fulfilWrap) {
            var opts = fulfilWrap.querySelectorAll('.tf2p-fulfil__opt');
            for (var fi = 0; fi < opts.length; fi++) {
                (function(o) {
                    o.addEventListener('click', function() {
                        for (var k = 0; k < opts.length; k++) {
                            opts[k].className = 'tf2p-fulfil__opt';
                        }
                        o.className = 'tf2p-fulfil__opt tf2p-fulfil__opt--on';
                        shipMode = o.getAttribute('data-mode') === 'delivery' ? 'delivery' : 'pickup';
                        var ship = document.getElementById('tf2p-ship');
                        if (ship) ship.style.display = shipMode === 'delivery' ? '' : 'none';
                    });
                })(opts[fi]);
            }
        }

        root.appendChild(backdrop);
        root.appendChild(flowEl);
        root.appendChild(drawer);
        root.appendChild(cropEl);
        root.appendChild(fab);
        document.body.appendChild(root);

        document.getElementById('tf2p-flow-close').addEventListener('click', closeFlow);
        document.getElementById('tf2p-flow-back').addEventListener('click', onFlowBack);
        document.getElementById('tf2p-flow-next').addEventListener('click', onFlowNext);
        document.getElementById('tf2p-drawer-close').addEventListener('click', closeDrawer);
        document.getElementById('tf2p-add-size').addEventListener('click', function() { addAnotherSize(); });
        document.getElementById('tf2p-continue').addEventListener('click', function() {
            closeDrawer();
            startFlow({ step: 1, photos: poolAsList(), selected: [] });
        });
        document.getElementById('tf2p-success-done').addEventListener('click', function() {
            closeDrawer();
            var success = document.getElementById('tf2p-success');
            var body = document.getElementById('tf2p-drawer-body');
            if (success) success.style.display = 'none';
            if (body) body.style.display = '';
        });
        document.getElementById('tf2p-checkout-form').addEventListener('submit', onCheckout);

        document.getElementById('tf2p-crop-close').addEventListener('click', closeCrop);
        document.getElementById('tf2p-crop-save').addEventListener('click', saveCrop);
        document.getElementById('tf2p-crop-reset').addEventListener('click', resetCrop);

        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;
            if (isCropOpen()) { closeCrop(); return; }
            closeFlow();
            closeDrawer();
        });

        if (cfg.customerName) {
            var nameInput = document.getElementById('tf2p-cust-name');
            if (nameInput) nameInput.value = cfg.customerName;
        }
        if (cfg.customerEmail) {
            var emailInput = document.getElementById('tf2p-cust-email');
            if (emailInput) emailInput.value = cfg.customerEmail;
        }

        bindCropGestures();
        updateInternalUI();
    }

    function poolAsList() {
        return photoPool.slice(0);
    }

    // ── Open / close ───────────────────────────────────

    function isFlowOpen()   { return flowEl && flowEl.classList.contains('tf2p-open'); }
    function isDrawerOpen() { return drawer && drawer.classList.contains('tf2p-open'); }
    function isCropOpen()   { return cropEl && cropEl.classList.contains('tf2p-open'); }

    function syncBackdrop() {
        if (!backdrop) return;
        var open = isFlowOpen() || isDrawerOpen();
        if (open) backdrop.classList.add('tf2p-open');
        else backdrop.classList.remove('tf2p-open');

        if (open || isCropOpen()) document.body.classList.add('tf2p-noscroll');
        else document.body.classList.remove('tf2p-noscroll');

        updateFab();
    }

    function closeFlow() {
        if (!flowEl) return;
        flowEl.classList.remove('tf2p-open');
        syncBackdrop();
    }

    function openDrawer() {
        buildOverlay();
        closeFlow();
        loadProducts(null);
        renderCart();
        var success = document.getElementById('tf2p-success');
        var body = document.getElementById('tf2p-drawer-body');
        if (!(success && success.style.display !== 'none') && body) {
            body.style.display = '';
        }
        drawer.classList.add('tf2p-open');
        syncBackdrop();
    }

    function closeDrawer() {
        if (!drawer) return;
        drawer.classList.remove('tf2p-open');
        syncBackdrop();
    }

    function updateFab() {
        if (!fab) return;
        var n = cartCount();
        var hidden = n <= 0 || isDrawerOpen() || isFlowOpen() || isCropOpen();
        fab.style.display = hidden ? 'none' : '';
        var badge = document.getElementById('tf2p-fab-badge');
        if (badge) badge.textContent = String(n);
    }

    // ── The batch flow ─────────────────────────────────

    var flow = null;

    /**
     * opts: {step, photos, selected, productId, productLocked, qtyPer}
     */
    function startFlow(opts) {
        buildOverlay();
        closeDrawer();
        opts = opts || {};

        var pool = opts.photos && opts.photos.length ? addToPool(opts.photos) : poolAsList();
        if (!pool.length) pool = poolAsList();

        var selected = {};
        var order = [];
        var seed = opts.selected || [];
        for (var i = 0; i < seed.length; i++) {
            var k = typeof seed[i] === 'string' ? seed[i] : photoKey(seed[i]);
            if (k && !selected[k]) { selected[k] = true; order.push(k); }
        }

        flow = {
            step: opts.step || 1,
            pool: pool,
            selected: selected,
            order: order,
            productId: opts.productId || '',
            productLocked: !!opts.productLocked,
            qtyPer: opts.qtyPer || 1,
            items: [],
            cropMode: opts.cropMode || 'studio',
            editBatchId: opts.editBatchId || null
        };

        loadProducts(function() {
            if (flow && flow.productId && !productsById[flow.productId]) {
                flow.productId = '';
                flow.productLocked = false;
            }
            if (flow && flow.step === 3 && !flow.productId) flow.step = 2;
            renderFlow();
        });

        renderFlow();
        flowEl.classList.add('tf2p-open');
        syncBackdrop();
    }

    function selectedKeys() {
        if (!flow) return [];
        var out = [];
        for (var i = 0; i < flow.order.length; i++) {
            if (flow.selected[flow.order[i]]) out.push(flow.order[i]);
        }
        return out;
    }

    function toggleSelect(key) {
        if (!flow) return;
        if (flow.selected[key]) {
            delete flow.selected[key];
        } else {
            flow.selected[key] = true;
            if (flow.order.indexOf(key) < 0) flow.order.push(key);
        }
    }

    function goStep(n) {
        if (!flow) return;
        flow.step = n;
        renderFlow();
        var body = document.getElementById('tf2p-flow-body');
        if (body) body.scrollTop = 0;
    }

    function onFlowBack() {
        if (!flow) return;
        if (flow.step === 1) { closeFlow(); return; }
        if (flow.step === 2) { goStep(1); return; }
        goStep(flow.productLocked ? 1 : 2);
    }

    function onFlowNext() {
        if (!flow) return;
        if (flow.step === 1) {
            if (!selectedKeys().length) return;
            if (flow.productId) { buildFlowItems(); goStep(3); }
            else goStep(2);
            return;
        }
        if (flow.step === 2) {
            if (!flow.productId) return;
            buildFlowItems();
            goStep(3);
            return;
        }
        commitBatch();
    }

    /** Turn the current selection + product into editable review items. */
    function buildFlowItems() {
        if (!flow) return;
        var keys = selectedKeys();
        var product = productsById[flow.productId];
        var prev = {};
        var i;
        for (i = 0; i < flow.items.length; i++) prev[flow.items[i].key] = flow.items[i];

        var items = [];
        for (i = 0; i < keys.length; i++) {
            var photo = poolPhoto(keys[i]);
            if (!photo) continue;
            var old = prev[keys[i]];
            items.push({
                key:       keys[i],
                photo:     photo,
                qty:       old ? old.qty : (flow.qtyPer || 1),
                crop:      (old && old.productId === flow.productId) ? old.crop : null,
                productId: flow.productId
            });
        }
        flow.items = items;

        // Fill in default crops as soon as we know each photo's real size.
        for (i = 0; i < items.length; i++) {
            (function(item) {
                if (item.crop) return;
                measurePhoto(item.photo, function(dims) {
                    if (!dims) return;
                    if (!flow || flow.items.indexOf(item) < 0) return;
                    if (item.crop) return;
                    var sa = dims.w / dims.h;
                    item.crop = defaultCrop(sa, targetAspect(product, sa));
                    if (flow.step === 3) renderFlow();
                });
            })(items[i]);
        }
    }

    function commitBatch() {
        if (!flow || !flow.items.length || !flow.productId) return;
        var product = productsById[flow.productId];
        if (!product) return;

        if (flow.editBatchId) removeBatchSilent(flow.editBatchId);

        var batchId = flow.editBatchId || uid('b_');
        var lastPhotos = [];
        for (var i = 0; i < flow.items.length; i++) {
            var it = flow.items[i];
            if (it.qty <= 0) continue;
            var dims = photoDims[it.key] || null;
            cart.push({
                batch_id:     batchId,
                crop_mode:    flow.cropMode || 'studio',
                key:          it.key,
                product_id:   product.id,
                product_name: product.name,
                category:     product.category || '',
                price:        product.price,
                qty:          Math.min(50, it.qty),
                filename:     it.photo.filename || '',
                photo_id:     it.photo.id || '',
                photo_url:    it.photo.url || '',
                thumb_url:    it.photo.thumb_url || '',
                crop:         it.crop || null,
                source_w:     dims ? dims.w : 0,
                source_h:     dims ? dims.h : 0,
                file_index:   typeof it.photo.file_index === 'number' ? it.photo.file_index : -1
            });
            lastPhotos.push(it.key);
        }

        lastBatchPhotos = lastPhotos;
        flow.editBatchId = null;
        notifyCountChange();
        closeFlow();
        openDrawer();
        flashBatch(batchId);
    }

    function removeBatchSilent(batchId) {
        var kept = [];
        for (var i = 0; i < cart.length; i++) {
            if ((cart[i].batch_id || ('legacy_' + cart[i].product_id)) !== batchId) kept.push(cart[i]);
        }
        cart = kept;
    }

    var lastBatchPhotos = [];

    function flashBatch(batchId) {
        var node = document.getElementById('tf2p-batch-' + batchId);
        if (!node) return;
        node.classList.add('tf2p-batch--new');
        try { node.scrollIntoView({ block: 'nearest' }); } catch (e) {}
    }

    function addAnotherSize() {
        var seed = lastBatchPhotos.length ? lastBatchPhotos : cartPhotoKeys();
        closeDrawer();
        startFlow({
            step: seed.length ? 2 : 1,
            photos: poolAsList(),
            selected: seed
        });
    }

    function cartPhotoKeys() {
        var out = [];
        var seen = {};
        for (var i = 0; i < cart.length; i++) {
            var k = cart[i].key || photoKey(cart[i]);
            if (k && !seen[k]) { seen[k] = true; out.push(k); }
        }
        return out;
    }

    function editBatch(batchId) {
        var items = [];
        var productId = '';
        for (var i = 0; i < cart.length; i++) {
            if ((cart[i].batch_id || ('legacy_' + cart[i].product_id)) !== batchId) continue;
            items.push(cart[i]);
            productId = cart[i].product_id;
        }
        if (!items.length) return;

        var keys = [];
        for (i = 0; i < items.length; i++) {
            var k = items[i].key || photoKey(items[i]);
            keys.push(k);
            addToPool([{
                id: items[i].photo_id || '',
                filename: items[i].filename,
                url: items[i].photo_url,
                thumb_url: items[i].thumb_url,
                file_index: items[i].file_index
            }]);
            if (items[i].source_w && items[i].source_h && !photoDims[k]) {
                photoDims[k] = { w: items[i].source_w, h: items[i].source_h };
            }
        }

        closeDrawer();
        startFlow({ step: 3, photos: poolAsList(), selected: keys, productId: productId, editBatchId: batchId });
        if (flow) {
            buildFlowItems();
            for (i = 0; i < flow.items.length; i++) {
                for (var j = 0; j < items.length; j++) {
                    if ((items[j].key || photoKey(items[j])) === flow.items[i].key) {
                        flow.items[i].qty = items[j].qty;
                        if (validCrop(items[j].crop)) flow.items[i].crop = items[j].crop;
                    }
                }
            }
            renderFlow();
        }
    }

    // ── Flow rendering ─────────────────────────────────

    function renderFlow() {
        if (!flow || !flowEl) return;

        var body    = document.getElementById('tf2p-flow-body');
        var title   = document.getElementById('tf2p-flow-title');
        var sub     = document.getElementById('tf2p-flow-sub');
        var eyebrow = document.getElementById('tf2p-flow-eyebrow');
        var summary = document.getElementById('tf2p-flow-summary');
        var nextBtn = document.getElementById('tf2p-flow-next');
        var backBtn = document.getElementById('tf2p-flow-back');
        if (!body) return;

        renderSteps();

        var product = productsById[flow.productId];
        var count   = selectedKeys().length;

        body.innerHTML = '';
        eyebrow.textContent = 'Step ' + flow.step + ' of 3';

        if (flow.step === 1) {
            title.textContent = product && flow.productLocked
                ? 'Ordering ' + product.name
                : 'Choose your photos';
            sub.textContent = product && flow.productLocked
                ? 'Pick every photo you’d like at this size — you can add other sizes after.'
                : 'Tick every photo you want printed. You’ll pick the size next.';
            body.appendChild(renderStepPhotos());
            summary.textContent = count ? count + (count === 1 ? ' photo selected' : ' photos selected') : 'No photos selected yet';
            nextBtn.textContent = flow.productId ? 'Review & crop' : 'Choose a size';
            nextBtn.disabled = count === 0;
            backBtn.textContent = 'Cancel';
        } else if (flow.step === 2) {
            title.textContent = 'Choose a size';
            sub.textContent = count + (count === 1 ? ' photo' : ' photos') + ' selected · pick one product for this batch.';
            body.appendChild(renderStepProduct());
            summary.textContent = batchSummaryText();
            nextBtn.textContent = 'Review & crop';
            nextBtn.disabled = !flow.productId;
            backBtn.textContent = 'Change photos';
        } else {
            title.textContent = 'Review & crop';
            sub.textContent = product ? product.name + (sizeLabel(product) ? ' · ' + sizeLabel(product) : '') : '';
            body.appendChild(renderStepReview());
            summary.textContent = reviewSummaryText();
            nextBtn.textContent = flow.editBatchId ? 'Save changes' : 'Add to cart';
            nextBtn.disabled = !reviewQty();
            backBtn.textContent = flow.productLocked ? 'Change photos' : 'Change size';
        }
    }

    function renderSteps() {
        var wrap = document.getElementById('tf2p-steps');
        if (!wrap) return;
        wrap.innerHTML = '';
        var labels = ['Photos', 'Size', 'Review'];
        for (var i = 0; i < labels.length; i++) {
            var n = i + 1;
            var li = el('li', 'tf2p-steps__item' + (n === flow.step ? ' tf2p-steps__item--on' : (n < flow.step ? ' tf2p-steps__item--done' : '')));
            li.appendChild(el('span', 'tf2p-steps__num', String(n)));
            li.appendChild(el('span', 'tf2p-steps__label', labels[i]));
            wrap.appendChild(li);
        }
    }

    function batchSummaryText() {
        var count = selectedKeys().length;
        var product = productsById[flow.productId];
        if (!product) return count + (count === 1 ? ' photo' : ' photos') + ' · choose a size';
        var qty = Math.max(1, flow.qtyPer);
        var total = product.price * count * qty;
        return count + ' × ' + shortSize(product) + (qty > 1 ? ' × ' + qty : '') + ' = ' + money(total);
    }

    function reviewQty() {
        var n = 0;
        if (!flow) return 0;
        for (var i = 0; i < flow.items.length; i++) n += flow.items[i].qty;
        return n;
    }

    function reviewSummaryText() {
        var product = productsById[flow.productId];
        var n = reviewQty();
        if (!product) return '';
        return n + (n === 1 ? ' print' : ' prints') + ' · ' + money(product.price * n);
    }

    // Step 1 — choose photos
    function renderStepPhotos() {
        var wrap = el('div', 'tf2p-step');

        if (flow.productLocked && productsById[flow.productId]) {
            var lock = el('div', 'tf2p-lockbar');
            lock.appendChild(el('span', 'tf2p-lockbar__name', productsById[flow.productId].name + ' · ' + money(productsById[flow.productId].price) + ' each'));
            var change = btn('tf2p-linkbtn', 'Change size');
            change.addEventListener('click', function() { flow.productLocked = false; goStep(2); });
            lock.appendChild(change);
            wrap.appendChild(lock);
        }

        if (mode === 'public') {
            wrap.appendChild(buildFlowDropzone());
        }

        var pool = flow.pool && flow.pool.length ? flow.pool : poolAsList();
        flow.pool = pool;

        if (!pool.length) {
            wrap.appendChild(el('p', 'tf2p-loading', mode === 'public'
                ? 'Add your photos above to get started.'
                : 'Open your gallery and tap the print icon on a photo to start an order.'));
            return wrap;
        }

        var bar = el('div', 'tf2p-selbar');
        var all = btn('tf2p-linkbtn', 'Select all');
        all.addEventListener('click', function() {
            for (var i = 0; i < flow.pool.length; i++) {
                var k = flow.pool[i].key;
                if (!flow.selected[k]) { flow.selected[k] = true; if (flow.order.indexOf(k) < 0) flow.order.push(k); }
            }
            renderFlow();
        });
        var clear = btn('tf2p-linkbtn', 'Clear');
        clear.addEventListener('click', function() {
            flow.selected = {};
            flow.order = [];
            renderFlow();
        });
        var cnt = el('span', 'tf2p-selbar__count', selectedKeys().length + ' of ' + pool.length + ' selected');
        bar.appendChild(all);
        bar.appendChild(clear);
        bar.appendChild(cnt);
        wrap.appendChild(bar);

        var grid = el('div', 'tf2p-photogrid');
        for (var i = 0; i < pool.length; i++) {
            grid.appendChild(buildPhotoTile(pool[i]));
        }
        wrap.appendChild(grid);
        return wrap;
    }

    function buildPhotoTile(photo) {
        var on = !!flow.selected[photo.key];
        var tile = btn('tf2p-ptile' + (on ? ' tf2p-ptile--on' : ''), '');
        tile.setAttribute('aria-pressed', on ? 'true' : 'false');

        var img = document.createElement('img');
        img.alt = photo.filename || '';
        img.loading = 'lazy';
        img.src = photo.thumb_url || photo.url;
        tile.appendChild(img);

        var check = el('span', 'tf2p-ptile__check');
        check.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4"><polyline points="20 6 9 17 4 12"/></svg>';
        tile.appendChild(check);

        tile.addEventListener('click', function() {
            toggleSelect(photo.key);
            renderFlow();
        });
        return tile;
    }

    function buildFlowDropzone() {
        var dz = el('div', 'tf2p-flow__dz');
        dz.setAttribute('role', 'button');
        dz.setAttribute('tabindex', '0');
        dz.innerHTML =
            '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>' +
            '<span class="tf2p-flow__dz-title">Add photos</span>' +
            '<span class="tf2p-flow__dz-note">JPEG or PNG · up to ' + MAX_PUBLIC_FILES + ' photos · 25MB each</span>';

        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/png';
        input.multiple = true;
        input.style.display = 'none';
        dz.appendChild(input);

        dz.addEventListener('click', function() { input.click(); });
        dz.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
        });
        dz.addEventListener('dragover', function(e) { e.preventDefault(); dz.classList.add('tf2p-flow__dz--over'); });
        dz.addEventListener('dragleave', function() { dz.classList.remove('tf2p-flow__dz--over'); });
        dz.addEventListener('drop', function(e) {
            e.preventDefault();
            dz.classList.remove('tf2p-flow__dz--over');
            if (e.dataTransfer && e.dataTransfer.files) acceptFiles(e.dataTransfer.files, true);
        });
        input.addEventListener('change', function() {
            if (input.files) acceptFiles(input.files, true);
            input.value = '';
        });
        return dz;
    }

    // Step 2 — choose a size
    function renderStepProduct() {
        var wrap = el('div', 'tf2p-step');

        if (!products.length) {
            wrap.appendChild(el('p', 'tf2p-loading', productsLoaded ? 'No products are available right now.' : 'Loading products…'));
            return wrap;
        }

        var qtyRow = el('div', 'tf2p-qtyrow');
        qtyRow.appendChild(el('span', 'tf2p-qtyrow__label', 'Copies of each photo'));
        qtyRow.appendChild(buildStepper(flow.qtyPer, function(v) {
            flow.qtyPer = Math.max(1, Math.min(50, v));
            renderFlow();
        }));
        wrap.appendChild(qtyRow);

        for (var c = 0; c < CATEGORY_ORDER.length; c++) {
            var category = CATEGORY_ORDER[c];
            var group = [];
            for (var i = 0; i < products.length; i++) {
                if (products[i].category === category) group.push(products[i]);
            }
            if (!group.length) continue;

            var locked = (category === 'photobook') && !photobookUnlocked();

            wrap.appendChild(el('h4', 'tf2p-group__title', CATEGORY_LABELS[category] || category));
            if (category === 'photobook') {
                if (locked) {
                    wrap.appendChild(el('p', 'tf2p-group__note',
                        'Albums need at least ' + PHOTOBOOK_MIN + ' photos — you have ' +
                        selectedKeys().length + '. Add ' + (PHOTOBOOK_MIN - selectedKeys().length) +
                        ' more to unlock them.'));
                } else {
                    wrap.appendChild(el('p', 'tf2p-group__note', 'We design your album together with you after ordering — the selected photos come along.'));
                }
            }

            var grid = el('div', 'tf2p-prodgrid' + (locked ? ' tf2p-prodgrid--locked' : ''));
            for (var g = 0; g < group.length; g++) {
                var card = buildProductCard(group[g]);
                if (locked) {
                    card.disabled = true;
                    card.className += ' tf2p-prodcard--locked';
                    card.setAttribute('aria-disabled', 'true');
                    card.title = 'Select at least ' + PHOTOBOOK_MIN + ' photos to order an album';
                }
                grid.appendChild(card);
            }
            wrap.appendChild(grid);
        }
        return wrap;
    }

    /** Albums unlock once enough photos are in the batch. */
    function photobookUnlocked() {
        return selectedKeys().length >= PHOTOBOOK_MIN;
    }

    function buildProductCard(p) {
        var on = flow.productId === p.id;
        var card = btn('tf2p-prodcard' + (on ? ' tf2p-prodcard--on' : ''), '');
        card.setAttribute('aria-pressed', on ? 'true' : 'false');

        var art = el('span', 'tf2p-prodcard__art');
        var shape = el('span', 'tf2p-prodcard__shape');
        if (p.width_in && p.height_in) {
            shape.style.aspectRatio = p.width_in + ' / ' + p.height_in;
        }
        art.appendChild(shape);
        card.appendChild(art);

        var body = el('span', 'tf2p-prodcard__body');
        body.appendChild(el('span', 'tf2p-prodcard__name', p.name));
        body.appendChild(el('span', 'tf2p-prodcard__price', money(p.price) + ' each'));
        card.appendChild(body);

        card.addEventListener('click', function() {
            flow.productId = p.id;
            renderFlow();
        });
        return card;
    }

    function buildStepper(value, onChange) {
        var stepper = el('div', 'tf2p-stepper');
        var minus = btn('tf2p-stepper__btn', '−');
        minus.setAttribute('aria-label', 'Fewer');
        var qtyEl = el('span', 'tf2p-stepper__qty', String(value));
        var plus = btn('tf2p-stepper__btn tf2p-stepper__btn--plus', '+');
        plus.setAttribute('aria-label', 'More');
        minus.addEventListener('click', function(e) { e.stopPropagation(); onChange(value - 1); });
        plus.addEventListener('click', function(e) { e.stopPropagation(); onChange(value + 1); });
        stepper.appendChild(minus);
        stepper.appendChild(qtyEl);
        stepper.appendChild(plus);
        return stepper;
    }

    // Step 3 — review & crop
    function renderStepReview() {
        var wrap = el('div', 'tf2p-step');
        var product = productsById[flow.productId];

        if (!flow.items.length) {
            wrap.appendChild(el('p', 'tf2p-loading', 'Nothing to review — go back and choose some photos.'));
            return wrap;
        }

        var lock = el('div', 'tf2p-lockbar');
        lock.appendChild(el('span', 'tf2p-lockbar__name', product ? product.name + ' · ' + money(product.price) + ' each' : ''));
        var change = btn('tf2p-linkbtn', 'Change size');
        change.addEventListener('click', function() { flow.productLocked = false; goStep(2); });
        lock.appendChild(change);
        wrap.appendChild(lock);

        if (cropService.enabled) {
            wrap.appendChild(buildCropChoice());
        }

        var list = el('div', 'tf2p-reviewlist');
        for (var i = 0; i < flow.items.length; i++) {
            list.appendChild(buildReviewRow(flow.items[i], product));
        }
        wrap.appendChild(list);
        return wrap;
    }

    /** Free-by-default "we'll crop it for you" choice (fee is admin-set). */
    function buildCropChoice() {
        var box = el('div', 'tf2p-cropsvc');
        var feeTxt = cropService.fee > 0 ? money(cropService.fee) : 'Free';

        var opts = [
            { id: 'studio', title: cropService.label || 'Let us crop them for you',
              note: cropService.note || '', tag: feeTxt },
            { id: 'self', title: 'I\u2019ll adjust the crops myself',
              note: 'Use "Adjust crop" on any photo below.', tag: '' }
        ];

        for (var i = 0; i < opts.length; i++) {
            (function(o) {
                var on  = (flow.cropMode || 'studio') === o.id;
                var row = btn('tf2p-cropsvc__opt' + (on ? ' tf2p-cropsvc__opt--on' : ''), '');
                row.appendChild(el('span', 'tf2p-cropsvc__radio'));
                var body = el('span', 'tf2p-cropsvc__body');
                body.appendChild(el('span', 'tf2p-cropsvc__title', o.title));
                if (o.note) body.appendChild(el('span', 'tf2p-cropsvc__note', o.note));
                row.appendChild(body);
                if (o.tag) row.appendChild(el('span', 'tf2p-cropsvc__tag', o.tag));
                row.addEventListener('click', function() {
                    flow.cropMode = o.id;
                    renderFlow();
                });
                box.appendChild(row);
            })(opts[i]);
        }
        return box;
    }

    /** Did any batch in the cart ask us to do the cropping? */
    function cartUsesStudioCrop() {
        for (var i = 0; i < cart.length; i++) {
            if ((cart[i].crop_mode || 'studio') === 'studio') return true;
        }
        return false;
    }

    /** Fee charged for the studio-crop service on this order (0 when free). */
    function cropServiceFee() {
        if (!cropService.enabled) return 0;
        if (!flow || (flow.cropMode || 'studio') !== 'studio') return 0;
        return cropService.fee > 0 ? cropService.fee : 0;
    }

    function buildReviewRow(item, product) {
        var row = el('div', 'tf2p-review');
        var dims = photoDims[item.key];
        var sa = dims ? dims.w / dims.h : 0;
        var size = orientedSize(product, sa || 1);

        var thumb = cropThumb('tf2p-review__thumb', item.photo.thumb_url || item.photo.url, item.crop, size);
        row.appendChild(thumb);

        var info = el('div', 'tf2p-review__info');
        info.appendChild(el('span', 'tf2p-review__file', item.photo.filename || ''));

        var dpi = dims ? itemDpi({ product_id: flow.productId, crop: item.crop, source_w: dims.w, source_h: dims.h }) : 0;
        if (dpi && dpi < MIN_DPI) {
            info.appendChild(el('span', 'tf2p-review__warn', 'Low resolution for this size (~' + dpi + ' DPI) — try a smaller print or zoom out.'));
        }

        var actions = el('div', 'tf2p-review__actions');
        if (size) {
            var cropBtn = btn('tf2p-linkbtn', 'Adjust crop');
            cropBtn.addEventListener('click', function() { openCrop(item, product); });
            actions.appendChild(cropBtn);
        }
        var rm = btn('tf2p-linkbtn tf2p-linkbtn--danger', 'Remove');
        rm.addEventListener('click', function() {
            var idx = flow.items.indexOf(item);
            if (idx >= 0) flow.items.splice(idx, 1);
            delete flow.selected[item.key];
            renderFlow();
        });
        actions.appendChild(rm);
        info.appendChild(actions);
        row.appendChild(info);

        row.appendChild(buildStepper(item.qty, function(v) {
            item.qty = Math.max(1, Math.min(50, v));
            renderFlow();
        }));

        return row;
    }

    // ── Crop editor ────────────────────────────────────

    var crop = null; // {item, product, dims, frameW, frameH, baseW, baseH, zoom, ox, oy}

    function openCrop(item, product) {
        var dims = photoDims[item.key];
        if (!dims) {
            measurePhoto(item.photo, function(d) { if (d) openCrop(item, product); });
            return;
        }
        var sa = dims.w / dims.h;
        var size = orientedSize(product, sa);
        if (!size) return;

        var frame = document.getElementById('tf2p-crop-frame');
        var img   = document.getElementById('tf2p-crop-img');
        var title = document.getElementById('tf2p-crop-title');
        if (!frame || !img) return;

        title.textContent = 'Adjust crop — ' + (product ? product.name : '');
        frame.style.aspectRatio = size.w + ' / ' + size.h;

        cropEl.classList.add('tf2p-open');
        syncBackdrop();

        // Frame metrics are only reliable after the modal is laid out.
        var rect = frame.getBoundingClientRect();
        var Fw = rect.width || 280;
        var Fh = rect.height || (Fw * size.h / size.w);

        var baseW = Math.max(Fw, Fh * sa);
        var baseH = baseW / sa;

        crop = {
            item: item, product: product, dims: dims, size: size,
            Fw: Fw, Fh: Fh, baseW: baseW, baseH: baseH,
            zoom: 1, ox: 0, oy: 0
        };

        var saved = validCrop(item.crop) ? item.crop : defaultCrop(sa, size.w / size.h);
        var Dw = Fw / saved.w;
        crop.zoom = Math.max(1, Math.min(6, Dw / baseW));
        applyCropGeometry(-saved.x * (baseW * crop.zoom), -saved.y * (baseH * crop.zoom));

        img.src = item.photo.url || item.photo.thumb_url;

        var range = document.getElementById('tf2p-crop-range');
        if (range) range.value = String(crop.zoom);
    }

    function applyCropGeometry(ox, oy) {
        if (!crop) return;
        var Dw = crop.baseW * crop.zoom;
        var Dh = crop.baseH * crop.zoom;
        crop.ox = Math.min(0, Math.max(crop.Fw - Dw, ox));
        crop.oy = Math.min(0, Math.max(crop.Fh - Dh, oy));

        var img = document.getElementById('tf2p-crop-img');
        if (img) {
            img.style.width  = Dw + 'px';
            img.style.height = Dh + 'px';
            img.style.left   = crop.ox + 'px';
            img.style.top    = crop.oy + 'px';
        }
        updateCropWarning();
    }

    function currentCropValue() {
        if (!crop) return null;
        var Dw = crop.baseW * crop.zoom;
        var Dh = crop.baseH * crop.zoom;
        return {
            x: Math.max(0, Math.min(1, Math.round((-crop.ox / Dw) * 1e6) / 1e6)),
            y: Math.max(0, Math.min(1, Math.round((-crop.oy / Dh) * 1e6) / 1e6)),
            w: Math.max(0.01, Math.min(1, Math.round((crop.Fw / Dw) * 1e6) / 1e6)),
            h: Math.max(0.01, Math.min(1, Math.round((crop.Fh / Dh) * 1e6) / 1e6)),
            zoom: Math.round(crop.zoom * 1000) / 1000
        };
    }

    function updateCropWarning() {
        var warn = document.getElementById('tf2p-crop-warn');
        if (!warn || !crop) return;
        var c = currentCropValue();
        var dpi = Math.floor(Math.min(
            (crop.dims.w * c.w) / crop.size.w,
            (crop.dims.h * c.h) / crop.size.h
        ));
        if (dpi > 0 && dpi < MIN_DPI) {
            warn.textContent = 'Heads up: this crop prints at about ' + dpi + ' DPI. For the sharpest ' +
                crop.size.w + '×' + crop.size.h + '" print we recommend ' + MIN_DPI + ' DPI or more — zoom out, or choose a smaller size.';
            warn.style.display = '';
        } else {
            warn.style.display = 'none';
        }
    }

    function setCropZoom(z, anchorX, anchorY) {
        if (!crop) return;
        var next = Math.max(1, Math.min(6, z));
        if (next === crop.zoom) return;
        // Keep the anchor point (frame coords) visually fixed while zooming.
        var ax = anchorX === undefined ? crop.Fw / 2 : anchorX;
        var ay = anchorY === undefined ? crop.Fh / 2 : anchorY;
        var ratio = next / crop.zoom;
        var ox = ax - (ax - crop.ox) * ratio;
        var oy = ay - (ay - crop.oy) * ratio;
        crop.zoom = next;
        applyCropGeometry(ox, oy);
        var range = document.getElementById('tf2p-crop-range');
        if (range && Math.abs(parseFloat(range.value) - next) > 0.001) range.value = String(next);
    }

    function bindCropGestures() {
        var frame = document.getElementById('tf2p-crop-frame');
        var range = document.getElementById('tf2p-crop-range');
        if (!frame) return;

        var dragging = false;
        var startX = 0, startY = 0, startOx = 0, startOy = 0;
        var pinchDist = 0, pinchZoom = 1;

        function frameXY(clientX, clientY) {
            var r = frame.getBoundingClientRect();
            return { x: clientX - r.left, y: clientY - r.top };
        }

        frame.addEventListener('mousedown', function(e) {
            if (!crop) return;
            e.preventDefault();
            dragging = true;
            startX = e.clientX; startY = e.clientY;
            startOx = crop.ox; startOy = crop.oy;
        });
        document.addEventListener('mousemove', function(e) {
            if (!dragging || !crop) return;
            applyCropGeometry(startOx + (e.clientX - startX), startOy + (e.clientY - startY));
        });
        document.addEventListener('mouseup', function() { dragging = false; });

        frame.addEventListener('touchstart', function(e) {
            if (!crop) return;
            if (e.touches.length === 1) {
                dragging = true;
                startX = e.touches[0].clientX; startY = e.touches[0].clientY;
                startOx = crop.ox; startOy = crop.oy;
            } else if (e.touches.length === 2) {
                dragging = false;
                pinchDist = touchDist(e.touches);
                pinchZoom = crop.zoom;
            }
        }, { passive: true });

        frame.addEventListener('touchmove', function(e) {
            if (!crop) return;
            if (e.touches.length === 1 && dragging) {
                e.preventDefault();
                applyCropGeometry(startOx + (e.touches[0].clientX - startX), startOy + (e.touches[0].clientY - startY));
            } else if (e.touches.length === 2 && pinchDist > 0) {
                e.preventDefault();
                var d = touchDist(e.touches);
                var mid = frameXY(
                    (e.touches[0].clientX + e.touches[1].clientX) / 2,
                    (e.touches[0].clientY + e.touches[1].clientY) / 2
                );
                setCropZoom(pinchZoom * (d / pinchDist), mid.x, mid.y);
            }
        }, { passive: false });

        frame.addEventListener('touchend', function(e) {
            if (!e.touches || e.touches.length < 2) pinchDist = 0;
            if (!e.touches || !e.touches.length) dragging = false;
        });

        frame.addEventListener('wheel', function(e) {
            if (!crop) return;
            e.preventDefault();
            var at = frameXY(e.clientX, e.clientY);
            setCropZoom(crop.zoom * (e.deltaY < 0 ? 1.08 : 1 / 1.08), at.x, at.y);
        }, { passive: false });

        if (range) {
            range.addEventListener('input', function() {
                setCropZoom(parseFloat(range.value) || 1);
            });
        }
    }

    function touchDist(touches) {
        var dx = touches[0].clientX - touches[1].clientX;
        var dy = touches[0].clientY - touches[1].clientY;
        return Math.sqrt(dx * dx + dy * dy);
    }

    function resetCrop() {
        if (!crop) return;
        var sa = crop.dims.w / crop.dims.h;
        var def = defaultCrop(sa, crop.size.w / crop.size.h);
        crop.zoom = 1;
        applyCropGeometry(-def.x * crop.baseW, -def.y * crop.baseH);
        var range = document.getElementById('tf2p-crop-range');
        if (range) range.value = '1';
    }

    function saveCrop() {
        if (!crop) { closeCrop(); return; }
        crop.item.crop = currentCropValue();
        closeCrop();
        renderFlow();
    }

    function closeCrop() {
        if (!cropEl) return;
        cropEl.classList.remove('tf2p-open');
        crop = null;
        syncBackdrop();
    }

    // ── Cart rendering ─────────────────────────────────

    function renderCart() {
        var list     = document.getElementById('tf2p-cart-items');
        var empty    = document.getElementById('tf2p-cart-empty');
        var form     = document.getElementById('tf2p-checkout-form');
        var subRow   = document.getElementById('tf2p-subtotal-row');
        var pickupEl = document.getElementById('tf2p-pickup-note');
        var addRow   = drawer ? drawer.querySelector('.tf2p-drawer__addrow') : null;
        if (!list) return;

        list.innerHTML = '';

        if (!cart.length) {
            if (empty) empty.style.display = '';
            if (form) form.style.display = 'none';
            if (subRow) subRow.style.display = 'none';
            if (pickupEl) pickupEl.style.display = 'none';
            if (addRow) {
                addRow.style.display = '';
                var addBtn = document.getElementById('tf2p-add-size');
                if (addBtn) addBtn.style.display = 'none';
            }
            return;
        }
        if (empty) empty.style.display = 'none';
        if (form) form.style.display = '';
        if (subRow) subRow.style.display = '';
        if (addRow) {
            addRow.style.display = '';
            var addBtn2 = document.getElementById('tf2p-add-size');
            if (addBtn2) addBtn2.style.display = '';
        }

        var batches = cartBatches();
        for (var b = 0; b < batches.length; b++) {
            list.appendChild(buildBatchCard(batches[b]));
        }

        var subEl = document.getElementById('tf2p-subtotal');
        if (subEl) subEl.textContent = money(subtotal());

        if (pickupEl) {
            if (pickupNote) {
                pickupEl.textContent = pickupNote;
                pickupEl.style.display = '';
            } else {
                pickupEl.style.display = 'none';
            }
        }
    }

    function buildBatchCard(batch) {
        var card = el('div', 'tf2p-batch');
        card.id = 'tf2p-batch-' + batch.id;

        var head = el('div', 'tf2p-batch__head');
        var info = el('div', 'tf2p-batch__info');
        info.appendChild(el('span', 'tf2p-batch__name', batch.product_name));
        info.appendChild(el('span', 'tf2p-batch__meta',
            batch.items.length + (batch.items.length === 1 ? ' photo' : ' photos') +
            ' · ' + batch.qty + (batch.qty === 1 ? ' print' : ' prints') +
            ' · ' + money(batch.total)));
        head.appendChild(info);

        var actions = el('div', 'tf2p-batch__actions');
        var edit = btn('tf2p-linkbtn', 'Edit');
        edit.addEventListener('click', function() { editBatch(batch.id); });
        actions.appendChild(edit);
        var rm = btn('tf2p-linkbtn tf2p-linkbtn--danger', 'Remove');
        rm.addEventListener('click', function() { removeBatch(batch.id); });
        actions.appendChild(rm);
        head.appendChild(actions);
        card.appendChild(head);

        var product = productsById[batch.product_id];
        var strip = el('div', 'tf2p-batch__strip');
        for (var i = 0; i < batch.items.length; i++) {
            strip.appendChild(buildCartItem(batch.items[i], product));
        }
        card.appendChild(strip);

        // Long batches stay compact until asked to open, then scroll
        // in place rather than stretching the summary down the page.
        if (batch.items.length > 3) {
            strip.className = 'tf2p-batch__strip tf2p-batch__strip--more';
            var toggle = btn('tf2p-batch__toggle', 'Show all ' + batch.items.length + ' photos');
            toggle.addEventListener('click', function() {
                var open = strip.className.indexOf('--open') > -1;
                strip.className = 'tf2p-batch__strip' + (open ? ' tf2p-batch__strip--more' : ' tf2p-batch__strip--open');
                toggle.textContent = open ? ('Show all ' + batch.items.length + ' photos') : 'Show fewer';
            });
            card.appendChild(toggle);
        }
        return card;
    }

    function buildCartItem(item, product) {
        var cell = el('div', 'tf2p-citem');
        var src = item.thumb_url || item.photo_url;
        if (!src && mode === 'public' && item.file_index >= 0 && publicFiles[item.file_index]) {
            src = publicFiles[item.file_index].previewUrl;
        }
        var sa = (item.source_w && item.source_h) ? item.source_w / item.source_h : 0;
        var size = orientedSize(product, sa || 1);
        cell.appendChild(cropThumb('tf2p-citem__thumb', src, item.crop, size));

        var dpi = itemDpi(item);
        if (dpi && dpi < MIN_DPI) {
            var flag = el('span', 'tf2p-citem__warn', '!');
            flag.title = 'Low resolution for this size (~' + dpi + ' DPI)';
            cell.appendChild(flag);
        }

        // Name / size / filename — without this the steppers read as
        // bare +/- boxes with no indication of what they change.
        var info = el('div', 'tf2p-citem__info');
        info.appendChild(el('span', 'tf2p-citem__name', (product && product.name) ? product.name : (item.product_name || 'Print')));
        var metaBits = [];
        if (item.filename) metaBits.push(item.filename);
        if (product) metaBits.push(money(product.price) + ' each');
        if (metaBits.length) info.appendChild(el('span', 'tf2p-citem__meta', metaBits.join(' · ')));
        cell.appendChild(info);

        var qtyWrap = el('div', 'tf2p-citem__qty');
        var minus = btn('tf2p-citem__step', '−');
        minus.setAttribute('aria-label', 'Fewer');
        var num = el('span', 'tf2p-citem__num', String(item.qty));
        var plus = btn('tf2p-citem__step', '+');
        plus.setAttribute('aria-label', 'More');
        minus.addEventListener('click', function() {
            item.qty -= 1;
            if (item.qty <= 0) {
                var idx = indexOfItem(item);
                if (idx >= 0) cart.splice(idx, 1);
            }
            notifyCountChange();
            renderCart();
        });
        plus.addEventListener('click', function() {
            item.qty = Math.min(50, item.qty + 1);
            notifyCountChange();
            renderCart();
        });
        qtyWrap.appendChild(minus);
        qtyWrap.appendChild(num);
        qtyWrap.appendChild(plus);
        cell.appendChild(qtyWrap);

        if (product) {
            cell.appendChild(el('span', 'tf2p-citem__line', money(product.price * item.qty)));
        }

        return cell;
    }

    // ── Checkout ───────────────────────────────────────

    var submitting = false;
    var shipMode   = 'pickup';

    function val(id) {
        var e = document.getElementById(id);
        return e ? trim(e.value) : '';
    }

    /** Shipping block — empty object when the customer is collecting. */
    function collectShipping() {
        if (shipMode !== 'delivery') return null;
        return {
            address1: val('tf2p-ship-addr1'),
            address2: val('tf2p-ship-addr2'),
            city:     val('tf2p-ship-city'),
            region:   val('tf2p-ship-region'),
            note:     val('tf2p-ship-note')
        };
    }

    function showCheckoutError(msg) {
        var errEl = document.getElementById('tf2p-checkout-error');
        if (errEl) {
            errEl.textContent = msg;
            errEl.style.display = msg ? '' : 'none';
        }
    }

    function onCheckout(e) {
        e.preventDefault();
        if (submitting || !cart.length) return;

        var name  = trim(document.getElementById('tf2p-cust-name').value);
        var email = trim(document.getElementById('tf2p-cust-email').value);
        var phone = trim(document.getElementById('tf2p-cust-phone').value);
        var notes = document.getElementById('tf2p-cust-notes').value || '';
        var hp    = document.getElementById('tf2p-hp').value || '';

        if (!name || !email || email.indexOf('@') < 1) {
            showCheckoutError('Please enter your name and a valid email address.');
            return;
        }
        if (shipMode === 'delivery') {
            var sh = collectShipping();
            if (!sh || !sh.address1 || !sh.city) {
                showCheckoutError('Please add the street address and town for delivery.');
                return;
            }
        }
        showCheckoutError('');

        submitting = true;
        var button = document.getElementById('tf2p-checkout-btn');
        var btnLabel = button ? button.textContent : '';
        if (button) {
            button.disabled = true;
            button.textContent = mode === 'public' ? 'Uploading photos…' : 'Placing order…';
        }

        var done = function(data) {
            submitting = false;
            if (button) {
                button.disabled = false;
                button.textContent = btnLabel;
            }
            if (data && data.ok && data.order_ref) {
                showSuccess(data);
                cart = [];
                lastBatchPhotos = [];
                notifyCountChange();
                if (mode === 'public') resetPublicPage();
            } else {
                var msg = (data && (data.message || data.code))
                    ? (data.message || 'Something went wrong. Please try again.')
                    : 'Network error. Please check your connection and try again.';
                showCheckoutError(msg);
            }
        };

        var i;
        if (mode === 'gallery') {
            var payload = [];
            for (i = 0; i < cart.length; i++) {
                payload.push({
                    product_id: cart[i].product_id,
                    qty:        cart[i].qty,
                    filename:   cart[i].filename,
                    photo_url:  cart[i].photo_url,
                    thumb_url:  cart[i].thumb_url,
                    crop:       cart[i].crop || null,
                    source_w:   cart[i].source_w || 0,
                    source_h:   cart[i].source_h || 0,
                    batch_id:   cart[i].batch_id || '',
                    crop_mode:  cart[i].crop_mode || 'studio'
                });
            }
            fetch(cfg.restUrl + 'order', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    session_code: code,
                    customer_name: name,
                    customer_email: email,
                    customer_phone: phone,
                    notes: notes,
                    website: hp,
                    crop_service: cartUsesStudioCrop() ? 1 : 0,
                    fulfilment: shipMode,
                    shipping: collectShipping(),
                    items: payload
                })
            })
            .then(function(r) { return r.json(); })
            .then(done)
            ['catch'](function() { done(null); });
        } else {
            // Public: multipart — only the files that are actually in the cart.
            var usedIdx = [];
            var j;
            for (i = 0; i < cart.length; i++) {
                var fi = cart[i].file_index;
                if (fi >= 0 && publicFiles[fi] && usedIdx.indexOf(fi) < 0) usedIdx.push(fi);
            }
            if (!usedIdx.length) {
                done(null);
                showCheckoutError('Please add at least one photo with a product.');
                return;
            }

            var fd = new FormData();
            var remap = {};
            for (i = 0; i < usedIdx.length; i++) {
                remap[usedIdx[i]] = i;
                fd.append('photos[]', publicFiles[usedIdx[i]].file, publicFiles[usedIdx[i]].name);
            }
            var items = [];
            for (i = 0; i < cart.length; i++) {
                if (cart[i].file_index < 0 || remap[cart[i].file_index] === undefined) continue;
                items.push({
                    file_index: remap[cart[i].file_index],
                    product_id: cart[i].product_id,
                    qty:        cart[i].qty,
                    crop:       cart[i].crop || null,
                    source_w:   cart[i].source_w || 0,
                    source_h:   cart[i].source_h || 0,
                    batch_id:   cart[i].batch_id || ''
                });
            }
            fd.append('items', JSON.stringify(items));
            fd.append('customer_name', name);
            fd.append('customer_email', email);
            fd.append('customer_phone', phone);
            fd.append('notes', notes);
            fd.append('website', hp);

            fetch(cfg.restUrl + 'public-order', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(done)
                ['catch'](function() { done(null); });
        }
    }

    function showSuccess(data) {
        var body    = document.getElementById('tf2p-drawer-body');
        var success = document.getElementById('tf2p-success');
        var refEl   = document.getElementById('tf2p-success-ref');
        var payWrap = document.getElementById('tf2p-success-pay');
        var payText = document.getElementById('tf2p-success-pay-text');
        var noteEl  = document.getElementById('tf2p-success-note');
        var portalBtn = document.getElementById('tf2p-success-portal');

        if (body) body.style.display = 'none';
        if (refEl) refEl.textContent = data.order_ref;

        if (!portalBtn && success) {
            portalBtn = document.createElement('a');
            portalBtn.id = 'tf2p-success-portal';
            portalBtn.className = 'tf2p-btn tf2p-btn--gold tf2p-btn--full';
            portalBtn.style.marginBottom = '10px';
            portalBtn.textContent = 'View your order / Make payment';
            var doneBtn = document.getElementById('tf2p-success-done');
            if (doneBtn && doneBtn.parentNode) doneBtn.parentNode.insertBefore(portalBtn, doneBtn);
            else success.appendChild(portalBtn);
        }
        if (portalBtn) {
            if (data.portal_url) {
                portalBtn.href = data.portal_url;
                portalBtn.style.display = '';
            } else {
                portalBtn.style.display = 'none';
            }
        }

        if (payWrap && payText) {
            if (data.payment_instructions) {
                payText.textContent = data.payment_instructions;
                payWrap.style.display = '';
            } else {
                payWrap.style.display = 'none';
            }
        }
        if (noteEl) noteEl.textContent = data.pickup_note || '';
        if (success) success.style.display = '';
        openDrawer();
    }

    // ── Public storefront shell ────────────────────────

    function acceptFiles(fileList, openAfter) {
        var errors = [];
        var added = [];
        for (var i = 0; i < fileList.length; i++) {
            var file = fileList[i];
            if (publicFiles.length >= MAX_PUBLIC_FILES) {
                errors.push('You can add up to ' + MAX_PUBLIC_FILES + ' photos per order.');
                break;
            }
            if (!/^image\/(jpeg|png)$/.test(file.type)) {
                errors.push(file.name + ': only JPEG or PNG.');
                continue;
            }
            if (file.size > MAX_FILE_BYTES) {
                errors.push(file.name + ': larger than 25MB.');
                continue;
            }
            var entry = { file: file, name: file.name, previewUrl: URL.createObjectURL(file) };
            publicFiles.push(entry);
            var index = publicFiles.length - 1;
            var photo = normalizePhoto({
                filename: entry.name,
                url: entry.previewUrl,
                thumb_url: entry.previewUrl,
                file_index: index
            });
            addToPool([photo]);
            added.push(photo);
            measurePhoto(photo, function() {});
        }
        if (errors.length) window.alert(errors.join('\n'));

        renderUploadGrid();
        if (flow) {
            flow.pool = poolAsList();
            for (var a = 0; a < added.length; a++) {
                var k = added[a].key;
                if (!flow.selected[k]) { flow.selected[k] = true; flow.order.push(k); }
            }
            if (isFlowOpen()) renderFlow();
        }
        if (openAfter && !isFlowOpen() && added.length) {
            startFlow({ step: 1, photos: poolAsList(), selected: keysOf(added) });
        }
        return added;
    }

    function keysOf(list) {
        var out = [];
        for (var i = 0; i < list.length; i++) out.push(list[i].key);
        return out;
    }

    function renderUploadGrid() {
        var grid = document.getElementById('tf2p-upload-grid');
        if (!grid) return;
        grid.innerHTML = '';
        for (var i = 0; i < publicFiles.length; i++) {
            grid.appendChild(buildUploadTile(publicFiles[i], i));
        }
        var startBtn = document.getElementById('tf2p-start-order');
        if (startBtn) startBtn.style.display = publicFiles.length ? '' : 'none';
    }

    function buildUploadTile(entry, index) {
        var tile = el('div', 'tf2-prints__tile');
        var img = document.createElement('img');
        img.src = entry.previewUrl;
        img.alt = entry.name;
        tile.appendChild(img);

        var key = 'f:' + index;
        var n = 0;
        for (var i = 0; i < cart.length; i++) {
            if ((cart[i].key || photoKey(cart[i])) === key) n += cart[i].qty;
        }
        var badge = el('span', 'tf2-prints__tile-badge', String(n));
        badge.style.display = n > 0 ? '' : 'none';
        tile.appendChild(badge);

        tile.addEventListener('click', function() {
            startFlow({ step: 2, photos: poolAsList(), selected: [key] });
        });
        return tile;
    }

    function initPublicPage() {
        var dropzone = document.getElementById('tf2p-dropzone');
        var input    = document.getElementById('tf2p-file-input');
        var startBtn = document.getElementById('tf2p-start-order');

        loadProducts(null);

        // Storefront product cards start the order with that size locked in.
        var cards = document.querySelectorAll('[data-tf2p-product]');
        for (var i = 0; i < cards.length; i++) {
            (function(card) {
                card.addEventListener('click', function(e) {
                    e.preventDefault();
                    var pid = card.getAttribute('data-tf2p-product');
                    startWithProduct(pid);
                });
            })(cards[i]);
        }

        var startLinks = document.querySelectorAll('[data-tf2p-start]');
        for (i = 0; i < startLinks.length; i++) {
            (function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    startFlow({ step: 1, photos: poolAsList(), selected: [] });
                });
            })(startLinks[i]);
        }

        if (startBtn) {
            startBtn.addEventListener('click', function() {
                startFlow({ step: 1, photos: poolAsList(), selected: [] });
            });
        }

        if (dropzone && input) {
            dropzone.addEventListener('click', function() { input.click(); });
            dropzone.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
            });
            dropzone.addEventListener('dragover', function(e) {
                e.preventDefault();
                dropzone.classList.add('tf2-prints__dropzone--over');
            });
            dropzone.addEventListener('dragleave', function() {
                dropzone.classList.remove('tf2-prints__dropzone--over');
            });
            dropzone.addEventListener('drop', function(e) {
                e.preventDefault();
                dropzone.classList.remove('tf2-prints__dropzone--over');
                if (e.dataTransfer && e.dataTransfer.files) acceptFiles(e.dataTransfer.files, true);
            });
            input.addEventListener('change', function() {
                if (input.files) acceptFiles(input.files, true);
                input.value = '';
            });
        }

        countCallbacks.push(renderUploadGrid);
        renderUploadGrid();
    }

    function startWithProduct(productId) {
        buildOverlay();
        loadProducts(function() {
            if (!productsById[productId]) {
                startFlow({ step: 1, photos: poolAsList(), selected: [] });
                return;
            }
            startFlow({
                step: 1,
                photos: poolAsList(),
                selected: [],
                productId: productId,
                productLocked: true
            });
        });
    }

    function resetPublicPage() {
        for (var i = 0; i < publicFiles.length; i++) {
            try { URL.revokeObjectURL(publicFiles[i].previewUrl); } catch (e) {}
        }
        publicFiles = [];
        photoPool = [];
        photoPoolKeys = {};
        photoDims = {};
        renderUploadGrid();
    }

    // ── Storefront scroll reveals ──────────────────────

    function initReveals() {
        var app = document.getElementById('tf2-prints-app');
        var nodes = document.querySelectorAll('.tf2-reveal');
        if (!app || !nodes.length) return;
        if (typeof window.IntersectionObserver === 'undefined') return;
        app.className += ' tf2-shop--anim';
        var io = new IntersectionObserver(function(entries) {
            for (var j = 0; j < entries.length; j++) {
                if (entries[j].isIntersecting) {
                    entries[j].target.classList.add('tf2-reveal--in');
                    io.unobserve(entries[j].target);
                }
            }
        }, { threshold: 0.12 });
        for (var k = 0; k < nodes.length; k++) io.observe(nodes[k]);
    }

    // ── Order portal: receipt + client-side OCR ────────

    function ocrExtract(text) {
        var out = { amount: null, ref: null };
        if (!text) return out;

        var labeled = text.match(/(?:transfer\s+amount|amount)[\s:]*(?:TTD|\$)?\s?(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)/i);
        if (labeled && labeled[1]) {
            out.amount = parseFloat(labeled[1].replace(/[^\d.]/g, ''));
        } else {
            var amounts = [];
            var m = text.match(/(?:TTD|\$)\s?(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)/gi);
            var fb = text.match(/\b\d{1,3}(?:,\d{3})*\.\d{2}\b/g);
            var i;
            if (m) {
                for (i = 0; i < m.length; i++) amounts.push(parseFloat(m[i].replace(/[^\d.]/g, '')));
            } else if (fb) {
                for (i = 0; i < fb.length; i++) amounts.push(parseFloat(fb[i].replace(/,/g, '')));
            }
            var best = 0;
            for (i = 0; i < amounts.length; i++) {
                if (!isNaN(amounts[i]) && amounts[i] > best) best = amounts[i];
            }
            if (best > 0) out.amount = best;
        }

        var refMatch = text.match(/(?:ref(?:erence)?\s*(?:no\.?|#|number)?|transaction\s*(?:id|no\.?|#)?|trx\s*(?:id|no\.?|#)?)\s*[:\-#]?\s*([a-z0-9]{6,15})/i);
        if (refMatch && refMatch[1]) out.ref = refMatch[1].toUpperCase();
        return out;
    }

    function runReceiptOcr(file, cb) {
        if (!window.Tesseract) { cb(null, null); return; }
        var worker = null;
        try {
            window.Tesseract.createWorker('eng')
                .then(function(w) {
                    worker = w;
                    return w.recognize(file);
                })
                .then(function(ret) {
                    var text = ret && ret.data ? ret.data.text : '';
                    if (worker) { try { worker.terminate(); } catch (e) {} }
                    var got = ocrExtract(text);
                    cb(got.amount, got.ref);
                })
                ['catch'](function() {
                    if (worker) { try { worker.terminate(); } catch (e) {} }
                    cb(null, null);
                });
        } catch (err) {
            cb(null, null);
        }
    }

    function initPortal() {
        var pick   = document.getElementById('tf2pp-receipt-pick');
        var input  = document.getElementById('tf2pp-receipt-input');
        var flowWrap = document.getElementById('tf2pp-receipt-flow');
        if (!pick || !input || !flowWrap) return;

        var preview  = document.getElementById('tf2pp-receipt-preview');
        var note     = document.getElementById('tf2pp-receipt-note');
        var amount   = document.getElementById('tf2pp-receipt-amount');
        var errEl    = document.getElementById('tf2pp-receipt-error');
        var submit   = document.getElementById('tf2pp-receipt-submit');
        var doneEl   = document.getElementById('tf2pp-receipt-done');

        var pendingFile = null;
        var pendingRef  = null;
        var busy = false;

        function showError(msg) {
            if (!errEl) return;
            errEl.textContent = msg || '';
            errEl.style.display = msg ? '' : 'none';
        }

        pick.addEventListener('click', function() { input.click(); });

        input.addEventListener('change', function() {
            if (!input.files || !input.files.length) return;
            var file = input.files[0];
            if (!/^image\//.test(file.type)) {
                showError('Please choose an image (a screenshot or photo of your receipt).');
                return;
            }
            if (file.size > 15 * 1024 * 1024) {
                showError('Receipt images must be under 15MB.');
                return;
            }
            showError('');
            pendingFile = file;
            pendingRef = null;

            if (preview) {
                try { preview.src = URL.createObjectURL(file); } catch (e) {}
            }
            flowWrap.style.display = '';
            if (doneEl) doneEl.style.display = 'none';
            pick.textContent = 'Choose a different image';
            if (note) note.textContent = 'Reading your receipt…';
            if (amount) amount.value = '';
            if (submit) submit.disabled = true;

            runReceiptOcr(file, function(readAmount, readRef) {
                pendingRef = readRef;
                if (submit) submit.disabled = false;
                if (!amount || !note) return;
                if (readAmount && readAmount > 0) {
                    amount.value = readAmount.toFixed(2);
                    note.textContent = 'We read TT$' + readAmount.toFixed(2) + ' from your receipt — confirm or correct it below, then submit.';
                } else {
                    amount.value = '';
                    note.textContent = "We couldn't read the amount automatically. Please type the amount you transferred, then submit.";
                }
                try { amount.focus(); } catch (e) {}
            });
        });

        if (submit) {
            submit.addEventListener('click', function() {
                if (busy || !pendingFile) return;
                var val = parseFloat(amount && amount.value ? amount.value : '0');
                if (isNaN(val) || val <= 0) {
                    showError('Please enter the amount you transferred.');
                    return;
                }
                showError('');
                busy = true;
                submit.disabled = true;
                var oldLabel = submit.textContent;
                submit.textContent = 'Uploading…';

                var fd = new FormData();
                fd.append('receipt', pendingFile, pendingFile.name || 'receipt.jpg');
                fd.append('order', cfg.orderRef || '');
                fd.append('t', cfg.portalToken || '');
                fd.append('ocr_amounts', 'TTD ' + val.toFixed(2));
                fd.append('confirmed_amount', val.toFixed(2));
                if (pendingRef) fd.append('ocr_reference', pendingRef);

                fetch(cfg.restUrl + 'portal-receipt', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        busy = false;
                        if (data && data.ok) {
                            flowWrap.style.display = 'none';
                            pick.style.display = 'none';
                            if (doneEl) doneEl.style.display = '';
                        } else {
                            submit.disabled = false;
                            submit.textContent = oldLabel;
                            showError((data && data.message) ? data.message : 'Something went wrong. Please try again.');
                        }
                    })
                    ['catch'](function() {
                        busy = false;
                        submit.disabled = false;
                        submit.textContent = oldLabel;
                        showError('Network error. Please check your connection and try again.');
                    });
            });
        }
    }

    // ── Internal UI sync ───────────────────────────────

    function updateInternalUI() {
        var title = document.getElementById('tf2p-drawer-title');
        if (title) {
            var c = cartCount();
            title.textContent = c > 0 ? 'Your Prints (' + c + ')' : 'Your Prints';
        }
        updateFab();
        if (isDrawerOpen()) renderCart();
    }

    // ── Public API ─────────────────────────────────────

    window.TwellerPrints = {
        ready: true,
        enabled: true,

        /** Batch flow. photos: [{id, filename, url, thumb_url}], opts: {productId} */
        openBatchOrder: function(photos, opts) {
            opts = opts || {};
            var list = [];
            var i;
            if (photos && photos.length) {
                for (i = 0; i < photos.length; i++) {
                    var p = normalizePhoto(photos[i]);
                    if (p) list.push(p);
                }
            }
            buildOverlay();
            addToPool(list);
            var keys = keysOf(list);
            loadProducts(function() {
                var productId = opts.productId && productsById[opts.productId] ? opts.productId : '';
                startFlow({
                    step: productId ? 3 : (keys.length ? 2 : 1),
                    photos: poolAsList(),
                    selected: keys,
                    productId: productId,
                    qtyPer: opts.qty || 1
                });
                if (productId && flow) {
                    buildFlowItems();
                    renderFlow();
                }
            });
        },

        /** Single photo — the legacy per-photo print icon. */
        openOrder: function(photo) {
            window.TwellerPrints.openBatchOrder(photo ? [photo] : [], {});
        },

        /** Back-compat alias for tracker.js. */
        openPicker: function(photo) {
            window.TwellerPrints.openOrder(photo);
        },

        /** Seed the album so "Add another size" can offer every photo. */
        setPhotos: function(photos) {
            if (!photos || !photos.length) return;
            var list = [];
            for (var i = 0; i < photos.length; i++) {
                var p = normalizePhoto(photos[i]);
                if (p) list.push(p);
            }
            addToPool(list);
            if (flow && isFlowOpen() && flow.step === 1) {
                flow.pool = poolAsList();
                renderFlow();
            }
        },

        openStore: function() { openDrawer(); },
        openCart:  function() { openDrawer(); },

        getCount: function() { return cartCount(); },
        getCountFor: function(filename) { return countFor(filename); },
        onCountChange: function(cb) {
            if (typeof cb === 'function') countCallbacks.push(cb);
        }
    };

    // ── Boot ───────────────────────────────────────────

    if (mode === 'portal') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initPortal);
        } else {
            initPortal();
        }
        return;
    }

    loadCart();
    buildOverlay();
    loadProducts(null);

    if (mode === 'public') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                initPublicPage();
                initReveals();
            });
        } else {
            initPublicPage();
            initReveals();
        }
    }

    // Auto-open the cart when the delivery email links with ?prints=1
    if (mode === 'gallery' && /[?&]prints=1(&|$)/.test(window.location.search)) {
        setTimeout(function() { openDrawer(); }, 600);
    }
})();
