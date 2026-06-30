<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf-wrap">
    <h1>Settings</h1>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="tf-alert tf-alert--success">Settings saved.</div>
    <?php endif; ?>

    <form method="post">
        <?php wp_nonce_field( 'tweller_flow_save_settings' ); ?>
        <input type="hidden" name="tweller_flow_save_settings" value="1">

        <div class="tf-card tf-mb-6">
            <!-- Folder Watcher -->
            <div class="tf-settings-section">
                <h3>Folder Watcher</h3>
                <p class="tf-description">Configure the local agent that watches your photo folders and updates session stages automatically.</p>

                <div class="tf-field" style="max-width:480px;">
                    <label class="tf-toggle">
                        <input type="checkbox" name="automation_enabled" value="1" <?php checked( ! empty( $automation['enabled'] ) ); ?>>
                        <span class="tf-toggle__switch"></span>
                        <span class="tf-toggle__label">Enable folder watcher integration</span>
                    </label>
                </div>

                <div class="tf-row" style="max-width:640px;">
                    <div class="tf-field">
                        <label class="tf-field__label">Backend Agent URL</label>
                        <input type="url" name="automation_backend_url" value="<?php echo esc_attr( $automation['backend_url'] ?? 'http://localhost:3001' ); ?>" placeholder="http://localhost:3001">
                        <div class="tf-field__hint">URL where the local Node.js agent is running.</div>
                    </div>
                    <div class="tf-field">
                        <label class="tf-field__label">API Key</label>
                        <input type="password" name="automation_api_key" value="<?php echo esc_attr( $automation['api_key'] ?? '' ); ?>" placeholder="Leave empty for localhost-only">
                        <div class="tf-field__hint">Shared secret between WordPress and the local agent.</div>
                    </div>
                </div>

                <div class="tf-field" style="max-width:640px;">
                    <label class="tf-field__label">Watch Directory Path</label>
                    <input type="text" name="automation_watch_dir" value="<?php echo esc_attr( $automation['watch_dir'] ?? '' ); ?>" placeholder="D:\Photos\Sessions">
                    <div class="tf-field__hint">The main folder on your local machine that the agent watches for new session folders.</div>
                </div>

                <div class="tf-field" style="max-width:640px;">
                    <label class="tf-field__label">Gallery Base URL</label>
                    <input type="url" name="automation_gallery_base_url" value="<?php echo esc_attr( $automation['gallery_base_url'] ?? '' ); ?>" placeholder="https://gallery.twellerstudios.com">
                    <div class="tf-field__hint">Base URL for client gallery links.</div>
                </div>
            </div>

            <!-- Delivery -->
            <div class="tf-settings-section">
                <h3>Delivery</h3>
                <p class="tf-description">Default delivery timeline for new sessions.</p>

                <div class="tf-field" style="max-width:200px;">
                    <label class="tf-field__label">Delivery Days</label>
                    <input type="number" name="delivery_days" value="<?php echo esc_attr( $delivery ); ?>" min="1" max="90">
                    <div class="tf-field__hint">Days after session date.</div>
                </div>
            </div>

            <!-- Email / SMTP -->
            <div class="tf-settings-section">
                <h3>Email (SMTP)</h3>
                <p class="tf-description">SMTP settings for sending email notifications to clients.</p>

                <div class="tf-row" style="max-width:640px;">
                    <div class="tf-field">
                        <label class="tf-field__label">SMTP Host</label>
                        <input type="text" name="smtp_host" value="<?php echo esc_attr( $smtp['host'] ?? '' ); ?>" placeholder="smtp.zoho.com">
                    </div>
                    <div class="tf-field">
                        <label class="tf-field__label">Port</label>
                        <input type="number" name="smtp_port" value="<?php echo esc_attr( $smtp['port'] ?? 465 ); ?>">
                    </div>
                </div>

                <div class="tf-field" style="max-width:300px;">
                    <label class="tf-field__label">Encryption</label>
                    <select name="smtp_encryption">
                        <option value="ssl" <?php selected( $smtp['encryption'] ?? '', 'ssl' ); ?>>SSL</option>
                        <option value="tls" <?php selected( $smtp['encryption'] ?? '', 'tls' ); ?>>TLS</option>
                        <option value="" <?php selected( $smtp['encryption'] ?? '', '' ); ?>>None</option>
                    </select>
                </div>

                <div class="tf-row" style="max-width:640px;">
                    <div class="tf-field">
                        <label class="tf-field__label">Username</label>
                        <input type="text" name="smtp_username" value="<?php echo esc_attr( $smtp['username'] ?? '' ); ?>">
                    </div>
                    <div class="tf-field">
                        <label class="tf-field__label">Password</label>
                        <input type="password" name="smtp_password" value="<?php echo esc_attr( $smtp['password'] ?? '' ); ?>">
                    </div>
                </div>

                <div class="tf-row" style="max-width:640px;">
                    <div class="tf-field">
                        <label class="tf-field__label">From Name</label>
                        <input type="text" name="smtp_from_name" value="<?php echo esc_attr( $smtp['from_name'] ?? 'Tweller Studios' ); ?>">
                    </div>
                    <div class="tf-field">
                        <label class="tf-field__label">From Email</label>
                        <input type="email" name="smtp_from_email" value="<?php echo esc_attr( $smtp['from_email'] ?? '' ); ?>">
                    </div>
                </div>
            </div>

            <!-- Banking & Payments -->
            <div class="tf-settings-section">
                <h3>Banking &amp; Payment Details</h3>
                <p class="tf-description">Payment instructions included in confirmation emails when clients owe an additional balance.</p>

                <div class="tf-field" style="max-width:640px;">
                    <label class="tf-field__label">Bank Transfer Details</label>
                    <textarea name="banking_info" rows="5" placeholder="Bank name, account name, account number, routing info..."><?php echo esc_textarea( $banking ); ?></textarea>
                    <div class="tf-field__hint">Shown in selection confirmation emails when client selects extra photos.</div>
                </div>

                <div class="tf-field" style="max-width:640px;">
                    <label class="tf-field__label">WiPay Payment URL</label>
                    <input type="url" name="wipay_url" value="<?php echo esc_attr( get_option( 'tweller_flow_wipay_url', '' ) ); ?>" placeholder="https://wipay.tt/pay/...">
                    <div class="tf-field__hint">Your WiPay payment link. Shown as a "Pay via WiPay" button in selection emails for credit/debit card payments.</div>
                </div>

                <div class="tf-field" style="max-width:200px;">
                    <label class="tf-field__label">Price Per Extra Photo (TTD)</label>
                    <input type="number" name="culling_price_per_photo" value="<?php echo esc_attr( get_option( 'tweller_flow_culling_price_per_photo', 30 ) ); ?>" min="1" step="1" placeholder="30">
                    <div class="tf-field__hint">Charge per additional photo beyond the included package amount.</div>
                </div>
            </div>

            <!-- Client Tracker -->
            <div class="tf-settings-section">
                <h3>Client Tracker</h3>
                <p class="tf-description">The page where clients can track their session progress.</p>

                <div class="tf-field" style="max-width:640px;">
                    <label class="tf-field__label">Tracker Page URL</label>
                    <input type="url" name="tracker_page_url" value="<?php echo esc_attr( $tracker ); ?>" placeholder="https://twellerstudios.com/session-tracker/">
                    <div class="tf-field__hint">Page with the [tweller_tracker] shortcode.</div>
                </div>
            </div>

            <!-- Webhooks -->
            <div class="tf-settings-section">
                <h3>Webhooks</h3>
                <p class="tf-description">For SureCart or other payment integrations that auto-create sessions.</p>

                <div class="tf-field" style="max-width:640px;">
                    <label class="tf-field__label">Webhook Secret</label>
                    <input type="password" name="webhook_secret" value="<?php echo esc_attr( $secret ); ?>" placeholder="Leave empty to skip verification">
                    <div class="tf-field__hint">Webhook URL: <code><?php echo rest_url( 'tweller-flow/v1/webhook/surecart' ); ?></code></div>
                </div>
            </div>

            <!-- Booking Sync & Config -->
            <div class="tf-settings-section">
                <h3>Booking Sync & Config</h3>
                <p class="tf-description">Fetch availability from external calendars and configure session types / packages.</p>

                <div class="tf-field" style="max-width:640px;">
                    <label class="tf-field__label">iCal Feed URL</label>
                    <input type="url" name="booking_ical_url" value="<?php echo esc_attr( $booking_ical ); ?>" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics">
                    <div class="tf-field__hint">Secret iCal URL from Google or Apple Calendar to block out busy times.</div>
                </div>

                <div class="tf-field" style="max-width:640px;">
                    <label class="tf-field__label">Session Types (JSON format)</label>
                    <textarea name="session_types_json" rows="8" style="font-family: monospace;"><?php 
                        $types = get_option('tweller_flow_session_types', array());
                        echo esc_textarea( wp_json_encode( $types, JSON_PRETTY_PRINT ) ); 
                    ?></textarea>
                    <div class="tf-field__hint">Advanced: Edit the available session types and their allowed packages. Valid JSON required.</div>
                </div>

                <div class="tf-field" style="max-width:640px;">
                    <label class="tf-field__label">Packages (JSON format)</label>
                    <textarea name="packages_json" rows="12" style="font-family: monospace;"><?php 
                        $pkgs = get_option('tweller_flow_packages', array());
                        echo esc_textarea( wp_json_encode( $pkgs, JSON_PRETTY_PRINT ) ); 
                    ?></textarea>
                    <div class="tf-field__hint">Advanced: Edit the available packages. Valid JSON required.</div>
                </div>
            </div>
        </div>

        <button type="submit" class="tf-btn tf-btn--primary tf-btn--lg">Save Settings</button>
    </form>
</div>
