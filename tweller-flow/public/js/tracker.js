/**
 * Tweller Flow v2.6 — Client Tracker + Gallery + Activity Tracking
 */
(function() {
    'use strict';

    var tracker = document.querySelector('.tf-tracker[data-code]');
    if (!tracker) return;

    var code = tracker.getAttribute('data-code');
    if (!code || typeof twellerFlowTracker === 'undefined') return;

    // ── Stage auto-refresh ─────────────────────────────
    setInterval(function() {
        fetch(twellerFlowTracker.apiUrl + code, {
            headers: { 'X-WP-Nonce': twellerFlowTracker.nonce }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.stages) {
                updateStages(data);
            }
        })
        .catch(function() {});
    }, 60000);

    function updateStages(data) {
        var stages = tracker.querySelectorAll('.tf-tracker__stage');
        stages.forEach(function(el, idx) {
            el.className = 'tf-tracker__stage';
            if (idx < data.current_stage_index) {
                el.classList.add('tf-tracker__stage--completed');
            } else if (idx === data.current_stage_index) {
                el.classList.add('tf-tracker__stage--current');
            } else {
                el.classList.add('tf-tracker__stage--upcoming');
            }
        });
    }

    // ── Activity Tracking ──────────────────────────────
    function trackActivity(eventType, detail) {
        var url = twellerFlowTracker.galleryUrl + code + '/activity';
        var body = { event: eventType };
        if (detail) body.detail = detail;

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': twellerFlowTracker.nonce
            },
            body: JSON.stringify(body)
        }).catch(function() {}); // fire-and-forget
    }

    // Track page view (gallery_viewed = loaded the tracker page when gallery is ready)
    var gallerySection = document.querySelector('.tf-gallery[data-code]');
    if (gallerySection) {
        trackActivity('gallery_viewed');
    }

    // ── Gallery ────────────────────────────────────────
    var gallery = document.querySelector('.tf-gallery[data-code]');
    if (!gallery) return;

    var galleryUrl = twellerFlowTracker.galleryUrl;
    if (!galleryUrl) return;

    var galleryToken = sessionStorage.getItem('tf_gallery_token_' + code) || '';
    var photos = [];
    var currentIdx = 0;

    // Elements
    var heroCover   = document.getElementById('tf-hero-cover');
    var heroCoverBg = document.getElementById('tf-hero-cover-bg');
    var viewBtn     = document.getElementById('tf-gallery-view-btn');
    var pwSection   = document.getElementById('tf-gallery-password');
    var pwForm      = document.getElementById('tf-gallery-pw-form');
    var pwInput     = document.getElementById('tf-gallery-pw-input');
    var pwError     = document.getElementById('tf-gallery-pw-error');
    var toolbar     = document.getElementById('tf-gallery-toolbar');
    var grid        = document.getElementById('tf-gallery-grid');
    var downloadAll = document.getElementById('tf-gallery-download-all');
    var lightbox    = document.getElementById('tf-lightbox');
    var lbImg       = document.getElementById('tf-lightbox-img');
    var lbClose     = document.getElementById('tf-lightbox-close');
    var lbPrev      = document.getElementById('tf-lightbox-prev');
    var lbNext      = document.getElementById('tf-lightbox-next');
    var lbCounter   = document.getElementById('tf-lightbox-counter');
    var lbDownload  = document.getElementById('tf-lightbox-download');

    var galleryLoaded = false;
    var needsPassword = false;
    var galleryRevealed = false;
    var galleryError = false;

    // Pre-fetch gallery on page load to set up hero cover
    prefetchGallery();

    function prefetchGallery() {
        var url = galleryUrl + code;
        if (galleryToken) url += '?token=' + encodeURIComponent(galleryToken);

        // Show a subtle loading indicator on the hero
        if (heroCover) {
            heroCover.style.display = '';
            heroCover.classList.add('tf-hero-cover--loading');
        }

        fetch(url, { headers: { 'X-WP-Nonce': twellerFlowTracker.nonce } })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) {
            if (heroCover) heroCover.classList.remove('tf-hero-cover--loading');

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

            // Set hero cover background to first photo
            if (photos.length && heroCover && heroCoverBg) {
                heroCoverBg.style.backgroundImage = 'url(' + photos[0].url + ')';
                heroCover.style.display = '';
            }
        })
        .catch(function(err) {
            galleryError = true;
            if (heroCover) heroCover.classList.remove('tf-hero-cover--loading');
            showGalleryMessage('Unable to load gallery. Please try refreshing the page.');
            console.error('[Tweller Gallery]', err.message || err);
        });
    }

    function showGalleryMessage(msg) {
        // Create or update a message element below the gallery section
        var msgEl = document.getElementById('tf-gallery-message');
        if (!msgEl) {
            msgEl = document.createElement('div');
            msgEl.id = 'tf-gallery-message';
            msgEl.className = 'tf-gallery__message';
            gallery.appendChild(msgEl);
        }
        msgEl.textContent = msg;
        msgEl.style.display = 'block';

        // Hide the hero cover if we're showing an error/info message
        if (heroCover) heroCover.style.display = 'none';
    }

    // ── "VIEW GALLERY" button ───────────────────────────
    if (viewBtn) {
        viewBtn.addEventListener('click', function() {
            // Track that client clicked to open gallery
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

        fetch(url, { headers: { 'X-WP-Nonce': twellerFlowTracker.nonce } })
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

        // Hide any message
        var msgEl = document.getElementById('tf-gallery-message');
        if (msgEl) msgEl.style.display = 'none';

        // Build the grid
        buildGrid();

        // Show toolbar and grid
        toolbar.style.display = '';
        grid.style.display = '';

        // Set download all link
        downloadAll.href = galleryUrl + code + '/download-all'
            + (galleryToken ? '?token=' + encodeURIComponent(galleryToken) : '');

        // Track download-all clicks
        downloadAll.addEventListener('click', function() {
            trackActivity('all_downloaded', photos.length + ' photos');
        });

        // Smooth scroll so toolbar is at top
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
                    'X-WP-Nonce': twellerFlowTracker.nonce
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
            item.className = 'tf-gallery__item';

            var img = document.createElement('img');
            img.className = 'loading';
            img.alt = photo.filename;
            img.loading = 'lazy';
            img.src = photo.thumb_url;
            img.onload = function() { img.classList.remove('loading'); };
            img.onerror = function() {
                img.classList.remove('loading');
                img.classList.add('tf-gallery__img-error');
                img.alt = 'Could not load image';
            };

            item.appendChild(img);
            item.addEventListener('click', function() { openLightbox(idx); });
            grid.appendChild(item);
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
        lbDownload.href = photo.url;
        lbDownload.download = photo.filename;
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

    // Track individual photo downloads
    if (lbDownload) {
        lbDownload.addEventListener('click', function() {
            var photo = photos[currentIdx];
            if (photo) {
                trackActivity('photo_downloaded', photo.filename);
            }
        });
    }

    // Click backdrop to close
    if (lightbox) {
        lightbox.addEventListener('click', function(e) {
            if (e.target === lightbox || e.target.classList.contains('tf-lightbox__content')) {
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

    // Touch swipe support for lightbox
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
