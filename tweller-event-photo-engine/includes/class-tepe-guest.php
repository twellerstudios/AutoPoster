<?php
/**
 * Zero-signup guest tracking.
 *
 * A guest is a *device*, not an account. We identify one by combining three
 * weak signals into one durable identity, most-durable first:
 *
 *   1. Browser fingerprint hash  — survives cookie clears, private windows
 *      re-share it, computed client-side (see public/js/tepe-fingerprint.js).
 *   2. Persistent cookie UID      — a random id we set in an httpOnly cookie
 *      and the client also mirrors in localStorage.
 *   3. Client IP (hashed)         — coarse fallback / abuse signal only.
 *
 * We never store a raw IP or a raw fingerprint — both are salted-hashed with
 * the site's auth salt, so the tracking table can't be used to re-identify a
 * person outside this system.
 *
 * Per guest we track upload counts, timestamped contributions (via the uploads
 * table) and print orders — all without a login.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TEPE_Guest {

    const COOKIE = 'tepe_guest';
    const COOKIE_TTL = 63072000; // 2 years

    public static function init() {
        // Nothing to hook globally; identity is resolved on demand in REST.
    }

    /** Salted hash — never store the raw value. */
    public static function hash( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' ) return '';
        return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) . '|tepe' );
    }

    /** Best-effort client IP behind common proxies. */
    public static function client_ip() {
        $keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
        foreach ( $keys as $k ) {
            if ( empty( $_SERVER[ $k ] ) ) continue;
            $raw = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
            $ip  = trim( explode( ',', $raw )[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
        }
        return '';
    }

    /** The cookie UID for this device, minting + setting one if absent. */
    public static function cookie_uid( $set = true ) {
        if ( ! empty( $_COOKIE[ self::COOKIE ] ) ) {
            $uid = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
            if ( preg_match( '/^[a-f0-9]{32,40}$/', $uid ) ) return $uid;
        }
        $uid = wp_generate_password( 40, false, false );
        $uid = substr( preg_replace( '/[^a-f0-9]/', '', md5( $uid . microtime() ) ) . sha1( $uid ), 0, 40 );
        if ( $set && ! headers_sent() ) {
            setcookie( self::COOKIE, $uid, array(
                'expires'  => time() + self::COOKIE_TTL,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ) );
            $_COOKIE[ self::COOKIE ] = $uid;
        }
        return $uid;
    }

    /**
     * Resolve (or create) the guest row for the current request against an
     * event. Also refreshes last_seen and any supplied contact details.
     *
     * @param int   $event_id
     * @param array $ctx  fingerprint (raw client hash string), uid (client
     *                    mirror), name, email, phone.
     * @return object guest row.
     */
    public static function identify( $event_id, $ctx = array() ) {
        global $wpdb;
        $table = TEPE_Database::table( TEPE_TABLE_GUESTS );

        $fingerprint = isset( $ctx['fingerprint'] ) ? self::hash( $ctx['fingerprint'] ) : '';
        $client_uid  = isset( $ctx['uid'] ) ? sanitize_text_field( $ctx['uid'] ) : '';
        $cookie_uid  = self::cookie_uid( true );
        $uid         = $client_uid !== '' ? $client_uid : $cookie_uid;
        $ip_hash     = self::hash( self::client_ip() );
        $ua          = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';

        // Match by fingerprint first (most durable), then cookie/client uid.
        $guest = null;
        if ( $fingerprint !== '' ) {
            $guest = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $table WHERE event_id = %d AND fingerprint_hash = %s ORDER BY id ASC LIMIT 1",
                $event_id, $fingerprint
            ) );
        }
        if ( ! $guest ) {
            $guest = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $table WHERE event_id = %d AND guest_uid = %s ORDER BY id ASC LIMIT 1",
                $event_id, $uid
            ) );
        }

        $email = isset( $ctx['email'] ) ? sanitize_email( $ctx['email'] ) : '';
        $name  = isset( $ctx['name'] ) ? sanitize_text_field( $ctx['name'] ) : '';
        $phone = isset( $ctx['phone'] ) ? sanitize_text_field( $ctx['phone'] ) : '';

        if ( $guest ) {
            $update = array( 'last_seen' => current_time( 'mysql' ), 'user_agent' => $ua );
            // Backfill identity signals we didn't have before, never blank them.
            if ( $fingerprint !== '' && $guest->fingerprint_hash === '' ) $update['fingerprint_hash'] = $fingerprint;
            if ( $uid !== '' && $guest->guest_uid === '' )               $update['guest_uid'] = $uid;
            if ( $ip_hash !== '' )                                       $update['ip_hash'] = $ip_hash;
            if ( $email !== '' && is_email( $email ) )                   $update['email'] = $email;
            if ( $name !== '' )                                          $update['display_name'] = $name;
            if ( $phone !== '' )                                         $update['phone'] = $phone;
            $wpdb->update( $table, $update, array( 'id' => $guest->id ) );
            return self::get( $guest->id );
        }

        $wpdb->insert( $table, array(
            'event_id'         => (int) $event_id,
            'guest_uid'        => $uid,
            'fingerprint_hash' => $fingerprint,
            'ip_hash'          => $ip_hash,
            'display_name'     => $name,
            'email'            => ( $email !== '' && is_email( $email ) ) ? $email : '',
            'phone'            => $phone,
            'upload_count'     => 0,
            'order_count'      => 0,
            'user_agent'       => $ua,
            'first_seen'       => current_time( 'mysql' ),
            'last_seen'        => current_time( 'mysql' ),
        ) );
        return self::get( (int) $wpdb->insert_id );
    }

    public static function get( $guest_id ) {
        global $wpdb;
        $table = TEPE_Database::table( TEPE_TABLE_GUESTS );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $guest_id ) );
    }

    public static function bump_uploads( $guest_id, $by = 1 ) {
        global $wpdb;
        $table = TEPE_Database::table( TEPE_TABLE_GUESTS );
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET upload_count = upload_count + %d, last_seen = %s WHERE id = %d",
            (int) $by, current_time( 'mysql' ), (int) $guest_id
        ) );
    }

    public static function bump_orders( $guest_id, $by = 1 ) {
        global $wpdb;
        $table = TEPE_Database::table( TEPE_TABLE_GUESTS );
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET order_count = order_count + %d WHERE id = %d",
            (int) $by, (int) $guest_id
        ) );
    }

    /** How many photos this guest has already uploaded to this event. */
    public static function upload_count( $event_id, $guest_id ) {
        global $wpdb;
        $table = TEPE_Database::table( TEPE_TABLE_UPLOADS );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE event_id = %d AND guest_id = %d",
            (int) $event_id, (int) $guest_id
        ) );
    }

    public static function list_for_event( $event_id ) {
        global $wpdb;
        $table = TEPE_Database::table( TEPE_TABLE_GUESTS );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE event_id = %d ORDER BY upload_count DESC, last_seen DESC",
            (int) $event_id
        ) );
    }
}
