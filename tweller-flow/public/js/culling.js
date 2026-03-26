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
    var upsellTiers = {};
    var chosenUpsell = null;
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
    var upsellModal      = document.getElementById('tc-upsell-modal');
    var upsellIncluded   = document.getElementById('tc-upsell-included');
    var upsellSelected   = document.getElementById('tc-upsell-selected');
    var upsellTiersEl    = document.getElementById('tc-upsell-tiers');
    var upsellCancel     = document.getElementById('tc-upsell-cancel');
    var confirmModal     = document.getElementById('tc-confirm-modal');
    var confirmCount     = document.getElementById('tc-confirm-count');
    var confirmUpsell    = document.getElementById('tc-confirm-upsell');
    var confirmCancel    = document.getElementById('tc-confirm-cancel');
    var confirmYes       = document.getElementById('tc-confirm-yes');

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
            freebies = data.freebies || 5;
            maxFree = data.max_free || (includedImages + freebies);
            upsellTiers = data.upsell_tiers || {};

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

            // Check if over free limit
            if (selectedIds.size > maxFree && !chosenUpsell) {
                showUpsellModal();
            }
        }
        updateCounter();
        updateSelectionNumbers();
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
        var max = chosenUpsell !== null ? getUpsellMax() : maxFree;

        countEl.textContent = count;
        maxEl.textContent = max === Infinity ? proofs.length : max;

        if (count > 0) {
            submitBtn.disabled = false;
            if (count > maxFree && !chosenUpsell) {
                countEl.style.color = '#DC2626';
            } else {
                countEl.style.color = '';
            }
        } else {
            submitBtn.disabled = true;
        }
    }

    function getUpsellMax() {
        if (chosenUpsell === 0) return proofs.length; // all
        return maxFree + (chosenUpsell || 0);
    }

    // ── Upsell Modal ───────────────────────────────────
    function showUpsellModal() {
        upsellIncluded.textContent = maxFree;
        upsellSelected.textContent = selectedIds.size;

        upsellTiersEl.innerHTML = '';

        // Sort tiers: 5, 10, 15, then 0 (all)
        var tierKeys = Object.keys(upsellTiers).map(Number).sort(function(a, b) {
            if (a === 0) return 1;
            if (b === 0) return -1;
            return a - b;
        });

        tierKeys.forEach(function(tier) {
            var price = upsellTiers[tier];
            var btn = document.createElement('button');
            btn.className = 'tc-upsell__tier';

            if (tier === 0) {
                btn.innerHTML = '<span class="tc-upsell__tier-label">All remaining photos</span><span class="tc-upsell__tier-price">+$' + price + '</span>';
            } else {
                btn.innerHTML = '<span class="tc-upsell__tier-label">+' + tier + ' photos</span><span class="tc-upsell__tier-price">+$' + price + '</span>';
            }

            btn.addEventListener('click', function() {
                chosenUpsell = tier;
                upsellModal.style.display = 'none';
                updateCounter();
            });

            upsellTiersEl.appendChild(btn);
        });

        upsellModal.style.display = 'flex';
    }

    if (upsellCancel) {
        upsellCancel.addEventListener('click', function() {
            // Remove the last selection that pushed over limit
            var arr = Array.from(selectedIds);
            while (selectedIds.size > maxFree) {
                var last = arr.pop();
                selectedIds.delete(last);
                var el = grid.querySelector('[data-id="' + last + '"]');
                if (el) el.classList.remove('tc-grid__item--selected');
            }
            chosenUpsell = null;
            upsellModal.style.display = 'none';
            updateCounter();
            updateSelectionNumbers();
        });
    }

    // ── Submit ──────────────────────────────────────────
    if (submitBtn) {
        submitBtn.addEventListener('click', function() {
            var count = selectedIds.size;

            // Check if over free and no upsell chosen
            if (count > maxFree && !chosenUpsell && chosenUpsell !== 0) {
                showUpsellModal();
                return;
            }

            // Show confirm
            confirmCount.textContent = count;
            if (chosenUpsell !== null && count > maxFree) {
                var extra = count - maxFree;
                var price = upsellTiers[chosenUpsell] || 0;
                confirmUpsell.innerHTML = '<strong>' + extra + ' extra photos</strong> — additional charge of <strong>$' + price + '</strong> will apply.';
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
            if (chosenUpsell !== null) {
                body.upsell_tier = String(chosenUpsell);
            }
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
