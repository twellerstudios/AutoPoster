<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf-admin">
    <h1>Tweller Flow Settings</h1>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
    <?php endif; ?>

    <form method="post">
        <?php wp_nonce_field( 'tweller_flow_save_settings' ); ?>
        <input type="hidden" name="tweller_flow_save_settings" value="1">

        <div class="tf-card">
            <h2>Email Settings (Zoho SMTP)</h2>
            <table class="form-table">
                <tr>
                    <th><label for="smtp_host">SMTP Host</label></th>
                    <td><input type="text" name="smtp_host" id="smtp_host" value="<?php echo esc_attr( $smtp['host'] ?? 'smtp.zoho.com' ); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="smtp_port">SMTP Port</label></th>
                    <td><input type="number" name="smtp_port" id="smtp_port" value="<?php echo esc_attr( $smtp['port'] ?? 465 ); ?>" class="small-text"></td>
                </tr>
                <tr>
                    <th><label for="smtp_encryption">Encryption</label></th>
                    <td>
                        <select name="smtp_encryption" id="smtp_encryption" class="tf-select">
                            <option value="ssl" <?php selected( $smtp['encryption'] ?? 'ssl', 'ssl' ); ?>>SSL</option>
                            <option value="tls" <?php selected( $smtp['encryption'] ?? '', 'tls' ); ?>>TLS</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="smtp_username">Username (Email)</label></th>
                    <td><input type="text" name="smtp_username" id="smtp_username" value="<?php echo esc_attr( $smtp['username'] ?? '' ); ?>" class="regular-text" placeholder="you@twellerstudios.com"></td>
                </tr>
                <tr>
                    <th><label for="smtp_password">Password</label></th>
                    <td><input type="password" name="smtp_password" id="smtp_password" value="<?php echo esc_attr( $smtp['password'] ?? '' ); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="smtp_from_name">From Name</label></th>
                    <td><input type="text" name="smtp_from_name" id="smtp_from_name" value="<?php echo esc_attr( $smtp['from_name'] ?? 'Tweller Studios' ); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="smtp_from_email">From Email</label></th>
                    <td><input type="email" name="smtp_from_email" id="smtp_from_email" value="<?php echo esc_attr( $smtp['from_email'] ?? '' ); ?>" class="regular-text"></td>
                </tr>
            </table>
        </div>

        <div class="tf-card">
            <h2>Banking Information</h2>
            <p class="description">This is included in booking confirmation emails so clients know where to send payment.</p>
            <textarea name="banking_info" rows="6" class="large-text"><?php echo esc_textarea( $banking ); ?></textarea>
        </div>

        <div class="tf-card">
            <h2>Delivery Settings</h2>
            <table class="form-table">
                <tr>
                    <th><label for="delivery_days">Default Delivery Time (days)</label></th>
                    <td>
                        <input type="number" name="delivery_days" id="delivery_days" value="<?php echo esc_attr( $delivery ); ?>" class="small-text" min="1" max="90">
                        <p class="description">Number of days after the session date to estimate delivery. Used in emails and the client tracker.</p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="tf-card">
            <h2>Client Tracker Page</h2>
            <table class="form-table">
                <tr>
                    <th><label for="tracker_page_url">Tracker Page URL</label></th>
                    <td>
                        <input type="url" name="tracker_page_url" id="tracker_page_url" value="<?php echo esc_attr( $tracker ); ?>" class="large-text" placeholder="https://twellerstudios.com/session-tracker/">
                        <p class="description">
                            The page where you've added the <code>[tweller_tracker]</code> shortcode.
                            Create a page, add the shortcode, then paste the URL here.
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="tf-card">
            <h2>SureCart Webhook</h2>
            <table class="form-table">
                <tr>
                    <th>Webhook URL</th>
                    <td>
                        <code><?php echo rest_url( 'tweller-flow/v1/webhook/surecart' ); ?></code>
                        <p class="description">Add this URL in your SureCart webhook settings. Events: <code>checkout.completed</code></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="webhook_secret">Webhook Secret</label></th>
                    <td>
                        <input type="text" name="webhook_secret" id="webhook_secret" value="<?php echo esc_attr( $secret ); ?>" class="regular-text">
                        <p class="description">Optional. If set, webhook payloads will be verified against this secret.</p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="tf-card">
            <h2>Packages</h2>
            <table class="tf-table widefat">
                <thead>
                    <tr>
                        <th>Package</th>
                        <th>Duration</th>
                        <th>Images</th>
                        <th>Max Members</th>
                        <th>Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $packages as $key => $pkg ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $pkg['name'] ); ?></strong></td>
                            <td><?php echo esc_html( $pkg['duration'] ); ?></td>
                            <td>~<?php echo esc_html( $pkg['images'] ); ?></td>
                            <td><?php echo esc_html( $pkg['members'] ); ?></td>
                            <td>$<?php echo number_format( $pkg['price'], 0 ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">Package configuration can be updated in the plugin settings (code or database).</p>
        </div>

        <p class="submit">
            <input type="submit" class="button button-primary button-hero" value="Save Settings">
        </p>
    </form>
</div>
