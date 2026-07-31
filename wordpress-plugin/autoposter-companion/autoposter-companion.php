<?php
/**
 * Plugin Name: AutoPoster Companion
 * Plugin URI: https://letsjourneyto.com
 * Description: Companion plugin for the Journey To Poster Android app. Adds a social links settings page and a small REST endpoint the app uses to show your "Follow us" icons — everything else (posts, media, categories, tags) is managed through WordPress's own REST API.
 * Version: 1.0.0
 * Author: Journey To
 * License: GPL-2.0-or-later
 * Text Domain: autoposter-companion
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AUTOPOSTER_COMPANION_VERSION', '1.0.0');
define('AUTOPOSTER_COMPANION_DIR', plugin_dir_path(__FILE__));
define('AUTOPOSTER_COMPANION_URL', plugin_dir_url(__FILE__));

require_once AUTOPOSTER_COMPANION_DIR . 'includes/class-social-settings.php';
require_once AUTOPOSTER_COMPANION_DIR . 'includes/class-site-info-rest.php';
require_once AUTOPOSTER_COMPANION_DIR . 'includes/class-frontend-display.php';

/**
 * Boots the plugin's three pieces:
 *  - Settings page (WP Admin → Settings → AutoPoster Companion)
 * - REST route the app reads (GET /wp-json/autoposter/v1/site-info)
 * - Frontend "Follow us" row appended to published posts
 */
function autoposter_companion_init() {
    $settings = new AutoPoster_Social_Settings();
    $settings->register();

    $rest = new AutoPoster_Site_Info_Rest($settings);
    $rest->register();

    $frontend = new AutoPoster_Frontend_Display($settings);
    $frontend->register();
}
add_action('plugins_loaded', 'autoposter_companion_init');

/**
 * Nudges the admin if Application Passwords can't be used (WordPress requires
 * either HTTPS or a local/dev environment for them to be available), since the
 * Android app depends entirely on that core feature to authenticate.
 */
function autoposter_companion_admin_notices() {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (is_ssl()) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'options-general') {
        return;
    }
    echo '<div class="notice notice-warning"><p>' .
        esc_html__('AutoPoster Companion: this site is not being served over HTTPS. The Journey To Poster Android app authenticates using WordPress Application Passwords, which require an HTTPS connection (or a recognized local development URL) to work.', 'autoposter-companion') .
        '</p></div>';
}
add_action('admin_notices', 'autoposter_companion_admin_notices');
