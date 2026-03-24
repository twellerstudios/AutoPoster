/**
 * Tweller Flow v2 — Client Tracker + Ken Burns Album
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

    // ── Gallery ────────────────────────────────────────
    var gallery = document.querySelector('.tf-gallery[data-code]');
    if (!gallery) return;

    var galleryUrl = twellerFlowTracker.galleryUrl;
    if (!galleryUrl) return;

    var galleryToken = sessionStorage.getItem('tf_gallery_token_' + code) || '';
    var photos = [];
    var currentIdx = 0;
    var heroIdx = 0;
    var heroTimer = null;

    // Gallery elements
    var cta         = document.getElementById('tf-gallery-cta');
    var viewBtn     = document.getElementById('tf-gallery-view-btn');
    var pwSection   = document.getElementById('tf-gallery-password');
    var pwForm      = document.getElementById('tf-gallery-pw-form');
    var pwInput     = document.getElementById('tf-gallery-pw-input');
    var pwError     = document.getElementById('tf-gallery-pw-error');
    var hero        = document.getElementById('tf-hero');
    var heroImg     = document.getElementById('tf-hero-img');
    var heroPrev    = document.getElementById('tf-hero-prev');
    var heroNext    = document.getElementById('tf-hero-next');
    var heroDots    = document.getElementById('tf-hero-dots');
    var heroCounter = document.getElementById('tf-hero-counter');
    var grid        = document.getElementById('tf-gallery-grid');
    var actions     = document.getElementById('tf-gallery-actions');
    var downloadAll = document.getElementById('tf-gallery-download-all');
    var lightbox    = document.getElementById('tf-lightbox');
    var lbImg       = document.getElementById('tf-lightbox-img');
    var lbClose     = document.getElementById('tf-lightbox-close');
    var lbPrev      = document.getElementById('tf-lightbox-prev');
    var lbNext      = document.getElementById('tf-lightbox-next');
    var lbCounter   = document.getElementById('tf-lightbox-counter');
    var lbDownload  = document.getElementById('tf-lightbox-download');

    // Ken Burns album elements
    var album       = document.getElementById('tf-album');
    var albumSlideA = document.getElementById('tf-album-slide-a');
    var albumSlideB = document.getElementById('tf-album-slide-b');
    var albumImgA   = document.getElementById('tf-album-img-a');
    var albumImgB   = document.getElementById('tf-album-img-b');
    var albumCounter= document.getElementById('tf-album-counter');
    var albumPrev   = document.getElementById('tf-album-prev');
    var albumNext   = document.getElementById('tf-album-next');
    var albumClose  = document.getElementById('tf-album-close');
    var albumIdx    = 0;
    var albumTimer  = null;
    var albumActiveSlide = 'a'; // which slide is currently visible
    var albumOpen   = false;

    // Track whether gallery has loaded data (to avoid re-fetching)
    var galleryLoaded = false;
    var needsPassword = false;

    // ── "View Album" button ─────────────────────────────
    if (viewBtn) {
        viewBtn.addEventListener('click', function() {
            if (galleryLoaded && photos.length) {
                // Already loaded, go straight to album
                openAlbum();
            } else if (galleryLoaded && needsPassword) {
                // Show password gate
                cta.style.display = 'none';
                pwSection.style.display = 'block';
            } else {
                // Fetch gallery first
                loadGalleryForAlbum();
            }
        });
    }

    // Pre-fetch gallery info on page load (to know if password needed)
    prefetchGallery();

    function prefetchGallery() {
        var url = galleryUrl + code;
        if (galleryToken) url += '?token=' + encodeURIComponent(galleryToken);

        fetch(url, { headers: { 'X-WP-Nonce': twellerFlowTracker.nonce } })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok || !data.ready) return;

            galleryLoaded = true;
            if (data.has_password && !data.unlocked) {
                needsPassword = true;
                return;
            }

            photos = data.photos || [];
        })
        .catch(function() {});
    }

    function loadGalleryForAlbum() {
        var url = galleryUrl + code;
        if (galleryToken) url += '?token=' + encodeURIComponent(galleryToken);

        fetch(url, { headers: { 'X-WP-Nonce': twellerFlowTracker.nonce } })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok || !data.ready) return;

            galleryLoaded = true;
            if (data.has_password && !data.unlocked) {
                needsPassword = true;
                cta.style.display = 'none';
                pwSection.style.display = 'block';
                return;
            }

            photos = data.photos || [];
            if (photos.length) openAlbum();
        })
        .catch(function() {});
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
                    // Reload gallery with token, then open album
                    loadGalleryForAlbum();
                }
            })
            .catch(function() {
                pwError.style.display = 'block';
                pwInput.value = '';
                pwInput.focus();
            });
        });
    }

    // ── Ken Burns Album ──────────────────────────────────

    function openAlbum() {
        if (!photos.length || !album) return;

        albumOpen = true;
        albumIdx = 0;
        cta.style.display = 'none';

        // Show album
        album.style.display = '';

        // Load first image into slide A
        albumActiveSlide = 'a';
        albumImgA.src = photos[0].url;
        albumImgA.alt = photos[0].filename;
        albumSlideA.className = 'tf-album__slide tf-album__slide--active';
        albumSlideA.style.animation = '';
        albumImgA.style.animation = 'none';
        // Force reflow then start animation
        void albumImgA.offsetWidth;
        albumImgA.style.animation = '';

        albumSlideB.className = 'tf-album__slide';
        albumCounter.textContent = '1 / ' + photos.length;

        // Scroll to top smoothly
        window.scrollTo({ top: 0, behavior: 'smooth' });

        // Start auto-advance
        startAlbumTimer();

        // Also show the gallery grid below
        showGallery(photos);
    }

    function closeAlbum() {
        albumOpen = false;
        clearInterval(albumTimer);
        album.style.display = 'none';
        cta.style.display = '';
        // Hide grid/hero/actions
        if (hero) hero.style.display = 'none';
        grid.style.display = 'none';
        actions.style.display = 'none';
    }

    function albumGo(dir) {
        albumIdx = (albumIdx + dir + photos.length) % photos.length;
        renderAlbumSlide();
        resetAlbumTimer();
    }

    function renderAlbumSlide() {
        var photo = photos[albumIdx];
        if (!photo) return;

        // Alternate zoom direction for visual variety
        var zoomOut = albumIdx % 2 === 1;

        // Crossfade: load into inactive slide, then swap
        if (albumActiveSlide === 'a') {
            // Load into B, fade B in, fade A out
            albumImgB.src = photo.url;
            albumImgB.alt = photo.filename;
            albumImgB.style.animation = 'none';
            void albumImgB.offsetWidth;
            albumImgB.style.animation = '';

            albumSlideB.className = 'tf-album__slide tf-album__slide--active' + (zoomOut ? ' tf-album__slide--zoom-out' : '');
            albumSlideA.className = 'tf-album__slide';
            albumActiveSlide = 'b';
        } else {
            // Load into A, fade A in, fade B out
            albumImgA.src = photo.url;
            albumImgA.alt = photo.filename;
            albumImgA.style.animation = 'none';
            void albumImgA.offsetWidth;
            albumImgA.style.animation = '';

            albumSlideA.className = 'tf-album__slide tf-album__slide--active' + (zoomOut ? ' tf-album__slide--zoom-out' : '');
            albumSlideB.className = 'tf-album__slide';
            albumActiveSlide = 'a';
        }

        albumCounter.textContent = (albumIdx + 1) + ' / ' + photos.length;
    }

    function startAlbumTimer() {
        albumTimer = setInterval(function() {
            albumIdx = (albumIdx + 1) % photos.length;
            renderAlbumSlide();
        }, 7000); // 7 seconds per slide to match Ken Burns duration
    }

    function resetAlbumTimer() {
        clearInterval(albumTimer);
        startAlbumTimer();
    }

    if (albumPrev) albumPrev.addEventListener('click', function() { albumGo(-1); });
    if (albumNext) albumNext.addEventListener('click', function() { albumGo(1); });
    if (albumClose) albumClose.addEventListener('click', closeAlbum);

    // Album touch swipe
    var albumTouchX = 0;
    if (album) {
        album.addEventListener('touchstart', function(e) {
            albumTouchX = e.changedTouches[0].screenX;
        }, { passive: true });
        album.addEventListener('touchend', function(e) {
            var dx = e.changedTouches[0].screenX - albumTouchX;
            if (Math.abs(dx) > 50) {
                albumGo(dx > 0 ? -1 : 1);
            }
        }, { passive: true });
    }

    // ── Gallery grid display (below album) ──────────────

    function showGallery(photoList) {
        photos = photoList;
        if (!photos.length) return;

        // ── Hero Slider ──
        if (hero) {
            hero.style.display = '';
            heroIdx = 0;
            renderHero();
            buildHeroDots();
            startHeroTimer();
        }

        // ── Photo Grid ──
        grid.innerHTML = '';
        grid.style.display = '';

        photos.forEach(function(photo, idx) {
            var item = document.createElement('div');
            item.className = 'tf-gallery__item';
            item.setAttribute('data-idx', idx);

            var img = document.createElement('img');
            img.className = 'loading';
            img.alt = photo.filename;
            img.loading = 'lazy';
            img.src = photo.thumb_url;
            img.onload = function() { img.classList.remove('loading'); };

            item.appendChild(img);
            item.addEventListener('click', function() { openLightbox(idx); });
            grid.appendChild(item);
        });

        // Download all
        actions.style.display = '';
        downloadAll.href = galleryUrl + code + '/download-all'
            + (galleryToken ? '?token=' + encodeURIComponent(galleryToken) : '');
    }

    // ── Hero Slider ───────────────────────────────────
    function renderHero() {
        if (!photos.length) return;
        var photo = photos[heroIdx];
        heroImg.classList.add('tf-hero__img--fading');
        setTimeout(function() {
            heroImg.src = photo.url;
            heroImg.alt = photo.filename;
            heroImg.onload = function() {
                heroImg.classList.remove('tf-hero__img--fading');
            };
            // fallback in case cached
            if (heroImg.complete) heroImg.classList.remove('tf-hero__img--fading');
        }, 300);
        heroCounter.textContent = (heroIdx + 1) + ' / ' + photos.length;
        updateHeroDots();
    }

    function buildHeroDots() {
        if (!heroDots) return;
        heroDots.innerHTML = '';
        if (photos.length > 20) { heroDots.style.display = 'none'; return; }
        heroDots.style.display = '';
        photos.forEach(function(_, i) {
            var dot = document.createElement('button');
            dot.className = 'tf-hero__dot';
            dot.setAttribute('aria-label', 'Photo ' + (i + 1));
            dot.addEventListener('click', function() {
                heroIdx = i;
                renderHero();
                resetHeroTimer();
            });
            heroDots.appendChild(dot);
        });
        updateHeroDots();
    }

    function updateHeroDots() {
        if (!heroDots) return;
        var dots = heroDots.querySelectorAll('.tf-hero__dot');
        dots.forEach(function(d, i) {
            d.classList.toggle('tf-hero__dot--active', i === heroIdx);
        });
    }

    function heroGo(dir) {
        heroIdx = (heroIdx + dir + photos.length) % photos.length;
        renderHero();
        resetHeroTimer();
    }

    function startHeroTimer() {
        heroTimer = setInterval(function() {
            heroIdx = (heroIdx + 1) % photos.length;
            renderHero();
        }, 5000);
    }

    function resetHeroTimer() {
        clearInterval(heroTimer);
        startHeroTimer();
    }

    if (heroPrev) heroPrev.addEventListener('click', function() { heroGo(-1); });
    if (heroNext) heroNext.addEventListener('click', function() { heroGo(1); });

    // Hero click opens lightbox at that photo
    if (heroImg) {
        heroImg.addEventListener('click', function() { openLightbox(heroIdx); });
        heroImg.style.cursor = 'pointer';
    }

    // Hero touch swipe
    var heroTouchX = 0;
    if (hero) {
        hero.addEventListener('touchstart', function(e) {
            heroTouchX = e.changedTouches[0].screenX;
        }, { passive: true });
        hero.addEventListener('touchend', function(e) {
            var dx = e.changedTouches[0].screenX - heroTouchX;
            if (Math.abs(dx) > 50) {
                heroGo(dx > 0 ? -1 : 1);
            }
        }, { passive: true });
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
        // Album keyboard controls
        if (albumOpen) {
            if (e.key === 'ArrowLeft') albumGo(-1);
            if (e.key === 'ArrowRight') albumGo(1);
            if (e.key === 'Escape') closeAlbum();
        }
        // Lightbox keyboard controls
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
