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
            <h2>Photo Automation</h2>
            <p class="description">Automate the culling, editing, and delivery pipeline. Drop photos into a watched folder and the system handles the rest.</p>
            <table class="form-table">
                <tr>
                    <th><label for="automation_enabled">Enable Automation</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="automation_enabled" id="automation_enabled" value="1" <?php checked( $automation['enabled'] ?? false ); ?>>
                            Enable automated photo pipeline
                        </label>
                        <p class="description">When enabled, the system watches for new photos and auto-runs culling, AI editing, and gallery creation.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="automation_backend_url">Backend URL</label></th>
                    <td>
                        <input type="url" name="automation_backend_url" id="automation_backend_url" value="<?php echo esc_attr( $automation['backend_url'] ?? 'http://localhost:3001' ); ?>" class="regular-text">
                        <p class="description">URL of the AutoPoster Node.js backend (e.g., <code>http://localhost:3001</code>).</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="automation_api_key">Automation API Key</label></th>
                    <td>
                        <input type="text" name="automation_api_key" id="automation_api_key" value="<?php echo esc_attr( $automation['api_key'] ?? '' ); ?>" class="regular-text">
                        <p class="description">Shared secret between the Node.js pipeline and WordPress. Set the same key in both <code>.env</code> and here.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="automation_watch_dir">Watch Directory</label></th>
                    <td>
                        <input type="text" name="automation_watch_dir" id="automation_watch_dir" value="<?php echo esc_attr( $automation['watch_dir'] ?? '' ); ?>" class="large-text" placeholder="/path/to/sessions/photos">
                        <p class="description">
                            Root folder to watch for session photos. Structure: <code>{watch_dir}/{TRACKING_CODE}/raw/</code><br>
                            Also set this as <code>PHOTO_WATCH_DIR</code> in the backend <code>.env</code> file.
                        </p>
                    </td>
                </tr>
            </table>

            <h3>Imagen AI</h3>
            <table class="form-table">
                <tr>
                    <th><label for="automation_imagen_api_key">Imagen AI API Key</label></th>
                    <td>
                        <input type="password" name="automation_imagen_api_key" id="automation_imagen_api_key" value="<?php echo esc_attr( $automation['imagen_api_key'] ?? '' ); ?>" class="regular-text">
                        <p class="description">Your Imagen AI API key. Leave blank to use the built-in auto-edit (normalize + sharpen + color boost).</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="automation_imagen_profile_id">Imagen AI Profile ID</label></th>
                    <td>
                        <input type="text" name="automation_imagen_profile_id" id="automation_imagen_profile_id" value="<?php echo esc_attr( $automation['imagen_profile_id'] ?? '' ); ?>" class="regular-text">
                        <p class="description">Your trained Personal AI Profile ID from Imagen AI. This applies your unique editing style.</p>
                    </td>
                </tr>
            </table>

            <h3>Gallery & Delivery</h3>
            <table class="form-table">
                <tr>
                    <th><label for="automation_gallery_base_url">Gallery Base URL</label></th>
                    <td>
                        <input type="url" name="automation_gallery_base_url" id="automation_gallery_base_url" value="<?php echo esc_attr( $automation['gallery_base_url'] ?? '' ); ?>" class="regular-text" placeholder="https://gallery.twellerstudios.com">
                        <p class="description">Base URL for client galleries. The session code is appended automatically.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="automation_auto_skip_review">Auto-Skip Review</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="automation_auto_skip_review" id="automation_auto_skip_review" value="1" <?php checked( $automation['auto_skip_review'] ?? false ); ?>>
                            Skip manual edit review (fully automated)
                        </label>
                        <p class="description">When enabled, edited photos go straight to gallery without manual review. Best used with a trained Imagen AI profile.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="automation_cull_keep_percent">Cull Keep %</label></th>
                    <td>
                        <input type="number" name="automation_cull_keep_percent" id="automation_cull_keep_percent" value="<?php echo esc_attr( $automation['cull_keep_percent'] ?? 40 ); ?>" class="small-text" min="10" max="90" step="5">
                        <span>%</span>
                        <p class="description">Percentage of photos to keep after auto-culling. Lower = more selective. Duplicates are always removed.</p>
                    </td>
                </tr>
            </table>

            <div style="background: #f0f0f1; padding: 12px 16px; border-left: 4px solid #2271b1; margin-top: 16px;">
                <strong>How it works:</strong>
                <ol style="margin: 8px 0 0 20px;">
                    <li>A new session is booked &rarr; folder structure is auto-created at <code>{watch_dir}/{CODE}/raw/</code></li>
                    <li>You import photos to the <code>raw/</code> folder (from SD card, tethering, etc.)</li>
                    <li>Auto-cull runs: removes blurry shots, duplicates, and bad exposure &rarr; keeps go to <code>_keeps/</code></li>
                    <li>Imagen AI (or built-in auto-edit) processes the keeps &rarr; results go to <code>edited/</code></li>
                    <li>You review edits (or auto-skip) &rarr; gallery is prepared and client is notified</li>
                </ol>
                <p style="margin-top: 8px;">
                    <strong>Backend .env variables:</strong><br>
                    <code>PHOTO_WATCH_DIR</code>, <code>WP_AUTOMATION_URL</code>, <code>WP_AUTOMATION_API_KEY</code>,
                    <code>IMAGEN_AI_API_KEY</code>, <code>IMAGEN_AI_PROFILE_ID</code>, <code>GALLERY_BASE_URL</code>,
                    <code>AUTO_SKIP_REVIEW</code>, <code>CULL_KEEP_PERCENT</code>
                </p>
            </div>
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
