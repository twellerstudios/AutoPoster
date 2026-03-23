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

            <!-- Banking -->
            <div class="tf-settings-section">
                <h3>Banking Details</h3>
                <p class="tf-description">Payment instructions included in booking confirmation emails.</p>

                <div class="tf-field" style="max-width:640px;">
                    <label class="tf-field__label">Banking Info</label>
                    <textarea name="banking_info" rows="5"><?php echo esc_textarea( $banking ); ?></textarea>
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
        </div>

        <button type="submit" class="tf-btn tf-btn--primary tf-btn--lg">Save Settings</button>
    </form>
</div>
