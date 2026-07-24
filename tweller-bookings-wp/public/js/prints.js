/**
 * Tweller Flow — Print Store (client UI)
 *
 * Two modes (twellerFlow2Prints.mode):
 *   'gallery' — floats over the delivered gallery; tracker.js calls in
 *               through window.TwellerPrints (openPicker / openStore).
 *   'public'  — binds to the [tweller_prints] page shell (upload + order).
 *
 * ES5 only, matching tracker.js style.
 */
(function() {
    'use strict';

    var cfg = window.twellerFlow2Prints;
    if (!cfg || !cfg.restUrl) return;

    var mode     = cfg.mode === 'gallery' ? 'gallery' : 'public';
    var code     = cfg.code || '';
    var currency = cfg.currency || 'TT$';
    var cartKey  = mode === 'gallery' ? 'tf2_prints_cart_' + code : '';

    var products      = [];
    var productsById  = {};
    var pickupNote    = '';
    var productsLoaded = false;
    var productsFailed = false;

    // Cart items: {product_id, product_name, price, qty, filename,
    //              photo_url, thumb_url, crop, file_index (public only)}
    var cart = [];
    var countCallbacks = [];

    // Public-mode uploads: {file, previewUrl, name}
    var publicFiles = [];

    // Picker state
    var pickerPhoto = null;   // {filename, url, thumb_url, file_index}
    var photoRatio  = 0;      // natural w/h of current picker photo
    var selectedProductId = '';

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

    function sizeLabel(p) {
        if (!p.width_in || !p.height_in) return '';
        return p.width_in + '×' + p.height_in + '"';
    }

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

    function findCartItem(filename, productId, fileIndex) {
        for (var i = 0; i < cart.length; i++) {
            var it = cart[i];
            if (it.product_id !== productId) continue;
            if (mode === 'public') {
                if (it.file_index === fileIndex) return it;
            } else if (it.filename === filename) {
                return it;
            }
        }
        return null;
    }

    function setQty(photo, productId, qty) {
        var item = findCartItem(photo.filename, productId, photo.file_index);
        if (qty <= 0) {
            if (item) cart.splice(indexOfItem(item), 1);
        } else if (item) {
            item.qty = Math.min(50, qty);
        } else {
            var p = productsById[productId];
            if (!p) return;
            cart.push({
                product_id:   p.id,
                product_name: p.name,
                price:        p.price,
                qty:          Math.min(50, qty),
                filename:     photo.filename || '',
                photo_url:    photo.url || '',
                thumb_url:    photo.thumb_url || '',
                crop:         '',
                file_index:   typeof photo.file_index === 'number' ? photo.file_index : -1
            });
        }
        notifyCountChange();
    }

    function indexOfItem(item) {
        for (var i = 0; i < cart.length; i++) if (cart[i] === item) return i;
        return -1;
    }

    // ── Load products ──────────────────────────────────

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
                    buildProductRows();
                }
                if (cb) cb();
            })
            .catch(function() {
                productsFailed = true;
                var wrap = document.getElementById('tf2p-product-groups');
                if (wrap && !productsLoaded) {
                    wrap.innerHTML = '';
                    wrap.appendChild(el('p', 'tf2p-loading', 'Could not load products. Please refresh and try again.'));
                }
                if (cb) cb();
            });
    }

    // ── Build overlay DOM (backdrop + picker sheet + cart drawer) ──

    var root, backdrop, sheet, drawer;

    function buildOverlay() {
        if (root) return;
        root = el('div', 'tf2p-root');

        backdrop = el('div', 'tf2p-backdrop');
        backdrop.addEventListener('click', function() {
            closePicker();
            closeDrawer();
        });

        // ── Product picker bottom sheet ──
        sheet = el('div', 'tf2p-sheet');
        sheet.setAttribute('role', 'dialog');
        sheet.setAttribute('aria-label', 'Choose print products');
        sheet.innerHTML =
            '<div class="tf2p-sheet__handle"></div>' +
            '<button type="button" class="tf2p-close" id="tf2p-sheet-close" aria-label="Close">&times;</button>' +
            '<div class="tf2p-sheet__scroll">' +
                '<div class="tf2p-preview-wrap">' +
                    '<div class="tf2p-preview">' +
                        '<div class="tf2p-preview__frame" id="tf2p-preview-frame">' +
                            '<img id="tf2p-preview-img" src="" alt="Print preview">' +
                        '</div>' +
                    '</div>' +
                    '<div class="tf2p-preview__meta">' +
                        '<span class="tf2p-preview__size" id="tf2p-preview-size"></span>' +
                        '<span class="tf2p-crop-note" id="tf2p-crop-note" style="display:none;"></span>' +
                    '</div>' +
                '</div>' +
                '<div class="tf2p-groups" id="tf2p-product-groups">' +
                    '<p class="tf2p-loading">Loading products…</p>' +
                '</div>' +
            '</div>' +
            '<div class="tf2p-sheet__footer">' +
                '<button type="button" class="tf2p-btn tf2p-btn--dark tf2p-btn--full" id="tf2p-view-cart"></button>' +
            '</div>';

        // ── Cart drawer ──
        drawer = el('div', 'tf2p-drawer');
        drawer.setAttribute('role', 'dialog');
        drawer.setAttribute('aria-label', 'Print cart');
        drawer.innerHTML =
            '<div class="tf2p-drawer__head">' +
                '<h3 class="tf2p-drawer__title" id="tf2p-drawer-title">Your Prints</h3>' +
                '<button type="button" class="tf2p-close" id="tf2p-drawer-close" aria-label="Close">&times;</button>' +
            '</div>' +
            '<div class="tf2p-drawer__body" id="tf2p-drawer-body">' +
                '<div id="tf2p-cart-items"></div>' +
                '<p class="tf2p-empty" id="tf2p-cart-empty" style="display:none;">Your cart is empty.<br>Tap the <span class="tf2p-empty__icon">' + printerSvg(14) + '</span> icon on any photo to add prints.</p>' +
                '<div class="tf2p-subtotal" id="tf2p-subtotal-row"><span>Subtotal</span><strong id="tf2p-subtotal"></strong></div>' +
                '<p class="tf2p-pickup" id="tf2p-pickup-note" style="display:none;"></p>' +
                '<form class="tf2p-form" id="tf2p-checkout-form" novalidate>' +
                    '<h4 class="tf2p-form__title">Your details</h4>' +
                    '<label class="tf2p-field"><span>Name</span><input type="text" id="tf2p-cust-name" autocomplete="name" required maxlength="120"></label>' +
                    '<label class="tf2p-field"><span>Email</span><input type="email" id="tf2p-cust-email" autocomplete="email" required maxlength="190"></label>' +
                    '<label class="tf2p-field"><span>Phone (WhatsApp)</span><input type="tel" id="tf2p-cust-phone" autocomplete="tel" maxlength="40"></label>' +
                    '<label class="tf2p-field"><span>Notes (optional)</span><textarea id="tf2p-cust-notes" rows="2" maxlength="1000"></textarea></label>' +
                    '<input type="text" name="website" id="tf2p-hp" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute; left:-9999px; height:1px; width:1px; opacity:0;">' +
                    '<p class="tf2p-error" id="tf2p-checkout-error" style="display:none;"></p>' +
                    '<button type="submit" class="tf2p-btn tf2p-btn--gold tf2p-btn--full" id="tf2p-checkout-btn">Place Order</button>' +
                    '<p class="tf2p-payhint">Pay after checkout from your order page — by card (via WiPay) or bank transfer.</p>' +
                '</form>' +
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

        root.appendChild(backdrop);
        root.appendChild(sheet);
        root.appendChild(drawer);
        document.body.appendChild(root);

        // Wiring
        document.getElementById('tf2p-sheet-close').addEventListener('click', closePicker);
        document.getElementById('tf2p-drawer-close').addEventListener('click', closeDrawer);
        document.getElementById('tf2p-view-cart').addEventListener('click', function() {
            closePicker();
            openDrawer();
        });
        document.getElementById('tf2p-success-done').addEventListener('click', function() {
            closeDrawer();
            var success = document.getElementById('tf2p-success');
            var body = document.getElementById('tf2p-drawer-body');
            if (success) success.style.display = 'none';
            if (body) body.style.display = '';
        });
        document.getElementById('tf2p-checkout-form').addEventListener('submit', onCheckout);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePicker();
                closeDrawer();
            }
        });

        // Prefill contact from gallery session
        if (cfg.customerName) {
            var nameInput = document.getElementById('tf2p-cust-name');
            if (nameInput) nameInput.value = cfg.customerName;
        }
        if (cfg.customerEmail) {
            var emailInput = document.getElementById('tf2p-cust-email');
            if (emailInput) emailInput.value = cfg.customerEmail;
        }

        updateInternalUI();
    }

    function printerSvg(size) {
        return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>';
    }

    // ── Product rows (grouped) ─────────────────────────

    var CATEGORY_LABELS = { print: 'Prints', canvas: 'Canvas', photobook: 'Photobooks' };
    var CATEGORY_ORDER  = ['print', 'canvas', 'photobook'];

    function buildProductRows() {
        var wrap = document.getElementById('tf2p-product-groups');
        if (!wrap) return;
        wrap.innerHTML = '';

        if (!products.length) {
            wrap.appendChild(el('p', 'tf2p-loading', 'No products are available right now.'));
            return;
        }

        for (var c = 0; c < CATEGORY_ORDER.length; c++) {
            var category = CATEGORY_ORDER[c];
            var group = [];
            for (var i = 0; i < products.length; i++) {
                if (products[i].category === category) group.push(products[i]);
            }
            if (!group.length) continue;

            wrap.appendChild(el('h4', 'tf2p-group__title', CATEGORY_LABELS[category] || category));
            if (category === 'photobook') {
                wrap.appendChild(el('p', 'tf2p-group__note', 'We design your book together with you after ordering — the qty below is the number of books.'));
            }

            for (var g = 0; g < group.length; g++) {
                wrap.appendChild(buildProductRow(group[g]));
            }
        }
    }

    function buildProductRow(p) {
        var row = el('div', 'tf2p-prod');
        row.setAttribute('data-product', p.id);

        var info = el('div', 'tf2p-prod__info');
        info.appendChild(el('span', 'tf2p-prod__name', p.name));
        var priceLine = sizeLabel(p) ? sizeLabel(p) + ' · ' + money(p.price) : money(p.price);
        info.appendChild(el('span', 'tf2p-prod__price', priceLine));
        row.appendChild(info);

        var stepper = el('div', 'tf2p-stepper');
        var minus = el('button', 'tf2p-stepper__btn', '−');
        minus.type = 'button';
        minus.setAttribute('aria-label', 'Fewer');
        var qtyEl = el('span', 'tf2p-stepper__qty', '0');
        var plus = el('button', 'tf2p-stepper__btn tf2p-stepper__btn--plus', '+');
        plus.type = 'button';
        plus.setAttribute('aria-label', 'More');
        stepper.appendChild(minus);
        stepper.appendChild(qtyEl);
        stepper.appendChild(plus);
        row.appendChild(stepper);

        minus.addEventListener('click', function(e) {
            e.stopPropagation();
            if (!pickerPhoto) return;
            var item = findCartItem(pickerPhoto.filename, p.id, pickerPhoto.file_index);
            setQty(pickerPhoto, p.id, item ? item.qty - 1 : 0);
            selectProduct(p.id);
        });
        plus.addEventListener('click', function(e) {
            e.stopPropagation();
            if (!pickerPhoto) return;
            var item = findCartItem(pickerPhoto.filename, p.id, pickerPhoto.file_index);
            setQty(pickerPhoto, p.id, item ? item.qty + 1 : 1);
            selectProduct(p.id);
        });
        row.addEventListener('click', function() { selectProduct(p.id); });

        return row;
    }

    function refreshProductRowQtys() {
        if (!sheet || !pickerPhoto) return;
        var rows = sheet.querySelectorAll('.tf2p-prod');
        for (var i = 0; i < rows.length; i++) {
            var pid = rows[i].getAttribute('data-product');
            var item = findCartItem(pickerPhoto.filename, pid, pickerPhoto.file_index);
            var qty = item ? item.qty : 0;
            var qtyEl = rows[i].querySelector('.tf2p-stepper__qty');
            if (qtyEl) qtyEl.textContent = String(qty);
            if (qty > 0) rows[i].classList.add('tf2p-prod--inCart');
            else rows[i].classList.remove('tf2p-prod--inCart');
        }
    }

    // ── Print preview (aspect crop simulation) ─────────

    function selectProduct(productId) {
        var p = productsById[productId];
        if (!p) return;
        selectedProductId = productId;

        var rows = sheet.querySelectorAll('.tf2p-prod');
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].getAttribute('data-product') === productId) {
                rows[i].classList.add('tf2p-prod--selected');
            } else {
                rows[i].classList.remove('tf2p-prod--selected');
            }
        }
        updatePreview();
        refreshProductRowQtys();
    }

    function updatePreview() {
        var frame   = document.getElementById('tf2p-preview-frame');
        var sizeEl  = document.getElementById('tf2p-preview-size');
        var noteEl  = document.getElementById('tf2p-crop-note');
        if (!frame || !pickerPhoto) return;

        var p = productsById[selectedProductId];
        var w = p && p.width_in ? p.width_in : 4;
        var h = p && p.height_in ? p.height_in : 6;

        // Match the product's orientation to the photo's
        if (photoRatio && ((photoRatio >= 1 && w < h) || (photoRatio < 1 && w > h))) {
            var t = w; w = h; h = t;
        }
        frame.style.aspectRatio = w + ' / ' + h;

        if (sizeEl) {
            sizeEl.textContent = p ? (p.name + (sizeLabel(p) ? ' — ' + sizeLabel(p) : '')) : '';
        }

        if (noteEl) {
            if (p && p.category === 'photobook') {
                noteEl.textContent = 'Photobooks are designed with you after ordering — this photo will be included.';
                noteEl.style.display = '';
            } else if (photoRatio && p && p.width_in && p.height_in) {
                var prodRatio = w / h;
                var mismatch = Math.abs(photoRatio - prodRatio) / prodRatio;
                if (mismatch > 0.02) {
                    noteEl.textContent = 'This size crops your photo slightly — the preview shows the printed area.';
                    noteEl.style.display = '';
                } else {
                    noteEl.style.display = 'none';
                }
            } else {
                noteEl.style.display = 'none';
            }
        }
    }

    // ── Open / close picker & drawer ───────────────────

    var overlayOpen = false;

    function lockScroll(lock) {
        if (lock) {
            document.body.classList.add('tf2p-noscroll');
        } else if (!isPickerOpen() && !isDrawerOpen()) {
            document.body.classList.remove('tf2p-noscroll');
        }
    }

    function isPickerOpen() { return sheet && sheet.classList.contains('tf2p-open'); }
    function isDrawerOpen() { return drawer && drawer.classList.contains('tf2p-open'); }

    function syncBackdrop() {
        if (!backdrop) return;
        if (isPickerOpen() || isDrawerOpen()) {
            backdrop.classList.add('tf2p-open');
        } else {
            backdrop.classList.remove('tf2p-open');
        }
        lockScroll(isPickerOpen() || isDrawerOpen());
    }

    function openPicker(photo) {
        if (!photo || !photo.filename) return;
        buildOverlay();
        closeDrawer();

        pickerPhoto = {
            filename:   photo.filename,
            url:        photo.url || '',
            thumb_url:  photo.thumb_url || photo.url || '',
            file_index: typeof photo.file_index === 'number' ? photo.file_index : -1
        };

        var img = document.getElementById('tf2p-preview-img');
        if (img) {
            photoRatio = 0;
            img.onload = function() {
                if (img.naturalWidth && img.naturalHeight) {
                    photoRatio = img.naturalWidth / img.naturalHeight;
                }
                updatePreview();
            };
            img.src = pickerPhoto.thumb_url || pickerPhoto.url;
            if (img.complete && img.naturalWidth) {
                photoRatio = img.naturalWidth / img.naturalHeight;
            }
        }

        loadProducts(function() {
            if (!selectedProductId || !productsById[selectedProductId]) {
                selectedProductId = products.length ? products[0].id : '';
            }
            if (selectedProductId) selectProduct(selectedProductId);
            refreshProductRowQtys();
            updatePreview();
        });

        sheet.classList.add('tf2p-open');
        syncBackdrop();
        refreshProductRowQtys();
        updateInternalUI();
    }

    function closePicker() {
        if (!sheet) return;
        sheet.classList.remove('tf2p-open');
        syncBackdrop();
    }

    function openDrawer() {
        buildOverlay();
        closePicker();
        loadProducts(null);
        renderCart();
        var success = document.getElementById('tf2p-success');
        var body = document.getElementById('tf2p-drawer-body');
        if (success && success.style.display !== 'none') {
            // keep success visible if an order was just placed
        } else if (body) {
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

    // ── Cart rendering ─────────────────────────────────

    function renderCart() {
        var list = document.getElementById('tf2p-cart-items');
        var empty = document.getElementById('tf2p-cart-empty');
        var form = document.getElementById('tf2p-checkout-form');
        var subRow = document.getElementById('tf2p-subtotal-row');
        var pickupEl = document.getElementById('tf2p-pickup-note');
        if (!list) return;

        list.innerHTML = '';

        if (!cart.length) {
            if (empty) empty.style.display = '';
            if (form) form.style.display = 'none';
            if (subRow) subRow.style.display = 'none';
            if (pickupEl) pickupEl.style.display = 'none';
            return;
        }
        if (empty) empty.style.display = 'none';
        if (form) form.style.display = '';
        if (subRow) subRow.style.display = '';

        for (var i = 0; i < cart.length; i++) {
            list.appendChild(buildCartRow(cart[i]));
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

    function buildCartRow(item) {
        var row = el('div', 'tf2p-cart-item');

        var thumbSrc = item.thumb_url || item.photo_url;
        if (!thumbSrc && mode === 'public' && item.file_index >= 0 && publicFiles[item.file_index]) {
            thumbSrc = publicFiles[item.file_index].previewUrl;
        }
        if (thumbSrc) {
            var img = document.createElement('img');
            img.className = 'tf2p-cart-item__thumb';
            img.alt = '';
            img.src = thumbSrc;
            row.appendChild(img);
        } else {
            row.appendChild(el('span', 'tf2p-cart-item__thumb tf2p-cart-item__thumb--blank'));
        }

        var info = el('div', 'tf2p-cart-item__info');
        info.appendChild(el('span', 'tf2p-cart-item__name', item.product_name));
        info.appendChild(el('span', 'tf2p-cart-item__file', item.filename));
        info.appendChild(el('span', 'tf2p-cart-item__price', money(item.price * item.qty)));
        row.appendChild(info);

        var controls = el('div', 'tf2p-cart-item__controls');
        var stepper = el('div', 'tf2p-stepper tf2p-stepper--sm');
        var minus = el('button', 'tf2p-stepper__btn', '−');
        minus.type = 'button';
        var qtyEl = el('span', 'tf2p-stepper__qty', String(item.qty));
        var plus = el('button', 'tf2p-stepper__btn tf2p-stepper__btn--plus', '+');
        plus.type = 'button';
        stepper.appendChild(minus);
        stepper.appendChild(qtyEl);
        stepper.appendChild(plus);
        controls.appendChild(stepper);

        var remove = el('button', 'tf2p-cart-item__remove', 'Remove');
        remove.type = 'button';
        controls.appendChild(remove);
        row.appendChild(controls);

        minus.addEventListener('click', function() {
            item.qty -= 1;
            if (item.qty <= 0) cart.splice(indexOfItem(item), 1);
            notifyCountChange();
            renderCart();
        });
        plus.addEventListener('click', function() {
            item.qty = Math.min(50, item.qty + 1);
            notifyCountChange();
            renderCart();
        });
        remove.addEventListener('click', function() {
            var idx = indexOfItem(item);
            if (idx >= 0) cart.splice(idx, 1);
            notifyCountChange();
            renderCart();
        });

        return row;
    }

    // ── Checkout ───────────────────────────────────────

    var submitting = false;

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

        var name  = (document.getElementById('tf2p-cust-name').value || '').replace(/^\s+|\s+$/g, '');
        var email = (document.getElementById('tf2p-cust-email').value || '').replace(/^\s+|\s+$/g, '');
        var phone = (document.getElementById('tf2p-cust-phone').value || '').replace(/^\s+|\s+$/g, '');
        var notes = document.getElementById('tf2p-cust-notes').value || '';
        var hp    = document.getElementById('tf2p-hp').value || '';

        if (!name || !email || email.indexOf('@') < 1) {
            showCheckoutError('Please enter your name and a valid email address.');
            return;
        }
        showCheckoutError('');

        submitting = true;
        var btn = document.getElementById('tf2p-checkout-btn');
        var btnLabel = btn ? btn.textContent : '';
        if (btn) {
            btn.disabled = true;
            btn.textContent = mode === 'public' ? 'Uploading photos…' : 'Placing order…';
        }

        var done = function(data) {
            submitting = false;
            if (btn) {
                btn.disabled = false;
                btn.textContent = btnLabel;
            }
            if (data && data.ok && data.order_ref) {
                showSuccess(data);
                cart = [];
                publicFiles = mode === 'public' ? publicFiles : [];
                notifyCountChange();
                if (mode === 'public') resetPublicPage();
            } else {
                var msg = (data && (data.message || data.code)) ? (data.message || 'Something went wrong. Please try again.') : 'Network error. Please check your connection and try again.';
                showCheckoutError(msg);
            }
        };

        if (mode === 'gallery') {
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
                    items: cart
                })
            })
            .then(function(r) { return r.json(); })
            .then(done)
            .catch(function() { done(null); });
        } else {
            // Public: multipart — only send the files that are in the cart,
            // remapping file indexes to the appended order.
            var usedIdx = [];
            var i, j;
            for (i = 0; i < cart.length; i++) {
                var fi = cart[i].file_index;
                if (fi >= 0 && publicFiles[fi]) {
                    var seen = false;
                    for (j = 0; j < usedIdx.length; j++) if (usedIdx[j] === fi) seen = true;
                    if (!seen) usedIdx.push(fi);
                }
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
                items.push({
                    file_index: remap[cart[i].file_index],
                    product_id: cart[i].product_id,
                    qty: cart[i].qty,
                    crop: cart[i].crop || ''
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
                .catch(function() { done(null); });
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

        // Main CTA: the tokenized order portal (pay + track there)
        if (!portalBtn && success) {
            portalBtn = document.createElement('a');
            portalBtn.id = 'tf2p-success-portal';
            portalBtn.className = 'tf2p-btn tf2p-btn--gold tf2p-btn--full';
            portalBtn.style.marginBottom = '10px';
            portalBtn.textContent = 'View your order / Make payment';
            var doneBtn = document.getElementById('tf2p-success-done');
            if (doneBtn && doneBtn.parentNode) {
                doneBtn.parentNode.insertBefore(portalBtn, doneBtn);
            } else {
                success.appendChild(portalBtn);
            }
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

    // ── Public page (upload shell) ─────────────────────

    function initPublicPage() {
        var dropzone = document.getElementById('tf2p-dropzone');
        var input    = document.getElementById('tf2p-file-input');
        var grid     = document.getElementById('tf2p-upload-grid');
        var cartbar  = document.getElementById('tf2p-cartbar');
        var cartbarBtn = document.getElementById('tf2p-cartbar-btn');
        if (!dropzone || !input || !grid) return;

        loadProducts(null);

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
            if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files);
        });
        input.addEventListener('change', function() {
            if (input.files) addFiles(input.files);
            input.value = '';
        });

        if (cartbarBtn) {
            cartbarBtn.addEventListener('click', function() { openDrawer(); });
        }

        function addFiles(fileList) {
            var errors = [];
            for (var i = 0; i < fileList.length; i++) {
                var file = fileList[i];
                if (publicFiles.length >= 25) {
                    errors.push('You can add up to 25 photos per order.');
                    break;
                }
                if (!/^image\/(jpeg|png)$/.test(file.type)) {
                    errors.push(file.name + ': only JPEG or PNG.');
                    continue;
                }
                if (file.size > 25 * 1024 * 1024) {
                    errors.push(file.name + ': larger than 25MB.');
                    continue;
                }
                var entry = {
                    file: file,
                    name: file.name,
                    previewUrl: URL.createObjectURL(file)
                };
                publicFiles.push(entry);
                grid.appendChild(buildUploadTile(entry, publicFiles.length - 1));
            }
            if (errors.length) {
                window.alert(errors.join('\n'));
            }
            updateInternalUI();
        }

        function buildUploadTile(entry, index) {
            var tile = el('div', 'tf2-prints__tile');

            var img = document.createElement('img');
            img.src = entry.previewUrl;
            img.alt = entry.name;
            tile.appendChild(img);

            var badge = el('span', 'tf2-prints__tile-badge', '0');
            badge.style.display = 'none';
            badge.setAttribute('data-file-index', String(index));
            tile.appendChild(badge);

            var btn = el('button', 'tf2p-btn tf2p-btn--gold tf2p-btn--sm tf2-prints__tile-btn');
            btn.type = 'button';
            btn.innerHTML = printerSvg(13) + ' <span>Choose products</span>';
            tile.appendChild(btn);

            var pick = function() {
                openPicker({
                    filename: entry.name,
                    url: entry.previewUrl,
                    thumb_url: entry.previewUrl,
                    file_index: index
                });
            };
            btn.addEventListener('click', function(e) { e.stopPropagation(); pick(); });
            tile.addEventListener('click', pick);

            return tile;
        }

        // keep cart bar in sync
        countCallbacks.push(function() { updatePublicBars(); });
        updatePublicBars();

        function updatePublicBars() {
            if (!cartbar) return;
            var n = cartCount();
            if (n > 0) {
                cartbar.style.display = '';
                var label = document.getElementById('tf2p-cartbar-label');
                if (label) label.textContent = n + (n === 1 ? ' item' : ' items') + ' · ' + money(subtotal());
            } else {
                cartbar.style.display = 'none';
            }
            var badges = grid.querySelectorAll('.tf2-prints__tile-badge');
            for (var i = 0; i < badges.length; i++) {
                var fi = parseInt(badges[i].getAttribute('data-file-index'), 10);
                var count = 0;
                for (var j = 0; j < cart.length; j++) {
                    if (cart[j].file_index === fi) count += cart[j].qty;
                }
                badges[i].textContent = String(count);
                badges[i].style.display = count > 0 ? '' : 'none';
            }
        }
    }

    function resetPublicPage() {
        var grid = document.getElementById('tf2p-upload-grid');
        if (grid) grid.innerHTML = '';
        for (var i = 0; i < publicFiles.length; i++) {
            try { URL.revokeObjectURL(publicFiles[i].previewUrl); } catch (e) {}
        }
        publicFiles = [];
        var cartbar = document.getElementById('tf2p-cartbar');
        if (cartbar) cartbar.style.display = 'none';
    }

    // ── Storefront scroll reveals (optional, no libs) ──

    function initReveals() {
        var app = document.getElementById('tf2-prints-app');
        var nodes = document.querySelectorAll('.tf2-reveal');
        if (!app || !nodes.length) return;
        if (typeof window.IntersectionObserver === 'undefined') return;
        // Content stays visible unless we can animate it in
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

    // ── Customer order portal: receipt + client-side OCR ──
    // Same approach as the booking receipt flow (Tesseract.js from CDN,
    // labeled-amount regex first, currency/decimal fallback).

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
        var flow   = document.getElementById('tf2pp-receipt-flow');
        if (!pick || !input || !flow) return;

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
            flow.style.display = '';
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
                            flow.style.display = 'none';
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
        // "View cart" footer inside the picker sheet
        var viewCart = document.getElementById('tf2p-view-cart');
        if (viewCart) {
            var n = cartCount();
            viewCart.textContent = n > 0
                ? 'View Cart (' + n + ') · ' + money(subtotal())
                : 'View Cart';
        }
        var title = document.getElementById('tf2p-drawer-title');
        if (title) {
            var c = cartCount();
            title.textContent = c > 0 ? 'Your Prints (' + c + ')' : 'Your Prints';
        }
        refreshProductRowQtys();
        if (isDrawerOpen()) renderCart();
    }

    // ── Public API for tracker.js ──────────────────────

    window.TwellerPrints = {
        ready: true,
        openStore: function() {
            openDrawer();
        },
        openPicker: function(photo) {
            openPicker(photo);
        },
        getCount: function() {
            return cartCount();
        },
        getCountFor: function(filename) {
            return countFor(filename);
        },
        onCountChange: function(cb) {
            if (typeof cb === 'function') countCallbacks.push(cb);
        }
    };

    // ── Boot ───────────────────────────────────────────

    if (cfg.mode === 'portal') {
        // Order portal: only the receipt flow — no store overlay.
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

    // Auto-open the store when the delivery email links with ?prints=1
    if (mode === 'gallery' && /[?&]prints=1(&|$)/.test(window.location.search)) {
        setTimeout(function() { openDrawer(); }, 600);
    }
})();
