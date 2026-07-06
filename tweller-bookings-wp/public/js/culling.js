/**
 * Tweller Flow — Client Culling Portal
 * Clients view proof images and select which to retouch.
 */
(function() {
    'use strict';

    var portal = document.querySelector('.tc-portal[data-code]');
    if (!portal || typeof twellerCulling === 'undefined') return;

    var code = portal.getAttribute('data-code');
    var apiUrl = twellerCulling.apiUrl;
    var nonce = twellerCulling.nonce;

    var token = sessionStorage.getItem('tc_token_' + code) || '';
    var proofs = [];
    var selectedIds = new Set();
    var maxFree = 0;
    var includedImages = 0;
    var freebies = 0;
    var upsellPricePerPhoto = 30;
    var submitted = false;

    // Elements
    var loading     = document.getElementById('tc-loading');
    var pwSection   = document.getElementById('tc-password');
    var pwForm      = document.getElementById('tc-pw-form');
    var pwInput     = document.getElementById('tc-pw-input');
    var pwError     = document.getElementById('tc-pw-error');
    var notReady    = document.getElementById('tc-not-ready');
    var submittedEl = document.getElementById('tc-submitted');
    var counter     = document.getElementById('tc-counter');
    var countEl     = document.getElementById('tc-count');
    var maxEl       = document.getElementById('tc-max');
    var grid        = document.getElementById('tc-grid');
    var submitBtn   = document.getElementById('tc-submit-btn');

    // Modals
    var confirmModal  = document.getElementById('tc-confirm-modal');
    var confirmCount  = document.getElementById('tc-confirm-count');
    var confirmUpsell = document.getElementById('tc-confirm-upsell');
    var confirmCancel = document.getElementById('tc-confirm-cancel');
    var confirmYes    = document.getElementById('tc-confirm-yes');
    // Extra cost banner (shown inline when client exceeds package)
    var extraBanner   = null;

    // Load data
    loadProofs();

    function loadProofs() {
        var url = apiUrl + code;
        if (token) url += '?token=' + encodeURIComponent(token);

        fetch(url, { headers: { 'X-WP-Nonce': nonce } })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            loading.style.display = 'none';

            if (!data.ok) {
                showMessage('Unable to load proofs. Please try again.');
                return;
            }

            if (!data.culling_enabled) {
                showMessage('Photo selection is not available for this session.');
                return;
            }

            if (data.has_password && !data.unlocked) {
                pwSection.style.display = 'block';
                return;
            }

            if (!data.ready) {
                notReady.style.display = 'block';
                return;
            }

            if (data.submitted) {
                submittedEl.style.display = 'block';
                return;
            }

            // Store data
            proofs = data.proofs || [];
            includedImages = data.included_images || 15;
            freebies = data.freebies || 0;
            maxFree = data.max_free || (includedImages + freebies);
            upsellPricePerPhoto = data.upsell_price_per_photo || 30;

            // Restore selections
            if (data.selected_ids) {
                data.selected_ids.forEach(function(id) { selectedIds.add(id); });
            }

            if (proofs.length === 0) {
                showMessage('No proofs available yet. Please check back soon.');
                return;
            }

            // Render
            buildGrid();
            counter.style.display = '';
            grid.style.display = '';
            updateCounter();
        })
        .catch(function(err) {
            loading.style.display = 'none';
            showMessage('Unable to load proofs. Please refresh the page.');
            console.error('[Tweller Culling]', err);
        });
    }

    function showMessage(msg) {
        var el = document.createElement('div');
        el.className = 'tc-portal__message';
        el.innerHTML = '<p>' + msg + '</p>';
        portal.appendChild(el);
    }

    // ── Password ───────────────────────────────────────
    if (pwForm) {
        pwForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var pw = pwInput.value.trim();
            if (!pw) return;
            pwError.style.display = 'none';

            fetch(apiUrl + code + '/verify', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify({ password: pw })
            })
            .then(function(r) {
                if (!r.ok) throw new Error('wrong');
                return r.json();
            })
            .then(function(data) {
                if (data.ok && data.token) {
                    token = data.token;
                    sessionStorage.setItem('tc_token_' + code, data.token);
                    pwSection.style.display = 'none';
                    loading.style.display = 'block';
                    loadProofs();
                }
            })
            .catch(function() {
                pwError.style.display = 'block';
                pwInput.value = '';
                pwInput.focus();
            });
        });
    }

    // ── Grid ───────────────────────────────────────────
    function buildGrid() {
        grid.innerHTML = '';

        proofs.forEach(function(proof) {
            var item = document.createElement('div');
            item.className = 'tc-grid__item' + (selectedIds.has(proof.id) ? ' tc-grid__item--selected' : '');
            item.setAttribute('data-id', proof.id);

            var img = document.createElement('img');
            img.src = proof.thumb_url;
            img.alt = proof.filename;
            img.loading = 'lazy';
            img.draggable = false;
            img.oncontextmenu = function(e) { e.preventDefault(); return false; };

            var check = document.createElement('div');
            check.className = 'tc-grid__check';
            check.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>';

            var number = document.createElement('div');
            number.className = 'tc-grid__number';

            item.appendChild(img);
            item.appendChild(check);
            item.appendChild(number);

            item.addEventListener('click', function() {
                if (submitted) return;
                toggleSelection(proof.id, item);
            });

            grid.appendChild(item);
        });

        updateSelectionNumbers();
    }

    function toggleSelection(id, item) {
        if (selectedIds.has(id)) {
            selectedIds.delete(id);
            item.classList.remove('tc-grid__item--selected');
        } else {
            selectedIds.add(id);
            item.classList.add('tc-grid__item--selected');
        }
        updateCounter();
        updateSelectionNumbers();
        updateExtraBanner();
    }

    function updateSelectionNumbers() {
        var items = grid.querySelectorAll('.tc-grid__item');
        var idx = 0;
        items.forEach(function(item) {
            var numEl = item.querySelector('.tc-grid__number');
            var id = parseInt(item.getAttribute('data-id'));
            if (selectedIds.has(id)) {
                idx++;
                numEl.textContent = idx;
                numEl.style.display = '';
            } else {
                numEl.style.display = 'none';
            }
        });
    }

    function updateCounter() {
        var count = selectedIds.size;
        var extra = Math.max(0, count - maxFree);

        countEl.textContent = count;
        maxEl.textContent = maxFree;

        submitBtn.disabled = count === 0;

        if (extra > 0) {
            countEl.style.color = '#D97706';
        } else {
            countEl.style.color = '';
        }
    }

    function updateExtraBanner() {
        var extra = Math.max(0, selectedIds.size - maxFree);
        var cost  = extra * upsellPricePerPhoto;

        if (!extraBanner) {
            extraBanner = document.createElement('div');
            extraBanner.id = 'tc-extra-banner';
            extraBanner.style.cssText = 'background:#FEF9EC; border:1px solid #FCD34D; border-radius:10px; padding:12px 18px; margin:12px 0; display:flex; align-items:center; gap:12px; font-size:14px;';
            if (counter) counter.after(extraBanner);
        }

        if (extra > 0) {
            extraBanner.style.display = 'flex';
            extraBanner.innerHTML =
                '<span style="font-size:20px;">💡</span>' +
                '<span>' +
                  '<strong style="color:#92400E;">' + extra + ' extra photo' + (extra > 1 ? 's' : '') + ' selected</strong>' +
                  ' &mdash; ' +
                  extra + ' × $' + upsellPricePerPhoto + ' TTD = ' +
                  '<strong style="color:#92400E;">$' + cost + ' TTD additional charge</strong>.' +
                  ' <span style="color:#6B7280;">Payment details will be emailed to you after submitting.</span>' +
                '</span>';
        } else {
            extraBanner.style.display = 'none';
        }
    }

    // ── Submit ──────────────────────────────────────────
    if (submitBtn) {
        submitBtn.addEventListener('click', function() {
            var count = selectedIds.size;
            var extra = Math.max(0, count - maxFree);
            var cost  = extra * upsellPricePerPhoto;

            confirmCount.textContent = count;
            if (extra > 0) {
                confirmUpsell.innerHTML =
                    '<strong>' + extra + ' extra photo' + (extra > 1 ? 's' : '') + '</strong> &mdash; an additional charge of <strong>$' + cost + ' TTD</strong> will apply. Payment details will be emailed to you.';
                confirmUpsell.style.display = 'block';
            } else {
                confirmUpsell.style.display = 'none';
            }
            confirmModal.style.display = 'flex';
        });
    }

    if (confirmCancel) {
        confirmCancel.addEventListener('click', function() {
            confirmModal.style.display = 'none';
        });
    }

    if (confirmYes) {
        confirmYes.addEventListener('click', function() {
            confirmYes.disabled = true;
            confirmYes.textContent = 'Submitting...';

            var body = {
                proof_ids: Array.from(selectedIds),
            };
            if (token) body.token = token;

            fetch(apiUrl + code + '/select', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify(body)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                confirmModal.style.display = 'none';
                if (data.ok) {
                    submitted = true;
                    grid.style.display = 'none';
                    counter.style.display = 'none';
                    submittedEl.style.display = 'block';
                    submittedEl.scrollIntoView({ behavior: 'smooth' });
                } else {
                    alert('Error: ' + (data.message || 'Could not submit. Please try again.'));
                    confirmYes.disabled = false;
                    confirmYes.textContent = 'Confirm & Submit';
                }
            })
            .catch(function(err) {
                alert('Network error. Please try again.');
                confirmYes.disabled = false;
                confirmYes.textContent = 'Confirm & Submit';
            });
        });
    }

    // Block right-click on images (basic protection)
    portal.addEventListener('contextmenu', function(e) {
        if (e.target.tagName === 'IMG') {
            e.preventDefault();
            return false;
        }
    });

    // Block drag on images
    portal.addEventListener('dragstart', function(e) {
        if (e.target.tagName === 'IMG') {
            e.preventDefault();
            return false;
        }
    });
})();
