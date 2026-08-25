<?php
/**
 * WiPay card payments (hosted-page API) for bookings and print orders.
 *
 * Flow:
 *   1. Client clicks "Pay by card" → GET /wipay/checkout?type=&ref=
 *   2. We POST a payment request to WiPay server-side and redirect the
 *      browser to WiPay's secure hosted card page.
 *   3. WiPay redirects the browser back to GET /wipay/response with the
 *      result; on a hash-verified success we mark the entity paid and
 *      fire `tweller_wipay_paid`.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_WiPay {

    const OPT_SETTINGS  = 'tweller_flow_2_wipay';
    const OPT_PENDING   = 'tweller_wipay_pending';
    const OPT_PROCESSED = 'tweller_wipay_processed';
    const OPT_LOG       = 'tweller_wipay_log';
    const API_URL       = 'https://tt.wipayfinancial.com/plugins/payments/request';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    public static function register_rest_routes() {
        register_rest_route( 'tweller-flow-2/v1', '/wipay/checkout', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_checkout' ),
            'permission_callback' => '__return_true',
        ));
        register_rest_route( 'tweller-flow-2/v1', '/wipay/response', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_response' ),
            'permission_callback' => '__return_true',
        ));
    }

    // ── Settings ─────────────────────────────────────────

    public static function get_settings() {
        $defaults = array(
            'enabled'        => false,
            'account_number' => '8694059828',
            'api_key'        => '',
            'environment'    => 'sandbox',
            'fee_structure'  => 'customer_pay',
            'origin'         => 'TwellerBookings-WP',
        );
        $saved = get_option( self::OPT_SETTINGS, array() );
        if ( ! is_array( $saved ) ) $saved = array();
        return array_merge( $defaults, $saved );
    }

    public static function is_enabled() {
        $s = self::get_settings();
        return ! empty( $s['enabled'] )
            && trim( (string) $s['account_number'] ) !== ''
            && trim( (string) $s['api_key'] ) !== '';
    }

    /** Public checkout URL for "Pay by card" buttons. */
    public static function checkout_url( $type, $ref ) {
        $base = rest_url( 'tweller-flow-2/v1/wipay/checkout' );
        return $base . ( strpos( $base, '?' ) !== false ? '&' : '?' )
            . 'type=' . rawurlencode( $type ) . '&ref=' . rawurlencode( $ref );
    }

    /**
     * Customer fields for the WiPay request, never empty.
     *
     * WiPay's LIVE gateway is stricter than sandbox and can refuse a
     * transaction outright when required customer fields are blank. Sandbox
     * lets a missing email/phone slide; live may not. We always send a
     * name, a valid email (falling back to the studio inbox so the field is
     * never empty), and a digits-only phone when we have one — so a missing
     * field can never be the reason a live charge is declined.
     *
     * @return array{ name:string, email:string, phone?:string }
     */
    private static function customer_fields( $name, $email, $phone = '' ) {
        $out = array();
        $out['name'] = trim( (string) $name ) !== '' ? sanitize_text_field( $name ) : 'Guest Customer';

        $email = trim( (string) $email );
        if ( ! is_email( $email ) ) {
            $fallback = get_option( 'admin_email' );
            $email    = is_email( $fallback ) ? $fallback : 'no-reply@twellerstudios.com';
        }
        $out['email'] = $email;

        $phone = preg_replace( '/[^0-9+]/', '', (string) $phone );
        if ( $phone !== '' ) $out['phone'] = $phone;

        return $out;
    }

    // ── Checkout: build request, send client to WiPay ────

    public static function rest_checkout( $request ) {
        $type = sanitize_text_field( (string) $request->get_param( 'type' ) );
        $ref  = sanitize_text_field( (string) $request->get_param( 'ref' ) );

        if ( ! in_array( $type, array( 'booking', 'print' ), true ) || $ref === '' ) {
            self::redirect_with( home_url( '/' ), array( 'payment' => 'error', 'pmsg' => 'Invalid payment link.' ) );
        }

        if ( ! self::is_enabled() ) {
            self::redirect_with( self::entity_url( $type, $ref ), array( 'payment' => 'error', 'pmsg' => 'Online payments are not available right now.' ) );
        }

        $amount   = 0.0;
        $customer = array();

        if ( $type === 'booking' ) {
            $session = TwellerFlow2_Session::get_by_code( $ref );
            if ( ! $session ) {
                self::redirect_with( self::entity_url( 'booking', '' ), array( 'payment' => 'error', 'pmsg' => 'Booking not found.' ) );
            }
            if ( $session->payment_status === 'paid' ) {
                self::redirect_with( self::entity_url( 'booking', $ref ), array( 'payment' => 'error', 'pmsg' => 'This booking is already fully paid.' ) );
            }
            $amount = (float) $session->total_amount
                    - ( $session->payment_status === 'deposit' ? (float) $session->deposit_amount : 0 );
            $customer = self::customer_fields(
                $session->client_name,
                $session->client_email,
                isset( $session->client_phone ) ? $session->client_phone : ''
            );
        } else {
            if ( ! class_exists( 'TwellerFlow2_Prints' ) ) {
                self::redirect_with( home_url( '/' ), array( 'payment' => 'error', 'pmsg' => 'Print orders are not available.' ) );
            }
            global $wpdb;
            $table = $wpdb->prefix . 'tweller_print_orders';
            $order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE order_ref = %s", $ref ) );
            if ( ! $order ) {
                self::redirect_with( self::entity_url( 'print', $ref ), array( 'payment' => 'error', 'pmsg' => 'Print order not found.' ) );
            }
            if ( $order->status !== 'new' ) {
                self::redirect_with( self::entity_url( 'print', $ref ), array( 'payment' => 'error', 'pmsg' => 'This order has already been paid or processed.' ) );
            }
            $amount = (float) $order->subtotal;
            $customer = self::customer_fields(
                $order->customer_name,
                $order->customer_email,
                isset( $order->customer_phone ) ? $order->customer_phone : ''
            );
        }

        if ( $amount < 1 ) {
            self::redirect_with( self::entity_url( $type, $ref ), array( 'payment' => 'error', 'pmsg' => 'There is no balance due for online payment.' ) );
        }

        $settings = self::get_settings();
        $env      = $settings['environment'] === 'live' ? 'live' : 'sandbox';
        // WiPay's sandbox only accepts its shared test account.
        $account  = ( $env === 'sandbox' ) ? '1234567890' : preg_replace( '/\D/', '', (string) $settings['account_number'] );

        $order_id = self::build_order_id( $ref );
        self::remember_pending( $order_id, $type, $ref, $amount );

        $body = array(
            'account_number' => $account,
            'avs'            => '0',
            'country_code'   => 'TT',
            'currency'       => 'TTD',
            'environment'    => $env,
            'fee_structure'  => in_array( $settings['fee_structure'], array( 'customer_pay', 'merchant_absorb', 'split' ), true ) ? $settings['fee_structure'] : 'customer_pay',
            'method'         => 'credit_card',
            'order_id'       => $order_id,
            'origin'         => self::sanitize_origin( $settings['origin'] ),
            'response_url'   => rest_url( 'tweller-flow-2/v1/wipay/response' ),
            'total'          => number_format( $amount, 2, '.', '' ),
        );
        $body = array_merge( $body, $customer );

        $response = wp_remote_post( self::API_URL, array(
            'timeout' => 20,
            'headers' => array(
                'Accept'       => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body'    => $body,
        ));

        if ( is_wp_error( $response ) ) {
            self::log( $type, $ref, 'error', 'Request failed: ' . $response->get_error_message() );
            self::redirect_with( self::entity_url( $type, $ref ), array( 'payment' => 'error', 'pmsg' => 'Could not reach the payment gateway. Please try again.' ) );
        }

        $http = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $http >= 200 && $http < 300 && is_array( $data ) && ! empty( $data['url'] ) ) {
            self::log( $type, $ref, 'request', 'Redirected to WiPay checkout (' . $env . ', order ' . $order_id . ', TT$' . number_format( $amount, 2 ) . ')' );
            wp_redirect( esc_url_raw( $data['url'] ) );
            exit;
        }

        // Record the raw gateway reply verbatim so a live decline like
        // "[3-RA6]: … Denied." is visible in the WiPay log on the Settings
        // screen, not just a generic "gateway error".
        self::log( $type, $ref, 'error', 'HTTP ' . $http . ' raw: ' . substr( (string) wp_remote_retrieve_body( $response ), 0, 220 ) );

        $msg = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : 'Payment gateway error (HTTP ' . $http . ').';
        self::log( $type, $ref, 'error', $msg );
        self::redirect_with( self::entity_url( $type, $ref ), array( 'payment' => 'error', 'pmsg' => substr( $msg, 0, 140 ) ) );
    }

    // ── Response: verify hash, mark paid ─────────────────

    public static function rest_response( $request ) {
        $params = array();
        foreach ( array( 'status', 'transaction_id', 'order_id', 'total', 'currency', 'message', 'date', 'card', 'customer_name', 'customer_email', 'customer_phone', 'hash' ) as $key ) {
            $params[ $key ] = sanitize_text_field( (string) $request->get_param( $key ) );
        }

        $status    = strtolower( $params['status'] );
        $order_id  = $params['order_id'];
        $txn       = $params['transaction_id'];
        $total_raw = $params['total'];
        $message   = $params['message'];
        $hash      = strtolower( $params['hash'] );

        $pending_all = get_option( self::OPT_PENDING, array() );
        $pending     = ( is_array( $pending_all ) && $order_id !== '' && isset( $pending_all[ $order_id ] ) ) ? $pending_all[ $order_id ] : null;

        if ( ! $pending ) {
            self::log( 'unknown', $order_id !== '' ? $order_id : '(none)', 'error', 'Callback for unknown or expired order reference' );
            self::redirect_with( self::entity_url( 'booking', '' ), array( 'payment' => 'error', 'pmsg' => 'Payment reference not recognised. Please contact us if you were charged.' ) );
        }

        $type = $pending['type'];
        $ref  = $pending['ref'];
        $page = self::entity_url( $type, $ref );

        // Idempotency — a transaction is only ever processed once.
        $processed = get_option( self::OPT_PROCESSED, array() );
        if ( ! is_array( $processed ) ) $processed = array();
        if ( $txn !== '' && in_array( $txn, $processed, true ) ) {
            if ( $status === 'success' ) {
                self::redirect_with( $page, array( 'payment' => 'success', 'txn' => $txn ) );
            }
            self::redirect_with( $page, array( 'payment' => 'failed', 'pmsg' => substr( $message, 0, 140 ) ) );
        }

        if ( $status !== 'success' ) {
            self::log( $type, $ref, $status !== '' ? $status : 'failed', $message !== '' ? $message : 'Payment was not completed' );
            self::redirect_with( $page, array( 'payment' => 'failed', 'pmsg' => substr( $message !== '' ? $message : 'Payment was not completed.', 0, 140 ) ) );
        }

        // Success MUST carry a valid hash: md5(transaction_id . total . api_key)
        $settings = self::get_settings();
        $api_key  = (string) $settings['api_key'];
        $verified = false;

        if ( $hash !== '' && $txn !== '' && $api_key !== '' ) {
            $candidates = array_unique( array_filter( array(
                number_format( (float) $pending['amount'], 2, '.', '' ), // original requested total
                $total_raw,                                              // raw final debited total
                $total_raw !== '' ? number_format( (float) $total_raw, 2, '.', '' ) : '',
            ) ) );
            foreach ( $candidates as $cand ) {
                if ( hash_equals( md5( $txn . $cand . $api_key ), $hash ) ) {
                    $verified = true;
                    break;
                }
            }
        }

        if ( ! $verified ) {
            self::log( $type, $ref, 'unverified', 'Hash missing/mismatch for TXN ' . ( $txn !== '' ? $txn : '(none)' ) . ' — NOT marked paid' );
            self::redirect_with( $page, array( 'payment' => 'unverified', 'pmsg' => 'We could not verify this payment automatically. We will confirm it manually — please contact us if unsure.' ) );
        }

        array_unshift( $processed, $txn );
        update_option( self::OPT_PROCESSED, array_slice( $processed, 0, 200 ), false );

        $total_fmt = number_format( (float) ( $total_raw !== '' ? $total_raw : $pending['amount'] ), 2 );

        if ( $type === 'booking' ) {
            $session = TwellerFlow2_Session::get_by_code( $ref );
            if ( $session ) {
                TwellerFlow2_Session::update( $session->id, array( 'payment_status' => 'paid' ) );
                TwellerFlow2_Session::record_stage_history(
                    $session->id,
                    $session->current_stage,
                    (int) $session->current_stage_index,
                    'Paid online by card via WiPay — TXN ' . $txn . ', TT$' . $total_fmt
                );
                // Flips the Google Calendar event blue
                do_action( 'tweller_flow_2_payment_updated', $session->id );
            }
            do_action( 'tweller_wipay_paid', 'booking', $ref, $params );
        } else {
            // The prints module hooks this to mark the order paid + email.
            do_action( 'tweller_wipay_paid', 'print', $ref, $params );
        }

        self::log( $type, $ref, 'success', 'TXN ' . $txn . ' — TT$' . $total_fmt . ' verified' );
        self::redirect_with( $page, array( 'payment' => 'success', 'txn' => $txn ) );
    }

    // ── Helpers ──────────────────────────────────────────

    /**
     * Unique WiPay order_id: sanitized ref + HHMMSS, ≤48 chars,
     * starting and ending alphanumeric.
     */
    private static function build_order_id( $ref ) {
        $clean  = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $ref );
        $suffix = current_time( 'His' );
        $base   = substr( $clean, 0, 48 - strlen( $suffix ) - 1 );

        $order_id = $base . '-' . $suffix;
        $order_id = preg_replace( '/^[^A-Za-z0-9]+/', '', $order_id );
        $order_id = preg_replace( '/[^A-Za-z0-9]+$/', '', $order_id );
        if ( $order_id === '' ) {
            $order_id = 'TW-' . $suffix;
        }
        return $order_id;
    }

    /** Origin: alphanumeric/dash/underscore, ≤32 chars, alnum at both ends. */
    private static function sanitize_origin( $origin ) {
        $origin = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $origin );
        $origin = substr( $origin, 0, 32 );
        $origin = preg_replace( '/^[^A-Za-z0-9]+/', '', $origin );
        $origin = preg_replace( '/[^A-Za-z0-9]+$/', '', $origin );
        return $origin !== '' ? $origin : 'TwellerBookings-WP';
    }

    /** Persist a pending payment attempt; prune entries older than 24h. */
    private static function remember_pending( $order_id, $type, $ref, $amount ) {
        $pending = get_option( self::OPT_PENDING, array() );
        if ( ! is_array( $pending ) ) $pending = array();

        $cutoff = time() - DAY_IN_SECONDS;
        foreach ( $pending as $key => $entry ) {
            if ( empty( $entry['created'] ) || $entry['created'] < $cutoff ) {
                unset( $pending[ $key ] );
            }
        }

        $pending[ $order_id ] = array(
            'type'    => $type,
            'ref'     => $ref,
            'amount'  => round( (float) $amount, 2 ),
            'created' => time(),
        );
        update_option( self::OPT_PENDING, $pending, false );
    }

    /** The public page a customer should land back on for an entity. */
    private static function entity_url( $type, $ref ) {
        if ( $type === 'print' ) {
            // Land back on the customer's own tokenized order portal so the
            // payment banner shows against their order, not the storefront.
            if ( $ref !== '' && class_exists( 'TwellerFlow2_Prints' ) ) {
                $order = TwellerFlow2_Prints::get_order_by_ref( $ref );
                if ( $order ) {
                    $portal = TwellerFlow2_Prints::portal_url( $order );
                    if ( $portal ) return $portal;
                }
            }
            $url = get_option( 'tweller_flow_2_prints_page', '' );
            if ( ! $url && class_exists( 'TwellerFlow2_Prints' ) ) {
                $url = TwellerFlow2_Prints::get_prints_page_url();
            }
            return $url ? $url : home_url( '/' );
        }

        $url = get_option( 'tweller_flow_2_tracker_page', '' );
        if ( ! $url ) {
            return home_url( '/' );
        }
        if ( $ref !== '' ) {
            $url .= ( strpos( $url, '?' ) !== false ? '&' : '?' ) . 'code=' . rawurlencode( $ref );
        }
        return $url;
    }

    /** Append query args (encoded) and redirect the browser. Always exits. */
    private static function redirect_with( $url, $args ) {
        foreach ( $args as $key => $value ) {
            if ( $value === '' ) continue;
            $url .= ( strpos( $url, '?' ) !== false ? '&' : '?' ) . $key . '=' . rawurlencode( $value );
        }
        wp_redirect( $url );
        exit;
    }

    /** Rolling log (last 50) shown on the settings page. */
    public static function log( $type, $ref, $status, $message ) {
        $log = get_option( self::OPT_LOG, array() );
        if ( ! is_array( $log ) ) $log = array();
        array_unshift( $log, array(
            'time'    => current_time( 'mysql' ),
            'type'    => $type,
            'ref'     => $ref,
            'status'  => $status,
            'message' => substr( (string) $message, 0, 200 ),
        ));
        update_option( self::OPT_LOG, array_slice( $log, 0, 50 ), false );
    }
}
