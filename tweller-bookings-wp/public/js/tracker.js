/**
 * Tweller Flow v2.8 — Client Tracker + Gallery + Activity Tracking
 */
(function() {
    'use strict';

    var tracker = document.querySelector('.tf2-tracker[data-code]');
    if (!tracker) return;

    var code = tracker.getAttribute('data-code');
    if (!code || typeof twellerFlow2Tracker === 'undefined') return;

    // ── WiPay payment return banner (?payment=success|failed|error|unverified) ──
    (function() {
        var qs = window.location.search || '';
        var stateMatch = qs.match(/[?&]payment=([A-Za-z]+)/);
        if (!stateMatch) return;
        var state = stateMatch[1].toLowerCase();

        function qparam(name) {
            var r = qs.match(new RegExp('[?&]' + name + '=([^&]*)'));
            if (!r) return '';
            try {
                return decodeURIComponent(r[1].replace(/\+/g, ' '));
            } catch (e) {
                return '';
            }
        }

        var banner = document.createElement('div');
        var title = document.createElement('p');
        title.className = 'tf2-pay-banner__title';
        var msg = document.createElement('p');
        msg.className = 'tf2-pay-banner__msg';

        if (state === 'success') {
            banner.className = 'tf2-pay-banner tf2-pay-banner--success';
            title.textContent = 'Payment received — thank you!';
            msg.textContent = 'Your session is confirmed.';
            var txn = qparam('txn').slice(0, 64);
            if (txn) {
                var txnEl = document.createElement('span');
                txnEl.className = 'tf2-pay-banner__txn';
                txnEl.textContent = 'Transaction ' + txn;
                msg.appendChild(txnEl);
            }
        } else if (state === 'unverified') {
            banner.className = 'tf2-pay-banner tf2-pay-banner--error';
            title.textContent = 'Payment received — verification pending';
            msg.textContent = 'We could not verify this payment automatically. We will confirm it manually — please contact us if you have any questions.';
        } else if (state === 'failed' || state === 'error') {
            banner.className = 'tf2-pay-banner tf2-pay-banner--error';
            title.textContent = 'Payment unsuccessful';
            var pmsg = qparam('pmsg').slice(0, 140);
            msg.textContent = pmsg || 'Your card was not charged. You can try again below, or pay by bank transfer instead.';
        } else {
            return;
        }

        banner.appendChild(title);
        banner.appendChild(msg);
        tracker.insertBefore(banner, tracker.firstChild);
    })();

    // Handle post-upload scroll restoration smoothly
    if (sessionStorage.getItem('tf_receipt_uploaded')) {
        sessionStorage.removeItem('tf_receipt_uploaded');
        setTimeout(function() {
            var box = document.querySelector('.tf2-tracker__receipt--verifying');
            if (box) window.scrollTo({ top: box.offsetTop - 100, behavior: 'smooth' });
        }, 200);
    }

    // ── Progress bar sub-step mapping ────────────────
    // Maps each client-facing stage to its backend sub-steps (in order)
    var stageSubSteps = {
        'Booked':                       ['booked'],
        'Select Photos for Editing':    ['culling'],
        'Editing':                      ['imported', 'culled', 'editing'],
        'Done Editing':                 ['edited'],
        'Exporting':                    ['exporting', 'exported'],
        'Gallery Ready':                ['uploading', 'uploaded'],
        'Delivered':                    ['delivered']
    };

    function computeProgress(clientStage, internalStage) {
        var steps = stageSubSteps[clientStage];
        if (!steps || steps.length <= 1) return 100;
        var idx = steps.indexOf(internalStage);
        if (idx < 0) return 0;
        // Each sub-step is an equal fraction; being AT that step means it's in progress
        return Math.round(((idx + 1) / steps.length) * 100);
    }

    function setProgressBars(internalStage) {
        var bars = tracker.querySelectorAll('.tf2-tracker__progress');
        bars.forEach(function(el) {
            var clientStage = el.getAttribute('data-stage');
            var pct = computeProgress(clientStage, internalStage);
            var fill = el.querySelector('.tf2-tracker__progress-fill');
            if (fill) {
                fill.style.width = pct + '%';
                fill.setAttribute('data-progress', pct);
            }
        });
    }

    // ── Stage auto-refresh ─────────────────────────────
    function fetchAndUpdate() {
        fetch(twellerFlow2Tracker.apiUrl + code, {
            headers: { 'X-WP-Nonce': twellerFlow2Tracker.nonce }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.stages) {
                updateStages(data);
                if (data.internal_stage) {
                    setProgressBars(data.internal_stage);
                }
            }
        })
        .catch(function() {});
    }

    setInterval(fetchAndUpdate, 60000);

    // Set initial progress from server-rendered data
    // We need to fetch once to get internal_stage
    fetchAndUpdate();

    function updateStages(data) {
        var stages = tracker.querySelectorAll('.tf2-tracker__stage');
        stages.forEach(function(el, idx) {
            el.className = 'tf2-tracker__stage';
            if (idx < data.current_stage_index) {
                el.classList.add('tf2-tracker__stage--completed');
            } else if (idx === data.current_stage_index) {
                el.classList.add('tf2-tracker__stage--current');
            } else {
                el.classList.add('tf2-tracker__stage--upcoming');
            }
        });
    }

    // ── Activity Tracking ──────────────────────────────
    function trackActivity(eventType, detail) {
        var url = twellerFlow2Tracker.galleryUrl + code + '/activity';
        var body = { event: eventType };
        if (detail) body.detail = detail;

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': twellerFlow2Tracker.nonce
            },
            body: JSON.stringify(body)
        }).catch(function() {}); // fire-and-forget
    }

    // Track page view
    var gallerySection = document.querySelector('.tf2-gallery[data-code]');
    if (gallerySection) {
        trackActivity('gallery_viewed');
    }

    // ── Receipt Upload ────────────────────────────────────────
    var receiptForm = document.getElementById('tf2-receipt-form');
    if (receiptForm) {
        var bankSelect = document.getElementById('tf2-receipt-bank');
        var bankOther = document.getElementById('tf2-receipt-bank-other');
        if (bankSelect && bankOther) {
            bankSelect.addEventListener('change', function() {
                bankOther.style.display = this.value === 'Other' ? 'block' : 'none';
                if (this.value === 'Other') {
                    bankOther.setAttribute('required', 'required');
                } else {
                    bankOther.removeAttribute('required');
                }
            });
        }

        // Two-step flow: 1) read the receipt (OCR) and show the detected
        // amount for the client to confirm/correct, 2) upload on confirm.
        var receiptStage = 'scan';       // 'scan' -> 'confirm'
        var pendingFile = null;
        var pendingRef = null;

        function getConfirmPanel() {
            var panel = document.getElementById('tf2-receipt-confirm');
            if (panel) return panel;
            panel = document.createElement('div');
            panel.id = 'tf2-receipt-confirm';
            panel.style.cssText = 'display:none; background:#FAF7F2; border:1px solid #E7DCC5; border-left:3px solid #C9A227; border-radius:10px; padding:14px 16px; margin:12px 0;';
            panel.innerHTML =
                '<p style="margin:0 0 8px; font-weight:600; font-size:14px;">Confirm your transfer amount</p>' +
                '<p id="tf2-receipt-confirm-note" style="margin:0 0 10px; font-size:12.5px; color:#6B7280;"></p>' +
                '<div style="display:flex; align-items:center; gap:8px;">' +
                    '<span style="font-weight:600; font-size:14px;">TTD $</span>' +
                    '<input type="number" step="0.01" min="0" id="tf2-receipt-amount" style="width:130px; padding:8px 10px; border:1px solid #D1D5DB; border-radius:8px; font-size:15px; font-weight:600;">' +
                '</div>';
            receiptForm.insertBefore(panel, document.getElementById('tf2-receipt-btn'));
            return panel;
        }

        function resetReceiptFlow(btn, btnText) {
            receiptStage = 'scan';
            pendingFile = null;
            var panel = document.getElementById('tf2-receipt-confirm');
            if (panel) panel.style.display = 'none';
            if (btnText) btnText.innerText = 'Verify Receipt Upload';
            else btn.innerText = 'Verify Receipt Upload';
        }

        // Going back to change the file restarts the flow
        document.getElementById('tf2-receipt-file').addEventListener('change', function() {
            resetReceiptFlow(document.getElementById('tf2-receipt-btn'), document.getElementById('tf2-receipt-btn-text'));
        });

        receiptForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            var btn = document.getElementById('tf2-receipt-btn');
            var btnText = document.getElementById('tf2-receipt-btn-text');
            var spinner = document.getElementById('tf2-receipt-spinner');
            var fileInput = document.getElementById('tf2-receipt-file');
            var statusDiv = document.getElementById('tf2-receipt-status');
            var codeInput = document.getElementById('tf2-receipt-code');

            if (!fileInput.files || fileInput.files.length === 0) return;
            var file = fileInput.files[0];

            // ── Step 2: client confirmed the amount -> upload ──
            if (receiptStage === 'confirm' && pendingFile) {
                var amountInput = document.getElementById('tf2-receipt-amount');
                var confirmedAmount = parseFloat(amountInput && amountInput.value ? amountInput.value : '0');
                doReceiptUpload(pendingFile, confirmedAmount > 0 ? 'TTD ' + confirmedAmount.toFixed(2) : '', pendingRef, true,
                    btn, btnText, spinner, statusDiv, codeInput, fileInput);
                return;
            }

            btn.disabled = true;
            if (btnText && spinner) {
                btnText.innerText = 'Reading receipt...';
                spinner.style.display = 'block';
            } else {
                btn.innerText = 'Reading receipt...';
            }
            statusDiv.style.display = 'block';
            statusDiv.innerText = 'Reading your receipt...';

            let extractedAmount = null;
            let extractedRef = null;

            // Free OCR using Tesseract if available
            if (window.Tesseract) {
                try {
                    const worker = await Tesseract.createWorker('eng');
                    const ret = await worker.recognize(file);
                    await worker.terminate();
                    
                    const text = ret.data.text;
                    
                    // 1. Amount Extraction Refinement
                    let pickedAmount = null;
                    const labeledAmountPattern = /(?:transfer\s+amount|amount)[\s:]*(?:TTD|\$)?\s?(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)/i;
                    const labeledMatch = text.match(labeledAmountPattern);
                    
                    if (labeledMatch && labeledMatch[1]) {
                        pickedAmount = parseFloat(labeledMatch[1].replace(/[^\d.]/g, ''));
                    } else {
                        const currencyPattern = /(?:TTD|\$)\s?(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)/gi;
                        const fallbackPattern = /\b\d{1,3}(?:,\d{3})*\.\d{2}\b/g;
                        
                        let matches = text.match(currencyPattern);
                        let fallbackMatches = text.match(fallbackPattern);
                        let amounts = [];
                        
                        if (matches) {
                            amounts = matches.map(m => parseFloat(m.replace(/[^\d.]/g, '')));
                        } else if (fallbackMatches) {
                            amounts = fallbackMatches.map(m => parseFloat(m.replace(/,/g, '')));
                        }

                        amounts = amounts.filter(a => !isNaN(a) && a > 0);

                        if (amounts.length > 0) {
                            pickedAmount = Math.max(...amounts);
                        }
                    }

                    if (pickedAmount) {
                        extractedAmount = 'TTD ' + pickedAmount.toFixed(2);
                        console.log('Final Amount Picked:', extractedAmount);
                    }

                    // 2. Reference / Transaction ID Extraction
                    const refPattern = /(?:ref(?:erence)?\s*(?:no\.?|#|number)?|transaction\s*(?:id|no\.?|#)?|trx\s*(?:id|no\.?|#)?)\s*[:\-#]?\s*([a-z0-9]{6,15})/i;
                    const refMatch = text.match(refPattern);
                    if (refMatch && refMatch[1]) {
                        extractedRef = refMatch[1].toUpperCase();
                        console.log('Detected Reference ID:', extractedRef);
                    }

                } catch(err) {
                    console.error('OCR failed', err);
                }
            }

            // ── Step 1 done: show the detected amount and ask to confirm ──
            if (spinner) spinner.style.display = 'none';
            receiptStage = 'confirm';
            pendingFile = file;
            pendingRef = extractedRef;

            var panel = getConfirmPanel();
            var note = document.getElementById('tf2-receipt-confirm-note');
            var amountField = document.getElementById('tf2-receipt-amount');

            if (extractedAmount) {
                var num = parseFloat(String(extractedAmount).replace(/[^\d.]/g, ''));
                amountField.value = isNaN(num) ? '' : num.toFixed(2);
                note.textContent = 'We automatically read this amount from your receipt. Please check it matches your transfer, correct it if needed, then submit.';
            } else {
                amountField.value = '';
                note.textContent = "We couldn't read the amount from your receipt automatically. Please type the amount you transferred, then submit.";
            }
            panel.style.display = 'block';
            statusDiv.style.display = 'none';

            btn.disabled = false;
            if (btnText) btnText.innerText = 'Confirm & Submit Receipt';
            else btn.innerText = 'Confirm & Submit Receipt';
            amountField.focus();
        });

        async function doReceiptUpload(file, amountStr, refStr, clientConfirmed, btn, btnText, spinner, statusDiv, codeInput, fileInput) {
            btn.disabled = true;
            if (btnText && spinner) {
                btnText.innerText = 'Uploading...';
                spinner.style.display = 'block';
            } else {
                btn.innerText = 'Uploading...';
            }
            statusDiv.style.display = 'block';
            statusDiv.innerText = 'Uploading...';

            var formData = new FormData();
            formData.append('receipt', file);
            formData.append('tracking_code', codeInput.value);
            if (amountStr) {
                formData.append('ocr_amounts', amountStr);
            }
            if (clientConfirmed) {
                formData.append('amount_confirmed', '1');
            }
            if (refStr) {
                formData.append('ocr_reference', refStr);
            }
            var sendingBank = bankSelect ? bankSelect.value : '';
            if (sendingBank === 'Other' && bankOther) {
                sendingBank = bankOther.value;
            }
            formData.append('bank_name', sendingBank);

            // Standard fetch upload since it isn't hitting /track, its hitting /upload-receipt
            var uploadUrl = twellerFlow2Tracker.apiUrl.replace('/track/', '/upload-receipt');

            try {
                var res = await fetch(uploadUrl, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-WP-Nonce': twellerFlow2Tracker.nonce }
                });
                var data = await res.json();

                if (data.success) {
                    if (spinner) spinner.style.display = 'none';
                    statusDiv.innerHTML = '<span style="color:green">Success! Your receipt is pending manual approval.</span>';
                    if (btnText) btnText.innerText = 'Uploaded Successfully';
                    else btn.innerText = 'Uploaded Successfully';
                    fileInput.disabled = true;
                    var panel = document.getElementById('tf2-receipt-confirm');
                    if (panel) panel.style.display = 'none';
                    sessionStorage.setItem('tf_receipt_uploaded', '1');
                    setTimeout(function(){ window.location.reload(); }, 1500);
                } else {
                    if (spinner) spinner.style.display = 'none';
                    statusDiv.innerHTML = '<span style="color:red">' + (data.message || 'Error uploading receipt.') + '</span>';
                    btn.disabled = false;
                    if (btnText) btnText.innerText = 'Confirm & Submit Receipt';
                    else btn.innerText = 'Confirm & Submit Receipt';
                }
            } catch (err) {
                if (spinner) spinner.style.display = 'none';
                statusDiv.innerHTML = '<span style="color:red">Network error during upload.</span>';
                btn.disabled = false;
                if (btnText) btnText.innerText = 'Confirm & Submit Receipt';
                else btn.innerText = 'Confirm & Submit Receipt';
            }
        }
    }

    // ── Gallery ────────────────────────────────────────
    var gallery = document.querySelector('.tf2-gallery[data-code]');
    if (!gallery) return;

    var galleryUrl = twellerFlow2Tracker.galleryUrl;
    if (!galleryUrl) return;

    var galleryToken = sessionStorage.getItem('tf_gallery_token_' + code) || '';
    var photos = [];
    var currentIdx = 0;

    // Elements
    var heroCover   = document.getElementById('tf2-hero-cover');
    var heroCoverBg = document.getElementById('tf2-hero-cover-bg');
    var viewBtn     = document.getElementById('tf2-gallery-view-btn');
    var pwSection   = document.getElementById('tf2-gallery-password');
    var pwForm      = document.getElementById('tf2-gallery-pw-form');
    var pwInput     = document.getElementById('tf2-gallery-pw-input');
    var pwError     = document.getElementById('tf2-gallery-pw-error');
    var toolbar     = document.getElementById('tf2-gallery-toolbar');
    var grid        = document.getElementById('tf2-gallery-grid');
    var downloadAll = document.getElementById('tf2-gallery-download-all');
    var lightbox    = document.getElementById('tf2-lightbox');
    var lbImg       = document.getElementById('tf2-lightbox-img');
    var lbClose     = document.getElementById('tf2-lightbox-close');
    var lbPrev      = document.getElementById('tf2-lightbox-prev');
    var lbNext      = document.getElementById('tf2-lightbox-next');
    var lbCounter   = document.getElementById('tf2-lightbox-counter');
    var lbDownload  = document.getElementById('tf2-lightbox-download');

    var galleryLoaded = false;
    var needsPassword = false;
    var galleryRevealed = false;
    var galleryError = false;

    prefetchGallery();

    function prefetchGallery() {
        var url = galleryUrl + code;
        if (galleryToken) url += '?token=' + encodeURIComponent(galleryToken);

        if (heroCover) {
            heroCover.style.display = '';
            heroCover.classList.add('tf2-hero-cover--loading');
        }

        fetch(url, { headers: { 'X-WP-Nonce': twellerFlow2Tracker.nonce } })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) {
            if (heroCover) heroCover.classList.remove('tf2-hero-cover--loading');

            if (!data.ok) {
                showGalleryMessage('Gallery is being prepared. Please check back soon.');
                return;
            }

            if (!data.ready) {
                showGalleryMessage('Your gallery is being prepared. Please check back soon.');
                return;
            }

            galleryLoaded = true;

            if (data.has_password && !data.unlocked) {
                needsPassword = true;
                if (heroCover) heroCover.style.display = '';
                return;
            }

            photos = data.photos || [];
            if (photos.length === 0) {
                showGalleryMessage('Your photos are being uploaded to the gallery. Please refresh in a moment.');
                return;
            }

            if (photos.length && heroCover && heroCoverBg) {
                applyCover(data.cover, photos);
                heroCover.style.display = '';
            }
        })
        .catch(function(err) {
            galleryError = true;
            if (heroCover) heroCover.classList.remove('tf2-hero-cover--loading');
            showGalleryMessage('Unable to load gallery. Please try refreshing the page.');
            console.error('[Tweller Gallery]', err.message || err);
        });
    }

    // Apply the admin-chosen cover photo and focal position to the hero.
    function applyCover(cover, photoList) {
        if (!heroCoverBg) return;
        var url = (cover && cover.url) ? cover.url : (photoList[0] && photoList[0].url);
        if (!url) return;
        heroCoverBg.style.backgroundImage = 'url(' + url + ')';
        heroCoverBg.style.backgroundPosition = (cover && cover.position) ? cover.position : 'center';
    }

    function showGalleryMessage(msg) {
        var msgEl = document.getElementById('tf2-gallery-message');
        if (!msgEl) {
            msgEl = document.createElement('div');
            msgEl.id = 'tf2-gallery-message';
            msgEl.className = 'tf2-gallery__message';
            gallery.appendChild(msgEl);
        }
        msgEl.textContent = msg;
        msgEl.style.display = 'block';
        if (heroCover) heroCover.style.display = 'none';
    }

    // ── "VIEW GALLERY" button ───────────────────────────
    if (viewBtn) {
        viewBtn.addEventListener('click', function() {
            trackActivity('gallery_opened');

            if (needsPassword) {
                pwSection.style.display = 'block';
                pwSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            if (photos.length) {
                revealGallery();
            } else {
                loadGalleryAndReveal();
            }
        });
    }

    function loadGalleryAndReveal() {
        var url = galleryUrl + code;
        if (galleryToken) url += '?token=' + encodeURIComponent(galleryToken);

        fetch(url, { headers: { 'X-WP-Nonce': twellerFlow2Tracker.nonce } })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) {
            if (!data.ok || !data.ready) {
                showGalleryMessage('Gallery is not ready yet. Please check back soon.');
                return;
            }

            galleryLoaded = true;
            if (data.has_password && !data.unlocked) {
                needsPassword = true;
                pwSection.style.display = 'block';
                pwSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            photos = data.photos || [];
            if (photos.length) {
                revealGallery();
            } else {
                showGalleryMessage('Your photos are being uploaded. Please refresh in a moment.');
            }
        })
        .catch(function(err) {
            showGalleryMessage('Unable to load gallery. Please try refreshing the page.');
            console.error('[Tweller Gallery]', err.message || err);
        });
    }

    function revealGallery() {
        if (galleryRevealed) return;
        galleryRevealed = true;

        var msgEl = document.getElementById('tf2-gallery-message');
        if (msgEl) msgEl.style.display = 'none';

        buildGrid();

        toolbar.style.display = '';
        grid.style.display = '';

        downloadAll.href = galleryUrl + code + '/download-all'
            + (galleryToken ? '?token=' + encodeURIComponent(galleryToken) : '');

        downloadAll.addEventListener('click', function() {
            trackActivity('all_downloaded', photos.length + ' photos');
        });

        setTimeout(function() {
            toolbar.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 100);
    }

    // Password form
    if (pwForm) {
        pwForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var pw = pwInput.value.trim();
            if (!pw) return;

            pwError.style.display = 'none';

            fetch(galleryUrl + code + '/verify', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': twellerFlow2Tracker.nonce
                },
                body: JSON.stringify({ password: pw })
            })
            .then(function(r) {
                if (!r.ok) throw new Error('wrong');
                return r.json();
            })
            .then(function(data) {
                if (data.ok && data.token) {
                    galleryToken = data.token;
                    sessionStorage.setItem('tf_gallery_token_' + code, data.token);
                    pwSection.style.display = 'none';
                    needsPassword = false;
                    loadGalleryAndReveal();
                }
            })
            .catch(function() {
                pwError.style.display = 'block';
                pwInput.value = '';
                pwInput.focus();
            });
        });
    }

    // ── Masonry Grid ──────────────────────────────────

    function buildGrid() {
        grid.innerHTML = '';

        photos.forEach(function(photo, idx) {
            var item = document.createElement('div');
            item.className = 'tf2-gallery__item';

            var img = document.createElement('img');
            img.className = 'loading';
            img.alt = photo.filename;
            img.loading = 'lazy';
            img.src = photo.thumb_url;
            img.onload = function() { img.classList.remove('loading'); };
            img.onerror = function() {
                img.classList.remove('loading');
                img.classList.add('tf2-gallery__img-error');
                img.alt = 'Could not load image';
            };

            item.appendChild(img);
            item.addEventListener('click', function() { openLightbox(idx); });

            // Print store: per-photo order button (only when the store is loaded)
            if (window.TwellerPrints) {
                item.appendChild(makeTilePrintBtn(photo));
            }

            grid.appendChild(item);
        });

        updatePrintBadges();
    }

    // ── Print store integration (window.TwellerPrints from prints.js) ──

    function makeTilePrintBtn(photo) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tf2-item-print';
        btn.title = 'Order a print of this photo';
        btn.setAttribute('data-filename', photo.filename);
        btn.innerHTML =
            '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>' +
            '<span class="tf2-item-print__count" style="display:none;">0</span>';
        btn.addEventListener('click', function(ev) {
            ev.stopPropagation();
            ev.preventDefault();
            if (window.TwellerPrints) {
                window.TwellerPrints.openPicker({
                    filename: photo.filename,
                    url: photo.url,
                    thumb_url: photo.thumb_url
                });
            }
        });
        return btn;
    }

    function updatePrintBadges() {
        if (!window.TwellerPrints) return;

        // Toolbar counter
        var topCount = document.getElementById('tf2-prints-count');
        if (topCount) {
            var total = window.TwellerPrints.getCount();
            topCount.textContent = String(total);
            topCount.style.display = total > 0 ? '' : 'none';
        }

        // Grid tile badges
        var tileBtns = grid ? grid.querySelectorAll('.tf2-item-print') : [];
        for (var i = 0; i < tileBtns.length; i++) {
            var fname = tileBtns[i].getAttribute('data-filename');
            var n = window.TwellerPrints.getCountFor(fname);
            var badge = tileBtns[i].querySelector('.tf2-item-print__count');
            if (badge) {
                badge.textContent = String(n);
                badge.style.display = n > 0 ? '' : 'none';
            }
            if (n > 0) tileBtns[i].classList.add('tf2-item-print--active');
            else tileBtns[i].classList.remove('tf2-item-print--active');
        }

        // Lightbox per-photo badge
        var lbBadge = document.getElementById('tf2-lightbox-print-count');
        if (lbBadge) {
            var photo = photos[currentIdx];
            var c = photo ? window.TwellerPrints.getCountFor(photo.filename) : 0;
            lbBadge.textContent = String(c);
            lbBadge.style.display = c > 0 ? '' : 'none';
        }
    }

    function initPrintsIntegration() {
        if (!window.TwellerPrints) return;

        var topBtn = document.getElementById('tf2-prints-open');
        if (topBtn) {
            topBtn.style.display = '';
            topBtn.addEventListener('click', function() {
                trackActivity('prints_store_opened');
                window.TwellerPrints.openStore();
            });
        }

        var lbPrint = document.getElementById('tf2-lightbox-print');
        if (lbPrint) {
            lbPrint.style.display = '';
            lbPrint.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var photo = photos[currentIdx];
                if (photo) {
                    window.TwellerPrints.openPicker({
                        filename: photo.filename,
                        url: photo.url,
                        thumb_url: photo.thumb_url
                    });
                }
            });
        }

        window.TwellerPrints.onCountChange(updatePrintBadges);
        updatePrintBadges();
    }

    // prints.js is a separate footer script — bind once everything has run
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPrintsIntegration);
    } else {
        initPrintsIntegration();
    }

    // ── Force download helper ─────────────────────────
    function forceDownload(url, filename) {
        fetch(url)
        .then(function(r) { return r.blob(); })
        .then(function(blob) {
            var a = document.createElement('a');
            var blobUrl = URL.createObjectURL(blob);
            a.href = blobUrl;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(blobUrl);
        })
        .catch(function() {
            // Fallback: open in new tab
            window.open(url, '_blank');
        });
    }

    // ── Lightbox ───────────────────────────────────────
    function openLightbox(idx) {
        currentIdx = idx;
        renderLightbox();
        lightbox.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        lightbox.style.display = 'none';
        document.body.style.overflow = '';
    }

    function renderLightbox() {
        var photo = photos[currentIdx];
        if (!photo) return;
        lbImg.src = photo.url;
        lbCounter.textContent = (currentIdx + 1) + ' / ' + photos.length;
        // Store data for download handler
        lbDownload.setAttribute('data-url', photo.url);
        lbDownload.setAttribute('data-filename', photo.filename);
        // Keep the per-photo print counter in sync while navigating
        updatePrintBadges();
    }

    function prevPhoto() {
        currentIdx = (currentIdx - 1 + photos.length) % photos.length;
        renderLightbox();
    }

    function nextPhoto() {
        currentIdx = (currentIdx + 1) % photos.length;
        renderLightbox();
    }

    if (lbClose) lbClose.addEventListener('click', closeLightbox);
    if (lbPrev)  lbPrev.addEventListener('click', prevPhoto);
    if (lbNext)  lbNext.addEventListener('click', nextPhoto);

    // Force download on lightbox download button
    if (lbDownload) {
        lbDownload.addEventListener('click', function(e) {
            e.preventDefault();
            var url = lbDownload.getAttribute('data-url');
            var filename = lbDownload.getAttribute('data-filename');
            if (url && filename) {
                forceDownload(url, filename);
                trackActivity('photo_downloaded', filename);
            }
        });
    }

    // Click backdrop to close
    if (lightbox) {
        lightbox.addEventListener('click', function(e) {
            if (e.target === lightbox || e.target.classList.contains('tf2-lightbox__content')) {
                closeLightbox();
            }
        });
    }

    // Keyboard navigation
    document.addEventListener('keydown', function(e) {
        if (lightbox && lightbox.style.display !== 'none') {
            if (e.key === 'Escape') closeLightbox();
            if (e.key === 'ArrowLeft') prevPhoto();
            if (e.key === 'ArrowRight') nextPhoto();
        }
    });

    // Touch swipe support
    var touchStartX = 0;
    if (lightbox) {
        lightbox.addEventListener('touchstart', function(e) {
            touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });

        lightbox.addEventListener('touchend', function(e) {
            var dx = e.changedTouches[0].screenX - touchStartX;
            if (Math.abs(dx) > 50) {
                if (dx > 0) prevPhoto();
                else nextPhoto();
            }
        }, { passive: true });
    }

    // ── Lightbox controls fade away for an uninterrupted view ──
    var lbIdleTimer = null;

    function lbWake() {
        if (!lightbox) return;
        lightbox.classList.remove('tf2-lightbox--idle');
        clearTimeout(lbIdleTimer);
        lbIdleTimer = setTimeout(function() {
            lightbox.classList.add('tf2-lightbox--idle');
        }, 2500);
    }

    if (lightbox) {
        ['mousemove', 'touchstart', 'click'].forEach(function(evt) {
            lightbox.addEventListener(evt, lbWake, { passive: true });
        });
        // Waking also happens when the image changes (nav/keyboard/swipe all
        // route through renderLightbox via prev/next)
        var _renderLightbox = renderLightbox;
        renderLightbox = function() {
            _renderLightbox();
            lbWake();
        };
    }

    // ── Slideshow ──────────────────────────────────────
    var ssBtn     = document.getElementById('tf2-gallery-slideshow');
    var ss        = document.getElementById('tf2-slideshow');
    var ssLayerA  = document.getElementById('tf2-ss-layer-a');
    var ssLayerB  = document.getElementById('tf2-ss-layer-b');
    var ssClose   = document.getElementById('tf2-ss-close');
    var ssPause   = document.getElementById('tf2-ss-pause');

    var SS_INTERVAL = 4000;  // ms per photo
    var ssTimer = null, ssIdleTimer = null;
    var ssIndex = 0, ssFront = null, ssPaused = false;

    // Slideshow launches straight into the classic (crossfade) mode —
    // there is no style chooser.
    if (ssBtn && ss) {
        ssBtn.addEventListener('click', function() {
            if (!photos.length) return;
            if (selMode) exitSelectMode();
            startSlideshow();
        });
    }

    function startSlideshow() {
        trackActivity('slideshow_played', 'classic');
        ss.className = 'tf2-slideshow';
        ss.style.display = 'block';
        document.body.style.overflow = 'hidden';

        ssIndex = 0;
        ssFront = null;
        ssPaused = false;
        ssLayerA.className = 'tf2-slideshow__layer';
        ssLayerB.className = 'tf2-slideshow__layer';
        ssLayerA.style.backgroundImage = '';
        ssLayerB.style.backgroundImage = '';

        showSlide(0);
        scheduleNext();
        ssWake();

        // Native fullscreen where available (best on desktop)
        try { if (ss.requestFullscreen) ss.requestFullscreen().catch(function(){}); } catch (e) {}
    }

    function showSlide(idx) {
        ssIndex = ((idx % photos.length) + photos.length) % photos.length;
        var incoming = (ssFront === ssLayerA) ? ssLayerB : ssLayerA;
        var outgoing = ssFront;

        var kb = 'tf2-kb-' + (Math.floor(Math.random() * 4) + 1);
        incoming.className = 'tf2-slideshow__layer';
        // Force style reset so the Ken Burns animation restarts cleanly
        void incoming.offsetWidth;
        incoming.style.backgroundImage = 'url("' + photos[ssIndex].url + '")';
        incoming.classList.add('tf2-slideshow__layer--visible', kb);

        if (outgoing) outgoing.classList.remove('tf2-slideshow__layer--visible');
        ssFront = incoming;

        // Preload the next image so the crossfade never stutters
        var nxt = new Image();
        nxt.src = photos[(ssIndex + 1) % photos.length].url;
    }

    function scheduleNext() {
        clearTimeout(ssTimer);
        if (ssPaused) return;
        ssTimer = setTimeout(function() {
            showSlide(ssIndex + 1);
            scheduleNext();
        }, SS_INTERVAL);
    }

    function stopSlideshow() {
        clearTimeout(ssTimer);
        ss.style.display = 'none';
        document.body.style.overflow = '';
        try { if (document.fullscreenElement) document.exitFullscreen().catch(function(){}); } catch (e) {}
    }

    function ssWake() {
        ss.classList.remove('tf2-slideshow--idle');
        clearTimeout(ssIdleTimer);
        ssIdleTimer = setTimeout(function() {
            ss.classList.add('tf2-slideshow--idle');
        }, 2500);
    }

    if (ss) {
        ['mousemove', 'touchstart'].forEach(function(evt) {
            ss.addEventListener(evt, ssWake, { passive: true });
        });

        if (ssClose) ssClose.addEventListener('click', stopSlideshow);

        if (ssPause) ssPause.addEventListener('click', function() {
            ssPaused = !ssPaused;
            ssPause.innerHTML = ssPaused
                ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>'
                : '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>';
            if (!ssPaused) scheduleNext();
            else clearTimeout(ssTimer);
            ssWake();
        });

        document.addEventListener('keydown', function(e) {
            if (ss.style.display === 'none') return;
            if (e.key === 'Escape') stopSlideshow();
            if (e.key === ' ') { e.preventDefault(); ssPause.click(); }
            if (e.key === 'ArrowRight') { showSlide(ssIndex + 1); scheduleNext(); ssWake(); }
            if (e.key === 'ArrowLeft')  { showSlide(ssIndex - 1); scheduleNext(); ssWake(); }
        });

        // Leaving native fullscreen (Esc) also ends the show cleanly
        document.addEventListener('fullscreenchange', function() {
            if (!document.fullscreenElement && ss.style.display !== 'none') {
                clearTimeout(ssTimer);
                ss.style.display = 'none';
                document.body.style.overflow = '';
            }
        });
    }
})();
