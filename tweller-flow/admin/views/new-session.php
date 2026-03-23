<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf-wrap">
    <div class="tf-page-header">
        <h1>New Session</h1>
        <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-sessions' ); ?>" class="tf-btn tf-btn--secondary">Back to Sessions</a>
    </div>

    <!-- Quick Add -->
    <div class="tf-quick-add">
        <h3>Quick Add (Same-Day / Fast Booking)</h3>
        <form method="post">
            <?php wp_nonce_field( 'tweller_flow_create_session' ); ?>
            <input type="hidden" name="tweller_flow_create_session" value="1">
            <input type="hidden" name="session_date" value="<?php echo date( 'Y-m-d' ); ?>">
            <div class="tf-quick-add__row">
                <div class="tf-quick-add__field">
                    <label>Client Name *</label>
                    <input type="text" name="client_name" required placeholder="John Doe">
                </div>
                <div class="tf-quick-add__field">
                    <label>Email</label>
                    <input type="email" name="client_email" placeholder="john@email.com">
                </div>
                <div class="tf-quick-add__field">
                    <label>Phone</label>
                    <input type="tel" name="client_phone" placeholder="+1234567890">
                </div>
                <div class="tf-quick-add__field">
                    <label>Package</label>
                    <select name="package_type">
                        <?php foreach ( $packages as $key => $pkg ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $pkg['name'] ); ?> — $<?php echo number_format( $pkg['price'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tf-quick-add__field" style="flex:0 0 auto;">
                    <label>&nbsp;</label>
                    <button type="submit" class="tf-btn tf-btn--primary">Create</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Full Form -->
    <div class="tf-card">
        <h2>Full Session Details</h2>
        <form method="post" class="tf-form">
            <?php wp_nonce_field( 'tweller_flow_create_session' ); ?>
            <input type="hidden" name="tweller_flow_create_session" value="1">

            <div class="tf-section-title">Client Information</div>

            <div class="tf-row">
                <div class="tf-field">
                    <label class="tf-field__label">Client Name *</label>
                    <input type="text" name="client_name" required>
                </div>
                <div class="tf-field">
                    <label class="tf-field__label">Email</label>
                    <input type="email" name="client_email">
                </div>
            </div>

            <div class="tf-row">
                <div class="tf-field">
                    <label class="tf-field__label">Phone</label>
                    <input type="tel" name="client_phone">
                </div>
                <div class="tf-field">
                    <label class="tf-field__label">Members</label>
                    <input type="number" name="members_count" value="1" min="1">
                </div>
            </div>

            <hr class="tf-separator">
            <div class="tf-section-title">Session Details</div>

            <div class="tf-row--3">
                <div class="tf-field">
                    <label class="tf-field__label">Package</label>
                    <select name="package_type">
                        <?php foreach ( $packages as $key => $pkg ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $pkg['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tf-field">
                    <label class="tf-field__label">Session Date</label>
                    <input type="date" name="session_date">
                </div>
                <div class="tf-field">
                    <label class="tf-field__label">Session Time</label>
                    <input type="time" name="session_time">
                </div>
            </div>

            <div class="tf-field">
                <label class="tf-field__label">Location</label>
                <input type="text" name="location" placeholder="Beach, Park, Studio...">
            </div>

            <hr class="tf-separator">
            <div class="tf-section-title">Payment</div>

            <div class="tf-row--3">
                <div class="tf-field">
                    <label class="tf-field__label">Payment Status</label>
                    <select name="payment_status">
                        <option value="pending">Pending</option>
                        <option value="deposit">Deposit Paid</option>
                        <option value="paid">Fully Paid</option>
                    </select>
                </div>
                <div class="tf-field">
                    <label class="tf-field__label">Deposit Amount</label>
                    <input type="number" name="deposit_amount" value="0" step="0.01" min="0">
                </div>
                <div class="tf-field">
                    <label class="tf-field__label">Total Amount</label>
                    <input type="number" name="total_amount" value="0" step="0.01" min="0">
                </div>
            </div>

            <div class="tf-field">
                <label class="tf-field__label">Payment Method</label>
                <select name="payment_method">
                    <option value="">Not specified</option>
                    <option value="cash">Cash</option>
                    <option value="transfer">Bank Transfer</option>
                    <option value="surecart">SureCart</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <hr class="tf-separator">
            <div class="tf-section-title">Notes</div>

            <div class="tf-field">
                <label class="tf-field__label">Notes</label>
                <textarea name="notes" placeholder="Any special requests, details..."></textarea>
            </div>

            <div class="tf-btn-group">
                <button type="submit" class="tf-btn tf-btn--primary tf-btn--lg">Create Session</button>
                <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-sessions' ); ?>" class="tf-btn tf-btn--secondary tf-btn--lg">Cancel</a>
            </div>
        </form>
    </div>
</div>
