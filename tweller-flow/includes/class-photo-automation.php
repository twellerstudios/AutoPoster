<?php
/**
 * Photo Automation — WordPress-side integration with local folder watcher
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow_Photo_Automation {

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
        add_action( 'tweller_flow_session_created', array( __CLASS__, 'on_session_created' ), 10, 2 );
    }

    public static function register_rest_routes() {
        // Local agent advances a session's stage (allow GET+POST — some hosts block POST to REST API)
        register_rest_route( 'tweller-flow/v1', '/automation/advance', array(
            'methods'  => array( 'GET', 'POST' ),
            'callback' => array( __CLASS__, 'rest_advance_stage' ),
            'permission_callback' => array( __CLASS__, 'verify_api_key' ),
        ));

        // Local agent queries sessions by date (for folder-to-session matching)
        register_rest_route( 'tweller-flow/v1', '/automation/sessions', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'rest_sessions_by_date' ),
            'permission_callback' => array( __CLASS__, 'verify_api_key' ),
        ));

        // Get automation status for a session
        register_rest_route( 'tweller-flow/v1', '/automation/status/(?P<code>[a-zA-Z0-9]+)', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'rest_get_status' ),
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
        ));
    }

    public static function verify_api_key( $request ) {
        $settings = self::get_settings();
        $api_key = $settings['api_key'] ?? '';

        if ( empty( $api_key ) ) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            return in_array( $ip, array( '127.0.0.1', '::1' ) );
        }

        $provided_key = $request->get_param( 'api_key' );
        if ( ! $provided_key ) {
            $auth_header = $request->get_header( 'Authorization' );
            if ( $auth_header && str_starts_with( $auth_header, 'Bearer ' ) ) {
                $provided_key = substr( $auth_header, 7 );
            }
        }

        return hash_equals( $api_key, $provided_key ?? '' );
    }

    public static function rest_advance_stage( $request ) {
        $session_code = sanitize_text_field( $request->get_param( 'session_code' ) );
        $target_stage = sanitize_text_field( $request->get_param( 'target_stage' ) );
        $notes        = sanitize_text_field( $request->get_param( 'notes' ) ?? '' );
        $gallery_url  = esc_url_raw( $request->get_param( 'gallery_url' ) ?? '' );
        $photo_count  = intval( $request->get_param( 'photo_count' ) ?? 0 );

        if ( ! $session_code || ! $target_stage ) {
            return new WP_Error( 'missing_params', 'session_code and target_stage are required', array( 'status' => 400 ) );
        }

        $session = TwellerFlow_Session::get_by_code( $session_code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $update_data = array();
        if ( $gallery_url ) $update_data['gallery_url'] = $gallery_url;
        if ( $photo_count )  $update_data['photo_count'] = $photo_count;
        if ( ! empty( $update_data ) ) {
            TwellerFlow_Session::update( $session->id, $update_data );
        }

        $result = TwellerFlow_Session::set_stage( $session->id, $target_stage, '[Watcher] ' . $notes );

        if ( ! $result ) {
            return new WP_Error( 'advance_failed', 'Could not set stage to ' . $target_stage, array( 'status' => 400 ) );
        }

        self::log_activity( $session->id, $target_stage, $notes );

        return rest_ensure_response( array(
            'ok'         => true,
            'session_id' => $session->id,
            'new_stage'  => $result,
        ));
    }

    public static function rest_sessions_by_date( $request ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_TABLE_SESSIONS;

        $date  = sanitize_text_field( $request->get_param( 'date' ) ?? '' );
        $range = sanitize_text_field( $request->get_param( 'range' ) ?? '' );

        if ( $range === 'recent' ) {
            $from = date( 'Y-m-d', strtotime( '-7 days' ) );
            $to   = date( 'Y-m-d', strtotime( '+7 days' ) );
            $sessions = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, tracking_code, client_name, client_email, client_phone,
                        package_type, session_date, session_time, location, members_count,
                        current_stage, folder_name
                 FROM $table
                 WHERE session_date BETWEEN %s AND %s
                 ORDER BY session_date ASC, session_time ASC",
                $from, $to
            ));
        } elseif ( $date ) {
            $sessions = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, tracking_code, client_name, client_email, client_phone,
                        package_type, session_date, session_time, location, members_count,
                        current_stage, folder_name
                 FROM $table
                 WHERE session_date = %s
                 ORDER BY session_time ASC",
                $date
            ));
        } else {
            // Return all non-delivered sessions
            $sessions = $wpdb->get_results(
                "SELECT id, tracking_code, client_name, client_email, client_phone,
                        package_type, session_date, session_time, location, members_count,
                        current_stage, folder_name
                 FROM $table
                 WHERE current_stage != 'delivered'
                 ORDER BY session_date DESC
                 LIMIT 50"
            );
        }

        return rest_ensure_response( $sessions ?: array() );
    }

    public static function rest_get_status( $request ) {
        $code = sanitize_text_field( $request['code'] );
        $session = TwellerFlow_Session::get_by_code( $code );

        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $log = self::get_activity_log( $session->id );

        return rest_ensure_response( array(
            'ok'            => true,
            'session_code'  => $code,
            'current_stage' => $session->current_stage,
            'folder_name'   => $session->folder_name,
            'photo_count'   => $session->photo_count,
            'activity_log'  => $log,
        ));
    }

    public static function on_session_created( $session_id, $data ) {
        // Nothing to do on creation for now — folder watcher handles detection
    }

    // ── Settings ─────────────────────────────────────────

    public static function get_settings() {
        return get_option( 'tweller_flow_automation', array(
            'enabled'          => false,
            'backend_url'      => 'http://localhost:3001',
            'api_key'          => '',
            'watch_dir'        => '',
            'gallery_base_url' => '',
        ));
    }

    public static function save_settings( $data ) {
        $settings = array(
            'enabled'           => ! empty( $data['automation_enabled'] ),
            'backend_url'       => esc_url_raw( $data['automation_backend_url'] ?? 'http://localhost:3001' ),
            'api_key'           => sanitize_text_field( $data['automation_api_key'] ?? '' ),
            'watch_dir'         => sanitize_text_field( $data['automation_watch_dir'] ?? '' ),
            'gallery_base_url'  => esc_url_raw( $data['automation_gallery_base_url'] ?? '' ),
        );
        update_option( 'tweller_flow_automation', $settings );
        return $settings;
    }

    // ── Activity log ─────────────────────────────────────

    public static function log_activity( $session_id, $stage, $message ) {
        $log = get_option( 'tweller_flow_automation_log', array() );

        array_unshift( $log, array(
            'session_id' => $session_id,
            'stage'      => $stage,
            'message'    => $message,
            'timestamp'  => current_time( 'mysql' ),
        ));

        $log = array_slice( $log, 0, 200 );
        update_option( 'tweller_flow_automation_log', $log );
    }

    public static function get_activity_log( $session_id = null, $limit = 50 ) {
        $log = get_option( 'tweller_flow_automation_log', array() );

        if ( $session_id ) {
            $log = array_filter( $log, function( $entry ) use ( $session_id ) {
                return $entry['session_id'] == $session_id;
            });
        }

        return array_slice( array_values( $log ), 0, $limit );
    }
}
