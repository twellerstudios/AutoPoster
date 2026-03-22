<?php
/**
 * Photo Automation — WordPress-side integration
 *
 * Handles:
 *   - REST endpoint for the Node.js pipeline to advance stages
 *   - Auto-create session folders when a new session is created
 *   - Automation settings storage
 *   - WP-Cron for polling pipeline status
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow_Photo_Automation {

    /**
     * Initialize hooks and REST endpoints.
     */
    public static function init() {
        // Register REST routes for automation callbacks
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

        // Auto-create session folders on new session
        add_action( 'tweller_flow_session_created', array( __CLASS__, 'on_session_created' ), 10, 2 );

        // Register WP-Cron for pipeline status polling
        add_action( 'tweller_flow_poll_pipeline', array( __CLASS__, 'poll_pipeline_status' ) );

        if ( ! wp_next_scheduled( 'tweller_flow_poll_pipeline' ) ) {
            wp_schedule_event( time(), 'every_five_minutes', 'tweller_flow_poll_pipeline' );
        }

        // Add custom cron interval
        add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );
    }

    /**
     * Add 5-minute cron interval.
     */
    public static function add_cron_interval( $schedules ) {
        $schedules['every_five_minutes'] = array(
            'interval' => 300,
            'display'  => 'Every 5 Minutes',
        );
        return $schedules;
    }

    /**
     * Register REST routes for automation pipeline callbacks.
     */
    public static function register_rest_routes() {
        // Endpoint for Node.js pipeline to advance a session's stage
        register_rest_route( 'tweller-flow/v1', '/automation/advance', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'rest_advance_stage' ),
            'permission_callback' => array( __CLASS__, 'verify_api_key' ),
        ));

        // Endpoint to get automation status for a session
        register_rest_route( 'tweller-flow/v1', '/automation/status/(?P<code>[a-zA-Z0-9]+)', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'rest_get_status' ),
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
        ));

        // Endpoint to trigger pipeline manually from WP admin
        register_rest_route( 'tweller-flow/v1', '/automation/trigger/(?P<code>[a-zA-Z0-9]+)', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'rest_trigger_pipeline' ),
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
        ));

        // Endpoint to approve review from WP admin
        register_rest_route( 'tweller-flow/v1', '/automation/approve/(?P<code>[a-zA-Z0-9]+)', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'rest_approve_review' ),
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
        ));

        // Endpoint for backend to query sessions by date (for photo-to-session matching)
        register_rest_route( 'tweller-flow/v1', '/automation/sessions', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'rest_sessions_by_date' ),
            'permission_callback' => array( __CLASS__, 'verify_api_key' ),
        ));
    }

    /**
     * Verify API key for automation callbacks.
     */
    public static function verify_api_key( $request ) {
        $settings = self::get_settings();
        $api_key = $settings['api_key'] ?? '';

        if ( empty( $api_key ) ) {
            // No key configured — allow if from localhost
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

    /**
     * REST: Advance a session's stage (called by Node.js pipeline).
     */
    public static function rest_advance_stage( $request ) {
        $session_code = sanitize_text_field( $request->get_param( 'session_code' ) );
        $target_stage = sanitize_text_field( $request->get_param( 'target_stage' ) );
        $notes        = sanitize_text_field( $request->get_param( 'notes' ) ?? '' );
        $gallery_url  = esc_url_raw( $request->get_param( 'gallery_url' ) ?? '' );

        if ( ! $session_code || ! $target_stage ) {
            return new WP_Error( 'missing_params', 'session_code and target_stage are required', array( 'status' => 400 ) );
        }

        $session = TwellerFlow_Session::get_by_code( $session_code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        // Update gallery URL if provided
        if ( $gallery_url ) {
            TwellerFlow_Session::update( $session->id, array( 'gallery_url' => $gallery_url ) );
        }

        // Set stage directly (pipeline controls the sequence)
        $result = TwellerFlow_Session::set_stage( $session->id, $target_stage, '[Auto] ' . $notes );

        if ( ! $result ) {
            return new WP_Error( 'advance_failed', 'Could not set stage to ' . $target_stage, array( 'status' => 400 ) );
        }

        // Log automation activity
        self::log_activity( $session->id, $target_stage, $notes );

        return rest_ensure_response( array(
            'ok'        => true,
            'session_id' => $session->id,
            'new_stage'  => $result,
            'notes'      => $notes,
        ));
    }

    /**
     * REST: Get automation status for a session.
     */
    public static function rest_get_status( $request ) {
        $code = sanitize_text_field( $request['code'] );
        $session = TwellerFlow_Session::get_by_code( $code );

        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $settings = self::get_settings();
        $backend_url = $settings['backend_url'] ?? '';

        // Try to get pipeline status from Node.js backend
        $pipeline_status = null;
        if ( $backend_url ) {
            $response = wp_remote_get( $backend_url . '/api/automation/session/' . $code, array(
                'timeout' => 5,
            ));
            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $pipeline_status = json_decode( wp_remote_retrieve_body( $response ), true );
            }
        }

        // Get automation log
        $log = self::get_activity_log( $session->id );

        return rest_ensure_response( array(
            'ok'           => true,
            'session_code' => $code,
            'current_stage' => $session->current_stage,
            'pipeline'     => $pipeline_status,
            'activity_log' => $log,
        ));
    }

    /**
     * REST: Trigger pipeline from WP admin.
     */
    public static function rest_trigger_pipeline( $request ) {
        $code = sanitize_text_field( $request['code'] );
        $settings = self::get_settings();
        $backend_url = $settings['backend_url'] ?? '';

        if ( ! $backend_url ) {
            return new WP_Error( 'not_configured', 'Backend URL not configured', array( 'status' => 400 ) );
        }

        $response = wp_remote_post( $backend_url . '/api/automation/session/' . $code . '/run', array(
            'timeout' => 10,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array() ),
        ));

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'backend_error', $response->get_error_message(), array( 'status' => 500 ) );
        }

        return rest_ensure_response( array(
            'ok'      => true,
            'message' => 'Pipeline triggered for session ' . $code,
        ));
    }

    /**
     * REST: Approve review from WP admin.
     */
    public static function rest_approve_review( $request ) {
        $code = sanitize_text_field( $request['code'] );
        $settings = self::get_settings();
        $backend_url = $settings['backend_url'] ?? '';

        if ( ! $backend_url ) {
            return new WP_Error( 'not_configured', 'Backend URL not configured', array( 'status' => 400 ) );
        }

        $response = wp_remote_post( $backend_url . '/api/automation/session/' . $code . '/approve', array(
            'timeout' => 10,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array() ),
        ));

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'backend_error', $response->get_error_message(), array( 'status' => 500 ) );
        }

        return rest_ensure_response( array(
            'ok'      => true,
            'message' => 'Review approved for session ' . $code,
        ));
    }

    /**
     * REST: Query sessions by date or recent range (for photo-to-session matching).
     * Used by the auto-import backend to find which session photos belong to.
     */
    public static function rest_sessions_by_date( $request ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_TABLE_SESSIONS;

        $date  = sanitize_text_field( $request->get_param( 'date' ) ?? '' );
        $range = sanitize_text_field( $request->get_param( 'range' ) ?? '' );

        if ( $range === 'recent' ) {
            // Return sessions from the last 7 days + next 7 days
            $from = date( 'Y-m-d', strtotime( '-7 days' ) );
            $to   = date( 'Y-m-d', strtotime( '+7 days' ) );
            $sessions = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, tracking_code, client_name, client_email, client_phone,
                        package_type, session_date, session_time, location, members_count,
                        current_stage, current_stage_index, estimated_delivery, gallery_url
                 FROM $table
                 WHERE session_date BETWEEN %s AND %s
                 ORDER BY session_date ASC, session_time ASC",
                $from, $to
            ));
        } elseif ( $date ) {
            // Return sessions for a specific date
            $sessions = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, tracking_code, client_name, client_email, client_phone,
                        package_type, session_date, session_time, location, members_count,
                        current_stage, current_stage_index, estimated_delivery, gallery_url
                 FROM $table
                 WHERE session_date = %s
                 ORDER BY session_time ASC",
                $date
            ));
        } else {
            return new WP_Error( 'missing_params', 'Provide "date" (YYYY-MM-DD) or "range=recent"', array( 'status' => 400 ) );
        }

        return rest_ensure_response( $sessions ?: array() );
    }

    /**
     * Auto-create session folders when a new session is created.
     */
    public static function on_session_created( $session_id, $data ) {
        $settings = self::get_settings();
        if ( empty( $settings['enabled'] ) || empty( $settings['backend_url'] ) ) return;

        $session = TwellerFlow_Session::get( $session_id );
        if ( ! $session ) return;

        // Call backend to create folder structure
        wp_remote_post( $settings['backend_url'] . '/api/automation/session/' . $session->tracking_code . '/folders', array(
            'timeout'  => 5,
            'blocking' => false, // Fire-and-forget
            'headers'  => array( 'Content-Type' => 'application/json' ),
            'body'     => wp_json_encode( array() ),
        ));
    }

    /**
     * Poll pipeline status for active sessions (WP-Cron).
     */
    public static function poll_pipeline_status() {
        $settings = self::get_settings();
        if ( empty( $settings['enabled'] ) || empty( $settings['backend_url'] ) ) return;

        // Get sessions in editing stages
        $editing_stages = array( 'importing', 'culling', 'ai_editing', 'edit_review', 'final_edits' );
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_TABLE_SESSIONS;
        $placeholders = implode( ',', array_fill( 0, count( $editing_stages ), '%s' ) );
        $sessions = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, tracking_code, current_stage FROM $table WHERE current_stage IN ($placeholders)",
            ...$editing_stages
        ));

        if ( empty( $sessions ) ) return;

        // Check pipeline status for each
        foreach ( $sessions as $session ) {
            $response = wp_remote_get(
                $settings['backend_url'] . '/api/automation/session/' . $session->tracking_code,
                array( 'timeout' => 5 )
            );

            if ( is_wp_error( $response ) ) continue;

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! empty( $body['pipeline']['status'] ) ) {
                self::log_activity(
                    $session->id,
                    $session->current_stage,
                    'Pipeline poll: ' . $body['pipeline']['status'] . ' — ' . ( $body['pipeline']['message'] ?? '' )
                );
            }
        }
    }

    // ── Settings ─────────────────────────────────────────────────────────────

    /**
     * Get automation settings.
     */
    public static function get_settings() {
        return get_option( 'tweller_flow_automation', array(
            'enabled'          => false,
            'backend_url'      => 'http://localhost:3001',
            'api_key'          => '',
            'imagen_api_key'   => '',
            'imagen_profile_id'=> '',
            'watch_dir'        => '',
            'gallery_base_url' => '',
            'auto_skip_review' => false,
            'cull_keep_percent'=> 40,
        ));
    }

    /**
     * Save automation settings.
     */
    public static function save_settings( $data ) {
        $settings = array(
            'enabled'           => ! empty( $data['automation_enabled'] ),
            'backend_url'       => esc_url_raw( $data['automation_backend_url'] ?? 'http://localhost:3001' ),
            'api_key'           => sanitize_text_field( $data['automation_api_key'] ?? '' ),
            'imagen_api_key'    => sanitize_text_field( $data['automation_imagen_api_key'] ?? '' ),
            'imagen_profile_id' => sanitize_text_field( $data['automation_imagen_profile_id'] ?? '' ),
            'watch_dir'         => sanitize_text_field( $data['automation_watch_dir'] ?? '' ),
            'gallery_base_url'  => esc_url_raw( $data['automation_gallery_base_url'] ?? '' ),
            'auto_skip_review'  => ! empty( $data['automation_auto_skip_review'] ),
            'cull_keep_percent' => intval( $data['automation_cull_keep_percent'] ?? 40 ),
        );
        update_option( 'tweller_flow_automation', $settings );
        return $settings;
    }

    // ── Activity log ─────────────────────────────────────────────────────────

    /**
     * Log automation activity.
     */
    public static function log_activity( $session_id, $stage, $message ) {
        $log = get_option( 'tweller_flow_automation_log', array() );

        array_unshift( $log, array(
            'session_id' => $session_id,
            'stage'      => $stage,
            'message'    => $message,
            'timestamp'  => current_time( 'mysql' ),
        ));

        // Keep last 200 entries
        $log = array_slice( $log, 0, 200 );
        update_option( 'tweller_flow_automation_log', $log );
    }

    /**
     * Get activity log, optionally filtered by session.
     */
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
