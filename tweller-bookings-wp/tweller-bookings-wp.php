<?php
/**
 * Plugin Name: Tweller Bookings WP
 * Plugin URI: https://twellerstudios.com
 * Description: Photography session workflow — booking, pipeline tracking, client proof uploads, photo selection portal, gallery delivery, and WiPay payment integration.
 * Version: 3.30.0
 * Author: Tweller Studios
 * Author URI: https://twellerstudios.com
 * License: GPL v2 or later
 * Text Domain: tweller-bookings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TWELLER_FLOW_2_VERSION', '3.30.0' );
define( 'TWELLER_FLOW_2_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TWELLER_FLOW_2_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TWELLER_FLOW_2_TABLE_SESSIONS', 'tweller_sessions' );
define( 'TWELLER_FLOW_2_TABLE_STAGE_HISTORY', 'tweller_stage_history' );
define( 'TWELLER_FLOW_2_TABLE_NOTIFICATIONS', 'tweller_notifications' );

// Include files
require_once TWELLER_FLOW_2_PLUGIN_DIR . 'includes/class-database.php';
require_once TWELLER_FLOW_2_PLUGIN_DIR . 'includes/class-session.php';
require_once TWELLER_FLOW_2_PLUGIN_DIR . 'includes/class-notifications.php';
require_once TWELLER_FLOW_2_PLUGIN_DIR . 'includes/class-tracker-shortcode.php';
require_once TWELLER_FLOW_2_PLUGIN_DIR . 'includes/class-webhook-handler.php';
require_once TWELLER_FLOW_2_PLUGIN_DIR . 'includes/class-photo-automation.php';
require_once TWELLER_FLOW_2_PLUGIN_DIR . 'includes/class-gallery.php';
require_once TWELLER_FLOW_2_PLUGIN_DIR . 'includes/class-client-activity.php';
require_once TWELLER_FLOW_2_PLUGIN_DIR . 'includes/class-culling.php';
require_once TWELLER_FLOW_2_PLUGIN_DIR . 'includes/class-booking-api.php';
require_once TWELLER_FLOW_2_PLUGIN_DIR . 'includes/class-booking-shortcode.php';
require_once TWELLER_FLOW_2_PLUGIN_DIR . 'includes/class-google-contacts.php';
require_once TWELLER_FLOW_2_PLUGIN_DIR . 'includes/class-google-calendar.php';
require_once TWELLER_FLOW_2_PLUGIN_DIR . 'includes/class-prints.php';
require_once TWELLER_FLOW_2_PLUGIN_DIR . 'includes/class-print-fulfillment.php';
require_once TWELLER_FLOW_2_PLUGIN_DIR . 'includes/class-print-providers.php';
require_once TWELLER_FLOW_2_PLUGIN_DIR . 'includes/class-wipay.php';

if ( is_admin() ) {
    require_once TWELLER_FLOW_2_PLUGIN_DIR . 'admin/class-admin.php';
}

/**
 * Plugin activation
 */
function tweller_flow_2_activate() {
    TwellerFlow2_Database::create_tables();
    TwellerFlow2_Database::seed_defaults();
    TwellerFlow2_Gallery::create_table();
    TwellerFlow2_Client_Activity::create_table();
    TwellerFlow2_Culling::create_tables();
    TwellerFlow2_Prints::create_tables();
    TwellerFlow2_Print_Providers::install();
    tweller_flow_2_ensure_tracker_page();
    tweller_flow_2_ensure_culling_page();
    flush_rewrite_rules();
}

/**
 * Create the session tracker page with [tweller_tracker] shortcode.
 */
function tweller_flow_2_ensure_tracker_page() {
    $existing_url = get_option( 'tweller_flow_2_tracker_page', '' );

    if ( $existing_url ) {
        $page_id = url_to_postid( $existing_url );
        if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
            return;
        }
    }

    $existing = get_posts( array(
        'post_type'   => 'page',
        'post_status' => 'publish',
        's'           => '[tweller_tracker]',
        'numberposts' => 1,
    ) );

    if ( ! empty( $existing ) ) {
        $page_url = get_permalink( $existing[0]->ID );
        update_option( 'tweller_flow_2_tracker_page', $page_url );
        return;
    }

    $page_id = wp_insert_post( array(
        'post_title'   => 'Session Tracker',
        'post_name'    => 'session-tracker',
        'post_content' => '[tweller_tracker]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ) );

    if ( $page_id && ! is_wp_error( $page_id ) ) {
        update_option( 'tweller_flow_2_tracker_page', get_permalink( $page_id ) );
    }
}
register_activation_hook( __FILE__, 'tweller_flow_2_activate' );

/**
 * Force upgrade settings upon load to dynamically push new stages without reactivation
 */
function tweller_flow_2_upgrade_check() {
    $db_version = get_option( 'tweller_flow_2_db_version', '1.0.0' );
    if ( version_compare( $db_version, TWELLER_FLOW_2_VERSION, '<' ) ) {
        // Redefine stages for new Confirmed feature
        $stages = array(
            'booked'     => array( 'label' => 'Reserved',     'client_label' => 'Reserved',        'icon' => 'calendar',     'notify' => true ),
            'confirmed'  => array( 'label' => 'Booking Confirmed', 'client_label' => 'Booking Confirmed', 'icon' => 'check-square', 'notify' => true ),
            'imported'   => array( 'label' => 'Imported',   'client_label' => 'Editing',       'icon' => 'download',     'notify' => false ),
            'culling'    => array( 'label' => 'Culling',    'client_label' => 'Select Photos for Editing', 'icon' => 'filter', 'notify' => false ),
            'culled'     => array( 'label' => 'Culled',     'client_label' => 'Editing',       'icon' => 'check-square', 'notify' => false ),
            'editing'    => array( 'label' => 'Editing',    'client_label' => 'Editing',       'icon' => 'edit-2',       'notify' => false ),
            'edited'     => array( 'label' => 'Edited',     'client_label' => 'Done Editing',  'icon' => 'check-circle', 'notify' => true ),
            'exporting'  => array( 'label' => 'Exporting',  'client_label' => 'Exporting',     'icon' => 'package',      'notify' => false ),
            'exported'   => array( 'label' => 'Exported',   'client_label' => 'Exporting',     'icon' => 'package',      'notify' => false ),
            'uploading'  => array( 'label' => 'Uploading',  'client_label' => 'Gallery Ready', 'icon' => 'upload',       'notify' => false ),
            'uploaded'   => array( 'label' => 'Uploaded',   'client_label' => 'Gallery Ready', 'icon' => 'upload',       'notify' => false ),
            'delivered'  => array( 'label' => 'Delivered',  'client_label' => 'Delivered',     'icon' => 'check-circle', 'notify' => true ),
        );
        $client_stages = array( 'Reserved', 'Booking Confirmed', 'Select Photos for Editing', 'Editing', 'Done Editing', 'Exporting', 'Gallery Ready', 'Delivered' );
        
        // Add star_rating column to selections table if missing
        global $wpdb;
        $sel_table = $wpdb->prefix . 'tweller_culling_selections';
        $col = $wpdb->get_results( $wpdb->prepare(
            "SHOW COLUMNS FROM `$sel_table` LIKE %s", 'star_rating'
        ));
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE `$sel_table` ADD COLUMN `star_rating` tinyint(1) DEFAULT 1 AFTER `filename`" );
        }

        // Widen code columns for friendly Shoot Codes (e.g. 04-July-2024-JohnDoe-Mini)
        $wpdb->query( "ALTER TABLE `{$wpdb->prefix}tweller_sessions` MODIFY `tracking_code` varchar(120) NOT NULL" );
        $wpdb->query( "ALTER TABLE `{$wpdb->prefix}tweller_gallery_photos` MODIFY `session_code` varchar(120) NOT NULL" );
        $wpdb->query( "ALTER TABLE `{$wpdb->prefix}tweller_culling_proofs` MODIFY `session_code` varchar(120) NOT NULL" );
        $wpdb->query( "ALTER TABLE `$sel_table` MODIFY `session_code` varchar(120) NOT NULL" );

        // Seed the review link setting (editable in Settings)
        if ( get_option( 'tweller_flow_2_review_url' ) === false ) {
            add_option( 'tweller_flow_2_review_url', 'https://g.page/r/CbntSRvzXVrSEBM/review' );
        }

        update_option( 'tweller_flow_2_stages', $stages );
        update_option( 'tweller_flow_2_client_stages', $client_stages );
        update_option( 'tweller_flow_2_db_version', TWELLER_FLOW_2_VERSION );
    }
}
add_action( 'plugins_loaded', 'tweller_flow_2_upgrade_check' );

/**
 * Create the culling portal page with [tweller_culling] shortcode.
 */
function tweller_flow_2_ensure_culling_page() {
    $existing_url = get_option( 'tweller_flow_2_culling_page', '' );

    if ( $existing_url ) {
        $page_id = url_to_postid( $existing_url );
        if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
            return;
        }
    }

    $existing = get_posts( array(
        'post_type'   => 'page',
        'post_status' => 'publish',
        's'           => '[tweller_culling]',
        'numberposts' => 1,
    ) );

    if ( ! empty( $existing ) ) {
        $page_url = get_permalink( $existing[0]->ID );
        update_option( 'tweller_flow_2_culling_page', $page_url );
        return;
    }

    $page_id = wp_insert_post( array(
        'post_title'   => 'Choose Your Photos',
        'post_name'    => 'culling-portal',
        'post_content' => '[tweller_culling]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ) );

    if ( $page_id && ! is_wp_error( $page_id ) ) {
        update_option( 'tweller_flow_2_culling_page', get_permalink( $page_id ) );
    }
}

/**
 * Plugin deactivation
 */
function tweller_flow_2_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'tweller_flow_2_deactivate' );

/**
 * Show admin notice if tracker page is missing
 */
function tweller_flow_2_admin_notice_tracker() {
    $tracker_url = get_option( 'tweller_flow_2_tracker_page', '' );
    if ( ! empty( $tracker_url ) ) return;
    tweller_flow_2_ensure_tracker_page();
    $tracker_url = get_option( 'tweller_flow_2_tracker_page', '' );
    if ( ! empty( $tracker_url ) ) return;
    echo '<div class="notice notice-warning"><p><strong>Tweller Flow:</strong> Session Tracker page missing. Create a page with <code>[tweller_tracker]</code> and set URL in <a href="' . admin_url( 'admin.php?page=tweller-flow-2-settings' ) . '">Settings</a>.</p></div>';
}
add_action( 'admin_notices', 'tweller_flow_2_admin_notice_tracker' );

/**
 * Initialize plugin
 */
function tweller_flow_2_init() {
    TwellerFlow2_Tracker_Shortcode::init();
    TwellerFlow2_Webhook_Handler::init();
    TwellerFlow2_Photo_Automation::init();
    TwellerFlow2_Gallery::init();
    TwellerFlow2_Client_Activity::init();
    TwellerFlow2_Culling::init();
    TwellerFlow2_Booking_API::init();
    TwellerFlow2_Booking_Shortcode::init();
    TwellerFlow2_Google_Contacts::init();
    TwellerFlow2_Google_Calendar::init();
    TwellerFlow2_Prints::init();
    TwellerFlow2_Print_Fulfillment::init();
    TwellerFlow2_Print_Providers::init();
    TwellerFlow2_WiPay::init();

    // Auto-upgrade: create tables if missing
    $db_version = get_option( 'tweller_flow_2_db_version', '2.1.2' );
    if ( version_compare( $db_version, '2.9.1', '<' ) ) {
        TwellerFlow2_Gallery::create_table();
        TwellerFlow2_Client_Activity::create_table();
        TwellerFlow2_Culling::create_tables();
        tweller_flow_2_ensure_culling_page();
        update_option( 'tweller_flow_2_db_version', '2.9.1' );
    }
    add_action( 'wp_enqueue_scripts', 'tweller_flow_2_public_assets' );
}
add_action( 'init', 'tweller_flow_2_init' );

/**
 * Enqueue public-facing assets
 */
function tweller_flow_2_public_assets() {
    wp_register_style(
        'tweller-flow-2-tracker',
        TWELLER_FLOW_2_PLUGIN_URL . 'public/css/tracker.css',
        array(),
        TWELLER_FLOW_2_VERSION
    );
    wp_register_script(
        'tweller-flow-2-tracker',
        TWELLER_FLOW_2_PLUGIN_URL . 'public/js/tracker.js',
        array(),
        TWELLER_FLOW_2_VERSION,
        true
    );

    wp_register_style(
        'tweller-flow-2-culling',
        TWELLER_FLOW_2_PLUGIN_URL . 'public/css/culling.css',
        array(),
        TWELLER_FLOW_2_VERSION
    );
    wp_register_script(
        'tweller-flow-2-culling',
        TWELLER_FLOW_2_PLUGIN_URL . 'public/js/culling.js',
        array(),
        TWELLER_FLOW_2_VERSION,
        true
    );
}

/**
 * Register REST API routes
 */
function tweller_flow_2_register_rest_routes() {
    register_rest_route( 'tweller-flow-2/v1', '/track/(?P<code>[a-zA-Z0-9\-]+)', array(
        'methods'  => 'GET',
        'callback' => array( 'TwellerFlow2_Session', 'rest_track' ),
        'permission_callback' => '__return_true',
    ));
    register_rest_route( 'tweller-flow-2/v1', '/sessions', array(
        'methods'  => 'GET',
        'callback' => array( 'TwellerFlow2_Session', 'rest_list' ),
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
    ));
    register_rest_route( 'tweller-flow-2/v1', '/sessions/(?P<id>\d+)/advance', array(
        'methods'  => 'POST',
        'callback' => array( 'TwellerFlow2_Session', 'rest_advance' ),
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
    ));
}
add_action( 'rest_api_init', 'tweller_flow_2_register_rest_routes' );
