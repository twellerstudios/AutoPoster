/**
 * Tweller Flow — Print Provider Portal (ES5 only)
 *
 * Every write goes through the provider REST endpoints, which check the
 * capability AND that the job is assigned to the signed-in provider. The
 * browser never sends a provider id — the server derives it from the user.
 */
(function () {
    'use strict';

    var CFG = window.twellerProvider || {};
    if (!CFG.restUrl) { return; }

    var elAlert  = document.getElementById('tfpv-alert');
    var elStats  = document.getElementById('tfpv-stats');
    var elOrders = document.getElementById('tfpv-orders');
    var elScan   = document.getElementById('tfpv-scan-input');
    var elScanGo = document.getElementById('tfpv-scan-go');
    var elScanCam = document.getElementById('tfpv-scan-cam');
    var elVideo  = document.getElementById('tfpv-scan-video');

    var openRef = '';       // order_ref whose detail panel is expanded
    var details = {};       // order_ref -> detail payload
    var busy    = false;
    var stream  = null;
    var scanTimer = null;

    // ── Helpers ────────────────────────────────────────

    function esc(value) {
        return String(value === null || typeof value === 'undefined' ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function money(value) {
        var n = parseFloat(value || 0);
        if (isNaN(n)) { n = 0; }
        return 'TT$ ' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function notify(message, kind) {
        if (!elAlert) { return; }
        elAlert.className = 'tfpv__alert' + (kind ? ' tfpv__alert--' + kind : '');
        elAlert.textContent = message;
        elAlert.hidden = false;
        if (elAlert.scrollIntoView) {
            elAlert.scrollIntoView({ block: 'nearest' });
        }
    }

    function clearNotice() {
        if (elAlert) { elAlert.hidden = true; }
    }

    function api(path, method, body) {
        var opts = {
            method: method || 'GET',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': CFG.nonce }
        };
        if (body) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        return fetch(CFG.restUrl + path, opts).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok) {
                    var message = (data && data.message) ? data.message : 'Something went wrong.';
                    throw new Error(message);
                }
                return data;
            });
        });
    }

    function fail(err) {
        busy = false;
        notify((err && err.message) ? err.message : 'Something went wrong.', 'error');
    }

    // ── Rendering ──────────────────────────────────────

    function tile(label, value, accent) {
        return '<div class="tfpv__tile' + (accent ? ' tfpv__tile--accent' : '') + '">' +
            '<span class="tfpv__tile-label">' + esc(label) + '</span>' +
            '<span class="tfpv__tile-value">' + esc(value) + '</span>' +
            '</div>';
    }

    function renderStats(stats) {
        if (!elStats || !stats) { return; }
        elStats.innerHTML =
            tile('Awaiting print', stats.awaiting) +
            tile('In production', stats.in_production) +
            tile('Ready', stats.ready) +
            tile('Done this month', stats.completed_month) +
            tile('Value this month', money(stats.value_month), true);
    }

    function pill(order) {
        var cls = 'tfpv__pill';
        if (order.status === 'printing' || order.status === 'ready' ||
            order.status === 'completed' || order.status === 'cancelled') {
            cls += ' tfpv__pill--' + order.status;
        }
        return '<span class="' + cls + '">' + esc(order.status_label) + '</span>';
    }

    function orderHtml(order) {
        var html = '<article class="tfpv__order" data-ref="' + esc(order.order_ref) + '">';

        html += '<div class="tfpv__order-head">' +
            '<span class="tfpv__ref">' + esc(order.order_ref) + '</span>' +
            pill(order) +
            '</div>';

        html += '<p class="tfpv__meta">Sent <strong>' + esc(order.sent_label) + '</strong>' +
            ' · <strong>' + esc(order.item_count) + '</strong> item' + (order.item_count === 1 ? '' : 's') +
            ' · <strong>' + esc(order.pieces) + '</strong> piece' + (order.pieces === 1 ? '' : 's') +
            ' · Delivered by <strong>' + esc(order.delivered_by) + '</strong></p>';

        if (order.sizes) {
            html += '<p class="tfpv__sizes">' + esc(order.sizes) + '</p>';
        }

        html += '<div class="tfpv__actions">';
        html += '<button type="button" class="tfpv__btn tfpv__btn--ghost" data-act="toggle">' +
            (openRef === order.order_ref ? 'Hide job' : 'View job') + '</button>';

        if (order.can_start) {
            html += '<button type="button" class="tfpv__btn" data-act="printing">Start printing</button>';
        }
        if (order.can_ready) {
            html += '<button type="button" class="tfpv__btn tfpv__btn--gold" data-act="ready">Mark ready</button>';
        }
        if (order.can_ship) {
            html += '<button type="button" class="tfpv__btn tfpv__btn--ghost" data-act="shipped">Mark handed over</button>';
        }
        html += '</div>';

        if (openRef === order.order_ref) {
            html += detailHtml(details[order.order_ref]);
        }

        html += '</article>';
        return html;
    }

    function detailHtml(detail) {
        if (!detail) {
            return '<div class="tfpv__detail"><p class="tfpv__loading">Loading job&hellip;</p></div>';
        }

        var html = '<div class="tfpv__detail">';

        var i;
        var items = detail.items || [];
        html += '<ul class="tfpv__items">';
        for (i = 0; i < items.length; i++) {
            var it = items[i];
            html += '<li class="tfpv__item">';
            if (it.thumb) {
                html += '<img class="tfpv__thumb" src="' + esc(it.thumb) + '" alt="" loading="lazy">';
            } else {
                html += '<span class="tfpv__thumb tfpv__thumb--empty">No preview</span>';
            }
            html += '<span>' +
                '<p class="tfpv__item-name">' + esc(it.name) + '</p>' +
                '<p class="tfpv__item-file">' + esc(it.file) + '</p>' +
                '</span>';
            html += '<span class="tfpv__item-qty">&times; ' + esc(it.qty) + '</span>';
            html += '</li>';
        }
        html += '</ul>';

        var notes = detail.studio_notes || [];
        for (i = 0; i < notes.length; i++) {
            html += '<p class="tfpv__note"><strong>Studio note:</strong> ' + esc(notes[i]) + '</p>';
        }
        if (detail.notes) {
            html += '<p class="tfpv__note"><strong>Delivery note:</strong> ' + esc(detail.notes) + '</p>';
        }

        html += '<div class="tfpv__actions">';
        if (detail.built && detail.zip_url) {
            html += '<a class="tfpv__btn tfpv__btn--gold" href="' + esc(detail.zip_url) + '">Download print-ready files</a>';
        } else {
            html += '<button type="button" class="tfpv__btn" data-act="build">Prepare print-ready files</button>';
        }
        if (detail.label_url) {
            html += '<a class="tfpv__btn tfpv__btn--ghost" href="' + esc(detail.label_url) + '" target="_blank" rel="noopener">Shipping label</a>';
        }
        html += '</div>';
        html += '<p class="tfpv__progress" data-role="progress" hidden></p>';

        html += '</div>';
        return html;
    }

    function renderOrders(orders) {
        if (!elOrders) { return; }
        if (!orders || !orders.length) {
            elOrders.innerHTML = '<div class="tfpv__empty">No print jobs have been sent to you yet.</div>';
            return;
        }
        var html = '';
        for (var i = 0; i < orders.length; i++) {
            html += orderHtml(orders[i]);
        }
        elOrders.innerHTML = html;
    }

    // ── Data ───────────────────────────────────────────

    function load() {
        return api('me').then(function (data) {
            renderStats(data.stats);
            renderOrders(data.orders);
        }).catch(fail);
    }

    function loadDetail(ref) {
        return api('orders/' + encodeURIComponent(ref)).then(function (detail) {
            details[ref] = detail;
            return load();
        }).catch(fail);
    }

    function setStatus(ref, status) {
        if (busy) { return; }
        busy = true;
        clearNotice();
        api('orders/' + encodeURIComponent(ref) + '/status', 'POST', { status: status })
            .then(function (data) {
                busy = false;
                if (data.order) { details[ref] = data.order; }
                notify(ref + ' is now ' + (data.order ? data.order.status_label : status) + '.', 'ok');
                return load();
            }).catch(fail);
    }

    function markShipped(ref) {
        if (busy) { return; }
        busy = true;
        clearNotice();
        api('orders/' + encodeURIComponent(ref) + '/shipped', 'POST', {})
            .then(function (data) {
                busy = false;
                if (data.order) { details[ref] = data.order; }
                notify(ref + ' marked as handed over.', 'ok');
                return load();
            }).catch(fail);
    }

    function build(ref, node) {
        if (busy) { return; }
        busy = true;
        clearNotice();

        var progress = node ? node.querySelector('[data-role="progress"]') : null;

        function step() {
            api('orders/' + encodeURIComponent(ref) + '/build', 'POST', {})
                .then(function (data) {
                    if (progress) {
                        progress.hidden = false;
                        progress.textContent = 'Preparing files… ' + data.progress + ' of ' + data.total;
                    }
                    if (!data.done) {
                        window.setTimeout(step, 700);
                        return null;
                    }
                    busy = false;
                    notify('Print-ready files are packaged and ready to download.', 'ok');
                    return loadDetail(ref);
                }).catch(fail);
        }

        step();
    }

    function submitScan(code) {
        if (busy) { return; }
        code = (code || '').replace(/\s+/g, '');
        if (!code) {
            notify('Enter the code printed under the barcode.', 'error');
            return;
        }
        busy = true;
        clearNotice();
        api('scan', 'POST', { code: code })
            .then(function (data) {
                busy = false;
                notify(data.message, 'ok');
                if (elScan) { elScan.value = ''; }
                stopCamera();
                return load();
            }).catch(fail);
    }

    // ── Camera scanning (progressive enhancement) ──────

    function stopCamera() {
        if (scanTimer) { window.clearInterval(scanTimer); scanTimer = null; }
        if (stream) {
            var tracks = stream.getTracks();
            for (var i = 0; i < tracks.length; i++) { tracks[i].stop(); }
            stream = null;
        }
        if (elVideo) { elVideo.hidden = true; elVideo.srcObject = null; }
        if (elScanCam) { elScanCam.textContent = 'Use camera'; }
    }

    function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            notify('This browser cannot open the camera — type the code instead.', 'error');
            return;
        }

        var detector;
        try {
            detector = new window.BarcodeDetector({ formats: ['code_128'] });
        } catch (e) {
            notify('Barcode scanning is not supported here — type the code instead.', 'error');
            return;
        }

        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(function (media) {
                stream = media;
                elVideo.srcObject = media;
                elVideo.hidden = false;
                elScanCam.textContent = 'Stop camera';
                return elVideo.play();
            })
            .then(function () {
                scanTimer = window.setInterval(function () {
                    detector.detect(elVideo).then(function (codes) {
                        if (codes && codes.length) {
                            var value = codes[0].rawValue;
                            stopCamera();
                            if (elScan) { elScan.value = value; }
                            submitScan(value);
                        }
                    }).catch(function () { /* frame not ready — keep trying */ });
                }, 600);
            })
            .catch(function () {
                notify('Camera access was blocked — type the code instead.', 'error');
                stopCamera();
            });
    }

    // ── Events ─────────────────────────────────────────

    if (elOrders) {
        elOrders.addEventListener('click', function (event) {
            var button = event.target;
            while (button && button !== elOrders && !button.getAttribute('data-act')) {
                button = button.parentNode;
            }
            if (!button || button === elOrders) { return; }

            var act = button.getAttribute('data-act');
            var card = button;
            while (card && card !== elOrders && !card.getAttribute('data-ref')) {
                card = card.parentNode;
            }
            if (!card || card === elOrders) { return; }
            var ref = card.getAttribute('data-ref');

            if (act === 'toggle') {
                if (openRef === ref) {
                    openRef = '';
                    load();
                } else {
                    openRef = ref;
                    if (details[ref]) { load(); } else { load().then(function () { return loadDetail(ref); }); }
                }
                return;
            }
            if (act === 'printing' || act === 'ready') { setStatus(ref, act); return; }
            if (act === 'shipped') { markShipped(ref); return; }
            if (act === 'build') { build(ref, card); return; }
        });
    }

    if (elScanGo) {
        elScanGo.addEventListener('click', function () {
            submitScan(elScan ? elScan.value : '');
        });
    }

    if (elScan) {
        elScan.addEventListener('keydown', function (event) {
            if (event.keyCode === 13) {
                event.preventDefault();
                submitScan(elScan.value);
            }
        });
    }

    if (elScanCam && window.BarcodeDetector && navigator.mediaDevices) {
        elScanCam.hidden = false;
        elScanCam.addEventListener('click', function () {
            if (stream) { stopCamera(); } else { startCamera(); }
        });
    }

    load();
}());
