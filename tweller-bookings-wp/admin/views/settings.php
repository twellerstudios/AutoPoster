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

        <!-- Google Contacts sync -->
        <div class="tf2-card tf2-mb-6">
            <div class="tf2-settings-section">
                <h3>Google Contacts</h3>
                <p class="tf2-description">Automatically add new booking clients to your Google Contacts — they sync straight to your phone.</p>

                <?php
                $g_config    = TwellerFlow2_Google_Contacts::get_config();
                $g_connected = TwellerFlow2_Google_Contacts::is_connected();
                ?>

                <?php if ( isset( $_GET['google'] ) && $_GET['google'] === 'connected' ) : ?>
                    <div class="tf2-alert tf2-alert--success">Google account connected — new bookings will sync to your contacts.</div>
                <?php elseif ( isset( $_GET['google'] ) && $_GET['google'] === 'error' ) : ?>
                    <div class="tf2-alert" style="background:#FEE2E2; color:#991B1B;">Google connection failed. Check the Client ID / Secret and try again.</div>
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
                    <?php if ( $g_connected ) : ?>
                        <p style="margin:0 0 8px; color:#166534; font-weight:600;">
                            &#10003; Connected as <?php echo esc_html( TwellerFlow2_Google_Contacts::get_account_email() ?: 'Google account' ); ?>
                        </p>
                        <a class="tf2-btn tf2-btn--secondary tf2-btn--sm"
                           href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=tweller-flow-2-settings&tf2_google=disconnect' ), 'tf2_google_disconnect' ) ); ?>">Disconnect</a>
                    <?php elseif ( ! empty( $g_config['client_id'] ) && ! empty( $g_config['client_secret'] ) ) : ?>
                        <a class="tf2-btn tf2-btn--primary" href="<?php echo esc_url( TwellerFlow2_Google_Contacts::connect_url() ); ?>">Connect Google Account</a>
                        <div class="tf2-field__hint" style="margin-top:8px;">Save settings first if you just entered the Client ID / Secret.</div>
                    <?php else : ?>
                        <div class="tf2-field__hint">
                            <strong>One-time setup:</strong> at <a href="https://console.cloud.google.com" target="_blank">console.cloud.google.com</a>
                            create a project, enable the <em>People API</em>, create an <em>OAuth Client ID (Web application)</em>, and add this redirect URI:<br>
                            <code style="user-select:all;"><?php echo esc_html( TwellerFlow2_Google_Contacts::redirect_uri() ); ?></code><br>
                            Then paste the Client ID and Secret above, save, and click Connect.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <button type="submit" class="tf2-btn tf2-btn--primary tf2-btn--lg">Save Settings</button>
    </form>
</div>
