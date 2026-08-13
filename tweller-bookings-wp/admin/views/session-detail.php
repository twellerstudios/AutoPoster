<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf2-wrap">

    <?php if ( isset( $_GET['created'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Session created! Shoot code: <strong><?php echo esc_html( $session->tracking_code ); ?></strong></div>
    <?php elseif ( isset( $_GET['updated'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Session updated.</div>
    <?php elseif ( isset( $_GET['advanced'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Stage advanced.</div>
    <?php elseif ( isset( $_GET['stage_set'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Stage updated.</div>
    <?php elseif ( isset( $_GET['notified'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Notification sent.</div>
    <?php elseif ( isset( $_GET['receipt_processed'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Receipt processed successfully.</div>
    <?php elseif ( isset( $_GET['printlab'] ) ) : ?>
        <?php
        // The hand-off is reported honestly: only a completed send is a
        // success notice. Assigned-but-still-building, or a failure, must not
        // look identical to "done".
        $tf2_pl_state = isset( $_GET['printlab_state'] ) ? sanitize_key( wp_unslash( $_GET['printlab_state'] ) ) : '';
        $tf2_pl_class = 'tf2-alert--success';
        if ( in_array( $tf2_pl_state, array( 'assigned_build_failed', 'assigned_send_failed', 'error' ), true ) ) {
            $tf2_pl_class = 'tf2-alert--error';
        } elseif ( in_array( $tf2_pl_state, array( 'assigned_building', 'unassigned' ), true ) ) {
            $tf2_pl_class = 'tf2-alert--warning';
        }
        ?>
        <div class="tf2-alert <?php echo esc_attr( $tf2_pl_class ); ?>">
            Print order <strong><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['printlab'] ) ) ); ?></strong> created.
            <?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['printlab_note'] ?? '' ) ) ); ?>
        </div>
    <?php elseif ( isset( $_GET['printlab_error'] ) ) : ?>
        <div class="tf2-alert tf2-alert--error"><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['printlab_error'] ) ) ); ?></div>
    <?php endif; ?>

    <!-- Header -->
    <div class="tf2-detail-header">
        <div class="tf2-detail-header__left">
            <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-2-sessions' ); ?>" class="tf2-btn tf2-btn--ghost tf2-btn--sm" style="margin-bottom:8px;">&larr; Back to Sessions</a>
            <h1><?php echo esc_html( $session->client_name ); ?></h1>
            <?php
            if ( class_exists( 'TwellerFlow2_Image_Consent' ) ) {
                $tf2_consent_badge = TwellerFlow2_Image_Consent::badge_html( $session->id );
                $tf2_client_credit = TwellerFlow2_Image_Consent::print_credit_balance( $session->client_email );
                if ( $tf2_consent_badge || $tf2_client_credit > 0 ) {
                    echo '<div style="margin:6px 0 8px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">';
                    echo $tf2_consent_badge;
                    if ( $tf2_client_credit > 0 ) {
                        echo '<span style="display:inline-flex; align-items:center; gap:6px; background:#DCFCE7; color:#166534; font-weight:700; font-size:12px; padding:5px 11px; border-radius:100px;">&#127873; TT$' . esc_html( number_format( (float) $tf2_client_credit, 2 ) ) . ' print credit</span>';
                    }
                    echo '</div>';
                }
            }
            ?>
            <div class="tf2-detail-header__code">
                Shoot Code: <span><?php echo esc_html( $session->tracking_code ); ?></span>
                <button class="tf2-copy-btn" onclick="navigator.clipboard.writeText('<?php echo esc_attr( $session->tracking_code ); ?>'); this.textContent='Copied!';">Copy</button>
            </div>
        </div>
        <div class="tf2-btn-group">
            <?php if ( $wa_link ) : ?>
                <a href="<?php echo esc_url( $wa_link ); ?>" target="_blank" class="tf2-btn tf2-btn--whatsapp tf2-btn--sm">WhatsApp</a>
            <?php endif; ?>
            <a href="<?php echo esc_url( $tracker_url ); ?>" target="_blank" class="tf2-btn tf2-btn--secondary tf2-btn--sm">Client View</a>
        </div>
    </div>

    <!-- Pipeline Progress -->
    <div class="tf2-card tf2-mb-6">
        <h2>Pipeline</h2>
        <div class="tf2-pipeline">
            <?php
            $current_idx = (int) $session->current_stage_index;
            foreach ( $stage_keys as $idx => $key ) :
                $stage_info = $stages[ $key ];
                $is_completed = $idx < $current_idx;
                $is_current   = $idx === $current_idx;

                $dot_class = $is_completed ? 'completed' : ( $is_current ? 'current' : 'upcoming' );
                $line_class = $idx < $current_idx ? 'completed' : 'upcoming';
            ?>
                <?php
                    // Map stage icon names to Unicode symbols
                    $icon_map = array(
                        'calendar'     => '&#128197;',
                        'download'     => '&#11015;',
                        'filter'       => '&#9881;',
                        'check-square' => '&#9745;',
                        'edit-2'       => '&#9998;',
                        'check-circle' => '&#10004;',
                        'package'      => '&#128230;',
                        'upload'       => '&#11014;',
                        'send'         => '&#10148;',
                    );
                    $icon_char = $icon_map[ $stage_info['icon'] ?? '' ] ?? '';
                ?>
                <div class="tf2-pipeline__step">
                    <div class="tf2-pipeline__node">
                        <div class="tf2-pipeline__dot tf2-pipeline__dot--<?php echo $dot_class; ?>">
                            <?php if ( $is_completed ) : ?>&#10003;<?php elseif ( $icon_char ) : echo $icon_char; endif; ?>
                        </div>
                        <div class="tf2-pipeline__name"><?php echo esc_html( $stage_info['label'] ); ?></div>
                    </div>
                    <?php if ( $idx < count( $stage_keys ) - 1 ) : ?>
                        <div class="tf2-pipeline__line tf2-pipeline__line--<?php echo $line_class; ?>"></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Stage Controls -->
        <div class="tf2-pipeline-controls">
            <div class="tf2-pipeline-controls__title">Stage Controls</div>
            <div class="tf2-pipeline-controls__actions">
                <!-- Advance to Next -->
                <?php if ( $current_idx < count( $stage_keys ) - 1 ) :
                    $next_stage = $stages[ $stage_keys[ $current_idx + 1 ] ]['label'];
                ?>
                    <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=tweller-flow-2-session&action=advance&session_id=' . $session->id ), 'tweller_flow_2_advance_' . $session->id ); ?>"
                       class="tf2-btn tf2-btn--primary"
                       style="background:#3B82F6; color:#FFFFFF; border-color:#3B82F6;"
                       onclick="return confirm('Advance to <?php echo esc_attr( $next_stage ); ?>?');">
                        Advance to <?php echo esc_html( $next_stage ); ?> &rarr;
                    </a>
                <?php endif; ?>

                <?php if ( in_array( $session->current_stage, array( 'uploaded', 'delivered' ), true ) ) : ?>
                    <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=tweller-flow-2-session&action=send_delivery&session_id=' . $session->id ), 'tweller_flow_2_deliver_' . $session->id ); ?>"
                       class="tf2-btn tf2-btn--primary"
                       style="background:#10B981; color:#FFFFFF; border-color:#10B981;"
                       onclick="return confirm('Send gallery delivery email to <?php echo esc_attr( $session->client_name ); ?> (<?php echo esc_attr( $session->client_email ); ?>)?');">
                        &#9993; Send Delivery Email
                    </a>
                <?php endif; ?>

                <!-- Jump to Specific Stage -->
                <form method="post" style="display:inline-flex; gap:6px; align-items:center;">
                    <?php wp_nonce_field( 'tweller_flow_2_set_stage' ); ?>
                    <input type="hidden" name="tweller_flow_2_set_stage" value="1">
                    <input type="hidden" name="session_id" value="<?php echo $session->id; ?>">
                    <select name="stage" class="tf2-filters__select" style="font-size:13px; padding:7px 32px 7px 10px;">
                        <?php foreach ( $stage_keys as $key ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $session->current_stage, $key ); ?>><?php echo esc_html( $stages[ $key ]['label'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="stage_notes" placeholder="Note (optional)" style="padding:7px 10px; font-size:13px; border:1px solid #D1D5DB; border-radius:6px; width:160px; font-family:inherit;">
                    <button type="submit" class="tf2-btn tf2-btn--secondary tf2-btn--sm">Set Stage</button>
                </form>
            </div>
        </div>
    </div>

    <div class="tf2-grid tf2-grid--sidebar">
        <!-- Left Column -->
        <div>
            <?php
            $receipt        = get_option( 'tf_receipt_' . $session->id );
            $receipt_status = $receipt['status'] ?? 'pending'; // pending | approved | rejected
            $is_processed   = in_array( $receipt_status, array( 'approved', 'rejected' ), true );
            ?>
            <?php if ( $receipt || $session->payment_status === 'verifying' ) : ?>
                <details class="tf2-card tf2-mb-6" style="border:1px solid #C7D2FE; background:#EEF2FF; padding:0;" <?php echo $is_processed ? '' : 'open'; ?>>
                    <summary style="cursor:pointer; padding:16px 20px; display:flex; align-items:center; gap:10px; list-style:none;">
                        <h2 style="color:#4F46E5; margin:0; display:inline;">Receipt Verification</h2>
                        <?php if ( $receipt_status === 'approved' ) : ?>
                            <span style="background:#D1FAE5; color:#065F46; font-size:12px; font-weight:600; padding:3px 10px; border-radius:99px;">✓ Approved<?php echo ! empty( $receipt['processed_at'] ) ? ' — ' . esc_html( date( 'M j, Y', strtotime( $receipt['processed_at'] ) ) ) : ''; ?></span>
                        <?php elseif ( $receipt_status === 'rejected' ) : ?>
                            <span style="background:#FEE2E2; color:#991B1B; font-size:12px; font-weight:600; padding:3px 10px; border-radius:99px;">✕ Rejected<?php echo ! empty( $receipt['processed_at'] ) ? ' — ' . esc_html( date( 'M j, Y', strtotime( $receipt['processed_at'] ) ) ) : ''; ?></span>
                        <?php else : ?>
                            <span style="background:#FEF3C7; color:#92400E; font-size:12px; font-weight:600; padding:3px 10px; border-radius:99px;">Awaiting Review</span>
                        <?php endif; ?>
                        <span style="margin-left:auto; color:#6B7280; font-size:12px;">click to expand / collapse</span>
                    </summary>
                    <div style="padding:0 20px 20px;">
                    <?php if ( $receipt ) : ?>
                        <div style="background:#FFF; padding:12px; border-radius:6px; margin-bottom:12px; display:flex; gap:20px;">
                            <div>
                                <strong style="display:block; font-size:12px; color:#6B7280; text-transform:uppercase;">Bank Used</strong>
                                <span style="font-size:16px; font-weight:600; color:#374151;"><?php echo esc_html($receipt['bank'] ?? 'Unknown'); ?></span>
                            </div>
                            <?php if ( ! empty($receipt['ref']) ) : ?>
                            <div>
                                <strong style="display:block; font-size:12px; color:#6B7280; text-transform:uppercase;">Reference / Trx ID</strong>
                                <span style="font-size:16px; font-weight:600; color:#374151;"><?php echo esc_html($receipt['ref']); ?></span>
                            </div>
                            <?php endif; ?>
                            <div>
                                <strong style="display:block; font-size:12px; color:#6B7280; text-transform:uppercase;">AI Detected Amount</strong>
                                <span style="font-size:16px; font-weight:700; color:#10B981;"><?php echo esc_html($receipt['ocr'] ?: 'None detected'); ?></span>
                                <?php if ( ! empty( $receipt['confirmed'] ) ) : ?>
                                    <span style="display:inline-block; background:#D1FAE5; color:#065F46; font-size:11px; font-weight:600; padding:2px 8px; border-radius:99px; margin-left:4px;">✓ Confirmed by client</span>
                                <?php endif; ?>
                                <?php $ocr_num = preg_replace('/[^0-9.]/', '', $receipt['ocr']); ?>
                            </div>
                        </div>
                        
                        <div id="tf2-receipt-preview" style="display:none; margin-bottom:12px; text-align:center;">
                            <img src="<?php echo esc_url($receipt['url']); ?>" style="max-width:100%; border-radius:6px; box-shadow:0 2px 4px rgba(0,0,0,0.1);" />
                        </div>
                        
                        <button type="button" class="tf2-btn tf2-btn--secondary tf2-btn--sm" onclick="document.getElementById('tf2-receipt-preview').style.display = document.getElementById('tf2-receipt-preview').style.display === 'none' ? 'block' : 'none';">Toggle Receipt Image</button>
                        
                        <hr style="border:none; border-top:1px solid #C7D2FE; margin:16px 0;">

                        <?php if ( $is_processed ) : ?>
                            <p style="color:#6B7280; font-size:13px; margin:0;">
                                This receipt was <strong><?php echo esc_html( $receipt_status ); ?></strong><?php echo ! empty( $receipt['processed_at'] ) ? ' on ' . esc_html( date( 'F j, Y \a\t g:i A', strtotime( $receipt['processed_at'] ) ) ) : ''; ?>.
                                The screenshot stays on file here for your records.
                            </p>
                        <?php else : ?>
                        <form method="post" style="display:flex; gap:10px; align-items:center;">
                            <?php wp_nonce_field( 'tweller_flow_2_verify_receipt' ); ?>
                            <input type="hidden" name="tweller_flow_2_verify_receipt" value="1">
                            <input type="hidden" name="session_id" value="<?php echo $session->id; ?>">

                            <select name="verify_action" style="padding:8px; border-radius:6px; font-size:13px; border:1px solid #D1D5DB; background:#FFF; color:#374151;">
                                <option value="approve">Approve Receipt</option>
                                <option value="reject">Reject Receipt (Resets to Pending)</option>
                            </select>

                            <div style="position:relative;">
                                <span style="position:absolute; left:8px; top:8px; color:#6B7280; font-size:13px;">TTD</span>
                                <input type="number" step="0.01" name="amount_paid" value="<?php echo esc_attr( $ocr_num ); ?>" style="padding:8px 8px 8px 36px; width:90px; border-radius:6px; font-size:13px; border:1px solid #D1D5DB; background:#FFF; color:#374151;" title="Edit the confirmed amount" required>
                            </div>

                            <button type="submit" class="tf2-btn tf2-btn--primary">Process Verification</button>
                        </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <p style="color:#6B7280; font-size:13px;">Payment is verifying but no receipt was found. Client may have bypassed standard upload or an error occurred.</p>
                    <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>

            <!-- Client Details (Editable) -->
            <div class="tf2-card tf2-mb-6">
                <div class="tf2-tabs" id="detail-tabs">
                    <button class="tf2-tab tf2-tab--active" data-tab="info">Details</button>
                    <button class="tf2-tab" data-tab="edit">Edit</button>
                    <button class="tf2-tab" data-tab="notify">Notify</button>
                </div>

                <!-- Info Tab -->
                <div class="tf2-tab-content tf2-tab-content--active" id="tab-info">
                    <div class="tf2-client-info">
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Name</span>
                            <span class="tf2-client-info__value"><?php echo esc_html( $session->client_name ); ?></span>
                        </div>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Email</span>
                            <span class="tf2-client-info__value"><?php echo esc_html( $session->client_email ?: '—' ); ?></span>
                        </div>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Phone</span>
                            <span class="tf2-client-info__value"><?php echo esc_html( $session->client_phone ?: '—' ); ?></span>
                        </div>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Package</span>
                            <span class="tf2-client-info__value"><?php
                                $pkg = $packages[ $session->package_type ] ?? array();
                                echo esc_html( $pkg['name'] ?? ucfirst( $session->package_type ) );
                            ?></span>
                        </div>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Session Date</span>
                            <span class="tf2-client-info__value"><?php echo $session->session_date ? date( 'F j, Y', strtotime( $session->session_date ) ) : '—'; ?></span>
                        </div>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Session Time</span>
                            <span class="tf2-client-info__value"><?php echo $session->session_time ? date( 'g:i A', strtotime( $session->session_time ) ) : '—'; ?></span>
                        </div>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Location</span>
                            <span class="tf2-client-info__value"><?php echo esc_html( $session->location ?: '—' ); ?></span>
                        </div>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Members</span>
                            <span class="tf2-client-info__value"><?php echo $session->members_count; ?></span>
                        </div>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Payment</span>
                            <span class="tf2-client-info__value">
                                <?php 
                                    $disp_status = $session->payment_status;
                                    $disp_text = ucfirst($session->payment_status);
                                    if ($session->deposit_amount > 0 && $session->deposit_amount < $session->total_amount) {
                                        $disp_text = 'Partially Paid';
                                        $disp_status = 'deposit';
                                    } elseif ($session->deposit_amount >= $session->total_amount && $session->total_amount > 0) {
                                        $disp_text = 'Fully Paid';
                                        $disp_status = 'paid';
                                    }
                                ?>
                                <span class="tf2-badge tf2-badge--<?php echo esc_attr( $disp_status ); ?>"><?php echo esc_html( $disp_text ); ?></span>
                            </span>
                        </div>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Total</span>
                            <span class="tf2-client-info__value">$<?php echo number_format( $session->total_amount, 2 ); ?></span>
                        </div>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Amount Paid</span>
                            <span class="tf2-client-info__value">$<?php echo number_format( $session->deposit_amount, 2 ); ?></span>
                        </div>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Balance</span>
                            <span class="tf2-client-info__value" style="color:#DC2626; font-weight:600;">$<?php echo number_format( max(0, $session->total_amount - $session->deposit_amount), 2 ); ?></span>
                        </div>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Est. Delivery</span>
                            <span class="tf2-client-info__value"><?php echo $session->estimated_delivery ? date( 'F j, Y', strtotime( $session->estimated_delivery ) ) : '—'; ?></span>
                        </div>
                        <?php if ( $session->gallery_url ) : ?>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Gallery URL</span>
                            <span class="tf2-client-info__value"><a href="<?php echo esc_url( $session->gallery_url ); ?>" target="_blank"><?php echo esc_html( $session->gallery_url ); ?></a></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ( $session->notes ) : ?>
                        <hr class="tf2-separator">
                        <div class="tf2-section-title">Notes</div>
                        <p style="font-size:14px; color:#374151; white-space:pre-wrap;"><?php echo esc_html( $session->notes ); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Edit Tab -->
                <div class="tf2-tab-content" id="tab-edit">
                    <form method="post" class="tf2-form">
                        <?php wp_nonce_field( 'tweller_flow_2_update_session' ); ?>
                        <input type="hidden" name="tweller_flow_2_update_session" value="1">
                        <input type="hidden" name="session_id" value="<?php echo $session->id; ?>">

                        <div class="tf2-row">
                            <div class="tf2-field">
                                <label class="tf2-field__label">Client Name</label>
                                <input type="text" name="client_name" value="<?php echo esc_attr( $session->client_name ); ?>" required>
                            </div>
                            <div class="tf2-field">
                                <label class="tf2-field__label">Email</label>
                                <input type="email" name="client_email" value="<?php echo esc_attr( $session->client_email ); ?>">
                            </div>
                        </div>
                        <div class="tf2-row">
                            <div class="tf2-field">
                                <label class="tf2-field__label">Phone</label>
                                <input type="tel" name="client_phone" value="<?php echo esc_attr( $session->client_phone ); ?>">
                            </div>
                            <div class="tf2-field">
                                <label class="tf2-field__label">Members</label>
                                <input type="number" name="members_count" value="<?php echo $session->members_count; ?>" min="1">
                            </div>
                        </div>
                        <div class="tf2-row--3">
                            <div class="tf2-field">
                                <label class="tf2-field__label">Package</label>
                                <select name="package_type">
                                    <?php foreach ( $packages as $key => $pkg ) : ?>
                                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $session->package_type, $key ); ?>><?php echo esc_html( $pkg['name'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="tf2-field">
                                <label class="tf2-field__label">Session Date</label>
                                <input type="date" name="session_date" value="<?php echo esc_attr( $session->session_date ); ?>">
                            </div>
                            <div class="tf2-field">
                                <label class="tf2-field__label">Session Time</label>
                                <input type="time" name="session_time" value="<?php echo esc_attr( $session->session_time ); ?>">
                            </div>
                        </div>
                        <div class="tf2-field">
                            <label class="tf2-field__label">Location</label>
                            <input type="text" name="location" value="<?php echo esc_attr( $session->location ); ?>">
                        </div>
                        <div class="tf2-row--3">
                            <div class="tf2-field">
                                <label class="tf2-field__label">Payment Status</label>
                                <select name="payment_status">
                                    <option value="pending" <?php selected( $session->payment_status, 'pending' ); ?>>Pending</option>
                                    <option value="deposit" <?php selected( $session->payment_status, 'deposit' ); ?>>Deposit Paid</option>
                                    <option value="paid" <?php selected( $session->payment_status, 'paid' ); ?>>Fully Paid</option>
                                </select>
                            </div>
                            <div class="tf2-field">
                                <label class="tf2-field__label">Deposit</label>
                                <input type="number" name="deposit_amount" value="<?php echo $session->deposit_amount; ?>" step="0.01">
                            </div>
                            <div class="tf2-field">
                                <label class="tf2-field__label">Total</label>
                                <input type="number" name="total_amount" value="<?php echo $session->total_amount; ?>" step="0.01">
                            </div>
                        </div>
                        <div class="tf2-field">
                            <label class="tf2-field__label">Payment Method</label>
                            <select name="payment_method">
                                <option value="" <?php selected( $session->payment_method, '' ); ?>>Not specified</option>
                                <option value="cash" <?php selected( $session->payment_method, 'cash' ); ?>>Cash</option>
                                <option value="transfer" <?php selected( $session->payment_method, 'transfer' ); ?>>Bank Transfer</option>
                                <option value="surecart" <?php selected( $session->payment_method, 'surecart' ); ?>>SureCart</option>
                                <option value="other" <?php selected( $session->payment_method, 'other' ); ?>>Other</option>
                            </select>
                        </div>
                        <div class="tf2-row">
                            <div class="tf2-field">
                                <label class="tf2-field__label">Gallery URL</label>
                                <input type="url" name="gallery_url" value="<?php echo esc_attr( $session->gallery_url ); ?>" placeholder="https://...">
                            </div>
                            <div class="tf2-field">
                                <label class="tf2-field__label">Est. Delivery</label>
                                <input type="date" name="estimated_delivery" value="<?php echo esc_attr( $session->estimated_delivery ); ?>">
                            </div>
                        </div>
                        <div class="tf2-field">
                            <label class="tf2-field__label">Notes</label>
                            <textarea name="notes"><?php echo esc_textarea( $session->notes ); ?></textarea>
                        </div>

                        <hr class="tf2-separator">
                        <div class="tf2-section-title">Client Culling</div>
                        <?php $culling_enabled = TwellerFlow2_Culling::is_culling_enabled( $session->id ); ?>
                        <div class="tf2-field">
                            <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                                <input type="checkbox" name="culling_enabled" value="1" <?php checked( $culling_enabled ); ?> style="width:18px; height:18px; accent-color:#6366F1;">
                                <span><strong>Enable Client Photo Selection</strong></span>
                            </label>
                        </div>
                        <div class="tf2-field">
                            <label class="tf2-field__label">Culling Portal Password</label>
                            <input type="text" name="culling_password" placeholder="Leave blank to keep current">
                        </div>

                        <button type="submit" class="tf2-btn tf2-btn--primary">Save Changes</button>
                    </form>
                </div>

                <!-- Notify Tab -->
                <div class="tf2-tab-content" id="tab-notify">
                    <form method="post" class="tf2-form">
                        <?php wp_nonce_field( 'tweller_flow_2_send_notification' ); ?>
                        <input type="hidden" name="tweller_flow_2_send_notification" value="1">
                        <input type="hidden" name="session_id" value="<?php echo $session->id; ?>">
                        <div class="tf2-field">
                            <label class="tf2-field__label">To</label>
                            <input type="text" value="<?php echo esc_attr( $session->client_email ); ?>" disabled style="background:#F9FAFB;">
                        </div>
                        <div class="tf2-field">
                            <label class="tf2-field__label">Subject</label>
                            <input type="text" name="notif_subject" value="Update on your photo session — Tweller Studios">
                        </div>
                        <div class="tf2-field">
                            <label class="tf2-field__label">Message</label>
                            <textarea name="notif_body" rows="6"><p>Hi <?php echo esc_html( $session->client_name ); ?>,</p>
<p>Here's an update on your photo session.</p>
<p>Track your progress: <a href="<?php echo esc_url( $tracker_url ); ?>">View Tracker</a></p></textarea>
                        </div>
                        <button type="submit" class="tf2-btn tf2-btn--primary" <?php echo empty( $session->client_email ) ? 'disabled title="No email address"' : ''; ?>>Send Email</button>
                    </form>

                    <?php if ( ! empty( $notifications ) ) : ?>
                        <hr class="tf2-separator">
                        <div class="tf2-section-title">Sent Notifications</div>
                        <?php foreach ( array_slice( $notifications, 0, 5 ) as $n ) : ?>
                            <div style="padding:8px 0; border-bottom:1px solid #F3F4F6; font-size:13px;">
                                <span class="tf2-notif-status--<?php echo esc_attr( $n->status ); ?>"><?php echo ucfirst( $n->status ); ?></span>
                                <?php echo esc_html( $n->subject ); ?>
                                <span class="tf2-muted"> — <?php echo wp_date( 'M j, g:i A', strtotime( get_gmt_from_date( $n->sent_at ) ) ); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column -->
        <div>
            <!-- Stage History -->
            <div class="tf2-card tf2-mb-6">
                <h2>History</h2>
                <?php if ( empty( $history ) ) : ?>
                    <p class="tf2-muted" style="font-size:13px;">No history yet.</p>
                <?php else : ?>
                    <div class="tf2-timeline">
                        <?php foreach ( array_reverse( $history ) as $entry ) :
                            $stage_label = $stages[ $entry->stage ]['label'] ?? $entry->stage;
                            if ( strpos( $entry->notes, 'receipt' ) !== false && $entry->stage === 'booked' ) {
                                $stage_label .= ' - Receipt Uploaded';
                            }
                        ?>
                            <div class="tf2-timeline__item">
                                <div class="tf2-timeline__dot"></div>
                                <div class="tf2-timeline__stage"><?php echo esc_html( $stage_label ); ?></div>
                                <div class="tf2-timeline__time"><?php echo wp_date( 'M j, Y — g:i A', strtotime( get_gmt_from_date( $entry->timestamp ) ) ); ?></div>
                                <?php if ( $entry->notes ) : ?>
                                    <div class="tf2-timeline__notes"><?php echo esc_html( $entry->notes ); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Gallery Favourites (CloudSpot-style) -->
            <?php if ( class_exists( 'TwellerFlow2_Gallery_Favorites' ) ) :
                $fav_visitors  = TwellerFlow2_Gallery_Favorites::visitors_with_likes( $session );
                $fav_aggregate = TwellerFlow2_Gallery_Favorites::aggregate_likes( $session );
                $fav_v_count   = TwellerFlow2_Gallery_Favorites::count_visitors( $session->id );
                $fav_l_count   = TwellerFlow2_Gallery_Favorites::count_likes( $session->id );
            ?>
            <div class="tf2-card tf2-mb-6">
                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                    <h2 style="margin:0;">Gallery Favourites</h2>
                    <span style="color:#6B7280; font-size:13px;">
                        <strong><?php echo (int) $fav_v_count; ?></strong> visitor<?php echo $fav_v_count === 1 ? '' : 's'; ?>
                        &middot; <strong><?php echo (int) $fav_l_count; ?></strong> like<?php echo $fav_l_count === 1 ? '' : 's'; ?>
                    </span>
                </div>
                <p class="tf2-description" style="margin-top:6px;">Who viewed the gallery and which photos they hearted — the shortlist worth pushing for prints.</p>

                <?php if ( $fav_v_count > 0 ) :
                    $tf2_export_url = wp_nonce_url(
                        admin_url( 'admin.php?page=tweller-flow-2-session&id=' . (int) $session->id . '&tf2_action=export_visitors&session_id=' . (int) $session->id ),
                        'tweller_flow_2_export_visitors'
                    );
                ?>
                    <p style="margin:2px 0 10px;"><a href="<?php echo esc_url( $tf2_export_url ); ?>" class="button">&#11015; Download viewer list (CSV)</a></p>
                <?php endif; ?>

                <?php if ( empty( $fav_visitors ) ) : ?>
                    <p style="color:#9CA3AF; padding:14px 0;">No one has signed in and liked photos yet. Likes appear here as visitors heart images in the gallery.</p>
                <?php else : ?>

                    <?php if ( ! empty( $fav_aggregate ) ) : ?>
                        <div style="border:1px solid #F3F4F6; border-radius:10px; padding:14px; margin:12px 0 18px; background:#FCFCFD;">
                            <h3 style="margin:0 0 10px; font-size:13px; font-weight:700; color:#374151;">Most loved <span style="font-weight:400; color:#9CA3AF;">— across everyone</span></h3>
                            <div style="display:flex; gap:8px; overflow-x:auto; padding-bottom:4px;">
                                <?php foreach ( array_slice( $fav_aggregate, 0, 24 ) as $ph ) : ?>
                                    <a href="<?php echo esc_url( $ph['url'] ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( $ph['filename'] . ' — ' . $ph['likes'] . ' like' . ( $ph['likes'] === 1 ? '' : 's' ) ); ?>" style="position:relative; flex:0 0 auto; display:block; width:84px; height:84px; border-radius:8px; overflow:hidden; background:#EEF0F3;">
                                        <img src="<?php echo esc_url( $ph['thumb_url'] ); ?>" alt="" loading="lazy" style="width:100%; height:100%; object-fit:cover; display:block;">
                                        <span style="position:absolute; top:4px; right:4px; background:#C9A227; color:#fff; font-size:11px; font-weight:700; line-height:1; padding:3px 6px; border-radius:100px; box-shadow:0 1px 2px rgba(0,0,0,0.25);">&#9829; <?php echo (int) $ph['likes']; ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php foreach ( $fav_visitors as $fv ) : ?>
                        <div style="border-top:1px solid #F3F4F6; padding:14px 0;">
                            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:6px; margin-bottom:10px;">
                                <div>
                                    <strong style="color:#111827;"><?php echo esc_html( $fv['name'] ); ?></strong>
                                    <a href="mailto:<?php echo esc_attr( $fv['email'] ); ?>" style="color:#6B7280; text-decoration:none; margin-left:8px;"><?php echo esc_html( $fv['email'] ); ?></a>
                                </div>
                                <span style="color:#6B7280; font-size:12px;">
                                    <?php echo (int) $fv['like_count']; ?> liked
                                    <?php if ( ! empty( $fv['last_seen'] ) ) : ?>
                                        &middot; last seen <?php echo esc_html( date( 'M j, g:ia', strtotime( $fv['last_seen'] ) ) ); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php if ( empty( $fv['photos'] ) ) : ?>
                                <p style="color:#9CA3AF; font-size:13px; margin:0;">Signed in but hasn't liked anything yet.</p>
                            <?php else : ?>
                                <div style="display:flex; flex-wrap:wrap; gap:6px;">
                                    <?php foreach ( $fv['photos'] as $ph ) : ?>
                                        <a href="<?php echo esc_url( $ph['url'] ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( $ph['filename'] ); ?>" style="display:block; width:70px; height:70px; border-radius:6px; overflow:hidden; background:#EEF0F3;">
                                            <img src="<?php echo esc_url( $ph['thumb_url'] ); ?>" alt="" loading="lazy" style="width:100%; height:100%; object-fit:cover; display:block;">
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Culling Status -->
            <?php $culling = TwellerFlow2_Culling::get_summary( $session->id ); ?>
            <?php if ( $culling['enabled'] ) : ?>
            <div class="tf2-card tf2-mb-6">
                <h2>Client Culling</h2>
                <div class="tf2-client-info">
                    <div class="tf2-client-info__item">
                        <span class="tf2-client-info__label">Status</span>
                        <span class="tf2-client-info__value">
                            <?php if ( $culling['submitted'] ) : ?>
                                <span class="tf2-badge tf2-badge--paid" style="background:#D1FAE5; color:#065F46;">Selections Received</span>
                            <?php elseif ( $culling['ready'] ) : ?>
                                <span class="tf2-badge" style="background:#DBEAFE; color:#1E40AF;">Awaiting Client Selection</span>
                            <?php else : ?>
                                <span class="tf2-badge" style="background:#FEF3C7; color:#92400E;">Proofs Not Uploaded</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="tf2-client-info__item">
                        <span class="tf2-client-info__label">Proof Photos</span>
                        <span class="tf2-client-info__value"><?php echo $culling['proof_count']; ?></span>
                    </div>
                    <?php if ( $culling['submitted'] ) : ?>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Selected</span>
                            <span class="tf2-client-info__value" style="color:#16A34A; font-weight:600;"><?php echo $culling['selection_count']; ?> photos</span>
                        </div>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Submitted At</span>
                            <span class="tf2-client-info__value"><?php echo wp_date( 'M j, Y — g:i A', strtotime( get_gmt_from_date( $culling['submitted'] ) ) ); ?></span>
                        </div>
                        <?php if ( $culling['upsell'] ) : ?>
                            <div class="tf2-client-info__item">
                                <span class="tf2-client-info__label">Upsell</span>
                                <span class="tf2-client-info__value" style="color:#6366F1; font-weight:600;">
                                    +<?php echo $culling['upsell']['tier'] ?: 'All'; ?> photos — $<?php echo $culling['upsell']['price']; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php
                    $culling_url = TwellerFlow2_Culling::get_culling_page_url( $session->tracking_code );
                    ?>
                    <div class="tf2-client-info__item">
                        <span class="tf2-client-info__label">Portal Link</span>
                        <span class="tf2-client-info__value"><a href="<?php echo esc_url( $culling_url ); ?>" target="_blank">View Portal</a></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Proof Upload (Culling) -->
            <?php
            $culling_summary = TwellerFlow2_Culling::get_summary( $session->id );
            $proof_count     = $culling_summary['proof_count'];
            $culling_ready   = $culling_summary['ready'];
            $culling_submitted = $culling_summary['submitted'];
            $culling_enabled = $culling_summary['enabled'];
            $culling_rest    = rest_url( 'tweller-flow-2/v1/culling/' . $session->tracking_code );
            $culling_nonce   = wp_create_nonce( 'wp_rest' );
            $packages_opts   = get_option( 'tweller_flow_2_packages', array() );
            $session_pkg     = $packages_opts[ $session->package_type ] ?? array();
            $pkg_included    = ( $session_pkg['images'] ?? 15 );
            $price_per_photo = get_option( 'tweller_flow_2_culling_price_per_photo', 30 );
            ?>
            <div class="tf2-card tf2-mb-6" id="tf2-proof-manager">
                <!-- Lightroom Workflow Info -->
                <div style="background:#F0F9FF; border:1px solid #BFDBFE; border-radius:8px; padding:12px; margin-bottom:14px; font-size:12px; color:#1E40AF;">
                    <p style="margin:0 0 8px; font-weight:600;">💡 Lightroom Preview Workflow</p>
                    <p style="margin:0 0 6px;"><strong>Step 1:</strong> Export Lightroom previews (or smart previews) as JPEGs — keep the same filenames as originals</p>
                    <p style="margin:0 0 6px;"><strong>Step 2:</strong> Upload the preview JPEGs below (not full RAW files)</p>
                    <p style="margin:0 0 6px;"><strong>Step 3:</strong> Client selects from previews</p>
                    <p style="margin:0;"><strong>Step 4:</strong> Download XMP metadata, extract into your Lightroom folder — stars apply to your original RAW files automatically</p>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                    <div>
                        <h2 style="margin:0 0 2px;">Proof Upload</h2>
                        <p style="margin:0; font-size:12px; color:#9CA3AF;">
                            <?php if ( $culling_submitted ) : ?>
                                <span style="color:#16A34A; font-weight:600;">&#10003; Client submitted <?php echo $culling_summary['selection_count']; ?> selections</span>
                            <?php elseif ( $culling_ready ) : ?>
                                <span style="color:#2563EB; font-weight:600;">&#9679; Awaiting client selection &mdash; <?php echo $proof_count; ?> proofs live</span>
                            <?php elseif ( $culling_enabled ) : ?>
                                <span style="color:#D97706; font-weight:600;">&#9711; <?php echo $proof_count; ?> proofs uploaded &mdash; not yet sent to client</span>
                            <?php else : ?>
                                <span style="color:#9CA3AF;">No proofs uploaded yet</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <label class="tf2-toggle" title="Enable / disable photo selection for this session">
                            <input type="checkbox" id="pm-culling-toggle" <?php checked( $culling_enabled ); ?>>
                            <span class="tf2-toggle__switch"></span>
                            <span class="tf2-toggle__label" style="font-size:12px;">Culling On</span>
                        </label>
                    </div>
                </div>

                <!-- Package info bar -->
                <div style="background:#F8FAFC; border:1px solid #E5E7EB; border-radius:8px; padding:10px 14px; display:flex; gap:20px; flex-wrap:wrap; margin-bottom:14px; font-size:12px; color:#6B7280;">
                    <span><strong style="color:#374151;">Package:</strong> <?php echo esc_html( $session_pkg['name'] ?? ucfirst( $session->package_type ) ); ?></span>
                    <span><strong style="color:#374151;">Included:</strong> <?php echo $pkg_included; ?> photos</span>
                    <span><strong style="color:#374151;">Extra photo rate:</strong> $<?php echo $price_per_photo; ?> TTD each</span>
                </div>

                <!-- Proof thumbnails -->
                <div id="pm-grid" style="<?php echo $proof_count === 0 ? 'display:none;' : 'display:grid;'; ?> grid-template-columns:repeat(auto-fill,minmax(80px,80px)); gap:6px; margin-bottom:14px;">
                    <?php
                    if ( $culling_enabled ) {
                        $proofs    = TwellerFlow2_Culling::get_proofs( $session->id );
                        $proof_url = TwellerFlow2_Culling::get_proof_url( $session->tracking_code );
                        foreach ( $proofs as $proof ) : ?>
                            <div class="pm-proof" data-id="<?php echo $proof->id; ?>" style="position:relative; border-radius:6px; overflow:hidden; width:80px; height:80px; background:#F3F4F6; border:2px solid transparent; flex-shrink:0;">
                                <img src="<?php echo esc_url( $proof_url . '/thumbs/' . $proof->filename ); ?>" alt="<?php echo esc_attr( $proof->filename ); ?>" style="width:80px; height:80px; object-fit:cover; display:block;">
                                <button class="pm-delete-btn" data-id="<?php echo $proof->id; ?>" title="Delete proof" style="position:absolute; top:3px; right:3px; width:20px; height:20px; background:rgba(220,38,38,0.85); color:#fff; border:none; border-radius:50%; font-size:11px; cursor:pointer; line-height:20px; padding:0; text-align:center; display:none;">&times;</button>
                            </div>
                        <?php endforeach;
                    }
                    ?>
                </div>
                <div id="pm-count-label" style="font-size:12px; color:#6B7280; margin-bottom:10px; <?php echo $proof_count === 0 ? 'display:none;' : ''; ?>">
                    <span id="pm-count"><?php echo $proof_count; ?></span> proof<?php echo $proof_count !== 1 ? 's' : ''; ?> uploaded
                    <button id="pm-edit-toggle" class="tf2-btn tf2-btn--secondary tf2-btn--sm" style="margin-left:8px; font-size:11px;">Edit / Delete</button>
                    <button id="pm-delete-all" class="tf2-btn tf2-btn--sm" style="margin-left:4px; font-size:11px; background:#DC2626; border-color:#DC2626; color:#fff;">Delete All</button>
                </div>

                <!-- Drop zone (always available — add more proofs anytime) -->
                <div id="pm-drop-zone" style="border:2px dashed #D1D5DB; border-radius:8px; padding:22px 16px; text-align:center; cursor:pointer; background:#FAFAFA; margin-bottom:12px; transition:border-color .2s, background .2s;">
                    <div style="font-size:24px; margin-bottom:6px;">&#128444;</div>
                    <p style="margin:0 0 4px; font-size:13px; font-weight:600; color:#374151;">Drop proof photos here or <span style="color:#6366F1; text-decoration:underline;">browse</span> to add more</p>
                    <p style="margin:0; font-size:11px; color:#9CA3AF;">JPEG, PNG or WebP — multiple files OK<?php echo $culling_submitted ? ' (client already submitted — new proofs won\'t reopen selection)' : ''; ?></p>
                    <input type="file" id="pm-file-input" accept="image/jpeg,image/png,image/webp" multiple style="display:none;">
                </div>
                <div id="pm-progress" style="display:none; margin-bottom:10px;">
                    <div style="height:4px; background:#E5E7EB; border-radius:2px; overflow:hidden; margin-bottom:4px;">
                        <div id="pm-bar" style="height:100%; background:#6366F1; width:0%; transition:width .3s;"></div>
                    </div>
                    <p id="pm-progress-text" style="font-size:11px; color:#6B7280; margin:0;"></p>
                </div>
                <div id="pm-result" style="display:none; font-size:12px; margin-bottom:10px;"></div>

                <!-- Mark Ready & Password -->
                <?php if ( ! $culling_submitted ) : ?>
                <div style="border-top:1px solid #F3F4F6; padding-top:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <input type="text" id="pm-password" placeholder="Set / change portal password (optional)" style="padding:7px 10px; font-size:12px; border:1px solid #D1D5DB; border-radius:6px; width:220px; font-family:inherit;">
                    <button id="pm-mark-ready" class="tf2-btn tf2-btn--primary tf2-btn--sm" style="background:#16A34A; border-color:#16A34A; font-size:12px;" <?php echo $proof_count === 0 ? 'disabled' : ''; ?>>
                        &#9993; <?php echo $culling_ready ? 'Re-send to Client' : 'Mark Ready &amp; Notify Client'; ?>
                    </button>
                    <?php if ( $culling_ready ) : ?>
                        <a href="<?php echo esc_url( TwellerFlow2_Culling::get_culling_page_url( $session->tracking_code ) ); ?>" target="_blank" class="tf2-btn tf2-btn--secondary tf2-btn--sm" style="font-size:12px;">View Portal</a>
                    <?php endif; ?>
                </div>
                <?php elseif ( $culling_summary['upsell'] && $culling_summary['upsell']['extra_count'] > 0 ) : ?>
                    <div style="background:#FEF9EC; border:1px solid #FCD34D; border-radius:8px; padding:12px 16px; font-size:13px;">
                        <strong style="color:#92400E;">Extra photos:</strong>
                        <?php echo $culling_summary['upsell']['extra_count']; ?> × $<?php echo $culling_summary['upsell']['price_each']; ?> TTD
                        = <strong style="color:#92400E;">$<?php echo $culling_summary['upsell']['total_price']; ?> TTD additional</strong>
                    </div>
                <?php endif; ?>

                <!-- Visual proof grid with selection status (click to edit) -->
                <div style="border-top:1px solid #F3F4F6; padding-top:14px; margin-top:14px; display:none;" id="pm-visual-grid-section">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:8px;">
                        <h3 style="margin:0; font-size:13px; font-weight:600; color:#374151;">All Proofs Overview <span style="font-weight:400; color:#9CA3AF;">— click photos to select / deselect</span></h3>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <span id="pm-sel-count" style="font-size:11px; color:#6B7280;"></span>
                            <button id="pm-save-selections" class="tf2-btn tf2-btn--primary tf2-btn--sm" style="font-size:11px;" disabled>Save Selections</button>
                            <button id="pm-clear-selections" class="tf2-btn tf2-btn--secondary tf2-btn--sm" style="font-size:11px;">Clear All</button>
                        </div>
                    </div>
                    <p style="margin:0 0 10px; font-size:11px; color:#9CA3AF;">
                        <span style="display:inline-block; margin-right:12px;"><span style="display:inline-block; width:12px; height:12px; border:2px solid #16A34A; border-radius:4px; vertical-align:middle;"></span> Selected (Included)</span>
                        <span style="display:inline-block;"><span style="display:inline-block; width:12px; height:12px; border:2px solid #6366F1; border-radius:4px; vertical-align:middle;"></span> Selected (Extra)</span>
                    </p>
                    <div id="pm-visual-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(80px,80px)); gap:8px;">
                        <!-- Loaded via JS -->
                    </div>
                </div>

                <?php if ( $culling_submitted ) :
                    $selections = TwellerFlow2_Culling::get_selections( $session->id );
                    $pkg_inc    = $pkg_included;
                    if ( ! empty( $selections ) ) : ?>
                <div style="border-top:1px solid #F3F4F6; padding-top:14px; margin-top:14px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <h3 style="margin:0; font-size:13px; font-weight:600; color:#374151;">Client Selections (<?php echo count($selections); ?> photos)</h3>
                        <div style="display:flex; gap:6px;">
                            <button id="pm-download-xmp" class="tf2-btn tf2-btn--secondary tf2-btn--sm" style="font-size:11px;" title="Download XMP sidecar files for Lightroom">⬇ XMP (Lightroom)</button>
                            <button id="pm-download-csv" class="tf2-btn tf2-btn--secondary tf2-btn--sm" style="font-size:11px;">⬇ CSV</button>
                        </div>
                    </div>
                    <div style="background:#F5F3FF; border:1px solid #DDD6FE; border-radius:6px; padding:10px; margin-bottom:12px; font-size:11px; color:#5B21B6; line-height:1.6;">
                        <strong>XMP for Lightroom — 3 steps:</strong><br>
                        1. Extract the .xmp files into the <strong>same folder as your RAW files</strong> (names must match, e.g. DSC_1234.CR2 ↔ DSC_1234.xmp).<br>
                        2. In Lightroom, <strong>select all those photos</strong> in Grid view.<br>
                        3. Go to <strong>Metadata &rarr; Read Metadata from Files</strong> and confirm. Restarting Lightroom does <em>not</em> pick up sidecars — this menu step is required for already-imported photos.<br>
                        Selected photos get <span style="font-weight:600;">&#9733; + Blue label</span> (included) or <span style="font-weight:600;">&#9733;&#9733; + Purple label</span> (extra). Then filter by rating &ge; 1 star. <em>Note: sidecars only work for RAW files, not JPEGs.</em>
                    </div>
                    <div style="border:1px solid #E5E7EB; border-radius:8px; overflow:hidden;">
                        <table style="width:100%; border-collapse:collapse; font-size:12px;">
                            <thead>
                                <tr style="background:#F9FAFB;">
                                    <th style="padding:7px 10px; text-align:left; color:#6B7280; font-weight:500; border-bottom:1px solid #E5E7EB;">#</th>
                                    <th style="padding:7px 10px; text-align:left; color:#6B7280; font-weight:500; border-bottom:1px solid #E5E7EB;">Filename</th>
                                    <th style="padding:7px 10px; text-align:center; color:#6B7280; font-weight:500; border-bottom:1px solid #E5E7EB;">Stars</th>
                                    <th style="padding:7px 10px; text-align:left; color:#6B7280; font-weight:500; border-bottom:1px solid #E5E7EB;">Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $idx = 0; foreach ( $selections as $sel ) : $idx++; $star = isset($sel->star_rating) ? (int)$sel->star_rating : ($idx <= $pkg_inc ? 1 : 2); ?>
                                <tr style="<?php echo $idx % 2 === 0 ? 'background:#F9FAFB;' : ''; ?> border-bottom:1px solid #F3F4F6;">
                                    <td style="padding:6px 10px; color:#9CA3AF;"><?php echo $idx; ?></td>
                                    <td style="padding:6px 10px; color:#374151; font-family:monospace;"><?php echo esc_html($sel->filename); ?></td>
                                    <td style="padding:6px 10px; text-align:center; color:<?php echo $star === 1 ? '#D97706' : '#6366F1'; ?>; font-size:14px;">
                                        <?php echo str_repeat('★', $star); ?>
                                    </td>
                                    <td style="padding:6px 10px;">
                                        <?php if ( $star === 1 ) : ?>
                                            <span style="background:#DCFCE7; color:#166534; padding:2px 6px; border-radius:4px; font-size:11px;">Included</span>
                                        <?php else : ?>
                                            <span style="background:#EDE9FE; color:#5B21B6; padding:2px 6px; border-radius:4px; font-size:11px;">Extra</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <script>
            (function() {
                var CODE     = '<?php echo esc_js( $session->tracking_code ); ?>';
                var REST     = '<?php echo esc_js( $culling_rest ); ?>';
                var NONCE    = '<?php echo esc_js( $culling_nonce ); ?>';
                var REST_BASE = '<?php echo esc_js( rest_url( 'tweller-flow-2/v1/' ) ); ?>';

                // ── Toggle culling on/off ──────────────
                var toggleEl = document.getElementById('pm-culling-toggle');
                if (toggleEl) {
                    toggleEl.addEventListener('change', function() {
                        fetch(REST + '/toggle', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                            body: JSON.stringify({ enabled: this.checked })
                        }).catch(function(){});
                    });
                }

                // ── Edit mode (show delete buttons) ────
                var editMode = false;
                var editBtn  = document.getElementById('pm-edit-toggle');
                if (editBtn) {
                    editBtn.addEventListener('click', function() {
                        editMode = !editMode;
                        var btns = document.querySelectorAll('.pm-delete-btn');
                        btns.forEach(function(b) { b.style.display = editMode ? 'block' : 'none'; });
                        editBtn.textContent = editMode ? 'Done Editing' : 'Edit / Delete';
                    });
                }

                // ── Delete ALL proofs ──────────────────
                var delAllBtn = document.getElementById('pm-delete-all');
                if (delAllBtn) {
                    delAllBtn.addEventListener('click', function() {
                        var n = document.getElementById('pm-count');
                        var count = n ? n.textContent : 'all';
                        if (!confirm('Delete ALL ' + count + ' proof photos for this session?\n\nThis also clears any client selections and resets the culling round. This cannot be undone.')) return;
                        delAllBtn.disabled = true;
                        delAllBtn.textContent = 'Deleting...';
                        fetch(REST + '/all-proofs', {
                            method: 'DELETE',
                            headers: { 'X-WP-Nonce': NONCE }
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.ok) {
                                window.location.reload();
                            } else {
                                alert('Could not delete: ' + (data.message || 'unknown error'));
                                delAllBtn.disabled = false;
                                delAllBtn.textContent = 'Delete All';
                            }
                        })
                        .catch(function() {
                            alert('Network error while deleting.');
                            delAllBtn.disabled = false;
                            delAllBtn.textContent = 'Delete All';
                        });
                    });
                }

                // ── Delete proof ───────────────────────
                document.querySelectorAll('.pm-delete-btn').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var id = this.getAttribute('data-id');
                        if (!confirm('Delete this proof photo?')) return;
                        var self = this;
                        fetch(REST_BASE + 'culling/proof/' + id, {
                            method: 'DELETE',
                            headers: { 'X-WP-Nonce': NONCE }
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.ok) {
                                var item = self.closest('.pm-proof');
                                if (item) item.remove();
                                var cnt = document.getElementById('pm-count');
                                if (cnt) cnt.textContent = parseInt(cnt.textContent) - 1;
                            }
                        })
                        .catch(function(){});
                    });
                });

                // ── Upload ─────────────────────────────
                var dz      = document.getElementById('pm-drop-zone');
                var fi      = document.getElementById('pm-file-input');
                var bar     = document.getElementById('pm-bar');
                var barText = document.getElementById('pm-progress-text');
                var result  = document.getElementById('pm-result');
                var grid    = document.getElementById('pm-grid');
                var cntEl   = document.getElementById('pm-count');
                var cntBar  = document.getElementById('pm-count-label');
                var readyBtn = document.getElementById('pm-mark-ready');

                if (dz) {
                    dz.addEventListener('click', function() { fi.click(); });
                    fi.addEventListener('change', function() { uploadFiles(this.files); });
                    dz.addEventListener('dragover', function(e) { e.preventDefault(); dz.style.borderColor='#6366F1'; dz.style.background='#EEF2FF'; });
                    dz.addEventListener('dragleave', function() { dz.style.borderColor='#D1D5DB'; dz.style.background='#FAFAFA'; });
                    dz.addEventListener('drop', function(e) { e.preventDefault(); dz.style.borderColor='#D1D5DB'; dz.style.background='#FAFAFA'; uploadFiles(e.dataTransfer.files); });
                }

                function uploadFiles(fileList) {
                    var files = Array.from(fileList);
                    if (!files.length) return;
                    var total = files.length, saved = 0, errors = [];

                    document.getElementById('pm-progress').style.display = 'block';
                    result.style.display = 'none';

                    function next(i) {
                        if (i >= total) {
                            bar.style.width = '100%';
                            var html = '';
                            if (saved) html += '<span style="color:#16a34a;">&#10003; ' + saved + ' proof(s) uploaded.</span> ';
                            if (errors.length) html += '<span style="color:#dc2626;">&#10007; ' + errors.join(', ') + '</span>';
                            result.innerHTML = html;
                            result.style.display = 'block';
                            document.getElementById('pm-progress').style.display = 'none';
                            if (readyBtn) readyBtn.removeAttribute('disabled');
                            return;
                        }

                        var pct = Math.round((i / total) * 100);
                        bar.style.width = pct + '%';
                        barText.textContent = 'Uploading ' + (i+1) + ' of ' + total + ': ' + files[i].name;

                        var fd = new FormData();
                        fd.append('photo', files[i]);

                        fetch(REST + '/upload-admin', {
                            method: 'POST',
                            headers: { 'X-WP-Nonce': NONCE },
                            body: fd
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.ok) {
                                saved++;
                                addThumb(data);
                                if (cntEl) cntEl.textContent = parseInt(cntEl.textContent || 0) + 1;
                                if (cntBar) cntBar.style.display = '';
                                if (grid) grid.style.display = '';
                            } else {
                                errors.push(files[i].name);
                            }
                            next(i+1);
                        })
                        .catch(function() { errors.push(files[i].name); next(i+1); });
                    }
                    next(0);
                }

                function addThumb(data) {
                    if (!grid) return;
                    var item = document.createElement('div');
                    item.className = 'pm-proof';
                    item.setAttribute('data-id', data.proof_id);
                    item.style.cssText = 'position:relative; border-radius:6px; overflow:hidden; width:80px; height:80px; background:#F3F4F6; border:2px solid transparent; flex-shrink:0;';

                    var img = document.createElement('img');
                    img.src = data.thumb_url;
                    img.style.cssText = 'width:80px; height:80px; object-fit:cover; display:block;';

                    var delBtn = document.createElement('button');
                    delBtn.className = 'pm-delete-btn';
                    delBtn.setAttribute('data-id', data.proof_id);
                    delBtn.title = 'Delete proof';
                    delBtn.style.cssText = 'position:absolute; top:3px; right:3px; width:20px; height:20px; background:rgba(220,38,38,0.85); color:#fff; border:none; border-radius:50%; font-size:11px; cursor:pointer; line-height:20px; padding:0; text-align:center; display:none;';
                    delBtn.textContent = '×';
                    delBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        if (!confirm('Delete this proof?')) return;
                        var self = this;
                        fetch(REST_BASE + 'culling/proof/' + data.proof_id, {
                            method: 'DELETE', headers: { 'X-WP-Nonce': NONCE }
                        }).then(function(r) { return r.json(); })
                        .then(function(d) {
                            if (d.ok) {
                                item.remove();
                                if (cntEl) cntEl.textContent = Math.max(0, parseInt(cntEl.textContent) - 1);
                            }
                        }).catch(function(){});
                    });

                    item.appendChild(img);
                    item.appendChild(delBtn);
                    grid.style.display = '';
                    grid.appendChild(item);
                }

                // ── Mark Ready ─────────────────────────
                if (readyBtn) {
                    readyBtn.addEventListener('click', function() {
                        var pw = document.getElementById('pm-password') ? document.getElementById('pm-password').value.trim() : '';
                        readyBtn.disabled = true;
                        readyBtn.textContent = 'Sending...';

                        fetch(REST + '/admin-ready', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                            body: JSON.stringify({ password: pw })
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.ok) {
                                readyBtn.textContent = '&#9993; Email Sent!';
                                readyBtn.style.background = '#16A34A';
                                setTimeout(function() { location.reload(); }, 1200);
                            } else {
                                readyBtn.disabled = false;
                                readyBtn.textContent = 'Mark Ready & Notify Client';
                                alert('Error: ' + (data.message || 'Could not send email.'));
                            }
                        })
                        .catch(function() {
                            readyBtn.disabled = false;
                            readyBtn.textContent = 'Mark Ready & Notify Client';
                        });
                    });
                }

                // ── Proofs overview: click to select on the client's behalf ────
                var visualGridSection = document.getElementById('pm-visual-grid-section');
                var visualGrid = document.getElementById('pm-visual-grid');
                var saveSelBtn  = document.getElementById('pm-save-selections');
                var clearSelBtn = document.getElementById('pm-clear-selections');
                var selCountEl  = document.getElementById('pm-sel-count');
                var INCLUDED    = <?php echo intval( $pkg_included ?? 15 ); ?>;
                var adminSel    = [];   // proof ids in click order
                var selDirty    = false;

                function repaintVisualGrid() {
                    visualGrid.querySelectorAll('.pm-vis-item').forEach(function(item) {
                        var id  = parseInt(item.getAttribute('data-id'));
                        var pos = adminSel.indexOf(id);
                        var badge = item.querySelector('.pm-vis-badge');
                        if (pos === -1) {
                            item.style.border = '3px solid transparent';
                            badge.style.display = 'none';
                        } else {
                            var isExtra = pos >= INCLUDED;
                            var color = isExtra ? '#6366F1' : '#16A34A';
                            item.style.border = '3px solid ' + color;
                            badge.style.display = 'block';
                            badge.style.background = color;
                            badge.textContent = isExtra ? '★★' : '★';
                        }
                    });
                    if (selCountEl) {
                        var extra = Math.max(0, adminSel.length - INCLUDED);
                        selCountEl.textContent = adminSel.length ? adminSel.length + ' selected' + (extra ? ' (' + extra + ' extra)' : '') : '';
                    }
                    if (saveSelBtn) saveSelBtn.disabled = !selDirty;
                }

                if (visualGrid && CODE) {
                    fetch(REST + '/admin-proofs-status', { headers: { 'X-WP-Nonce': NONCE } })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (!d.ok || !d.proofs) return;
                        visualGrid.innerHTML = '';

                        // Seed current selections: included (1-star) first so
                        // click-order star logic matches what's saved.
                        // (IDs arrive as strings from the API — normalize to ints.)
                        d.proofs.filter(function(p) { return p.selected && parseInt(p.star_rating) === 1; })
                                .forEach(function(p) { adminSel.push(parseInt(p.id)); });
                        d.proofs.filter(function(p) { return p.selected && parseInt(p.star_rating) !== 1; })
                                .forEach(function(p) { adminSel.push(parseInt(p.id)); });

                        d.proofs.forEach(function(proof) {
                            var item = document.createElement('div');
                            item.className = 'pm-vis-item';
                            item.setAttribute('data-id', proof.id);
                            item.style.cssText = 'position:relative; border-radius:6px; overflow:hidden; width:80px; height:80px; background:#F3F4F6; cursor:pointer; border:3px solid transparent;';

                            var img = document.createElement('img');
                            img.src = proof.thumb_url;
                            img.style.cssText = 'width:80px; height:80px; object-fit:cover; display:block;';
                            item.appendChild(img);

                            var badge = document.createElement('div');
                            badge.className = 'pm-vis-badge';
                            badge.style.cssText = 'position:absolute; bottom:2px; right:2px; color:#fff; font-size:11px; padding:2px 5px; border-radius:3px; font-weight:bold; display:none;';
                            item.appendChild(badge);

                            item.addEventListener('click', function() {
                                var id = parseInt(proof.id);
                                var pos = adminSel.indexOf(id);
                                if (pos === -1) adminSel.push(id);
                                else adminSel.splice(pos, 1);
                                selDirty = true;
                                repaintVisualGrid();
                            });

                            visualGrid.appendChild(item);
                        });

                        repaintVisualGrid();
                        if (d.proofs.length > 0) {
                            visualGridSection.style.display = 'block';
                        }
                    })
                    .catch(function(){});
                }

                if (saveSelBtn) {
                    saveSelBtn.addEventListener('click', function() {
                        saveSelBtn.disabled = true;
                        saveSelBtn.textContent = 'Saving...';
                        fetch(REST + '/admin-select', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                            body: JSON.stringify({ proof_ids: adminSel })
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            if (d.ok) {
                                window.location.reload();
                            } else {
                                alert('Could not save: ' + (d.message || 'unknown error'));
                                saveSelBtn.disabled = false;
                                saveSelBtn.textContent = 'Save Selections';
                            }
                        })
                        .catch(function() {
                            alert('Network error while saving.');
                            saveSelBtn.disabled = false;
                            saveSelBtn.textContent = 'Save Selections';
                        });
                    });
                }

                if (clearSelBtn) {
                    clearSelBtn.addEventListener('click', function() {
                        if (adminSel.length === 0) return;
                        if (!confirm('Clear ALL selections? This reopens the selection round for the client.')) return;
                        adminSel = [];
                        selDirty = true;
                        repaintVisualGrid();
                    });
                }

                // ── Download Selections as XMP ─────────
                var dlXmpBtn = document.getElementById('pm-download-xmp');
                if (dlXmpBtn) {
                    dlXmpBtn.addEventListener('click', function() {
                        dlXmpBtn.disabled = true;
                        dlXmpBtn.textContent = 'Downloading...';
                        fetch(REST + '/download-xmp', { headers: { 'X-WP-Nonce': NONCE } })
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            if (d.ok && d.zip_base64) {
                                var binary = atob(d.zip_base64);
                                var bytes = new Uint8Array(binary.length);
                                for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
                                var blob = new Blob([bytes], { type: 'application/zip' });
                                var url  = URL.createObjectURL(blob);
                                var a    = document.createElement('a');
                                a.href = url; a.download = d.filename; a.click();
                                URL.revokeObjectURL(url);
                                alert(d.instruction);
                            } else {
                                alert('Could not download XMP files.');
                            }
                            dlXmpBtn.disabled = false;
                            dlXmpBtn.textContent = '⬇ XMP (Lightroom)';
                        })
                        .catch(function() {
                            alert('Network error.');
                            dlXmpBtn.disabled = false;
                            dlXmpBtn.textContent = '⬇ XMP (Lightroom)';
                        });
                    });
                }

                // ── Download Selections CSV ────────────
                var dlBtn = document.getElementById('pm-download-csv');
                if (dlBtn) {
                    dlBtn.addEventListener('click', function() {
                        dlBtn.disabled = true;
                        dlBtn.textContent = 'Downloading...';
                        fetch(REST + '/download-selections', { headers: { 'X-WP-Nonce': NONCE } })
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            if (d.ok && d.csv) {
                                var blob = new Blob([d.csv], { type: 'text/csv' });
                                var url  = URL.createObjectURL(blob);
                                var a    = document.createElement('a');
                                a.href = url; a.download = d.filename; a.click();
                                URL.revokeObjectURL(url);
                            } else {
                                alert('Could not download selections.');
                            }
                            dlBtn.disabled = false;
                            dlBtn.textContent = '⬇ CSV';
                        })
                        .catch(function() {
                            alert('Network error.');
                            dlBtn.disabled = false;
                            dlBtn.textContent = '⬇ CSV';
                        });
                    });
                }
            })();
            </script>

            <!-- Client Activity -->
            <?php $activity = TwellerFlow2_Client_Activity::get_summary( $session->id ); ?>
            <div class="tf2-card tf2-mb-6">
                <h2>Client Activity</h2>
                <?php if ( $activity['first_viewed'] ) : ?>
                    <div class="tf2-client-info">
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">First Viewed</span>
                            <span class="tf2-client-info__value"><?php echo wp_date( 'M j, Y — g:i A', strtotime( get_gmt_from_date( $activity['first_viewed'] ) ) ); ?></span>
                        </div>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Last Viewed</span>
                            <span class="tf2-client-info__value"><?php echo wp_date( 'M j, Y — g:i A', strtotime( get_gmt_from_date( $activity['last_viewed'] ) ) ); ?></span>
                        </div>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Total Views</span>
                            <span class="tf2-client-info__value"><?php echo $activity['total_views']; ?></span>
                        </div>
                        <?php if ( $activity['first_downloaded'] ) : ?>
                            <div class="tf2-client-info__item">
                                <span class="tf2-client-info__label">First Download</span>
                                <span class="tf2-client-info__value" style="color:#16A34A; font-weight:600;"><?php echo wp_date( 'M j, Y — g:i A', strtotime( get_gmt_from_date( $activity['first_downloaded'] ) ) ); ?></span>
                            </div>
                            <div class="tf2-client-info__item">
                                <span class="tf2-client-info__label">Last Download</span>
                                <span class="tf2-client-info__value"><?php echo wp_date( 'M j, Y — g:i A', strtotime( get_gmt_from_date( $activity['last_downloaded'] ) ) ); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="tf2-client-info__item">
                            <span class="tf2-client-info__label">Photos Downloaded</span>
                            <span class="tf2-client-info__value"><?php echo $activity['total_downloads']; ?> individual<?php if ( $activity['full_downloads'] ) echo ' + ' . $activity['full_downloads'] . ' full gallery'; ?></span>
                        </div>
                    </div>
                <?php else : ?>
                    <p class="tf2-muted" style="font-size:13px;">Client has not viewed the gallery yet.</p>
                <?php endif; ?>
            </div>

            <!-- Gallery Management -->
            <?php
            $gallery_info = TwellerFlow2_Gallery::get_gallery_info( $session->id, $session->tracking_code );
            $gallery_url  = TwellerFlow2_Gallery::get_gallery_url( $session->tracking_code );
            ?>
            <div class="tf2-card tf2-mb-6" id="tf2-gallery-manager">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <h2 style="margin:0;">Gallery (<span id="gm-count"><?php echo $gallery_info['photo_count']; ?></span> photos<?php echo $gallery_info['total_size_mb'] ? ' / ' . $gallery_info['total_size_mb'] . ' MB' : ''; ?>)</h2>
                    <?php if ( $gallery_info['photo_count'] > 0 ) : ?>
                        <button id="gm-edit-btn" class="tf2-btn tf2-btn--secondary tf2-btn--sm" style="font-size:12px;" title="Edit gallery">&#9998; Edit</button>
                    <?php endif; ?>
                </div>

                <!-- Edit mode toolbar (hidden by default) -->
                <div id="gm-toolbar" style="display:none; padding:10px 14px; background:#FEF3C7; border:1px solid #FCD34D; border-radius:8px; margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                        <div style="display:flex; gap:8px; align-items:center;">
                            <span id="gm-selected-count" style="font-size:12px; color:#92400E; font-weight:600;">0 selected</span>
                            <button id="gm-select-all" class="tf2-btn tf2-btn--secondary tf2-btn--sm" style="font-size:11px;">Select All</button>
                            <button id="gm-delete-selected" class="tf2-btn tf2-btn--sm" style="font-size:11px; background:#DC2626; color:#fff; border-color:#DC2626;" disabled>Delete Selected</button>
                        </div>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <button id="gm-save-order" class="tf2-btn tf2-btn--sm" style="font-size:11px; background:#6366F1; color:#fff; border-color:#6366F1; display:none;">Save Order</button>
                            <button id="gm-done-btn" class="tf2-btn tf2-btn--secondary tf2-btn--sm" style="font-size:11px;">Done</button>
                        </div>
                    </div>
                    <p style="margin:6px 0 0; font-size:11px; color:#92400E;">Click photos to select. Drag to reorder.</p>
                </div>

                <?php
                $cover = $gallery_info['photo_count'] > 0 ? TwellerFlow2_Gallery::get_cover( $session ) : null;
                ?>
                <?php if ( $gallery_info['photo_count'] > 0 ) : ?>
                    <div id="gm-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(90px,1fr)); gap:6px; margin-bottom:16px;">
                        <?php foreach ( $gallery_info['photos'] as $photo ) : ?>
                            <div class="gm-photo" data-id="<?php echo $photo->id; ?>" style="position:relative; border-radius:6px; overflow:hidden; aspect-ratio:1; background:#F3F4F6; cursor:default; border:2px solid transparent; transition:border-color .15s;">
                                <img src="<?php echo esc_url( $gallery_url . '/thumbs/' . $photo->filename ); ?>" alt="<?php echo esc_attr( $photo->filename ); ?>" style="width:100%; height:100%; object-fit:cover; pointer-events:none;">
                                <div class="gm-check" style="display:none; position:absolute; top:4px; left:4px; width:20px; height:20px; background:#3B82F6; border-radius:50%; color:#fff; font-size:12px; line-height:20px; text-align:center;">&#10003;</div>
                                <div class="gm-order-badge" style="display:none; position:absolute; bottom:4px; right:4px; min-width:20px; height:20px; background:rgba(0,0,0,0.6); border-radius:10px; color:#fff; font-size:10px; line-height:20px; text-align:center; padding:0 5px;"></div>
                                <button class="gm-cover-btn" data-id="<?php echo $photo->id; ?>" data-url="<?php echo esc_url( $gallery_url . '/' . $photo->filename ); ?>" title="Set as cover photo" style="position:absolute; top:4px; right:4px; width:22px; height:22px; background:rgba(0,0,0,0.55); color:#fff; border:none; border-radius:50%; font-size:12px; line-height:22px; padding:0; cursor:pointer; text-align:center; <?php echo ( $cover && (int) $cover['photo_id'] === (int) $photo->id ) ? 'background:#F59E0B;' : ''; ?>">&#9733;</button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Cover photo & positioning -->
                    <div id="gm-cover-panel" style="border:1px solid #E5E7EB; border-radius:10px; padding:14px; margin-bottom:16px; background:#FAFAFA;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <h3 style="margin:0; font-size:13px; font-weight:600; color:#374151;">Gallery Cover &amp; Framing</h3>
                            <button id="gm-cover-save" class="tf2-btn tf2-btn--primary tf2-btn--sm" style="font-size:11px;" disabled>Save Position</button>
                        </div>
                        <p style="margin:0 0 10px; font-size:11px; color:#6B7280;">
                            This framing is what the client sees at the top of their gallery, and what shows when the link is shared on WhatsApp / social media.
                            Click the &#9733; on any photo above to make it the cover, then <strong>drag inside the preview</strong> to frame it.
                        </p>
                        <div id="gm-cover-preview" style="position:relative; width:100%; max-width:640px; aspect-ratio:21/9; border-radius:8px; overflow:hidden; background:#111 center/cover no-repeat; cursor:grab; user-select:none; touch-action:none;
                            background-image:url('<?php echo $cover ? esc_url( $cover['url'] ) : ''; ?>');
                            background-position:<?php echo $cover ? esc_attr( $cover['position'] ) : 'center'; ?>;">
                            <div style="position:absolute; inset:0; display:flex; align-items:flex-end; padding:10px; pointer-events:none;">
                                <span style="background:rgba(0,0,0,0.55); color:#fff; font-size:10px; padding:3px 8px; border-radius:5px;">Client hero preview — drag to reposition</span>
                            </div>
                        </div>
                        <p id="gm-cover-status" style="margin:8px 0 0; font-size:11px; color:#16A34A; display:none;"></p>
                    </div>
                <?php endif; ?>

                <!-- Drop zone upload -->
                <div id="sd-drop-zone" style="border:2px dashed #D1D5DB; border-radius:8px; padding:24px 16px; text-align:center; cursor:pointer; background:#FAFAFA; margin-bottom:12px; transition:border-color .2s,background .2s;">
                    <div style="font-size:24px; margin-bottom:6px;">&#128444;</div>
                    <p style="margin:0 0 6px; font-size:13px; color:#374151; font-weight:600;">Drop photos here or <span style="color:#6366F1; text-decoration:underline;">browse</span></p>
                    <p style="margin:0; font-size:11px; color:#9CA3AF;">JPEG, PNG or WebP — multiple files supported</p>
                    <input type="file" id="sd-file-input" accept="image/jpeg,image/png,image/webp" multiple style="display:none;">
                </div>
                <div id="sd-progress" style="display:none; margin-bottom:10px;">
                    <div style="height:5px; background:#E5E7EB; border-radius:3px; overflow:hidden;">
                        <div id="sd-bar" style="height:100%; background:#6366F1; width:0%; transition:width .3s;"></div>
                    </div>
                    <p id="sd-progress-text" style="font-size:11px; color:#6B7280; margin:4px 0 0;"></p>
                </div>
                <div id="sd-result" style="display:none; font-size:12px; margin-bottom:10px;"></div>

                <script>
                (function($){
                    var SESSION_CODE = '<?php echo esc_js( $session->tracking_code ); ?>';
                    var restBase = '<?php echo esc_js( rest_url( 'tweller-flow-2/v1/gallery/' . $session->tracking_code ) ); ?>';
                    var restNonce = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';

                    // ── Upload ─────────────────────────────
                    var files = [];
                    var dz = document.getElementById('sd-drop-zone');
                    var fi = document.getElementById('sd-file-input');

                    dz.addEventListener('click', function(){ fi.click(); });
                    fi.addEventListener('change', function(){ startUpload(this.files); });
                    dz.addEventListener('dragover', function(e){ e.preventDefault(); dz.style.borderColor='#6366F1'; dz.style.background='#EEF2FF'; });
                    dz.addEventListener('dragleave', function(){ dz.style.borderColor='#D1D5DB'; dz.style.background='#FAFAFA'; });
                    dz.addEventListener('drop', function(e){ e.preventDefault(); dz.style.borderColor='#D1D5DB'; dz.style.background='#FAFAFA'; startUpload(e.dataTransfer.files); });

                    function startUpload(fileList) {
                        files = Array.from(fileList);
                        if (!files.length) return;
                        var total = files.length, saved = [], errors = [];
                        $('#sd-progress').show();
                        $('#sd-result').hide().empty();

                        function next(i) {
                            if (i >= total) {
                                $('#sd-bar').css('width','100%');
                                var html = '';
                                if (saved.length) html += '<span style="color:#16a34a;">&#10003; ' + saved.length + ' photo(s) uploaded.</span> ';
                                if (errors.length) html += '<span style="color:#dc2626;">&#10007; ' + errors.join(', ') + '</span>';
                                $('#sd-result').html(html).show();
                                setTimeout(function(){ location.reload(); }, 1200);
                                return;
                            }
                            var pct = Math.round((i / total) * 100);
                            $('#sd-bar').css('width', pct + '%');
                            $('#sd-progress-text').text('Uploading ' + (i+1) + ' of ' + total + ': ' + files[i].name);

                            var fd = new FormData();
                            fd.append('action', 'tweller_flow_2_admin_upload');
                            fd.append('nonce', twellerFlow2.nonce);
                            fd.append('session_code', SESSION_CODE);
                            fd.append('photos[]', files[i]);

                            $.ajax({
                                url: twellerFlow2.ajaxUrl, method: 'POST',
                                data: fd, processData: false, contentType: false,
                                success: function(r){
                                    if (r.success) { saved = saved.concat(r.data.saved||[]); errors = errors.concat(r.data.errors||[]); }
                                    else { errors.push(files[i].name + ': ' + (r.data||'error')); }
                                    next(i+1);
                                },
                                error: function(){ errors.push(files[i].name + ': network error'); next(i+1); }
                            });
                        }
                        next(0);
                    }

                    // ── Cover photo & framing ──────────────
                    var coverPreview = document.getElementById('gm-cover-preview');
                    var coverSaveBtn = document.getElementById('gm-cover-save');
                    var coverStatus  = document.getElementById('gm-cover-status');
                    var coverPos = { x: <?php echo $cover ? floatval( $cover['pos_x'] ) : 50; ?>, y: <?php echo $cover ? floatval( $cover['pos_y'] ) : 50; ?> };
                    var coverDirty = false;

                    function coverShowStatus(msg, ok) {
                        if (!coverStatus) return;
                        coverStatus.textContent = msg;
                        coverStatus.style.color = ok ? '#16A34A' : '#DC2626';
                        coverStatus.style.display = 'block';
                        setTimeout(function(){ coverStatus.style.display = 'none'; }, 3000);
                    }

                    function saveCover(payload, doneMsg) {
                        return fetch(restBase + '/cover', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
                            body: JSON.stringify(payload)
                        })
                        .then(function(r){ return r.json(); })
                        .then(function(d){
                            if (d.ok) {
                                coverShowStatus(doneMsg, true);
                                if (d.cover && coverPreview) {
                                    coverPreview.style.backgroundImage = 'url(' + d.cover.url + ')';
                                }
                            } else {
                                coverShowStatus('Could not save cover.', false);
                            }
                            return d;
                        })
                        .catch(function(){ coverShowStatus('Network error saving cover.', false); });
                    }

                    // Set-as-cover star buttons
                    document.querySelectorAll('.gm-cover-btn').forEach(function(btn) {
                        btn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            var id = this.getAttribute('data-id');
                            var url = this.getAttribute('data-url');
                            document.querySelectorAll('.gm-cover-btn').forEach(function(b){ b.style.background = 'rgba(0,0,0,0.55)'; });
                            this.style.background = '#F59E0B';
                            if (coverPreview) {
                                coverPreview.style.backgroundImage = 'url(' + url + ')';
                                coverPos = { x: 50, y: 50 };
                                coverPreview.style.backgroundPosition = '50% 50%';
                            }
                            saveCover({ photo_id: parseInt(id), pos_x: 50, pos_y: 50 }, 'Cover photo updated.');
                        });
                    });

                    // Drag inside the preview to reposition (moves background-position)
                    if (coverPreview) {
                        var dragging = false, startX = 0, startY = 0, startPos = null;

                        coverPreview.addEventListener('pointerdown', function(e) {
                            dragging = true;
                            startX = e.clientX; startY = e.clientY;
                            startPos = { x: coverPos.x, y: coverPos.y };
                            coverPreview.style.cursor = 'grabbing';
                            coverPreview.setPointerCapture(e.pointerId);
                        });
                        coverPreview.addEventListener('pointermove', function(e) {
                            if (!dragging) return;
                            var rect = coverPreview.getBoundingClientRect();
                            // Dragging the image right moves focus left (natural drag feel)
                            var dx = ((e.clientX - startX) / rect.width) * 100;
                            var dy = ((e.clientY - startY) / rect.height) * 100;
                            coverPos.x = Math.max(0, Math.min(100, startPos.x - dx));
                            coverPos.y = Math.max(0, Math.min(100, startPos.y - dy));
                            coverPreview.style.backgroundPosition = coverPos.x + '% ' + coverPos.y + '%';
                            coverDirty = true;
                            if (coverSaveBtn) coverSaveBtn.disabled = false;
                        });
                        function endDrag() {
                            dragging = false;
                            coverPreview.style.cursor = 'grab';
                        }
                        coverPreview.addEventListener('pointerup', endDrag);
                        coverPreview.addEventListener('pointercancel', endDrag);
                    }

                    if (coverSaveBtn) {
                        coverSaveBtn.addEventListener('click', function() {
                            coverSaveBtn.disabled = true;
                            coverSaveBtn.textContent = 'Saving...';
                            saveCover({ pos_x: coverPos.x, pos_y: coverPos.y }, 'Framing saved — clients and shared links now use this view.')
                            .then(function(){
                                coverSaveBtn.textContent = 'Save Position';
                                coverDirty = false;
                            });
                        });
                    }

                    // ── Gallery Edit Mode ──────────────────
                    var editMode = false;
                    var selected = new Set();
                    var orderChanged = false;
                    var dragItem = null;
                    var dragOverItem = null;

                    var editBtn = document.getElementById('gm-edit-btn');
                    var toolbar = document.getElementById('gm-toolbar');
                    var grid    = document.getElementById('gm-grid');

                    if (editBtn) {
                        editBtn.addEventListener('click', function() {
                            editMode = !editMode;
                            toolbar.style.display = editMode ? '' : 'none';
                            editBtn.innerHTML = editMode ? '&#10005; Cancel' : '&#9998; Edit';
                            selected.clear();
                            orderChanged = false;
                            updateEditUI();
                        });
                    }

                    $('#gm-done-btn').on('click', function() {
                        editMode = false;
                        toolbar.style.display = 'none';
                        if (editBtn) editBtn.innerHTML = '&#9998; Edit';
                        selected.clear();
                        orderChanged = false;
                        updateEditUI();
                    });

                    function updateEditUI() {
                        var photos = grid ? grid.querySelectorAll('.gm-photo') : [];
                        photos.forEach(function(el, idx) {
                            var check = el.querySelector('.gm-check');
                            var badge = el.querySelector('.gm-order-badge');
                            var isSelected = selected.has(el.dataset.id);

                            if (editMode) {
                                el.style.cursor = 'pointer';
                                el.setAttribute('draggable', 'true');
                                check.style.display = isSelected ? '' : 'none';
                                el.style.borderColor = isSelected ? '#3B82F6' : 'transparent';
                                badge.style.display = '';
                                badge.textContent = idx + 1;
                            } else {
                                el.style.cursor = 'default';
                                el.removeAttribute('draggable');
                                check.style.display = 'none';
                                el.style.borderColor = 'transparent';
                                badge.style.display = 'none';
                            }
                        });

                        $('#gm-selected-count').text(selected.size + ' selected');
                        $('#gm-delete-selected').prop('disabled', selected.size === 0);
                        $('#gm-save-order').toggle(orderChanged);
                    }

                    // Click to select
                    if (grid) {
                        grid.addEventListener('click', function(e) {
                            if (!editMode) return;
                            var photo = e.target.closest('.gm-photo');
                            if (!photo) return;
                            var id = photo.dataset.id;
                            if (selected.has(id)) {
                                selected.delete(id);
                            } else {
                                selected.add(id);
                            }
                            updateEditUI();
                        });

                        // Drag and drop reorder
                        grid.addEventListener('dragstart', function(e) {
                            if (!editMode) return;
                            dragItem = e.target.closest('.gm-photo');
                            if (dragItem) {
                                dragItem.style.opacity = '0.4';
                                e.dataTransfer.effectAllowed = 'move';
                            }
                        });

                        grid.addEventListener('dragover', function(e) {
                            if (!editMode || !dragItem) return;
                            e.preventDefault();
                            e.dataTransfer.dropEffect = 'move';
                            var target = e.target.closest('.gm-photo');
                            if (target && target !== dragItem) {
                                if (dragOverItem) dragOverItem.style.borderColor = selected.has(dragOverItem.dataset.id) ? '#3B82F6' : 'transparent';
                                dragOverItem = target;
                                dragOverItem.style.borderColor = '#FCD34D';
                            }
                        });

                        grid.addEventListener('drop', function(e) {
                            if (!editMode || !dragItem) return;
                            e.preventDefault();
                            var target = e.target.closest('.gm-photo');
                            if (target && target !== dragItem) {
                                // Reorder DOM
                                var allPhotos = Array.from(grid.querySelectorAll('.gm-photo'));
                                var fromIdx = allPhotos.indexOf(dragItem);
                                var toIdx = allPhotos.indexOf(target);
                                if (fromIdx < toIdx) {
                                    target.parentNode.insertBefore(dragItem, target.nextSibling);
                                } else {
                                    target.parentNode.insertBefore(dragItem, target);
                                }
                                orderChanged = true;
                            }
                        });

                        grid.addEventListener('dragend', function() {
                            if (dragItem) dragItem.style.opacity = '';
                            if (dragOverItem) dragOverItem.style.borderColor = selected.has(dragOverItem.dataset.id) ? '#3B82F6' : 'transparent';
                            dragItem = null;
                            dragOverItem = null;
                            updateEditUI();
                        });
                    }

                    // Select All
                    $('#gm-select-all').on('click', function() {
                        var photos = grid ? grid.querySelectorAll('.gm-photo') : [];
                        if (selected.size === photos.length) {
                            selected.clear();
                        } else {
                            photos.forEach(function(el) { selected.add(el.dataset.id); });
                        }
                        updateEditUI();
                    });

                    // Delete selected
                    $('#gm-delete-selected').on('click', function() {
                        if (selected.size === 0) return;
                        if (!confirm('Delete ' + selected.size + ' photo(s)? This cannot be undone.')) return;

                        var ids = Array.from(selected).map(Number);
                        $(this).prop('disabled', true).text('Deleting...');

                        $.ajax({
                            url: restBase + '/batch-delete',
                            method: 'POST',
                            headers: { 'X-WP-Nonce': restNonce },
                            contentType: 'application/json',
                            data: JSON.stringify({ photo_ids: ids }),
                            success: function(r) {
                                if (r.ok) {
                                    // Remove deleted photos from DOM
                                    ids.forEach(function(id) {
                                        var el = grid.querySelector('[data-id="' + id + '"]');
                                        if (el) el.remove();
                                    });
                                    selected.clear();
                                    var remaining = grid.querySelectorAll('.gm-photo').length;
                                    $('#gm-count').text(remaining);
                                    updateEditUI();
                                }
                            },
                            error: function() { alert('Failed to delete photos.'); },
                            complete: function() { $('#gm-delete-selected').prop('disabled', false).text('Delete Selected'); }
                        });
                    });

                    // Save order
                    $('#gm-save-order').on('click', function() {
                        var photos = grid.querySelectorAll('.gm-photo');
                        var order = Array.from(photos).map(function(el) { return Number(el.dataset.id); });
                        $(this).prop('disabled', true).text('Saving...');

                        $.ajax({
                            url: restBase + '/reorder',
                            method: 'POST',
                            headers: { 'X-WP-Nonce': restNonce },
                            contentType: 'application/json',
                            data: JSON.stringify({ order: order }),
                            success: function(r) {
                                if (r.ok) {
                                    orderChanged = false;
                                    updateEditUI();
                                }
                            },
                            error: function() { alert('Failed to save order.'); },
                            complete: function() { $('#gm-save-order').prop('disabled', false).text('Save Order'); }
                        });
                    });
                })(jQuery);
                </script>

                <!-- Gallery Password -->
                <div style="display:flex; gap:8px; align-items:center; padding-top:12px; border-top:1px solid #F3F4F6;">
                    <span style="font-size:13px; color:#6B7280; white-space:nowrap;">
                        <?php echo $gallery_info['has_password'] ? '&#128274; Password protected' : '&#128275; No password'; ?>
                    </span>
                    <form method="post" style="display:inline-flex; gap:6px; align-items:center; margin-left:auto;">
                        <?php wp_nonce_field( 'tweller_flow_2_gallery_password' ); ?>
                        <input type="hidden" name="tweller_flow_2_gallery_password" value="1">
                        <input type="hidden" name="session_id" value="<?php echo $session->id; ?>">
                        <input type="text" name="gallery_pw" placeholder="Set or change password" style="padding:6px 10px; font-size:12px; border:1px solid #D1D5DB; border-radius:6px; width:140px; font-family:inherit;">
                        <button type="submit" class="tf2-btn tf2-btn--secondary tf2-btn--sm" style="font-size:12px;">Set</button>
                    </form>
                </div>

                <?php if ( $gallery_info['photo_count'] > 0 ) : ?>
                    <div style="margin-top:12px; padding-top:12px; border-top:1px solid #F3F4F6;">
                        <a href="<?php echo esc_url( $tracker_url . '#gallery' ); ?>" target="_blank" class="tf2-btn tf2-btn--secondary tf2-btn--sm" style="font-size:12px;">View Client Gallery</a>
                    </div>

                    <?php if ( class_exists( 'TwellerFlow2_Prints' ) ) {
                        TwellerFlow2_Prints::render_send_to_lab_panel( $session, $gallery_info['photo_count'], 'session' );
                    } ?>
                <?php endif; ?>
            </div>

            <!-- Danger Zone -->
            <div class="tf2-card tf2-card--danger">
                <h2>Danger Zone</h2>
                <p style="font-size:13px; color:#6B7280; margin-bottom:12px;">Permanently delete this session and all its data.</p>
                <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=tweller-flow-2-sessions&action=delete&session_id=' . $session->id ), 'tweller_flow_2_delete_' . $session->id ); ?>"
                   class="tf2-btn tf2-btn--danger tf2-btn--sm"
                   onclick="return confirm('Are you sure you want to delete this session? This cannot be undone.');">
                    Delete Session
                </a>
            </div>
        </div>
    </div>
</div>
