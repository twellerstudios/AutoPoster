<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf2-wrap">
    <h1>Settings</h1>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Settings saved.</div>
    <?php endif; ?>

    <form method="post">
        <?php wp_nonce_field( 'tweller_flow_2_save_settings' ); ?>
        <input type="hidden" name="tweller_flow_2_save_settings" value="1">

        <div class="tf2-card tf2-mb-6">
            <!-- Folder Watcher -->
            <div class="tf2-settings-section">
                <h3>Folder Watcher</h3>
                <p class="tf2-description">Configure the local agent that watches your photo folders and updates session stages automatically.</p>

                <div class="tf2-field" style="max-width:480px;">
                    <label class="tf2-toggle">
                        <input type="checkbox" name="automation_enabled" value="1" <?php checked( ! empty( $automation['enabled'] ) ); ?>>
                        <span class="tf2-toggle__switch"></span>
                        <span class="tf2-toggle__label">Enable folder watcher integration</span>
                    </label>
                </div>

                <div class="tf2-row" style="max-width:640px;">
                    <div class="tf2-field">
                        <label class="tf2-field__label">Backend Agent URL</label>
                        <input type="url" name="automation_backend_url" value="<?php echo esc_attr( $automation['backend_url'] ?? 'http://localhost:3001' ); ?>" placeholder="http://localhost:3001">
                        <div class="tf2-field__hint">URL where the local Node.js agent is running.</div>
                    </div>
                    <div class="tf2-field">
                        <label class="tf2-field__label">API Key</label>
                        <input type="password" name="automation_api_key" value="<?php echo esc_attr( $automation['api_key'] ?? '' ); ?>" placeholder="Leave empty for localhost-only">
                        <div class="tf2-field__hint">Shared secret between WordPress and the local agent.</div>
                    </div>
                </div>

                <div class="tf2-field" style="max-width:640px;">
                    <label class="tf2-field__label">Watch Directory Path</label>
                    <input type="text" name="automation_watch_dir" value="<?php echo esc_attr( $automation['watch_dir'] ?? '' ); ?>" placeholder="D:\Photos\Sessions">
                    <div class="tf2-field__hint">The main folder on your local machine that the agent watches for new session folders.</div>
                </div>

                <div class="tf2-field" style="max-width:640px;">
                    <label class="tf2-field__label">Gallery Base URL</label>
                    <input type="url" name="automation_gallery_base_url" value="<?php echo esc_attr( $automation['gallery_base_url'] ?? '' ); ?>" placeholder="https://gallery.twellerstudios.com">
                    <div class="tf2-field__hint">Base URL for client gallery links.</div>
                </div>
            </div>

            <!-- Delivery -->
            <div class="tf2-settings-section">
                <h3>Delivery</h3>
                <p class="tf2-description">Default delivery timeline for new sessions.</p>

                <div class="tf2-field" style="max-width:200px;">
                    <label class="tf2-field__label">Delivery Days</label>
                    <input type="number" name="delivery_days" value="<?php echo esc_attr( $delivery ); ?>" min="1" max="90">
                    <div class="tf2-field__hint">Days after session date.</div>
                </div>
            </div>

            <!-- Email / SMTP -->
            <div class="tf2-settings-section">
                <h3>Email (SMTP)</h3>
                <p class="tf2-description">SMTP settings for sending email notifications to clients.</p>

                <div class="tf2-row" style="max-width:640px;">
                    <div class="tf2-field">
                        <label class="tf2-field__label">SMTP Host</label>
                        <input type="text" name="smtp_host" value="<?php echo esc_attr( $smtp['host'] ?? '' ); ?>" placeholder="smtp.zoho.com">
                    </div>
                    <div class="tf2-field">
                        <label class="tf2-field__label">Port</label>
                        <input type="number" name="smtp_port" value="<?php echo esc_attr( $smtp['port'] ?? 465 ); ?>">
                    </div>
                </div>

                <div class="tf2-field" style="max-width:300px;">
                    <label class="tf2-field__label">Encryption</label>
                    <select name="smtp_encryption">
                        <option value="ssl" <?php selected( $smtp['encryption'] ?? '', 'ssl' ); ?>>SSL</option>
                        <option value="tls" <?php selected( $smtp['encryption'] ?? '', 'tls' ); ?>>TLS</option>
                        <option value="" <?php selected( $smtp['encryption'] ?? '', '' ); ?>>None</option>
                    </select>
                </div>

                <div class="tf2-row" style="max-width:640px;">
                    <div class="tf2-field">
                        <label class="tf2-field__label">Username</label>
                        <input type="text" name="smtp_username" value="<?php echo esc_attr( $smtp['username'] ?? '' ); ?>">
                    </div>
                    <div class="tf2-field">
                        <label class="tf2-field__label">Password</label>
                        <input type="password" name="smtp_password" value="<?php echo esc_attr( $smtp['password'] ?? '' ); ?>">
                    </div>
                </div>

                <div class="tf2-row" style="max-width:640px;">
                    <div class="tf2-field">
                        <label class="tf2-field__label">From Name</label>
                        <input type="text" name="smtp_from_name" value="<?php echo esc_attr( $smtp['from_name'] ?? 'Tweller Studios' ); ?>">
                    </div>
                    <div class="tf2-field">
                        <label class="tf2-field__label">From Email</label>
                        <input type="email" name="smtp_from_email" value="<?php echo esc_attr( $smtp['from_email'] ?? '' ); ?>">
                    </div>
                </div>
            </div>

            <!-- Banking & Payments -->
            <div class="tf2-settings-section">
                <h3>Banking &amp; Payment Details</h3>
                <p class="tf2-description">Payment instructions included in confirmation emails when clients owe an additional balance.</p>

                <div class="tf2-field" style="max-width:640px;">
                    <label class="tf2-field__label">Bank Transfer Details</label>
                    <textarea name="banking_info" rows="5" placeholder="Bank name, account name, account number, routing info..."><?php echo esc_textarea( $banking ); ?></textarea>
                    <div class="tf2-field__hint">Shown in selection confirmation emails when client selects extra photos.</div>
                </div>

                <div class="tf2-field" style="max-width:640px;">
                    <label class="tf2-field__label">WiPay Payment URL</label>
                    <input type="url" name="wipay_url" value="<?php echo esc_attr( get_option( 'tweller_flow_2_wipay_url', '' ) ); ?>" placeholder="https://wipay.tt/pay/...">
                    <div class="tf2-field__hint">Your WiPay payment link. Shown as a "Pay via WiPay" button in selection emails for credit/debit card payments.</div>
                </div>

                <div class="tf2-field" style="max-width:200px;">
                    <label class="tf2-field__label">Price Per Extra Photo (TTD)</label>
                    <input type="number" name="culling_price_per_photo" value="<?php echo esc_attr( get_option( 'tweller_flow_2_culling_price_per_photo', 30 ) ); ?>" min="1" step="1" placeholder="30">
                    <div class="tf2-field__hint">Charge per additional photo beyond the included package amount.</div>
                </div>

                <div class="tf2-field" style="max-width:640px;">
                    <label class="tf2-field__label">Leave a Review URL</label>
                    <input type="url" name="review_url" value="<?php echo esc_attr( get_option( 'tweller_flow_2_review_url', 'https://g.page/r/CbntSRvzXVrSEBM/review' ) ); ?>" placeholder="https://g.page/r/.../review">
                    <div class="tf2-field__hint">The "Leave Us a Review" button in the delivery email links here.</div>
                </div>
            </div>

            <!-- Client Tracker -->
            <div class="tf2-settings-section">
                <h3>Client Tracker</h3>
                <p class="tf2-description">The page where clients can track their session progress.</p>

                <div class="tf2-field" style="max-width:640px;">
                    <label class="tf2-field__label">Tracker Page URL</label>
                    <input type="url" name="tracker_page_url" value="<?php echo esc_attr( $tracker ); ?>" placeholder="https://twellerstudios.com/session-tracker/">
                    <div class="tf2-field__hint">Page with the [tweller_tracker] shortcode.</div>
                </div>
            </div>

            <!-- Webhooks -->
            <div class="tf2-settings-section">
                <h3>Webhooks</h3>
                <p class="tf2-description">For SureCart or other payment integrations that auto-create sessions.</p>

                <div class="tf2-field" style="max-width:640px;">
                    <label class="tf2-field__label">Webhook Secret</label>
                    <input type="password" name="webhook_secret" value="<?php echo esc_attr( $secret ); ?>" placeholder="Leave empty to skip verification">
                    <div class="tf2-field__hint">Webhook URL: <code><?php echo rest_url( 'tweller-flow-2/v1/webhook/surecart' ); ?></code></div>
                </div>
            </div>

            <!-- Booking Sync & Config -->
            <div class="tf2-settings-section">
                <h3>Booking Sync & Config</h3>
                <p class="tf2-description">Fetch availability from external calendars and configure session types / packages.</p>

                <div class="tf2-field" style="max-width:640px;">
                    <label class="tf2-field__label">iCal Feed URL</label>
                    <input type="url" name="booking_ical_url" value="<?php echo esc_attr( $booking_ical ); ?>" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics">
                    <div class="tf2-field__hint">Secret iCal URL from Google or Apple Calendar to block out busy times.</div>
                </div>

                <div class="tf2-field" style="max-width:640px;">
                    <p class="tf2-description">
                        Session types and packages are now managed visually on the
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=tweller-flow-2-offerings' ) ); ?>"><strong>Offerings</strong></a> page —
                        add, remove, reorder, and edit prices there.
                    </p>
                </div>
            </div>
        </div>

        <!-- Google Sync (Contacts + Calendar) -->
        <div class="tf2-card tf2-mb-6">
            <div class="tf2-settings-section">
                <h3>Google Sync (Contacts + Calendar)</h3>
                <p class="tf2-description">New booking clients are added to your Google Contacts (they sync straight to your phone), and every session appears on your Google Calendar — yellow while reserved, blue once fully paid.</p>

                <?php
                $g_config       = TwellerFlow2_Google_Contacts::get_config();
                $g_connected    = TwellerFlow2_Google_Contacts::is_connected();
                $g_cal_ok       = TwellerFlow2_Google_Contacts::is_calendar_authorized();
                $g_cal_enabled  = ! array_key_exists( 'calendar_enabled', (array) $g_config ) || ! empty( $g_config['calendar_enabled'] );
                $g_log          = TwellerFlow2_Google_Contacts::get_log( 10 );
                ?>

                <?php if ( isset( $_GET['google'] ) && $_GET['google'] === 'connected' ) : ?>
                    <div class="tf2-alert tf2-alert--success">Google account connected — new bookings will sync to your contacts and calendar.</div>
                <?php elseif ( isset( $_GET['google'] ) && $_GET['google'] === 'error' ) : ?>
                    <div class="tf2-alert" style="background:#FEE2E2; color:#991B1B;">Google connection failed. Check the Client ID / Secret and try again.</div>
                <?php endif; ?>

                <?php if ( isset( $_GET['google_test'] ) ) : ?>
                    <?php
                    $test_session_id = intval( $_GET['google_test'] );
                    $test_entries    = array();
                    foreach ( $g_log as $entry ) {
                        if ( intval( $entry['session_id'] ) === $test_session_id ) {
                            $test_entries[] = $entry;
                        }
                        if ( count( $test_entries ) >= 2 ) break; // newest contact + calendar result
                    }
                    ?>
                    <?php if ( $test_session_id && ! empty( $test_entries ) ) : ?>
                        <div class="tf2-alert" style="background:#EFF6FF; color:#1E3A8A;">
                            <strong>Test sync results (session #<?php echo esc_html( $test_session_id ); ?>):</strong>
                            <?php foreach ( array_reverse( $test_entries ) as $entry ) : ?>
                                <br><?php echo $entry['ok'] ? '&#10003;' : '&#10007;'; ?>
                                <strong><?php echo esc_html( ucfirst( $entry['type'] ) ); ?>:</strong>
                                <?php echo esc_html( $entry['message'] ); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ( ! $test_session_id ) : ?>
                        <div class="tf2-alert" style="background:#FEF3C7; color:#92400E;">No sessions exist yet — create a booking first, then test the sync.</div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ( isset( $_GET['gcal_backfill'] ) ) : ?>
                    <?php
                    $gb_synced    = intval( $_GET['gb_synced'] ?? 0 );
                    $gb_failed    = intval( $_GET['gb_failed'] ?? 0 );
                    $gb_remaining = intval( $_GET['gb_remaining'] ?? 0 );
                    $gb_forced    = ! empty( $_GET['gb_forced'] ) && $_GET['gb_forced'] === '1';
                    $gb_verb      = $gb_forced ? 'Re-pushed' : 'Added';
                    $gb_again     = $gb_forced ? 'Re-sync every booking' : 'Sync all bookings to calendar';
                    ?>
                    <?php if ( $_GET['gcal_backfill'] === 'blocked' ) : ?>
                        <div class="tf2-alert" style="background:#FEF3C7; color:#92400E;">
                            Couldn't sync your bookings: <?php echo esc_html( wp_unslash( $_GET['gb_reason'] ?? 'Calendar sync is unavailable.' ) ); ?>
                        </div>
                    <?php else : ?>
                        <div class="tf2-alert" style="background:#EFF6FF; color:#1E3A8A;">
                            <strong><?php echo esc_html( $gb_verb ); ?> <?php echo esc_html( $gb_synced ); ?> booking<?php echo $gb_synced === 1 ? '' : 's'; ?> to your calendar.</strong>
                            <?php if ( $gb_failed > 0 ) : ?>
                                <?php echo esc_html( $gb_failed ); ?> couldn't be synced &mdash; see the log below for why.
                            <?php endif; ?>
                            <?php if ( $gb_remaining > 0 ) : ?>
                                <br><?php echo esc_html( $gb_remaining ); ?> more still to go &mdash; click <em><?php echo esc_html( $gb_again ); ?></em> again to continue.
                            <?php elseif ( $gb_synced === 0 && $gb_failed === 0 ) : ?>
                                Every booking with a date is already on your calendar &mdash; you're all caught up.
                            <?php else : ?>
                                That's everything &mdash; your calendar is up to date.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php
                // A connection can be "connected" (a refresh token is stored)
                // and still be refused by Google every single time. That used
                // to fail silently, so nothing on this page ever said why the
                // calendar had stopped filling up.
                $g_auth_error = TwellerFlow2_Google_Contacts::get_auth_error();
                ?>
                <?php if ( $g_connected && $g_auth_error ) : ?>
                    <div class="tf2-alert" style="background:#FEE2E2; color:#991B1B;">
                        <strong>Google is refusing this connection — bookings are not syncing.</strong><br>
                        <?php echo esc_html( $g_auth_error ); ?><br>
                        <a class="tf2-btn tf2-btn--primary tf2-btn--sm" style="margin-top:8px;"
                           href="<?php echo esc_url( TwellerFlow2_Google_Contacts::connect_url() ); ?>">Reconnect Google</a>
                    </div>
                <?php endif; ?>

                <?php if ( $g_connected && ! $g_cal_ok ) : ?>
                    <div class="tf2-alert" style="background:#FEF3C7; color:#92400E;">
                        <strong>Reconnect Google to enable calendar sync.</strong>
                        Your current connection was made before calendar sync existed, so Google hasn't granted calendar permission yet.
                        Click below to reconnect (same account) — contacts keep working either way.<br>
                        <a class="tf2-btn tf2-btn--primary tf2-btn--sm" style="margin-top:8px;"
                           href="<?php echo esc_url( TwellerFlow2_Google_Contacts::connect_url() ); ?>">Reconnect Google</a>
                    </div>
                <?php endif; ?>

                <div class="tf2-row" style="max-width:640px;">
                    <div class="tf2-field">
                        <label class="tf2-field__label">Google OAuth Client ID</label>
                        <input type="text" name="google_client_id" value="<?php echo esc_attr( $g_config['client_id'] ?? '' ); ?>" placeholder="xxxx.apps.googleusercontent.com">
                    </div>
                    <div class="tf2-field">
                        <label class="tf2-field__label">Client Secret</label>
                        <input type="password" name="google_client_secret" value="<?php echo esc_attr( $g_config['client_secret'] ?? '' ); ?>">
                    </div>
                </div>

                <div class="tf2-field" style="max-width:640px;">
                    <label class="tf2-toggle">
                        <input type="checkbox" name="google_sync_enabled" value="1" <?php checked( ! empty( $g_config['enabled'] ) ); ?>>
                        <span class="tf2-toggle__switch"></span>
                        <span class="tf2-toggle__label">Sync new bookings to Google Contacts</span>
                    </label>
                </div>

                <div class="tf2-field" style="max-width:640px;">
                    <label class="tf2-toggle">
                        <input type="checkbox" name="google_calendar_enabled" value="1" <?php checked( $g_cal_enabled ); ?>>
                        <span class="tf2-toggle__switch"></span>
                        <span class="tf2-toggle__label">Sync sessions to Google Calendar (yellow = reserved, blue = paid)</span>
                    </label>
                </div>

                <?php $rem = class_exists( 'TwellerFlow2_Google_Calendar' ) ? TwellerFlow2_Google_Calendar::reminder_settings() : array(); ?>
                <div class="tf2-field" style="max-width:640px; border:1px solid #E5E7EB; border-radius:8px; padding:14px 16px; background:#FAFAFA;">
                    <label class="tf2-toggle" style="margin:0 0 4px;">
                        <input type="checkbox" name="gcal_rem_enabled" value="1" <?php checked( ! empty( $rem['enabled'] ) ); ?>>
                        <span class="tf2-toggle__switch"></span>
                        <span class="tf2-toggle__label"><strong>Remind me about shoots</strong></span>
                    </label>
                    <div class="tf2-field__hint" style="margin:0 0 12px;">
                        Alerts are set on each calendar event, so they reach every device signed into
                        that Google account &mdash; phone included. Applies to bookings synced from now
                        on; use <em>Re-sync every booking</em> to add them to shoots already on the calendar.
                    </div>

                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <label style="min-width:150px; margin:0;">
                            <input type="checkbox" name="gcal_rem_day_before" value="1" <?php checked( ! empty( $rem['day_before'] ) ); ?>>
                            The day before, at
                        </label>
                        <input type="time" name="gcal_rem_day_before_at" value="<?php echo esc_attr( $rem['day_before_at'] ?? '18:00' ); ?>" style="width:120px;">
                    </div>

                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <label style="min-width:150px; margin:0;">
                            <input type="checkbox" name="gcal_rem_morning_of" value="1" <?php checked( ! empty( $rem['morning_of'] ) ); ?>>
                            That morning, at
                        </label>
                        <input type="time" name="gcal_rem_morning_at" value="<?php echo esc_attr( $rem['morning_at'] ?? '08:00' ); ?>" style="width:120px;">
                    </div>

                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <label style="min-width:150px; margin:0;">Hours before</label>
                        <input type="number" name="gcal_rem_hours_before" min="0" max="24" value="<?php echo esc_attr( intval( $rem['hours_before'] ?? 2 ) ); ?>" style="width:80px;">
                        <span class="tf2-field__hint" style="margin:0;">0 = off</span>
                    </div>

                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <label style="min-width:150px; margin:0;">Minutes before</label>
                        <input type="number" name="gcal_rem_minutes_before" min="0" max="120" value="<?php echo esc_attr( intval( $rem['minutes_before'] ?? 30 ) ); ?>" style="width:80px;">
                        <span class="tf2-field__hint" style="margin:0;">0 = off</span>
                    </div>

                    <div style="display:flex; align-items:center; gap:10px;">
                        <label style="min-width:150px; margin:0;">Alert by</label>
                        <select name="gcal_rem_method" style="width:140px;">
                            <option value="popup" <?php selected( ( $rem['method'] ?? 'popup' ), 'popup' ); ?>>Notification</option>
                            <option value="email" <?php selected( ( $rem['method'] ?? 'popup' ), 'email' ); ?>>Email</option>
                        </select>
                    </div>

                    <div class="tf2-field__hint" style="margin-top:12px;">
                        A reminder that would land after the shoot has already started is skipped &mdash;
                        so <em>that morning at 8am</em> is simply left off a 7am sunrise session rather
                        than firing at the wrong moment. All-day holds (bookings with no time set) get
                        the day-before reminder only. Google allows five per event.
                    </div>
                </div>

                <div class="tf2-field" style="max-width:640px;">
                    <?php if ( $g_connected ) : ?>
                        <p style="margin:0 0 8px; color:#166534; font-weight:600;">
                            &#10003; Connected as <?php echo esc_html( TwellerFlow2_Google_Contacts::get_account_email() ?: 'Google account' ); ?>
                            <?php if ( $g_cal_ok ) : ?>
                                <span style="font-weight:400; color:#3D3630;">(contacts + calendar)</span>
                            <?php else : ?>
                                <span style="font-weight:400; color:#92400E;">(contacts only — reconnect for calendar)</span>
                            <?php endif; ?>
                        </p>
                        <button type="submit" name="tweller_flow_2_google_test_sync" value="1" class="tf2-btn tf2-btn--primary tf2-btn--sm">Save &amp; Test Sync Now</button>
                        <?php if ( $g_cal_ok ) : ?>
                            <button type="submit" name="tweller_flow_2_google_sync_all" value="1" class="tf2-btn tf2-btn--secondary tf2-btn--sm">Sync all bookings to calendar</button>
                            <button type="submit" name="tweller_flow_2_google_resync_all" value="1" class="tf2-btn tf2-btn--secondary tf2-btn--sm">Re-sync every booking</button>
                        <?php endif; ?>
                        <a class="tf2-btn tf2-btn--secondary tf2-btn--sm"
                           href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=tweller-flow-2-settings&tf2_google=disconnect' ), 'tf2_google_disconnect' ) ); ?>">Disconnect</a>
                        <div class="tf2-field__hint" style="margin-top:8px;">Test runs the contact + calendar sync for your most recent session. <strong>Sync all bookings</strong> back-fills bookings that aren't on the calendar yet. <strong>Re-sync every booking</strong> also rewrites the ones already there, which is what you want after a booking's length or time has been corrected &mdash; it updates events in place rather than duplicating them. Both run in batches; click again if it says more remain.</div>
                    <?php elseif ( ! empty( $g_config['client_id'] ) && ! empty( $g_config['client_secret'] ) ) : ?>
                        <a class="tf2-btn tf2-btn--primary" href="<?php echo esc_url( TwellerFlow2_Google_Contacts::connect_url() ); ?>">Connect Google Account</a>
                        <div class="tf2-field__hint" style="margin-top:8px;">Save settings first if you just entered the Client ID / Secret.</div>
                        <div class="tf2-alert" style="background:#FEF3C7; color:#92400E; margin-top:10px; max-width:640px;">
                            <strong>Seeing "Access blocked: this app hasn't completed Google verification"?</strong>
                            That's Google's own screen, not an error in this plugin — it appears because your Cloud project
                            requests calendar &amp; contacts access, which Google flags as sensitive. You don't need to submit
                            the app for verification. In <a href="https://console.cloud.google.com/apis/credentials/consent" target="_blank">Google Cloud &rarr; OAuth consent screen</a>:
                            set <em>User type</em> to <strong>External</strong>, keep <em>Publishing status</em> on <strong>Testing</strong>,
                            and under <em>Test users</em> add the exact Google account you're connecting
                            (<code>stephen.twellerstudios@gmail.com</code>). Then retry &mdash; on the "unverified app" screen click
                            <em>Advanced &rarr; Go to twellerstudios.com (unsafe)</em> to continue. Test users are allowed through without verification.
                        </div>
                    <?php else : ?>
                        <div class="tf2-field__hint">
                            <strong>One-time setup:</strong> at <a href="https://console.cloud.google.com" target="_blank">console.cloud.google.com</a>
                            create a project, enable the <em>People API</em> and the <em>Google Calendar API</em>, create an <em>OAuth Client ID (Web application)</em>, and add this redirect URI:<br>
                            <code style="user-select:all;"><?php echo esc_html( TwellerFlow2_Google_Contacts::redirect_uri() ); ?></code><br>
                            Then paste the Client ID and Secret above, save, and click Connect.<br><br>
                            On the OAuth consent screen keep <em>Publishing status</em> on <strong>Testing</strong> and add your own
                            Google account under <em>Test users</em> &mdash; otherwise Google blocks sign-in with
                            "this app hasn't completed verification", since calendar &amp; contacts are sensitive scopes.
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ( ! empty( $g_log ) ) : ?>
                    <div class="tf2-field" style="max-width:640px;">
                        <label class="tf2-field__label">Recent sync activity</label>
                        <table class="widefat striped" style="margin-top:4px;">
                            <thead>
                                <tr>
                                    <th style="width:140px;">Time</th>
                                    <th style="width:70px;">Type</th>
                                    <th style="width:60px;">Status</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $g_log as $entry ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $entry['time'] ); ?></td>
                                        <td><?php echo esc_html( ucfirst( $entry['type'] ) ); ?></td>
                                        <td>
                                            <?php if ( ! empty( $entry['ok'] ) ) : ?>
                                                <span style="color:#166534; font-weight:600;">OK</span>
                                            <?php else : ?>
                                                <span style="color:#991B1B; font-weight:600;">Failed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>#<?php echo esc_html( intval( $entry['session_id'] ) ); ?> — <?php echo esc_html( $entry['message'] ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="tf2-field__hint" style="margin-top:4px;">Last 10 sync attempts, newest first. Failures show Google's HTTP code and error message.</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payments — WiPay -->
        <?php if ( class_exists( 'TwellerFlow2_WiPay' ) ) :
            $wipay     = TwellerFlow2_WiPay::get_settings();
            $wipay_log = get_option( 'tweller_wipay_log', array() );
            $wipay_log = is_array( $wipay_log ) ? array_slice( $wipay_log, 0, 8 ) : array();
        ?>
        <div class="tf2-card tf2-mb-6">
            <div class="tf2-settings-section">
                <h3>Payments &mdash; WiPay</h3>
                <p class="tf2-description">Accept Visa &amp; Mastercard through WiPay's secure hosted checkout. Clients get a "Pay by card" button on their tracker page next to the bank-transfer option.</p>

                <div class="tf2-field" style="max-width:480px;">
                    <label class="tf2-toggle">
                        <input type="checkbox" name="wipay_enabled" value="1" <?php checked( ! empty( $wipay['enabled'] ) ); ?>>
                        <span class="tf2-toggle__switch"></span>
                        <span class="tf2-toggle__label">Enable WiPay card payments</span>
                    </label>
                </div>

                <div class="tf2-row" style="max-width:640px;">
                    <div class="tf2-field">
                        <label class="tf2-field__label">Account Number</label>
                        <input type="text" name="wipay_account_number" value="<?php echo esc_attr( $wipay['account_number'] ); ?>" placeholder="8694059828" pattern="[0-9]{10}" maxlength="10">
                        <div class="tf2-field__hint">Your 10-digit WiPay account number (used in Live mode).</div>
                    </div>
                    <div class="tf2-field">
                        <label class="tf2-field__label">API Key</label>
                        <input type="password" name="wipay_api_key" value="<?php echo esc_attr( $wipay['api_key'] ); ?>" placeholder="From your WiPay dashboard">
                        <div class="tf2-field__hint">Used to cryptographically verify payment confirmations.</div>
                    </div>
                </div>

                <div class="tf2-row" style="max-width:640px;">
                    <div class="tf2-field">
                        <label class="tf2-field__label">Environment</label>
                        <select name="wipay_environment">
                            <option value="sandbox" <?php selected( $wipay['environment'], 'sandbox' ); ?>>Sandbox &mdash; test cards</option>
                            <option value="live" <?php selected( $wipay['environment'], 'live' ); ?>>Live</option>
                        </select>
                    </div>
                    <div class="tf2-field">
                        <label class="tf2-field__label">Fee Structure</label>
                        <select name="wipay_fee_structure">
                            <option value="customer_pay" <?php selected( $wipay['fee_structure'], 'customer_pay' ); ?>>Customer pays fee</option>
                            <option value="merchant_absorb" <?php selected( $wipay['fee_structure'], 'merchant_absorb' ); ?>>I absorb the fee</option>
                            <option value="split" <?php selected( $wipay['fee_structure'], 'split' ); ?>>Split</option>
                        </select>
                    </div>
                </div>

                <div class="tf2-field" style="max-width:300px;">
                    <label class="tf2-field__label">Origin (app identifier)</label>
                    <input type="text" name="wipay_origin" value="<?php echo esc_attr( $wipay['origin'] ); ?>" readonly onfocus="this.removeAttribute('readonly');">
                    <div class="tf2-field__hint">Identifies this website to WiPay. Leave as-is unless WiPay support asks otherwise.</div>
                </div>

                <div class="tf2-field" style="max-width:640px;">
                    <label class="tf2-field__label">Response URL</label>
                    <code style="user-select:all; display:inline-block; padding:6px 8px; background:#F5F5F5; border-radius:6px;"><?php echo esc_html( rest_url( 'tweller-flow-2/v1/wipay/response' ) ); ?></code>
                    <div class="tf2-field__hint">Sent automatically with every payment request &mdash; nothing to configure on the WiPay side.</div>
                </div>

                <div class="tf2-alert" style="background:#EFF6FF; color:#1E3A8A; max-width:640px;">
                    <strong>Sandbox testing:</strong> in Sandbox mode the plugin automatically uses WiPay's shared test account <code>1234567890</code> &mdash; set the API Key above to <code>123</code>, then pay with test card <code>4111 1111 1111 1111</code> (any expiry / CVV). No real money moves.
                </div>

                <?php if ( ! empty( $wipay_log ) ) : ?>
                    <div class="tf2-field" style="max-width:640px;">
                        <label class="tf2-field__label">Recent payment activity</label>
                        <table class="widefat striped" style="margin-top:4px;">
                            <thead>
                                <tr>
                                    <th style="width:140px;">Time</th>
                                    <th style="width:70px;">Type</th>
                                    <th style="width:90px;">Status</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $wipay_log as $entry ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
                                        <td><?php echo esc_html( ucfirst( $entry['type'] ?? '' ) ); ?></td>
                                        <td>
                                            <?php $wl_status = $entry['status'] ?? ''; ?>
                                            <?php if ( $wl_status === 'success' ) : ?>
                                                <span style="color:#166534; font-weight:600;">Success</span>
                                            <?php elseif ( $wl_status === 'request' ) : ?>
                                                <span style="color:#3D3630;">Request</span>
                                            <?php else : ?>
                                                <span style="color:#991B1B; font-weight:600;"><?php echo esc_html( ucfirst( $wl_status ) ); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html( ( $entry['ref'] ?? '' ) . ' — ' . ( $entry['message'] ?? '' ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="tf2-field__hint" style="margin-top:4px;">Last 8 payment events, newest first.</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Culling edited-preview look -->
        <div class="tf2-card tf2-mb-6">
            <div class="tf2-settings-section">
                <h3>Edited Preview (Culling Portal)</h3>
                <p class="tf2-description">
                    The before/after slider clients see when choosing photos. Tune these until the "after" side matches
                    your Lightroom preset's look — open any culling gallery in another tab to check as you adjust.
                </p>
                <?php
                $cp = wp_parse_args( get_option( 'tweller_flow_2_culling_preset', array() ), array(
                    'brightness' => 1.06, 'contrast' => 1.12, 'saturate' => 1.16, 'warmth' => 0.12, 'hue' => -3,
                ) );
                ?>
                <div class="tf2-row" style="max-width:640px; flex-wrap:wrap; gap:12px;">
                    <div class="tf2-field">
                        <label class="tf2-field__label">Brightness</label>
                        <input type="number" step="0.01" min="0.5" max="2" name="preset_brightness" value="<?php echo esc_attr( $cp['brightness'] ); ?>">
                        <div class="tf2-field__hint">1 = unchanged, 1.1 = brighter</div>
                    </div>
                    <div class="tf2-field">
                        <label class="tf2-field__label">Contrast</label>
                        <input type="number" step="0.01" min="0.5" max="2" name="preset_contrast" value="<?php echo esc_attr( $cp['contrast'] ); ?>">
                        <div class="tf2-field__hint">1 = unchanged</div>
                    </div>
                    <div class="tf2-field">
                        <label class="tf2-field__label">Saturation</label>
                        <input type="number" step="0.01" min="0" max="2" name="preset_saturate" value="<?php echo esc_attr( $cp['saturate'] ); ?>">
                        <div class="tf2-field__hint">1 = unchanged</div>
                    </div>
                    <div class="tf2-field">
                        <label class="tf2-field__label">Warmth</label>
                        <input type="number" step="0.01" min="0" max="1" name="preset_warmth" value="<?php echo esc_attr( $cp['warmth'] ); ?>">
                        <div class="tf2-field__hint">0 = none, 0.2 = golden</div>
                    </div>
                    <div class="tf2-field">
                        <label class="tf2-field__label">Hue shift (deg)</label>
                        <input type="number" step="1" min="-30" max="30" name="preset_hue" value="<?php echo esc_attr( $cp['hue'] ); ?>">
                        <div class="tf2-field__hint">Small negative = warmer tone</div>
                    </div>
                </div>
            </div>
        </div>

        <button type="submit" class="tf2-btn tf2-btn--primary tf2-btn--lg">Save Settings</button>
    </form>
</div>
