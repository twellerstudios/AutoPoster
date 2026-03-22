/**
 * Tweller Flow Admin JS
 */
(function($) {
    'use strict';

    // Dismiss notices
    $(document).on('click', '.notice .notice-dismiss', function() {
        $(this).parent().fadeOut(200);
    });

    // Copy tracking code to clipboard
    $(document).on('click', '.tf-copy-code', function(e) {
        e.preventDefault();
        var code = $(this).data('code');
        if (navigator.clipboard) {
            navigator.clipboard.writeText(code).then(function() {
                alert('Tracking code copied: ' + code);
            });
        }
    });

})(jQuery);
