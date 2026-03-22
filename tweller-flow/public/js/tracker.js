/**
 * Tweller Flow Client Tracker
 * Auto-refreshes the tracker page every 60 seconds
 */
(function() {
    'use strict';

    var tracker = document.querySelector('.tf-tracker[data-code]');
    if (!tracker) return;

    var code = tracker.getAttribute('data-code');
    if (!code) return;

    // Auto-refresh every 60 seconds
    setInterval(function() {
        var apiUrl = (typeof twellerFlowTracker !== 'undefined' && twellerFlowTracker.apiUrl)
            ? twellerFlowTracker.apiUrl
            : '/wp-json/tweller-flow/v1/track/';

        fetch(apiUrl + code)
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data && data.current_stage_index !== undefined) {
                    updateStages(data);
                }
            })
            .catch(function() {
                // Silent fail on refresh
            });
    }, 60000);

    function updateStages(data) {
        var stages = tracker.querySelectorAll('.tf-tracker__stage');
        stages.forEach(function(stage, index) {
            stage.className = 'tf-tracker__stage';
            if (index < data.current_stage_index) {
                stage.classList.add('tf-tracker__stage--completed');
            } else if (index === data.current_stage_index) {
                stage.classList.add('tf-tracker__stage--current');
            } else {
                stage.classList.add('tf-tracker__stage--upcoming');
            }
        });

        // Show gallery link if delivered
        if (data.gallery_url && data.current_stage === 'Delivered') {
            var gallerySection = tracker.querySelector('.tf-tracker__gallery');
            if (!gallerySection) {
                var div = document.createElement('div');
                div.className = 'tf-tracker__gallery';
                div.innerHTML = '<h3>Your Gallery is Ready!</h3>' +
                    '<a href="' + data.gallery_url + '" class="tf-tracker__gallery-btn" target="_blank">View & Download Photos</a>';
                var stagesSection = tracker.querySelector('.tf-tracker__stages');
                if (stagesSection) {
                    stagesSection.after(div);
                }
            }
        }
    }
})();
