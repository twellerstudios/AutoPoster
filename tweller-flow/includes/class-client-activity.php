<?php
/**
 * Tweller Flow — Client Activity Tracking
 *
 * Tracks when clients view galleries and download photos.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow_Client_Activity {

    const TABLE = 'tweller_client_activity';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    public static function register_rest_routes() {
        // Log a client activity event (public — called from tracker JS)
        register_rest_route( 'tweller-flow/v1', '/gallery/(?P<code>[a-zA-Z0-9]+)/activity', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_log_activity' ),
            'permission_callback' => '__return_true',
        ));

        // Get activity for a session (admin only)
        register_rest_route( 'tweller-flow/v1', '/gallery/(?P<code>[a-zA-Z0-9]+)/activity', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_activity' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));
    }

    // ── Database ───────────────────────────────────────

    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            session_code varchar(10) NOT NULL,
            event_type varchar(30) NOT NULL,
            detail varchar(255) DEFAULT '',
            ip_address varchar(45) DEFAULT '',
            user_agent varchar(500) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY session_code (session_code),
            KEY event_type (event_type)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    // ── REST: Log Activity ────────────────────────────

    public static function rest_log_activity( $request ) {
        $code       = sanitize_text_field( $request['code'] );
        $event_type = sanitize_text_field( $request->get_param( 'event' ) );
        $detail     = sanitize_text_field( $request->get_param( 'detail' ) ?? '' );

        $allowed_events = array( 'gallery_viewed', 'photo_downloaded', 'all_downloaded', 'gallery_opened' );
        if ( ! in_array( $event_type, $allowed_events, true ) ) {
            return new WP_Error( 'invalid_event', 'Invalid event type', array( 'status' => 400 ) );
        }

        $session = TwellerFlow_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        // Rate-limit: don't log the same event type more than once per 5 minutes per IP
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $ip = self::get_client_ip();
        $recent = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE session_code = %s AND event_type = %s AND ip_address = %s AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)",
            $code, $event_type, $ip
        ));

        if ( $recent > 0 && $event_type === 'gallery_viewed' ) {
            return rest_ensure_response( array( 'ok' => true, 'duplicate' => true ) );
        }

        self::log( $session->id, $code, $event_type, $detail );

        // Update session meta for quick access
        if ( $event_type === 'gallery_viewed' || $event_type === 'gallery_opened' ) {
            $first_view = get_option( 'tweller_gallery_first_view_' . $session->id, '' );
            if ( ! $first_view ) {
                update_option( 'tweller_gallery_first_view_' . $session->id, current_time( 'mysql' ) );
            }
            update_option( 'tweller_gallery_last_view_' . $session->id, current_time( 'mysql' ) );
        }

        if ( $event_type === 'all_downloaded' || $event_type === 'photo_downloaded' ) {
            $first_dl = get_option( 'tweller_gallery_first_download_' . $session->id, '' );
            if ( ! $first_dl ) {
                update_option( 'tweller_gallery_first_download_' . $session->id, current_time( 'mysql' ) );
            }
            update_option( 'tweller_gallery_last_download_' . $session->id, current_time( 'mysql' ) );
        }

        return rest_ensure_response( array( 'ok' => true ) );
    }

    // ── REST: Get Activity (Admin) ────────────────────

    public static function rest_get_activity( $request ) {
        $code = sanitize_text_field( $request['code'] );
        $session = TwellerFlow_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $events = self::get_events( $session->id, 50 );

        return rest_ensure_response( array(
            'ok'     => true,
            'events' => $events,
        ));
    }

    // ── Internal ──────────────────────────────────────

    public static function log( $session_id, $session_code, $event_type, $detail = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $wpdb->insert( $table, array(
            'session_id'   => $session_id,
            'session_code' => $session_code,
            'event_type'   => $event_type,
            'detail'       => $detail,
            'ip_address'   => self::get_client_ip(),
            'user_agent'   => substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500 ),
            'created_at'   => current_time( 'mysql' ),
        ));
    }

    public static function get_events( $session_id, $limit = 50 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE session_id = %d ORDER BY created_at DESC LIMIT %d",
            $session_id, $limit
        ));
    }

    public static function get_summary( $session_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $views = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE session_id = %d AND event_type IN ('gallery_viewed','gallery_opened')",
            $session_id
        ));

        $downloads = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE session_id = %d AND event_type = 'photo_downloaded'",
            $session_id
        ));

        $full_downloads = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE session_id = %d AND event_type = 'all_downloaded'",
            $session_id
        ));

        $first_view     = get_option( 'tweller_gallery_first_view_' . $session_id, '' );
        $last_view      = get_option( 'tweller_gallery_last_view_' . $session_id, '' );
        $first_download = get_option( 'tweller_gallery_first_download_' . $session_id, '' );
        $last_download  = get_option( 'tweller_gallery_last_download_' . $session_id, '' );

        return array(
            'total_views'      => $views,
            'total_downloads'  => $downloads,
            'full_downloads'   => $full_downloads,
            'first_viewed'     => $first_view,
            'last_viewed'      => $last_view,
            'first_downloaded' => $first_download,
            'last_downloaded'  => $last_download,
        );
    }

    private static function get_client_ip() {
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            return sanitize_text_field( $_SERVER['HTTP_CF_CONNECTING_IP'] );
        }
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
            return sanitize_text_field( trim( $ips[0] ) );
        }
        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
    }
}
