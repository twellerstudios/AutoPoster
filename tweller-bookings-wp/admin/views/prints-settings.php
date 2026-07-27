<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf2-wrap">
    <h1 style="display:flex; align-items:center; gap:12px;">
        Print Store — Products &amp; Settings
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=tweller-flow-2-prints' ) ); ?>" class="tf2-btn tf2-btn--secondary tf2-btn--sm">&larr; Orders</a>
    </h1>
    <p class="tf2-description" style="max-width:640px;">
        The products clients can order from their gallery and from the public
        <a href="<?php echo esc_url( TwellerFlow2_Prints::get_prints_page_url() ); ?>" target="_blank">Order Prints page</a>.
        Use the arrows to reorder — the order here is the order in the picker.
    </p>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Print store settings saved.</div>
    <?php endif; ?>

    <form method="post" id="tf2-prints-form">
        <?php wp_nonce_field( 'tweller_flow_2_save_prints' ); ?>
        <input type="hidden" name="tweller_flow_2_save_prints" value="1">

        <!-- ══════════ PRODUCTS ══════════ -->
        <div class="tf2-card tf2-mb-6">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <h2 style="margin:0;">Products</h2>
                <button type="button" class="tf2-btn tf2-btn--secondary tf2-btn--sm" id="tf2-pr-add">+ Add Product</button>
            </div>
            <p class="tf2-description">Prints, canvases and photobooks with sizes (inches) and prices (TTD). Untick "Active" to hide a product without deleting it.</p>

            <div id="tf2-pr-list">
                <?php foreach ( $products as $idx => $p ) : ?>
                <div class="tf2-pr-row">
                    <div class="tf2-pr-row__head">
                        <span class="tf2-pr-row__grip">
                            <button type="button" class="tf2-pr-move" data-dir="up" title="Move up">&#9650;</button>
                            <button type="button" class="tf2-pr-move" data-dir="down" title="Move down">&#9660;</button>
                        </span>
                        <input type="hidden" name="pr[<?php echo (int) $idx; ?>][id]" value="<?php echo esc_attr( $p['id'] ?? '' ); ?>">
                        <input type="text" class="tf2-pr-name" name="pr[<?php echo (int) $idx; ?>][name]" value="<?php echo esc_attr( $p['name'] ?? '' ); ?>" placeholder="Product name" required>
                        <select name="pr[<?php echo (int) $idx; ?>][category]">
                            <option value="print" <?php selected( $p['category'] ?? '', 'print' ); ?>>Print</option>
                            <option value="canvas" <?php selected( $p['category'] ?? '', 'canvas' ); ?>>Canvas</option>
                            <option value="photobook" <?php selected( $p['category'] ?? '', 'photobook' ); ?>>Photobook</option>
                        </select>
                        <span class="tf2-pr-size">
                            <input type="number" step="0.5" min="0" name="pr[<?php echo (int) $idx; ?>][width_in]" value="<?php echo esc_attr( $p['width_in'] ?? 0 ); ?>" title="Width (in)">
                            &times;
                            <input type="number" step="0.5" min="0" name="pr[<?php echo (int) $idx; ?>][height_in]" value="<?php echo esc_attr( $p['height_in'] ?? 0 ); ?>" title="Height (in)">
                            in
                        </span>
                        <span class="tf2-pr-price">
                            TT$ <input type="number" step="0.01" min="0" name="pr[<?php echo (int) $idx; ?>][price]" value="<?php echo esc_attr( $p['price'] ?? 0 ); ?>" title="Price (TTD)">
                        </span>
                        <label class="tf2-pr-active" title="Shown to customers">
                            <input type="checkbox" name="pr[<?php echo (int) $idx; ?>][active]" value="1" <?php checked( ! empty( $p['active'] ) ); ?>> Active
                        </label>
                        <button type="button" class="tf2-pr-delete" title="Remove">&times;</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ══════════ STOREFRONT ══════════ -->
        <div class="tf2-card tf2-mb-6">
            <h2 style="margin-top:0;">Storefront</h2>
            <p class="tf2-description">The hero section at the top of the public print shop page.</p>
            <div class="tf2-form">
                <div class="tf2-field">
                    <label>Hero headline</label>
                    <input type="text" name="prints_hero_headline" value="<?php echo esc_attr( $settings['hero_headline'] ); ?>" style="max-width:640px;" placeholder="Your memories, beautifully printed.">
                </div>
                <div class="tf2-field">
                    <label>Hero subheadline</label>
                    <input type="text" name="prints_hero_subheadline" value="<?php echo esc_attr( $settings['hero_subheadline'] ); ?>" style="max-width:640px;" placeholder="Museum-grade prints, gallery canvases and handcrafted albums — delivered across Trinidad &amp; Tobago.">
                </div>
                <div class="tf2-field">
                    <label>Hero intro (optional)</label>
                    <textarea name="prints_hero_intro" rows="2" style="max-width:640px;" placeholder="An optional extra line under the subheadline."><?php echo esc_textarea( $settings['hero_intro'] ); ?></textarea>
                </div>
                <div class="tf2-field">
                    <label>Hero background image URL (optional)</label>
                    <input type="url" name="prints_hero_bg_url" value="<?php echo esc_attr( $settings['hero_bg_url'] ); ?>" style="max-width:640px;" placeholder="https://…  (leave empty for the solid black hero)">
                    <p class="tf2-description">A wide photo works best — it's darkened automatically so the text stays readable.</p>
                </div>
            </div>
        </div>

        <!-- ══════════ STORE SETTINGS ══════════ -->
        <div class="tf2-card tf2-mb-6">
            <h2 style="margin-top:0;">Store Settings</h2>
            <div class="tf2-form">
                <div class="tf2-field">
                    <label>Order notification email</label>
                    <input type="email" name="prints_notify_email" value="<?php echo esc_attr( $settings['notify_email'] ); ?>" style="max-width:360px;">
                    <p class="tf2-description">New-order alerts are sent here.</p>
                </div>
                <div class="tf2-field">
                    <label>Pickup / delivery note</label>
                    <textarea name="prints_pickup_note" rows="3" style="max-width:640px;"><?php echo esc_textarea( $settings['pickup_note'] ); ?></textarea>
                    <p class="tf2-description">Shown at checkout and in order emails (turnaround time, pickup location, delivery info).</p>
                </div>
                <div class="tf2-field">
                    <label>Bank transfer payment instructions</label>
                    <textarea name="prints_payment_instructions" rows="5" style="max-width:640px;" placeholder="Leave empty to reuse the booking banking details from Settings."><?php echo esc_textarea( $settings['payment_instructions'] ); ?></textarea>
                    <p class="tf2-description">Shown on the customer's order portal (with the receipt uploader) and on the order-success screen. Empty = reuse the booking banking info.</p>
                </div>
            </div>
        </div>

        <p><button type="submit" class="tf2-btn tf2-btn--primary">Save Print Store</button></p>
    </form>
</div>

<style>
.tf2-pr-row { border:1px solid #E5E7EB; border-radius:10px; margin-bottom:8px; background:#fff; }
.tf2-pr-row__head { display:flex; align-items:center; gap:10px; padding:8px 12px; flex-wrap:wrap; }
.tf2-pr-row__grip { display:flex; flex-direction:column; gap:2px; }
.tf2-pr-move { border:none; background:#F3F4F6; border-radius:4px; cursor:pointer; font-size:9px; line-height:1; padding:3px 6px; color:#6B7280; }
.tf2-pr-move:hover { background:#E5E7EB; color:#111; }
.tf2-pr-name { flex:1 1 200px; min-width:160px; }
.tf2-pr-size { display:flex; align-items:center; gap:4px; font-size:12px; color:#6B7280; white-space:nowrap; }
.tf2-pr-size input { width:64px; }
.tf2-pr-price { display:flex; align-items:center; gap:4px; font-size:12px; color:#374151; font-weight:600; white-space:nowrap; }
.tf2-pr-price input { width:90px; }
.tf2-pr-active { font-size:12px; color:#374151; display:flex; align-items:center; gap:5px; white-space:nowrap; }
.tf2-pr-delete { border:none; background:#FEE2E2; color:#B91C1C; width:26px; height:26px; border-radius:50%; font-size:15px; cursor:pointer; line-height:1; flex-shrink:0; }
.tf2-pr-delete:hover { background:#DC2626; color:#fff; }
</style>

<script>
(function() {
    var form = document.getElementById('tf2-prints-form');
    var list = document.getElementById('tf2-pr-list');
    if (!form || !list) return;

    function uid() { return 'new' + Math.random().toString(36).slice(2, 9); }

    form.addEventListener('click', function(e) {
        var moveBtn = e.target.closest('.tf2-pr-move');
        if (moveBtn) {
            var row = moveBtn.closest('.tf2-pr-row');
            if (moveBtn.getAttribute('data-dir') === 'up' && row.previousElementSibling) {
                row.parentNode.insertBefore(row, row.previousElementSibling);
            } else if (moveBtn.getAttribute('data-dir') === 'down' && row.nextElementSibling) {
                row.parentNode.insertBefore(row.nextElementSibling, row);
            }
            return;
        }
        var delBtn = e.target.closest('.tf2-pr-delete');
        if (delBtn) {
            var prow = delBtn.closest('.tf2-pr-row');
            var name = prow.querySelector('.tf2-pr-name');
            if (confirm('Remove "' + (name ? name.value : 'this product') + '" from the store?\n(Takes effect when you press Save.)')) {
                prow.remove();
            }
        }
    });

    document.getElementById('tf2-pr-add').addEventListener('click', function() {
        var id = uid();
        var row = document.createElement('div');
        row.className = 'tf2-pr-row';
        row.innerHTML =
            '<div class="tf2-pr-row__head">' +
                '<span class="tf2-pr-row__grip">' +
                    '<button type="button" class="tf2-pr-move" data-dir="up" title="Move up">&#9650;</button>' +
                    '<button type="button" class="tf2-pr-move" data-dir="down" title="Move down">&#9660;</button>' +
                '</span>' +
                '<input type="hidden" name="pr[' + id + '][id]" value="">' +
                '<input type="text" class="tf2-pr-name" name="pr[' + id + '][name]" value="" placeholder="Product name" required>' +
                '<select name="pr[' + id + '][category]">' +
                    '<option value="print">Print</option>' +
                    '<option value="canvas">Canvas</option>' +
                    '<option value="photobook">Photobook</option>' +
                '</select>' +
                '<span class="tf2-pr-size">' +
                    '<input type="number" step="0.5" min="0" name="pr[' + id + '][width_in]" value="0" title="Width (in)"> &times; ' +
                    '<input type="number" step="0.5" min="0" name="pr[' + id + '][height_in]" value="0" title="Height (in)"> in' +
                '</span>' +
                '<span class="tf2-pr-price">TT$ <input type="number" step="0.01" min="0" name="pr[' + id + '][price]" value="0" title="Price (TTD)"></span>' +
                '<label class="tf2-pr-active"><input type="checkbox" name="pr[' + id + '][active]" value="1" checked> Active</label>' +
                '<button type="button" class="tf2-pr-delete" title="Remove">&times;</button>' +
            '</div>';
        list.appendChild(row);
        row.querySelector('.tf2-pr-name').focus();
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
})();
</script>
