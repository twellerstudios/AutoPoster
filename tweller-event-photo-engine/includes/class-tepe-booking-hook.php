<?php
/**
 * Native integration with the Tweller Bookings lifecycle.
 *
 * When a booking is CONFIRMED in Tweller Bookings, we programmatically create
 * a matching event gallery (booking-linked, so no promo branding). We hook the
 * two signals that Bookings actually fires:
 *
 *   - `tweller_flow_2_payment_updated` ($session_id)  — fires the moment a
 *      receipt is approved / WiPay clears and the session enters the
 *      "confirmed" stage. This is the true "booking confirmed" event.
 *   - `tweller_flow_2_session_created` ($session_id, $data) — used only to
 *      pre-provision galleries for event-type packages if the studio prefers
 *      (guarded behind a setting, off by default).
 *
 * All hooks no-op cleanly when Tweller Bookings isn't installed.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TEPE_Booking_Hook {

    public static function init() {
        add_action( 'tweller_flow_2_payment_updated', array( __CLASS__, 'on_payment_updated' ), 20, 1 );
        add_action( 'tweller_flow_2_session_created', array( __CLASS__, 'on_session_created' ), 20, 2 );
    }

    /**
     * Fired when a session's payment/stage changes. We create the gallery once
     * the session has reached (or passed) the "confirmed" stage.
     */
    public static function on_payment_updated( $session_id ) {
        if ( ! class_exists( 'TwellerFlow2_Session' ) ) return;

        $session = TwellerFlow2_Session::get( $session_id );
        if ( ! $session ) return;

        // Only for confirmed-or-later sessions.
        $confirmed_stages = array( 'confirmed', 'imported', 'culling', 'culled', 'editing', 'edited', 'exporting', 'exported', 'uploading', 'uploaded', 'delivered' );
        if ( ! in_array( $session->current_stage, $confirmed_stages, true ) ) return;

        self::ensure_gallery_for_session( $session );
    }

    /**
     * Optional: pre-create galleries at booking time for event packages, so the
     * QR/upload link can go on the day-of signage before payment clears. Off
     * unless the studio flips `tepe_auto_create_on_booking` on.
     */
    public static function on_session_created( $session_id, $data ) {
        if ( ! get_option( 'tepe_auto_create_on_booking', 0 ) ) return;
        if ( ! class_exists( 'TwellerFlow2_Session' ) ) return;

        $session = TwellerFlow2_Session::get( $session_id );
        if ( ! $session ) return;

        // Restrict to event-type packages (event_1hr … event_4hr) where a
        // crowd-sourced gallery actually makes sense.
        if ( strpos( (string) $session->package_type, 'event' ) !== 0 ) return;

        self::ensure_gallery_for_session( $session );
    }

    /**
     * Create the gallery for a session if one doesn't already exist. Idempotent.
     */
    public static function ensure_gallery_for_session( $session ) {
        $existing = TEPE_Gallery::get_by_session( $session->id );
        if ( $existing ) return $existing->ID;

        $title = self::title_for( $session );

        $event_id = TEPE_Gallery::create( array(
            'title'             => $title,
            'host_email'        => $session->client_email,
            'host_name'         => $session->client_name,
            'session_id'        => (int) $session->id,
            'session_code'      => $session->tracking_code,
            'is_booking_linked' => 1,                 // paid booking → no promo branding
            'event_date'        => $session->session_date,
            'pricing_tier'      => 'client',
        ) );

        if ( is_wp_error( $event_id ) ) {
            error_log( '[TEPE] Failed to auto-create gallery for session ' . $session->id . ': ' . $event_id->get_error_message() );
            return $event_id;
        }

        do_action( 'tepe_gallery_created_from_booking', $event_id, $session );

        // Let the client know their crowd-gallery is live (best-effort, reuses
        // the Bookings notification stack when present).
        self::notify_host( $event_id, $session );

        return $event_id;
    }

    private static function title_for( $session ) {
        $name = trim( (string) $session->client_name );
        $date = $session->session_date ? date_i18n( 'F j, Y', strtotime( $session->session_date ) ) : '';
        if ( $name !== '' && $date !== '' ) return $name . ' — ' . $date;
        if ( $name !== '' ) return $name . ' Event';
        return 'Event Gallery ' . $session->tracking_code;
    }

    private static function notify_host( $event_id, $session ) {
        if ( empty( $session->client_email ) || ! is_email( $session->client_email ) ) return;

        $event = TEPE_Gallery::get( $event_id );
        if ( ! $event ) return;

        $first = trim( explode( ' ', trim( (string) $session->client_name ) )[0] );
        $intro = ( $first !== '' ? "Hi {$first}, we've set up a shared gallery for your event. " : '' )
            . 'Your guests can scan a QR code and upload their photos straight from their phones — no app, no sign-up. Everything lands in one place for you.';

        // Same branded black/gold template as the self-service path.
        TEPE_Gallery::email_host( $event, $session->client_email, $intro );
    }
}
