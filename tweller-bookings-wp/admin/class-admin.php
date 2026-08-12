<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menus' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
        add_action( 'wp_ajax_tweller_flow_2_admin_upload', array( $this, 'ajax_admin_upload' ) );
    }

    /**
     * Register admin menus
     */
    public function add_menus() {
        add_menu_page(
            'Tweller Bookings',
            'Tweller Bookings',
            'manage_options',
            'tweller-flow-2',
            array( $this, 'page_dashboard' ),
            'dashicons-camera',
            30
        );

        add_submenu_page(
            'tweller-flow-2',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'tweller-flow-2',
            array( $this, 'page_dashboard' )
        );

        add_submenu_page(
            'tweller-flow-2',
            'Sessions',
            'Sessions',
            'manage_options',
            'tweller-flow-2-sessions',
            array( $this, 'page_sessions' )
        );

        add_submenu_page(
            'tweller-flow-2',
            'New Session',
            'New Session',
            'manage_options',
            'tweller-flow-2-new',
            array( $this, 'page_new_session' )
        );

        add_submenu_page(
            'tweller-flow-2',
            'Notifications',
            'Notifications',
            'manage_options',
            'tweller-flow-2-notifications',
            array( $this, 'page_notifications' )
        );

        add_submenu_page(
            'tweller-flow-2',
            'Galleries',
            'Galleries',
            'manage_options',
            'tweller-flow-2-galleries',
            array( $this, 'page_galleries' )
        );

        add_submenu_page(
            'tweller-flow-2',
            'Offerings',
            'Offerings',
            'manage_options',
            'tweller-flow-2-offerings',
            array( $this, 'page_offerings' )
        );

        add_submenu_page(
            'tweller-flow-2',
            'Settings',
            'Settings',
            'manage_options',
            'tweller-flow-2-settings',
            array( $this, 'page_settings' )
        );

        // Hidden page for session detail
        add_submenu_page(
            null,
            'Session Detail',
            'Session Detail',
            'manage_options',
            'tweller-flow-2-session',
            array( $this, 'page_session_detail' )
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
        if ( strpos( $hook, 'tweller-flow-2' ) === false && strpos( $page, 'tweller-flow-2' ) === false ) return;

        wp_enqueue_style(
            'tweller-flow-2-admin',
            TWELLER_FLOW_2_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            TWELLER_FLOW_2_VERSION
        );
        wp_enqueue_script(
            'tweller-flow-2-admin',
            TWELLER_FLOW_2_PLUGIN_URL . 'admin/js/admin.js',
            array( 'jquery' ),
            TWELLER_FLOW_2_VERSION,
            true
        );
        wp_localize_script( 'tweller-flow-2-admin', 'twellerFlow2', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'tweller_flow_2_nonce' ),
            'restUrl' => rest_url( 'tweller-flow-2/v1/' ),
            'restNonce' => wp_create_nonce( 'wp_rest' ),
        ));
    }

    /**
     * Handle form submissions and actions
     */
    public function handle_actions() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Download gallery viewers as CSV (names + emails of everyone who
        // signed in to view a gallery). Reads only the visitor sign-in
        // table — no WordPress accounts are created or touched. session_id
        // scopes it to one gallery; omitted = every gallery.
        if ( isset( $_GET['tf2_action'] ) && $_GET['tf2_action'] === 'export_visitors'
             && class_exists( 'TwellerFlow2_Gallery_Favorites' ) ) {
            check_admin_referer( 'tweller_flow_2_export_visitors' );
            $this->export_visitors_csv( isset( $_GET['session_id'] ) ? absint( $_GET['session_id'] ) : 0 );
            // export_visitors_csv() sends the file and exits.
        }

        // Create session
        if ( isset( $_POST['tweller_flow_2_create_session'] ) ) {
            check_admin_referer( 'tweller_flow_2_create_session' );
            $session_id = TwellerFlow2_Session::create( $_POST );
            if ( $session_id ) {
                // Enable culling if checkbox checked
                if ( ! empty( $_POST['culling_enabled'] ) ) {
                    $cull_pw = sanitize_text_field( $_POST['culling_password'] ?? '' );
                    TwellerFlow2_Culling::enable_culling( $session_id, $cull_pw );
                }
                wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-session&id=' . $session_id . '&created=1' ) );
                exit;
            }
        }

        // Update session
        if ( isset( $_POST['tweller_flow_2_update_session'] ) ) {
            check_admin_referer( 'tweller_flow_2_update_session' );
            $id = intval( $_POST['session_id'] );
            $update_data = array(
                'client_name'    => sanitize_text_field( $_POST['client_name'] ),
                'client_email'   => sanitize_email( $_POST['client_email'] ),
                'client_phone'   => sanitize_text_field( $_POST['client_phone'] ),
                'package_type'   => sanitize_text_field( $_POST['package_type'] ),
                'session_date'   => sanitize_text_field( $_POST['session_date'] ),
                'session_time'   => sanitize_text_field( $_POST['session_time'] ),
                'location'       => sanitize_text_field( $_POST['location'] ),
                'members_count'  => intval( $_POST['members_count'] ),
                'payment_status' => sanitize_text_field( $_POST['payment_status'] ),
                'deposit_amount' => floatval( $_POST['deposit_amount'] ),
                'total_amount'   => floatval( $_POST['total_amount'] ),
                'payment_method' => sanitize_text_field( $_POST['payment_method'] ),
                'gallery_url'    => esc_url_raw( $_POST['gallery_url'] ?? '' ),
                'estimated_delivery' => sanitize_text_field( $_POST['estimated_delivery'] ?? '' ),
                'notes'          => sanitize_textarea_field( $_POST['notes'] ),
            );
            TwellerFlow2_Session::update( $id, $update_data );

            // Payment status / date / time may have changed — re-sync Google Calendar
            do_action( 'tweller_flow_2_payment_updated', $id );

            // Handle culling toggle
            if ( ! empty( $_POST['culling_enabled'] ) ) {
                $cull_pw = sanitize_text_field( $_POST['culling_password'] ?? '' );
                TwellerFlow2_Culling::enable_culling( $id, $cull_pw );
            } else {
                TwellerFlow2_Culling::disable_culling( $id );
            }

            wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-session&id=' . $id . '&updated=1' ) );
            exit;
        }

        // Advance stage
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'advance' && isset( $_GET['session_id'] ) ) {
            check_admin_referer( 'tweller_flow_2_advance_' . $_GET['session_id'] );
            $id = intval( $_GET['session_id'] );
            $notes = sanitize_text_field( $_GET['notes'] ?? '' );
            TwellerFlow2_Session::advance_stage( $id, $notes );
            wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-session&id=' . $id . '&advanced=1' ) );
            exit;
        }

        // Set specific stage
        if ( isset( $_POST['tweller_flow_2_set_stage'] ) ) {
            check_admin_referer( 'tweller_flow_2_set_stage' );
            $id = intval( $_POST['session_id'] );
            $stage = sanitize_text_field( $_POST['stage'] );
            $notes = sanitize_text_field( $_POST['stage_notes'] ?? '' );
            TwellerFlow2_Session::set_stage( $id, $stage, $notes );
            wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-session&id=' . $id . '&stage_set=1' ) );
            exit;
        }

        // Save offerings (session types + packages)
        if ( isset( $_POST['tweller_flow_2_save_offerings'] ) ) {
            check_admin_referer( 'tweller_flow_2_save_offerings' );

            // Rows arrive in DOM order, so the saved order is the on-page order
            $packages = array();
            foreach ( (array) ( $_POST['pk'] ?? array() ) as $row ) {
                $name = sanitize_text_field( $row['name'] ?? '' );
                if ( $name === '' ) continue;

                $key = sanitize_key( $row['key'] ?? '' );
                if ( $key === '' ) {
                    $key = str_replace( '-', '_', sanitize_title( $name ) );
                }
                $base = $key ?: 'package';
                $i = 2;
                while ( isset( $packages[ $key ] ) ) { $key = $base . '_' . $i++; }

                $features = array_filter( array_map( 'sanitize_text_field',
                    preg_split( '/\r\n|\r|\n/', (string) ( $row['features'] ?? '' ) ) ) );

                $packages[ $key ] = array(
                    'name'      => $name,
                    'duration'  => max( 5, intval( $row['duration'] ?? 60 ) ),
                    'images'    => intval( $row['images'] ?? 10 ),
                    'members'   => intval( $row['members'] ?? 1 ),
                    'price'     => floatval( $row['price'] ?? 0 ),
                    'old_price' => floatval( $row['old_price'] ?? 0 ),
                    'features'  => array_values( $features ),
                );
            }

            $session_types = array();
            foreach ( (array) ( $_POST['st'] ?? array() ) as $row ) {
                $name = sanitize_text_field( $row['name'] ?? '' );
                if ( $name === '' ) continue;

                $key = sanitize_key( $row['key'] ?? '' );
                if ( $key === '' ) {
                    $key = str_replace( '-', '_', sanitize_title( $name ) );
                }
                $base = $key ?: 'session';
                $i = 2;
                while ( isset( $session_types[ $key ] ) ) { $key = $base . '_' . $i++; }

                $allowed = array_values( array_intersect(
                    array_map( 'sanitize_key', (array) ( $row['allowed'] ?? array() ) ),
                    array_keys( $packages )
                ) );

                $session_types[ $key ] = array(
                    'name'             => $name,
                    'description'      => sanitize_text_field( $row['description'] ?? '' ),
                    'allowed_packages' => $allowed,
                );
            }

            if ( ! empty( $packages ) ) {
                update_option( 'tweller_flow_2_packages', $packages );
            }
            if ( ! empty( $session_types ) ) {
                update_option( 'tweller_flow_2_session_types', $session_types );
            }

            wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-offerings&saved=1' ) );
            exit;
        }

        // Verify Receipt
        if ( isset( $_POST['tweller_flow_2_verify_receipt'] ) ) {
            check_admin_referer( 'tweller_flow_2_verify_receipt' );
            $id = intval( $_POST['session_id'] );
            $action = sanitize_text_field( $_POST['verify_action'] );
            $session = TwellerFlow2_Session::get( $id );
            
            if ( $session ) {
                // Shared with the mobile app's verify-receipt endpoint, so the
                // two paths cannot diverge. It handles the amount → deposit/paid
                // decision, the Confirmed stage move, the receipt stamp, the
                // client email (confirmation with balance, or re-upload request)
                // and the calendar recolour.
                $amt = isset( $_POST['amount_paid'] ) ? floatval( $_POST['amount_paid'] ) : 0;
                TwellerFlow2_Session::verify_receipt( $id, $action, $amt );
            }
            wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-session&id=' . $id . '&receipt_processed=1' ) );
            exit;
        }

        // Gallery password
        if ( isset( $_POST['tweller_flow_2_gallery_password'] ) ) {
            check_admin_referer( 'tweller_flow_2_gallery_password' );
            $id = intval( $_POST['session_id'] );
            $pw = sanitize_text_field( $_POST['gallery_pw'] ?? '' );
            if ( ! empty( $pw ) ) {
                TwellerFlow2_Gallery::set_password( $id, $pw );
            } else {
                delete_option( 'tweller_gallery_pw_' . $id );
            }
            $redirect = sanitize_text_field( $_POST['redirect_to'] ?? '' );
            if ( $redirect === 'galleries' ) {
                wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-galleries&saved=1' ) );
            } else {
                wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-session&id=' . $id . '&updated=1' ) );
            }
            exit;
        }

        // Send delivery email and advance to delivered
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'send_delivery' && isset( $_GET['session_id'] ) ) {
            check_admin_referer( 'tweller_flow_2_deliver_' . $_GET['session_id'] );
            $id = intval( $_GET['session_id'] );
            $session = TwellerFlow2_Session::get( $id );
            if ( $session && in_array( $session->current_stage, array( 'uploaded', 'delivered' ), true ) ) {
                // Send the delivery email
                $template = TwellerFlow2_Notifications::get_email_template( 'delivered', $session );
                if ( $template && ! empty( $session->client_email ) ) {
                    $sent = TwellerFlow2_Notifications::send_email( $session, $template['subject'], $template['body'] );
                    if ( $sent ) {
                        // Advance to delivered if not already
                        if ( $session->current_stage !== 'delivered' ) {
                            TwellerFlow2_Session::set_stage( $id, 'delivered', 'Gallery delivery email sent to ' . $session->client_email );
                        }
                        wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-session&id=' . $id . '&delivered=1' ) );
                    } else {
                        wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-session&id=' . $id . '&email_failed=1' ) );
                    }
                } else {
                    wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-session&id=' . $id . '&no_email=1' ) );
                }
                exit;
            }
        }

        // Gallery: delete all photos for a session
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete_gallery' && isset( $_GET['session_id'] ) ) {
            check_admin_referer( 'tweller_flow_2_delete_gallery_' . $_GET['session_id'] );
            $session_id = intval( $_GET['session_id'] );
            $session = TwellerFlow2_Session::get( $session_id );
            if ( $session ) {
                $photos = TwellerFlow2_Gallery::get_photos( $session_id );
                $gallery_dir = TwellerFlow2_Gallery::get_gallery_dir( $session->tracking_code );
                foreach ( $photos as $photo ) {
                    @unlink( $gallery_dir . '/' . $photo->filename );
                    @unlink( $gallery_dir . '/thumbs/' . $photo->filename );
                }
                global $wpdb;
                $wpdb->delete( $wpdb->prefix . 'tweller_gallery_photos', array( 'session_id' => $session_id ) );
                delete_option( 'tweller_gallery_pw_' . $session_id );
            }
            wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-galleries&deleted=1' ) );
            exit;
        }

        // Delete session
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['session_id'] ) ) {
            check_admin_referer( 'tweller_flow_2_delete_' . $_GET['session_id'] );
            TwellerFlow2_Session::delete( intval( $_GET['session_id'] ) );
            wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-sessions&deleted=1' ) );
            exit;
        }

        // Save settings
        if ( isset( $_POST['tweller_flow_2_save_settings'] ) ) {
            check_admin_referer( 'tweller_flow_2_save_settings' );
            update_option( 'tweller_flow_2_smtp', array(
                'host'       => sanitize_text_field( $_POST['smtp_host'] ),
                'port'       => intval( $_POST['smtp_port'] ),
                'encryption' => sanitize_text_field( $_POST['smtp_encryption'] ),
                'username'   => sanitize_text_field( $_POST['smtp_username'] ),
                'password'   => $_POST['smtp_password'], // Allow special chars in password
                'from_name'  => sanitize_text_field( $_POST['smtp_from_name'] ),
                'from_email' => sanitize_email( $_POST['smtp_from_email'] ),
            ));
            update_option( 'tweller_flow_2_banking', sanitize_textarea_field( $_POST['banking_info'] ) );
            update_option( 'tweller_flow_2_wipay_url', esc_url_raw( $_POST['wipay_url'] ?? '' ) );
            update_option( 'tweller_flow_2_culling_price_per_photo', max( 1, intval( $_POST['culling_price_per_photo'] ?? 30 ) ) );
            update_option( 'tweller_flow_2_review_url', esc_url_raw( $_POST['review_url'] ?? 'https://g.page/r/CbntSRvzXVrSEBM/review' ) );
            update_option( 'tweller_flow_2_delivery_days', intval( $_POST['delivery_days'] ) );
            update_option( 'tweller_flow_2_tracker_page', esc_url_raw( $_POST['tracker_page_url'] ) );
            update_option( 'tweller_flow_2_webhook_secret', sanitize_text_field( $_POST['webhook_secret'] ) );
            update_option( 'tweller_flow_2_booking_ical_url', esc_url_raw( $_POST['booking_ical_url'] ?? '' ) );

            // Session types & packages are managed on the Offerings page

            // WiPay card payments
            if ( isset( $_POST['wipay_account_number'] ) && class_exists( 'TwellerFlow2_WiPay' ) ) {
                $wipay_account = preg_replace( '/\D/', '', sanitize_text_field( $_POST['wipay_account_number'] ) );
                $wipay_env     = in_array( $_POST['wipay_environment'] ?? '', array( 'sandbox', 'live' ), true )
                    ? $_POST['wipay_environment'] : 'sandbox';
                $wipay_fee     = in_array( $_POST['wipay_fee_structure'] ?? '', array( 'customer_pay', 'merchant_absorb', 'split' ), true )
                    ? $_POST['wipay_fee_structure'] : 'customer_pay';
                $wipay_origin  = preg_replace( '/[^A-Za-z0-9_-]/', '', sanitize_text_field( $_POST['wipay_origin'] ?? '' ) );
                $wipay_origin  = substr( $wipay_origin, 0, 32 );

                // A blank API-key field means "leave it as-is", not "erase
                // it". Blanking the live key silently breaks the response
                // hash check — the card is charged but comes back unverified.
                $wipay_existing = TwellerFlow2_WiPay::get_settings();
                $wipay_posted_key = sanitize_text_field( $_POST['wipay_api_key'] ?? '' );
                $wipay_api_key    = $wipay_posted_key !== '' ? $wipay_posted_key : ( $wipay_existing['api_key'] ?? '' );

                update_option( 'tweller_flow_2_wipay', array(
                    'enabled'        => ! empty( $_POST['wipay_enabled'] ),
                    'account_number' => $wipay_account !== '' ? $wipay_account : '8694059828',
                    'api_key'        => $wipay_api_key,
                    'environment'    => $wipay_env,
                    'fee_structure'  => $wipay_fee,
                    'origin'         => $wipay_origin !== '' ? $wipay_origin : 'TwellerBookings-WP',
                ));
            }

            // Save automation settings
            if ( isset( $_POST['automation_backend_url'] ) ) {
                TwellerFlow2_Photo_Automation::save_settings( $_POST );
            }

            // Culling edited-preview preset
            if ( isset( $_POST['preset_brightness'] ) ) {
                update_option( 'tweller_flow_2_culling_preset', array(
                    'brightness' => floatval( $_POST['preset_brightness'] ),
                    'contrast'   => floatval( $_POST['preset_contrast'] ?? 1.12 ),
                    'saturate'   => floatval( $_POST['preset_saturate'] ?? 1.16 ),
                    'warmth'     => floatval( $_POST['preset_warmth'] ?? 0.12 ),
                    'hue'        => floatval( $_POST['preset_hue'] ?? -3 ),
                ) );
            }

            // Google Sync (Contacts + Calendar)
            if ( isset( $_POST['google_client_id'] ) ) {
                update_option( TwellerFlow2_Google_Contacts::OPT_CONFIG, array(
                    'client_id'        => sanitize_text_field( $_POST['google_client_id'] ),
                    'client_secret'    => sanitize_text_field( $_POST['google_client_secret'] ?? '' ),
                    'enabled'          => ! empty( $_POST['google_sync_enabled'] ),
                    'calendar_enabled' => ! empty( $_POST['google_calendar_enabled'] ),
                ) );
            }

            // "Save & Test Sync Now" — run contact + calendar sync for the
            // most recent session inline and show the results on the page.
            if ( ! empty( $_POST['tweller_flow_2_google_test_sync'] ) ) {
                $recent = TwellerFlow2_Session::get_all( array( 'per_page' => 1, 'orderby' => 'created_at', 'order' => 'DESC' ) );
                if ( ! empty( $recent ) ) {
                    $test_id = intval( $recent[0]->id );
                    TwellerFlow2_Google_Contacts::sync_session_contact( $test_id );
                    if ( class_exists( 'TwellerFlow2_Google_Calendar' ) ) {
                        TwellerFlow2_Google_Calendar::sync_session( $test_id );
                    }
                    wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-settings&saved=1&google_test=' . $test_id ) );
                } else {
                    wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-settings&saved=1&google_test=0' ) );
                }
                exit;
            }

            // "Sync all bookings to calendar" — back-fill every existing
            // booking that predates the calendar connection. Batched inside
            // sync_all(), so a large history takes a few clicks rather than
            // one long request; the result counts are shown on the page.
            if ( ! empty( $_POST['tweller_flow_2_google_sync_all'] ) ) {
                $res = array( 'ok' => false, 'synced' => 0, 'failed' => 0, 'remaining' => 0, 'reason' => 'Calendar sync is unavailable.' );
                if ( class_exists( 'TwellerFlow2_Google_Calendar' ) ) {
                    $res = TwellerFlow2_Google_Calendar::sync_all( 25 );
                }
                wp_redirect( add_query_arg( array_map( 'rawurlencode', array(
                    'page'          => 'tweller-flow-2-settings',
                    'gcal_backfill' => empty( $res['ok'] ) ? 'blocked' : '1',
                    'gb_synced'     => (string) (int) $res['synced'],
                    'gb_failed'     => (string) (int) $res['failed'],
                    'gb_remaining'  => (string) (int) $res['remaining'],
                    'gb_reason'     => (string) ( $res['reason'] ?? '' ),
                ) ), admin_url( 'admin.php' ) ) );
                exit;
            }

            wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-settings&saved=1' ) );
            exit;
        }

        // Send manual notification
        if ( isset( $_POST['tweller_flow_2_send_notification'] ) ) {
            check_admin_referer( 'tweller_flow_2_send_notification' );
            $id = intval( $_POST['session_id'] );
            $session = TwellerFlow2_Session::get( $id );
            if ( $session ) {
                $subject = sanitize_text_field( $_POST['notif_subject'] );
                $body = wp_kses_post( $_POST['notif_body'] );
                TwellerFlow2_Notifications::send_email( $session, $subject, $body );
                wp_redirect( admin_url( 'admin.php?page=tweller-flow-2-session&id=' . $id . '&notified=1' ) );
                exit;
            }
        }
    }

    /**
     * Stream the gallery-viewers CSV and exit. Called from handle_actions()
     * on admin_init, so it runs before any admin page output — headers are
     * safe to send. Caller has already verified manage_options + the nonce.
     */
    private function export_visitors_csv( $session_id = 0 ) {
        $rows = TwellerFlow2_Gallery_Favorites::export_visitor_rows( $session_id );

        $scope = 'all-galleries';
        if ( $session_id > 0 ) {
            $session = TwellerFlow2_Session::get( $session_id );
            if ( $session ) {
                $scope = sanitize_file_name( $session->tracking_code . '-' . $session->client_name );
            }
        }
        $filename = 'gallery-viewers-' . $scope . '-' . date( 'Y-m-d' ) . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        // UTF-8 BOM so Excel opens accented names correctly.
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, array( 'Name', 'Email', 'Gallery Code', 'Booked Client', 'First Viewed', 'Last Seen', 'Photos Liked' ) );
        foreach ( $rows as $r ) {
            fputcsv( $out, array(
                self::csv_cell( $r['name'] ),
                self::csv_cell( $r['email'] ),
                self::csv_cell( $r['session_code'] ),
                self::csv_cell( $r['client_name'] ),
                $r['created_at'],
                $r['last_seen_at'],
                (int) $r['like_count'],
            ) );
        }
        fclose( $out );
        exit;
    }

    /**
     * Neutralise CSV/formula injection: a cell a spreadsheet would run as a
     * formula (leading = + - @, or a leading tab/CR) is prefixed with a
     * single quote so it's shown literally instead of executed.
     */
    private static function csv_cell( $value ) {
        $value = (string) $value;
        if ( $value !== '' && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
            $value = "'" . $value;
        }
        return $value;
    }

    /**
     * Dashboard page
     */
    public function page_dashboard() {
        $active_count  = TwellerFlow2_Session::count_active();
        $total_count   = TwellerFlow2_Session::count();
        $stage_counts  = TwellerFlow2_Session::get_stage_counts();
        $revenue       = TwellerFlow2_Session::get_revenue_stats();
        $recent        = TwellerFlow2_Session::get_all( array( 'per_page' => 5, 'orderby' => 'updated_at', 'order' => 'DESC' ) );
        $stages        = TwellerFlow2_Database::get_stages();

        include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Sessions list page
     */
    public function page_sessions() {
        $search  = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $stage   = isset( $_GET['stage'] ) ? sanitize_text_field( $_GET['stage'] ) : '';
        $paged   = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $per_page = 20;

        $sessions = TwellerFlow2_Session::get_all( array(
            'search'   => $search,
            'stage'    => $stage,
            'per_page' => $per_page,
            'offset'   => ( $paged - 1 ) * $per_page,
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        ));

        $total = TwellerFlow2_Session::count( array( 'stage' => $stage ) );
        $stages = TwellerFlow2_Database::get_stages();

        include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/sessions.php';
    }

    /**
     * New session page
     */
    public function page_new_session() {
        $packages = get_option( 'tweller_flow_2_packages', array() );
        include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/new-session.php';
    }

    /**
     * Session detail page
     */
    public function page_session_detail() {
        $id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
        $session = TwellerFlow2_Session::get( $id );

        if ( ! $session ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>Session not found.</p></div></div>';
            return;
        }

        $history  = TwellerFlow2_Session::get_history( $id );
        $stages   = TwellerFlow2_Database::get_stages();
        $stage_keys = TwellerFlow2_Database::get_stage_keys();
        $packages = get_option( 'tweller_flow_2_packages', array() );
        $tracker_url = TwellerFlow2_Notifications::get_tracker_url( $session->tracking_code );

        // Get notifications for this session
        global $wpdb;
        $notif_table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_NOTIFICATIONS;
        $notifications = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $notif_table WHERE session_id = %d ORDER BY sent_at DESC",
            $id
        ));

        // WhatsApp link
        $wa_message = "Hi {$session->client_name}! Here's an update on your photo session with Tweller Studios. Track your progress here: {$tracker_url}";
        $wa_link = TwellerFlow2_Notifications::get_whatsapp_link( $session->client_phone, $wa_message );

        include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/session-detail.php';
    }

    /**
     * Notifications page
     */
    public function page_notifications() {
        global $wpdb;
        $notif_table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_NOTIFICATIONS;
        $sessions_table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;

        $notifications = $wpdb->get_results(
            "SELECT n.*, s.client_name, s.tracking_code
             FROM $notif_table n
             LEFT JOIN $sessions_table s ON n.session_id = s.id
             ORDER BY n.sent_at DESC
             LIMIT 50"
        );

        include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/notifications.php';
    }

    /**
     * Offerings page — session types & packages shown on the booking form
     */
    public function page_offerings() {
        $session_types = get_option( 'tweller_flow_2_session_types', array() );
        $packages      = get_option( 'tweller_flow_2_packages', array() );
        include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/offerings.php';
    }

    /**
     * Settings page
     */
    public function page_settings() {
        $smtp      = get_option( 'tweller_flow_2_smtp', array() );
        $banking   = get_option( 'tweller_flow_2_banking', '' );
        $delivery  = get_option( 'tweller_flow_2_delivery_days', 14 );
        $tracker   = get_option( 'tweller_flow_2_tracker_page', '' );
        $secret    = get_option( 'tweller_flow_2_webhook_secret', '' );
        $packages  = get_option( 'tweller_flow_2_packages', array() );
        $automation = TwellerFlow2_Photo_Automation::get_settings();
        $booking_ical = get_option( 'tweller_flow_2_booking_ical_url', '' );

        include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/settings.php';
    }

    public function page_galleries() {
        global $wpdb;
        $sessions_table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;
        $gallery_table  = $wpdb->prefix . 'tweller_gallery_photos';

        // Sessions that already have gallery photos or are in a late stage
        $sessions_with_galleries = $wpdb->get_results(
            "SELECT s.*, COUNT(g.id) as gallery_count
             FROM $sessions_table s
             LEFT JOIN $gallery_table g ON g.session_id = s.id
             GROUP BY s.id
             HAVING gallery_count > 0 OR s.current_stage IN ('edited', 'delivering', 'delivered')
             ORDER BY s.updated_at DESC
             LIMIT 50"
        );

        // All sessions for the manual upload dropdown
        $all_sessions = $wpdb->get_results(
            "SELECT id, tracking_code, client_name, session_date, current_stage
             FROM $sessions_table
             ORDER BY session_date DESC, created_at DESC
             LIMIT 200"
        );

        $tracker_url = get_option( 'tweller_flow_2_tracker_page', '' );

        include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/galleries.php';
    }

    /**
     * AJAX: upload one or more photos to a gallery from the admin backend.
     */
    public function ajax_admin_upload() {
        check_ajax_referer( 'tweller_flow_2_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $session_code = sanitize_text_field( $_POST['session_code'] ?? '' );
        if ( empty( $session_code ) ) {
            wp_send_json_error( 'session_code is required' );
        }

        $session = TwellerFlow2_Session::get_by_code( $session_code );
        if ( ! $session ) {
            wp_send_json_error( 'Session not found: ' . $session_code );
        }

        if ( empty( $_FILES['photos'] ) ) {
            wp_send_json_error( 'No files received' );
        }

        // $_FILES['photos'] may contain multiple files (HTML multiple attribute)
        $files  = $_FILES['photos'];
        $count  = is_array( $files['name'] ) ? count( $files['name'] ) : 1;
        $saved  = array();
        $errors = array();

        for ( $i = 0; $i < $count; $i++ ) {
            $file = is_array( $files['name'] ) ? array(
                'name'     => $files['name'][ $i ],
                'type'     => $files['type'][ $i ],
                'tmp_name' => $files['tmp_name'][ $i ],
                'error'    => $files['error'][ $i ],
                'size'     => $files['size'][ $i ],
            ) : $files;

            $result = TwellerFlow2_Gallery::save_photo_file( $session, $file );
            if ( is_wp_error( $result ) ) {
                $errors[] = $file['name'] . ': ' . $result->get_error_message();
            } else {
                $saved[] = $result['filename'];
            }
        }

        wp_send_json_success( array(
            'saved'  => $saved,
            'errors' => $errors,
            'count'  => count( $saved ),
        ) );
    }
}

new TwellerFlow2_Admin();
