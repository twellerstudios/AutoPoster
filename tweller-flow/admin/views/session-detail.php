<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf-wrap">

    <?php if ( isset( $_GET['created'] ) ) : ?>
        <div class="tf-alert tf-alert--success">Session created! Tracking code: <strong><?php echo esc_html( $session->tracking_code ); ?></strong></div>
    <?php elseif ( isset( $_GET['updated'] ) ) : ?>
        <div class="tf-alert tf-alert--success">Session updated.</div>
    <?php elseif ( isset( $_GET['advanced'] ) ) : ?>
        <div class="tf-alert tf-alert--success">Stage advanced.</div>
    <?php elseif ( isset( $_GET['stage_set'] ) ) : ?>
        <div class="tf-alert tf-alert--success">Stage updated.</div>
    <?php elseif ( isset( $_GET['notified'] ) ) : ?>
        <div class="tf-alert tf-alert--success">Notification sent.</div>
    <?php endif; ?>

    <!-- Header -->
    <div class="tf-detail-header">
        <div class="tf-detail-header__left">
            <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-sessions' ); ?>" class="tf-btn tf-btn--ghost tf-btn--sm" style="margin-bottom:8px;">&larr; Back to Sessions</a>
            <h1><?php echo esc_html( $session->client_name ); ?></h1>
            <div class="tf-detail-header__code">
                Tracking Code: <span><?php echo esc_html( $session->tracking_code ); ?></span>
                <button class="tf-copy-btn" onclick="navigator.clipboard.writeText('<?php echo esc_attr( $session->tracking_code ); ?>'); this.textContent='Copied!';">Copy</button>
            </div>
        </div>
        <div class="tf-btn-group">
            <?php if ( $wa_link ) : ?>
                <a href="<?php echo esc_url( $wa_link ); ?>" target="_blank" class="tf-btn tf-btn--whatsapp tf-btn--sm">WhatsApp</a>
            <?php endif; ?>
            <a href="<?php echo esc_url( $tracker_url ); ?>" target="_blank" class="tf-btn tf-btn--secondary tf-btn--sm">Client View</a>
        </div>
    </div>

    <!-- Pipeline Progress -->
    <div class="tf-card tf-mb-6">
        <h2>Pipeline</h2>
        <div class="tf-pipeline">
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
                        'send'         => '&#10148;',
                    );
                    $icon_char = $icon_map[ $stage_info['icon'] ?? '' ] ?? '';
                ?>
                <div class="tf-pipeline__step">
                    <div class="tf-pipeline__node">
                        <div class="tf-pipeline__dot tf-pipeline__dot--<?php echo $dot_class; ?>">
                            <?php if ( $is_completed ) : ?>&#10003;<?php elseif ( $icon_char ) : echo $icon_char; endif; ?>
                        </div>
                        <div class="tf-pipeline__name"><?php echo esc_html( $stage_info['label'] ); ?></div>
                    </div>
                    <?php if ( $idx < count( $stage_keys ) - 1 ) : ?>
                        <div class="tf-pipeline__line tf-pipeline__line--<?php echo $line_class; ?>"></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Stage Controls -->
        <div class="tf-pipeline-controls">
            <div class="tf-pipeline-controls__title">Stage Controls</div>
            <div class="tf-pipeline-controls__actions">
                <!-- Advance to Next -->
                <?php if ( $current_idx < count( $stage_keys ) - 1 ) :
                    $next_stage = $stages[ $stage_keys[ $current_idx + 1 ] ]['label'];
                ?>
                    <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=tweller-flow-session&action=advance&session_id=' . $session->id ), 'tweller_flow_advance_' . $session->id ); ?>"
                       class="tf-btn tf-btn--primary"
                       style="background:#3B82F6; color:#FFFFFF; border-color:#3B82F6;"
                       onclick="return confirm('Advance to <?php echo esc_attr( $next_stage ); ?>?');">
                        Advance to <?php echo esc_html( $next_stage ); ?> &rarr;
                    </a>
                <?php endif; ?>

                <!-- Jump to Specific Stage -->
                <form method="post" style="display:inline-flex; gap:6px; align-items:center;">
                    <?php wp_nonce_field( 'tweller_flow_set_stage' ); ?>
                    <input type="hidden" name="tweller_flow_set_stage" value="1">
                    <input type="hidden" name="session_id" value="<?php echo $session->id; ?>">
                    <select name="stage" class="tf-filters__select" style="font-size:13px; padding:7px 32px 7px 10px;">
                        <?php foreach ( $stage_keys as $key ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $session->current_stage, $key ); ?>><?php echo esc_html( $stages[ $key ]['label'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="stage_notes" placeholder="Note (optional)" style="padding:7px 10px; font-size:13px; border:1px solid #D1D5DB; border-radius:6px; width:160px; font-family:inherit;">
                    <button type="submit" class="tf-btn tf-btn--secondary tf-btn--sm">Set Stage</button>
                </form>
            </div>
        </div>
    </div>

    <div class="tf-grid tf-grid--sidebar">
        <!-- Left Column -->
        <div>
            <!-- Client Details (Editable) -->
            <div class="tf-card tf-mb-6">
                <div class="tf-tabs" id="detail-tabs">
                    <button class="tf-tab tf-tab--active" data-tab="info">Details</button>
                    <button class="tf-tab" data-tab="edit">Edit</button>
                    <button class="tf-tab" data-tab="notify">Notify</button>
                </div>

                <!-- Info Tab -->
                <div class="tf-tab-content tf-tab-content--active" id="tab-info">
                    <div class="tf-client-info">
                        <div class="tf-client-info__item">
                            <span class="tf-client-info__label">Name</span>
                            <span class="tf-client-info__value"><?php echo esc_html( $session->client_name ); ?></span>
                        </div>
                        <div class="tf-client-info__item">
                            <span class="tf-client-info__label">Email</span>
                            <span class="tf-client-info__value"><?php echo esc_html( $session->client_email ?: '—' ); ?></span>
                        </div>
                        <div class="tf-client-info__item">
                            <span class="tf-client-info__label">Phone</span>
                            <span class="tf-client-info__value"><?php echo esc_html( $session->client_phone ?: '—' ); ?></span>
                        </div>
                        <div class="tf-client-info__item">
                            <span class="tf-client-info__label">Package</span>
                            <span class="tf-client-info__value"><?php
                                $pkg = $packages[ $session->package_type ] ?? array();
                                echo esc_html( $pkg['name'] ?? ucfirst( $session->package_type ) );
                            ?></span>
                        </div>
                        <div class="tf-client-info__item">
                            <span class="tf-client-info__label">Session Date</span>
                            <span class="tf-client-info__value"><?php echo $session->session_date ? date( 'F j, Y', strtotime( $session->session_date ) ) : '—'; ?></span>
                        </div>
                        <div class="tf-client-info__item">
                            <span class="tf-client-info__label">Session Time</span>
                            <span class="tf-client-info__value"><?php echo $session->session_time ? date( 'g:i A', strtotime( $session->session_time ) ) : '—'; ?></span>
                        </div>
                        <div class="tf-client-info__item">
                            <span class="tf-client-info__label">Location</span>
                            <span class="tf-client-info__value"><?php echo esc_html( $session->location ?: '—' ); ?></span>
                        </div>
                        <div class="tf-client-info__item">
                            <span class="tf-client-info__label">Members</span>
                            <span class="tf-client-info__value"><?php echo $session->members_count; ?></span>
                        </div>
                        <div class="tf-client-info__item">
                            <span class="tf-client-info__label">Payment</span>
                            <span class="tf-client-info__value">
                                <span class="tf-badge tf-badge--<?php echo esc_attr( $session->payment_status ); ?>"><?php echo ucfirst( $session->payment_status ); ?></span>
                            </span>
                        </div>
                        <div class="tf-client-info__item">
                            <span class="tf-client-info__label">Total</span>
                            <span class="tf-client-info__value">$<?php echo number_format( $session->total_amount, 2 ); ?></span>
                        </div>
                        <div class="tf-client-info__item">
                            <span class="tf-client-info__label">Deposit</span>
                            <span class="tf-client-info__value">$<?php echo number_format( $session->deposit_amount, 2 ); ?></span>
                        </div>
                        <div class="tf-client-info__item">
                            <span class="tf-client-info__label">Est. Delivery</span>
                            <span class="tf-client-info__value"><?php echo $session->estimated_delivery ? date( 'F j, Y', strtotime( $session->estimated_delivery ) ) : '—'; ?></span>
                        </div>
                        <?php if ( $session->gallery_url ) : ?>
                        <div class="tf-client-info__item">
                            <span class="tf-client-info__label">Gallery URL</span>
                            <span class="tf-client-info__value"><a href="<?php echo esc_url( $session->gallery_url ); ?>" target="_blank"><?php echo esc_html( $session->gallery_url ); ?></a></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ( $session->notes ) : ?>
                        <hr class="tf-separator">
                        <div class="tf-section-title">Notes</div>
                        <p style="font-size:14px; color:#374151; white-space:pre-wrap;"><?php echo esc_html( $session->notes ); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Edit Tab -->
                <div class="tf-tab-content" id="tab-edit">
                    <form method="post" class="tf-form">
                        <?php wp_nonce_field( 'tweller_flow_update_session' ); ?>
                        <input type="hidden" name="tweller_flow_update_session" value="1">
                        <input type="hidden" name="session_id" value="<?php echo $session->id; ?>">

                        <div class="tf-row">
                            <div class="tf-field">
                                <label class="tf-field__label">Client Name</label>
                                <input type="text" name="client_name" value="<?php echo esc_attr( $session->client_name ); ?>" required>
                            </div>
                            <div class="tf-field">
                                <label class="tf-field__label">Email</label>
                                <input type="email" name="client_email" value="<?php echo esc_attr( $session->client_email ); ?>">
                            </div>
                        </div>
                        <div class="tf-row">
                            <div class="tf-field">
                                <label class="tf-field__label">Phone</label>
                                <input type="tel" name="client_phone" value="<?php echo esc_attr( $session->client_phone ); ?>">
                            </div>
                            <div class="tf-field">
                                <label class="tf-field__label">Members</label>
                                <input type="number" name="members_count" value="<?php echo $session->members_count; ?>" min="1">
                            </div>
                        </div>
                        <div class="tf-row--3">
                            <div class="tf-field">
                                <label class="tf-field__label">Package</label>
                                <select name="package_type">
                                    <?php foreach ( $packages as $key => $pkg ) : ?>
                                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $session->package_type, $key ); ?>><?php echo esc_html( $pkg['name'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="tf-field">
                                <label class="tf-field__label">Session Date</label>
                                <input type="date" name="session_date" value="<?php echo esc_attr( $session->session_date ); ?>">
                            </div>
                            <div class="tf-field">
                                <label class="tf-field__label">Session Time</label>
                                <input type="time" name="session_time" value="<?php echo esc_attr( $session->session_time ); ?>">
                            </div>
                        </div>
                        <div class="tf-field">
                            <label class="tf-field__label">Location</label>
                            <input type="text" name="location" value="<?php echo esc_attr( $session->location ); ?>">
                        </div>
                        <div class="tf-row--3">
                            <div class="tf-field">
                                <label class="tf-field__label">Payment Status</label>
                                <select name="payment_status">
                                    <option value="pending" <?php selected( $session->payment_status, 'pending' ); ?>>Pending</option>
                                    <option value="deposit" <?php selected( $session->payment_status, 'deposit' ); ?>>Deposit Paid</option>
                                    <option value="paid" <?php selected( $session->payment_status, 'paid' ); ?>>Fully Paid</option>
                                </select>
                            </div>
                            <div class="tf-field">
                                <label class="tf-field__label">Deposit</label>
                                <input type="number" name="deposit_amount" value="<?php echo $session->deposit_amount; ?>" step="0.01">
                            </div>
                            <div class="tf-field">
                                <label class="tf-field__label">Total</label>
                                <input type="number" name="total_amount" value="<?php echo $session->total_amount; ?>" step="0.01">
                            </div>
                        </div>
                        <div class="tf-field">
                            <label class="tf-field__label">Payment Method</label>
                            <select name="payment_method">
                                <option value="" <?php selected( $session->payment_method, '' ); ?>>Not specified</option>
                                <option value="cash" <?php selected( $session->payment_method, 'cash' ); ?>>Cash</option>
                                <option value="transfer" <?php selected( $session->payment_method, 'transfer' ); ?>>Bank Transfer</option>
                                <option value="surecart" <?php selected( $session->payment_method, 'surecart' ); ?>>SureCart</option>
                                <option value="other" <?php selected( $session->payment_method, 'other' ); ?>>Other</option>
                            </select>
                        </div>
                        <div class="tf-row">
                            <div class="tf-field">
                                <label class="tf-field__label">Gallery URL</label>
                                <input type="url" name="gallery_url" value="<?php echo esc_attr( $session->gallery_url ); ?>" placeholder="https://...">
                            </div>
                            <div class="tf-field">
                                <label class="tf-field__label">Est. Delivery</label>
                                <input type="date" name="estimated_delivery" value="<?php echo esc_attr( $session->estimated_delivery ); ?>">
                            </div>
                        </div>
                        <div class="tf-field">
                            <label class="tf-field__label">Notes</label>
                            <textarea name="notes"><?php echo esc_textarea( $session->notes ); ?></textarea>
                        </div>
                        <button type="submit" class="tf-btn tf-btn--primary">Save Changes</button>
                    </form>
                </div>

                <!-- Notify Tab -->
                <div class="tf-tab-content" id="tab-notify">
                    <form method="post" class="tf-form">
                        <?php wp_nonce_field( 'tweller_flow_send_notification' ); ?>
                        <input type="hidden" name="tweller_flow_send_notification" value="1">
                        <input type="hidden" name="session_id" value="<?php echo $session->id; ?>">
                        <div class="tf-field">
                            <label class="tf-field__label">To</label>
                            <input type="text" value="<?php echo esc_attr( $session->client_email ); ?>" disabled style="background:#F9FAFB;">
                        </div>
                        <div class="tf-field">
                            <label class="tf-field__label">Subject</label>
                            <input type="text" name="notif_subject" value="Update on your photo session — Tweller Studios">
                        </div>
                        <div class="tf-field">
                            <label class="tf-field__label">Message</label>
                            <textarea name="notif_body" rows="6"><p>Hi <?php echo esc_html( $session->client_name ); ?>,</p>
<p>Here's an update on your photo session.</p>
<p>Track your progress: <a href="<?php echo esc_url( $tracker_url ); ?>">View Tracker</a></p></textarea>
                        </div>
                        <button type="submit" class="tf-btn tf-btn--primary" <?php echo empty( $session->client_email ) ? 'disabled title="No email address"' : ''; ?>>Send Email</button>
                    </form>

                    <?php if ( ! empty( $notifications ) ) : ?>
                        <hr class="tf-separator">
                        <div class="tf-section-title">Sent Notifications</div>
                        <?php foreach ( array_slice( $notifications, 0, 5 ) as $n ) : ?>
                            <div style="padding:8px 0; border-bottom:1px solid #F3F4F6; font-size:13px;">
                                <span class="tf-notif-status--<?php echo esc_attr( $n->status ); ?>"><?php echo ucfirst( $n->status ); ?></span>
                                <?php echo esc_html( $n->subject ); ?>
                                <span class="tf-muted"> — <?php echo date( 'M j, g:i A', strtotime( $n->sent_at ) ); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column -->
        <div>
            <!-- Stage History -->
            <div class="tf-card tf-mb-6">
                <h2>History</h2>
                <?php if ( empty( $history ) ) : ?>
                    <p class="tf-muted" style="font-size:13px;">No history yet.</p>
                <?php else : ?>
                    <div class="tf-timeline">
                        <?php foreach ( array_reverse( $history ) as $entry ) :
                            $stage_label = $stages[ $entry->stage ]['label'] ?? $entry->stage;
                        ?>
                            <div class="tf-timeline__item">
                                <div class="tf-timeline__dot"></div>
                                <div class="tf-timeline__stage"><?php echo esc_html( $stage_label ); ?></div>
                                <div class="tf-timeline__time"><?php echo date( 'M j, Y — g:i A', strtotime( $entry->timestamp ) ); ?></div>
                                <?php if ( $entry->notes ) : ?>
                                    <div class="tf-timeline__notes"><?php echo esc_html( $entry->notes ); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Danger Zone -->
            <div class="tf-card tf-card--danger">
                <h2>Danger Zone</h2>
                <p style="font-size:13px; color:#6B7280; margin-bottom:12px;">Permanently delete this session and all its data.</p>
                <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=tweller-flow-sessions&action=delete&session_id=' . $session->id ), 'tweller_flow_delete_' . $session->id ); ?>"
                   class="tf-btn tf-btn--danger tf-btn--sm"
                   onclick="return confirm('Are you sure you want to delete this session? This cannot be undone.');">
                    Delete Session
                </a>
            </div>
        </div>
    </div>
</div>
