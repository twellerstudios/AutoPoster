/**
 * Tweller Flow v2 — Client Tracker
 * Auto-refreshes every 60 seconds
 */
(function() {
    'use strict';

    var tracker = document.querySelector('.tf-tracker[data-code]');
    if (!tracker) return;

    var code = tracker.getAttribute('data-code');
    if (!code || typeof twellerFlowTracker === 'undefined') return;

    // Auto-refresh every 60 seconds
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
        .catch(function() {
            // Silently fail on network errors
        });
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
})();
