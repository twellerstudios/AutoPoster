<?php
/**
 * Tweller Flow — Print Store
 *
 * CloudSpot-style print sales: gallery clients order prints of their
 * delivered photos, and the public uploads photos on the "Order Prints"
 * page. Bank-transfer payment (no card processing), matching the studio's
 * booking flow. Black & gold brand.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Prints {

    const TABLE_ORDERS  = 'tweller_print_orders';
    const UPLOAD_SUBDIR = 'tweller-prints';
    const VERSION       = '1.0.0';

    const OPT_PRODUCTS = 'tweller_prints_products';
    const OPT_SETTINGS = 'tweller_prints_settings';
    const OPT_PAGE     = 'tweller_flow_2_prints_page';
    const OPT_VERSION  = 'tweller_prints_version';

    /** Public upload limits */
    const MAX_PUBLIC_FILES     = 25;
    const MAX_PUBLIC_FILE_SIZE = 26214400; // 25 MB
    const PUBLIC_RATE_LIMIT    = 10;       // orders/hour/IP (public upload)
    const GALLERY_RATE_LIMIT   = 20;       // orders/hour/IP (gallery cart)

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
        add_shortcode( 'tweller_prints', array( __CLASS__, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 20 );
        add_action( 'admin_init', array( __CLASS__, 'handle_admin_actions' ) );

        // Self-heal: create tables / page / defaults without reactivation
        // (same pattern as gallery & culling auto-upgrade).
        self::maybe_install();
    }

    public static function get_statuses() {
        return array( 'new', 'confirmed', 'printing', 'ready', 'completed', 'cancelled' );
    }

    // ── Install / self-heal ────────────────────────────

    public static function maybe_install() {
        if ( get_option( self::OPT_VERSION, '' ) === self::VERSION ) return;
        self::create_tables();
        self::ensure_page();
        if ( get_option( self::OPT_PRODUCTS, false ) === false ) {
            add_option( self::OPT_PRODUCTS, self::default_products() );
        }
        update_option( self::OPT_VERSION, self::VERSION );
    }

    public static function create_tables() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_ORDERS;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_ref varchar(32) NOT NULL,
            session_id bigint(20) unsigned DEFAULT NULL,
            session_code varchar(120) DEFAULT NULL,
            customer_name varchar(190) NOT NULL DEFAULT '',
            customer_email varchar(190) NOT NULL DEFAULT '',
            customer_phone varchar(64) NOT NULL DEFAULT '',
            items longtext,
            subtotal decimal(10,2) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'new',
            source varchar(20) NOT NULL DEFAULT 'gallery',
            notes text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY order_ref (order_ref),
            KEY session_id (session_id),
            KEY status (status)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    /**
     * Create the public "Order Prints" page with [tweller_prints]
     * (same pattern as the tracker & culling pages).
     */
    public static function ensure_page() {
        $existing_url = get_option( self::OPT_PAGE, '' );
        if ( $existing_url ) {
            $page_id = url_to_postid( $existing_url );
            if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
                return;
            }
        }

        $existing = get_posts( array(
            'post_type'   => 'page',
            'post_status' => 'publish',
            's'           => '[tweller_prints]',
            'numberposts' => 1,
        ) );

        if ( ! empty( $existing ) ) {
            update_option( self::OPT_PAGE, get_permalink( $existing[0]->ID ) );
            return;
        }

        $page_id = wp_insert_post( array(
            'post_title'   => 'Order Prints',
            'post_name'    => 'order-prints',
            'post_content' => '[tweller_prints]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ) );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( self::OPT_PAGE, get_permalink( $page_id ) );
        }
    }

    // ── Catalog & settings ─────────────────────────────

    public static function default_products() {
        $products = array();
        $prints = array(
            array( 4, 6, 8 ), array( 5, 7, 15 ), array( 8, 10, 45 ), array( 8, 12, 55 ),
            array( 11, 14, 85 ), array( 12, 18, 120 ), array( 16, 20, 175 ), array( 20, 30, 260 ),
        );
        foreach ( $prints as $p ) {
            $products[] = array(
                'id'       => 'print_' . $p[0] . 'x' . $p[1],
                'name'     => $p[0] . '×' . $p[1] . ' Print',
                'category' => 'print',
                'width_in' => $p[0],
                'height_in' => $p[1],
                'price'    => $p[2],
                'active'   => 1,
            );
        }
        $canvases = array( array( 16, 20, 400 ), array( 20, 30, 550 ) );
        foreach ( $canvases as $c ) {
            $products[] = array(
                'id'       => 'canvas_' . $c[0] . 'x' . $c[1],
                'name'     => $c[0] . '×' . $c[1] . ' Gallery Canvas',
                'category' => 'canvas',
                'width_in' => $c[0],
                'height_in' => $c[1],
                'price'    => $c[2],
                'active'   => 1,
            );
        }
        $books = array(
            array( 'book_layflat_8x8',    'Zno Layflat Photobook 8×8 (20 pages)',   8,  8,  650 ),
            array( 'book_layflat_10x10',  'Zno Layflat Photobook 10×10 (20 pages)', 10, 10, 850 ),
            array( 'book_flushmount_12x12', 'Zno Premium Flushmount Album 12×12',   12, 12, 1400 ),
        );
        foreach ( $books as $b ) {
            $products[] = array(
                'id'       => $b[0],
                'name'     => $b[1],
                'category' => 'photobook',
                'width_in' => $b[2],
                'height_in' => $b[3],
                'price'    => $b[4],
                'active'   => 1,
            );
        }
        return $products;
    }

    public static function get_products() {
        $products = get_option( self::OPT_PRODUCTS, array() );
        if ( ! is_array( $products ) || empty( $products ) ) {
            $products = self::default_products();
        }
        return array_values( $products );
    }

    public static function get_active_products() {
        $out = array();
        foreach ( self::get_products() as $p ) {
            if ( ! empty( $p['active'] ) ) $out[] = $p;
        }
        return $out;
    }

    public static function get_product( $product_id ) {
        foreach ( self::get_products() as $p ) {
            if ( isset( $p['id'] ) && $p['id'] === $product_id ) return $p;
        }
        return null;
    }

    public static function get_settings() {
        $defaults = array(
            'notify_email'         => get_option( 'admin_email' ),
            'pickup_note'          => "Prints are usually ready for pickup at Tweller Studios within 7–10 business days. Delivery across Trinidad & Tobago can be arranged — we'll confirm the details with you after your order.",
            'payment_instructions' => '',
        );
        $saved = get_option( self::OPT_SETTINGS, array() );
        if ( ! is_array( $saved ) ) $saved = array();
        return array_merge( $defaults, $saved );
    }

    /** Bank transfer details shown at checkout / in emails (falls back to booking banking info). */
    public static function get_payment_instructions() {
        $settings = self::get_settings();
        $text = trim( (string) $settings['payment_instructions'] );
        if ( $text === '' ) {
            $text = trim( (string) get_option( 'tweller_flow_2_banking', '' ) );
        }
        return $text;
    }

    public static function get_prints_page_url() {
        $url = get_option( self::OPT_PAGE, '' );
        return $url ? $url : home_url( '/order-prints/' );
    }

    // ── Assets & shortcode ─────────────────────────────

    public static function register_assets() {
        wp_register_style(
            'tweller-flow-2-prints',
            TWELLER_FLOW_2_PLUGIN_URL . 'public/css/prints.css',
            array(),
            TWELLER_FLOW_2_VERSION
        );
        wp_register_script(
            'tweller-flow-2-prints',
            TWELLER_FLOW_2_PLUGIN_URL . 'public/js/prints.js',
            array(),
            TWELLER_FLOW_2_VERSION,
            true
        );
    }

    /**
     * Enqueue the store UI + config. Called by the tracker shortcode
     * (gallery mode) and by [tweller_prints] (public mode).
     */
    public static function enqueue_store_assets( $config = array() ) {
        wp_enqueue_style( 'tweller-flow-2-prints' );
        wp_enqueue_script( 'tweller-flow-2-prints' );

        $defaults = array(
            'restUrl'  => rest_url( 'tweller-flow-2/v1/prints/' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'currency' => 'TT$',
            'mode'     => 'public',
            'code'     => '',
            'customerName'  => '',
            'customerEmail' => '',
        );
        wp_localize_script( 'tweller-flow-2-prints', 'twellerFlow2Prints', array_merge( $defaults, $config ) );
    }

    /** [tweller_prints] — the public upload-and-order page */
    public static function render_shortcode( $atts ) {
        self::enqueue_store_assets( array( 'mode' => 'public' ) );

        ob_start();
        ?>
        <div class="tf2-prints" id="tf2-prints-app">
            <div class="tf2-prints__hero">
                <span class="tf2-prints__eyebrow">Tweller Studios Print Shop</span>
                <h2 class="tf2-prints__title">Order Prints</h2>
                <p class="tf2-prints__sub">Upload your photos, pick your sizes, and we'll produce beautiful professional prints, canvases and photobooks — ready for pickup or delivery in Trinidad &amp; Tobago.</p>
            </div>

            <div class="tf2-prints__dropzone" id="tf2p-dropzone" role="button" tabindex="0">
                <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                <p class="tf2-prints__dz-title">Tap to add your photos</p>
                <p class="tf2-prints__dz-note">JPEG or PNG · up to <?php echo (int) self::MAX_PUBLIC_FILES; ?> photos · 25MB each</p>
                <input type="file" id="tf2p-file-input" accept="image/jpeg,image/png" multiple style="display:none;">
            </div>

            <div class="tf2-prints__grid" id="tf2p-upload-grid"></div>

            <div class="tf2-prints__cartbar" id="tf2p-cartbar" style="display:none;">
                <span class="tf2-prints__cartbar-label" id="tf2p-cartbar-label"></span>
                <button type="button" class="tf2p-btn tf2p-btn--gold" id="tf2p-cartbar-btn">Review &amp; Checkout</button>
            </div>

            <noscript><p style="text-align:center;">The print shop needs JavaScript. Please enable it, or email <a href="mailto:hello@twellerstudios.com">hello@twellerstudios.com</a> to order.</p></noscript>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── REST API ───────────────────────────────────────

    public static function register_rest_routes() {
        $ns = 'tweller-flow-2/v1';

        // Active products for the pickers (public)
        register_rest_route( $ns, '/prints/products', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_products' ),
            'permission_callback' => '__return_true',
        ));

        // Create order from the gallery cart (public)
        register_rest_route( $ns, '/prints/order', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_create_order' ),
            'permission_callback' => '__return_true',
        ));

        // Public upload page: multipart images + items + contact (public)
        register_rest_route( $ns, '/prints/public-order', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_create_public_order' ),
            'permission_callback' => '__return_true',
        ));

        // Orders list (mobile app, API key)
        register_rest_route( $ns, '/prints/orders', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_list_orders' ),
            'permission_callback' => array( 'TwellerFlow2_Photo_Automation', 'verify_api_key' ),
        ));

        // Single order (API key)
        register_rest_route( $ns, '/prints/orders/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_order' ),
            'permission_callback' => array( 'TwellerFlow2_Photo_Automation', 'verify_api_key' ),
        ));

        // Set order status (API key)
        register_rest_route( $ns, '/prints/orders/(?P<id>\d+)/status', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_set_status' ),
            'permission_callback' => array( 'TwellerFlow2_Photo_Automation', 'verify_api_key' ),
        ));
    }

    public static function rest_get_products( $request ) {
        $settings = self::get_settings();
        $products = array();
        foreach ( self::get_active_products() as $p ) {
            $products[] = array(
                'id'        => (string) $p['id'],
                'name'      => (string) $p['name'],
                'category'  => (string) $p['category'],
                'width_in'  => (float) $p['width_in'],
                'height_in' => (float) $p['height_in'],
                'price'     => (float) $p['price'],
            );
        }
        return rest_ensure_response( array(
            'ok'          => true,
            'currency'    => 'TT$',
            'products'    => $products,
            'pickup_note' => (string) $settings['pickup_note'],
        ));
    }

    /** POST /prints/order — gallery cart checkout */
    public static function rest_create_order( $request ) {
        // Honeypot — bots fill every field
        if ( trim( (string) $request->get_param( 'website' ) ) !== '' ) {
            return new WP_Error( 'invalid', 'Invalid submission', array( 'status' => 400 ) );
        }
        if ( ! self::check_rate_limit( 'order', self::GALLERY_RATE_LIMIT ) ) {
            return new WP_Error( 'rate_limited', 'Too many orders from this address. Please try again later.', array( 'status' => 429 ) );
        }

        $name  = sanitize_text_field( $request->get_param( 'customer_name' ) );
        $email = sanitize_email( $request->get_param( 'customer_email' ) );
        $phone = sanitize_text_field( $request->get_param( 'customer_phone' ) );
        $notes = sanitize_textarea_field( $request->get_param( 'notes' ) );

        if ( $name === '' || ! is_email( $email ) ) {
            return new WP_Error( 'missing_contact', 'Please provide your name and a valid email address.', array( 'status' => 400 ) );
        }

        // Optional session code — must resolve when provided
        $session_code = sanitize_text_field( $request->get_param( 'session_code' ) );
        $session      = null;
        if ( $session_code !== '' ) {
            $session = TwellerFlow2_Session::get_by_code( $session_code );
            if ( ! $session ) {
                return new WP_Error( 'bad_session', 'Gallery session not found.', array( 'status' => 400 ) );
            }
        }

        $raw_items = $request->get_param( 'items' );
        if ( is_string( $raw_items ) ) {
            $raw_items = json_decode( $raw_items, true );
        }
        $items = self::sanitize_cart_items( $raw_items, true );
        if ( empty( $items ) ) {
            return new WP_Error( 'no_items', 'Your cart is empty.', array( 'status' => 400 ) );
        }

        $order_id = self::insert_order( array(
            'session_id'     => $session ? (int) $session->id : null,
            'session_code'   => $session ? $session->tracking_code : null,
            'customer_name'  => $name,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'items'          => $items,
            'notes'          => $notes,
            'source'         => 'gallery',
        ));

        if ( ! $order_id ) {
            return new WP_Error( 'save_failed', 'Could not save your order. Please try again.', array( 'status' => 500 ) );
        }

        $order = self::get_order( $order_id );
        self::send_customer_confirmation( $order );
        self::send_studio_alert( $order );

        $settings = self::get_settings();
        return rest_ensure_response( array(
            'ok'                   => true,
            'order_ref'            => $order->order_ref,
            'subtotal'             => (float) $order->subtotal,
            'payment_instructions' => self::get_payment_instructions(),
            'pickup_note'          => (string) $settings['pickup_note'],
        ));
    }

    /** POST /prints/public-order — public upload page checkout (multipart) */
    public static function rest_create_public_order( $request ) {
        if ( trim( (string) $request->get_param( 'website' ) ) !== '' ) {
            return new WP_Error( 'invalid', 'Invalid submission', array( 'status' => 400 ) );
        }
        if ( ! self::check_rate_limit( 'public', self::PUBLIC_RATE_LIMIT ) ) {
            return new WP_Error( 'rate_limited', 'Too many orders from this address. Please try again in an hour.', array( 'status' => 429 ) );
        }

        $name  = sanitize_text_field( $request->get_param( 'customer_name' ) );
        $email = sanitize_email( $request->get_param( 'customer_email' ) );
        $phone = sanitize_text_field( $request->get_param( 'customer_phone' ) );
        $notes = sanitize_textarea_field( $request->get_param( 'notes' ) );

        if ( $name === '' || ! is_email( $email ) ) {
            return new WP_Error( 'missing_contact', 'Please provide your name and a valid email address.', array( 'status' => 400 ) );
        }

        // ── Files ──
        $file_params = $request->get_file_params();
        if ( empty( $file_params['photos'] ) ) {
            return new WP_Error( 'no_files', 'No photos were uploaded.', array( 'status' => 400 ) );
        }
        $files = self::normalize_files_array( $file_params['photos'] );
        if ( count( $files ) > self::MAX_PUBLIC_FILES ) {
            return new WP_Error( 'too_many_files', 'A maximum of ' . self::MAX_PUBLIC_FILES . ' photos per order.', array( 'status' => 400 ) );
        }

        // ── Items (reference files by index) ──
        $raw_items = $request->get_param( 'items' );
        if ( is_string( $raw_items ) ) {
            $raw_items = json_decode( $raw_items, true );
        }
        if ( ! is_array( $raw_items ) || empty( $raw_items ) ) {
            return new WP_Error( 'no_items', 'No products were selected.', array( 'status' => 400 ) );
        }

        $order_ref = self::generate_order_ref();
        $dir       = self::get_order_dir( $order_ref );
        $thumbs    = $dir . '/thumbs';
        wp_mkdir_p( $dir );
        wp_mkdir_p( $thumbs );
        $base_url  = self::get_order_url( $order_ref );

        $allowed = array( 'image/jpeg', 'image/jpg', 'image/png' );
        $saved   = array(); // file index => array(filename, url, thumb_url)

        foreach ( $files as $idx => $file ) {
            if ( ! isset( $file['error'] ) || $file['error'] !== UPLOAD_ERR_OK ) continue;
            if ( $file['size'] > self::MAX_PUBLIC_FILE_SIZE ) continue;

            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            $mime  = finfo_file( $finfo, $file['tmp_name'] );
            finfo_close( $finfo );
            if ( ! in_array( $mime, $allowed, true ) ) continue;

            $filename = sanitize_file_name( $file['name'] );
            if ( $filename === '' ) $filename = 'photo-' . ( $idx + 1 ) . '.jpg';
            $dest = $dir . '/' . $filename;
            $i = 1;
            while ( file_exists( $dest ) ) {
                $info     = pathinfo( $filename );
                $ext      = isset( $info['extension'] ) ? '.' . $info['extension'] : '';
                $filename = $info['filename'] . '-' . $i . $ext;
                $dest     = $dir . '/' . $filename;
                $i++;
            }

            if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) continue;

            if ( class_exists( 'TwellerFlow2_Gallery' ) ) {
                TwellerFlow2_Gallery::create_thumbnail( $dest, $thumbs . '/' . $filename, 600 );
            }

            $saved[ $idx ] = array(
                'filename'  => $filename,
                'url'       => $base_url . '/' . $filename,
                'thumb_url' => $base_url . '/thumbs/' . $filename,
            );
        }

        if ( empty( $saved ) ) {
            return new WP_Error( 'upload_failed', 'None of the photos could be saved. Please use JPEG or PNG under 25MB.', array( 'status' => 400 ) );
        }

        // Map items to saved files & price from the catalog
        $items = array();
        foreach ( array_slice( (array) $raw_items, 0, 100 ) as $row ) {
            if ( ! is_array( $row ) ) continue;
            $file_index = isset( $row['file_index'] ) ? intval( $row['file_index'] ) : -1;
            if ( ! isset( $saved[ $file_index ] ) ) continue;

            $product = self::get_product( sanitize_text_field( $row['product_id'] ?? '' ) );
            if ( ! $product || empty( $product['active'] ) ) continue;

            $qty = max( 1, min( 50, intval( $row['qty'] ?? 1 ) ) );
            $items[] = array(
                'product_id'   => (string) $product['id'],
                'product_name' => (string) $product['name'],
                'category'     => (string) $product['category'],
                'price'        => (float) $product['price'],
                'qty'          => $qty,
                'filename'     => $saved[ $file_index ]['filename'],
                'photo_url'    => $saved[ $file_index ]['url'],
                'thumb_url'    => $saved[ $file_index ]['thumb_url'],
                'crop'         => sanitize_text_field( $row['crop'] ?? '' ),
            );
        }

        if ( empty( $items ) ) {
            return new WP_Error( 'no_items', 'No valid products were selected.', array( 'status' => 400 ) );
        }

        $order_id = self::insert_order( array(
            'order_ref'      => $order_ref,
            'session_id'     => null,
            'session_code'   => null,
            'customer_name'  => $name,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'items'          => $items,
            'notes'          => $notes,
            'source'         => 'public',
        ));

        if ( ! $order_id ) {
            return new WP_Error( 'save_failed', 'Could not save your order. Please try again.', array( 'status' => 500 ) );
        }

        $order = self::get_order( $order_id );
        self::send_customer_confirmation( $order );
        self::send_studio_alert( $order );

        $settings = self::get_settings();
        return rest_ensure_response( array(
            'ok'                   => true,
            'order_ref'            => $order->order_ref,
            'subtotal'             => (float) $order->subtotal,
            'payment_instructions' => self::get_payment_instructions(),
            'pickup_note'          => (string) $settings['pickup_note'],
        ));
    }

    /** GET /prints/orders?status=&search= (API key) */
    public static function rest_list_orders( $request ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_ORDERS;

        $status = sanitize_text_field( $request->get_param( 'status' ) );
        $search = sanitize_text_field( $request->get_param( 'search' ) );
        $limit  = max( 1, min( 200, intval( $request->get_param( 'limit' ) ?: 100 ) ) );

        $where  = array( '1=1' );
        $params = array();
        if ( $status !== '' && in_array( $status, self::get_statuses(), true ) ) {
            $where[]  = 'status = %s';
            $params[] = $status;
        }
        if ( $search !== '' ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]  = '(order_ref LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s OR session_code LIKE %s)';
            array_push( $params, $like, $like, $like, $like, $like );
        }

        $sql      = "SELECT * FROM $table WHERE " . implode( ' AND ', $where ) . " ORDER BY created_at DESC LIMIT %d";
        $params[] = $limit;
        $rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

        $orders = array();
        foreach ( (array) $rows as $row ) {
            $orders[] = self::format_order( $row );
        }
        return rest_ensure_response( array( 'ok' => true, 'orders' => $orders, 'count' => count( $orders ) ) );
    }

    /** GET /prints/orders/{id} (API key) */
    public static function rest_get_order( $request ) {
        $order = self::get_order( intval( $request['id'] ) );
        if ( ! $order ) {
            return new WP_Error( 'not_found', 'Order not found', array( 'status' => 404 ) );
        }
        return rest_ensure_response( array( 'ok' => true, 'order' => self::format_order( $order ) ) );
    }

    /** POST /prints/orders/{id}/status (API key) */
    public static function rest_set_status( $request ) {
        $order = self::get_order( intval( $request['id'] ) );
        if ( ! $order ) {
            return new WP_Error( 'not_found', 'Order not found', array( 'status' => 404 ) );
        }

        $status = sanitize_text_field( $request->get_param( 'status' ) );
        if ( ! in_array( $status, self::get_statuses(), true ) ) {
            return new WP_Error( 'bad_status', 'Invalid status', array( 'status' => 400 ) );
        }
        $notify = filter_var( $request->get_param( 'notify' ), FILTER_VALIDATE_BOOLEAN );

        self::update_status( $order->id, $status, $notify );

        return rest_ensure_response( array( 'ok' => true, 'id' => (int) $order->id, 'status' => $status, 'notified' => (bool) $notify ) );
    }

    // ── Order helpers ──────────────────────────────────

    public static function update_status( $order_id, $status, $notify = false ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_ORDERS;
        $wpdb->update(
            $table,
            array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => intval( $order_id ) )
        );
        if ( $notify ) {
            $order = self::get_order( $order_id );
            if ( $order ) {
                self::send_status_email( $order, $status );
            }
        }
    }

    public static function get_order( $order_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_ORDERS;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", intval( $order_id ) ) );
    }

    public static function delete_order( $order_id ) {
        global $wpdb;
        $order = self::get_order( $order_id );
        if ( ! $order ) return false;

        // Remove public upload files (only ever written under tweller-prints/{ref})
        if ( $order->source === 'public' && preg_match( '/^TS-PRINT-[A-Z0-9]+$/', $order->order_ref ) ) {
            $dir = self::get_order_dir( $order->order_ref );
            if ( is_dir( $dir ) ) {
                foreach ( (array) glob( $dir . '/thumbs/*' ) as $f ) { @unlink( $f ); }
                @rmdir( $dir . '/thumbs' );
                foreach ( (array) glob( $dir . '/*' ) as $f ) { if ( is_file( $f ) ) @unlink( $f ); }
                @rmdir( $dir );
            }
        }

        $table = $wpdb->prefix . self::TABLE_ORDERS;
        $wpdb->delete( $table, array( 'id' => intval( $order_id ) ) );
        return true;
    }

    public static function get_order_items( $order ) {
        $items = json_decode( (string) $order->items, true );
        return is_array( $items ) ? $items : array();
    }

    private static function format_order( $row ) {
        return array(
            'id'             => (int) $row->id,
            'order_ref'      => $row->order_ref,
            'session_id'     => $row->session_id ? (int) $row->session_id : null,
            'session_code'   => $row->session_code,
            'customer_name'  => $row->customer_name,
            'customer_email' => $row->customer_email,
            'customer_phone' => $row->customer_phone,
            'items'          => self::get_order_items( $row ),
            'subtotal'       => (float) $row->subtotal,
            'status'         => $row->status,
            'source'         => $row->source,
            'notes'          => $row->notes,
            'created_at'     => $row->created_at,
            'updated_at'     => $row->updated_at,
        );
    }

    /**
     * Sanitize gallery-cart items: prices always come from the catalog,
     * never from the client. Photo URLs must live in our uploads dir.
     */
    private static function sanitize_cart_items( $raw_items, $require_upload_urls = true ) {
        if ( ! is_array( $raw_items ) ) return array();

        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];

        $items = array();
        foreach ( array_slice( $raw_items, 0, 100 ) as $row ) {
            if ( ! is_array( $row ) ) continue;

            $product = self::get_product( sanitize_text_field( $row['product_id'] ?? '' ) );
            if ( ! $product || empty( $product['active'] ) ) continue;

            $qty = max( 1, min( 50, intval( $row['qty'] ?? 1 ) ) );

            $photo_url = esc_url_raw( (string) ( $row['photo_url'] ?? '' ) );
            $thumb_url = esc_url_raw( (string) ( $row['thumb_url'] ?? '' ) );
            if ( $require_upload_urls ) {
                if ( $photo_url !== '' && strpos( $photo_url, $base_url ) !== 0 ) $photo_url = '';
                if ( $thumb_url !== '' && strpos( $thumb_url, $base_url ) !== 0 ) $thumb_url = '';
            }

            $items[] = array(
                'product_id'   => (string) $product['id'],
                'product_name' => (string) $product['name'],
                'category'     => (string) $product['category'],
                'price'        => (float) $product['price'],
                'qty'          => $qty,
                'filename'     => sanitize_file_name( (string) ( $row['filename'] ?? '' ) ),
                'photo_url'    => $photo_url,
                'thumb_url'    => $thumb_url,
                'crop'         => sanitize_text_field( (string) ( $row['crop'] ?? '' ) ),
            );
        }
        return $items;
    }

    private static function insert_order( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_ORDERS;

        $items    = $data['items'];
        $subtotal = 0;
        foreach ( $items as $item ) {
            $subtotal += $item['price'] * $item['qty'];
        }

        $now = current_time( 'mysql' );
        $ok  = $wpdb->insert( $table, array(
            'order_ref'      => ! empty( $data['order_ref'] ) ? $data['order_ref'] : self::generate_order_ref(),
            'session_id'     => $data['session_id'],
            'session_code'   => $data['session_code'],
            'customer_name'  => $data['customer_name'],
            'customer_email' => $data['customer_email'],
            'customer_phone' => $data['customer_phone'],
            'items'          => wp_json_encode( $items ),
            'subtotal'       => round( $subtotal, 2 ),
            'status'         => 'new',
            'source'         => $data['source'],
            'notes'          => $data['notes'],
            'created_at'     => $now,
            'updated_at'     => $now,
        ));

        return $ok ? $wpdb->insert_id : 0;
    }

    public static function generate_order_ref() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_ORDERS;
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $suffix = '';
            for ( $i = 0; $i < 6; $i++ ) {
                $suffix .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
            }
            $ref    = 'TS-PRINT-' . $suffix;
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE order_ref = %s", $ref ) );
        } while ( $exists );
        return $ref;
    }

    public static function get_order_dir( $order_ref ) {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/' . self::UPLOAD_SUBDIR . '/' . sanitize_file_name( $order_ref );
    }

    public static function get_order_url( $order_ref ) {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/' . self::UPLOAD_SUBDIR . '/' . sanitize_file_name( $order_ref );
    }

    /** $_FILES-style multi-upload → list of single-file arrays */
    private static function normalize_files_array( $files ) {
        if ( ! is_array( $files['name'] ) ) {
            return array( $files );
        }
        $out = array();
        $count = count( $files['name'] );
        for ( $i = 0; $i < $count; $i++ ) {
            $out[] = array(
                'name'     => $files['name'][ $i ],
                'type'     => $files['type'][ $i ],
                'tmp_name' => $files['tmp_name'][ $i ],
                'error'    => $files['error'][ $i ],
                'size'     => $files['size'][ $i ],
            );
        }
        return $out;
    }

    /** Sliding one-hour rate limit per IP, per action */
    private static function check_rate_limit( $bucket, $max ) {
        $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
        $key = 'tf2_prints_rl_' . $bucket . '_' . md5( $ip );
        $count = (int) get_transient( $key );
        if ( $count >= $max ) return false;
        set_transient( $key, $count + 1, HOUR_IN_SECONDS );
        return true;
    }

    // ── Emails ─────────────────────────────────────────

    /** Branded items table for order emails */
    private static function items_table_html( $order ) {
        $items = self::get_order_items( $order );
        $rows  = '';
        foreach ( $items as $item ) {
            $thumb = '';
            if ( ! empty( $item['thumb_url'] ) ) {
                $thumb = "<img src='" . esc_url( $item['thumb_url'] ) . "' alt='' width='54' style='width:54px; height:54px; object-fit:cover; border-radius:6px; display:block;'>";
            }
            $line_total = number_format( $item['price'] * $item['qty'], 2 );
            $rows .= "
                <tr>
                    <td style='padding:8px 10px 8px 0; vertical-align:middle; width:54px;'>{$thumb}</td>
                    <td style='padding:8px 10px 8px 0; vertical-align:middle;'>
                        <span style='color:#101010; font-weight:600; font-size:14px;'>" . esc_html( $item['product_name'] ) . "</span><br>
                        <span style='color:#8A8178; font-size:12px;'>" . esc_html( $item['filename'] ) . " &times; " . intval( $item['qty'] ) . "</span>
                    </td>
                    <td style='padding:8px 0; vertical-align:middle; text-align:right; white-space:nowrap; color:#3D3630; font-weight:600; font-size:14px;'>TT$ {$line_total}</td>
                </tr>";
        }
        $subtotal = number_format( (float) $order->subtotal, 2 );
        return "
            <table style='width:100%; border-collapse:collapse;'>
                {$rows}
                <tr>
                    <td colspan='2' style='padding:12px 10px 0 0; border-top:1px solid #EDE5D8; color:#101010; font-weight:700; font-size:14px;'>Subtotal</td>
                    <td style='padding:12px 0 0; border-top:1px solid #EDE5D8; text-align:right; color:#101010; font-weight:700; font-size:15px;'>TT$ {$subtotal}</td>
                </tr>
            </table>";
    }

    public static function send_customer_confirmation( $order ) {
        if ( ! $order || empty( $order->customer_email ) || ! class_exists( 'TwellerFlow2_Notifications' ) ) return false;

        $first_name = trim( explode( ' ', trim( $order->customer_name ) )[0] );
        $settings   = self::get_settings();
        $payment    = self::get_payment_instructions();

        $payment_block = '';
        if ( $payment !== '' ) {
            $payment_block = TwellerFlow2_Notifications::email_card( 'Payment — Bank Transfer', "
                <p style='margin:6px 0; color:#3D3630; line-height:1.7;'>To get your order into production, kindly transfer <strong>TT$ " . number_format( (float) $order->subtotal, 2 ) . "</strong> using the details below, then reply to this email with your receipt.</p>
                <pre style='background:#fff; border:1px solid #EDE5D8; padding:16px; border-radius:8px; white-space:pre-wrap; color:#3D3630; font-size:14px;'>" . esc_html( $payment ) . "</pre>
            " );
        }

        $subject = "We've received your print order, {$first_name} — " . $order->order_ref;
        $body = "
            <h2 style='color:#101010; font-weight:600;'>Your print order is in</h2>
            <p style='color:#3D3630;'>Hi " . esc_html( $order->customer_name ) . ",</p>
            <p style='color:#3D3630; line-height:1.7;'>Thank you for your order — we can't wait to see these in print. Your order reference is <strong style='color:#101010;'>" . esc_html( $order->order_ref ) . "</strong>.</p>

            " . TwellerFlow2_Notifications::email_card( 'Your Order', self::items_table_html( $order ) ) . "

            {$payment_block}

            <p style='color:#3D3630; line-height:1.7;'>" . esc_html( $settings['pickup_note'] ) . "</p>
            <p style='color:#3D3630; line-height:1.7;'>We'll be in touch shortly to confirm everything. If you have any questions, just reply to this email.</p>
            <p style='color:#3D3630;'>Warm regards,<br><strong>The Tweller Studios Team</strong></p>
        ";

        return TwellerFlow2_Notifications::send_raw( $order->customer_email, $subject, $body );
    }

    public static function send_studio_alert( $order ) {
        if ( ! $order || ! class_exists( 'TwellerFlow2_Notifications' ) ) return false;

        $settings = self::get_settings();
        $to = is_email( $settings['notify_email'] ) ? $settings['notify_email'] : get_option( 'admin_email' );
        $admin_url = admin_url( 'admin.php?page=tweller-flow-2-prints' );

        $meta  = TwellerFlow2_Notifications::email_detail_row( 'Customer', esc_html( $order->customer_name ) );
        $meta .= TwellerFlow2_Notifications::email_detail_row( 'Email', esc_html( $order->customer_email ) );
        if ( $order->customer_phone ) {
            $meta .= TwellerFlow2_Notifications::email_detail_row( 'Phone', esc_html( $order->customer_phone ) );
        }
        $meta .= TwellerFlow2_Notifications::email_detail_row( 'Source', $order->source === 'gallery' ? 'Client gallery' : 'Public upload' );
        if ( $order->session_code ) {
            $meta .= TwellerFlow2_Notifications::email_detail_row( 'Shoot code', esc_html( $order->session_code ) );
        }
        if ( $order->notes ) {
            $meta .= TwellerFlow2_Notifications::email_detail_row( 'Notes', esc_html( $order->notes ) );
        }

        $subject = 'New print order ' . $order->order_ref . ' — ' . $order->customer_name . ' — TT$ ' . number_format( (float) $order->subtotal, 2 );
        $body = "
            <h2 style='color:#101010; font-weight:600;'>New print order</h2>
            <p style='color:#3D3630; line-height:1.7;'>A new print order just came in: <strong>" . esc_html( $order->order_ref ) . "</strong>.</p>
            " . TwellerFlow2_Notifications::email_card( 'Order Details', $meta ) . "
            " . TwellerFlow2_Notifications::email_card( 'Items', self::items_table_html( $order ) ) . "
            <div style='text-align:center; margin:28px 0;'>
                " . TwellerFlow2_Notifications::email_button( esc_url( $admin_url ), 'Open Print Store Orders' ) . "
            </div>
        ";

        return TwellerFlow2_Notifications::send_raw( $to, $subject, $body );
    }

    /** Status-update email to the customer (used by admin + REST notify flag) */
    public static function send_status_email( $order, $status ) {
        if ( ! $order || empty( $order->customer_email ) || ! class_exists( 'TwellerFlow2_Notifications' ) ) return false;

        $first_name = trim( explode( ' ', trim( $order->customer_name ) )[0] );
        $settings   = self::get_settings();

        $copy = array(
            'confirmed' => array(
                'subject' => "Payment received — your print order is confirmed, {$first_name}",
                'heading' => 'Order confirmed',
                'line'    => "Lovely news — we've received your payment and your print order <strong>" . esc_html( $order->order_ref ) . "</strong> is now in the production queue.",
            ),
            'printing' => array(
                'subject' => "Your prints are in production, {$first_name}",
                'heading' => 'In production',
                'line'    => "Your order <strong>" . esc_html( $order->order_ref ) . "</strong> is being printed right now. We'll let you know the moment it's ready.",
            ),
            'ready' => array(
                'subject' => "Your prints are ready for pickup, {$first_name} ✨",
                'heading' => 'Ready for pickup',
                'line'    => "The moment you've been waiting for — your order <strong>" . esc_html( $order->order_ref ) . "</strong> is printed, packed and ready.<br><br>" . esc_html( $settings['pickup_note'] ),
            ),
            'completed' => array(
                'subject' => "Enjoy your prints, {$first_name}!",
                'heading' => 'Order completed',
                'line'    => "Your order <strong>" . esc_html( $order->order_ref ) . "</strong> is complete. Thank you for printing with Tweller Studios — we hope they look beautiful on your walls.",
            ),
            'cancelled' => array(
                'subject' => "Your print order has been cancelled — " . $order->order_ref,
                'heading' => 'Order cancelled',
                'line'    => "Your order <strong>" . esc_html( $order->order_ref ) . "</strong> has been cancelled. If this is unexpected, just reply to this email and we'll sort it out.",
            ),
        );

        if ( ! isset( $copy[ $status ] ) ) return false;
        $c = $copy[ $status ];

        $body = "
            <h2 style='color:#101010; font-weight:600;'>" . $c['heading'] . "</h2>
            <p style='color:#3D3630;'>Hi " . esc_html( $order->customer_name ) . ",</p>
            <p style='color:#3D3630; line-height:1.7;'>" . $c['line'] . "</p>
            " . TwellerFlow2_Notifications::email_card( 'Your Order', self::items_table_html( $order ) ) . "
            <p style='color:#3D3630;'>Warm regards,<br><strong>The Tweller Studios Team</strong></p>
        ";

        return TwellerFlow2_Notifications::send_raw( $order->customer_email, $c['subject'], $body );
    }

    // ── Admin ──────────────────────────────────────────

    public static function register_admin_menu() {
        add_submenu_page(
            'tweller-flow-2',
            'Print Store',
            'Print Store',
            'manage_options',
            'tweller-flow-2-prints',
            array( __CLASS__, 'page_prints' )
        );
    }

    public static function page_prints() {
        $view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'orders';

        if ( $view === 'settings' ) {
            $products = self::get_products();
            $settings = self::get_settings();
            include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/prints-settings.php';
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_ORDERS;

        $status_filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
        if ( ! in_array( $status_filter, self::get_statuses(), true ) ) $status_filter = '';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';

        $where  = array( '1=1' );
        $params = array();
        if ( $status_filter !== '' ) {
            $where[]  = 'status = %s';
            $params[] = $status_filter;
        }
        if ( $search !== '' ) {
            $like    = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(order_ref LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s OR session_code LIKE %s)';
            array_push( $params, $like, $like, $like, $like );
        }
        $sql = "SELECT * FROM $table WHERE " . implode( ' AND ', $where ) . " ORDER BY created_at DESC LIMIT 200";
        $orders = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

        $status_counts = array( '' => 0 );
        foreach ( self::get_statuses() as $s ) { $status_counts[ $s ] = 0; }
        $counts = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM $table GROUP BY status" );
        foreach ( (array) $counts as $row ) {
            if ( isset( $status_counts[ $row->status ] ) ) {
                $status_counts[ $row->status ] = (int) $row->c;
            }
            $status_counts[''] += (int) $row->c;
        }

        $statuses = self::get_statuses();
        include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/prints-orders.php';
    }

    public static function handle_admin_actions() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Save products + store settings
        if ( isset( $_POST['tweller_flow_2_save_prints'] ) ) {
            check_admin_referer( 'tweller_flow_2_save_prints' );

            $products = array();
            $used_ids = array();
            foreach ( (array) ( $_POST['pr'] ?? array() ) as $row ) {
                $name = sanitize_text_field( $row['name'] ?? '' );
                if ( $name === '' ) continue;

                $category = sanitize_key( $row['category'] ?? 'print' );
                if ( ! in_array( $category, array( 'print', 'canvas', 'photobook' ), true ) ) {
                    $category = 'print';
                }

                $id = sanitize_key( $row['id'] ?? '' );
                if ( $id === '' ) {
                    $id = $category . '_' . str_replace( '-', '_', sanitize_title( $name ) );
                }
                $base = $id ?: 'product';
                $i = 2;
                while ( isset( $used_ids[ $id ] ) ) { $id = $base . '_' . $i++; }
                $used_ids[ $id ] = true;

                $products[] = array(
                    'id'        => $id,
                    'name'      => $name,
                    'category'  => $category,
                    'width_in'  => max( 0, floatval( $row['width_in'] ?? 0 ) ),
                    'height_in' => max( 0, floatval( $row['height_in'] ?? 0 ) ),
                    'price'     => max( 0, floatval( $row['price'] ?? 0 ) ),
                    'active'    => empty( $row['active'] ) ? 0 : 1,
                );
            }
            if ( ! empty( $products ) ) {
                update_option( self::OPT_PRODUCTS, $products );
            }

            update_option( self::OPT_SETTINGS, array(
                'notify_email'         => sanitize_email( $_POST['prints_notify_email'] ?? '' ),
                'pickup_note'          => sanitize_textarea_field( $_POST['prints_pickup_note'] ?? '' ),
                'payment_instructions' => sanitize_textarea_field( $_POST['prints_payment_instructions'] ?? '' ),
            ));

            wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-prints&view=settings&saved=1' ) );
            exit;
        }

        // Update order status (+ optional client email)
        if ( isset( $_POST['tweller_flow_2_print_order_status'] ) ) {
            check_admin_referer( 'tweller_flow_2_print_order_status' );
            $order_id = intval( $_POST['order_id'] ?? 0 );
            $status   = sanitize_text_field( $_POST['order_status'] ?? '' );
            $notify   = ! empty( $_POST['notify_client'] );
            if ( $order_id && in_array( $status, self::get_statuses(), true ) ) {
                self::update_status( $order_id, $status, $notify );
            }
            wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-prints&updated=1' ) );
            exit;
        }

        // Delete order
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete_print_order' && isset( $_GET['order_id'] ) ) {
            check_admin_referer( 'tweller_flow_2_delete_print_order_' . $_GET['order_id'] );
            self::delete_order( intval( $_GET['order_id'] ) );
            wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-prints&deleted=1' ) );
            exit;
        }
    }
}
