<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menus' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
    }

    /**
     * Register admin menus
     */
    public function add_menus() {
        add_menu_page(
            'Tweller Flow',
            'Tweller Flow',
            'manage_options',
            'tweller-flow',
            array( $this, 'page_dashboard' ),
            'dashicons-camera',
            30
        );

        add_submenu_page(
            'tweller-flow',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'tweller-flow',
            array( $this, 'page_dashboard' )
        );

        add_submenu_page(
            'tweller-flow',
            'Sessions',
            'Sessions',
            'manage_options',
            'tweller-flow-sessions',
            array( $this, 'page_sessions' )
        );

        add_submenu_page(
            'tweller-flow',
            'New Session',
            'New Session',
            'manage_options',
            'tweller-flow-new',
            array( $this, 'page_new_session' )
        );

        add_submenu_page(
            'tweller-flow',
            'Notifications',
            'Notifications',
            'manage_options',
            'tweller-flow-notifications',
            array( $this, 'page_notifications' )
        );

        add_submenu_page(
            'tweller-flow',
            'Settings',
            'Settings',
            'manage_options',
            'tweller-flow-settings',
            array( $this, 'page_settings' )
        );

        // Hidden page for session detail
        add_submenu_page(
            null,
            'Session Detail',
            'Session Detail',
            'manage_options',
            'tweller-flow-session',
            array( $this, 'page_session_detail' )
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'tweller-flow' ) === false ) return;

        wp_enqueue_style(
            'tweller-flow-admin',
            TWELLER_FLOW_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            TWELLER_FLOW_VERSION
        );
        wp_enqueue_script(
            'tweller-flow-admin',
            TWELLER_FLOW_PLUGIN_URL . 'admin/js/admin.js',
            array( 'jquery' ),
            TWELLER_FLOW_VERSION,
            true
        );
        wp_localize_script( 'tweller-flow-admin', 'twellerFlow', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'tweller_flow_nonce' ),
            'restUrl' => rest_url( 'tweller-flow/v1/' ),
            'restNonce' => wp_create_nonce( 'wp_rest' ),
        ));
    }

    /**
     * Handle form submissions and actions
     */
    public function handle_actions() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Create session
        if ( isset( $_POST['tweller_flow_create_session'] ) ) {
            check_admin_referer( 'tweller_flow_create_session' );
            $session_id = TwellerFlow_Session::create( $_POST );
            if ( $session_id ) {
                wp_redirect( admin_url( 'admin.php?page=tweller-flow-session&id=' . $session_id . '&created=1' ) );
                exit;
            }
        }

        // Update session
        if ( isset( $_POST['tweller_flow_update_session'] ) ) {
            check_admin_referer( 'tweller_flow_update_session' );
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
            TwellerFlow_Session::update( $id, $update_data );
            wp_redirect( admin_url( 'admin.php?page=tweller-flow-session&id=' . $id . '&updated=1' ) );
            exit;
        }

        // Advance stage
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'advance' && isset( $_GET['session_id'] ) ) {
            check_admin_referer( 'tweller_flow_advance_' . $_GET['session_id'] );
            $id = intval( $_GET['session_id'] );
            $notes = sanitize_text_field( $_GET['notes'] ?? '' );
            TwellerFlow_Session::advance_stage( $id, $notes );
            wp_redirect( admin_url( 'admin.php?page=tweller-flow-session&id=' . $id . '&advanced=1' ) );
            exit;
        }

        // Set specific stage
        if ( isset( $_POST['tweller_flow_set_stage'] ) ) {
            check_admin_referer( 'tweller_flow_set_stage' );
            $id = intval( $_POST['session_id'] );
            $stage = sanitize_text_field( $_POST['stage'] );
            $notes = sanitize_text_field( $_POST['stage_notes'] ?? '' );
            TwellerFlow_Session::set_stage( $id, $stage, $notes );
            wp_redirect( admin_url( 'admin.php?page=tweller-flow-session&id=' . $id . '&stage_set=1' ) );
            exit;
        }

        // Delete session
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['session_id'] ) ) {
            check_admin_referer( 'tweller_flow_delete_' . $_GET['session_id'] );
            TwellerFlow_Session::delete( intval( $_GET['session_id'] ) );
            wp_redirect( admin_url( 'admin.php?page=tweller-flow-sessions&deleted=1' ) );
            exit;
        }

        // Save settings
        if ( isset( $_POST['tweller_flow_save_settings'] ) ) {
            check_admin_referer( 'tweller_flow_save_settings' );
            update_option( 'tweller_flow_smtp', array(
                'host'       => sanitize_text_field( $_POST['smtp_host'] ),
                'port'       => intval( $_POST['smtp_port'] ),
                'encryption' => sanitize_text_field( $_POST['smtp_encryption'] ),
                'username'   => sanitize_text_field( $_POST['smtp_username'] ),
                'password'   => $_POST['smtp_password'], // Allow special chars in password
                'from_name'  => sanitize_text_field( $_POST['smtp_from_name'] ),
                'from_email' => sanitize_email( $_POST['smtp_from_email'] ),
            ));
            update_option( 'tweller_flow_banking', sanitize_textarea_field( $_POST['banking_info'] ) );
            update_option( 'tweller_flow_delivery_days', intval( $_POST['delivery_days'] ) );
            update_option( 'tweller_flow_tracker_page', esc_url_raw( $_POST['tracker_page_url'] ) );
            update_option( 'tweller_flow_webhook_secret', sanitize_text_field( $_POST['webhook_secret'] ) );
            wp_redirect( admin_url( 'admin.php?page=tweller-flow-settings&saved=1' ) );
            exit;
        }

        // Send manual notification
        if ( isset( $_POST['tweller_flow_send_notification'] ) ) {
            check_admin_referer( 'tweller_flow_send_notification' );
            $id = intval( $_POST['session_id'] );
            $session = TwellerFlow_Session::get( $id );
            if ( $session ) {
                $subject = sanitize_text_field( $_POST['notif_subject'] );
                $body = wp_kses_post( $_POST['notif_body'] );
                TwellerFlow_Notifications::send_email( $session, $subject, $body );
                wp_redirect( admin_url( 'admin.php?page=tweller-flow-session&id=' . $id . '&notified=1' ) );
                exit;
            }
        }
    }

    /**
     * Dashboard page
     */
    public function page_dashboard() {
        $active_count  = TwellerFlow_Session::count_active();
        $total_count   = TwellerFlow_Session::count();
        $stage_counts  = TwellerFlow_Session::get_stage_counts();
        $revenue       = TwellerFlow_Session::get_revenue_stats();
        $recent        = TwellerFlow_Session::get_all( array( 'per_page' => 5, 'orderby' => 'updated_at', 'order' => 'DESC' ) );
        $stages        = TwellerFlow_Database::get_stages();

        include TWELLER_FLOW_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Sessions list page
     */
    public function page_sessions() {
        $search  = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $stage   = isset( $_GET['stage'] ) ? sanitize_text_field( $_GET['stage'] ) : '';
        $paged   = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $per_page = 20;

        $sessions = TwellerFlow_Session::get_all( array(
            'search'   => $search,
            'stage'    => $stage,
            'per_page' => $per_page,
            'offset'   => ( $paged - 1 ) * $per_page,
            'orderby'  => 'updated_at',
            'order'    => 'DESC',
        ));

        $total = TwellerFlow_Session::count( array( 'stage' => $stage ) );
        $stages = TwellerFlow_Database::get_stages();

        include TWELLER_FLOW_PLUGIN_DIR . 'admin/views/sessions.php';
    }

    /**
     * New session page
     */
    public function page_new_session() {
        $packages = get_option( 'tweller_flow_packages', array() );
        include TWELLER_FLOW_PLUGIN_DIR . 'admin/views/new-session.php';
    }

    /**
     * Session detail page
     */
    public function page_session_detail() {
        $id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
        $session = TwellerFlow_Session::get( $id );

        if ( ! $session ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>Session not found.</p></div></div>';
            return;
        }

        $history  = TwellerFlow_Session::get_history( $id );
        $stages   = TwellerFlow_Database::get_stages();
        $stage_keys = TwellerFlow_Database::get_stage_keys();
        $packages = get_option( 'tweller_flow_packages', array() );
        $tracker_url = TwellerFlow_Notifications::get_tracker_url( $session->tracking_code );

        // Get notifications for this session
        global $wpdb;
        $notif_table = $wpdb->prefix . TWELLER_FLOW_TABLE_NOTIFICATIONS;
        $notifications = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $notif_table WHERE session_id = %d ORDER BY sent_at DESC",
            $id
        ));

        // WhatsApp link
        $wa_message = "Hi {$session->client_name}! Here's an update on your photo session with Tweller Studios. Track your progress here: {$tracker_url}";
        $wa_link = TwellerFlow_Notifications::get_whatsapp_link( $session->client_phone, $wa_message );

        include TWELLER_FLOW_PLUGIN_DIR . 'admin/views/session-detail.php';
    }

    /**
     * Notifications page
     */
    public function page_notifications() {
        global $wpdb;
        $notif_table = $wpdb->prefix . TWELLER_FLOW_TABLE_NOTIFICATIONS;
        $sessions_table = $wpdb->prefix . TWELLER_FLOW_TABLE_SESSIONS;

        $notifications = $wpdb->get_results(
            "SELECT n.*, s.client_name, s.tracking_code
             FROM $notif_table n
             LEFT JOIN $sessions_table s ON n.session_id = s.id
             ORDER BY n.sent_at DESC
             LIMIT 50"
        );

        include TWELLER_FLOW_PLUGIN_DIR . 'admin/views/notifications.php';
    }

    /**
     * Settings page
     */
    public function page_settings() {
        $smtp      = get_option( 'tweller_flow_smtp', array() );
        $banking   = get_option( 'tweller_flow_banking', '' );
        $delivery  = get_option( 'tweller_flow_delivery_days', 14 );
        $tracker   = get_option( 'tweller_flow_tracker_page', '' );
        $secret    = get_option( 'tweller_flow_webhook_secret', '' );
        $packages  = get_option( 'tweller_flow_packages', array() );

        include TWELLER_FLOW_PLUGIN_DIR . 'admin/views/settings.php';
    }
}

new TwellerFlow_Admin();
