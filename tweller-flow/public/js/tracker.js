/**
 * Tweller Flow v2.8 — Client Tracker + Gallery + Activity Tracking
 */
(function() {
    'use strict';

    var tracker = document.querySelector('.tf2-tracker[data-code]');
    if (!tracker) return;

    var code = tracker.getAttribute('data-code');
    if (!code || typeof twellerFlow2Tracker === 'undefined') return;

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

            btn.disabled = true;
            if (btnText && spinner) {
                btnText.innerText = 'Please wait...';
                spinner.style.display = 'block';
            } else {
                btn.innerText = 'Please wait...';
            }
            statusDiv.style.display = 'block';
            statusDiv.innerText = 'Please wait...';

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

            if (btnText) btnText.innerText = 'Uploading...';
            else btn.innerText = 'Uploading...';
            statusDiv.innerText = 'Uploading...';

            var formData = new FormData();
            formData.append('receipt', file);
            formData.append('tracking_code', codeInput.value);
            if (extractedAmount) {
                formData.append('ocr_amounts', extractedAmount);
            }
            if (extractedRef) {
                formData.append('ocr_reference', extractedRef);
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
                    statusDiv.innerHTML = '<span style="color:green">Success! Your receipt is pending manual approval.</span>';
                    btn.innerText = 'Uploaded Successfully';
                    fileInput.disabled = true;
                    sessionStorage.setItem('tf_receipt_uploaded', '1');
                    setTimeout(function(){ window.location.reload(); }, 1500);
                } else {
                    statusDiv.innerHTML = '<span style="color:red">' + (data.message || 'Error uploading receipt.') + '</span>';
                    btn.disabled = false;
                    btn.innerText = 'Try Again';
                }
            } catch (err) {
                statusDiv.innerHTML = '<span style="color:red">Network error during upload.</span>';
                btn.disabled = false;
                btn.innerText = 'Try Again';
            }
        });
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
            grid.appendChild(item);
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
})();
