<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf2-wrap">
    <h1>Offerings</h1>
    <p class="tf2-description" style="max-width:640px;">
        What clients see on the <strong>Book Us</strong> page. Session types appear first; each one offers a set of packages.
        Use the arrows to change the display order — the order here is the order on the site.
    </p>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Offerings saved. The booking page is updated.</div>
    <?php endif; ?>

    <form method="post" id="tf2-offerings-form">
        <?php wp_nonce_field( 'tweller_flow_2_save_offerings' ); ?>
        <input type="hidden" name="tweller_flow_2_save_offerings" value="1">

        <!-- ══════════ PACKAGES ══════════ -->
        <div class="tf2-card tf2-mb-6">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <h2 style="margin:0;">Packages</h2>
                <button type="button" class="tf2-btn tf2-btn--secondary tf2-btn--sm" id="tf2-add-pk">+ Add Package</button>
            </div>
            <p class="tf2-description">Pricing, duration and what's included. These are offered inside session types below.</p>

            <div id="tf2-pk-list">
                <?php foreach ( $packages as $key => $pkg ) : ?>
                <div class="tf2-off-row" data-kind="pk">
                    <div class="tf2-off-row__head">
                        <span class="tf2-off-row__grip">
                            <button type="button" class="tf2-off-move" data-dir="up" title="Move up">&#9650;</button>
                            <button type="button" class="tf2-off-move" data-dir="down" title="Move down">&#9660;</button>
                        </span>
                        <strong class="tf2-off-row__title"><?php echo esc_html( $pkg['name'] ?? $key ); ?></strong>
                        <span class="tf2-off-row__key">key: <?php echo esc_html( $key ); ?></span>
                        <button type="button" class="tf2-off-toggle tf2-btn tf2-btn--secondary tf2-btn--sm">Edit</button>
                        <button type="button" class="tf2-off-delete" title="Remove">&times;</button>
                    </div>
                    <div class="tf2-off-row__body" style="display:none;">
                        <input type="hidden" name="pk[<?php echo esc_attr( $key ); ?>][key]" value="<?php echo esc_attr( $key ); ?>">
                        <div class="tf2-off-grid">
                            <label>Name
                                <input type="text" name="pk[<?php echo esc_attr( $key ); ?>][name]" value="<?php echo esc_attr( $pkg['name'] ?? '' ); ?>" required>
                            </label>
                            <label>Price (TTD)
                                <input type="number" step="0.01" min="0" name="pk[<?php echo esc_attr( $key ); ?>][price]" value="<?php echo esc_attr( $pkg['price'] ?? 0 ); ?>">
                            </label>
                            <label>Old price (strikethrough, 0 = none)
                                <input type="number" step="0.01" min="0" name="pk[<?php echo esc_attr( $key ); ?>][old_price]" value="<?php echo esc_attr( $pkg['old_price'] ?? 0 ); ?>">
                            </label>
                            <label>Duration (minutes)
                                <input type="number" min="5" name="pk[<?php echo esc_attr( $key ); ?>][duration]" value="<?php echo esc_attr( $pkg['duration'] ?? 60 ); ?>">
                            </label>
                            <label>Edited images (999 = unlimited)
                                <input type="number" min="0" name="pk[<?php echo esc_attr( $key ); ?>][images]" value="<?php echo esc_attr( $pkg['images'] ?? 10 ); ?>">
                            </label>
                            <label>Max people
                                <input type="number" min="1" name="pk[<?php echo esc_attr( $key ); ?>][members]" value="<?php echo esc_attr( $pkg['members'] ?? 1 ); ?>">
                            </label>
                        </div>
                        <label class="tf2-off-features">Features shown on the card (one per line)
                            <textarea name="pk[<?php echo esc_attr( $key ); ?>][features]" rows="4"><?php echo esc_textarea( implode( "\n", (array) ( $pkg['features'] ?? array() ) ) ); ?></textarea>
                        </label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ══════════ SESSION TYPES ══════════ -->
        <div class="tf2-card tf2-mb-6">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <h2 style="margin:0;">Session Types</h2>
                <button type="button" class="tf2-btn tf2-btn--secondary tf2-btn--sm" id="tf2-add-st">+ Add Session Type</button>
            </div>
            <p class="tf2-description">The session choices clients pick first (Mommy &amp; Me, Family, Events…). Tick which packages each one offers. A type with no packages shows as "send us an inquiry".</p>

            <div id="tf2-st-list">
                <?php foreach ( $session_types as $key => $type ) : ?>
                <div class="tf2-off-row" data-kind="st">
                    <div class="tf2-off-row__head">
                        <span class="tf2-off-row__grip">
                            <button type="button" class="tf2-off-move" data-dir="up" title="Move up">&#9650;</button>
                            <button type="button" class="tf2-off-move" data-dir="down" title="Move down">&#9660;</button>
                        </span>
                        <strong class="tf2-off-row__title"><?php echo esc_html( $type['name'] ?? $key ); ?></strong>
                        <span class="tf2-off-row__key">key: <?php echo esc_html( $key ); ?></span>
                        <button type="button" class="tf2-off-toggle tf2-btn tf2-btn--secondary tf2-btn--sm">Edit</button>
                        <button type="button" class="tf2-off-delete" title="Remove">&times;</button>
                    </div>
                    <div class="tf2-off-row__body" style="display:none;">
                        <input type="hidden" name="st[<?php echo esc_attr( $key ); ?>][key]" value="<?php echo esc_attr( $key ); ?>">
                        <div class="tf2-off-grid tf2-off-grid--wide">
                            <label>Name
                                <input type="text" name="st[<?php echo esc_attr( $key ); ?>][name]" value="<?php echo esc_attr( $type['name'] ?? '' ); ?>" required>
                            </label>
                            <label>Description (one short line)
                                <input type="text" name="st[<?php echo esc_attr( $key ); ?>][description]" value="<?php echo esc_attr( $type['description'] ?? '' ); ?>">
                            </label>
                        </div>
                        <div class="tf2-off-allowed">
                            <span class="tf2-off-allowed__label">Packages offered:</span>
                            <?php foreach ( $packages as $pk_key => $pk ) : ?>
                                <label class="tf2-off-allowed__item">
                                    <input type="checkbox" name="st[<?php echo esc_attr( $key ); ?>][allowed][]" value="<?php echo esc_attr( $pk_key ); ?>"
                                        <?php checked( in_array( $pk_key, (array) ( $type['allowed_packages'] ?? array() ), true ) ); ?>>
                                    <?php echo esc_html( $pk['name'] ?? $pk_key ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <p style="display:flex; gap:10px; align-items:center;">
            <button type="submit" class="tf2-btn tf2-btn--primary">Save Offerings</button>
            <span style="color:#9CA3AF; font-size:12px;">New packages become tickable in session types after saving.</span>
        </p>
    </form>
</div>

<style>
.tf2-off-row { border:1px solid #E5E7EB; border-radius:10px; margin-bottom:10px; background:#fff; }
.tf2-off-row__head { display:flex; align-items:center; gap:10px; padding:10px 14px; }
.tf2-off-row__grip { display:flex; flex-direction:column; gap:2px; }
.tf2-off-move { border:none; background:#F3F4F6; border-radius:4px; cursor:pointer; font-size:9px; line-height:1; padding:3px 6px; color:#6B7280; }
.tf2-off-move:hover { background:#E5E7EB; color:#111; }
.tf2-off-row__title { font-size:14px; }
.tf2-off-row__key { font-size:11px; color:#9CA3AF; margin-right:auto; }
.tf2-off-delete { border:none; background:#FEE2E2; color:#B91C1C; width:26px; height:26px; border-radius:50%; font-size:15px; cursor:pointer; line-height:1; }
.tf2-off-delete:hover { background:#DC2626; color:#fff; }
.tf2-off-row__body { border-top:1px solid #F3F4F6; padding:14px; }
.tf2-off-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:12px; margin-bottom:12px; }
.tf2-off-grid--wide { grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); }
.tf2-off-grid label, .tf2-off-features { display:block; font-size:12px; color:#374151; font-weight:600; }
.tf2-off-grid input, .tf2-off-features textarea { display:block; width:100%; margin-top:4px; font-weight:400; }
.tf2-off-allowed { display:flex; flex-wrap:wrap; gap:8px 16px; align-items:center; background:#F9FAFB; border-radius:8px; padding:10px 12px; }
.tf2-off-allowed__label { font-size:12px; font-weight:600; color:#374151; }
.tf2-off-allowed__item { font-size:12px; color:#374151; display:flex; align-items:center; gap:5px; font-weight:400; }
</style>

<script>
(function() {
    var form = document.getElementById('tf2-offerings-form');
    if (!form) return;

    var PK_KEYS = <?php echo wp_json_encode( array_map( function( $k, $p ) {
        return array( 'key' => $k, 'name' => $p['name'] ?? $k );
    }, array_keys( $packages ), array_values( $packages ) ) ); ?>;

    function uid() { return 'new' + Math.random().toString(36).slice(2, 9); }

    // Move / delete / expand — one delegated handler
    form.addEventListener('click', function(e) {
        var moveBtn = e.target.closest('.tf2-off-move');
        if (moveBtn) {
            var row = moveBtn.closest('.tf2-off-row');
            if (moveBtn.getAttribute('data-dir') === 'up' && row.previousElementSibling) {
                row.parentNode.insertBefore(row, row.previousElementSibling);
            } else if (moveBtn.getAttribute('data-dir') === 'down' && row.nextElementSibling) {
                row.parentNode.insertBefore(row.nextElementSibling, row);
            }
            return;
        }
        var delBtn = e.target.closest('.tf2-off-delete');
        if (delBtn) {
            var row = delBtn.closest('.tf2-off-row');
            var title = row.querySelector('.tf2-off-row__title').textContent;
            if (confirm('Remove "' + title + '" from the booking page?\n(Takes effect when you press Save Offerings.)')) {
                row.remove();
            }
            return;
        }
        var togBtn = e.target.closest('.tf2-off-toggle');
        if (togBtn) {
            var body = togBtn.closest('.tf2-off-row').querySelector('.tf2-off-row__body');
            var open = body.style.display !== 'none';
            body.style.display = open ? 'none' : 'block';
            togBtn.textContent = open ? 'Edit' : 'Close';
        }
    });

    // Keep the header title live while typing
    form.addEventListener('input', function(e) {
        if (e.target.name && /\[name\]$/.test(e.target.name)) {
            var row = e.target.closest('.tf2-off-row');
            if (row) row.querySelector('.tf2-off-row__title').textContent = e.target.value || '(unnamed)';
        }
    });

    function addRow(kind) {
        var id = uid();
        var row = document.createElement('div');
        row.className = 'tf2-off-row';
        row.setAttribute('data-kind', kind);

        var head =
            '<div class="tf2-off-row__head">' +
                '<span class="tf2-off-row__grip">' +
                    '<button type="button" class="tf2-off-move" data-dir="up" title="Move up">&#9650;</button>' +
                    '<button type="button" class="tf2-off-move" data-dir="down" title="Move down">&#9660;</button>' +
                '</span>' +
                '<strong class="tf2-off-row__title">(new)</strong>' +
                '<span class="tf2-off-row__key">new</span>' +
                '<button type="button" class="tf2-off-toggle tf2-btn tf2-btn--secondary tf2-btn--sm">Close</button>' +
                '<button type="button" class="tf2-off-delete" title="Remove">&times;</button>' +
            '</div>';

        var body;
        if (kind === 'pk') {
            body =
                '<div class="tf2-off-row__body">' +
                    '<input type="hidden" name="pk[' + id + '][key]" value="">' +
                    '<div class="tf2-off-grid">' +
                        '<label>Name<input type="text" name="pk[' + id + '][name]" value="" required></label>' +
                        '<label>Price (TTD)<input type="number" step="0.01" min="0" name="pk[' + id + '][price]" value="0"></label>' +
                        '<label>Old price (strikethrough, 0 = none)<input type="number" step="0.01" min="0" name="pk[' + id + '][old_price]" value="0"></label>' +
                        '<label>Duration (minutes)<input type="number" min="5" name="pk[' + id + '][duration]" value="60"></label>' +
                        '<label>Edited images (999 = unlimited)<input type="number" min="0" name="pk[' + id + '][images]" value="10"></label>' +
                        '<label>Max people<input type="number" min="1" name="pk[' + id + '][members]" value="4"></label>' +
                    '</div>' +
                    '<label class="tf2-off-features">Features shown on the card (one per line)' +
                        '<textarea name="pk[' + id + '][features]" rows="4"></textarea>' +
                    '</label>' +
                '</div>';
        } else {
            var checks = PK_KEYS.map(function(p) {
                return '<label class="tf2-off-allowed__item"><input type="checkbox" name="st[' + id + '][allowed][]" value="' + p.key + '"> ' + p.name + '</label>';
            }).join('');
            body =
                '<div class="tf2-off-row__body">' +
                    '<input type="hidden" name="st[' + id + '][key]" value="">' +
                    '<div class="tf2-off-grid tf2-off-grid--wide">' +
                        '<label>Name<input type="text" name="st[' + id + '][name]" value="" required></label>' +
                        '<label>Description (one short line)<input type="text" name="st[' + id + '][description]" value=""></label>' +
                    '</div>' +
                    '<div class="tf2-off-allowed">' +
                        '<span class="tf2-off-allowed__label">Packages offered:</span>' + checks +
                    '</div>' +
                '</div>';
        }

        row.innerHTML = head + body;
        document.getElementById(kind === 'pk' ? 'tf2-pk-list' : 'tf2-st-list').appendChild(row);
        row.querySelector('input[type=text]').focus();
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    document.getElementById('tf2-add-pk').addEventListener('click', function() { addRow('pk'); });
    document.getElementById('tf2-add-st').addEventListener('click', function() { addRow('st'); });
})();
</script>
