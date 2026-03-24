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
                        'package'      => '&#128230;',
                        'upload'       => '&#11014;',
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

                <?php if ( $session->current_stage === 'deliver' ) : ?>
                    <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=tweller-flow-session&action=send_delivery&session_id=' . $session->id ), 'tweller_flow_deliver_' . $session->id ); ?>"
                       class="tf-btn tf-btn--primary"
                       style="background:#10B981; color:#FFFFFF; border-color:#10B981;"
                       onclick="return confirm('Send gallery delivery email to <?php echo esc_attr( $session->client_name ); ?> (<?php echo esc_attr( $session->client_email ); ?>)?');">
                        &#9993; Send Delivery Email
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

            <!-- Gallery Management -->
            <?php
            $gallery_info = TwellerFlow_Gallery::get_gallery_info( $session->id, $session->tracking_code );
            $gallery_url  = TwellerFlow_Gallery::get_gallery_url( $session->tracking_code );
            ?>
            <div class="tf-card tf-mb-6" id="tf-gallery-manager">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <h2 style="margin:0;">Gallery (<span id="gm-count"><?php echo $gallery_info['photo_count']; ?></span> photos<?php echo $gallery_info['total_size_mb'] ? ' / ' . $gallery_info['total_size_mb'] . ' MB' : ''; ?>)</h2>
                    <?php if ( $gallery_info['photo_count'] > 0 ) : ?>
                        <button id="gm-edit-btn" class="tf-btn tf-btn--secondary tf-btn--sm" style="font-size:12px;" title="Edit gallery">&#9998; Edit</button>
                    <?php endif; ?>
                </div>

                <!-- Edit mode toolbar (hidden by default) -->
                <div id="gm-toolbar" style="display:none; padding:10px 14px; background:#FEF3C7; border:1px solid #FCD34D; border-radius:8px; margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                        <div style="display:flex; gap:8px; align-items:center;">
                            <span id="gm-selected-count" style="font-size:12px; color:#92400E; font-weight:600;">0 selected</span>
                            <button id="gm-select-all" class="tf-btn tf-btn--secondary tf-btn--sm" style="font-size:11px;">Select All</button>
                            <button id="gm-delete-selected" class="tf-btn tf-btn--sm" style="font-size:11px; background:#DC2626; color:#fff; border-color:#DC2626;" disabled>Delete Selected</button>
                        </div>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <button id="gm-save-order" class="tf-btn tf-btn--sm" style="font-size:11px; background:#6366F1; color:#fff; border-color:#6366F1; display:none;">Save Order</button>
                            <button id="gm-done-btn" class="tf-btn tf-btn--secondary tf-btn--sm" style="font-size:11px;">Done</button>
                        </div>
                    </div>
                    <p style="margin:6px 0 0; font-size:11px; color:#92400E;">Click photos to select. Drag to reorder.</p>
                </div>

                <?php if ( $gallery_info['photo_count'] > 0 ) : ?>
                    <div id="gm-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(90px,1fr)); gap:6px; margin-bottom:16px;">
                        <?php foreach ( $gallery_info['photos'] as $photo ) : ?>
                            <div class="gm-photo" data-id="<?php echo $photo->id; ?>" style="position:relative; border-radius:6px; overflow:hidden; aspect-ratio:1; background:#F3F4F6; cursor:default; border:2px solid transparent; transition:border-color .15s;">
                                <img src="<?php echo esc_url( $gallery_url . '/thumbs/' . $photo->filename ); ?>" alt="<?php echo esc_attr( $photo->filename ); ?>" style="width:100%; height:100%; object-fit:cover; pointer-events:none;">
                                <div class="gm-check" style="display:none; position:absolute; top:4px; left:4px; width:20px; height:20px; background:#3B82F6; border-radius:50%; color:#fff; font-size:12px; line-height:20px; text-align:center;">&#10003;</div>
                                <div class="gm-order-badge" style="display:none; position:absolute; bottom:4px; right:4px; min-width:20px; height:20px; background:rgba(0,0,0,0.6); border-radius:10px; color:#fff; font-size:10px; line-height:20px; text-align:center; padding:0 5px;"></div>
                            </div>
                        <?php endforeach; ?>
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
                    var restBase = '<?php echo esc_js( rest_url( 'tweller-flow/v1/gallery/' . $session->tracking_code ) ); ?>';
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
                            fd.append('action', 'tweller_flow_admin_upload');
                            fd.append('nonce', twellerFlow.nonce);
                            fd.append('session_code', SESSION_CODE);
                            fd.append('photos[]', files[i]);

                            $.ajax({
                                url: twellerFlow.ajaxUrl, method: 'POST',
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
                        <?php wp_nonce_field( 'tweller_flow_gallery_password' ); ?>
                        <input type="hidden" name="tweller_flow_gallery_password" value="1">
                        <input type="hidden" name="session_id" value="<?php echo $session->id; ?>">
                        <input type="text" name="gallery_pw" placeholder="Set or change password" style="padding:6px 10px; font-size:12px; border:1px solid #D1D5DB; border-radius:6px; width:140px; font-family:inherit;">
                        <button type="submit" class="tf-btn tf-btn--secondary tf-btn--sm" style="font-size:12px;">Set</button>
                    </form>
                </div>

                <?php if ( $gallery_info['photo_count'] > 0 ) : ?>
                    <div style="margin-top:12px; padding-top:12px; border-top:1px solid #F3F4F6;">
                        <a href="<?php echo esc_url( $tracker_url . '#gallery' ); ?>" target="_blank" class="tf-btn tf-btn--secondary tf-btn--sm" style="font-size:12px;">View Client Gallery</a>
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
