<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf-admin">
    <h1>New Session</h1>

    <div class="tf-card tf-card--form">
        <form method="post" action="<?php echo admin_url( 'admin.php' ); ?>">
            <?php wp_nonce_field( 'tweller_flow_create_session' ); ?>
            <input type="hidden" name="tweller_flow_create_session" value="1">

            <h2>Client Information</h2>
            <table class="form-table">
                <tr>
                    <th><label for="client_name">Client Name *</label></th>
                    <td><input type="text" name="client_name" id="client_name" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="client_email">Email</label></th>
                    <td><input type="email" name="client_email" id="client_email" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="client_phone">Phone (with country code)</label></th>
                    <td><input type="text" name="client_phone" id="client_phone" class="regular-text" placeholder="+1868..."></td>
                </tr>
            </table>

            <h2>Session Details</h2>
            <table class="form-table">
                <tr>
                    <th><label for="package_type">Package</label></th>
                    <td>
                        <select name="package_type" id="package_type" class="tf-select">
                            <?php foreach ( $packages as $key => $pkg ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>">
                                    <?php echo esc_html( $pkg['name'] ); ?> — $<?php echo number_format( $pkg['price'], 0 ); ?>
                                    (<?php echo esc_html( $pkg['duration'] ); ?>, ~<?php echo $pkg['images']; ?> images, up to <?php echo $pkg['members']; ?> members)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="session_date">Session Date</label></th>
                    <td><input type="date" name="session_date" id="session_date" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="session_time">Session Time</label></th>
                    <td><input type="time" name="session_time" id="session_time" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="location">Location</label></th>
                    <td><input type="text" name="location" id="location" class="regular-text" placeholder="e.g., Queen's Park Savannah"></td>
                </tr>
                <tr>
                    <th><label for="members_count">Number of Members</label></th>
                    <td><input type="number" name="members_count" id="members_count" class="small-text" value="1" min="1" max="20"></td>
                </tr>
            </table>

            <h2>Payment</h2>
            <table class="form-table">
                <tr>
                    <th><label for="total_amount">Total Amount ($)</label></th>
                    <td><input type="number" name="total_amount" id="total_amount" class="small-text" step="0.01" value="600"></td>
                </tr>
                <tr>
                    <th><label for="deposit_amount">Deposit Amount ($)</label></th>
                    <td><input type="number" name="deposit_amount" id="deposit_amount" class="small-text" step="0.01" value="0"></td>
                </tr>
                <tr>
                    <th><label for="payment_status">Payment Status</label></th>
                    <td>
                        <select name="payment_status" id="payment_status" class="tf-select">
                            <option value="pending">Pending</option>
                            <option value="deposit">Deposit Received</option>
                            <option value="paid">Paid in Full</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="payment_method">Payment Method</label></th>
                    <td>
                        <select name="payment_method" id="payment_method" class="tf-select">
                            <option value="">— Select —</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cash">Cash</option>
                            <option value="wipay">WiPay (Credit Card)</option>
                            <option value="surecart">SureCart</option>
                        </select>
                    </td>
                </tr>
            </table>

            <h2>Notes</h2>
            <table class="form-table">
                <tr>
                    <th><label for="notes">Internal Notes</label></th>
                    <td><textarea name="notes" id="notes" rows="4" class="large-text" placeholder="Any special requests, outfit details, etc."></textarea></td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" class="button button-primary button-hero" value="Create Session">
                <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-sessions' ); ?>" class="button button-hero">Cancel</a>
            </p>
        </form>
    </div>
</div>

<script>
jQuery(function($) {
    // Auto-fill total amount when package changes
    var packagePrices = <?php echo json_encode( array_map( function( $p ) { return $p['price']; }, $packages ) ); ?>;
    $('#package_type').on('change', function() {
        var price = packagePrices[$(this).val()] || 0;
        $('#total_amount').val(price);
        $('#deposit_amount').val(Math.round(price / 2));
    });
});
</script>
