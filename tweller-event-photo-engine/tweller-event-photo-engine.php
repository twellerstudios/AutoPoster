<?php
/**
 * Plugin Name: Tweller Event Photo Engine
 * Plugin URI:  https://twellerstudios.com
 * Description: Guest-sourced event galleries with QR upload, zero-signup guest tracking, direct-to-print checkout, free-tier promo branding and a social-share reward engine. A native companion module for Tweller Bookings WP — no WooCommerce, no third-party SaaS.
 * Version:     1.0.0
 * Author:      Tweller Studios
 * Author URI:  https://twellerstudios.com
 * License:     GPL v2 or later
 * Text Domain: tweller-event-photo-engine
 *
 * ---------------------------------------------------------------------------
 * ARCHITECTURE
 * ---------------------------------------------------------------------------
 * This is a standalone plugin that *integrates natively* with the existing
 * "Tweller Bookings WP" plugin (class prefix TwellerFlow2_, admin menu slug
 * `tweller-flow-2`). It reuses that plugin's Print Engine (TwellerFlow2_Prints),
 * WiPay bridge and booking lifecycle hooks when they are present, and degrades
 * gracefully when they are not — so it can be activated independently.
 *
 * It stores its data in:
 *   - CPT `tweller_event_gallery` (one event = one post)  ... event config/meta
 *   - {$prefix}tweller_event_uploads      ... every guest-uploaded photo
 *   - {$prefix}tweller_event_guests       ... no-signup device tracking
 *   - {$prefix}tweller_event_print_orders ... native guest print orders
 *   - {$prefix}tweller_event_promos       ... single-use share-reward codes
 *   - {$prefix}tweller_event_shares       ... social share action log
 *
 * NOTE ON TABLE NAMING: the brief names `wp_tweller_print_orders`, but the
 * Bookings plugin already owns `{$prefix}tweller_print_orders`. To avoid a
 * live-data collision we namespace ours as `tweller_event_print_orders`.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TEPE_VERSION', '1.0.0' );
define( 'TEPE_DB_VERSION', '1.0.0' );
define( 'TEPE_PLUGIN_FILE', __FILE__ );
define( 'TEPE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TEPE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TEPE_CPT', 'tweller_event_gallery' );

// Custom table (unprefixed) names — always resolve through TEPE_Database::table().
define( 'TEPE_TABLE_UPLOADS', 'tweller_event_uploads' );
define( 'TEPE_TABLE_GUESTS', 'tweller_event_guests' );
define( 'TEPE_TABLE_ORDERS', 'tweller_event_print_orders' );
define( 'TEPE_TABLE_PROMOS', 'tweller_event_promos' );
define( 'TEPE_TABLE_SHARES', 'tweller_event_shares' );

// Uploads live under wp-content/uploads/tweller-events/{event-slug}/
define( 'TEPE_UPLOAD_SUBDIR', 'tweller-events' );

require_once TEPE_PLUGIN_DIR . 'includes/class-tepe-database.php';
require_once TEPE_PLUGIN_DIR . 'includes/class-tepe-qr.php';
require_once TEPE_PLUGIN_DIR . 'includes/class-tepe-cpt.php';
require_once TEPE_PLUGIN_DIR . 'includes/class-tepe-gallery.php';
require_once TEPE_PLUGIN_DIR . 'includes/class-tepe-categories.php';
require_once TEPE_PLUGIN_DIR . 'includes/class-tepe-guest.php';
require_once TEPE_PLUGIN_DIR . 'includes/class-tepe-uploads.php';
require_once TEPE_PLUGIN_DIR . 'includes/class-tepe-prints.php';
require_once TEPE_PLUGIN_DIR . 'includes/class-tepe-promo.php';
require_once TEPE_PLUGIN_DIR . 'includes/class-tepe-booking-hook.php';
require_once TEPE_PLUGIN_DIR . 'includes/class-tepe-rewrite.php';
require_once TEPE_PLUGIN_DIR . 'includes/class-tepe-frontend.php';
require_once TEPE_PLUGIN_DIR . 'includes/class-tepe-rest.php';

if ( is_admin() ) {
    require_once TEPE_PLUGIN_DIR . 'admin/class-tepe-admin.php';
}

/**
 * Activation: create tables, register CPT + rewrites, then flush.
 */
function tepe_activate() {
    TEPE_Database::install();
    TEPE_CPT::register();          // so rewrite slugs exist before the flush
    TEPE_Rewrite::add_rules();
    flush_rewrite_rules();
    update_option( 'tepe_db_version', TEPE_DB_VERSION );
}
register_activation_hook( __FILE__, 'tepe_activate' );

/**
 * Deactivation: just flush rewrites. Data is preserved.
 */
function tepe_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'tepe_deactivate' );

/**
 * Self-healing upgrade: create/patch tables without a reactivation, mirroring
 * the Bookings plugin's own pattern.
 */
function tepe_maybe_upgrade() {
    if ( get_option( 'tepe_db_version', '' ) !== TEPE_DB_VERSION ) {
        TEPE_Database::install();
        update_option( 'tepe_needs_flush', 1 );
        update_option( 'tepe_db_version', TEPE_DB_VERSION );
    }
    if ( get_option( 'tepe_needs_flush' ) ) {
        flush_rewrite_rules();
        delete_option( 'tepe_needs_flush' );
    }
}
add_action( 'plugins_loaded', 'tepe_maybe_upgrade' );

/**
 * Boot all modules on init.
 */
function tepe_init() {
    TEPE_CPT::init();
    TEPE_Gallery::init();
    TEPE_Guest::init();
    TEPE_Uploads::init();
    TEPE_Prints::init();
    TEPE_Promo::init();
    TEPE_Booking_Hook::init();
    TEPE_Rewrite::init();
    TEPE_Frontend::init();
    TEPE_REST::init();

    if ( is_admin() && class_exists( 'TEPE_Admin' ) ) {
        TEPE_Admin::init();
    }
}
add_action( 'init', 'tepe_init', 5 );
