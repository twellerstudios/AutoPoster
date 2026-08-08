<?php
/**
 * Direct-to-print bridge + native checkout (no WooCommerce).
 *
 * The print drawer lets a guest pick sizes and quantities per selected photo,
 * then checks out with just a name + phone (+ optional email) and one of two
 * payment methods, exactly as the brief requires:
 *
 *   1. Bank Transfer — shows the studio's bank instructions on submission.
 *   2. Cash on Site / Event-Date Delivery — pay when prints are handed over.
 *
 * The catalog is bridged straight from the existing Tweller Print Engine
 * (TwellerFlow2_Prints) so pricing stays in one place; when that plugin isn't
 * present we fall back to a sensible built-in size list. Orders are stored in
 * our own tweller_event_print_orders table and never touch WooCommerce.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TEPE_Prints {

    const MAX_ORDER_ITEMS = 300;

    public static function init() {
        // no global hooks; invoked by the REST layer
    }

    public static function bridge_available() {
        return class_exists( 'TwellerFlow2_Prints' );
    }

    /** The print-store page to hand card/complex orders off to, if present. */
    public static function print_engine_url() {
        if ( self::bridge_available() && method_exists( 'TwellerFlow2_Prints', 'get_prints_page_url' ) ) {
            return TwellerFlow2_Prints::get_prints_page_url();
        }
        return home_url( '/order-prints/' );
    }

    /**
     * Print-size catalog. Bridges the base engine's active `print` products;
     * falls back to the brief's sizes (4x6, 5x7, 8x10, 11x14) plus a couple more.
     */
    public static function catalog() {
        if ( self::bridge_available() ) {
            $out = array();
            foreach ( TwellerFlow2_Prints::get_active_products() as $p ) {
                if ( ( $p['category'] ?? '' ) !== 'print' ) continue; // prints only in the event drawer
                $out[] = array(
                    'id'    => (string) $p['id'],
                    'name'  => (string) $p['name'],
                    'size'  => self::size_label( $p['width_in'] ?? 0, $p['height_in'] ?? 0 ),
                    'price' => (float) $p['price'],
                );
            }
            if ( ! empty( $out ) ) return $out;
        }
        return self::default_catalog();
    }

    public static function default_catalog() {
        $sizes = array(
            array( 4, 6, 8 ), array( 5, 7, 15 ), array( 8, 10, 45 ), array( 11, 14, 85 ),
        );
        $out = array();
        foreach ( $sizes as $s ) {
            $out[] = array(
                'id'    => 'print_' . $s[0] . 'x' . $s[1],
                'name'  => $s[0] . '×' . $s[1] . ' Print',
                'size'  => $s[0] . '×' . $s[1] . '"',
                'price' => (float) $s[2],
            );
        }
        return $out;
    }

    private static function size_label( $w, $h ) {
        $w = rtrim( rtrim( (string) $w, '0' ), '.' );
        $h = rtrim( rtrim( (string) $h, '0' ), '.' );
        return ( $w && $h ) ? ( $w . '×' . $h . '"' ) : '';
    }

    public static function get_product( $id ) {
        foreach ( self::catalog() as $p ) {
            if ( $p['id'] === $id ) return $p;
        }
        return null;
    }

    /** Bank-transfer instructions, reusing the studio's existing settings. */
    public static function bank_instructions() {
        if ( self::bridge_available() && method_exists( 'TwellerFlow2_Prints', 'get_payment_instructions' ) ) {
            $txt = TwellerFlow2_Prints::get_payment_instructions();
            if ( trim( (string) $txt ) !== '' ) return $txt;
        }
        return (string) get_option( 'tweller_flow_2_banking', '' );
    }

    public static function delivery_fee() {
        if ( self::bridge_available() && method_exists( 'TwellerFlow2_Prints', 'get_delivery_fee' ) ) {
            return (float) TwellerFlow2_Prints::get_delivery_fee();
        }
        return (float) get_option( 'tepe_delivery_fee', 40 );
    }

    // ── Order creation ──────────────────────────────────────────────────────

    /**
     * @param array $args event_id, guest, customer_name, customer_phone,
     *                    customer_email, raw_items[{upload_id,product_id,qty}],
     *                    payment_method (bank|cash), delivery_option, notes,
     *                    promo_code.
     * @return array|WP_Error
     */
    public static function create_order( $args ) {
        global $wpdb;

        $event = TEPE_Gallery::get( (int) $args['event_id'] );
        if ( ! $event ) return new WP_Error( 'no_event', 'Event not found.', array( 'status' => 404 ) );
        if ( ! get_post_meta( $event->ID, TEPE_CPT::META_ALLOW_PRINTS, true ) ) {
            return new WP_Error( 'prints_off', 'Print ordering is not available for this event.', array( 'status' => 403 ) );
        }

        $name  = sanitize_text_field( $args['customer_name'] ?? '' );
        $phone = sanitize_text_field( $args['customer_phone'] ?? '' );
        $email = sanitize_email( $args['customer_email'] ?? '' );
        if ( $name === '' || $phone === '' ) {
            return new WP_Error( 'missing_contact', 'Please provide your name and phone number.', array( 'status' => 400 ) );
        }

        $raw = $args['raw_items'] ?? array();
        if ( is_string( $raw ) ) $raw = json_decode( $raw, true );
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            return new WP_Error( 'no_items', 'Your print selection is empty.', array( 'status' => 400 ) );
        }
        if ( count( $raw ) > self::MAX_ORDER_ITEMS ) {
            return new WP_Error( 'too_many', 'That is more items than we can take in one order.', array( 'status' => 400 ) );
        }

        // Build validated line items with server-side pricing only.
        $items = array();
        $subtotal = 0.0;
        foreach ( $raw as $r ) {
            $product = self::get_product( sanitize_text_field( $r['product_id'] ?? '' ) );
            $qty     = max( 1, min( 99, (int) ( $r['qty'] ?? 1 ) ) );
            $upload_id = (int) ( $r['upload_id'] ?? 0 );
            if ( ! $product ) {
                return new WP_Error( 'bad_product', 'One of the selected print sizes is no longer available.', array( 'status' => 400 ) );
            }
            $upload = $upload_id ? TEPE_Gallery::get_upload( $upload_id ) : null;
            if ( ! $upload || (int) $upload->event_id !== (int) $event->ID ) {
                return new WP_Error( 'bad_photo', 'One of the selected photos could not be verified.', array( 'status' => 400 ) );
            }
            $line = round( $product['price'] * $qty, 2 );
            $subtotal += $line;
            $items[] = array(
                'upload_id'  => $upload_id,
                'product_id' => $product['id'],
                'name'       => $product['name'],
                'size'       => $product['size'],
                'qty'        => $qty,
                'unit'       => (float) $product['price'],
                'line'       => $line,
                'filename'   => $upload->filename,
            );
        }

        $delivery_option = sanitize_key( $args['delivery_option'] ?? 'event' );
        if ( ! in_array( $delivery_option, array( 'event', 'delivery', 'meetup' ), true ) ) $delivery_option = 'event';
        $delivery_detail = sanitize_text_field( $args['delivery_detail'] ?? '' );

        if ( $delivery_option === 'delivery' ) {
            $fee = self::delivery_fee();
            if ( $fee > 0 ) {
                $subtotal += $fee;
                $items[] = array( 'upload_id' => 0, 'product_id' => 'delivery_fee', 'name' => 'Delivery', 'size' => '', 'qty' => 1, 'unit' => $fee, 'line' => $fee, 'filename' => '' );
            }
        }

        $payment_method = sanitize_key( $args['payment_method'] ?? 'bank' );
        if ( ! in_array( $payment_method, array( 'bank', 'cash' ), true ) ) $payment_method = 'bank';

        // Promo: single-use share reward (e.g. free 4x6).
        $discount   = 0.0;
        $promo_code = strtoupper( sanitize_text_field( $args['promo_code'] ?? '' ) );
        $promo = null;
        if ( $promo_code !== '' ) {
            $promo = TEPE_Promo::validate( $promo_code, $event->ID );
            if ( is_wp_error( $promo ) ) return $promo;
            $discount = TEPE_Promo::discount_for( $promo, $items );
        }

        $total = max( 0, round( $subtotal - $discount, 2 ) );

        $order_ref = self::generate_ref();
        $now = current_time( 'mysql' );
        $ok = $wpdb->insert( TEPE_Database::table( TEPE_TABLE_ORDERS ), array(
            'order_ref'       => $order_ref,
            'event_id'        => $event->ID,
            'guest_id'        => ( ! empty( $args['guest'] ) && is_object( $args['guest'] ) ) ? (int) $args['guest']->id : null,
            'customer_name'   => $name,
            'customer_email'  => ( $email && is_email( $email ) ) ? $email : '',
            'customer_phone'  => $phone,
            'items'           => wp_json_encode( $items ),
            'subtotal'        => round( $subtotal, 2 ),
            'discount'        => round( $discount, 2 ),
            'total'           => $total,
            'payment_method'  => $payment_method,
            'delivery_option' => $delivery_option,
            'delivery_detail' => $delivery_detail,
            'promo_code'      => $promo ? $promo->code : '',
            'status'          => 'new',
            'notes'           => sanitize_textarea_field( $args['notes'] ?? '' ),
            'created_at'      => $now,
            'updated_at'      => $now,
        ) );
        if ( ! $ok ) return new WP_Error( 'save_failed', 'Could not save your order. Please try again.', array( 'status' => 500 ) );

        $order_id = (int) $wpdb->insert_id;

        if ( $promo ) TEPE_Promo::redeem( $promo->id, $order_id );
        if ( ! empty( $args['guest'] ) && is_object( $args['guest'] ) ) TEPE_Guest::bump_orders( $args['guest']->id, 1 );

        $order = self::get_order( $order_id );
        self::notify( $order, $event );

        do_action( 'tepe_print_order_created', $order_id, $event->ID );

        return array(
            'ok'                 => true,
            'order_ref'          => $order_ref,
            'subtotal'           => round( $subtotal, 2 ),
            'discount'           => round( $discount, 2 ),
            'total'              => $total,
            'payment_method'     => $payment_method,
            'bank_instructions'  => ( $payment_method === 'bank' ) ? self::bank_instructions() : '',
            'message'            => self::confirmation_message( $payment_method, $delivery_option ),
        );
    }

    private static function confirmation_message( $method, $delivery ) {
        if ( $method === 'cash' ) {
            return 'Order received! Pay cash when your prints are handed over' . ( $delivery === 'event' ? ' on the event date.' : '.' );
        }
        return 'Order received! Please complete your bank transfer using the details shown, and send us a photo of the receipt.';
    }

    public static function generate_ref() {
        return 'EVP-' . strtoupper( base_convert( time(), 10, 36 ) ) . '-' . strtoupper( wp_generate_password( 4, false, false ) );
    }

    // ── Queries ─────────────────────────────────────────────────────────────

    public static function get_order( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . TEPE_Database::table( TEPE_TABLE_ORDERS ) . " WHERE id = %d", (int) $id
        ) );
    }

    public static function get_order_by_ref( $ref ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . TEPE_Database::table( TEPE_TABLE_ORDERS ) . " WHERE order_ref = %s", $ref
        ) );
    }

    public static function list_orders( $event_id = 0 ) {
        global $wpdb;
        $table = TEPE_Database::table( TEPE_TABLE_ORDERS );
        if ( $event_id ) {
            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE event_id = %d ORDER BY created_at DESC", (int) $event_id ) );
        }
        return $wpdb->get_results( "SELECT * FROM $table ORDER BY created_at DESC LIMIT 200" );
    }

    public static function set_status( $id, $status ) {
        global $wpdb;
        $allowed = array( 'new', 'confirmed', 'printing', 'ready', 'completed', 'cancelled' );
        if ( ! in_array( $status, $allowed, true ) ) return false;
        return (bool) $wpdb->update(
            TEPE_Database::table( TEPE_TABLE_ORDERS ),
            array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => (int) $id )
        );
    }

    // ── Notifications ───────────────────────────────────────────────────────

    private static function notify( $order, $event ) {
        $studio = array_unique( array_filter( array(
            get_option( 'admin_email' ),
            'hello@twellerstudios.com',
        ) ) );

        $lines = '';
        foreach ( json_decode( $order->items, true ) ?: array() as $it ) {
            $lines .= '• ' . esc_html( $it['name'] ) . ' × ' . (int) $it['qty'] . ' — TT$ ' . number_format( (float) $it['line'], 2 ) . "\n";
        }
        $body = "New event print order {$order->order_ref}\n\n"
            . "Event: " . get_the_title( $event ) . "\n"
            . "Name: {$order->customer_name}\nPhone: {$order->customer_phone}\n"
            . ( $order->customer_email ? "Email: {$order->customer_email}\n" : '' )
            . "Payment: " . strtoupper( $order->payment_method ) . " · Fulfilment: {$order->delivery_option}\n"
            . ( $order->promo_code ? "Promo: {$order->promo_code} (−TT$ " . number_format( (float) $order->discount, 2 ) . ")\n" : '' )
            . "\n" . $lines . "\nTotal: TT$ " . number_format( (float) $order->total, 2 ) . "\n";

        wp_mail( $studio, 'Event print order — ' . $order->order_ref, $body );

        if ( $order->customer_email && is_email( $order->customer_email ) ) {
            $guest_body = "Hi {$order->customer_name},\n\nThanks for your print order ({$order->order_ref}).\n\n"
                . $lines . "\nTotal: TT$ " . number_format( (float) $order->total, 2 ) . "\n\n"
                . ( $order->payment_method === 'bank'
                    ? "Please complete your bank transfer:\n\n" . self::bank_instructions() . "\n\nThen reply with a photo of your receipt.\n"
                    : "You can pay cash when your prints are handed over.\n" )
                . "\n— Tweller Studios";
            wp_mail( $order->customer_email, 'Your print order — ' . $order->order_ref, $guest_body );
        }
    }
}
