<?php
/**
 * Plugin Name: Tweller Flow
 * Plugin URI: https://twellerstudios.com
 * Description: Photography session workflow management — booking tracker, client notifications, and session pipeline for Tweller Studios.
 * Version: 1.0.0
 * Author: Tweller Studios
 * Author URI: https://twellerstudios.com
 * License: GPL v2 or later
 * Text Domain: tweller-flow
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TWELLER_FLOW_VERSION', '1.0.0' );
define( 'TWELLER_FLOW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TWELLER_FLOW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TWELLER_FLOW_TABLE_SESSIONS', 'tweller_sessions' );
define( 'TWELLER_FLOW_TABLE_STAGE_HISTORY', 'tweller_stage_history' );
define( 'TWELLER_FLOW_TABLE_NOTIFICATIONS', 'tweller_notifications' );

// Include files
require_once TWELLER_FLOW_PLUGIN_DIR . 'includes/class-database.php';
require_once TWELLER_FLOW_PLUGIN_DIR . 'includes/class-session.php';
require_once TWELLER_FLOW_PLUGIN_DIR . 'includes/class-notifications.php';
require_once TWELLER_FLOW_PLUGIN_DIR . 'includes/class-tracker-shortcode.php';
require_once TWELLER_FLOW_PLUGIN_DIR . 'includes/class-webhook-handler.php';
require_once TWELLER_FLOW_PLUGIN_DIR . 'includes/class-photo-automation.php';

if ( is_admin() ) {
    require_once TWELLER_FLOW_PLUGIN_DIR . 'admin/class-admin.php';
}

/**
 * Plugin activation
 */
function tweller_flow_activate() {
    TwellerFlow_Database::create_tables();
    TwellerFlow_Database::seed_defaults();

    // Auto-create the Session Tracker page if it doesn't exist
    tweller_flow_ensure_tracker_page();

    flush_rewrite_rules();
}

/**
 * Create the session tracker page with the [tweller_tracker] shortcode.
 * Runs on activation and can be called to repair a missing page.
 */
function tweller_flow_ensure_tracker_page() {
    $existing_url = get_option( 'tweller_flow_tracker_page', '' );

    // If a tracker page URL is already configured, check the page still exists
    if ( $existing_url ) {
        $page_id = url_to_postid( $existing_url );
        if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
            return; // page exists and is published, nothing to do
        }
    }

    // Check if a page with the shortcode already exists
    $existing = get_posts( array(
        'post_type'   => 'page',
        'post_status' => 'publish',
        's'           => '[tweller_tracker]',
        'numberposts' => 1,
    ) );

    if ( ! empty( $existing ) ) {
        $page_url = get_permalink( $existing[0]->ID );
        update_option( 'tweller_flow_tracker_page', $page_url );
        return;
    }

    // Create the page
    $page_id = wp_insert_post( array(
        'post_title'   => 'Session Tracker',
        'post_name'    => 'session-tracker',
        'post_content' => '[tweller_tracker]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ) );

    if ( $page_id && ! is_wp_error( $page_id ) ) {
        $page_url = get_permalink( $page_id );
        update_option( 'tweller_flow_tracker_page', $page_url );
    }
}
register_activation_hook( __FILE__, 'tweller_flow_activate' );

/**
 * Plugin deactivation
 */
function tweller_flow_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'tweller_flow_deactivate' );

/**
 * Show admin notice if tracker page is missing
 */
function tweller_flow_admin_notice_tracker() {
    $tracker_url = get_option( 'tweller_flow_tracker_page', '' );
    if ( ! empty( $tracker_url ) ) {
        return;
    }
    // Try to auto-create it
    tweller_flow_ensure_tracker_page();
    $tracker_url = get_option( 'tweller_flow_tracker_page', '' );
    if ( ! empty( $tracker_url ) ) {
        return;
    }
    echo '<div class="notice notice-warning"><p><strong>Tweller Flow:</strong> The Session Tracker page is missing. Please create a page with the <code>[tweller_tracker]</code> shortcode and set its URL in <a href="' . admin_url( 'admin.php?page=tweller-flow-settings' ) . '">Settings</a>.</p></div>';
}
add_action( 'admin_notices', 'tweller_flow_admin_notice_tracker' );

/**
 * Initialize plugin
 */
function tweller_flow_init() {
    // Register shortcode
    TwellerFlow_Tracker_Shortcode::init();

    // Register webhook endpoint
    TwellerFlow_Webhook_Handler::init();

    // Initialize photo automation
    TwellerFlow_Photo_Automation::init();

    // Enqueue public styles/scripts
    add_action( 'wp_enqueue_scripts', 'tweller_flow_public_assets' );
}
add_action( 'init', 'tweller_flow_init' );

/**
 * Enqueue public-facing assets
 */
function tweller_flow_public_assets() {
    wp_register_style(
        'tweller-flow-tracker',
        TWELLER_FLOW_PLUGIN_URL . 'public/css/tracker.css',
        array(),
        TWELLER_FLOW_VERSION
    );
    wp_register_script(
        'tweller-flow-tracker',
        TWELLER_FLOW_PLUGIN_URL . 'public/js/tracker.js',
        array(),
        TWELLER_FLOW_VERSION,
        true
    );
}

/**
 * Register REST API routes
 */
function tweller_flow_register_rest_routes() {
    register_rest_route( 'tweller-flow/v1', '/track/(?P<code>[a-zA-Z0-9]+)', array(
        'methods'  => 'GET',
        'callback' => array( 'TwellerFlow_Session', 'rest_track' ),
        'permission_callback' => '__return_true',
    ));
    register_rest_route( 'tweller-flow/v1', '/sessions', array(
        'methods'  => 'GET',
        'callback' => array( 'TwellerFlow_Session', 'rest_list' ),
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
    ));
    register_rest_route( 'tweller-flow/v1', '/sessions/(?P<id>\d+)/advance', array(
        'methods'  => 'POST',
        'callback' => array( 'TwellerFlow_Session', 'rest_advance' ),
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
    ));
}
add_action( 'rest_api_init', 'tweller_flow_register_rest_routes' );
