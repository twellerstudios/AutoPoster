<?php
/**
 * REST API — the single surface the front-end talks to.
 *
 * Security posture:
 *   - Every state-changing route requires a valid `wp_rest` nonce (checked in
 *     require_nonce()), which the page localises for logged-out guests too.
 *   - Uploads pass through TEPE_Uploads (real MIME sniffing, size + count
 *     caps, per-guest / per-event limits).
 *   - A honeypot field + per-IP rate limiting blunt automated abuse.
 *   - Admin routes require manage_options.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TEPE_REST {

    const NS = 'tepe/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
    }

    public static function register() {
        $slug = '(?P<slug>[a-z0-9\-]+)';

        register_rest_route( self::NS, "/event/$slug/photos", array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'get_photos' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( self::NS, "/event/$slug/upload", array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'upload' ),
            'permission_callback' => array( __CLASS__, 'require_nonce' ),
        ) );

        register_rest_route( self::NS, "/event/$slug/upload-chunk", array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'upload_chunk' ),
            'permission_callback' => array( __CLASS__, 'require_nonce' ),
        ) );

        register_rest_route( self::NS, "/event/$slug/album", array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'create_album' ),
            'permission_callback' => array( __CLASS__, 'require_nonce' ),
        ) );

        register_rest_route( self::NS, "/event/$slug/share", array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'record_share' ),
            'permission_callback' => array( __CLASS__, 'require_nonce' ),
        ) );

        register_rest_route( self::NS, "/event/$slug/print-order", array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'print_order' ),
            'permission_callback' => array( __CLASS__, 'require_nonce' ),
        ) );

        register_rest_route( self::NS, '/print-catalog', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'print_catalog' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( self::NS, '/create-event', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'create_event' ),
            'permission_callback' => array( __CLASS__, 'require_nonce' ),
        ) );

        // "Email me my galleries" — privacy-safe: we email the links to the
        // address itself rather than returning other people's events to the
        // caller. Always responds generically.
        register_rest_route( self::NS, '/find-events', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'find_events' ),
            'permission_callback' => array( __CLASS__, 'require_nonce' ),
        ) );

        // ── Admin ──
        register_rest_route( self::NS, '/admin/upload/(?P<id>\d+)/(?P<action>approve|reject|delete)', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'admin_upload_action' ),
            'permission_callback' => array( __CLASS__, 'require_admin' ),
        ) );
        register_rest_route( self::NS, '/admin/order/(?P<id>\d+)/status', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'admin_order_status' ),
            'permission_callback' => array( __CLASS__, 'require_admin' ),
        ) );
    }

    // ── Permission callbacks ────────────────────────────────────────────────

    public static function require_nonce( $request ) {
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! $nonce ) $nonce = $request->get_param( '_wpnonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error( 'bad_nonce', 'Your session expired — please refresh the page and try again.', array( 'status' => 403 ) );
        }
        return true;
    }

    public static function require_admin() {
        return current_user_can( 'manage_options' );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private static function resolve_event( $request ) {
        $event = TEPE_Gallery::get_by_slug( $request['slug'] );
        if ( ! $event || $event->post_status !== 'publish' ) {
            return new WP_Error( 'no_event', 'That event gallery could not be found.', array( 'status' => 404 ) );
        }
        return $event;
    }

    private static function guest_ctx( $request ) {
        return array(
            'fingerprint' => (string) $request->get_param( 'fingerprint' ),
            'uid'         => (string) $request->get_param( 'guest_uid' ),
            'name'        => (string) $request->get_param( 'guest_name' ),
            'email'       => (string) $request->get_param( 'guest_email' ),
            'phone'       => (string) $request->get_param( 'guest_phone' ),
        );
    }

    private static function honeypot_tripped( $request ) {
        return trim( (string) $request->get_param( 'website' ) ) !== '';
    }

    private static function rate_limited( $key, $limit, $window = 3600 ) {
        $ip = TEPE_Guest::hash( TEPE_Guest::client_ip() );
        $bucket = 'tepe_rl_' . $key . '_' . substr( $ip, 0, 16 );
        $count = (int) get_transient( $bucket );
        if ( $count >= $limit ) return true;
        set_transient( $bucket, $count + 1, $window );
        return false;
    }

    // ── Read ────────────────────────────────────────────────────────────────

    public static function get_photos( $request ) {
        $event = self::resolve_event( $request );
        if ( is_wp_error( $event ) ) return $event;

        $category = $request->get_param( 'category' );
        $category = ( $category === null ) ? null : sanitize_key( $category );

        $guest = null;
        $fp = $request->get_param( 'fingerprint' );
        $uid = $request->get_param( 'guest_uid' );
        if ( $fp || $uid ) {
            $guest = TEPE_Guest::identify( $event->ID, self::guest_ctx( $request ) );
        }

        $rows   = TEPE_Gallery::get_uploads( $event->ID, array( 'status' => 'approved', 'category' => $category ) );
        $photos = array();
        foreach ( $rows as $row ) {
            $p = TEPE_Gallery::upload_payload( $event, $row );
            if ( $guest && (int) $row->guest_id === (int) $guest->id ) $p['mine'] = true;
            $photos[] = $p;
        }

        return rest_ensure_response( array(
            'ok'         => true,
            'event'      => get_the_title( $event ),
            'categories' => TEPE_Categories::get( $event->ID ),
            'photos'     => $photos,
            'count'      => count( $photos ),
        ) );
    }

    // ── Upload (simple multi-file) ──────────────────────────────────────────

    public static function upload( $request ) {
        $event = self::resolve_event( $request );
        if ( is_wp_error( $event ) ) return $event;
        if ( self::honeypot_tripped( $request ) ) {
            return new WP_Error( 'invalid', 'Invalid submission.', array( 'status' => 400 ) );
        }
        if ( self::rate_limited( 'upload', 40 ) ) {
            return new WP_Error( 'rate', 'Too many uploads from your connection — please wait a little.', array( 'status' => 429 ) );
        }

        $files_param = $request->get_file_params();
        if ( empty( $files_param['photos'] ) ) {
            return new WP_Error( 'no_files', 'No photos were received.', array( 'status' => 400 ) );
        }
        $files = TEPE_Uploads::normalize_files_array( $files_param['photos'] );
        if ( count( $files ) > TEPE_Uploads::MAX_FILES_PER_REQUEST ) {
            return new WP_Error( 'too_many', 'Please upload up to ' . TEPE_Uploads::MAX_FILES_PER_REQUEST . ' photos at a time.', array( 'status' => 400 ) );
        }

        $guest = TEPE_Guest::identify( $event->ID, self::guest_ctx( $request ) );
        $gate  = TEPE_Uploads::can_upload( $event, $guest, count( $files ) );
        if ( is_wp_error( $gate ) ) return $gate;

        $category = sanitize_key( (string) $request->get_param( 'category' ) );
        $captions = $request->get_param( 'captions' );
        if ( is_string( $captions ) ) $captions = json_decode( $captions, true );
        if ( ! is_array( $captions ) ) $captions = array();

        $result = TEPE_Uploads::handle_files( $event, $guest, $files, $category, $captions );

        return rest_ensure_response( array(
            'ok'     => true,
            'saved'  => $result['saved'],
            'errors' => $result['errors'],
            'guest_uid' => $guest->guest_uid,
        ) );
    }

    // ── Upload (chunked) ─────────────────────────────────────────────────────

    public static function upload_chunk( $request ) {
        $event = self::resolve_event( $request );
        if ( is_wp_error( $event ) ) return $event;
        if ( self::rate_limited( 'chunk', 400 ) ) {
            return new WP_Error( 'rate', 'Too many requests — please slow down.', array( 'status' => 429 ) );
        }

        $files = $request->get_file_params();
        if ( empty( $files['chunk'] ) ) {
            return new WP_Error( 'no_chunk', 'Chunk not received.', array( 'status' => 400 ) );
        }

        $guest = TEPE_Guest::identify( $event->ID, self::guest_ctx( $request ) );

        // Only gate on the first chunk so a multi-chunk file counts once.
        if ( (int) $request->get_param( 'index' ) === 0 ) {
            $gate = TEPE_Uploads::can_upload( $event, $guest, 1 );
            if ( is_wp_error( $gate ) ) return $gate;
        }

        $result = TEPE_Uploads::handle_chunk( $event, $guest, array(
            'upload_id' => (string) $request->get_param( 'upload_id' ),
            'index'     => (int) $request->get_param( 'index' ),
            'total'     => (int) $request->get_param( 'total' ),
            'filename'  => (string) $request->get_param( 'filename' ),
            'category'  => (string) $request->get_param( 'category' ),
            'caption'   => (string) $request->get_param( 'caption' ),
        ), $files['chunk'] );

        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( array_merge( array( 'ok' => true, 'guest_uid' => $guest->guest_uid ), $result ) );
    }

    // ── Guest sub-albums ─────────────────────────────────────────────────────

    public static function create_album( $request ) {
        $event = self::resolve_event( $request );
        if ( is_wp_error( $event ) ) return $event;

        $slug = TEPE_Categories::add_guest_album( $event->ID, (string) $request->get_param( 'label' ) );
        if ( is_wp_error( $slug ) ) return $slug;

        return rest_ensure_response( array(
            'ok'         => true,
            'slug'       => $slug,
            'categories' => TEPE_Categories::get( $event->ID ),
        ) );
    }

    // ── Social share reward ──────────────────────────────────────────────────

    public static function record_share( $request ) {
        $event = self::resolve_event( $request );
        if ( is_wp_error( $event ) ) return $event;
        if ( self::rate_limited( 'share', 20 ) ) {
            return new WP_Error( 'rate', 'Too many shares — please try again later.', array( 'status' => 429 ) );
        }

        $guest  = TEPE_Guest::identify( $event->ID, self::guest_ctx( $request ) );
        $reward = TEPE_Promo::record_share( $event, $guest, (string) $request->get_param( 'platform' ) );

        return rest_ensure_response( array_merge( array( 'ok' => true ), $reward ) );
    }

    // ── Print orders ─────────────────────────────────────────────────────────

    public static function print_order( $request ) {
        $event = self::resolve_event( $request );
        if ( is_wp_error( $event ) ) return $event;
        if ( self::honeypot_tripped( $request ) ) {
            return new WP_Error( 'invalid', 'Invalid submission.', array( 'status' => 400 ) );
        }
        if ( self::rate_limited( 'order', 15 ) ) {
            return new WP_Error( 'rate', 'Too many orders from your connection — please wait a little.', array( 'status' => 429 ) );
        }

        $guest = TEPE_Guest::identify( $event->ID, self::guest_ctx( $request ) );

        $result = TEPE_Prints::create_order( array(
            'event_id'        => $event->ID,
            'guest'           => $guest,
            'customer_name'   => $request->get_param( 'customer_name' ),
            'customer_phone'  => $request->get_param( 'customer_phone' ),
            'customer_email'  => $request->get_param( 'customer_email' ),
            'raw_items'       => $request->get_param( 'items' ),
            'payment_method'  => $request->get_param( 'payment_method' ),
            'delivery_option' => $request->get_param( 'delivery_option' ),
            'delivery_detail' => $request->get_param( 'delivery_detail' ),
            'promo_code'      => $request->get_param( 'promo_code' ),
            'notes'           => $request->get_param( 'notes' ),
        ) );

        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( $result );
    }

    public static function print_catalog( $request ) {
        return rest_ensure_response( array(
            'ok'               => true,
            'currency'         => 'TT$',
            'products'         => TEPE_Prints::catalog(),
            'delivery_fee'     => TEPE_Prints::delivery_fee(),
            'bank_instructions'=> TEPE_Prints::bank_instructions(),
            'print_engine_url' => TEPE_Prints::print_engine_url(),
        ) );
    }

    // ── Self-service event creation ──────────────────────────────────────────

    public static function create_event( $request ) {
        if ( self::honeypot_tripped( $request ) ) {
            return new WP_Error( 'invalid', 'Invalid submission.', array( 'status' => 400 ) );
        }
        if ( ! get_option( 'tepe_allow_self_service', 1 ) ) {
            return new WP_Error( 'disabled', 'Self-service event creation is currently closed.', array( 'status' => 403 ) );
        }
        if ( self::rate_limited( 'create', 5, 3600 ) ) {
            return new WP_Error( 'rate', 'Too many events created from your connection. Please try again later.', array( 'status' => 429 ) );
        }

        $email = sanitize_email( (string) $request->get_param( 'host_email' ) );
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'bad_email', 'Please enter a valid email address.', array( 'status' => 400 ) );
        }

        $cats = $request->get_param( 'categories' );
        if ( is_string( $cats ) ) $cats = array_filter( array_map( 'trim', explode( ',', $cats ) ) );

        $event_id = TEPE_Gallery::create( array(
            'title'             => (string) $request->get_param( 'title' ),
            'host_email'        => $email,
            'host_name'         => (string) $request->get_param( 'host_name' ),
            'event_date'        => (string) $request->get_param( 'event_date' ),
            'welcome'           => (string) $request->get_param( 'welcome' ),
            'is_booking_linked' => 0,           // self-service → free tier w/ promo branding
            'pricing_tier'      => 'free',
            'categories'        => is_array( $cats ) ? $cats : array(),
        ) );
        if ( is_wp_error( $event_id ) ) return $event_id;

        $event = TEPE_Gallery::get( $event_id );
        self::email_host_links( $event, $email );

        return rest_ensure_response( array(
            'ok'          => true,
            'event_id'    => $event_id,
            'title'       => get_the_title( $event ),
            'gallery_url' => TEPE_Gallery::gallery_url( $event ),
            'upload_url'  => TEPE_Gallery::upload_url( $event ),
            'qr_svg'      => TEPE_Rewrite::qr_url( $event, 'svg' ),
            'manage_hint' => 'We’ve emailed your gallery and QR links to ' . $email . '.',
        ) );
    }

    public static function find_events( $request ) {
        if ( self::rate_limited( 'find', 8, 3600 ) ) {
            return new WP_Error( 'rate', 'Too many requests — please try again later.', array( 'status' => 429 ) );
        }
        $email  = sanitize_email( (string) $request->get_param( 'email' ) );
        $generic = 'If we have galleries for that email, we’ve just sent the links to it.';
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'bad_email', 'Please enter a valid email address.', array( 'status' => 400 ) );
        }

        $events = TEPE_Gallery::get_by_host_email( $email );
        if ( ! empty( $events ) ) {
            $lines = '';
            foreach ( $events as $ev ) {
                if ( ! $ev ) continue;
                $lines .= "• " . get_the_title( $ev ) . "\n"
                    . "   Gallery: " . TEPE_Gallery::gallery_url( $ev ) . "\n"
                    . "   Guest upload (QR target): " . TEPE_Gallery::upload_url( $ev ) . "\n\n";
            }
            wp_mail( $email, 'Your Tweller event galleries', "Here are your event galleries:\n\n" . $lines . "— Tweller Studios" );
        }
        return rest_ensure_response( array( 'ok' => true, 'message' => $generic ) );
    }

    private static function email_host_links( $event, $email ) {
        $upload  = TEPE_Gallery::upload_url( $event );
        $gallery = TEPE_Gallery::gallery_url( $event );
        $body = "Your event gallery is ready!\n\n"
            . "Gallery: $gallery\n"
            . "Guest upload link (put the QR on your tables): {$upload}\n\n"
            . "Guests just scan and upload — no app, no sign-up.\n\n— Tweller Studios";
        wp_mail( $email, 'Your Tweller event gallery is ready', $body );
    }

    // ── Admin actions ────────────────────────────────────────────────────────

    public static function admin_upload_action( $request ) {
        $id     = (int) $request['id'];
        $action = $request['action'];
        $upload = TEPE_Gallery::get_upload( $id );
        if ( ! $upload ) return new WP_Error( 'no_upload', 'Upload not found.', array( 'status' => 404 ) );

        if ( $action === 'delete' ) {
            TEPE_Gallery::delete_upload( $id );
            return rest_ensure_response( array( 'ok' => true, 'deleted' => true ) );
        }
        global $wpdb;
        $wpdb->update(
            TEPE_Database::table( TEPE_TABLE_UPLOADS ),
            array( 'status' => ( $action === 'approve' ? 'approved' : 'rejected' ) ),
            array( 'id' => $id )
        );
        return rest_ensure_response( array( 'ok' => true, 'status' => $action ) );
    }

    public static function admin_order_status( $request ) {
        $id = (int) $request['id'];
        $status = sanitize_key( $request->get_param( 'status' ) );
        if ( ! TEPE_Prints::set_status( $id, $status ) ) {
            return new WP_Error( 'bad_status', 'Could not update the order.', array( 'status' => 400 ) );
        }
        return rest_ensure_response( array( 'ok' => true, 'status' => $status ) );
    }
}
