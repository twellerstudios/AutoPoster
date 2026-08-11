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
            panel.style.cssText = 'display:none; background:#FAF7F2; border:1px solid #E7DCC5; border-radius:10px; padding:14px 16px; margin:12px 0;';
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
    var lbLike      = document.getElementById('tf2-lightbox-like');

    // Sign-in gate + favourites
    var signinSection = document.getElementById('tf2-gallery-signin');
    var signinForm    = document.getElementById('tf2-gallery-signin-form');
    var signinName    = document.getElementById('tf2-signin-name');
    var signinEmail   = document.getElementById('tf2-signin-email');
    var signinError   = document.getElementById('tf2-signin-error');
    var signinBtn     = document.getElementById('tf2-signin-btn');
    var tabAll        = document.getElementById('tf2-tab-all');
    var tabLiked      = document.getElementById('tf2-tab-liked');
    var likedCountEl  = document.getElementById('tf2-liked-count');
    var downloadLiked = document.getElementById('tf2-gallery-download-liked');

    // Visitor identity persists across visits (localStorage), so a returning
    // guest is never asked for their details twice.
    var VISITOR_KEY = 'tf_gallery_visitor_' + code;
    var visitorToken = '';
    try { visitorToken = localStorage.getItem(VISITOR_KEY) || ''; } catch (e) {}
    var visitorReady = false;      // resolved (resumed or signed in) this session
    var visitorResolvePromise = null; // shared in-flight token resume (warmed on load)
    var likedIds = {};             // photo_id -> true
    var viewMode = 'all';          // 'all' | 'liked'
    var pendingReveal = false;     // reveal once the visitor is resolved

    var galleryLoaded = false;
    var needsPassword = false;
    var galleryRevealed = false;
    var galleryError = false;

    prefetchGallery();

    // Warm up a returning visitor's identity in parallel with the page load,
    // so it isn't a fresh network wait the moment they click VIEW GALLERY.
    if (visitorToken) resolveVisitor(function() {});

    function galleryEndpoint() {
        return galleryUrl + code + (galleryToken ? '?token=' + encodeURIComponent(galleryToken) : '');
    }

    // One in-flight gallery request, shared by the prefetch on load and the
    // VIEW GALLERY button. Before this, clicking the button while the prefetch
    // was still running fired a SECOND identical fetch and gave no feedback —
    // so on a big gallery or a slow connection the button felt dead for
    // seconds. Now the click reuses whatever is already loading.
    var galleryPromise = null;
    function loadGalleryData(force) {
        if (force) galleryPromise = null;
        if (!galleryPromise) {
            galleryPromise = fetch(galleryEndpoint(), { headers: { 'X-WP-Nonce': twellerFlow2Tracker.nonce } })
                .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
            // Clear the cache on failure so a later click retries cleanly.
            galleryPromise.catch(function() { galleryPromise = null; });
        }
        return galleryPromise;
    }

    function prefetchGallery() {
        if (heroCover) {
            heroCover.style.display = '';
            heroCover.classList.add('tf2-hero-cover--loading');
        }

        loadGalleryData()
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

    // Instant feedback so the button never looks unresponsive while data loads.
    function setViewBtnBusy(on) {
        if (!viewBtn) return;
        if (on) {
            if (viewBtn.getAttribute('data-label') === null) {
                viewBtn.setAttribute('data-label', viewBtn.textContent);
            }
            viewBtn.textContent = 'Loading…';
            viewBtn.setAttribute('aria-busy', 'true');
            viewBtn.style.opacity = '0.75';
        } else {
            var lbl = viewBtn.getAttribute('data-label');
            if (lbl !== null) viewBtn.textContent = lbl;
            viewBtn.removeAttribute('aria-busy');
            viewBtn.style.opacity = '';
        }
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

            // Data already in — reveal instantly.
            if (photos.length) {
                revealGallery();
                return;
            }

            // Not in yet — reuse the in-flight prefetch and show the button
            // working, so it never appears frozen while the fetch completes.
            setViewBtnBusy(true);
            loadGalleryAndReveal(false);
        });
    }

    function loadGalleryAndReveal(force) {
        loadGalleryData(force)
        .then(function(data) {
            setViewBtnBusy(false);
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
            setViewBtnBusy(false);
            showGalleryMessage('Unable to load gallery. Please try refreshing the page.');
            console.error('[Tweller Gallery]', err.message || err);
        });
    }

    function revealGallery() {
        if (galleryRevealed) return;

        // The name/email gate stands in front of every gallery. A returning
        // guest is resolved silently from their saved token; a new one is
        // asked once. Nothing below runs until we know who is looking.
        if (!visitorReady) {
            resolveVisitor(function(ok) {
                if (ok) revealGallery();
                else showSignin();
            });
            return;
        }

        galleryRevealed = true;
        hideSignin();

        var msgEl = document.getElementById('tf2-gallery-message');
        if (msgEl) msgEl.style.display = 'none';

        buildGrid();

        if (toolbar) toolbar.style.display = '';
        if (grid) grid.style.display = '';

        if (downloadAll) {
            downloadAll.href = galleryUrl + code + '/download-all'
                + (galleryToken ? '?token=' + encodeURIComponent(galleryToken) : '');

            downloadAll.addEventListener('click', function() {
                if (selMode) exitSelectMode();
                trackActivity('all_downloaded', photos.length + ' photos');
            });
        }

        wireFavouritesUI();
        refreshLikedUI();

        setTimeout(function() {
            if (toolbar) toolbar.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 100);
    }

    // ── Visitor sign-in (name + email, remembered) ─────

    // Resume a saved visitor from their token; cb(true) if recognised. The
    // network round-trip is shared and started early (see the warm-up on
    // load), so by the time a returning visitor clicks VIEW GALLERY their
    // identity is usually already resolved and the reveal is instant — no
    // waiting on a request at click time. (visitorResolvePromise is declared
    // with the other visitor state above, so the warm-up isn't wiped by a
    // later re-initialisation.)
    function resolveVisitor(cb) {
        if (visitorReady) { cb(true); return; }
        if (!visitorToken) { cb(false); return; }
        if (!visitorResolvePromise) {
            visitorResolvePromise = new Promise(function(resolve) {
                postVisitor({ token: visitorToken }, function(data) {
                    if (data && data.ok) { adoptVisitor(data); resolve(true); }
                    else { clearVisitor(); resolve(false); }
                }, function() { resolve(false); });
            });
        }
        visitorResolvePromise.then(cb);
    }

    function adoptVisitor(data) {
        visitorReady = true;
        visitorToken = data.token || visitorToken;
        try { localStorage.setItem(VISITOR_KEY, visitorToken); } catch (e) {}
        likedIds = {};
        (data.likes || []).forEach(function(id) { likedIds[id] = true; });
    }

    function clearVisitor() {
        visitorToken = '';
        try { localStorage.removeItem(VISITOR_KEY); } catch (e) {}
    }

    function postVisitor(body, onOk, onErr) {
        fetch(galleryUrl + code + '/visitor', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': twellerFlow2Tracker.nonce },
            body: JSON.stringify(body)
        })
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, body: j }; }); })
        .then(function(res) {
            if (res.ok && res.body && res.body.ok) onOk(res.body);
            else if (onOk) onOk(res.body && res.body.ok ? res.body : null);
        })
        .catch(function(e) { if (onErr) onErr(e); });
    }

    function showSignin() {
        if (heroCover) heroCover.style.display = 'none';
        if (pwSection) pwSection.style.display = 'none';
        var msgEl = document.getElementById('tf2-gallery-message');
        if (msgEl) msgEl.style.display = 'none';
        if (signinSection) {
            // 'flex', not 'block' — the CSS centres the card with flexbox, and
            // an inline display:block would silently override that and shove
            // the card to the left on desktop.
            signinSection.style.display = 'flex';
            signinSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function hideSignin() {
        if (signinSection) signinSection.style.display = 'none';
    }

    if (signinForm) {
        signinForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var name = (signinName.value || '').trim();
            var email = (signinEmail.value || '').trim();
            if (!name || email.indexOf('@') < 1) {
                if (signinError) signinError.style.display = 'block';
                return;
            }
            if (signinError) signinError.style.display = 'none';
            if (signinBtn) { signinBtn.disabled = true; signinBtn.textContent = 'One moment…'; }

            postVisitor({ name: name, email: email }, function(data) {
                if (signinBtn) { signinBtn.disabled = false; signinBtn.textContent = 'View Gallery'; }
                if (data && data.ok) {
                    adoptVisitor(data);
                    hideSignin();
                    revealGallery();
                } else {
                    if (signinError) { signinError.textContent = 'Please enter your name and a valid email.'; signinError.style.display = 'block'; }
                }
            }, function() {
                if (signinBtn) { signinBtn.disabled = false; signinBtn.textContent = 'View Gallery'; }
                if (signinError) { signinError.textContent = 'Something went wrong. Please try again.'; signinError.style.display = 'block'; }
            });
        });
    }

    // ── Favourites: like toggle, tabs, download ────────

    var favWired = false;
    function wireFavouritesUI() {
        if (favWired) return;
        favWired = true;

        if (tabAll)   tabAll.addEventListener('click', function() { setViewMode('all'); });
        if (tabLiked) tabLiked.addEventListener('click', function() { setViewMode('liked'); });

        if (downloadLiked) {
            downloadLiked.addEventListener('click', function(e) {
                if (!likedCount()) { e.preventDefault(); return; }
                trackActivity('liked_downloaded', likedCount() + ' liked photos');
            });
        }

        if (lbLike) {
            lbLike.addEventListener('click', function() {
                var photo = photos[currentIdx];
                if (photo) toggleLike(photo.id);
            });
        }
    }

    function likedCount() {
        var n = 0;
        for (var k in likedIds) { if (likedIds[k]) n++; }
        return n;
    }

    function isLiked(photoId) { return !!likedIds[photoId]; }

    // Toggle a like optimistically, then persist. On failure we roll back so
    // the heart never lies about what the server actually stored.
    function toggleLike(photoId) {
        if (!visitorReady) { showSignin(); return; }
        var next = !likedIds[photoId];
        likedIds[photoId] = next;
        applyLikeState(photoId);
        refreshLikedUI();

        fetch(galleryUrl + code + '/like', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': twellerFlow2Tracker.nonce },
            body: JSON.stringify({ token: visitorToken, photo_id: photoId, liked: next ? 1 : 0 })
        })
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, body: j }; }); })
        .then(function(res) {
            if (!res.ok || !res.body || !res.body.ok) {
                likedIds[photoId] = !next; // roll back
                applyLikeState(photoId);
                refreshLikedUI();
            }
        })
        .catch(function() {
            likedIds[photoId] = !next;
            applyLikeState(photoId);
            refreshLikedUI();
        });
    }

    // Reflect one photo's like state on its grid tile + the lightbox.
    function applyLikeState(photoId) {
        if (grid) {
            var tiles = grid.querySelectorAll('.tf2-gallery__item[data-photo-id="' + photoId + '"]');
            for (var i = 0; i < tiles.length; i++) {
                var on = isLiked(photoId);
                tiles[i].classList.toggle('tf2-gallery__item--liked', on);
                var btn = tiles[i].querySelector('.tf2-like');
                if (btn) btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            }
        }
        var cur = photos[currentIdx];
        if (lbLike && cur && cur.id === photoId) syncLightboxLike();
    }

    function refreshLikedUI() {
        var n = likedCount();
        if (likedCountEl) likedCountEl.textContent = String(n);
        if (tabLiked) tabLiked.classList.toggle('tf2-gtab--has', n > 0);
        if (downloadLiked) {
            downloadLiked.style.display = n > 0 ? '' : 'none';
            // Keep the href current so the link always downloads the latest
            // favourites even if the click handler is bypassed.
            if (visitorToken) {
                downloadLiked.href = galleryUrl + code + '/download-liked?token=' + encodeURIComponent(visitorToken);
            }
        }
        if (viewMode === 'liked') applyViewFilter();
    }

    function setViewMode(mode) {
        viewMode = (mode === 'liked') ? 'liked' : 'all';
        if (tabAll) {
            tabAll.classList.toggle('tf2-gtab--active', viewMode === 'all');
            tabAll.setAttribute('aria-selected', viewMode === 'all' ? 'true' : 'false');
        }
        if (tabLiked) {
            tabLiked.classList.toggle('tf2-gtab--active', viewMode === 'liked');
            tabLiked.setAttribute('aria-selected', viewMode === 'liked' ? 'true' : 'false');
        }
        applyViewFilter();
    }

    // In "liked" view, hide the tiles that aren't liked. No re-index — the
    // grid keeps its data-idx, so the lightbox and print selection are
    // unaffected; only visibility changes.
    function applyViewFilter() {
        if (!grid) return;
        var showingLiked = (viewMode === 'liked');
        grid.classList.toggle('tf2-gallery__grid--liked', showingLiked);
        var tiles = grid.querySelectorAll('.tf2-gallery__item');
        var shown = 0;
        for (var i = 0; i < tiles.length; i++) {
            var pid = parseInt(tiles[i].getAttribute('data-photo-id'), 10);
            var vis = !showingLiked || isLiked(pid);
            tiles[i].style.display = vis ? '' : 'none';
            if (vis) shown++;
        }
        var empty = document.getElementById('tf2-liked-empty');
        if (showingLiked && shown === 0) {
            if (!empty) {
                empty = document.createElement('div');
                empty.id = 'tf2-liked-empty';
                empty.className = 'tf2-gallery__liked-empty';
                empty.innerHTML = 'No favourites yet — tap the &#9825; on any photo to save it here.';
                grid.parentNode.insertBefore(empty, grid.nextSibling);
            }
            empty.style.display = '';
        } else if (empty) {
            empty.style.display = 'none';
        }
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
                    // Force a fresh fetch: the unlock token changed, so the
                    // cached (locked) prefetch must not be reused.
                    loadGalleryAndReveal(true);
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
        if (!grid) return;
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
            item.setAttribute('data-idx', String(idx));
            item.setAttribute('data-photo-id', String(photo.id));
            if (isLiked(photo.id)) item.classList.add('tf2-gallery__item--liked');
            item.addEventListener('click', function() {
                if (selMode) {
                    toggleSelect(idx);
                } else {
                    openLightbox(idx);
                }
            });

            // Favourite heart — always available to a signed-in visitor.
            item.appendChild(makeLikeBtn(photo));

            // Print store: per-photo order button (only when the store is loaded)
            if (window.TwellerPrints) {
                item.appendChild(makeTilePrintBtn(photo));
            }

            // Batch-selection tick (inert until selection mode is on)
            item.appendChild(makeSelectCheck());

            grid.appendChild(item);
        });

        updatePrintBadges();
        syncSelectionUI();
        applyViewFilter();
    }

    // The heart overlay on a grid tile. Elegant but unmissable: a soft
    // circle that fills gold when liked. stopPropagation keeps a like from
    // also opening the lightbox.
    function makeLikeBtn(photo) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tf2-like';
        btn.setAttribute('aria-label', 'Like this photo');
        btn.setAttribute('aria-pressed', isLiked(photo.id) ? 'true' : 'false');
        btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/></svg>';
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleLike(photo.id);
        });
        return btn;
    }

    function makeSelectCheck() {
        var chk = document.createElement('span');
        chk.className = 'tf2-printsel-check';
        chk.setAttribute('aria-hidden', 'true');
        chk.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        return chk;
    }

    // ── Print store integration (window.TwellerPrints from prints.js) ──

    // Feature detection — prints.js is a separate, independently versioned
    // script, so every entry point is guarded before it is shown or called.
    function printsApi() {
        return window.TwellerPrints || null;
    }

    function printsCan(method) {
        var api = printsApi();
        return !!(api && typeof api[method] === 'function');
    }

    // Normalise a gallery photo into the shape the print store expects.
    function printPayload(photo) {
        return {
            id: photo.id,
            filename: photo.filename,
            url: photo.url,
            thumb_url: photo.thumb_url
        };
    }

    // Per-photo order: openOrder() is the current contract, openPicker()
    // is the legacy name — support whichever the loaded store exposes.
    function openSinglePrint(photo) {
        if (!photo) return;
        try {
            if (printsCan('openOrder')) {
                window.TwellerPrints.openOrder(printPayload(photo));
            } else if (printsCan('openPicker')) {
                window.TwellerPrints.openPicker(printPayload(photo));
            }
        } catch (e) {}
    }

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
            // While picking photos in bulk the tile print icon is hidden, but
            // guard anyway so a stray tap toggles selection rather than
            // opening the single-photo flow.
            if (selMode) {
                var tile = btn.parentNode;
                if (tile) toggleSelect(parseInt(tile.getAttribute('data-idx'), 10));
                return;
            }
            openSinglePrint(photo);
        });
        return btn;
    }

    function countFor(filename) {
        if (!filename || !printsCan('getCountFor')) return 0;
        try {
            return window.TwellerPrints.getCountFor(filename) || 0;
        } catch (e) {
            return 0;
        }
    }

    function updatePrintBadges() {
        if (!printsApi()) return;

        // Toolbar cart-count badge on "Order Prints"
        var topCount = document.getElementById('tf2-prints-count');
        if (topCount && printsCan('getCount')) {
            var total = 0;
            try { total = window.TwellerPrints.getCount() || 0; } catch (e) { total = 0; }
            topCount.textContent = String(total);
            topCount.style.display = total > 0 ? '' : 'none';
        }

        // Grid tile badges
        var tileBtns = grid ? grid.querySelectorAll('.tf2-item-print') : [];
        for (var i = 0; i < tileBtns.length; i++) {
            var n = countFor(tileBtns[i].getAttribute('data-filename'));
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
            var c = photo ? countFor(photo.filename) : 0;
            lbBadge.textContent = String(c);
            lbBadge.style.display = c > 0 ? '' : 'none';
        }
    }

    // Batch-selection elements (declared before initPrintsIntegration, which
    // may run synchronously and needs selEnterBtn)
    var selEnterBtn = document.getElementById('tf2-printsel-enter');
    var selBar      = document.getElementById('tf2-printsel-bar');
    var selCountEl  = document.getElementById('tf2-printsel-count');
    var selAllBtn   = document.getElementById('tf2-printsel-all');
    var selClearBtn = document.getElementById('tf2-printsel-clear');
    var selDoneBtn  = document.getElementById('tf2-printsel-done');
    var selNextBtn  = document.getElementById('tf2-printsel-continue');

    var selMode = false;
    var selected = {};   // photo index -> true

    function initPrintsIntegration() {
        // No print store on the page → every print entry point stays hidden.
        if (!printsApi()) return;

        var topBtn = document.getElementById('tf2-prints-open');
        if (topBtn && printsCan('openStore')) {
            topBtn.style.display = '';
            topBtn.addEventListener('click', function() {
                trackActivity('prints_store_opened');
                try { window.TwellerPrints.openStore(); } catch (e) {}
            });
        }

        var lbPrint = document.getElementById('tf2-lightbox-print');
        if (lbPrint && (printsCan('openOrder') || printsCan('openPicker'))) {
            lbPrint.style.display = '';
            lbPrint.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                openSinglePrint(photos[currentIdx]);
            });
        }

        // Batch selection only makes sense when the store can receive a batch
        if (selEnterBtn && printsCan('openBatchOrder')) {
            selEnterBtn.style.display = '';
        }

        if (printsCan('onCountChange')) {
            window.TwellerPrints.onCountChange(updatePrintBadges);
        }
        updatePrintBadges();
    }

    // prints.js is a separate footer script — bind once everything has run
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPrintsIntegration);
    } else {
        initPrintsIntegration();
    }

    // ── Batch print selection ──────────────────────────
    // Namespaced tf2-printsel-* so it can never collide with the culling
    // shortcode's tc-* selection UI.

    function selCount() {
        var n = 0, k;
        for (k in selected) {
            if (Object.prototype.hasOwnProperty.call(selected, k)) n++;
        }
        return n;
    }

    function enterSelectMode() {
        if (selMode || !grid || !printsCan('openBatchOrder')) return;
        selMode = true;
        selected = {};
        grid.classList.add('tf2-printsel-on');
        if (gallery) gallery.classList.add('tf2-gallery--printsel');
        if (selBar) selBar.style.display = 'block';
        if (selEnterBtn) selEnterBtn.classList.add('tf2-gbtn--on');
        syncSelectionUI();
        trackActivity('prints_batch_select_started');
    }

    function exitSelectMode() {
        if (!selMode) return;
        selMode = false;
        selected = {};
        if (grid) grid.classList.remove('tf2-printsel-on');
        if (gallery) gallery.classList.remove('tf2-gallery--printsel');
        if (selBar) selBar.style.display = 'none';
        if (selEnterBtn) selEnterBtn.classList.remove('tf2-gbtn--on');
        syncSelectionUI();
    }

    function toggleSelect(idx) {
        if (isNaN(idx) || !photos[idx]) return;
        if (selected[idx]) delete selected[idx];
        else selected[idx] = true;
        syncSelectionUI();
    }

    function syncSelectionUI() {
        if (!grid) return;
        var tiles = grid.querySelectorAll('.tf2-gallery__item');
        for (var i = 0; i < tiles.length; i++) {
            var idx = parseInt(tiles[i].getAttribute('data-idx'), 10);
            var on = selMode && !!selected[idx];
            if (on) tiles[i].classList.add('tf2-gallery__item--printsel');
            else tiles[i].classList.remove('tf2-gallery__item--printsel');
            if (selMode) tiles[i].setAttribute('aria-pressed', on ? 'true' : 'false');
            else tiles[i].removeAttribute('aria-pressed');
        }

        var n = selCount();
        if (selCountEl) {
            selCountEl.textContent = n === 1 ? '1 selected' : n + ' selected';
        }
        if (selNextBtn) selNextBtn.disabled = n === 0;
        if (selAllBtn) {
            selAllBtn.textContent = '';
            var lbl = document.createElement('span');
            lbl.className = 'tf2-gbtn__label';
            lbl.textContent = (n > 0 && n === photos.length) ? 'Deselect all' : 'Select all';
            selAllBtn.appendChild(lbl);
        }
    }

    if (selEnterBtn) {
        selEnterBtn.addEventListener('click', function() {
            if (selMode) exitSelectMode();
            else enterSelectMode();
        });
    }

    if (selAllBtn) {
        selAllBtn.addEventListener('click', function() {
            if (selCount() === photos.length) {
                selected = {};
            } else {
                selected = {};
                for (var i = 0; i < photos.length; i++) selected[i] = true;
            }
            syncSelectionUI();
        });
    }

    if (selClearBtn) {
        selClearBtn.addEventListener('click', function() {
            selected = {};
            syncSelectionUI();
        });
    }

    if (selDoneBtn) selDoneBtn.addEventListener('click', exitSelectMode);

    // Escape leaves selection mode — but only when no overlay owns the key
    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Escape' || !selMode) return;
        if (lightbox && lightbox.style.display !== 'none') return;
        if (ss && ss.style.display !== 'none') return;
        exitSelectMode();
    });

    if (selNextBtn) {
        selNextBtn.addEventListener('click', function() {
            if (!printsCan('openBatchOrder')) return;
            var batch = [];
            for (var i = 0; i < photos.length; i++) {
                if (selected[i]) batch.push(printPayload(photos[i]));
            }
            if (!batch.length) return;
            trackActivity('prints_batch_selected', batch.length + ' photos');
            try {
                window.TwellerPrints.openBatchOrder(batch, {});
                exitSelectMode();
            } catch (e) {}
        });
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
    // The lightbox navigates the CURRENTLY VISIBLE set: all photos, or just
    // the liked ones when the Liked tab is active. navIndices holds indices
    // into the canonical `photos` array, so print selection and downloads
    // (which use `photos`) are untouched.
    var navIndices = [];
    var navPos = 0;

    function currentNav() {
        var out = [];
        for (var i = 0; i < photos.length; i++) {
            if (viewMode !== 'liked' || isLiked(photos[i].id)) out.push(i);
        }
        return out;
    }

    function openLightbox(idx) {
        navIndices = currentNav();
        navPos = navIndices.indexOf(idx);
        if (navPos === -1) {
            if (!navIndices.length) return;
            navPos = 0;
        }
        currentIdx = navIndices[navPos];
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
        lbCounter.textContent = (navPos + 1) + ' / ' + navIndices.length;
        // Store data for download handler
        lbDownload.setAttribute('data-url', photo.url);
        lbDownload.setAttribute('data-filename', photo.filename);
        syncLightboxLike();
        // Keep the per-photo print counter in sync while navigating
        updatePrintBadges();
    }

    function syncLightboxLike() {
        if (!lbLike) return;
        var photo = photos[currentIdx];
        var on = photo ? isLiked(photo.id) : false;
        lbLike.classList.toggle('tf2-lightbox__like--on', on);
        lbLike.setAttribute('aria-pressed', on ? 'true' : 'false');
        var lbl = lbLike.querySelector('.tf2-lightbox__like-label');
        if (lbl) lbl.textContent = on ? 'Liked' : 'Like';
    }

    function prevPhoto() {
        if (!navIndices.length) return;
        navPos = (navPos - 1 + navIndices.length) % navIndices.length;
        currentIdx = navIndices[navPos];
        renderLightbox();
    }

    function nextPhoto() {
        if (!navIndices.length) return;
        navPos = (navPos + 1) % navIndices.length;
        currentIdx = navIndices[navPos];
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

        incoming.className = 'tf2-slideshow__layer';
        // Force a style flush so the crossfade restarts cleanly
        void incoming.offsetWidth;
        incoming.style.backgroundImage = 'url("' + photos[ssIndex].url + '")';
        incoming.classList.add('tf2-slideshow__layer--visible');

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
