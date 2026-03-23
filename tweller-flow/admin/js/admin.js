/**
 * Tweller Flow v2 — Admin JS
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        initTabs();
        initAutoFillPrice();
    });

    /**
     * Tab switching on session detail page
     */
    function initTabs() {
        var tabContainer = document.getElementById('detail-tabs');
        if (!tabContainer) return;

        var tabs = tabContainer.querySelectorAll('.tf-tab');
        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                var targetId = 'tab-' + this.getAttribute('data-tab');

                // Deactivate all tabs and content
                tabs.forEach(function(t) { t.classList.remove('tf-tab--active'); });
                document.querySelectorAll('.tf-tab-content').forEach(function(c) {
                    c.classList.remove('tf-tab-content--active');
                });

                // Activate clicked tab and content
                this.classList.add('tf-tab--active');
                var target = document.getElementById(targetId);
                if (target) target.classList.add('tf-tab-content--active');
            });
        });
    }

    /**
     * Auto-fill total price when package changes on new session form
     */
    function initAutoFillPrice() {
        var packageSelects = document.querySelectorAll('select[name="package_type"]');
        packageSelects.forEach(function(select) {
            select.addEventListener('change', function() {
                var form = this.closest('form');
                if (!form) return;

                var totalInput = form.querySelector('input[name="total_amount"]');
                if (!totalInput) return;

                // Extract price from option text (e.g., "Mini Session — $600")
                var selectedOption = this.options[this.selectedIndex];
                var match = selectedOption.textContent.match(/\$([0-9,]+)/);
                if (match) {
                    totalInput.value = match[1].replace(',', '');
                }
            });
        });
    }
})();
