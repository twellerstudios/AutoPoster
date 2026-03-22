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
    flush_rewrite_rules();
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
