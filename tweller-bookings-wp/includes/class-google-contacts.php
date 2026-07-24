<?php
/**
 * Google Contacts sync — new bookings become contacts on the studio's
 * Google account (and from there sync to the phone automatically).
 *
 * Also owns the shared Google OAuth machinery (tokens, scopes, rolling
 * sync log) used by TwellerFlow2_Google_Calendar.
 *
 * Setup (one time, in Tweller Bookings > Settings > Google Sync):
 *   1. Create a project at console.cloud.google.com, enable the People API
 *      and the Google Calendar API.
 *   2. Create an OAuth Client ID (Web application) and add the redirect URI
 *      shown on the settings page.
 *   3. Paste the Client ID + Secret, save, then click "Connect Google".
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Google_Contacts {

    const OPT_CONFIG = 'tweller_flow_2_google_config'; // client_id, client_secret, enabled, calendar_enabled
    const OPT_AUTH   = 'tweller_flow_2_google_auth';   // refresh_token, access_token, expires_at, account_email, scopes
    const OPT_SYNCED = 'tweller_flow_2_google_synced'; // email => people resourceName
    const OPT_LOG    = 'tweller_flow_2_google_log';    // rolling log of the last 50 sync attempts
    const OPT_STATUS = 'tweller_flow_2_google_sync_status'; // session_id => per-type sync status

    const SCOPE          = 'https://www.googleapis.com/auth/contacts';
    const SCOPE_CALENDAR = 'https://www.googleapis.com/auth/calendar.events';

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'maybe_handle_oauth' ) );
        add_action( 'tweller_flow_2_session_created', array( __CLASS__, 'on_session_created' ), 10, 2 );
        add_action( 'tweller_flow_2_google_contact_sync', array( __CLASS__, 'sync_session_contact' ) );
    }

    // ── Config helpers ─────────────────────────────────

    public static function get_config() {
        return get_option( self::OPT_CONFIG, array( 'client_id' => '', 'client_secret' => '', 'enabled' => false, 'calendar_enabled' => true ) );
    }

    public static function is_connected() {
        $auth = get_option( self::OPT_AUTH, array() );
        return ! empty( $auth['refresh_token'] );
    }

    public static function get_account_email() {
        $auth = get_option( self::OPT_AUTH, array() );
        return $auth['account_email'] ?? '';
    }

    /**
     * Whether the stored grant includes the Calendar scope. Accounts
     * connected before calendar sync existed must reconnect to grant it.
     */
    public static function is_calendar_authorized() {
        $auth = get_option( self::OPT_AUTH, array() );
        return strpos( (string) ( $auth['scopes'] ?? '' ), self::SCOPE_CALENDAR ) !== false;
    }

    public static function redirect_uri() {
        return admin_url( 'admin.php?page=tweller-flow-2-settings&tf2_google=callback' );
    }

    public static function connect_url() {
        $config = self::get_config();
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( array(
            'client_id'     => $config['client_id'],
            'redirect_uri'  => self::redirect_uri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE . ' ' . self::SCOPE_CALENDAR . ' https://www.googleapis.com/auth/userinfo.email',
            'access_type'   => 'offline',
            'prompt'        => 'consent', // ensures we get a refresh token
            'state'         => wp_create_nonce( 'tf2_google_oauth' ),
        ) );
    }

    // ── Sync log + per-session status ──────────────────

    /**
     * Append an entry to the rolling sync log (last 50 kept).
     *
     * @param string $type       'contact' or 'calendar'.
     * @param int    $session_id Session the attempt was for.
     * @param bool   $ok         Whether the attempt succeeded.
     * @param string $message    Human-readable detail (HTTP code + body on failure).
     */
    public static function log_event( $type, $session_id, $ok, $message ) {
        $log = get_option( self::OPT_LOG, array() );
        if ( ! is_array( $log ) ) $log = array();
        $log[] = array(
            'time'       => current_time( 'mysql' ),
            'type'       => $type,
            'session_id' => intval( $session_id ),
            'ok'         => (bool) $ok,
            'message'    => mb_substr( trim( (string) $message ), 0, 500 ),
        );
        update_option( self::OPT_LOG, array_slice( $log, -50 ), false );
    }

    /** Last $count log entries, newest first. */
    public static function get_log( $count = 10 ) {
        $log = get_option( self::OPT_LOG, array() );
        if ( ! is_array( $log ) ) return array();
        return array_reverse( array_slice( $log, -1 * max( 1, intval( $count ) ) ) );
    }

    /** Record the latest sync outcome for a session so failures stay visible. */
    public static function set_sync_status( $session_id, $type, $ok, $message ) {
        $status = get_option( self::OPT_STATUS, array() );
        if ( ! is_array( $status ) ) $status = array();
        if ( ! isset( $status[ $session_id ] ) || ! is_array( $status[ $session_id ] ) ) {
            $status[ $session_id ] = array();
        }
        $status[ $session_id ][ $type ] = array(
            'ok'      => (bool) $ok,
            'message' => mb_substr( trim( (string) $message ), 0, 300 ),
            'time'    => current_time( 'mysql' ),
        );
        // Keep the map from growing forever — last 100 sessions is plenty.
        if ( count( $status ) > 100 ) {
            $status = array_slice( $status, -100, null, true );
        }
        update_option( self::OPT_STATUS, $status, false );
    }

    // ── OAuth flow ─────────────────────────────────────

    public static function maybe_handle_oauth() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ( $_GET['page'] ?? '' ) !== 'tweller-flow-2-settings' ) return;
        $action = $_GET['tf2_google'] ?? '';
        if ( ! $action ) return;

        if ( $action === 'disconnect' ) {
            check_admin_referer( 'tf2_google_disconnect' );
            delete_option( self::OPT_AUTH );
            wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-settings&google=disconnected' ) );
            exit;
        }

        if ( $action === 'callback' && isset( $_GET['code'] ) ) {
            if ( ! wp_verify_nonce( $_GET['state'] ?? '', 'tf2_google_oauth' ) ) {
                wp_die( 'Invalid OAuth state.' );
            }

            $config = self::get_config();
            $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
                'timeout' => 15,
                'body'    => array(
                    'code'          => sanitize_text_field( $_GET['code'] ),
                    'client_id'     => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'redirect_uri'  => self::redirect_uri(),
                    'grant_type'    => 'authorization_code',
                ),
            ) );

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $body['refresh_token'] ) && empty( $body['access_token'] ) ) {
                wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-settings&google=error' ) );
                exit;
            }

            $auth = array(
                'refresh_token' => $body['refresh_token'] ?? '',
                'access_token'  => $body['access_token'] ?? '',
                'expires_at'    => time() + intval( $body['expires_in'] ?? 3600 ) - 60,
                'account_email' => '',
                // Scopes actually granted — used to detect connections made
                // before calendar sync existed (they need a reconnect).
                'scopes'        => sanitize_text_field( $body['scope'] ?? '' ),
            );

            // Identify which Google account was connected
            $info = wp_remote_get( 'https://www.googleapis.com/oauth2/v2/userinfo', array(
                'timeout' => 10,
                'headers' => array( 'Authorization' => 'Bearer ' . $auth['access_token'] ),
            ) );
            $info_body = json_decode( wp_remote_retrieve_body( $info ), true );
            if ( ! empty( $info_body['email'] ) ) {
                $auth['account_email'] = sanitize_email( $info_body['email'] );
            }

            update_option( self::OPT_AUTH, $auth );
            wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-settings&google=connected' ) );
            exit;
        }
    }

    /** Get a valid access token, refreshing when expired. Null if unavailable. */
    public static function get_access_token() {
        $auth = get_option( self::OPT_AUTH, array() );
        if ( empty( $auth['refresh_token'] ) ) return null;

        if ( ! empty( $auth['access_token'] ) && time() < intval( $auth['expires_at'] ?? 0 ) ) {
            return $auth['access_token'];
        }

        $config = self::get_config();
        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'timeout' => 10,
            'body'    => array(
                'refresh_token' => $auth['refresh_token'],
                'client_id'     => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'grant_type'    => 'refresh_token',
            ),
        ) );

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['access_token'] ) ) {
            return null;
        }

        $auth['access_token'] = $body['access_token'];
        $auth['expires_at']   = time() + intval( $body['expires_in'] ?? 3600 ) - 60;
        if ( ! empty( $body['scope'] ) ) {
            $auth['scopes'] = sanitize_text_field( $body['scope'] );
        }
        update_option( self::OPT_AUTH, $auth );

        return $auth['access_token'];
    }

    // ── Booking hook ───────────────────────────────────

    /**
     * Sync the contact inline — WP-Cron often never fires on low-traffic
     * hosts, so waiting for it meant contacts silently never appeared.
     * A cron retry is scheduled only when the inline attempt fails.
     */
    public static function on_session_created( $session_id, $data ) {
        $config = self::get_config();
        if ( empty( $config['enabled'] ) || ! self::is_connected() ) return;

        $ok = self::sync_session_contact( $session_id );

        if ( ! $ok && ! wp_next_scheduled( 'tweller_flow_2_google_contact_sync', array( $session_id ) ) ) {
            wp_schedule_single_event( time() + 300, 'tweller_flow_2_google_contact_sync', array( $session_id ) );
        }
    }

    /**
     * Create (or skip an already-synced) Google contact for a session.
     * Every outcome is written to the rolling log + per-session status.
     *
     * @return bool True on success (or nothing to do), false on failure.
     */
    public static function sync_session_contact( $session_id ) {
        $session = TwellerFlow2_Session::get( $session_id );
        if ( ! $session || empty( $session->client_name ) ) {
            self::log_event( 'contact', $session_id, false, 'Session not found or has no client name.' );
            return false;
        }

        // Skip repeat clients we've already pushed (matched by email or phone)
        $synced = get_option( self::OPT_SYNCED, array() );
        $dedupe_key = strtolower( trim( $session->client_email ?: $session->client_phone ?: '' ) );
        if ( $dedupe_key && isset( $synced[ $dedupe_key ] ) ) {
            $msg = 'Already in Google Contacts (' . $synced[ $dedupe_key ] . ') — skipped.';
            self::set_sync_status( $session_id, 'contact', true, $msg );
            self::log_event( 'contact', $session_id, true, $msg );
            return true;
        }

        $token = self::get_access_token();
        if ( ! $token ) {
            $msg = 'Could not get a Google access token — check Client ID/Secret and reconnect the Google account.';
            self::set_sync_status( $session_id, 'contact', false, $msg );
            self::log_event( 'contact', $session_id, false, $msg );
            return false;
        }

        $packages = get_option( 'tweller_flow_2_packages', array() );
        $pkg_name = $packages[ $session->package_type ]['name'] ?? ucfirst( (string) $session->package_type );

        $contact = array(
            'names' => array( array( 'unstructuredName' => $session->client_name ) ),
            'biographies' => array( array(
                'value' => 'Tweller Studios client — ' . $pkg_name
                    . ( $session->session_date ? ' on ' . $session->session_date : '' )
                    . ' (shoot code ' . $session->tracking_code . ')',
            ) ),
        );
        if ( ! empty( $session->client_email ) ) {
            $contact['emailAddresses'] = array( array( 'value' => $session->client_email ) );
        }
        if ( ! empty( $session->client_phone ) ) {
            $contact['phoneNumbers'] = array( array( 'value' => $session->client_phone ) );
        }

        $response = wp_remote_post( 'https://people.googleapis.com/v1/people:createContact', array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( $contact ),
        ) );

        if ( is_wp_error( $response ) ) {
            $msg = 'Request failed: ' . $response->get_error_message();
            self::set_sync_status( $session_id, 'contact', false, $msg );
            self::log_event( 'contact', $session_id, false, $msg );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );

        if ( ! empty( $body['resourceName'] ) ) {
            if ( $dedupe_key ) {
                $synced[ $dedupe_key ] = $body['resourceName'];
                update_option( self::OPT_SYNCED, $synced );
            }
            $msg = 'Contact created for ' . $session->client_name . ' (' . $body['resourceName'] . ').';
            self::set_sync_status( $session_id, 'contact', true, $msg );
            self::log_event( 'contact', $session_id, true, $msg );
            if ( class_exists( 'TwellerFlow2_Session' ) ) {
                TwellerFlow2_Session::record_stage_history(
                    $session->id, $session->current_stage, $session->current_stage_index,
                    'Contact synced to Google Contacts'
                );
            }
            return true;
        }

        $msg = 'Google People API error — HTTP ' . intval( $code ) . ': ' . mb_substr( $raw, 0, 300 );
        self::set_sync_status( $session_id, 'contact', false, $msg );
        self::log_event( 'contact', $session_id, false, $msg );
        error_log( '[Tweller Bookings] Google contact sync failed for session ' . $session_id . ': ' . $msg );
        return false;
    }
}
