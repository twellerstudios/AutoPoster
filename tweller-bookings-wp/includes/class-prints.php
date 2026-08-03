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
    const VERSION       = '2.1.0';

    /**
     * Client-side receipt OCR. One constant so the customer portal and the
     * inline bank-transfer step load the identical library — the OCR is a
     * convenience that pre-fills the amount; if it never loads the customer
     * simply types the amount and the upload works exactly the same.
     */
    const OCR_SCRIPT_URL = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';

    const OPT_PRODUCTS = 'tweller_prints_products';
    const OPT_SETTINGS = 'tweller_prints_settings';
    const OPT_PAGE     = 'tweller_flow_2_prints_page';
    const OPT_VERSION  = 'tweller_prints_version';

    /** Public upload limits */
    const MAX_PUBLIC_FILES     = 25;
    /**
     * Hard ceiling on line items in one order. This is a sanity bound only —
     * it must never be applied by truncating, because silently dropping
     * items produces an order that bills for fewer photos than the customer
     * chose. Exceeding it is an error the caller sees.
     */
    const MAX_ORDER_ITEMS      = 500;
    const MAX_PUBLIC_FILE_SIZE = 26214400; // 25 MB
    const PUBLIC_RATE_LIMIT    = 10;       // orders/hour/IP (public upload)
    const GALLERY_RATE_LIMIT   = 20;       // orders/hour/IP (gallery cart)

    /**
     * Non-printable line items. They are real lines on the order — they
     * carry money and must be summed by every consumer — but they have no
     * photo, so the file builder and the provider costing skip them.
     */
    const ITEM_CROP_SERVICE = 'crop_service';
    const ITEM_DELIVERY     = 'delivery_fee';
    const ITEM_MEETUP       = 'meetup';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
        add_shortcode( 'tweller_prints', array( __CLASS__, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 20 );
        add_action( 'admin_init', array( __CLASS__, 'handle_admin_actions' ) );

        // WiPay card payments: TwellerFlow2_WiPay fires this after a
        // verified successful checkout for a print order.
        add_action( 'tweller_wipay_paid', array( __CLASS__, 'on_wipay_paid' ), 10, 3 );

        // Self-heal: create tables / page / defaults without reactivation
        // (same pattern as gallery & culling auto-upgrade).
        self::maybe_install();
    }

    public static function get_statuses() {
        return array( 'new', 'confirmed', 'printing', 'ready', 'completed', 'cancelled' );
    }

    /** Human labels — 'confirmed' means "Payment confirmed" everywhere. */
    public static function get_status_labels() {
        return array(
            'new'       => 'New / awaiting payment',
            'confirmed' => 'Payment confirmed',
            'printing'  => 'Printing',
            'ready'     => 'Ready to hand over',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        );
    }

    public static function get_status_label( $status ) {
        $labels = self::get_status_labels();
        return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( (string) $status );
    }

    // ── Install / self-heal ────────────────────────────

    public static function maybe_install() {
        if ( get_option( self::OPT_VERSION, '' ) === self::VERSION ) return;
        self::create_tables();
        self::ensure_page();
        if ( get_option( self::OPT_PRODUCTS, false ) === false ) {
            add_option( self::OPT_PRODUCTS, self::default_products() );
        } else {
            self::strip_supplier_names();
        }
        update_option( self::OPT_VERSION, self::VERSION );
    }

    /**
     * Album products are made by a trade supplier and resold as our own —
     * their brand never belongs in customer-facing product names.
     */
    private static function strip_supplier_names() {
        $products = get_option( self::OPT_PRODUCTS, array() );
        if ( ! is_array( $products ) ) return;

        $changed = false;
        foreach ( $products as &$p ) {
            if ( empty( $p['name'] ) ) continue;
            $clean = trim( preg_replace( '/\bZno\b\s*/i', '', $p['name'] ) );
            if ( $clean !== '' && $clean !== $p['name'] ) {
                $p['name'] = $clean;
                $changed   = true;
            }
        }
        unset( $p );

        if ( $changed ) {
            update_option( self::OPT_PRODUCTS, $products );
        }
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
            payment longtext,
            payment_review tinyint(1) NOT NULL DEFAULT 0,
            receipt_url varchar(500) DEFAULT NULL,
            receipt_ocr varchar(190) DEFAULT NULL,
            receipt_reference varchar(64) DEFAULT NULL,
            receipt_confirmed_amount decimal(10,2) DEFAULT NULL,
            receipt_uploaded_at datetime DEFAULT NULL,
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
            array( 'book_layflat_8x8',    'Layflat Photobook 8×8 (20 pages)',   8,  8,  650 ),
            array( 'book_layflat_10x10',  'Layflat Photobook 10×10 (20 pages)', 10, 10, 850 ),
            array( 'book_flushmount_12x12', 'Premium Flushmount Album 12×12',   12, 12, 1400 ),
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

    /** Meet-up points offered at checkout when the customer isn't taking delivery. */
    public static function default_meetup_points() {
        return array( 'Heartland Plaza', 'Sun Plaza', 'Prize Plaza', 'Xtra Plaza' );
    }

    public static function get_settings() {
        $defaults = array(
            'notify_email'         => get_option( 'admin_email' ),
            'pickup_note'          => "Prints are usually ready within 7–10 business days. We'll meet you at your chosen meet-up point, or deliver anywhere in Trinidad & Tobago for a flat fee.",
            'payment_instructions' => '',
            'hero_headline'        => 'Your memories, beautifully printed.',
            'hero_subheadline'     => 'Museum-grade prints, gallery canvases and handcrafted albums — delivered across Trinidad & Tobago.',
            'hero_intro'           => '',
            'hero_bg_url'          => '',
            // "We'll crop it for you" service. Free by default; set a fee
            // here to start charging for it without touching any code.
            'crop_service_enabled' => 1,
            'crop_service_fee'     => 0,
            'crop_service_label'   => 'Let us crop them for you',
            'crop_service_note'    => 'Our editors will centre and crop every photo for the size you chose — the same way we prepare prints in studio.',
            // Fulfilment. Meet-up is free at one of the points below;
            // delivery is a flat fee. Both are editable here, never in code.
            'delivery_fee'         => 40,
            'delivery_label'       => 'Delivery',
            'meetup_label'         => 'Meet-up',
            'meetup_points'        => self::default_meetup_points(),
            // Studio addresses that also get print-partner approval alerts.
            'partner_alert_emails' => 'hello@twellerstudios.com, stephen.twellerstudios@gmail.com',
        );
        $saved = get_option( self::OPT_SETTINGS, array() );
        if ( ! is_array( $saved ) ) $saved = array();
        $merged = array_merge( $defaults, $saved );
        // Never let a blank save wipe the storefront copy
        foreach ( array( 'hero_headline', 'hero_subheadline', 'pickup_note', 'delivery_label', 'meetup_label' ) as $key ) {
            if ( trim( (string) $merged[ $key ] ) === '' ) {
                $merged[ $key ] = $defaults[ $key ];
            }
        }
        $merged['delivery_fee']  = max( 0, round( (float) $merged['delivery_fee'], 2 ) );
        $merged['meetup_points'] = self::sanitize_meetup_points( $merged['meetup_points'] );
        if ( empty( $merged['meetup_points'] ) ) {
            $merged['meetup_points'] = $defaults['meetup_points'];
        }
        return $merged;
    }

    /**
     * Meet-up points from either a textarea (one per line) or an array.
     * Returns a clean, de-duplicated, re-indexed list of plain strings.
     */
    public static function sanitize_meetup_points( $raw ) {
        if ( is_string( $raw ) ) {
            $raw = preg_split( '/[\r\n]+/', $raw );
        }
        if ( ! is_array( $raw ) ) return array();

        $out = array();
        foreach ( $raw as $point ) {
            if ( is_array( $point ) ) continue;
            $point = trim( sanitize_text_field( (string) $point ) );
            if ( $point === '' ) continue;
            if ( in_array( $point, $out, true ) ) continue;
            $out[] = $point;
        }
        return $out;
    }

    /** Flat delivery fee (TTD). Always read from settings, never from the client. */
    public static function get_delivery_fee() {
        $settings = self::get_settings();
        return max( 0, round( (float) $settings['delivery_fee'], 2 ) );
    }

    public static function get_meetup_points() {
        $settings = self::get_settings();
        return $settings['meetup_points'];
    }

    /**
     * A customer-supplied meet-up point is only accepted when it matches a
     * configured point exactly — we never invent a place to meet someone.
     */
    public static function match_meetup_point( $wanted ) {
        $wanted = trim( (string) $wanted );
        if ( $wanted === '' ) return '';
        foreach ( self::get_meetup_points() as $point ) {
            if ( strcasecmp( $point, $wanted ) === 0 ) return $point;
        }
        return '';
    }

    /** Extra studio addresses for print-partner alerts (settings-driven). */
    public static function get_partner_alert_emails() {
        $settings = self::get_settings();
        $out      = array();
        foreach ( preg_split( '/[,;\s]+/', (string) $settings['partner_alert_emails'] ) as $email ) {
            $email = sanitize_email( trim( $email ) );
            if ( $email !== '' && is_email( $email ) && ! in_array( $email, $out, true ) ) {
                $out[] = $email;
            }
        }
        return $out;
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

    // ── Customer order portal (tokenized) ──────────────

    /** HMAC token binding an order ref to its customer email. */
    public static function portal_token( $order_ref, $customer_email ) {
        return substr( hash_hmac( 'sha256', $order_ref . '|' . $customer_email, wp_salt( 'auth' ) ), 0, 20 );
    }

    /** Tokenized customer portal URL for an order (row object). */
    public static function portal_url( $order ) {
        if ( ! $order || empty( $order->order_ref ) ) {
            return self::get_prints_page_url();
        }
        return add_query_arg(
            array(
                'order' => $order->order_ref,
                't'     => self::portal_token( $order->order_ref, $order->customer_email ),
            ),
            self::get_prints_page_url()
        );
    }

    public static function get_order_by_ref( $order_ref ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_ORDERS;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE order_ref = %s", $order_ref ) );
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

        $s = self::get_settings();
        $defaults['cropService'] = array(
            'enabled' => ! empty( $s['crop_service_enabled'] ),
            'fee'     => (float) $s['crop_service_fee'],
            'label'   => (string) $s['crop_service_label'],
            'note'    => (string) $s['crop_service_note'],
        );
        // The fee shown at checkout is the same number insert_order() charges.
        $defaults['fulfilment'] = array(
            'deliveryFee'   => self::get_delivery_fee(),
            'deliveryLabel' => (string) $s['delivery_label'],
            'meetupLabel'   => (string) $s['meetup_label'],
            'meetupPoints'  => array_values( self::get_meetup_points() ),
        );

        // Payment. "Pay by card" is only ever offered when WiPay is really
        // configured; with it off the checkout quietly becomes bank
        // transfer only rather than showing a button that cannot work.
        $defaults['wipay']    = ( class_exists( 'TwellerFlow2_WiPay' ) && TwellerFlow2_WiPay::is_enabled() ) ? 1 : 0;
        $defaults['bankText'] = self::get_payment_instructions();
        $defaults['ocrUrl']   = self::OCR_SCRIPT_URL;
        wp_localize_script( 'tweller-flow-2-prints', 'twellerFlow2Prints', array_merge( $defaults, $config ) );
    }

    /**
     * [tweller_prints] — storefront homepage, or the tokenized customer
     * order portal when the URL carries ?order={ref}&t={token}.
     */
    public static function render_shortcode( $atts ) {
        $order_ref = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : '';
        $token     = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';

        if ( $order_ref !== '' && $token !== '' ) {
            return self::render_portal( $order_ref, $token );
        }
        return self::render_storefront();
    }

    /** CSS-only product mockup illustration for the storefront cards. */
    private static function mockup_html( $product ) {
        $w = max( 1, (float) ( $product['width_in'] ?: 3 ) );
        $h = max( 1, (float) ( $product['height_in'] ?: 2 ) );
        $ratio = esc_attr( $w . ' / ' . $h );

        switch ( $product['category'] ) {
            case 'canvas':
                return '<span class="tf2-mock tf2-mock--canvas"><span class="tf2-mock__canvas" style="aspect-ratio:' . $ratio . ';"><span class="tf2-mock__scene"></span><span class="tf2-mock__canvas-edge"></span></span></span>';
            case 'photobook':
                return '<span class="tf2-mock tf2-mock--book"><span class="tf2-mock__book"><span class="tf2-mock__page tf2-mock__page--left"></span><span class="tf2-mock__spine"></span><span class="tf2-mock__page tf2-mock__page--right"><span class="tf2-mock__page-photo"></span></span></span></span>';
            default: // framed print with mat
                return '<span class="tf2-mock tf2-mock--print"><span class="tf2-mock__frame" style="aspect-ratio:' . $ratio . ';"><span class="tf2-mock__mat"><span class="tf2-mock__scene"></span></span></span></span>';
        }
    }

    /** Default storefront homepage: hero → products → how it works → order flow. */
    private static function render_storefront() {
        self::enqueue_store_assets( array( 'mode' => 'public' ) );

        $settings   = self::get_settings();
        $products   = self::get_active_products();
        $categories = array(
            'print'     => array( 'label' => 'Fine-Art Prints',  'blurb' => 'Rich, true-to-colour photographic prints on professional lustre paper.' ),
            'canvas'    => array( 'label' => 'Gallery Canvas',   'blurb' => 'Ready-to-hang wrapped canvas — your photo, gallery depth, no framing needed.' ),
            'photobook' => array( 'label' => 'Albums & Photobooks', 'blurb' => 'Handcrafted layflat albums, designed together with you after you order.' ),
        );
        $grouped = array();
        foreach ( $products as $p ) {
            $grouped[ $p['category'] ][] = $p;
        }

        $hero_style = '';
        if ( ! empty( $settings['hero_bg_url'] ) ) {
            $hero_style = " style=\"background-image:linear-gradient(rgba(16,16,16,0.62), rgba(16,16,16,0.72)), url('" . esc_url( $settings['hero_bg_url'] ) . "');\"";
        }

        ob_start();
        ?>
        <div class="tf2-prints tf2-shop" id="tf2-prints-app">

            <section class="tf2-shop__hero<?php echo $settings['hero_bg_url'] ? ' tf2-shop__hero--img' : ''; ?>"<?php echo $hero_style; // phpcs:ignore -- escaped above ?>>
                <span class="tf2-prints__eyebrow">Tweller Studios Print Shop</span>
                <h2 class="tf2-shop__headline"><?php echo esc_html( $settings['hero_headline'] ); ?></h2>
                <p class="tf2-shop__sub"><?php echo esc_html( $settings['hero_subheadline'] ); ?></p>
                <?php if ( trim( (string) $settings['hero_intro'] ) !== '' ) : ?>
                    <p class="tf2-shop__intro"><?php echo esc_html( $settings['hero_intro'] ); ?></p>
                <?php endif; ?>
                <div class="tf2-shop__cta">
                    <a href="#tf2p-start" class="tf2p-btn tf2p-btn--gold">Start your order</a>
                    <a href="#tf2-shop-products" class="tf2p-btn tf2p-btn--ghost">See products &amp; pricing</a>
                </div>
            </section>

            <section class="tf2-shop__products" id="tf2-shop-products">
                <?php foreach ( $categories as $cat_key => $cat ) :
                    if ( empty( $grouped[ $cat_key ] ) ) continue; ?>
                    <div class="tf2-shop__group tf2-reveal">
                        <h3 class="tf2-shop__cat"><?php echo esc_html( $cat['label'] ); ?></h3>
                        <p class="tf2-shop__cat-blurb"><?php echo esc_html( $cat['blurb'] ); ?></p>
                        <div class="tf2-shop__grid">
                            <?php foreach ( $grouped[ $cat_key ] as $p ) :
                                $size = ( $p['width_in'] && $p['height_in'] ) ? ( rtrim( rtrim( (string) $p['width_in'], '0' ), '.' ) . '×' . rtrim( rtrim( (string) $p['height_in'], '0' ), '.' ) . '"' ) : '';
                            ?>
                            <a href="#tf2p-start" class="tf2-shop-card tf2-reveal">
                                <span class="tf2-shop-card__art"><?php echo self::mockup_html( $p ); // phpcs:ignore -- built + escaped above ?></span>
                                <span class="tf2-shop-card__body">
                                    <span class="tf2-shop-card__name"><?php echo esc_html( $p['name'] ); ?></span>
                                    <span class="tf2-shop-card__meta"><?php echo esc_html( trim( ( $size ? $size . ' · ' : '' ) . 'TT$ ' . number_format( (float) $p['price'], 2 ) ) ); ?></span>
                                </span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="tf2-shop__how tf2-reveal">
                <h3 class="tf2-shop__cat">How it works</h3>
                <ol class="tf2-shop__steps">
                    <li class="tf2-shop__step"><span class="tf2-shop__step-num">1</span><strong>Upload your photos</strong><span>Add your favourites straight from your phone or computer.</span></li>
                    <li class="tf2-shop__step"><span class="tf2-shop__step-num">2</span><strong>Choose sizes &amp; finishes</strong><span>Prints, canvas or albums — see a live preview of every size.</span></li>
                    <li class="tf2-shop__step"><span class="tf2-shop__step-num">3</span><strong>We print</strong><span>Pay by card or bank transfer, then meet us at a plaza or take delivery across T&amp;T.</span></li>
                </ol>
            </section>

            <section class="tf2-shop__start" id="tf2p-start">
                <h3 class="tf2-shop__cat">Start your order</h3>
                <p class="tf2-shop__cat-blurb">Add your photos below, tap to select the ones you want, then continue.</p>

                <div class="tf2-prints__dropzone" id="tf2p-dropzone" role="button" tabindex="0">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    <p class="tf2-prints__dz-title">Tap to add your photos</p>
                    <p class="tf2-prints__dz-note">JPEG or PNG · up to <?php echo (int) self::MAX_PUBLIC_FILES; ?> photos · 25MB each</p>
                    <input type="file" id="tf2p-file-input" accept="image/jpeg,image/png" multiple style="display:none;">
                </div>

                <div class="tf2-prints__grid" id="tf2p-upload-grid"></div>
            </section>

            <div class="tf2-prints__cartbar" id="tf2p-cartbar" style="display:none;">
                <span class="tf2-prints__cartbar-label" id="tf2p-cartbar-label"></span>
                <button type="button" class="tf2p-btn tf2p-btn--gold" id="tf2p-cartbar-btn">Review &amp; Checkout</button>
            </div>

            <noscript><p style="text-align:center;">The print shop needs JavaScript. Please enable it, or email <a href="mailto:hello@twellerstudios.com">hello@twellerstudios.com</a> to order.</p></noscript>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Tokenized customer order portal. */
    private static function render_portal( $order_ref, $token ) {
        $order = self::get_order_by_ref( $order_ref );
        $valid = $order && hash_equals( self::portal_token( $order->order_ref, $order->customer_email ), $token );

        wp_enqueue_style( 'tweller-flow-2-prints' );

        if ( ! $valid ) {
            ob_start();
            ?>
            <div class="tf2-prints tf2-portal">
                <div class="tf2-portal__card tf2-portal__invalid">
                    <h2>We couldn't open that order</h2>
                    <p>This order link is invalid or has expired. Please use the link from your order email, or contact us at <a href="mailto:hello@twellerstudios.com">hello@twellerstudios.com</a>.</p>
                    <a class="tf2p-btn tf2p-btn--dark" href="<?php echo esc_url( self::get_prints_page_url() ); ?>">Visit the print shop</a>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        $settings      = self::get_settings();
        $summary       = self::order_summary( $order );
        $fulfilment    = self::get_order_fulfilment( $order );
        $payment_text  = self::get_payment_instructions();
        $wipay_ok      = class_exists( 'TwellerFlow2_WiPay' ) && TwellerFlow2_WiPay::is_enabled();
        $wipay_url     = $wipay_ok ? TwellerFlow2_WiPay::checkout_url( 'print', $order->order_ref ) : '';
        $has_review    = ! empty( $order->payment_review );
        $awaiting_pay  = ( $order->status === 'new' );

        self::enqueue_store_assets( array(
            'mode'          => 'portal',
            'orderRef'      => $order->order_ref,
            'portalToken'   => $token,
            'orderStatus'   => $order->status,
            'orderSubtotal' => (float) $order->subtotal,
        ) );
        if ( $awaiting_pay ) {
            // Same client-side OCR stack as the booking receipt flow.
            wp_enqueue_script( 'tesseract-js', self::OCR_SCRIPT_URL, array(), null, true );
        }

        // WiPay return banners
        $banner = '';
        $banner_class = '';
        $pay_flag = isset( $_GET['payment'] ) ? sanitize_key( $_GET['payment'] ) : '';
        if ( $pay_flag === 'success' ) {
            $banner = 'Payment received — thank you! Your order is confirmed and heading into production.';
            $banner_class = 'tf2-portal__banner--success';
            // Even if the confirmation hook is a moment behind, never
            // show the payment section again after a successful charge.
            $awaiting_pay = false;
        } elseif ( $pay_flag === 'failed' ) {
            $banner = "Your card payment didn't go through and no charge was made. You can try again below, or pay by bank transfer.";
            $banner_class = 'tf2-portal__banner--error';
        } elseif ( $pay_flag === 'error' ) {
            $banner = 'Something went wrong while processing your payment. Please try again — if it keeps happening, reply to your order email and we\'ll help.';
            $banner_class = 'tf2-portal__banner--error';
        } elseif ( $pay_flag === 'unverified' ) {
            $banner = "We've received a payment response and are verifying it now. You'll get an email as soon as your order is confirmed.";
            $banner_class = 'tf2-portal__banner--warn';
        }

        // Status timeline
        $steps = array(
            'new'       => 'New',
            'confirmed' => 'Payment confirmed',
            'printing'  => 'Printing',
            'ready'     => 'Ready',
            'completed' => 'Completed',
        );
        $step_keys   = array_keys( $steps );
        $current_idx = array_search( $order->status, $step_keys, true );
        if ( $current_idx === false ) $current_idx = -1; // cancelled

        ob_start();
        ?>
        <div class="tf2-prints tf2-portal" id="tf2-prints-app">

            <div class="tf2-portal__head">
                <span class="tf2-prints__eyebrow">Tweller Studios Print Shop</span>
                <h2 class="tf2-portal__title">Your order</h2>
                <p class="tf2-portal__ref"><?php echo esc_html( $order->order_ref ); ?></p>
                <p class="tf2-portal__placed">Placed <?php echo esc_html( date( 'F j, Y', strtotime( $order->created_at ) ) ); ?> · <?php echo esc_html( $order->customer_name ); ?></p>
            </div>

            <?php if ( $banner !== '' ) : ?>
                <div class="tf2-portal__banner <?php echo esc_attr( $banner_class ); ?>"><?php echo esc_html( $banner ); ?></div>
            <?php endif; ?>

            <?php if ( $order->status === 'cancelled' ) : ?>
                <div class="tf2-portal__banner tf2-portal__banner--error">This order has been cancelled. If that's unexpected, reply to your order email and we'll sort it out.</div>
            <?php else : ?>
            <div class="tf2-portal__card">
                <p class="tf2-portal__label">Order status</p>
                <ol class="tf2-timeline">
                    <?php foreach ( $step_keys as $i => $key ) :
                        $state = $i < $current_idx ? 'done' : ( $i === $current_idx ? 'current' : 'todo' ); ?>
                        <li class="tf2-timeline__step tf2-timeline__step--<?php echo esc_attr( $state ); ?>">
                            <span class="tf2-timeline__dot"><?php if ( $state === 'done' ) : ?><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4"><polyline points="20 6 9 17 4 12"/></svg><?php endif; ?></span>
                            <span class="tf2-timeline__text"><?php echo esc_html( $steps[ $key ] ); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if ( $awaiting_pay && $has_review ) : ?>
                    <p class="tf2-portal__reviewnote">Receipt received — we'll confirm your payment shortly.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="tf2-portal__card">
                <p class="tf2-portal__label">Order summary</p>
                <?php foreach ( $summary['groups'] as $g ) :
                    $label = self::summary_label( $g );
                    $meta = '× ' . (int) $g['qty'] . ' @ TT$ ' . number_format( $g['unit'], 2 );
                    if ( empty( $g['service'] ) && (int) $g['photos'] > 1 ) $meta .= ' · ' . (int) $g['photos'] . ' photos';
                ?>
                    <div class="tf2-portal__line">
                        <span class="tf2-portal__line-info">
                            <span class="tf2-portal__line-name"><?php echo esc_html( $label ); ?></span>
                            <span class="tf2-portal__line-meta"><?php echo esc_html( $meta ); ?></span>
                        </span>
                        <span class="tf2-portal__line-price">TT$ <?php echo esc_html( number_format( $g['line'], 2 ) ); ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="tf2-portal__total"><span>Total</span><strong>TT$ <?php echo esc_html( number_format( (float) $order->subtotal, 2 ) ); ?></strong></div>
            </div>

            <?php if ( $awaiting_pay ) : ?>
            <div class="tf2-portal__card" id="tf2pp-payment">
                <p class="tf2-portal__label">Payment</p>
                <p class="tf2-portal__due">Amount due <strong>TT$ <?php echo esc_html( number_format( (float) $order->subtotal, 2 ) ); ?></strong></p>

                <?php if ( $wipay_ok ) : ?>
                    <a class="tf2p-btn tf2p-btn--dark tf2p-btn--full tf2-portal__paybtn" href="<?php echo esc_url( $wipay_url ); ?>">Pay by card</a>
                    <p class="tf2-portal__paysub">Secure checkout · Visa &amp; Mastercard via WiPay</p>
                <?php endif; ?>

                <?php if ( $payment_text !== '' ) : ?>
                    <div class="tf2-portal__divider"><span><?php echo $wipay_ok ? 'Or pay by bank transfer' : 'Pay by bank transfer'; ?></span></div>
                    <pre class="tf2-portal__bank"><?php echo esc_html( $payment_text ); ?></pre>

                    <div id="tf2pp-receipt">
                        <?php if ( $has_review ) : ?>
                            <div class="tf2-portal__received">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                Receipt received — we'll confirm shortly.
                            </div>
                            <p class="tf2-portal__paysub">Sent the wrong screenshot? You can upload a new one below and it will replace the previous receipt.</p>
                        <?php endif; ?>

                        <input type="file" id="tf2pp-receipt-input" accept="image/*" style="display:none;">
                        <button type="button" class="tf2p-btn <?php echo $has_review ? 'tf2p-btn--ghost' : 'tf2p-btn--gold'; ?> tf2p-btn--full" id="tf2pp-receipt-pick">Upload transfer receipt</button>

                        <div id="tf2pp-receipt-flow" style="display:none;">
                            <img id="tf2pp-receipt-preview" class="tf2-portal__receipt-preview" alt="Receipt preview">
                            <p id="tf2pp-receipt-note" class="tf2-portal__receipt-note"></p>
                            <label class="tf2p-field"><span>Amount transferred (TT$)</span><input type="number" step="0.01" min="0" inputmode="decimal" id="tf2pp-receipt-amount"></label>
                            <p class="tf2p-error" id="tf2pp-receipt-error" style="display:none;"></p>
                            <button type="button" class="tf2p-btn tf2p-btn--gold tf2p-btn--full" id="tf2pp-receipt-submit">Confirm &amp; submit receipt</button>
                        </div>

                        <div class="tf2-portal__received" id="tf2pp-receipt-done" style="display:none;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            Receipt received — we'll confirm shortly.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="tf2-portal__card">
                <p class="tf2-portal__label">Getting it to you</p>
                <?php if ( $fulfilment['mode'] === 'delivery' ) : ?>
                    <p class="tf2-portal__note"><strong>Delivery</strong> — TT$ <?php echo esc_html( number_format( $fulfilment['fee'], 2 ) ); ?>, included in your total.</p>
                <?php elseif ( $fulfilment['mode'] === 'meetup' ) : ?>
                    <p class="tf2-portal__note"><strong>Meet-up (free)</strong> — <?php echo esc_html( $fulfilment['location'] ); ?>. We'll confirm a time with you once your prints are ready.</p>
                <?php endif; ?>
                <p class="tf2-portal__note"><?php echo esc_html( $settings['pickup_note'] ); ?></p>
                <?php if ( $order->notes ) : ?>
                    <p class="tf2-portal__note"><strong>Your notes:</strong> <?php echo esc_html( $order->notes ); ?></p>
                <?php endif; ?>
            </div>

            <p class="tf2-portal__help">Questions? Reply to your order email or write to <a href="mailto:hello@twellerstudios.com">hello@twellerstudios.com</a>.</p>
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

        // Customer portal: bank-transfer receipt upload (public, tokenized)
        register_rest_route( $ns, '/prints/portal-receipt', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_upload_portal_receipt' ),
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

        // Send a whole session gallery to the print lab (API key) — the
        // same studio-initiated order the admin screens create, so the
        // phone can do everything the desk can.
        register_rest_route( $ns, '/prints/studio-order', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_studio_order' ),
            'permission_callback' => array( 'TwellerFlow2_Photo_Automation', 'verify_api_key' ),
        ));

        // What the app needs to build that form: products, providers,
        // meet-up points and the delivery fee, in one call.
        register_rest_route( $ns, '/prints/studio-options', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_studio_options' ),
            'permission_callback' => array( 'TwellerFlow2_Photo_Automation', 'verify_api_key' ),
        ));
    }

    /** Options the app needs to offer "send to print lab". */
    public static function rest_studio_options( $request ) {
        $settings = self::get_settings();

        $products = array();
        foreach ( self::get_active_products() as $p ) {
            $products[] = array(
                'id'        => (string) $p['id'],
                'name'      => (string) $p['name'],
                'category'  => (string) $p['category'],
                'price'     => (float) $p['price'],
                'width_in'  => (float) $p['width_in'],
                'height_in' => (float) $p['height_in'],
            );
        }

        $providers = array();
        if ( class_exists( 'TwellerFlow2_Print_Providers' ) ) {
            foreach ( TwellerFlow2_Print_Providers::providers() as $pv ) {
                if ( empty( $pv['active'] ) ) continue;
                $providers[] = array(
                    'id'      => (string) $pv['id'],
                    'name'    => (string) $pv['name'],
                    'default' => ! empty( $pv['default'] ) ? 1 : 0,
                );
            }
        }

        return rest_ensure_response( array(
            'ok'            => true,
            'products'      => $products,
            'providers'     => $providers,
            'meetup_points' => self::get_meetup_points(),
            'delivery_fee'  => (float) $settings['delivery_fee'],
            'currency'      => 'TT$',
        ) );
    }

    /** Create a studio print order for a session gallery, from the app. */
    public static function rest_studio_order( $request ) {
        $session_code = sanitize_text_field( (string) $request->get_param( 'session_code' ) );
        $session_id   = (int) $request->get_param( 'session_id' );

        if ( $session_id <= 0 && $session_code !== '' && class_exists( 'TwellerFlow2_Session' ) ) {
            $session = TwellerFlow2_Session::get_by_code( $session_code );
            if ( $session ) $session_id = (int) $session->id;
        }
        if ( $session_id <= 0 ) {
            return new WP_Error( 'bad_session', 'Send a session_id or a session_code.', array( 'status' => 400 ) );
        }

        $photo_ids = $request->get_param( 'photo_ids' );
        if ( is_string( $photo_ids ) ) {
            $photo_ids = array_filter( array_map( 'trim', explode( ',', $photo_ids ) ) );
        }

        $result = self::create_studio_order( array(
            'session_id'      => $session_id,
            'product_id'      => sanitize_text_field( (string) $request->get_param( 'product_id' ) ),
            'qty'             => (int) $request->get_param( 'qty' ),
            'photo_ids'       => is_array( $photo_ids ) ? $photo_ids : array(),
            'fulfilment'      => sanitize_key( (string) $request->get_param( 'fulfilment' ) ),
            'meetup_location' => sanitize_text_field( (string) $request->get_param( 'meetup_location' ) ),
            'notes'           => sanitize_textarea_field( (string) $request->get_param( 'notes' ) ),
            'provider_id'     => sanitize_key( (string) $request->get_param( 'provider_id' ) ),
            'notify_customer' => ! empty( $request->get_param( 'notify_customer' ) ),
        ) );

        if ( is_wp_error( $result ) ) {
            $result->add_data( array( 'status' => 400 ), $result->get_error_code() );
            return $result;
        }

        return rest_ensure_response( array_merge( array( 'ok' => true ), (array) $result ) );
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
        if ( is_array( $raw_items ) && count( $raw_items ) > self::MAX_ORDER_ITEMS ) {
            return new WP_Error(
                'too_many_items',
                sprintf( 'This order has %d items, more than the %d we can take in one go. Please split it into two orders.', count( $raw_items ), self::MAX_ORDER_ITEMS ),
                array( 'status' => 400 )
            );
        }

        $items = self::sanitize_cart_items( $raw_items, true );
        if ( empty( $items ) ) {
            return new WP_Error( 'no_items', 'Your cart is empty.', array( 'status' => 400 ) );
        }

        // A dropped line item means an under-billed order. If anything failed
        // validation, refuse rather than quietly charge for fewer photos.
        if ( is_array( $raw_items ) && count( $items ) !== count( $raw_items ) ) {
            return new WP_Error(
                'items_rejected',
                sprintf( 'Only %d of %d items could be verified. Nothing has been charged — please refresh the gallery and try again.', count( $items ), count( $raw_items ) ),
                array( 'status' => 400 )
            );
        }

        $order_id = self::insert_order( array(
            'session_id'      => $session ? (int) $session->id : null,
            'session_code'    => $session ? $session->tracking_code : null,
            'customer_name'   => $name,
            'customer_email'  => $email,
            'customer_phone'  => $phone,
            'items'           => $items,
            'notes'           => $notes,
            'source'          => 'gallery',
            'crop_service'    => ! empty( $request->get_param( 'crop_service' ) ),
            'fulfilment'      => $request->get_param( 'fulfilment' ),
            'meetup_location' => sanitize_text_field( (string) $request->get_param( 'meetup_location' ) ),
            'shipping'        => $request->get_param( 'shipping' ),
        ));

        if ( is_wp_error( $order_id ) ) return $order_id;
        if ( ! $order_id ) {
            return new WP_Error( 'save_failed', 'Could not save your order. Please try again.', array( 'status' => 500 ) );
        }

        $order = self::get_order( $order_id );
        self::send_customer_confirmation( $order );
        self::send_studio_alert( $order );

        return rest_ensure_response( self::order_created_payload( $order ) );
    }

    /**
     * What the checkout gets back after an order is stored.
     *
     * `subtotal` is read straight off the saved row — it is the number
     * insert_order() computed from the catalog plus the server-side fee
     * lines, never anything the browser posted — and it is what the
     * payment step is told to collect. `pay_url` lets the card path go
     * directly into WiPay's hosted page instead of parking the customer on
     * an extra screen; `portal_token` lets the bank-transfer path run the
     * same tokenized receipt upload inline.
     */
    private static function order_created_payload( $order ) {
        $settings  = self::get_settings();
        $wipay_ok  = class_exists( 'TwellerFlow2_WiPay' ) && TwellerFlow2_WiPay::is_enabled();

        return array(
            'ok'                   => true,
            'order_ref'            => $order->order_ref,
            'subtotal'             => (float) $order->subtotal,
            'portal_url'           => self::portal_url( $order ),
            'portal_token'         => self::portal_token( $order->order_ref, $order->customer_email ),
            'wipay'                => $wipay_ok ? 1 : 0,
            'pay_url'              => $wipay_ok ? TwellerFlow2_WiPay::checkout_url( 'print', $order->order_ref ) : '',
            'payment_instructions' => self::get_payment_instructions(),
            'pickup_note'          => (string) $settings['pickup_note'],
        );
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
        foreach ( (array) $raw_items as $row ) {
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
                // {x,y,w,h,zoom} — a string cast here used to store the
                // literal "Array" and throw the customer's crop away.
                'crop'         => self::sanitize_crop( isset( $row['crop'] ) ? $row['crop'] : null ),
            );
        }

        if ( empty( $items ) ) {
            return new WP_Error( 'no_items', 'No valid products were selected.', array( 'status' => 400 ) );
        }

        // A dropped line is an under-billed order — refuse instead.
        if ( count( $items ) !== count( $raw_items ) ) {
            return new WP_Error(
                'items_rejected',
                sprintf( 'Only %d of %d items could be verified. Nothing has been charged — please try again.', count( $items ), count( $raw_items ) ),
                array( 'status' => 400 )
            );
        }

        $order_id = self::insert_order( array(
            'order_ref'       => $order_ref,
            'session_id'      => null,
            'session_code'    => null,
            'customer_name'   => $name,
            'customer_email'  => $email,
            'customer_phone'  => $phone,
            'items'           => $items,
            'notes'           => $notes,
            'source'          => 'public',
            'crop_service'    => ! empty( $_POST['crop_service'] ),
            'fulfilment'      => isset( $_POST['fulfilment'] ) ? sanitize_text_field( wp_unslash( $_POST['fulfilment'] ) ) : '',
            'meetup_location' => isset( $_POST['meetup_location'] ) ? sanitize_text_field( wp_unslash( $_POST['meetup_location'] ) ) : '',
            'shipping'        => isset( $_POST['shipping'] ) ? json_decode( wp_unslash( $_POST['shipping'] ), true ) : null,
        ));

        if ( is_wp_error( $order_id ) ) return $order_id;
        if ( ! $order_id ) {
            return new WP_Error( 'save_failed', 'Could not save your order. Please try again.', array( 'status' => 500 ) );
        }

        $order = self::get_order( $order_id );
        self::send_customer_confirmation( $order );
        self::send_studio_alert( $order );

        return rest_ensure_response( self::order_created_payload( $order ) );
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
        return rest_ensure_response( array(
            'ok'     => true,
            'orders' => $orders,
            'count'  => count( $orders ),
            'counts' => self::get_status_counts(),
        ));
    }

    /** Badge counts for the admin bubble + mobile app tab. */
    public static function get_status_counts() {
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE_ORDERS;
        $counts = array( 'new' => 0, 'payment_review' => 0, 'confirmed' => 0, 'printing' => 0, 'ready' => 0 );

        $rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c, SUM(CASE WHEN payment_review = 1 THEN 1 ELSE 0 END) AS pr FROM $table GROUP BY status" );
        foreach ( (array) $rows as $row ) {
            if ( isset( $counts[ $row->status ] ) ) {
                $counts[ $row->status ] = (int) $row->c;
            }
            if ( $row->status === 'new' ) {
                $counts['payment_review'] = (int) $row->pr;
            }
        }
        return $counts;
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

    /**
     * POST /prints/portal-receipt — bank-transfer receipt from the
     * customer portal. Public but tokenized + rate-limited. The image was
     * OCR'd client-side (Tesseract.js) exactly like the booking receipt.
     */
    public static function rest_upload_portal_receipt( $request ) {
        if ( ! self::check_rate_limit( 'receipt', 10 ) ) {
            return new WP_Error( 'rate_limited', 'Too many uploads from this address. Please try again later.', array( 'status' => 429 ) );
        }

        $order_ref = sanitize_text_field( $request->get_param( 'order' ) );
        $token     = sanitize_text_field( $request->get_param( 't' ) );
        $order     = $order_ref !== '' ? self::get_order_by_ref( $order_ref ) : null;

        if ( ! $order || $token === '' || ! hash_equals( self::portal_token( $order->order_ref, $order->customer_email ), $token ) ) {
            return new WP_Error( 'invalid_link', 'This order link is invalid or has expired.', array( 'status' => 403 ) );
        }
        if ( $order->status !== 'new' ) {
            return new WP_Error( 'already_processed', 'This order is no longer awaiting payment.', array( 'status' => 400 ) );
        }

        $files = $request->get_file_params();
        if ( empty( $files['receipt'] ) || ! is_array( $files['receipt'] ) ) {
            return new WP_Error( 'no_file', 'No receipt file was provided.', array( 'status' => 400 ) );
        }
        $file = $files['receipt'];
        if ( ! isset( $file['error'] ) || $file['error'] !== UPLOAD_ERR_OK || empty( $file['tmp_name'] ) ) {
            return new WP_Error( 'upload_error', 'The receipt could not be uploaded. Please try again.', array( 'status' => 400 ) );
        }
        if ( $file['size'] > 15728640 ) { // 15 MB
            return new WP_Error( 'too_large', 'Receipt images must be under 15MB.', array( 'status' => 400 ) );
        }

        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );
        if ( ! in_array( $mime, array( 'image/jpeg', 'image/jpg', 'image/png', 'image/webp' ), true ) ) {
            return new WP_Error( 'bad_type', 'Please upload a JPEG, PNG or WebP screenshot of your receipt.', array( 'status' => 400 ) );
        }

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
        }
        $movefile = wp_handle_upload( $file, array(
            'test_form' => false,
            'mimes'     => array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ),
        ));
        if ( ! $movefile || isset( $movefile['error'] ) ) {
            $msg = ( is_array( $movefile ) && isset( $movefile['error'] ) ) ? $movefile['error'] : 'Upload failed.';
            return new WP_Error( 'upload_failed', $msg, array( 'status' => 500 ) );
        }

        $ocr       = sanitize_text_field( $request->get_param( 'ocr_amounts' ) );
        $ocr_ref   = sanitize_text_field( $request->get_param( 'ocr_reference' ) );
        $confirmed = round( floatval( $request->get_param( 'confirmed_amount' ) ), 2 );

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_ORDERS;
        $wpdb->update( $table, array(
            'receipt_url'              => esc_url_raw( $movefile['url'] ),
            'receipt_ocr'              => $ocr,
            'receipt_reference'        => substr( $ocr_ref, 0, 64 ),
            'receipt_confirmed_amount' => $confirmed > 0 ? $confirmed : null,
            'receipt_uploaded_at'      => current_time( 'mysql' ),
            'payment_review'           => 1,
            'updated_at'               => current_time( 'mysql' ),
        ), array( 'id' => (int) $order->id ) );

        $order = self::get_order( $order->id );
        self::send_receipt_review_alert( $order );

        return rest_ensure_response( array(
            'ok'      => true,
            'message' => "Receipt received — we'll confirm shortly.",
        ));
    }

    /**
     * WiPay hook: fired by TwellerFlow2_WiPay after a verified successful
     * card payment — do_action( 'tweller_wipay_paid', 'print', $ref, $params ).
     */
    public static function on_wipay_paid( $type, $order_ref, $params = array() ) {
        if ( $type !== 'print' ) return;
        $order = self::get_order_by_ref( sanitize_text_field( (string) $order_ref ) );
        if ( ! $order ) return;
        if ( ! is_array( $params ) ) $params = array();

        $txn = '';
        foreach ( array( 'transaction_id', 'txn_id', 'order_id', 'reference' ) as $key ) {
            if ( ! empty( $params[ $key ] ) ) { $txn = sanitize_text_field( (string) $params[ $key ] ); break; }
        }
        $total = isset( $params['total'] ) ? round( floatval( $params['total'] ), 2 ) : (float) $order->subtotal;

        $payment = array(
            'method'         => 'wipay_card',
            'transaction_id' => $txn,
            'total'          => $total,
            'paid_at'        => current_time( 'mysql' ),
        );

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_ORDERS;
        $data  = array(
            'payment'        => wp_json_encode( $payment ),
            'payment_review' => 0,
            'updated_at'     => current_time( 'mysql' ),
        );
        $was_new = ( $order->status === 'new' );
        if ( $was_new ) {
            $data['status'] = 'confirmed';
        }
        $wpdb->update( $table, $data, array( 'id' => (int) $order->id ) );

        if ( $was_new ) {
            $order = self::get_order( $order->id );
            self::send_status_email( $order, 'confirmed' );
            self::send_studio_payment_alert( $order, $payment );
        }
    }

    // ── Order helpers ──────────────────────────────────

    public static function update_status( $order_id, $status, $notify = false ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_ORDERS;
        $data  = array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) );
        if ( $status !== 'new' ) {
            // Once the order moves past "new" it no longer needs receipt review.
            $data['payment_review'] = 0;
        }
        $wpdb->update( $table, $data, array( 'id' => intval( $order_id ) ) );

        // A cancelled order that is sitting with a print lab has to reach
        // the lab, not just the customer — otherwise they print a job that
        // nobody is going to pay for. Sent regardless of $notify, which
        // governs the customer's copy, not the lab's.
        if ( $status === 'cancelled' && class_exists( 'TwellerFlow2_Print_Providers' ) ) {
            $cancelled = self::get_order( $order_id );
            if ( $cancelled ) {
                TwellerFlow2_Print_Providers::notify_provider_order_update( $cancelled, 'cancelled' );
            }
        }

        if ( $notify ) {
            $order = self::get_order( $order_id );
            if ( $order ) {
                self::send_status_email( $order, $status );
            }
        }
    }

    /**
     * Admin "Confirm payment": records the verified bank transfer on the
     * order, moves it to 'confirmed' and optionally emails the customer.
     */
    public static function confirm_payment( $order_id, $notify = true ) {
        $order = self::get_order( $order_id );
        if ( ! $order ) return false;

        $amount = isset( $order->receipt_confirmed_amount ) && $order->receipt_confirmed_amount !== null
            ? (float) $order->receipt_confirmed_amount
            : (float) $order->subtotal;

        $payment = array(
            'method'         => 'bank_transfer',
            'transaction_id' => isset( $order->receipt_reference ) ? (string) $order->receipt_reference : '',
            'total'          => round( $amount, 2 ),
            'paid_at'        => current_time( 'mysql' ),
        );

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_ORDERS;
        $wpdb->update( $table, array(
            'payment'        => wp_json_encode( $payment ),
            'payment_review' => 0,
            'status'         => 'confirmed',
            'updated_at'     => current_time( 'mysql' ),
        ), array( 'id' => (int) $order->id ) );

        if ( $notify ) {
            $order = self::get_order( $order->id );
            self::send_status_email( $order, 'confirmed' );
        }
        return true;
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

    // ── Money & the one order summary ──────────────────

    /**
     * Is this line a fee/service rather than something we print?
     * Fees are real money on the order; they simply have no photo, so the
     * file builder and the provider costing skip them.
     */
    public static function is_service_item( $item ) {
        if ( ! is_array( $item ) ) return false;
        if ( isset( $item['category'] ) && (string) $item['category'] === 'service' ) return true;
        $id = isset( $item['product_id'] ) ? (string) $item['product_id'] : '';
        return in_array( $id, array( self::ITEM_CROP_SERVICE, self::ITEM_DELIVERY, self::ITEM_MEETUP ), true );
    }

    /** Only the printable lines — used by the file builder and the lab. */
    public static function printable_items( $items ) {
        $out = array();
        foreach ( (array) $items as $item ) {
            if ( self::is_service_item( $item ) ) continue;
            $out[] = $item;
        }
        return array_values( $out );
    }

    /**
     * THE order total. Everything that computes money — insert_order(),
     * the email summary, the portal — goes through this, in integer cents,
     * so no consumer can drift from the stored subtotal by a rounding step.
     */
    public static function items_total( $items ) {
        $cents = 0;
        foreach ( (array) $items as $item ) {
            if ( ! is_array( $item ) ) continue;
            $unit  = (int) round( ( (float) ( isset( $item['price'] ) ? $item['price'] : 0 ) ) * 100 );
            $qty   = max( 1, (int) ( isset( $item['qty'] ) ? $item['qty'] : 1 ) );
            $cents += $unit * $qty;
        }
        return $cents / 100;
    }

    /**
     * THE grouped order summary — one implementation shared by the customer
     * email, the studio alert, the status emails, the customer portal and
     * the provider job email. A 116-photo order is five or six rows here,
     * never 116, and the rows always add up to the stored subtotal.
     *
     * @return array{groups:array,pieces:int,photos:int,total:float}
     */
    public static function summarize_items( $items ) {
        $products = array();
        $services = array();
        $pieces   = 0;
        $photos   = 0;

        foreach ( (array) $items as $item ) {
            if ( ! is_array( $item ) ) continue;

            $qty     = max( 1, (int) ( isset( $item['qty'] ) ? $item['qty'] : 1 ) );
            $unit    = round( (float) ( isset( $item['price'] ) ? $item['price'] : 0 ), 2 );
            $service = self::is_service_item( $item );
            $name    = trim( (string) ( isset( $item['product_name'] ) ? $item['product_name'] : '' ) );
            if ( $name === '' ) $name = $service ? 'Service' : 'Print';

            $key  = ( isset( $item['product_id'] ) ? (string) $item['product_id'] : $name ) . '|' . number_format( $unit, 2, '.', '' );
            $into = $service ? 'services' : 'products';

            if ( ! isset( ${$into}[ $key ] ) ) {
                $product = ( ! $service && isset( $item['product_id'] ) ) ? self::get_product( (string) $item['product_id'] ) : null;
                $size    = '';
                if ( $product && ! empty( $product['width_in'] ) && ! empty( $product['height_in'] ) ) {
                    $size = self::trim_number( $product['width_in'] ) . '×' . self::trim_number( $product['height_in'] ) . '"';
                }
                ${$into}[ $key ] = array(
                    'product_id' => isset( $item['product_id'] ) ? (string) $item['product_id'] : '',
                    'label'      => $name,
                    'size'       => $size,
                    'unit'       => $unit,
                    'qty'        => 0,
                    'photos'     => 0,
                    'line'       => 0.0,
                    'service'    => $service ? 1 : 0,
                );
            }

            ${$into}[ $key ]['qty']    += $qty;
            ${$into}[ $key ]['photos'] += 1;
            // "Pieces" means physical prints. A delivery fee or crop-service
            // line is not a piece — counting it told the studio and the lab
            // there was one more print in the box than there really was.
            if ( ! $service ) {
                $pieces += $qty;
                $photos += 1;
            }
        }

        // Line totals from the same integer-cent arithmetic as items_total().
        foreach ( array( 'products', 'services' ) as $bucket ) {
            foreach ( ${$bucket} as $key => $group ) {
                ${$bucket}[ $key ]['line'] = ( (int) round( $group['unit'] * 100 ) * (int) $group['qty'] ) / 100;
            }
        }

        return array(
            'groups' => array_values( array_merge( array_values( $products ), array_values( $services ) ) ),
            'pieces' => $pieces,
            'photos' => $photos,
            'total'  => self::items_total( $items ),
        );
    }

    public static function order_summary( $order ) {
        return self::summarize_items( self::get_order_items( $order ) );
    }

    /** Row label for one summary group — the size only when it isn't already in the name. */
    public static function summary_label( $group ) {
        $label = (string) ( isset( $group['label'] ) ? $group['label'] : '' );
        $size  = (string) ( isset( $group['size'] ) ? $group['size'] : '' );
        if ( $size !== '' && strpos( $label, rtrim( $size, '"' ) ) === false ) {
            $label .= ' · ' . $size;
        }
        return $label;
    }

    /** 8.00 → "8", 11.50 → "11.5" */
    private static function trim_number( $n ) {
        $s = rtrim( rtrim( number_format( (float) $n, 2, '.', '' ), '0' ), '.' );
        return $s === '' ? '0' : $s;
    }

    /**
     * How this order is being handed over, read back off its own line items
     * so admin, emails and the app never disagree with what was charged.
     *
     * @return array{mode:string,label:string,location:string,fee:float}
     */
    public static function get_order_fulfilment( $order ) {
        $out = array( 'mode' => '', 'label' => '', 'location' => '', 'fee' => 0.0 );
        foreach ( self::get_order_items( $order ) as $item ) {
            if ( ! is_array( $item ) ) continue;
            $id = isset( $item['product_id'] ) ? (string) $item['product_id'] : '';
            if ( $id === self::ITEM_DELIVERY ) {
                $out['mode']  = 'delivery';
                $out['label'] = (string) ( isset( $item['product_name'] ) ? $item['product_name'] : 'Delivery' );
                $out['fee']   = round( (float) ( isset( $item['price'] ) ? $item['price'] : 0 ), 2 );
            } elseif ( $id === self::ITEM_MEETUP ) {
                $out['mode']     = 'meetup';
                $out['label']    = (string) ( isset( $item['product_name'] ) ? $item['product_name'] : 'Meet-up' );
                $out['location'] = (string) ( isset( $item['meetup_location'] ) ? $item['meetup_location'] : '' );
            }
        }
        return $out;
    }

    private static function format_order( $row ) {
        $payment = null;
        if ( isset( $row->payment ) && $row->payment ) {
            $decoded = json_decode( (string) $row->payment, true );
            if ( is_array( $decoded ) ) $payment = $decoded;
        }

        // Who has this job. Carried on every order in the list payload so the
        // app can answer "which lab is this with?" without a second call, and
        // read straight off fulfillment_stage() so the wording can never drift
        // from wp-admin's.
        $provider_id   = '';
        $provider_name = '';
        $assigned_at   = '';
        $stage         = null;
        if ( class_exists( 'TwellerFlow2_Print_Fulfillment' ) ) {
            $st = TwellerFlow2_Print_Fulfillment::fulfillment_stage( $row );
            $f  = TwellerFlow2_Print_Fulfillment::get_fulfillment( $row );

            $provider_id   = (string) $st['provider_id'];
            $provider_name = (string) $st['provider_name'];
            $assigned_at   = (string) $f['assigned_at'];
            $stage         = array(
                'key'           => (string) $st['key'],
                'short'         => (string) $st['short'],
                'label'         => (string) $st['label'],
                'tone'          => (string) $st['tone'],
                'provider_id'   => $provider_id,
                'provider_name' => $provider_name,
                // Honest about the package even when the lab is assigned.
                'files_built'   => ( is_array( $f['build'] ) && ! empty( $f['build']['done'] ) ) ? 1 : 0,
                'send_pending'  => ! empty( $f['send_pending'] ) ? 1 : 0,
                'build_error'   => (string) $f['build_error'],
            );
        }

        return array(
            'id'                       => (int) $row->id,
            'order_ref'                => $row->order_ref,
            'session_id'               => $row->session_id ? (int) $row->session_id : null,
            'session_code'             => $row->session_code,
            'customer_name'            => $row->customer_name,
            'customer_email'           => $row->customer_email,
            'customer_phone'           => $row->customer_phone,
            'items'                    => self::get_order_items( $row ),
            // The app shows the same grouped summary and the same fee lines
            // as the emails and the portal — one shared source of truth.
            'summary'                  => self::order_summary( $row ),
            'fulfilment'               => self::get_order_fulfilment( $row ),
            'subtotal'                 => (float) $row->subtotal,
            'status'                   => $row->status,
            'status_label'             => self::get_status_label( $row->status ),
            'source'                   => $row->source,
            'notes'                    => $row->notes,
            'payment'                  => $payment,
            'payment_review'           => ! empty( $row->payment_review ) ? 1 : 0,
            'receipt_url'              => isset( $row->receipt_url ) ? (string) $row->receipt_url : '',
            'receipt_ocr'              => isset( $row->receipt_ocr ) ? (string) $row->receipt_ocr : '',
            'receipt_reference'        => isset( $row->receipt_reference ) ? (string) $row->receipt_reference : '',
            'receipt_confirmed_amount' => ( isset( $row->receipt_confirmed_amount ) && $row->receipt_confirmed_amount !== null ) ? (float) $row->receipt_confirmed_amount : null,
            'receipt_uploaded_at'      => isset( $row->receipt_uploaded_at ) ? $row->receipt_uploaded_at : null,
            'portal_url'               => self::portal_url( $row ),
            'provider_id'              => $provider_id,
            'provider_name'            => $provider_name,
            'assigned_at'              => $assigned_at,
            'fulfillment_stage'        => $stage,
            'created_at'               => $row->created_at,
            'updated_at'               => $row->updated_at,
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
        foreach ( $raw_items as $row ) {
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
                'crop'         => self::sanitize_crop( $row['crop'] ?? null ),
            );
        }
        return $items;
    }

    /**
     * Crop arrives as {x,y,w,h,zoom} normalised 0-1. It used to be run
     * through sanitize_text_field() after a string cast, which turns an
     * array into the literal "Array" and threw the crop away entirely.
     */
    private static function sanitize_crop( $crop ) {
        if ( ! is_array( $crop ) ) return null;
        $out = array();
        foreach ( array( 'x', 'y', 'w', 'h', 'zoom' ) as $k ) {
            if ( ! isset( $crop[ $k ] ) || ! is_numeric( $crop[ $k ] ) ) continue;
            $out[ $k ] = round( (float) $crop[ $k ], 6 );
        }
        if ( ! isset( $out['w'], $out['h'] ) ) return null;
        return $out;
    }

    /**
     * Build the definitive line-item list for an order: the customer's
     * photos, then the crop service, then exactly one fulfilment line
     * (meet-up at TT$0 or delivery at the settings fee). Everything that
     * carries money is a line item, so every downstream consumer — emails,
     * admin, portal, provider view, economics — sums the same list.
     *
     * @return array|WP_Error
     */
    private static function build_order_items( $data ) {
        $items = isset( $data['items'] ) && is_array( $data['items'] ) ? array_values( $data['items'] ) : array();
        if ( empty( $items ) ) {
            return new WP_Error( 'no_items', 'This order has no items.', array( 'status' => 400 ) );
        }
        if ( count( $items ) > self::MAX_ORDER_ITEMS ) {
            return new WP_Error(
                'too_many_items',
                sprintf( 'This order has %d items, more than the %d we can take in one go. Please split it into two orders.', count( $items ), self::MAX_ORDER_ITEMS ),
                array( 'status' => 400 )
            );
        }

        $settings = self::get_settings();

        // "We'll crop it for you" — recorded as its own line so it shows up
        // in totals, emails, the admin order and the app. Free until a fee
        // is set under Print Store → Settings → Cropping Service.
        if ( ! empty( $data['crop_service'] ) && ! empty( $settings['crop_service_enabled'] ) ) {
            $items[] = array(
                'product_id'   => self::ITEM_CROP_SERVICE,
                'product_name' => (string) $settings['crop_service_label'],
                'category'     => 'service',
                'price'        => max( 0, round( (float) $settings['crop_service_fee'], 2 ) ),
                'qty'          => 1,
                'filename'     => '',
                'photo_url'    => '',
                'thumb_url'    => '',
                'crop'         => null,
            );
        }

        // Fulfilment. The fee ALWAYS comes from the settings — a client that
        // posts its own delivery price is simply ignored.
        $mode = ( isset( $data['fulfilment'] ) && (string) $data['fulfilment'] === 'delivery' ) ? 'delivery' : 'meetup';

        if ( $mode === 'delivery' ) {
            $items[] = array(
                'product_id'   => self::ITEM_DELIVERY,
                'product_name' => (string) $settings['delivery_label'],
                'category'     => 'service',
                'price'        => self::get_delivery_fee(),
                'qty'          => 1,
                'filename'     => '',
                'photo_url'    => '',
                'thumb_url'    => '',
                'crop'         => null,
            );
        } else {
            $location = self::match_meetup_point( isset( $data['meetup_location'] ) ? $data['meetup_location'] : '' );
            if ( $location === '' ) {
                return new WP_Error(
                    'bad_meetup',
                    'Please choose one of our meet-up points, or select delivery instead.',
                    array( 'status' => 400 )
                );
            }
            $items[] = array(
                'product_id'      => self::ITEM_MEETUP,
                'product_name'    => trim( (string) $settings['meetup_label'] ) . ' — ' . $location,
                'category'        => 'service',
                'price'           => 0.0,
                'qty'             => 1,
                'filename'        => '',
                'photo_url'       => '',
                'thumb_url'       => '',
                'crop'            => null,
                'meetup_location' => $location,
            );
        }

        return $items;
    }

    /** Human-readable fulfilment block appended to the order notes. */
    private static function fulfilment_note( $items, $shipping ) {
        $fulfil = self::get_order_fulfilment( (object) array( 'items' => wp_json_encode( $items ) ) );

        if ( $fulfil['mode'] === 'delivery' ) {
            $sh    = is_array( $shipping ) ? $shipping : array();
            $lines = array();
            foreach ( array( 'address1', 'address2', 'city', 'region' ) as $k ) {
                $v = sanitize_text_field( (string) ( isset( $sh[ $k ] ) ? $sh[ $k ] : '' ) );
                if ( $v !== '' ) $lines[] = $v;
            }
            $note  = sanitize_text_field( (string) ( isset( $sh['note'] ) ? $sh['note'] : '' ) );
            $block = "DELIVERY REQUESTED (TT$ " . number_format( $fulfil['fee'], 2 ) . ")";
            if ( ! empty( $lines ) ) $block .= "\n" . implode( ', ', $lines );
            if ( $note !== '' )      $block .= "\nNotes: " . $note;
            return $block;
        }

        return 'MEET-UP at ' . $fulfil['location'];
    }

    /**
     * @return int|WP_Error New order id, or the reason nothing was saved.
     */
    private static function insert_order( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_ORDERS;

        $items = self::build_order_items( $data );
        if ( is_wp_error( $items ) ) return $items;

        $subtotal = self::items_total( $items );

        // Delivery / meet-up detail, appended to the order notes so it
        // reaches the studio, the emails and the app with no schema change.
        $notes = (string) ( isset( $data['notes'] ) ? $data['notes'] : '' );
        $block = self::fulfilment_note( $items, isset( $data['shipping'] ) ? $data['shipping'] : null );
        $notes = $notes !== '' ? $notes . "\n\n" . $block : $block;

        $now = current_time( 'mysql' );
        $ok  = $wpdb->insert( $table, array(
            'order_ref'      => ! empty( $data['order_ref'] ) ? $data['order_ref'] : self::generate_order_ref(),
            'session_id'     => isset( $data['session_id'] ) ? $data['session_id'] : null,
            'session_code'   => isset( $data['session_code'] ) ? $data['session_code'] : null,
            'customer_name'  => $data['customer_name'],
            'customer_email' => $data['customer_email'],
            'customer_phone' => isset( $data['customer_phone'] ) ? $data['customer_phone'] : '',
            'items'          => wp_json_encode( $items ),
            'subtotal'       => round( $subtotal, 2 ),
            'status'         => 'new',
            'source'         => $data['source'],
            'notes'          => $notes,
            'created_at'     => $now,
            'updated_at'     => $now,
        ));

        if ( ! $ok ) {
            return new WP_Error( 'save_failed', 'Could not save your order. Please try again.', array( 'status' => 500 ) );
        }
        return (int) $wpdb->insert_id;
    }

    // ── Studio-initiated orders ("Send to print lab") ──

    /**
     * Turn a session's gallery into a real print order without the studio
     * having to pose as the customer.
     *
     * The order is built with exactly the same line-item shape as a customer
     * order — catalog pricing, the same fulfilment line, the same crop line —
     * so the summary, the emails, the portal, the ZIP builder and the
     * provider economics all work on it unchanged. It is attached to the
     * session (session_id + session_code) and to the customer's email, so
     * portal_url() opens it in that customer's order portal and it shows up
     * in the mobile app like any other order.
     *
     * @param array $args session_id, product_id, qty, photo_ids, fulfilment,
     *                    meetup_location, shipping, notes, provider_id,
     *                    notify_customer.
     * @return array|WP_Error {order_id, order_ref, portal_url, pieces, subtotal,
     *                         provider_state, provider_note, provider_id, provider_name, files_built}
     */
    public static function create_studio_order( $args ) {
        $args = array_merge( array(
            'session_id'      => 0,
            'product_id'      => '',
            'qty'             => 1,
            'photo_ids'       => array(),
            'fulfilment'      => 'meetup',
            'meetup_location' => '',
            'shipping'        => null,
            'notes'           => '',
            'provider_id'     => '',
            'notify_customer' => false,
        ), (array) $args );

        if ( ! class_exists( 'TwellerFlow2_Session' ) || ! class_exists( 'TwellerFlow2_Gallery' ) ) {
            return new WP_Error( 'unavailable', 'Sessions and galleries are unavailable on this site.' );
        }

        $session = TwellerFlow2_Session::get( (int) $args['session_id'] );
        if ( ! $session ) {
            return new WP_Error( 'bad_session', 'That session could not be found.' );
        }
        if ( ! is_email( $session->client_email ) ) {
            return new WP_Error( 'no_email', 'This session has no valid client email, so the order could not be linked to their portal. Add one on the session first.' );
        }

        $product = self::get_product( sanitize_text_field( (string) $args['product_id'] ) );
        if ( ! $product || empty( $product['active'] ) ) {
            return new WP_Error( 'bad_product', 'Choose a product that is active in the print catalog.' );
        }

        $qty = max( 1, min( 50, (int) $args['qty'] ) );

        $photos = TwellerFlow2_Gallery::get_photos( (int) $session->id );
        if ( empty( $photos ) ) {
            return new WP_Error( 'no_photos', 'That gallery has no photos yet.' );
        }

        $wanted = array();
        foreach ( (array) $args['photo_ids'] as $pid ) {
            $pid = (int) $pid;
            if ( $pid > 0 ) $wanted[ $pid ] = true;
        }

        $gallery_url = TwellerFlow2_Gallery::get_gallery_url( $session->tracking_code );
        $items       = array();
        foreach ( $photos as $photo ) {
            if ( ! empty( $wanted ) && empty( $wanted[ (int) $photo->id ] ) ) continue;
            $items[] = array(
                'product_id'   => (string) $product['id'],
                'product_name' => (string) $product['name'],
                'category'     => (string) $product['category'],
                'price'        => round( (float) $product['price'], 2 ),
                'qty'          => $qty,
                'filename'     => (string) $photo->filename,
                'photo_id'     => (int) $photo->id,
                'photo_url'    => $gallery_url . '/' . rawurlencode( $photo->filename ),
                'thumb_url'    => $gallery_url . '/thumbs/' . rawurlencode( $photo->filename ),
                'crop'         => null,
            );
        }

        if ( empty( $items ) ) {
            return new WP_Error( 'no_photos', 'None of the selected photos are in that gallery.' );
        }

        $user  = wp_get_current_user();
        $notes = trim( sanitize_textarea_field( (string) $args['notes'] ) );
        $stamp = 'Sent to the print lab by ' . ( ( $user && $user->exists() ) ? $user->display_name : 'the studio' )
            . ' from the ' . $session->tracking_code . ' gallery.';
        $notes = $notes !== '' ? $notes . "\n\n" . $stamp : $stamp;

        $order_id = self::insert_order( array(
            'session_id'      => (int) $session->id,
            'session_code'    => (string) $session->tracking_code,
            'customer_name'   => (string) $session->client_name,
            'customer_email'  => (string) $session->client_email,
            'customer_phone'  => (string) $session->client_phone,
            'items'           => $items,
            'notes'           => $notes,
            'source'          => 'studio',
            'crop_service'    => false,
            'fulfilment'      => (string) $args['fulfilment'],
            'meetup_location' => (string) $args['meetup_location'],
            'shipping'        => $args['shipping'],
        ));

        if ( is_wp_error( $order_id ) ) return $order_id;
        if ( ! $order_id ) {
            return new WP_Error( 'save_failed', 'The print order could not be saved.' );
        }

        $order   = self::get_order( $order_id );
        $summary = self::order_summary( $order );

        if ( ! empty( $args['notify_customer'] ) ) {
            self::send_customer_confirmation( $order );
        }

        $handoff = self::hand_off_to_provider( $order, sanitize_key( (string) $args['provider_id'] ) );

        return array(
            'order_id'       => (int) $order_id,
            'order_ref'      => (string) $order->order_ref,
            'portal_url'     => self::portal_url( $order ),
            'pieces'         => (int) $summary['pieces'],
            'subtotal'       => round( (float) $order->subtotal, 2 ),
            // What ACTUALLY happened with the lab — never a bare "created".
            'provider_state' => (string) $handoff['state'],
            'provider_note'  => (string) $handoff['message'],
            'provider_id'    => (string) $handoff['provider_id'],
            'provider_name'  => (string) $handoff['provider_name'],
            'files_built'    => ! empty( $handoff['built'] ) ? 1 : 0,
        );
    }

    /**
     * Hand the job to a provider.
     *
     * Assignment is a decision and is recorded IMMEDIATELY; building the files
     * is a slow process that runs behind it. They used to be coupled — this
     * method looped the chunked builder against a 20-second deadline and only
     * assigned the provider if the build reported done, so a 116-photo order
     * (15 chunks) could never reach the assignment and silently ended up with
     * "No provider assigned yet" while the app reported success.
     *
     * @return array {state, message, provider_id, provider_name, built}
     */
    private static function hand_off_to_provider( $order, $provider_id ) {
        if ( $provider_id === '' ) {
            return array(
                'state'         => 'unassigned',
                'message'       => 'Order created — no print lab was chosen, so it is not assigned to anyone yet.',
                'provider_id'   => '',
                'provider_name' => '',
                'built'         => false,
            );
        }
        if ( ! class_exists( 'TwellerFlow2_Print_Fulfillment' ) ) {
            return array(
                'state'         => 'error',
                'message'       => 'The fulfilment module is unavailable, so nothing was sent to a provider.',
                'provider_id'   => '',
                'provider_name' => '',
                'built'         => false,
            );
        }

        $result = TwellerFlow2_Print_Fulfillment::assign_and_send( (int) $order->id, $provider_id, '', 15 );

        if ( is_wp_error( $result ) ) {
            return array(
                'state'         => 'error',
                'message'       => 'The order was created, but it could not be assigned to that lab: ' . $result->get_error_message(),
                'provider_id'   => '',
                'provider_name' => '',
                'built'         => false,
            );
        }
        return $result;
    }

    /** Providers available for the "Send to print lab" pickers. */
    public static function provider_choices() {
        if ( ! class_exists( 'TwellerFlow2_Print_Fulfillment' ) ) return array();
        $out = array();
        foreach ( (array) TwellerFlow2_Print_Fulfillment::get_providers( true ) as $p ) {
            if ( empty( $p['id'] ) ) continue;
            $out[ (string) $p['id'] ] = (string) $p['name'];
        }
        return $out;
    }

    /**
     * The "Send to print lab" panel, shared by the session detail screen and
     * the galleries screen so both entry points behave identically.
     *
     * @param object $session     Session row.
     * @param int    $photo_count Photos in that gallery.
     * @param string $redirect    'session' or 'galleries'.
     */
    public static function render_send_to_lab_panel( $session, $photo_count, $redirect = 'session' ) {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! $session || (int) $photo_count < 1 ) return;

        $products  = self::get_active_products();
        $providers = self::provider_choices();
        $settings  = self::get_settings();
        $uid       = 'tf2lab-' . (int) $session->id . '-' . sanitize_key( $redirect );
        ?>
        <div class="tf2-printlab" id="tf2-print-lab-<?php echo (int) $session->id; ?>">
            <form method="post" class="tf2-printlab__form">
                <?php wp_nonce_field( 'tweller_flow_2_studio_print_order' ); ?>
                <input type="hidden" name="tweller_flow_2_studio_print_order" value="1">
                <input type="hidden" name="session_id" value="<?php echo (int) $session->id; ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>">

                <div class="tf2-printlab__head">
                    <strong>Send to print lab</strong>
                    <span>Creates a real print order for <?php echo esc_html( $session->client_name ); ?> from all <?php echo (int) $photo_count; ?> photos in this gallery.</span>
                </div>

                <div class="tf2-printlab__row">
                    <label>
                        <span>Product / size</span>
                        <select name="product_id" required>
                            <?php foreach ( $products as $p ) : ?>
                                <option value="<?php echo esc_attr( $p['id'] ); ?>">
                                    <?php echo esc_html( $p['name'] . ' — TT$ ' . number_format( (float) $p['price'], 2 ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Copies of each photo</span>
                        <input type="number" name="qty" value="1" min="1" max="50" step="1">
                    </label>
                    <label>
                        <span>Print lab (optional)</span>
                        <select name="provider_id">
                            <option value="">— create the order only —</option>
                            <?php foreach ( $providers as $pid => $pname ) : ?>
                                <option value="<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( $pname ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="tf2-printlab__row">
                    <label>
                        <span>Hand-over</span>
                        <select name="fulfilment" id="<?php echo esc_attr( $uid ); ?>-mode">
                            <option value="meetup">Meet-up (free)</option>
                            <option value="delivery">Delivery — TT$ <?php echo esc_html( number_format( self::get_delivery_fee(), 2 ) ); ?></option>
                        </select>
                    </label>
                    <label id="<?php echo esc_attr( $uid ); ?>-point">
                        <span>Meet-up point</span>
                        <select name="meetup_location">
                            <?php foreach ( self::get_meetup_points() as $point ) : ?>
                                <option value="<?php echo esc_attr( $point ); ?>"><?php echo esc_html( $point ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label id="<?php echo esc_attr( $uid ); ?>-addr" style="display:none;">
                        <span>Delivery address</span>
                        <input type="text" name="ship_address1" maxlength="160" placeholder="Street address, town">
                    </label>
                </div>

                <div class="tf2-printlab__row">
                    <label class="tf2-printlab__grow">
                        <span>Note on the order (optional)</span>
                        <input type="text" name="notes" maxlength="500" placeholder="Anything the lab or the client should know">
                    </label>
                    <?php if ( is_email( $session->client_email ) ) : ?>
                        <label class="tf2-printlab__check">
                            <input type="checkbox" name="notify_customer" value="1">
                            Email <?php echo esc_html( $session->client_email ); ?> their order confirmation
                        </label>
                    <?php else : ?>
                        <span class="tf2-printlab__check tf2-printlab__warn">Add a client email to this session first — the order has to belong to someone.</span>
                    <?php endif; ?>
                </div>

                <p class="tf2-printlab__foot">
                    <button type="submit" class="tf2-btn tf2-btn--primary tf2-btn--sm">Send to print lab</button>
                    <span>Priced from the catalog, plus <?php echo esc_html( trim( (string) $settings['delivery_label'] ) ); ?> when chosen — the client sees it in their order portal.</span>
                </p>
            </form>
        </div>
        <?php
        // The galleries screen renders one panel per gallery — the shared
        // stylesheet only needs to go out once.
        static $css_done = false;
        if ( $css_done ) {
            self::send_to_lab_script( $uid );
            return;
        }
        $css_done = true;
        ?>
        <style>
        .tf2-printlab { border:1px solid #E5E7EB; border-radius:10px; padding:14px 16px; background:#FAFAFA; margin-top:14px; }
        .tf2-printlab__head { display:flex; flex-direction:column; gap:2px; margin-bottom:10px; }
        .tf2-printlab__head strong { font-size:14px; color:#111827; }
        .tf2-printlab__head span { font-size:12px; color:#6B7280; }
        .tf2-printlab__row { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:10px; align-items:flex-end; }
        .tf2-printlab__row label { display:flex; flex-direction:column; gap:4px; font-size:12px; color:#6B7280; min-width:0; }
        .tf2-printlab__row label > span { font-size:11px; text-transform:uppercase; letter-spacing:.04em; }
        .tf2-printlab__row select, .tf2-printlab__row input[type=text], .tf2-printlab__row input[type=number] {
            padding:6px 9px; border:1px solid #D1D5DB; border-radius:6px; font-size:13px; font-family:inherit; max-width:100%;
        }
        .tf2-printlab__grow { flex:1 1 260px; }
        .tf2-printlab__grow input { width:100%; }
        .tf2-printlab__check { display:flex; flex-direction:row !important; align-items:center; gap:6px !important; font-size:12px; color:#374151; }
        .tf2-printlab__warn { color:#B45309; }
        .tf2-printlab__foot { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0; }
        .tf2-printlab__foot span { font-size:11.5px; color:#9CA3AF; }
        </style>
        <?php
        self::send_to_lab_script( $uid );
    }

    /** Meet-up ↔ delivery field toggle for one "Send to print lab" panel. */
    private static function send_to_lab_script( $uid ) {
        ?>
        <script>
        (function() {
            var mode  = document.getElementById('<?php echo esc_js( $uid ); ?>-mode');
            var point = document.getElementById('<?php echo esc_js( $uid ); ?>-point');
            var addr  = document.getElementById('<?php echo esc_js( $uid ); ?>-addr');
            if (!mode || !point || !addr) return;
            mode.addEventListener('change', function() {
                var delivery = mode.value === 'delivery';
                point.style.display = delivery ? 'none' : '';
                addr.style.display  = delivery ? '' : 'none';
            });
        })();
        </script>
        <?php
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

    /**
     * THE order table for every email and the customer portal.
     *
     * One row per product/size — never one row per photo, so a 116-photo
     * order is a handful of readable lines instead of a 116-row wall of
     * thumbnails. Delivery / meet-up and the crop service are rows of their
     * own, and the total is summarize_items()'s total, which is computed
     * from the same integer-cent arithmetic that produced the stored
     * subtotal. If the two ever disagree the stored subtotal wins and the
     * mismatch is shown rather than hidden.
     *
     * @param object $order
     * @param array  $args  show_prices: false for the white-label lab email.
     */
    public static function order_summary_html( $order, $args = array() ) {
        $args = array_merge( array( 'show_prices' => true ), (array) $args );
        $show = ! empty( $args['show_prices'] );

        $summary = self::order_summary( $order );
        $stored  = isset( $order->subtotal ) ? round( (float) $order->subtotal, 2 ) : $summary['total'];

        $rows   = '';
        $pieces = 0;
        foreach ( $summary['groups'] as $g ) {
            // The lab never sees the fee lines — they print nothing.
            if ( ! $show && ! empty( $g['service'] ) ) continue;
            if ( empty( $g['service'] ) ) $pieces += (int) $g['qty'];

            $label = self::summary_label( $g );
            $meta  = '&times; ' . (int) $g['qty'];
            if ( $show ) {
                $meta .= ' @ TT$ ' . number_format( $g['unit'], 2 );
            }
            if ( empty( $g['service'] ) && (int) $g['photos'] > 1 ) {
                $meta .= ' &middot; ' . (int) $g['photos'] . ' photos';
            }

            $rows .= "
                <tr>
                    <td style='padding:9px 10px 9px 0; border-bottom:1px solid #ECE9E2; vertical-align:top;'>
                        <span style='color:#101010; font-weight:600; font-size:14px;'>" . esc_html( $label ) . "</span><br>
                        <span style='color:#8A8178; font-size:12px;'>{$meta}</span>
                    </td>";
            if ( $show ) {
                $rows .= "
                    <td style='padding:9px 0; border-bottom:1px solid #ECE9E2; text-align:right; white-space:nowrap; vertical-align:top; color:#3D3630; font-weight:600; font-size:14px;'>TT$ " . number_format( $g['line'], 2 ) . "</td>";
            }
            $rows .= "
                </tr>";
        }

        if ( $show ) {
            $foot = "
                <tr>
                    <td style='padding:13px 10px 0 0; color:#101010; font-weight:700; font-size:14px;'>Total</td>
                    <td style='padding:13px 0 0; text-align:right; color:#101010; font-weight:700; font-size:16px;'>TT$ " . number_format( $stored, 2 ) . "</td>
                </tr>";
            if ( abs( $summary['total'] - $stored ) >= 0.01 ) {
                $foot .= "
                <tr>
                    <td colspan='2' style='padding:8px 0 0; color:#B91C1C; font-size:12px;'>The lines above total TT$ " . number_format( $summary['total'], 2 ) . " — please contact us before paying.</td>
                </tr>";
            }
        } else {
            $foot = "
                <tr>
                    <td style='padding:13px 10px 0 0; color:#101010; font-weight:700; font-size:14px;'>Total pieces</td>
                </tr>";
        }

        $pieces_row = '';
        if ( ! $show ) {
            $pieces_row = "
                <tr>
                    <td style='padding:2px 0 0; color:#101010; font-weight:700; font-size:16px;'>" . (int) $pieces . "</td>
                </tr>";
        }

        return "
            <table role='presentation' style='width:100%; border-collapse:collapse;' cellpadding='0' cellspacing='0' border='0'>
                {$rows}{$foot}{$pieces_row}
            </table>";
    }

    /** Back-compat alias — every caller now gets the grouped summary. */
    private static function items_table_html( $order ) {
        return self::order_summary_html( $order );
    }

    public static function send_customer_confirmation( $order ) {
        if ( ! $order || empty( $order->customer_email ) || ! class_exists( 'TwellerFlow2_Notifications' ) ) return false;

        $first_name = trim( explode( ' ', trim( $order->customer_name ) )[0] );
        $settings   = self::get_settings();
        $portal     = self::portal_url( $order );
        $wipay_ok   = class_exists( 'TwellerFlow2_WiPay' ) && TwellerFlow2_WiPay::is_enabled();

        $pay_line = $wipay_ok
            ? 'Pay securely by card (Visa &amp; Mastercard via WiPay) or by bank transfer — both options are on your order page, where you can also follow your order every step of the way.'
            : 'Pay by bank transfer and upload your receipt on your order page, where you can also follow your order every step of the way.';

        $payment_block = TwellerFlow2_Notifications::email_card( 'Payment', "
            <p style='margin:0 0 6px; color:#3D3630; line-height:1.7;'>Amount due: <strong style='color:#101010;'>TT$ " . number_format( (float) $order->subtotal, 2 ) . "</strong></p>
            <p style='margin:6px 0 0; color:#3D3630; line-height:1.7;'>{$pay_line}</p>
        " );

        $subject = "We've received your print order, {$first_name} — " . $order->order_ref;
        $body = "
            <h2 style='color:#101010; font-weight:600;'>Your print order is in</h2>
            <p style='color:#3D3630;'>Hi " . esc_html( $order->customer_name ) . ",</p>
            <p style='color:#3D3630; line-height:1.7;'>Thank you for your order — we can't wait to see these in print. Your order reference is <strong style='color:#101010;'>" . esc_html( $order->order_ref ) . "</strong>.</p>

            " . TwellerFlow2_Notifications::email_card( 'Your Order', self::items_table_html( $order ) ) . "

            {$payment_block}

            <div style='text-align:center; margin:28px 0;'>
                " . TwellerFlow2_Notifications::email_button( esc_url( $portal ), 'View your order / Make payment' ) . "
            </div>

            <p style='color:#3D3630; line-height:1.7;'>" . esc_html( $settings['pickup_note'] ) . "</p>
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
        $sources = array( 'gallery' => 'Client gallery', 'public' => 'Public upload', 'studio' => 'Studio (sent to print lab)' );
        $meta .= TwellerFlow2_Notifications::email_detail_row( 'Source', isset( $sources[ $order->source ] ) ? $sources[ $order->source ] : esc_html( (string) $order->source ) );

        $fulfilment = self::get_order_fulfilment( $order );
        if ( $fulfilment['mode'] === 'delivery' ) {
            $meta .= TwellerFlow2_Notifications::email_detail_row( 'Fulfilment', 'Delivery — TT$ ' . number_format( $fulfilment['fee'], 2 ) );
        } elseif ( $fulfilment['mode'] === 'meetup' ) {
            $meta .= TwellerFlow2_Notifications::email_detail_row( 'Fulfilment', 'Meet-up — ' . esc_html( $fulfilment['location'] ) );
        }
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

        // "Ready" means different things depending on how they're getting it.
        $fulfilment = self::get_order_fulfilment( $order );
        if ( $fulfilment['mode'] === 'delivery' ) {
            $ready_line = "It's printed, packed and ready — we'll be in touch to arrange your delivery.";
        } elseif ( $fulfilment['mode'] === 'meetup' ) {
            $ready_line = "It's printed, packed and ready — we'll confirm a time to meet you at <strong>" . esc_html( $fulfilment['location'] ) . "</strong>.";
        } else {
            $ready_line = "It's printed, packed and ready.";
        }

        $copy = array(
            'confirmed' => array(
                'subject' => "Payment confirmed — your print order is in production, {$first_name}",
                'heading' => 'Payment confirmed',
                'line'    => "Lovely news — we've confirmed your payment and your print order <strong>" . esc_html( $order->order_ref ) . "</strong> is now in the production queue.",
            ),
            'printing' => array(
                'subject' => "Your prints are in production, {$first_name}",
                'heading' => 'In production',
                'line'    => "Your order <strong>" . esc_html( $order->order_ref ) . "</strong> is being printed right now. We'll let you know the moment it's ready.",
            ),
            'ready' => array(
                'subject' => "Your prints are ready, {$first_name} ✨",
                'heading' => 'Ready',
                'line'    => "The moment you've been waiting for — your order <strong>" . esc_html( $order->order_ref ) . "</strong> is done. " . $ready_line . "<br><br>" . esc_html( $settings['pickup_note'] ),
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
        $portal = self::portal_url( $order );

        $body = "
            <h2 style='color:#101010; font-weight:600;'>" . $c['heading'] . "</h2>
            <p style='color:#3D3630;'>Hi " . esc_html( $order->customer_name ) . ",</p>
            <p style='color:#3D3630; line-height:1.7;'>" . $c['line'] . "</p>
            " . TwellerFlow2_Notifications::email_card( 'Your Order', self::items_table_html( $order ) ) . "
            <div style='text-align:center; margin:28px 0;'>
                " . TwellerFlow2_Notifications::email_button( esc_url( $portal ), 'View your order' ) . "
            </div>
            <p style='color:#3D3630;'>Warm regards,<br><strong>The Tweller Studios Team</strong></p>
        ";

        return TwellerFlow2_Notifications::send_raw( $order->customer_email, $c['subject'], $body );
    }

    /** Studio alert: a bank-transfer receipt arrived and needs verification. */
    public static function send_receipt_review_alert( $order ) {
        if ( ! $order || ! class_exists( 'TwellerFlow2_Notifications' ) ) return false;

        $settings  = self::get_settings();
        $to        = is_email( $settings['notify_email'] ) ? $settings['notify_email'] : get_option( 'admin_email' );
        $admin_url = admin_url( 'admin.php?page=tweller-flow-2-prints&status=new' );

        $confirmed = ( isset( $order->receipt_confirmed_amount ) && $order->receipt_confirmed_amount !== null ) ? (float) $order->receipt_confirmed_amount : 0;
        $mismatch  = $confirmed > 0 && abs( $confirmed - (float) $order->subtotal ) > 0.5;

        $meta  = TwellerFlow2_Notifications::email_detail_row( 'Order', esc_html( $order->order_ref ) );
        $meta .= TwellerFlow2_Notifications::email_detail_row( 'Customer', esc_html( $order->customer_name ) );
        $meta .= TwellerFlow2_Notifications::email_detail_row( 'Order total', 'TT$ ' . number_format( (float) $order->subtotal, 2 ) );
        if ( $confirmed > 0 ) {
            $meta .= TwellerFlow2_Notifications::email_detail_row( 'Customer confirmed', 'TT$ ' . number_format( $confirmed, 2 ) . ( $mismatch ? ' — does not match the order total' : '' ) );
        }
        if ( ! empty( $order->receipt_ocr ) ) {
            $meta .= TwellerFlow2_Notifications::email_detail_row( 'OCR detected', esc_html( $order->receipt_ocr ) );
        }
        if ( ! empty( $order->receipt_reference ) ) {
            $meta .= TwellerFlow2_Notifications::email_detail_row( 'Reference', esc_html( $order->receipt_reference ) );
        }

        $receipt_link = ! empty( $order->receipt_url )
            ? "<p style='margin:14px 0 0;'><a href='" . esc_url( $order->receipt_url ) . "' style='color:#101010; font-weight:600;'>Open the uploaded receipt &rarr;</a></p>"
            : '';

        $subject = 'Receipt uploaded — verify payment · ' . $order->order_ref . ' · ' . $order->customer_name;
        $body = "
            <h2 style='color:#101010; font-weight:600;'>Receipt uploaded — verify payment</h2>
            <p style='color:#3D3630; line-height:1.7;'><strong>" . esc_html( $order->customer_name ) . "</strong> uploaded a bank-transfer receipt for print order <strong>" . esc_html( $order->order_ref ) . "</strong>. Once the transfer checks out, press &ldquo;Confirm payment&rdquo; on the order.</p>
            " . TwellerFlow2_Notifications::email_card( 'Payment Details', $meta . $receipt_link ) . "
            <div style='text-align:center; margin:28px 0;'>
                " . TwellerFlow2_Notifications::email_button( esc_url( $admin_url ), 'Review in Print Store Orders' ) . "
            </div>
        ";

        return TwellerFlow2_Notifications::send_raw( $to, $subject, $body );
    }

    /** Studio alert: a WiPay card payment was verified for a print order. */
    public static function send_studio_payment_alert( $order, $payment ) {
        if ( ! $order || ! class_exists( 'TwellerFlow2_Notifications' ) ) return false;

        $settings  = self::get_settings();
        $to        = is_email( $settings['notify_email'] ) ? $settings['notify_email'] : get_option( 'admin_email' );
        $admin_url = admin_url( 'admin.php?page=tweller-flow-2-prints' );

        $meta  = TwellerFlow2_Notifications::email_detail_row( 'Order', esc_html( $order->order_ref ) );
        $meta .= TwellerFlow2_Notifications::email_detail_row( 'Customer', esc_html( $order->customer_name ) );
        $meta .= TwellerFlow2_Notifications::email_detail_row( 'Paid', 'TT$ ' . number_format( (float) ( $payment['total'] ?? $order->subtotal ), 2 ) );
        if ( ! empty( $payment['transaction_id'] ) ) {
            $meta .= TwellerFlow2_Notifications::email_detail_row( 'Transaction', esc_html( $payment['transaction_id'] ) );
        }

        $subject = 'Card payment received · ' . $order->order_ref . ' · ' . $order->customer_name;
        $body = "
            <h2 style='color:#101010; font-weight:600;'>Card payment received</h2>
            <p style='color:#3D3630; line-height:1.7;'>WiPay confirmed the card payment for print order <strong>" . esc_html( $order->order_ref ) . "</strong>. The order has moved to <strong>Payment confirmed</strong> and is ready for production.</p>
            " . TwellerFlow2_Notifications::email_card( 'Payment Details', $meta ) . "
            <div style='text-align:center; margin:28px 0;'>
                " . TwellerFlow2_Notifications::email_button( esc_url( $admin_url ), 'Open Print Store Orders' ) . "
            </div>
        ";

        return TwellerFlow2_Notifications::send_raw( $to, $subject, $body );
    }

    // ── Admin ──────────────────────────────────────────

    /** Orders needing attention: everything still in 'new' (awaiting payment / receipt review). */
    public static function count_pending_attention() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_ORDERS;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'new'" );
    }

    public static function register_admin_menu() {
        $label   = 'Print Store';
        $pending = self::count_pending_attention();
        if ( $pending > 0 ) {
            $label .= ' <span class="awaiting-mod count-' . (int) $pending . '"><span class="pending-count">' . (int) $pending . '</span></span>';
        }
        add_submenu_page(
            'tweller-flow-2',
            'Print Store',
            $label,
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

        $review_count = 0;
        $review_row = $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'new' AND payment_review = 1" );
        if ( $review_row !== null ) $review_count = (int) $review_row;

        $statuses = self::get_statuses();
        $labels   = self::get_status_labels();
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
                'hero_headline'        => sanitize_text_field( $_POST['prints_hero_headline'] ?? '' ),
                'hero_subheadline'     => sanitize_text_field( $_POST['prints_hero_subheadline'] ?? '' ),
                'hero_intro'           => sanitize_textarea_field( $_POST['prints_hero_intro'] ?? '' ),
                'hero_bg_url'          => esc_url_raw( $_POST['prints_hero_bg_url'] ?? '' ),
                'crop_service_enabled' => empty( $_POST['prints_crop_service_enabled'] ) ? 0 : 1,
                'crop_service_fee'     => max( 0, (float) ( $_POST['prints_crop_service_fee'] ?? 0 ) ),
                'crop_service_label'   => sanitize_text_field( $_POST['prints_crop_service_label'] ?? '' ),
                'crop_service_note'    => sanitize_textarea_field( $_POST['prints_crop_service_note'] ?? '' ),
                'delivery_fee'         => max( 0, round( (float) ( $_POST['prints_delivery_fee'] ?? 0 ), 2 ) ),
                'delivery_label'       => sanitize_text_field( $_POST['prints_delivery_label'] ?? '' ),
                'meetup_label'         => sanitize_text_field( $_POST['prints_meetup_label'] ?? '' ),
                'meetup_points'        => self::sanitize_meetup_points( wp_unslash( $_POST['prints_meetup_points'] ?? '' ) ),
                'partner_alert_emails' => sanitize_text_field( $_POST['prints_partner_alert_emails'] ?? '' ),
            ));

            wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-prints&view=settings&saved=1' ) );
            exit;
        }

        // "Send to print lab" — studio-initiated order from a session gallery.
        // Available from the session detail screen and the galleries screen.
        if ( isset( $_POST['tweller_flow_2_studio_print_order'] ) ) {
            check_admin_referer( 'tweller_flow_2_studio_print_order' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( 'You are not allowed to create print orders.' );
            }

            $session_id = intval( $_POST['session_id'] ?? 0 );
            $redirect   = ( isset( $_POST['redirect_to'] ) && $_POST['redirect_to'] === 'galleries' ) ? 'galleries' : 'session';

            $result = self::create_studio_order( array(
                'session_id'      => $session_id,
                'product_id'      => sanitize_text_field( wp_unslash( $_POST['product_id'] ?? '' ) ),
                'qty'             => intval( $_POST['qty'] ?? 1 ),
                'fulfilment'      => sanitize_text_field( wp_unslash( $_POST['fulfilment'] ?? 'meetup' ) ),
                'meetup_location' => sanitize_text_field( wp_unslash( $_POST['meetup_location'] ?? '' ) ),
                'shipping'        => array( 'address1' => sanitize_text_field( wp_unslash( $_POST['ship_address1'] ?? '' ) ) ),
                'notes'           => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
                'provider_id'     => sanitize_key( wp_unslash( $_POST['provider_id'] ?? '' ) ),
                'notify_customer' => ! empty( $_POST['notify_customer'] ),
            ));

            $base = $redirect === 'galleries'
                ? admin_url( 'admin.php?page=tweller-flow-2-galleries' )
                : admin_url( 'admin.php?page=tweller-flow-2-session&id=' . $session_id );

            if ( is_wp_error( $result ) ) {
                wp_redirect( add_query_arg( 'printlab_error', rawurlencode( $result->get_error_message() ), $base ) );
                exit;
            }

            wp_redirect( add_query_arg( array(
                'printlab'       => rawurlencode( $result['order_ref'] ),
                'printlab_note'  => rawurlencode( $result['provider_note'] ),
                'printlab_state' => rawurlencode( $result['provider_state'] ),
            ), $base ) );
            exit;
        }

        // Confirm payment (verified bank transfer) — sets 'Payment confirmed'
        if ( isset( $_POST['tweller_flow_2_print_confirm_payment'] ) ) {
            check_admin_referer( 'tweller_flow_2_print_confirm_payment' );
            $order_id = intval( $_POST['order_id'] ?? 0 );
            $notify   = ! empty( $_POST['notify_client'] );
            if ( $order_id ) {
                self::confirm_payment( $order_id, $notify );
            }
            wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-prints&updated=1' ) );
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
