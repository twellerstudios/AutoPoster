<?php
/**
 * Google Calendar sync — booked sessions appear on the studio's primary
 * Google Calendar. Events are YELLOW while a session is only reserved
 * (payment pending / verifying / deposit) and turn BLUE once fully paid.
 *
 * Reuses the OAuth machinery in TwellerFlow2_Google_Contacts. Requires the
 * 'https://www.googleapis.com/auth/calendar.events' scope — accounts that
 * connected before calendar sync existed must reconnect Google once.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Google_Calendar {

    const EVENT_OPT_PREFIX = 'tweller_gcal_event_'; // + session_id => Google event id
    const OPT_REMINDERS    = 'tweller_flow_2_gcal_reminders';

    const API_BASE = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    const COLOR_RESERVED = '5'; // Banana (yellow)
    const COLOR_PAID     = '9'; // Blueberry (blue)

    /*
     * The studio timezone lives on TwellerFlow2_Session::timezone() so the
     * calendar events and the .ics invites can never disagree about it.
     * Filter 'tweller_flow_2_studio_timezone' to change it.
     */

    public static function init() {
        add_action( 'tweller_flow_2_session_created', array( __CLASS__, 'on_session_created' ), 20, 2 );
        add_action( 'tweller_flow_2_payment_updated', array( __CLASS__, 'on_payment_updated' ) );
        // Rescheduled, moved, repackaged — the event has to follow.
        add_action( 'tweller_flow_2_session_updated', array( __CLASS__, 'on_session_updated' ), 20 );
        add_action( 'tweller_flow_2_session_deleted', array( __CLASS__, 'on_session_deleted' ) );
        add_action( 'tweller_flow_2_google_calendar_sync', array( __CLASS__, 'sync_session' ) );
    }

    // ── State helpers ──────────────────────────────────

    /** Calendar sync toggle — defaults to on for already-connected accounts. */
    public static function is_enabled() {
        $config = TwellerFlow2_Google_Contacts::get_config();
        if ( ! array_key_exists( 'calendar_enabled', (array) $config ) ) {
            return true;
        }
        return ! empty( $config['calendar_enabled'] );
    }

    /** Connected, calendar scope granted, and the toggle is on. */
    public static function is_ready() {
        return self::is_enabled()
            && TwellerFlow2_Google_Contacts::is_connected()
            && TwellerFlow2_Google_Contacts::is_calendar_authorized();
    }

    // ── Hooks ──────────────────────────────────────────

    /** New booking — create the (yellow) hold on the calendar right away. */
    public static function on_session_created( $session_id, $data ) {
        if ( ! self::is_enabled() || ! TwellerFlow2_Google_Contacts::is_connected() ) return;
        self::sync_inline_with_retry( $session_id );
    }

    /** Payment status changed — flip color (and pick up date/time edits). */
    public static function on_payment_updated( $session_id ) {
        if ( ! self::is_enabled() || ! TwellerFlow2_Google_Contacts::is_connected() ) return;
        self::sync_inline_with_retry( $session_id );
    }

    /** Date, time, package, location or status changed — move the event. */
    public static function on_session_updated( $session_id ) {
        if ( ! self::is_enabled() || ! TwellerFlow2_Google_Contacts::is_connected() ) return;
        self::sync_inline_with_retry( $session_id );
    }

    /** Session deleted — remove its calendar event. */
    public static function on_session_deleted( $session_id ) {
        self::delete_event( $session_id );
    }

    /** Run inline (WP-Cron is unreliable) with a single cron retry on failure. */
    private static function sync_inline_with_retry( $session_id ) {
        $ok = self::sync_session( $session_id );
        if ( ! $ok && ! wp_next_scheduled( 'tweller_flow_2_google_calendar_sync', array( $session_id ) ) ) {
            wp_schedule_single_event( time() + 300, 'tweller_flow_2_google_calendar_sync', array( $session_id ) );
        }
    }

    // ── Sync ───────────────────────────────────────────

    /**
     * Back-fill: push every existing booking onto the calendar.
     *
     * New bookings sync on creation, but sessions booked before the Google
     * account was connected (or before calendar sync worked) never fired a
     * sync — so the calendar is missing history. This walks the bookings
     * that have a date, are not cancelled, and don't yet have an event, and
     * creates each. Already-synced bookings are left alone, so running it
     * twice never duplicates. It processes newest-first and stops after
     * $limit real API calls, reporting how many remain, so one click can
     * never hang the admin request on a large back-catalogue — click again
     * to continue.
     *
     * @param int  $limit Max bookings to push in this run.
     * @param bool $force  Re-push bookings that already have an event, instead
     *                     of skipping them. Needed whenever the event PAYLOAD
     *                     changes — a corrected duration, say — because the
     *                     skip below is keyed on "has an event id at all", not
     *                     on whether that event is still right.
     * @return array{ ok:bool, reason:string, synced:int, failed:int, skipped:int, remaining:int, total:int }
     */
    public static function sync_all( $limit = 25, $force = false ) {
        $out = array( 'ok' => false, 'reason' => '', 'synced' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => 0, 'total' => 0 );

        if ( ! self::is_ready() ) {
            $out['reason'] = ! self::is_enabled()
                ? 'Calendar sync is turned off.'
                : ( ! TwellerFlow2_Google_Contacts::is_connected()
                    ? 'Google account is not connected.'
                    : 'Calendar permission is missing — reconnect Google to grant it.' );
            return $out;
        }

        $limit = max( 1, (int) $limit );

        // Every booking with a date, newest first. per_page is generous —
        // the batching below, not the query, is what bounds the work.
        $sessions = TwellerFlow2_Session::get_all( array(
            'per_page' => 2000,
            'orderby'  => 'session_date',
            'order'    => 'DESC',
        ) );
        if ( ! is_array( $sessions ) ) $sessions = array();

        foreach ( $sessions as $session ) {
            // Not a real booking on the calendar's terms.
            if ( empty( $session->session_date ) || (string) $session->current_stage === 'cancelled' ) {
                $out['skipped']++;
                continue;
            }

            // Already on the calendar — leave it (idempotent), unless we are
            // deliberately re-pushing to correct events already up there.
            if ( ! $force && get_option( self::EVENT_OPT_PREFIX . $session->id, '' ) !== '' ) {
                continue;
            }

            $out['total']++;

            if ( $out['synced'] + $out['failed'] >= $limit ) {
                $out['remaining']++;
                continue;
            }

            if ( self::sync_session( (int) $session->id ) ) {
                $out['synced']++;
            } else {
                $out['failed']++;
            }
        }

        $out['ok'] = true;
        return $out;
    }

    /**
     * Create-or-update the calendar event for a session on the connected
     * account's primary calendar. Logs every outcome.
     *
     * @return bool True on success (or nothing to do), false on failure.
     */
    public static function sync_session( $session_id ) {
        $session = TwellerFlow2_Session::get( $session_id );
        if ( ! $session ) {
            TwellerFlow2_Google_Contacts::log_event( 'calendar', $session_id, false, 'Session not found.' );
            return false;
        }

        if ( empty( $session->session_date ) ) {
            $msg = 'Session has no date yet — calendar event skipped.';
            TwellerFlow2_Google_Contacts::set_sync_status( $session_id, 'calendar', true, $msg );
            TwellerFlow2_Google_Contacts::log_event( 'calendar', $session_id, true, $msg );
            return true;
        }

        if ( ! TwellerFlow2_Google_Contacts::is_connected() ) {
            $msg = 'Google account not connected.';
            TwellerFlow2_Google_Contacts::set_sync_status( $session_id, 'calendar', false, $msg );
            TwellerFlow2_Google_Contacts::log_event( 'calendar', $session_id, false, $msg );
            return false;
        }

        if ( ! TwellerFlow2_Google_Contacts::is_calendar_authorized() ) {
            $msg = 'Calendar permission missing — click "Reconnect Google" in Settings to grant calendar access.';
            TwellerFlow2_Google_Contacts::set_sync_status( $session_id, 'calendar', false, $msg );
            TwellerFlow2_Google_Contacts::log_event( 'calendar', $session_id, false, $msg );
            return false;
        }

        $token = TwellerFlow2_Google_Contacts::get_access_token();
        if ( ! $token ) {
            $msg = 'Could not get a Google access token — check Client ID/Secret and reconnect the Google account.';
            TwellerFlow2_Google_Contacts::set_sync_status( $session_id, 'calendar', false, $msg );
            TwellerFlow2_Google_Contacts::log_event( 'calendar', $session_id, false, $msg );
            return false;
        }

        $event    = self::build_event( $session );
        $event_id = get_option( self::EVENT_OPT_PREFIX . $session->id, '' );
        $is_paid  = ( $session->payment_status === 'paid' );

        if ( $event_id ) {
            // Update the existing event (color / time / details)
            $response = wp_remote_request( self::API_BASE . '/' . rawurlencode( $event_id ), array(
                'method'  => 'PATCH',
                'timeout' => 10,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'body' => wp_json_encode( $event ),
            ) );

            // Event was deleted from the calendar by hand — recreate it.
            if ( ! is_wp_error( $response ) && intval( wp_remote_retrieve_response_code( $response ) ) === 404 ) {
                delete_option( self::EVENT_OPT_PREFIX . $session->id );
                $event_id = '';
            }
        }

        if ( ! $event_id ) {
            $response = wp_remote_post( self::API_BASE, array(
                'timeout' => 10,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'body' => wp_json_encode( $event ),
            ) );
        }

        if ( is_wp_error( $response ) ) {
            $msg = 'Request failed: ' . $response->get_error_message();
            TwellerFlow2_Google_Contacts::set_sync_status( $session_id, 'calendar', false, $msg );
            TwellerFlow2_Google_Contacts::log_event( 'calendar', $session_id, false, $msg );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );

        if ( ! empty( $body['id'] ) ) {
            update_option( self::EVENT_OPT_PREFIX . $session->id, sanitize_text_field( $body['id'] ), false );
            $msg = ( $event_id ? 'Event updated' : 'Event created' )
                . ( $is_paid ? ' (blue — paid)' : ' (yellow — reserved)' )
                . ' for ' . $session->session_date
                . ( $session->session_time ? ' ' . $session->session_time : '' ) . '.';
            TwellerFlow2_Google_Contacts::set_sync_status( $session_id, 'calendar', true, $msg );
            TwellerFlow2_Google_Contacts::log_event( 'calendar', $session_id, true, $msg );
            return true;
        }

        $msg = 'Google Calendar API error — HTTP ' . intval( $code ) . ': ' . mb_substr( $raw, 0, 300 );
        TwellerFlow2_Google_Contacts::set_sync_status( $session_id, 'calendar', false, $msg );
        TwellerFlow2_Google_Contacts::log_event( 'calendar', $session_id, false, $msg );
        error_log( '[Tweller Bookings] Google calendar sync failed for session ' . $session_id . ': ' . $msg );
        return false;
    }

    // ── Reminders ──────────────────────────────────────

    /** Reminder preferences, with the studio's defaults filled in. */
    public static function reminder_settings() {
        $defaults = array(
            'enabled'        => 1,
            'method'         => 'popup',   // popup | email
            'day_before'     => 1,
            'day_before_at'  => '18:00',
            'morning_of'     => 1,
            'morning_at'     => '08:00',
            'hours_before'   => 2,         // 0 = off
            'minutes_before' => 30,        // 0 = off
        );
        $saved = get_option( self::OPT_REMINDERS, array() );
        return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
    }

    /** Same calendar day as $d, but at HH:MM. */
    private static function at_time( $d, $hhmm ) {
        $parts = explode( ':', (string) $hhmm );
        $h = max( 0, min( 23, intval( $parts[0] ?? 8 ) ) );
        $m = max( 0, min( 59, intval( $parts[1] ?? 0 ) ) );
        return $d->setTime( $h, $m, 0 );
    }

    /**
     * Google only understands "N minutes before the start". "The day before at
     * 6pm" and "that morning at 8am" are wall-clock times, so what N is depends
     * on when this particular shoot begins — an 8am reminder is 7 hours before
     * a 3pm session and already in the past for a 7am one. Each is converted
     * against this session's own start, and anything that lands at or after the
     * start is dropped rather than sent as a negative Google would reject.
     *
     * @return array|null Overrides for the API, or null to leave the calendar's
     *                    own defaults in charge.
     */
    private static function reminder_overrides( $start, $is_all_day ) {
        $s = self::reminder_settings();
        if ( empty( $s['enabled'] ) || ! $start ) return null;

        $mins = array();

        if ( ! empty( $s['day_before'] ) ) {
            $target = self::at_time( $start->modify( '-1 day' ), $s['day_before_at'] );
            $mins[] = ( $start->getTimestamp() - $target->getTimestamp() ) / 60;
        }

        // An all-day hold starts at midnight, so "that morning" and anything
        // measured in hours would fire during the night before. Only the
        // day-before reminder is meaningful for those.
        if ( ! $is_all_day ) {
            if ( ! empty( $s['morning_of'] ) ) {
                $target = self::at_time( $start, $s['morning_at'] );
                $mins[] = ( $start->getTimestamp() - $target->getTimestamp() ) / 60;
            }
            if ( intval( $s['hours_before'] ) > 0 )   $mins[] = intval( $s['hours_before'] ) * 60;
            if ( intval( $s['minutes_before'] ) > 0 ) $mins[] = intval( $s['minutes_before'] );
        }

        // Positive, inside Google's 4-week ceiling, no duplicates (a 7am shoot
        // makes "that morning at 8am" collide with or invert other entries).
        $mins = array_filter( $mins, function ( $m ) { return $m > 0 && $m <= 40320; } );
        $mins = array_values( array_unique( array_map( 'intval', $mins ) ) );
        rsort( $mins );                 // furthest out first, purely for legibility
        $mins = array_slice( $mins, 0, 5 );   // Google caps overrides at 5

        if ( ! $mins ) return null;

        $method = ( $s['method'] === 'email' ) ? 'email' : 'popup';
        $out = array();
        foreach ( $mins as $m ) {
            $out[] = array( 'method' => $method, 'minutes' => $m );
        }
        return $out;
    }

    /** Build the Google Calendar event payload for a session. */
    private static function build_event( $session ) {
        $packages = get_option( 'tweller_flow_2_packages', array() );
        $pkg_name = $packages[ $session->package_type ]['name'] ?? ucfirst( (string) $session->package_type );

        $admin_link = admin_url( 'admin.php?page=tweller-flow-2-session&id=' . intval( $session->id ) );

        $description = 'Shoot code: ' . $session->tracking_code;
        if ( ! empty( $session->client_phone ) ) {
            $description .= "\nPhone: " . $session->client_phone;
        }
        if ( ! empty( $session->location ) ) {
            $description .= "\nLocation: " . $session->location;
        }
        $description .= "\nPayment: " . $session->payment_status;
        $description .= "\nManage: " . $admin_link;

        $event = array(
            'summary'     => "\xF0\x9F\x93\xB8 " . $session->client_name . ' — ' . $pkg_name,
            'description' => $description,
            'colorId'     => ( $session->payment_status === 'paid' ) ? self::COLOR_PAID : self::COLOR_RESERVED,
        );
        if ( ! empty( $session->location ) ) {
            $event['location'] = $session->location;
        }

        $start = TwellerFlow2_Session::start_datetime( $session );

        if ( ! empty( $session->session_time ) && $start ) {
            // Length comes from the package the client actually chose. It used
            // to be guessed from keywords in the package name — one hour for
            // everything, two if the words "wedding" or "event" happened to
            // appear — so a 30-minute mini blocked an hour and a four-hour
            // package blocked two, and the calendar disagreed with the .ics
            // invite for the very same booking.
            $minutes = TwellerFlow2_Session::duration_minutes( $session );

            // One source of truth for the zone, so filtering the studio's
            // timezone moves Google and the .ics invites together.
            $tz_name = TwellerFlow2_Session::timezone()->getName();

            // Local wall-clock paired with an explicit timeZone, which is what
            // Google expects; no UTC conversion happens here on purpose.
            $event['start'] = array(
                'dateTime' => $start->format( 'Y-m-d\TH:i:s' ),
                'timeZone' => $tz_name,
            );
            $event['end'] = array(
                'dateTime' => $start->modify( '+' . $minutes . ' minutes' )->format( 'Y-m-d\TH:i:s' ),
                'timeZone' => $tz_name,
            );
        } else {
            // No time set — hold the whole day
            $event['start'] = array( 'date' => $session->session_date );
            $event['end']   = array( 'date' => date( 'Y-m-d', strtotime( $session->session_date . ' +1 day' ) ) );
        }

        $overrides = self::reminder_overrides( $start, empty( $session->session_time ) );
        $event['reminders'] = ( $overrides === null )
            ? array( 'useDefault' => true )
            : array( 'useDefault' => false, 'overrides' => $overrides );

        return $event;
    }

    /** Delete the calendar event for a session (used when a session is deleted). */
    public static function delete_event( $session_id ) {
        $event_id = get_option( self::EVENT_OPT_PREFIX . $session_id, '' );
        if ( empty( $event_id ) ) return true;

        delete_option( self::EVENT_OPT_PREFIX . $session_id );

        $token = TwellerFlow2_Google_Contacts::get_access_token();
        if ( ! $token ) {
            TwellerFlow2_Google_Contacts::log_event( 'calendar', $session_id, false, 'Could not delete event — no Google access token.' );
            return false;
        }

        $response = wp_remote_request( self::API_BASE . '/' . rawurlencode( $event_id ), array(
            'method'  => 'DELETE',
            'timeout' => 10,
            'headers' => array( 'Authorization' => 'Bearer ' . $token ),
        ) );

        if ( is_wp_error( $response ) ) {
            TwellerFlow2_Google_Contacts::log_event( 'calendar', $session_id, false, 'Event delete failed: ' . $response->get_error_message() );
            return false;
        }

        $code = intval( wp_remote_retrieve_response_code( $response ) );
        if ( $code === 204 || $code === 200 || $code === 404 || $code === 410 ) {
            TwellerFlow2_Google_Contacts::log_event( 'calendar', $session_id, true, 'Calendar event removed.' );
            return true;
        }

        TwellerFlow2_Google_Contacts::log_event( 'calendar', $session_id, false,
            'Event delete failed — HTTP ' . $code . ': ' . mb_substr( wp_remote_retrieve_body( $response ), 0, 300 ) );
        return false;
    }
}
