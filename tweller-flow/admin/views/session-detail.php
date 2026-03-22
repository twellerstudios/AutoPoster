<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf-admin">
    <h1>
        <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-sessions' ); ?>">&larr; Sessions</a>
        &nbsp;/&nbsp;<?php echo esc_html( $session->client_name ); ?>
        <code style="font-size: 14px; margin-left: 10px;"><?php echo esc_html( $session->tracking_code ); ?></code>
    </h1>

    <?php if ( isset( $_GET['created'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Session created! Tracking code: <strong><?php echo esc_html( $session->tracking_code ); ?></strong></p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['updated'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Session updated.</p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['advanced'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Stage advanced!</p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['notified'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Notification sent!</p></div>
    <?php endif; ?>

    <!-- Stage Pipeline -->
    <div class="tf-card">
        <h2>Session Pipeline</h2>
        <div class="tf-admin-pipeline">
            <?php foreach ( $stages as $key => $stage_info ) :
                $idx = array_search( $key, $stage_keys );
                $is_completed = $idx < $session->current_stage_index;
                $is_current = $key === $session->current_stage;
                $status = $is_completed ? 'completed' : ( $is_current ? 'current' : 'upcoming' );
            ?>
                <div class="tf-admin-pipeline__stage tf-admin-pipeline__stage--<?php echo $status; ?>" title="<?php echo esc_attr( $stage_info['label'] ); ?>">
                    <div class="tf-admin-pipeline__dot"></div>
                    <div class="tf-admin-pipeline__label"><?php echo esc_html( $stage_info['label'] ); ?></div>
                    <?php if ( $stage_info['notify'] ) : ?>
                        <span class="tf-admin-pipeline__notify" title="Client is notified at this stage">✉</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="tf-stage-actions">
            <?php if ( $session->current_stage !== 'delivered' ) : ?>
                <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=tweller-flow-sessions&action=advance&session_id=' . $session->id ), 'tweller_flow_advance_' . $session->id ); ?>"
                   class="button button-primary button-large"
                   onclick="return confirm('Advance to next stage?');">
                    ▶ Advance to Next Stage
                </a>
            <?php else : ?>
                <span class="tf-badge tf-badge--delivered" style="font-size:16px; padding: 8px 16px;">✓ Delivered</span>
            <?php endif; ?>

            <form method="post" style="display:inline-block; margin-left: 10px;">
                <?php wp_nonce_field( 'tweller_flow_set_stage' ); ?>
                <input type="hidden" name="tweller_flow_set_stage" value="1">
                <input type="hidden" name="session_id" value="<?php echo $session->id; ?>">
                <select name="stage" class="tf-select">
                    <?php foreach ( $stages as $key => $stage_info ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $session->current_stage, $key ); ?>><?php echo esc_html( $stage_info['label'] ); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="stage_notes" placeholder="Notes (optional)" class="regular-text" style="width:200px;">
                <button type="submit" class="button" onclick="return confirm('Set stage manually?');">Set Stage</button>
            </form>
        </div>
    </div>

    <div class="tf-dashboard-grid">
        <!-- Session Details (editable) -->
        <div class="tf-card">
            <h2>Session Details</h2>
            <form method="post">
                <?php wp_nonce_field( 'tweller_flow_update_session' ); ?>
                <input type="hidden" name="tweller_flow_update_session" value="1">
                <input type="hidden" name="session_id" value="<?php echo $session->id; ?>">

                <table class="form-table tf-form-table">
                    <tr>
                        <th>Client Name</th>
                        <td><input type="text" name="client_name" value="<?php echo esc_attr( $session->client_name ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Email</th>
                        <td><input type="email" name="client_email" value="<?php echo esc_attr( $session->client_email ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Phone</th>
                        <td>
                            <input type="text" name="client_phone" value="<?php echo esc_attr( $session->client_phone ); ?>" class="regular-text">
                            <?php if ( $session->client_phone ) : ?>
                                <a href="<?php echo esc_url( $wa_link ); ?>" target="_blank" class="button button-small" style="margin-left:5px;" title="Send WhatsApp Message">💬 WhatsApp</a>
                                <a href="tel:<?php echo esc_attr( $session->client_phone ); ?>" class="button button-small">📞 Call</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Package</th>
                        <td>
                            <select name="package_type" class="tf-select">
                                <?php foreach ( $packages as $key => $pkg ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $session->package_type, $key ); ?>><?php echo esc_html( $pkg['name'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Session Date</th>
                        <td><input type="date" name="session_date" value="<?php echo esc_attr( $session->session_date ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Session Time</th>
                        <td><input type="time" name="session_time" value="<?php echo esc_attr( $session->session_time ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Location</th>
                        <td><input type="text" name="location" value="<?php echo esc_attr( $session->location ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Members</th>
                        <td><input type="number" name="members_count" value="<?php echo esc_attr( $session->members_count ); ?>" class="small-text" min="1"></td>
                    </tr>
                    <tr>
                        <th>Total Amount</th>
                        <td><input type="number" name="total_amount" value="<?php echo esc_attr( $session->total_amount ); ?>" class="small-text" step="0.01"></td>
                    </tr>
                    <tr>
                        <th>Deposit Amount</th>
                        <td><input type="number" name="deposit_amount" value="<?php echo esc_attr( $session->deposit_amount ); ?>" class="small-text" step="0.01"></td>
                    </tr>
                    <tr>
                        <th>Payment Status</th>
                        <td>
                            <select name="payment_status" class="tf-select">
                                <option value="pending" <?php selected( $session->payment_status, 'pending' ); ?>>Pending</option>
                                <option value="deposit" <?php selected( $session->payment_status, 'deposit' ); ?>>Deposit Received</option>
                                <option value="paid" <?php selected( $session->payment_status, 'paid' ); ?>>Paid in Full</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Payment Method</th>
                        <td>
                            <select name="payment_method" class="tf-select">
                                <option value="" <?php selected( $session->payment_method, '' ); ?>>—</option>
                                <option value="bank_transfer" <?php selected( $session->payment_method, 'bank_transfer' ); ?>>Bank Transfer</option>
                                <option value="cash" <?php selected( $session->payment_method, 'cash' ); ?>>Cash</option>
                                <option value="wipay" <?php selected( $session->payment_method, 'wipay' ); ?>>WiPay</option>
                                <option value="surecart" <?php selected( $session->payment_method, 'surecart' ); ?>>SureCart</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Est. Delivery</th>
                        <td><input type="date" name="estimated_delivery" value="<?php echo esc_attr( $session->estimated_delivery ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Gallery URL</th>
                        <td><input type="url" name="gallery_url" value="<?php echo esc_attr( $session->gallery_url ); ?>" class="large-text" placeholder="https://..."></td>
                    </tr>
                    <tr>
                        <th>Notes</th>
                        <td><textarea name="notes" rows="4" class="large-text"><?php echo esc_textarea( $session->notes ); ?></textarea></td>
                    </tr>
                </table>
                <p><input type="submit" class="button button-primary" value="Save Changes"></p>
            </form>
        </div>

        <!-- Right sidebar -->
        <div>
            <!-- Quick Links -->
            <div class="tf-card">
                <h2>Quick Links</h2>
                <div class="tf-quick-links">
                    <a href="<?php echo esc_url( $tracker_url ); ?>" target="_blank" class="button button-large" style="width:100%; text-align:center; margin-bottom:8px;">🔗 View Client Tracker</a>
                    <?php if ( $session->client_phone ) : ?>
                        <a href="<?php echo esc_url( $wa_link ); ?>" target="_blank" class="button button-large" style="width:100%; text-align:center; margin-bottom:8px;">💬 WhatsApp Client</a>
                    <?php endif; ?>
                    <?php if ( $session->gallery_url ) : ?>
                        <a href="<?php echo esc_url( $session->gallery_url ); ?>" target="_blank" class="button button-large" style="width:100%; text-align:center; margin-bottom:8px;">📷 View Gallery</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Send Notification -->
            <div class="tf-card">
                <h2>Send Notification</h2>
                <form method="post">
                    <?php wp_nonce_field( 'tweller_flow_send_notification' ); ?>
                    <input type="hidden" name="tweller_flow_send_notification" value="1">
                    <input type="hidden" name="session_id" value="<?php echo $session->id; ?>">
                    <p><input type="text" name="notif_subject" class="large-text" placeholder="Email subject" required></p>
                    <p><textarea name="notif_body" rows="5" class="large-text" placeholder="Email body (HTML supported)" required></textarea></p>
                    <p><button type="submit" class="button" onclick="return confirm('Send email to <?php echo esc_attr( $session->client_email ); ?>?');">Send Email</button></p>
                </form>
            </div>

            <!-- Stage History -->
            <div class="tf-card">
                <h2>Stage History</h2>
                <?php if ( empty( $history ) ) : ?>
                    <p class="tf-empty">No history yet.</p>
                <?php else : ?>
                    <div class="tf-history">
                        <?php foreach ( array_reverse( $history ) as $entry ) :
                            $stage_info = $stages[ $entry->stage ] ?? array( 'label' => $entry->stage );
                        ?>
                            <div class="tf-history__item">
                                <div class="tf-history__dot"></div>
                                <div class="tf-history__content">
                                    <strong><?php echo esc_html( $stage_info['label'] ); ?></strong>
                                    <br><small><?php echo date( 'M j, Y g:i A', strtotime( $entry->timestamp ) ); ?></small>
                                    <?php if ( $entry->notes ) : ?>
                                        <br><small class="tf-muted"><?php echo esc_html( $entry->notes ); ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Notification Log -->
            <div class="tf-card">
                <h2>Notification Log</h2>
                <?php if ( empty( $notifications ) ) : ?>
                    <p class="tf-empty">No notifications sent yet.</p>
                <?php else : ?>
                    <?php foreach ( $notifications as $notif ) : ?>
                        <div class="tf-notif-item">
                            <span class="tf-notif-status tf-notif-status--<?php echo esc_attr( $notif->status ); ?>"><?php echo $notif->status === 'sent' ? '✓' : '✗'; ?></span>
                            <strong><?php echo esc_html( $notif->subject ); ?></strong>
                            <br><small><?php echo date( 'M j, g:i A', strtotime( $notif->sent_at ) ); ?> — <?php echo esc_html( $notif->recipient ); ?></small>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Danger Zone -->
            <div class="tf-card tf-card--danger">
                <h2>Danger Zone</h2>
                <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=tweller-flow-sessions&action=delete&session_id=' . $session->id ), 'tweller_flow_delete_' . $session->id ); ?>"
                   class="button button-link-delete"
                   onclick="return confirm('Are you sure you want to delete this session? This cannot be undone.');">
                    Delete This Session
                </a>
            </div>
        </div>
    </div>
</div>
