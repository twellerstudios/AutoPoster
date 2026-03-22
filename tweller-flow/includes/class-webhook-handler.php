<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow_Webhook_Handler {

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( 'tweller-flow/v1', '/webhook/surecart', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_surecart' ),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Handle SureCart webhook for new orders
     */
    public static function handle_surecart( $request ) {
        $body = $request->get_json_params();

        if ( empty( $body ) ) {
            return new WP_Error( 'invalid_payload', 'Empty payload', array( 'status' => 400 ) );
        }

        // Verify webhook secret if configured
        $secret = get_option( 'tweller_flow_webhook_secret', '' );
        if ( $secret ) {
            $signature = $request->get_header( 'X-SureCart-Signature' );
            if ( ! $signature || ! self::verify_signature( $request->get_body(), $signature, $secret ) ) {
                return new WP_Error( 'unauthorized', 'Invalid signature', array( 'status' => 401 ) );
            }
        }

        // Extract order data from SureCart webhook payload
        $event_type = $body['type'] ?? '';

        if ( $event_type !== 'checkout.completed' && $event_type !== 'order.created' ) {
            return rest_ensure_response( array( 'status' => 'ignored', 'reason' => 'Event type not handled' ) );
        }

        $order = $body['data'] ?? array();
        $customer = $order['customer'] ?? array();
        $line_items = $order['line_items'] ?? array();

        // Map SureCart product to package type
        $package_type = 'mini'; // default
        $total = 0;

        foreach ( $line_items as $item ) {
            $product_name = strtolower( $item['product']['name'] ?? '' );
            $total += floatval( $item['total'] ?? 0 ) / 100; // SureCart amounts are in cents

            if ( strpos( $product_name, 'full' ) !== false ) {
                $package_type = 'full';
            } elseif ( strpos( $product_name, 'extended' ) !== false ) {
                $package_type = 'extended';
            }
        }

        // Create session
        $session_id = TwellerFlow_Session::create( array(
            'client_name'       => sanitize_text_field( $customer['name'] ?? $customer['first_name'] . ' ' . ( $customer['last_name'] ?? '' ) ),
            'client_email'      => sanitize_email( $customer['email'] ?? '' ),
            'client_phone'      => sanitize_text_field( $customer['phone'] ?? '' ),
            'package_type'      => $package_type,
            'total_amount'      => $total,
            'payment_status'    => 'paid',
            'payment_method'    => 'surecart',
            'surecart_order_id' => sanitize_text_field( $order['id'] ?? '' ),
            'notes'             => 'Auto-created from SureCart order',
        ));

        if ( $session_id ) {
            $session = TwellerFlow_Session::get( $session_id );
            return rest_ensure_response( array(
                'status'        => 'created',
                'session_id'    => $session_id,
                'tracking_code' => $session->tracking_code,
            ));
        }

        return new WP_Error( 'create_failed', 'Failed to create session', array( 'status' => 500 ) );
    }

    /**
     * Verify webhook signature
     */
    private static function verify_signature( $payload, $signature, $secret ) {
        $computed = hash_hmac( 'sha256', $payload, $secret );
        return hash_equals( $computed, $signature );
    }
}
