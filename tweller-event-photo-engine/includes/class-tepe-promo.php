<?php
/**
 * Social-share incentive / reward engine.
 *
 * Flow:
 *   1. Guest taps "Share to Socials" on the event gallery.
 *   2. The client fires the Web Share API (or a platform intent) and, on a
 *      confirmed share, calls back to the server.
 *   3. We log the share and mint a single-use promo code for a Free 4x6 Print.
 *   4. The code is surfaced in the print checkout drawer and auto-applied.
 *
 * Codes are single-use: once attached to an order they can never be reused.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TEPE_Promo {

    const REWARD          = 'free_4x6';
    const REWARD_LABEL    = 'Free 4×6 Print';
    // One reward per guest per event within this window (anti-abuse).
    const REISSUE_WINDOW  = 86400; // 24h

    public static function init() {}

    /** Reference value of the reward = current 4×6 catalog price. */
    public static function reward_value() {
        foreach ( TEPE_Prints::catalog() as $p ) {
            if ( self::is_4x6( $p ) ) return (float) $p['price'];
        }
        return 8.0; // sensible default (TT$)
    }

    private static function is_4x6( $product ) {
        $id   = (string) ( $product['id'] ?? $product['product_id'] ?? '' );
        $size = (string) ( $product['size'] ?? '' );
        return ( stripos( $id, '4x6' ) !== false ) || ( strpos( $size, '4×6' ) === 0 ) || ( strpos( $size, '4x6' ) === 0 );
    }

    /**
     * Record a confirmed share and issue (or return the existing) reward code
     * for this guest + event. Idempotent within the reissue window so tapping
     * share twice doesn't farm codes.
     *
     * @return array { code, reward, label, value, reused }
     */
    public static function record_share( $event, $guest, $platform ) {
        global $wpdb;
        $promos = TEPE_Database::table( TEPE_TABLE_PROMOS );
        $shares = TEPE_Database::table( TEPE_TABLE_SHARES );

        $platform = sanitize_key( $platform );
        $guest_id = $guest ? (int) $guest->id : 0;

        // Reward engine can be disabled per event.
        $enabled = get_post_meta( $event->ID, TEPE_CPT::META_SHARE_REWARD, true );

        // Log the share regardless (analytics for the host).
        $wpdb->insert( $shares, array(
            'event_id'   => $event->ID,
            'guest_id'   => $guest_id ?: null,
            'platform'   => $platform,
            'ip_hash'    => TEPE_Guest::hash( TEPE_Guest::client_ip() ),
            'created_at' => current_time( 'mysql' ),
        ) );
        $share_id = (int) $wpdb->insert_id;

        if ( ! $enabled ) {
            return array( 'code' => '', 'reward' => '', 'label' => '', 'value' => 0, 'reused' => false, 'rewarded' => false );
        }

        // Return an existing unredeemed code for this guest+event if recent.
        if ( $guest_id ) {
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $promos WHERE event_id = %d AND guest_id = %d AND status = 'issued' AND created_at > %s ORDER BY id DESC LIMIT 1",
                $event->ID, $guest_id, date( 'Y-m-d H:i:s', time() - self::REISSUE_WINDOW )
            ) );
            if ( $existing ) {
                return array(
                    'code'     => $existing->code,
                    'reward'   => $existing->reward,
                    'label'    => $existing->reward_label,
                    'value'    => (float) $existing->reward_value,
                    'reused'   => true,
                    'rewarded' => true,
                );
            }
        }

        $code  = self::generate_code();
        $value = self::reward_value();
        $wpdb->insert( $promos, array(
            'code'         => $code,
            'event_id'     => $event->ID,
            'guest_id'     => $guest_id ?: null,
            'reward'       => self::REWARD,
            'reward_label' => self::REWARD_LABEL,
            'reward_value' => $value,
            'platform'     => $platform,
            'status'       => 'issued',
            'created_at'   => current_time( 'mysql' ),
        ) );
        $promo_id = (int) $wpdb->insert_id;

        if ( $share_id ) {
            $wpdb->update( $shares, array( 'promo_id' => $promo_id ), array( 'id' => $share_id ) );
        }

        do_action( 'tepe_share_rewarded', $promo_id, $event->ID, $guest_id );

        return array(
            'code'     => $code,
            'reward'   => self::REWARD,
            'label'    => self::REWARD_LABEL,
            'value'    => $value,
            'reused'   => false,
            'rewarded' => true,
        );
    }

    public static function generate_code() {
        global $wpdb;
        $promos = TEPE_Database::table( TEPE_TABLE_PROMOS );
        do {
            $code = 'TS4X6-' . strtoupper( wp_generate_password( 6, false, false ) );
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $promos WHERE code = %s", $code ) );
        } while ( $exists );
        return $code;
    }

    /**
     * Validate a code for use on an event. Returns the promo row or WP_Error.
     */
    public static function validate( $code, $event_id ) {
        global $wpdb;
        $promos = TEPE_Database::table( TEPE_TABLE_PROMOS );
        $code = strtoupper( sanitize_text_field( $code ) );

        $promo = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $promos WHERE code = %s", $code ) );
        if ( ! $promo ) {
            return new WP_Error( 'promo_invalid', 'That promo code isn’t valid.', array( 'status' => 400 ) );
        }
        if ( $promo->status !== 'issued' ) {
            return new WP_Error( 'promo_used', 'That promo code has already been used.', array( 'status' => 400 ) );
        }
        if ( $promo->event_id && (int) $promo->event_id !== (int) $event_id ) {
            return new WP_Error( 'promo_event', 'That promo code belongs to a different event.', array( 'status' => 400 ) );
        }
        return $promo;
    }

    /**
     * How much this promo takes off the given cart items. For the free 4×6 it
     * discounts the cost of one 4×6 print that's actually in the cart.
     */
    public static function discount_for( $promo, $items ) {
        if ( $promo->reward !== self::REWARD ) {
            return min( (float) $promo->reward_value, self::items_total( $items ) );
        }
        // Free 4×6: knock off one 4×6 unit price present in the cart.
        foreach ( $items as $it ) {
            if ( self::is_4x6( $it ) ) {
                return round( min( (float) $it['unit'], self::items_total( $items ) ), 2 );
            }
        }
        return 0.0; // no 4×6 in the cart → nothing to discount yet
    }

    private static function items_total( $items ) {
        $t = 0.0;
        foreach ( $items as $it ) $t += (float) $it['line'];
        return $t;
    }

    public static function redeem( $promo_id, $order_id ) {
        global $wpdb;
        $promos = TEPE_Database::table( TEPE_TABLE_PROMOS );
        return (bool) $wpdb->update( $promos, array(
            'status'      => 'redeemed',
            'order_id'    => (int) $order_id,
            'redeemed_at' => current_time( 'mysql' ),
        ), array( 'id' => (int) $promo_id, 'status' => 'issued' ) );
    }

    /** Active (issued, recent) code for a guest+event, for pre-filling checkout. */
    public static function active_for_guest( $event_id, $guest_id ) {
        if ( ! $guest_id ) return null;
        global $wpdb;
        $promos = TEPE_Database::table( TEPE_TABLE_PROMOS );
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $promos WHERE event_id = %d AND guest_id = %d AND status = 'issued' ORDER BY id DESC LIMIT 1",
            (int) $event_id, (int) $guest_id
        ) );
    }
}
