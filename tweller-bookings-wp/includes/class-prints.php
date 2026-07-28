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
            'ready'     => 'Ready for pickup',
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

    public static function get_settings() {
        $defaults = array(
            'notify_email'         => get_option( 'admin_email' ),
            'pickup_note'          => "Prints are usually ready for pickup at Tweller Studios within 7–10 business days. Delivery across Trinidad & Tobago can be arranged — we'll confirm the details with you after your order.",
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
        );
        $saved = get_option( self::OPT_SETTINGS, array() );
        if ( ! is_array( $saved ) ) $saved = array();
        $merged = array_merge( $defaults, $saved );
        // Never let a blank save wipe the storefront copy
        foreach ( array( 'hero_headline', 'hero_subheadline', 'pickup_note' ) as $key ) {
            if ( trim( (string) $merged[ $key ] ) === '' ) {
                $merged[ $key ] = $defaults[ $key ];
            }
        }
        return $merged;
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
                    <li class="tf2-shop__step"><span class="tf2-shop__step-num">3</span><strong>We print</strong><span>Pay by card or bank transfer, then pickup or delivery across T&amp;T.</span></li>
                </ol>
            </section>

            <section class="tf2-shop__start" id="tf2p-start">
                <h3 class="tf2-shop__cat">Start your order</h3>
                <p class="tf2-shop__cat-blurb">Add your photos below, then tap each one to choose products and sizes.</p>

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
        $items         = self::get_order_items( $order );
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
            wp_enqueue_script( 'tesseract-js', 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js', array(), null, true );
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
            'ready'     => 'Ready for pickup',
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
                <p class="tf2-portal__label">Items</p>
                <?php foreach ( $items as $it ) :
                    $line = (float) ( $it['price'] ?? 0 ) * max( 1, (int) ( $it['qty'] ?? 1 ) ); ?>
                    <div class="tf2-portal__item">
                        <?php if ( ! empty( $it['thumb_url'] ) ) : ?>
                            <img class="tf2-portal__item-thumb" src="<?php echo esc_url( $it['thumb_url'] ); ?>" alt="">
                        <?php else : ?>
                            <span class="tf2-portal__item-thumb tf2-portal__item-thumb--blank"></span>
                        <?php endif; ?>
                        <span class="tf2-portal__item-info">
                            <span class="tf2-portal__item-name"><?php echo esc_html( $it['product_name'] ?? '' ); ?></span>
                            <span class="tf2-portal__item-file"><?php echo esc_html( $it['filename'] ?? '' ); ?> × <?php echo (int) ( $it['qty'] ?? 1 ); ?></span>
                        </span>
                        <span class="tf2-portal__item-price">TT$ <?php echo esc_html( number_format( $line, 2 ) ); ?></span>
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
                <p class="tf2-portal__label">Pickup &amp; delivery</p>
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
            'crop_service'   => ! empty( $request->get_param( 'crop_service' ) ),
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
            'portal_url'           => self::portal_url( $order ),
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
            'crop_service'   => ! empty( $_POST['crop_service'] ),
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
            'portal_url'           => self::portal_url( $order ),
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

    private static function format_order( $row ) {
        $payment = null;
        if ( isset( $row->payment ) && $row->payment ) {
            $decoded = json_decode( (string) $row->payment, true );
            if ( is_array( $decoded ) ) $payment = $decoded;
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

        $items = $data['items'];

        // "We'll crop it for you" — recorded as its own line so it shows up
        // in totals, emails, the admin order and the app. Free until a fee
        // is set under Print Store → Settings → Cropping Service.
        if ( ! empty( $data['crop_service'] ) ) {
            $settings = self::get_settings();
            if ( ! empty( $settings['crop_service_enabled'] ) ) {
                $items[] = array(
                    'product_id'   => 'crop_service',
                    'product_name' => (string) $settings['crop_service_label'],
                    'category'     => 'service',
                    'price'        => (float) $settings['crop_service_fee'],
                    'qty'          => 1,
                    'filename'     => '',
                    'photo_url'    => '',
                    'thumb_url'    => '',
                    'crop'         => null,
                );
            }
        }

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
                    <td colspan='2' style='padding:12px 10px 0 0; border-top:1px solid #ECE9E2; color:#101010; font-weight:700; font-size:14px;'>Subtotal</td>
                    <td style='padding:12px 0 0; border-top:1px solid #ECE9E2; text-align:right; color:#101010; font-weight:700; font-size:15px;'>TT$ {$subtotal}</td>
                </tr>
            </table>";
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
            ));

            wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-prints&view=settings&saved=1' ) );
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
