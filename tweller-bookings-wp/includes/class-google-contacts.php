<?php
/**
 * Google Contacts sync — new bookings become contacts on the studio's
 * Google account (and from there sync to the phone automatically).
 *
 * Setup (one time, in Tweller Bookings > Settings > Google Contacts):
 *   1. Create a project at console.cloud.google.com, enable the People API.
 *   2. Create an OAuth Client ID (Web application) and add the redirect URI
 *      shown on the settings page.
 *   3. Paste the Client ID + Secret, save, then click "Connect Google".
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Google_Contacts {

    const OPT_CONFIG = 'tweller_flow_2_google_config'; // client_id, client_secret, enabled
    const OPT_AUTH   = 'tweller_flow_2_google_auth';   // refresh_token, access_token, expires_at, account_email
    const OPT_SYNCED = 'tweller_flow_2_google_synced'; // email => people resourceName

    const SCOPE = 'https://www.googleapis.com/auth/contacts';

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'maybe_handle_oauth' ) );
        add_action( 'tweller_flow_2_session_created', array( __CLASS__, 'on_session_created' ), 10, 2 );
        add_action( 'tweller_flow_2_google_contact_sync', array( __CLASS__, 'sync_session_contact' ) );
    }

    // ── Config helpers ─────────────────────────────────

    public static function get_config() {
        return get_option( self::OPT_CONFIG, array( 'client_id' => '', 'client_secret' => '', 'enabled' => false ) );
    }

    public static function is_connected() {
        $auth = get_option( self::OPT_AUTH, array() );
        return ! empty( $auth['refresh_token'] );
    }

    public static function get_account_email() {
        $auth = get_option( self::OPT_AUTH, array() );
        return $auth['account_email'] ?? '';
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
            'scope'         => self::SCOPE . ' https://www.googleapis.com/auth/userinfo.email',
            'access_type'   => 'offline',
            'prompt'        => 'consent', // ensures we get a refresh token
            'state'         => wp_create_nonce( 'tf2_google_oauth' ),
        ) );
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
    private static function get_access_token() {
        $auth = get_option( self::OPT_AUTH, array() );
        if ( empty( $auth['refresh_token'] ) ) return null;

        if ( ! empty( $auth['access_token'] ) && time() < intval( $auth['expires_at'] ?? 0 ) ) {
            return $auth['access_token'];
        }

        $config = self::get_config();
        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'timeout' => 15,
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
        update_option( self::OPT_AUTH, $auth );

        return $auth['access_token'];
    }

    // ── Booking hook ───────────────────────────────────

    /** Queue the sync in the background so booking requests stay fast */
    public static function on_session_created( $session_id, $data ) {
        $config = self::get_config();
        if ( empty( $config['enabled'] ) || ! self::is_connected() ) return;

        wp_schedule_single_event( time() + 10, 'tweller_flow_2_google_contact_sync', array( $session_id ) );
    }

    /** Create (or skip an already-synced) Google contact for a session */
    public static function sync_session_contact( $session_id ) {
        $session = TwellerFlow2_Session::get( $session_id );
        if ( ! $session || empty( $session->client_name ) ) return;

        // Skip repeat clients we've already pushed (matched by email or phone)
        $synced = get_option( self::OPT_SYNCED, array() );
        $dedupe_key = strtolower( trim( $session->client_email ?: $session->client_phone ?: '' ) );
        if ( $dedupe_key && isset( $synced[ $dedupe_key ] ) ) return;

        $token = self::get_access_token();
        if ( ! $token ) return;

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
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( $contact ),
        ) );

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $body['resourceName'] ) ) {
            if ( $dedupe_key ) {
                $synced[ $dedupe_key ] = $body['resourceName'];
                update_option( self::OPT_SYNCED, $synced );
            }
            if ( class_exists( 'TwellerFlow2_Session' ) ) {
                TwellerFlow2_Session::record_stage_history(
                    $session->id, $session->current_stage, $session->current_stage_index,
                    'Contact synced to Google Contacts'
                );
            }
        } else {
            error_log( '[Tweller Bookings] Google contact sync failed for session ' . $session_id . ': ' . wp_remote_retrieve_body( $response ) );
        }
    }
}
