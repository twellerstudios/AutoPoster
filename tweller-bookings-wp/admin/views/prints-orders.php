<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf2-wrap">
    <h1 style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        Print Store
        <?php if ( ! empty( $review_count ) ) : ?>
            <span class="tf2-badge" style="background:#C9A227; color:#101010; font-weight:700;"><?php echo (int) $review_count; ?> receipt<?php echo (int) $review_count === 1 ? '' : 's'; ?> to verify</span>
        <?php endif; ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=tweller-flow-2-prints&view=settings' ) ); ?>" class="tf2-btn tf2-btn--secondary tf2-btn--sm">Products &amp; Settings</a>
        <a href="<?php echo esc_url( TwellerFlow2_Prints::get_prints_page_url() ); ?>" target="_blank" class="tf2-btn tf2-btn--ghost tf2-btn--sm">View Public Order Page &rarr;</a>
    </h1>

    <?php if ( isset( $_GET['updated'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Order updated.</div>
    <?php endif; ?>
    <?php if ( isset( $_GET['deleted'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Order deleted.</div>
    <?php endif; ?>

    <?php
    // Fulfilment flash notices + the driver that walks a chunked build to
    // completion. Without this the build redirects land here silently.
    $tf2pf_open_id = 0;
    if ( class_exists( 'TwellerFlow2_Print_Fulfillment' ) ) {
        TwellerFlow2_Print_Fulfillment::render_page_notices();
        $tf2pf_open_id = (int) TwellerFlow2_Print_Fulfillment::open_order_id();
    }
    ?>

    <?php
    $tabs = array( '' => 'All' );
    foreach ( $statuses as $s ) {
        $tabs[ $s ] = isset( $labels[ $s ] ) ? $labels[ $s ] : ucfirst( $s );
    }
    ?>
    <div style="display:flex; flex-wrap:wrap; gap:6px; margin:14px 0;">
        <?php foreach ( $tabs as $key => $label ) :
            $url = admin_url( 'admin.php?page=tweller-flow-2-prints' . ( $key !== '' ? '&status=' . $key : '' ) );
            $is_active = ( $status_filter === $key );
        ?>
            <a href="<?php echo esc_url( $url ); ?>" class="tf2-btn tf2-btn--sm <?php echo $is_active ? 'tf2-btn--primary' : 'tf2-btn--secondary'; ?>">
                <?php echo esc_html( $label ); ?>
                <span style="opacity:0.7;">(<?php echo (int) ( $status_counts[ $key ] ?? 0 ); ?>)</span>
            </a>
        <?php endforeach; ?>

        <form method="get" style="margin-left:auto; display:flex; gap:6px;">
            <input type="hidden" name="page" value="tweller-flow-2-prints">
            <?php if ( $status_filter !== '' ) : ?>
                <input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>">
            <?php endif; ?>
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search ref, name, email…">
            <button type="submit" class="tf2-btn tf2-btn--secondary tf2-btn--sm">Search</button>
        </form>
    </div>

    <div class="tf2-card tf2-card--flush">
        <table class="tf2-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Source</th>
                    <th>Items</th>
                    <th>Subtotal</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $orders ) ) : ?>
                <tr><td colspan="8" style="text-align:center; color:#9CA3AF; padding:30px;">No print orders yet.</td></tr>
            <?php endif; ?>
            <?php foreach ( (array) $orders as $order ) :
                $items = TwellerFlow2_Prints::get_order_items( $order );
                $qty_total = 0;
                foreach ( $items as $it ) { $qty_total += (int) ( $it['qty'] ?? 0 ); }
                $delete_url = wp_nonce_url(
                    admin_url( 'admin.php?page=tweller-flow-2-prints&action=delete_print_order&order_id=' . (int) $order->id ),
                    'tweller_flow_2_delete_print_order_' . (int) $order->id
                );
                $has_review       = ! empty( $order->payment_review );
                $has_receipt      = ! empty( $order->receipt_url );
                $confirmed_amount = ( isset( $order->receipt_confirmed_amount ) && $order->receipt_confirmed_amount !== null ) ? (float) $order->receipt_confirmed_amount : null;
                $amount_mismatch  = ( $confirmed_amount !== null ) && ( abs( $confirmed_amount - (float) $order->subtotal ) > 0.5 );
                $payment          = ! empty( $order->payment ) ? json_decode( (string) $order->payment, true ) : null;
                if ( ! is_array( $payment ) ) $payment = null;
                $portal_url       = TwellerFlow2_Prints::portal_url( $order );

                // An action that redirects back here re-opens its own row, so
                // the result is on screen straight away instead of needing a
                // second click into the order.
                $is_open = ( $tf2pf_open_id === (int) $order->id );

                // Fulfilment stage — sent-but-unfulfilled must never read as
                // delivered; only a recorded delivery does.
                $fp_stage = null;
                if ( class_exists( 'TwellerFlow2_Print_Fulfillment' ) ) {
                    $fp_stage = TwellerFlow2_Print_Fulfillment::fulfillment_stage( $order );
                }
            ?>
                <tr class="tf2-po-row<?php echo $is_open ? ' tf2-po-row--open' : ''; ?>" data-order="<?php echo (int) $order->id; ?>" style="cursor:pointer;">
                    <td><strong><?php echo esc_html( $order->order_ref ); ?></strong></td>
                    <td>
                        <?php echo esc_html( $order->customer_name ); ?><br>
                        <span style="color:#9CA3AF; font-size:12px;"><?php echo esc_html( $order->customer_email ); ?></span>
                    </td>
                    <td>
                        <?php if ( $order->source === 'gallery' ) : ?>
                            <span class="tf2-badge tf2-badge--delivered">Gallery</span>
                            <?php if ( $order->session_code ) : ?>
                                <br><span style="color:#9CA3AF; font-size:11px;"><?php echo esc_html( $order->session_code ); ?></span>
                            <?php endif; ?>
                        <?php else : ?>
                            <span class="tf2-badge tf2-badge--booked">Public</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo (int) $qty_total; ?> item<?php echo $qty_total === 1 ? '' : 's'; ?></td>
                    <td><strong>TT$ <?php echo esc_html( number_format( (float) $order->subtotal, 2 ) ); ?></strong></td>
                    <td>
                        <span class="tf2-badge tf2-po-status--<?php echo esc_attr( $order->status ); ?>"><?php echo esc_html( TwellerFlow2_Prints::get_status_label( $order->status ) ); ?></span>
                        <?php if ( $has_review && $order->status === 'new' ) : ?>
                            <br><span class="tf2-badge tf2-po-review">Receipt uploaded</span>
                        <?php endif; ?>
                        <?php if ( $fp_stage && $fp_stage['key'] !== 'none' ) : ?>
                            <br><span class="tf2-po-stage tf2-po-stage--<?php echo esc_attr( $fp_stage['tone'] ); ?>"><?php echo esc_html( $fp_stage['short'] ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;"><?php echo esc_html( date( 'M j, Y g:ia', strtotime( $order->created_at ) ) ); ?></td>
                    <td><button type="button" class="tf2-btn tf2-btn--secondary tf2-btn--sm tf2-po-toggle"><?php echo $is_open ? 'Close' : 'Details'; ?></button></td>
                </tr>
                <tr class="tf2-po-detail" id="tf2-po-detail-<?php echo (int) $order->id; ?>"<?php echo $is_open ? '' : ' style="display:none;"'; ?>>
                    <td colspan="8" style="background:#F9FAFB;">
                        <div style="display:flex; flex-wrap:wrap; gap:24px; padding:8px 4px 14px;">
                            <div style="flex:1 1 380px; min-width:300px;">
                                <h4 style="margin:8px 0;">Items</h4>
                                <table style="width:100%; border-collapse:collapse;">
                                    <?php foreach ( $items as $it ) : ?>
                                    <tr style="border-bottom:1px solid #EEF0F3;">
                                        <td style="padding:6px 8px 6px 0; width:48px;">
                                            <?php if ( ! empty( $it['thumb_url'] ) ) : ?>
                                                <a href="<?php echo esc_url( $it['photo_url'] ?: $it['thumb_url'] ); ?>" target="_blank">
                                                    <img src="<?php echo esc_url( $it['thumb_url'] ); ?>" alt="" style="width:44px; height:44px; object-fit:cover; border-radius:6px; display:block;">
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding:6px 8px 6px 0;">
                                            <strong><?php echo esc_html( $it['product_name'] ?? '' ); ?></strong><br>
                                            <span style="color:#9CA3AF; font-size:12px;">
                                                <?php echo esc_html( $it['filename'] ?? '' ); ?> &times; <?php echo (int) ( $it['qty'] ?? 1 ); ?>
                                                <?php if ( ! empty( $it['crop'] ) ) : ?> · <?php echo esc_html( $it['crop'] ); ?><?php endif; ?>
                                            </span>
                                        </td>
                                        <td style="padding:6px 0; text-align:right; white-space:nowrap;">
                                            TT$ <?php echo esc_html( number_format( (float) ( $it['price'] ?? 0 ) * (int) ( $it['qty'] ?? 1 ), 2 ) ); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </table>
                                <?php if ( $order->notes ) : ?>
                                    <p style="margin:10px 0 0; color:#374151; font-size:13px;"><strong>Notes:</strong> <?php echo esc_html( $order->notes ); ?></p>
                                <?php endif; ?>

                                <?php if ( $has_receipt ) : ?>
                                    <h4 style="margin:16px 0 8px;">Bank transfer receipt</h4>
                                    <div class="tf2-po-receipt <?php echo $has_review ? 'tf2-po-receipt--pending' : ''; ?>">
                                        <a href="<?php echo esc_url( $order->receipt_url ); ?>" target="_blank">
                                            <img src="<?php echo esc_url( $order->receipt_url ); ?>" alt="Receipt" style="max-width:140px; max-height:140px; border-radius:8px; display:block; border:1px solid #E5E7EB;">
                                        </a>
                                        <div style="font-size:13px; color:#374151; line-height:1.8;">
                                            <?php if ( ! empty( $order->receipt_ocr ) ) : ?>
                                                OCR read: <strong><?php echo esc_html( $order->receipt_ocr ); ?></strong><br>
                                            <?php endif; ?>
                                            <?php if ( $confirmed_amount !== null ) : ?>
                                                Customer confirmed: <strong style="<?php echo $amount_mismatch ? 'color:#B91C1C;' : 'color:#065F46;'; ?>">TT$ <?php echo esc_html( number_format( $confirmed_amount, 2 ) ); ?></strong>
                                                vs order total <strong>TT$ <?php echo esc_html( number_format( (float) $order->subtotal, 2 ) ); ?></strong>
                                                <?php if ( $amount_mismatch ) : ?>
                                                    <span class="tf2-badge" style="background:#FEE2E2; color:#B91C1C;">Mismatch</span>
                                                <?php else : ?>
                                                    <span class="tf2-badge" style="background:#D1FAE5; color:#065F46;">Match</span>
                                                <?php endif; ?>
                                                <br>
                                            <?php endif; ?>
                                            <?php if ( ! empty( $order->receipt_reference ) ) : ?>
                                                Reference: <strong><?php echo esc_html( $order->receipt_reference ); ?></strong><br>
                                            <?php endif; ?>
                                            <?php if ( ! empty( $order->receipt_uploaded_at ) ) : ?>
                                                <span style="color:#9CA3AF;">Uploaded <?php echo esc_html( date( 'M j, Y g:ia', strtotime( $order->receipt_uploaded_at ) ) ); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ( $payment ) : ?>
                                    <p style="margin:12px 0 0; font-size:13px; color:#065F46;">
                                        <strong>Paid:</strong> TT$ <?php echo esc_html( number_format( (float) ( $payment['total'] ?? 0 ), 2 ) ); ?>
                                        · <?php echo esc_html( ( $payment['method'] ?? '' ) === 'wipay_card' ? 'Card (WiPay)' : 'Bank transfer' ); ?>
                                        <?php if ( ! empty( $payment['transaction_id'] ) ) : ?> · Txn <?php echo esc_html( $payment['transaction_id'] ); ?><?php endif; ?>
                                        <?php if ( ! empty( $payment['paid_at'] ) ) : ?> · <?php echo esc_html( date( 'M j, Y g:ia', strtotime( $payment['paid_at'] ) ) ); ?><?php endif; ?>
                                    </p>
                                <?php endif; ?>

                                <?php
                                // Print-ready files, provider hand-off, shipping label
                                if ( class_exists( 'TwellerFlow2_Print_Fulfillment' ) ) {
                                    TwellerFlow2_Print_Fulfillment::render_order_panel( $order );
                                }
                                ?>
                            </div>
                            <div style="flex:0 0 300px;">
                                <h4 style="margin:8px 0;">Manage</h4>
                                <p style="margin:4px 0; font-size:13px; color:#374151;">
                                    <?php if ( $order->customer_phone ) : ?>
                                        Phone: <strong><?php echo esc_html( $order->customer_phone ); ?></strong><br>
                                    <?php endif; ?>
                                    <?php if ( $order->session_code ) : ?>
                                        Shoot code: <strong><?php echo esc_html( $order->session_code ); ?></strong><br>
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url( $portal_url ); ?>" target="_blank">Customer order portal &rarr;</a>
                                </p>

                                <?php if ( $order->status === 'new' ) : ?>
                                    <form method="post" style="margin:10px 0 14px;">
                                        <?php wp_nonce_field( 'tweller_flow_2_print_confirm_payment' ); ?>
                                        <input type="hidden" name="tweller_flow_2_print_confirm_payment" value="1">
                                        <input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
                                        <button type="submit" class="tf2-btn tf2-btn--sm tf2-po-confirmpay"
                                            onclick="return confirm('Confirm payment for <?php echo esc_js( $order->order_ref ); ?>? The order moves to “Payment confirmed”.');">
                                            &#10003; Confirm payment
                                        </button>
                                        <label style="font-size:12.5px; color:#374151; display:flex; align-items:center; gap:6px; margin-top:6px;">
                                            <input type="checkbox" name="notify_client" value="1" checked>
                                            Email the client that payment is confirmed
                                        </label>
                                    </form>
                                <?php endif; ?>

                                <form method="post" style="display:flex; flex-direction:column; gap:8px; max-width:260px;">
                                    <?php wp_nonce_field( 'tweller_flow_2_print_order_status' ); ?>
                                    <input type="hidden" name="tweller_flow_2_print_order_status" value="1">
                                    <input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
                                    <select name="order_status">
                                        <?php foreach ( $statuses as $s ) : ?>
                                            <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $order->status, $s ); ?>><?php echo esc_html( $labels[ $s ] ?? ucfirst( $s ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label style="font-size:12.5px; color:#374151; display:flex; align-items:center; gap:6px;">
                                        <input type="checkbox" name="notify_client" value="1" checked>
                                        Email the client about this update
                                    </label>
                                    <button type="submit" class="tf2-btn tf2-btn--primary tf2-btn--sm">Update Status</button>
                                </form>
                                <p style="margin-top:12px;">
                                    <a href="<?php echo esc_url( $delete_url ); ?>" style="color:#B91C1C; font-size:12.5px;"
                                       onclick="return confirm('Delete order <?php echo esc_js( $order->order_ref ); ?>? This cannot be undone.');">Delete order</a>
                                </p>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.tf2-po-status--new       { background:#FEF3C7; color:#92400E; }
.tf2-po-status--confirmed { background:#DBEAFE; color:#1E40AF; }
.tf2-po-status--printing  { background:#EDE9FE; color:#5B21B6; }
.tf2-po-status--ready     { background:#D1FAE5; color:#065F46; }
.tf2-po-status--completed { background:#E5E7EB; color:#374151; }
.tf2-po-status--cancelled { background:#FEE2E2; color:#B91C1C; }
.tf2-po-review            { background:#C9A227; color:#101010; margin-top:4px; display:inline-block; }
.tf2-po-receipt           { display:flex; gap:14px; align-items:flex-start; background:#fff; border:1px solid #E5E7EB; border-radius:10px; padding:12px; }
.tf2-po-receipt--pending  { border-color:#C9A227; background:#FDFAF1; }
.tf2-po-confirmpay        { background:#101010; color:#C9A227; font-weight:700; }
.tf2-po-confirmpay:hover  { background:#2A2A2A; color:#E7C55C; }
.tf2-po-row--open > td    { background:#FDFAF1; }
.tf2-po-stage             { display:inline-block; margin-top:4px; font-size:11px; font-weight:600; line-height:1.35; border-radius:5px; padding:2px 7px; }
.tf2-po-stage--idle       { background:#F3F4F6; color:#4B5563; }
.tf2-po-stage--active     { background:#FDFAF1; color:#7A5C08; border:1px solid #E7CF87; }
.tf2-po-stage--done       { background:#101010; color:#C9A227; }
</style>

<script>
(function() {
    var rows = document.querySelectorAll('.tf2-po-row');
    for (var i = 0; i < rows.length; i++) {
        rows[i].addEventListener('click', function(e) {
            if (e.target.closest('a, form, select, input, button:not(.tf2-po-toggle)')) return;
            var id = this.getAttribute('data-order');
            var detail = document.getElementById('tf2-po-detail-' + id);
            if (!detail) return;
            var open = detail.style.display !== 'none';
            detail.style.display = open ? 'none' : '';
            var btn = this.querySelector('.tf2-po-toggle');
            if (btn) btn.textContent = open ? 'Details' : 'Close';
        });
    }
})();
</script>
