/* Event Photo Engine — admin interactions (moderation, cover, order status). */
(function () {
    'use strict';
    var A = window.tepeAdmin || {};
    if (!A.restBase) return;

    function api(path, body) {
        return fetch(A.restBase + path, {
            method: 'POST',
            headers: { 'X-WP-Nonce': A.nonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : null
        }).then(function (r) { return r.json(); });
    }

    document.addEventListener('click', function (e) {
        var t = e.target.closest('.tepe-approve, .tepe-delete, .tepe-setcover');
        if (!t) return;
        e.preventDefault();
        var id = t.getAttribute('data-id');
        var tile = t.closest('.tepe-admin-tile');

        if (t.classList.contains('tepe-approve')) {
            api('admin/upload/' + id + '/approve').then(function () {
                if (tile) { tile.classList.remove('tepe-status-pending'); tile.classList.add('tepe-status-approved'); var f = tile.querySelector('.tepe-admin-tile__flag'); if (f) f.remove(); }
            });
        } else if (t.classList.contains('tepe-delete')) {
            if (!confirm('Delete this photo?')) return;
            api('admin/upload/' + id + '/delete').then(function () { if (tile) tile.remove(); });
        } else if (t.classList.contains('tepe-setcover')) {
            var hidden = document.getElementById('tepe-cover-id');
            if (hidden) hidden.value = id;
            document.querySelectorAll('.tepe-admin-tile').forEach(function (n) { n.classList.remove('tepe-cover-selected'); });
            if (tile) tile.classList.add('tepe-cover-selected');
            // Nudge the admin to persist.
            var btn = document.querySelector('input[name="tepe_action"][value="save"]');
            alert('Cover selected — click “Save settings” to apply.');
        }
    });

    document.addEventListener('change', function (e) {
        var sel = e.target.closest('.tepe-order-status');
        if (!sel) return;
        api('admin/order/' + sel.getAttribute('data-id') + '/status', { status: sel.value }).then(function (r) {
            sel.style.outline = r && r.ok ? '2px solid #46b450' : '2px solid #dc3232';
            setTimeout(function () { sel.style.outline = ''; }, 1200);
        });
    });
})();
