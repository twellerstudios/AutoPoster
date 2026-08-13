<?php
/**
 * Tweller Flow — Client Culling Portal
 *
 * Allows clients to view proof images and select which ones to retouch.
 * Handles proof uploads, selections, package limits, and upsell pricing.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Culling {

    const TABLE_PROOFS     = 'tweller_culling_proofs';
    const TABLE_SELECTIONS = 'tweller_culling_selections';
    const UPLOAD_SUBDIR    = 'tweller-culling';

    /** Price per additional photo beyond package limit (TTD) */
    const UPSELL_PRICE_PER_PHOTO = 30;

    const FREEBIES = 0;

    /** Proofs are heavily compressed previews, never delivery files */
    const PROOF_MAX_EDGE = 1600;
    const PROOF_QUALITY  = 60;

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
        add_shortcode( 'tweller_culling', array( __CLASS__, 'render_shortcode' ) );
    }

    // ── REST Routes ─────────────────────────────────────

    public static function register_rest_routes() {
        $ns = 'tweller-flow-2/v1';

        // Upload proof photo (from watcher, auth required)
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9\-]+)/upload', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_upload_proof' ),
            'permission_callback' => array( 'TwellerFlow2_Photo_Automation', 'verify_api_key' ),
        ));

        // Get proofs for client (public, password-protected)
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9\-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_proofs' ),
            'permission_callback' => '__return_true',
        ));

        // Submit selections (public)
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9\-]+)/select', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_submit_selections' ),
            'permission_callback' => '__return_true',
        ));

        // Get selections (watcher pulls these)
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9\-]+)/selections', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_selections' ),
            'permission_callback' => array( 'TwellerFlow2_Photo_Automation', 'verify_api_key' ),
        ));

        // Verify culling password
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9\-]+)/verify', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_verify_password' ),
            'permission_callback' => '__return_true',
        ));

        // Batch upload status / mark culling ready (from watcher)
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9\-]+)/ready', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_mark_ready' ),
            'permission_callback' => array( 'TwellerFlow2_Photo_Automation', 'verify_api_key' ),
        ));

        // Get existing proof filenames (dedup for watcher)
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9\-]+)/filenames', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_filenames' ),
            'permission_callback' => array( 'TwellerFlow2_Photo_Automation', 'verify_api_key' ),
        ));

        // ── Admin-authenticated routes ─────────────────
        // Upload proofs from admin dashboard
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9\-]+)/upload-admin', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_upload_admin' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));

        // List proofs for admin view
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9\-]+)/admin-proofs', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_admin_proofs' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));

        // Delete a single proof
        register_rest_route( $ns, '/culling/proof/(?P<proof_id>\d+)', array(
            'methods'             => 'DELETE',
            'callback'            => array( __CLASS__, 'rest_delete_proof_admin' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));

        // Delete ALL proofs for a session (admin)
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9\-]+)/all-proofs', array(
            'methods'             => 'DELETE',
            'callback'            => array( __CLASS__, 'rest_delete_all_proofs' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));

        // Admin sets/edits selections on the client's behalf
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9\-]+)/admin-select', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_admin_select' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));

        // Mark ready (admin version)
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9\-]+)/admin-ready', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_mark_ready_admin' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));

        // Enable / disable culling for a session
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9\-]+)/toggle', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_toggle_culling' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));

        // Download selections as CSV (admin)
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9\-]+)/download-selections', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_download_selections' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));

        // Download selections as XMP metadata (admin) — for Lightroom import
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9\-]+)/download-xmp', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_download_xmp' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));

        // Get all proofs with selection status (admin)
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9\-]+)/admin-proofs-status', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_proofs_with_status' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));
    }

    // ── Database ───────────────────────────────────────

    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $proofs_table = $wpdb->prefix . self::TABLE_PROOFS;
        $sql_proofs = "CREATE TABLE $proofs_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            session_code varchar(120) NOT NULL,
            filename varchar(255) NOT NULL,
            original_filename varchar(255) NOT NULL,
            sort_order int DEFAULT 0,
            uploaded_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY session_code (session_code)
        ) $charset_collate;";

        $selections_table = $wpdb->prefix . self::TABLE_SELECTIONS;
        $sql_selections = "CREATE TABLE $selections_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            session_code varchar(120) NOT NULL,
            proof_id bigint(20) unsigned NOT NULL,
            filename varchar(255) NOT NULL,
            star_rating tinyint(1) DEFAULT 1,
            selected_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            UNIQUE KEY unique_selection (session_id, proof_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql_proofs );
        dbDelta( $sql_selections );
    }

    // ── Upload Proof (from Watcher) ────────────────────

    public static function rest_upload_proof( $request ) {
        $code = sanitize_text_field( $request['code'] );

        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        // API-key uploads (Lightroom / watcher) auto-enable culling so the
        // photographer doesn't have to flip the switch in the dashboard first.
        if ( ! self::is_culling_enabled( $session->id ) ) {
            self::enable_culling( $session->id );
        }

        // Fresh round: proofs arriving into an EMPTY gallery after a previous
        // submission means the old round was wiped — reset it so the client
        // portal shows the selection view again instead of "already received".
        if ( self::count_proofs( $session->id ) === 0
             && get_option( 'tweller_culling_submitted_' . $session->id, false ) ) {
            self::reset_culling_round( $session->id );
        }

        $files = $request->get_file_params();
        if ( empty( $files['photo'] ) ) {
            return new WP_Error( 'no_file', 'No photo file provided', array( 'status' => 400 ) );
        }

        $file = $files['photo'];
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return new WP_Error( 'upload_error', 'Upload failed', array( 'status' => 400 ) );
        }

        // Validate type
        $allowed = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/webp' );
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );
        if ( ! in_array( $mime, $allowed ) ) {
            return new WP_Error( 'invalid_type', 'Only JPEG, PNG, and WebP allowed', array( 'status' => 400 ) );
        }

        $original_filename = sanitize_text_field( $request->get_param( 'original_filename' ) ) ?: $file['name'];

        // Save proof
        $proof_dir = self::get_proof_dir( $code );
        $thumbs_dir = $proof_dir . '/thumbs';
        wp_mkdir_p( $proof_dir );
        wp_mkdir_p( $thumbs_dir );

        $filename = sanitize_file_name( $file['name'] );
        $dest = $proof_dir . '/' . $filename;

        // Avoid overwrites
        $i = 1;
        while ( file_exists( $dest ) ) {
            $info = pathinfo( $filename );
            $filename = $info['filename'] . '-' . $i . '.' . $info['extension'];
            $dest = $proof_dir . '/' . $filename;
            $i++;
        }

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            return new WP_Error( 'move_failed', 'Could not save file', array( 'status' => 500 ) );
        }

        // Compress to preview size and stamp the studio watermark —
        // proofs are for selection only, never delivery quality.
        self::process_proof_image( $dest );

        // Generate thumbnail (600px for proof grid, inherits the watermark)
        $thumb_path = $thumbs_dir . '/' . $filename;
        self::create_thumbnail( $dest, $thumb_path, 600 );

        // Insert DB record
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PROOFS;
        $max_order = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(sort_order) FROM $table WHERE session_id = %d", $session->id
        ));

        $wpdb->insert( $table, array(
            'session_id'        => $session->id,
            'session_code'      => $code,
            'filename'          => $filename,
            'original_filename' => sanitize_file_name( $original_filename ),
            'sort_order'        => ( $max_order ?? -1 ) + 1,
            'uploaded_at'       => current_time( 'mysql' ),
        ));

        return rest_ensure_response( array(
            'ok'       => true,
            'proof_id' => $wpdb->insert_id,
            'filename' => $filename,
        ));
    }

    // ── Get Proofs (Client) ────────────────────────────

    public static function rest_get_proofs( $request ) {
        $code  = sanitize_text_field( $request['code'] );
        $token = sanitize_text_field( $request->get_param( 'token' ) );

        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        if ( ! self::is_culling_enabled( $session->id ) ) {
            return rest_ensure_response( array( 'ok' => true, 'culling_enabled' => false ) );
        }

        // Check if culling is ready (proofs uploaded)
        $is_ready = get_option( 'tweller_culling_ready_' . $session->id, false );

        // Check password
        $password_hash = get_option( 'tweller_culling_pw_' . $session->id, '' );
        $has_password = ! empty( $password_hash );
        $is_unlocked = false;

        if ( $has_password && $token ) {
            $is_unlocked = TwellerFlow2_Gallery::verify_token( $session->id, $token );
        } elseif ( ! $has_password ) {
            $is_unlocked = true;
        }

        if ( ! $is_unlocked ) {
            return rest_ensure_response( array(
                'ok'              => true,
                'culling_enabled' => true,
                'ready'           => (bool) $is_ready,
                'has_password'    => true,
                'unlocked'        => false,
                'client_name'     => $session->client_name,
                'session_date'    => $session->session_date,
            ));
        }

        // Already submitted?
        $submitted = get_option( 'tweller_culling_submitted_' . $session->id, false );

        // Get package info
        $packages = get_option( 'tweller_flow_2_packages', array() );
        $pkg = $packages[ $session->package_type ] ?? array();
        $included_images = $pkg['images'] ?? 15;

        // Get proofs
        $proofs = self::get_proofs( $session->id );
        $proof_url = self::get_proof_url( $code );

        $proof_list = array();
        foreach ( $proofs as $proof ) {
            $proof_list[] = array(
                'id'        => $proof->id,
                'filename'  => $proof->filename,
                'thumb_url' => $proof_url . '/thumbs/' . $proof->filename,
                'url'       => $proof_url . '/' . $proof->filename,
            );
        }

        // Get current selections
        $selections = self::get_selections( $session->id );
        $selected_ids = array_map( function( $s ) { return (int) $s->proof_id; }, $selections );

        return rest_ensure_response( array(
            'ok'                    => true,
            'culling_enabled'       => true,
            'ready'                 => (bool) $is_ready,
            'has_password'          => $has_password,
            'unlocked'              => true,
            'submitted'             => (bool) $submitted,
            'client_name'           => $session->client_name,
            'session_date'          => $session->session_date,
            'package_name'          => $pkg['name'] ?? ucfirst( $session->package_type ),
            'included_images'       => $included_images,
            'freebies'              => self::FREEBIES,
            'max_free'              => $included_images + self::FREEBIES,
            'upsell_price_per_photo'=> self::UPSELL_PRICE_PER_PHOTO,
            'proofs'                => $proof_list,
            'proof_count'           => count( $proof_list ),
            'selected_ids'          => $selected_ids,
        ));
    }

    // ── Submit Selections ──────────────────────────────

    public static function rest_submit_selections( $request ) {
        $code      = sanitize_text_field( $request['code'] );
        $token     = sanitize_text_field( $request->get_param( 'token' ) );
        $proof_ids = $request->get_param( 'proof_ids' );
        $upsell    = sanitize_text_field( $request->get_param( 'upsell_tier' ) );

        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        // Check already submitted
        if ( get_option( 'tweller_culling_submitted_' . $session->id, false ) ) {
            return new WP_Error( 'already_submitted', 'Selections already submitted', array( 'status' => 400 ) );
        }

        if ( ! is_array( $proof_ids ) || empty( $proof_ids ) ) {
            return new WP_Error( 'no_selections', 'No photos selected', array( 'status' => 400 ) );
        }

        $result = self::apply_selections( $session, $code, $proof_ids, 'Client submitted' );
        $total_selected = $result['total'];
        $extra_count    = $result['extra'];
        $extra_cost_ttd = $result['extra_cost'];
        $included       = $result['included'];

        // Track activity
        if ( class_exists( 'TwellerFlow2_Client_Activity' ) ) {
            TwellerFlow2_Client_Activity::log(
                $session->id, $code, 'culling_submitted',
                $total_selected . ' photos selected' . ( $extra_count > 0 ? ' (+' . $extra_count . ' extra = $' . $extra_cost_ttd . ' TTD)' : '' )
            );
        }

        // Send confirmation email to client
        self::send_selection_email( $session, $total_selected, $included, $extra_count, $extra_cost_ttd );

        return rest_ensure_response( array(
            'ok'         => true,
            'selected'   => $total_selected,
            'extra'      => $extra_count,
            'extra_cost' => $extra_cost_ttd,
        ));
    }

    /**
     * Persist a set of proof selections (in click order: the first
     * package-included picks get 1 star, extras get 2), mark the round
     * submitted, store upsell info, and advance the pipeline to "culled".
     * Shared by the client portal submit and the admin editor.
     */
    private static function apply_selections( $session, $code, $proof_ids, $who = 'Client submitted' ) {
        $packages = get_option( 'tweller_flow_2_packages', array() );
        $pkg = $packages[ $session->package_type ] ?? array();
        $included = ( $pkg['images'] ?? 15 ) + self::FREEBIES;

        // Per-photo model: no hard cap — extra photos incur a per-photo fee
        $total_selected = count( $proof_ids );
        $extra_count    = max( 0, $total_selected - $included );
        $extra_cost_ttd = $extra_count * self::UPSELL_PRICE_PER_PHOTO;

        global $wpdb;
        $sel_table   = $wpdb->prefix . self::TABLE_SELECTIONS;
        $proof_table = $wpdb->prefix . self::TABLE_PROOFS;

        // Clear old selections
        $wpdb->delete( $sel_table, array( 'session_id' => $session->id ) );

        $selection_index = 0;
        foreach ( $proof_ids as $pid ) {
            $pid = intval( $pid );
            $proof = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $proof_table WHERE id = %d AND session_id = %d", $pid, $session->id
            ));
            if ( ! $proof ) continue;

            $selection_index++;
            // First $included selections = 1 star (base package), extras = 2 stars
            $star_rating = ( $selection_index <= $included ) ? 1 : 2;

            $wpdb->insert( $sel_table, array(
                'session_id'   => $session->id,
                'session_code' => $code,
                'proof_id'     => $pid,
                'filename'     => $proof->original_filename,
                'star_rating'  => $star_rating,
                'selected_at'  => current_time( 'mysql' ),
            ));
        }

        // Mark as submitted
        update_option( 'tweller_culling_submitted_' . $session->id, current_time( 'mysql' ) );

        // Advance the pipeline: selections are in, session moves to "culled"
        $stage_keys  = TwellerFlow2_Database::get_stage_keys();
        $current_idx = array_search( $session->current_stage, $stage_keys );
        $culled_idx  = array_search( 'culled', $stage_keys );
        if ( $current_idx !== false && $culled_idx !== false && $current_idx < $culled_idx ) {
            TwellerFlow2_Session::set_stage( $session->id, 'culled', $who . ' ' . $total_selected . ' photo selections', false );
        }

        // Store extra cost info
        update_option( 'tweller_culling_upsell_' . $session->id, array(
            'extra_count' => $extra_count,
            'price_each'  => self::UPSELL_PRICE_PER_PHOTO,
            'total_price' => $extra_cost_ttd,
            'count'       => $total_selected,
        ));

        return array(
            'total'      => $total_selected,
            'extra'      => $extra_count,
            'extra_cost' => $extra_cost_ttd,
            'included'   => $included,
        );
    }

    /**
     * Admin edits selections on the client's behalf. Unlike the client
     * endpoint this may overwrite an existing submission, and sending an
     * empty list clears the selections (reopening the round for the client).
     */
    public static function rest_admin_select( $request ) {
        $code    = sanitize_text_field( $request['code'] );
        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $proof_ids = $request->get_param( 'proof_ids' );
        if ( ! is_array( $proof_ids ) ) {
            $proof_ids = array();
        }

        if ( empty( $proof_ids ) ) {
            // Clear everything — client can select again
            self::reset_culling_round( $session->id );
            return rest_ensure_response( array( 'ok' => true, 'selected' => 0, 'cleared' => true ) );
        }

        $result = self::apply_selections( $session, $code, $proof_ids, 'Admin set' );

        if ( class_exists( 'TwellerFlow2_Client_Activity' ) ) {
            TwellerFlow2_Client_Activity::log(
                $session->id, $code, 'culling_submitted',
                'Admin set ' . $result['total'] . ' photo selections'
            );
        }

        return rest_ensure_response( array(
            'ok'       => true,
            'selected' => $result['total'],
            'extra'    => $result['extra'],
        ));
    }

    // ── Get Selections (Watcher) ───────────────────────

    public static function rest_get_selections( $request ) {
        $code = sanitize_text_field( $request['code'] );
        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $submitted = get_option( 'tweller_culling_submitted_' . $session->id, false );
        if ( ! $submitted ) {
            return rest_ensure_response( array(
                'ok'        => true,
                'submitted' => false,
                'filenames' => array(),
            ));
        }

        $selections = self::get_selections( $session->id );
        $filenames = array();
        foreach ( $selections as $sel ) {
            $filenames[] = $sel->filename; // original_filename stored in selections
        }

        $upsell = get_option( 'tweller_culling_upsell_' . $session->id, null );

        return rest_ensure_response( array(
            'ok'             => true,
            'submitted'      => true,
            'submitted_at'   => $submitted,
            'filenames'      => $filenames,
            'count'          => count( $filenames ),
            'upsell'         => $upsell,
        ));
    }

    // ── Verify Password ────────────────────────────────

    public static function rest_verify_password( $request ) {
        $code     = sanitize_text_field( $request['code'] );
        $password = sanitize_text_field( $request->get_param( 'password' ) );

        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Not found', array( 'status' => 404 ) );
        }

        $password_hash = get_option( 'tweller_culling_pw_' . $session->id, '' );
        if ( empty( $password_hash ) ) {
            $token = TwellerFlow2_Gallery::generate_token( $session->id );
            return rest_ensure_response( array( 'ok' => true, 'token' => $token ) );
        }

        if ( ! wp_check_password( $password, $password_hash ) ) {
            return new WP_Error( 'wrong_password', 'Incorrect password', array( 'status' => 403 ) );
        }

        $token = TwellerFlow2_Gallery::generate_token( $session->id );
        return rest_ensure_response( array( 'ok' => true, 'token' => $token ) );
    }

    // ── Mark Ready (Watcher) ───────────────────────────

    public static function rest_mark_ready( $request ) {
        $code = sanitize_text_field( $request['code'] );
        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $password = sanitize_text_field( $request->get_param( 'password' ) );
        if ( $password ) {
            self::set_password( $session->id, $password );
        }

        update_option( 'tweller_culling_ready_' . $session->id, current_time( 'mysql' ) );

        // Update culling URL on session
        $culling_url = self::get_culling_page_url( $code );
        if ( $culling_url ) {
            update_option( 'tweller_culling_url_' . $session->id, $culling_url );
        }

        // Move the session to the "culling" stage so the tracker shows
        // "Select Photos for Editing" (email below handles notification)
        $stage_keys  = TwellerFlow2_Database::get_stage_keys();
        $current_idx = array_search( $session->current_stage, $stage_keys );
        $culling_idx = array_search( 'culling', $stage_keys );
        if ( $current_idx !== false && $culling_idx !== false && $current_idx < $culling_idx ) {
            TwellerFlow2_Session::set_stage( $session->id, 'culling', 'Proofs uploaded — awaiting client selection', false );
        }

        // Send culling email to client
        self::send_culling_email( $session );

        $proof_count = self::count_proofs( $session->id );

        return rest_ensure_response( array(
            'ok'          => true,
            'proof_count' => $proof_count,
            'culling_url' => $culling_url,
        ));
    }

    // ── Get Filenames (dedup) ──────────────────────────

    public static function rest_get_filenames( $request ) {
        $code = sanitize_text_field( $request['code'] );
        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $proofs = self::get_proofs( $session->id );
        $filenames = array();
        foreach ( $proofs as $p ) {
            $filenames[] = $p->filename;
        }

        return rest_ensure_response( array(
            'ok'        => true,
            'filenames' => $filenames,
            'count'     => count( $filenames ),
        ));
    }

    // ── Shortcode ──────────────────────────────────────

    public static function render_shortcode( $atts ) {
        wp_enqueue_style( 'tweller-flow-2-culling' );
        wp_enqueue_script( 'tweller-flow-2-culling' );

        // The "edited preview" look: CSS-filter approximation of the studio's
        // Lightroom preset, tunable in Settings > Edited Preview.
        $preset = get_option( 'tweller_flow_2_culling_preset', array() );
        $preset = wp_parse_args( $preset, array(
            'brightness' => 1.06,
            'contrast'   => 1.12,
            'saturate'   => 1.16,
            'warmth'     => 0.12,
            'hue'        => -3,
        ) );
        $preset_filter = sprintf(
            'brightness(%s) contrast(%s) saturate(%s) sepia(%s) hue-rotate(%sdeg)',
            floatval( $preset['brightness'] ),
            floatval( $preset['contrast'] ),
            floatval( $preset['saturate'] ),
            floatval( $preset['warmth'] ),
            floatval( $preset['hue'] )
        );

        wp_localize_script( 'tweller-flow-2-culling', 'twellerCulling', array(
            'apiUrl'       => rest_url( 'tweller-flow-2/v1/culling/' ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'presetFilter' => $preset_filter,
        ));

        $code = isset( $_GET['code'] ) ? sanitize_text_field( $_GET['code'] ) : '';

        ob_start();

        if ( empty( $code ) ) {
            echo '<div class="tc-portal tc-portal--empty"><p>No session code provided.</p></div>';
        } else {
            $session = TwellerFlow2_Session::get_by_code( $code );
            if ( ! $session ) {
                echo '<div class="tc-portal tc-portal--error"><p>Session not found.</p></div>';
            } else {
                self::render_portal( $session );
            }
        }

        return ob_get_clean();
    }

    private static function render_portal( $session ) {
        $packages = get_option( 'tweller_flow_2_packages', array() );
        $pkg = $packages[ $session->package_type ] ?? array();
        $pkg_name = $pkg['name'] ?? ucfirst( $session->package_type );
        $included = $pkg['images'] ?? 15;
        ?>
        <script>document.title = <?php echo wp_json_encode( $session->client_name . ' — Choose Your Photos | Tweller Studios' ); ?>;</script>
        <div class="tc-portal" data-code="<?php echo esc_attr( $session->tracking_code ); ?>">

            <!-- Header -->
            <div class="tc-portal__header">
                <h1 class="tc-portal__title">TWELLER STUDIOS</h1>
                <h2 class="tc-portal__subtitle">Choose Your Photos</h2>
                <p class="tc-portal__greeting">Hi <?php echo esc_html( $session->client_name ); ?>! Browse your proofs and select the ones you'd like retouched.</p>
                <div class="tc-portal__meta">
                    <span class="tc-portal__badge"><?php echo esc_html( $pkg_name ); ?></span>
                    <span class="tc-portal__included"><?php echo $included; ?> photos included + <?php echo self::FREEBIES; ?> free</span>
                </div>
            </div>

            <!-- Password gate -->
            <div class="tc-portal__password" id="tc-password" style="display:none;">
                <div class="tc-portal__lock-icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1"/></svg>
                </div>
                <p>Enter your password to view your proofs.</p>
                <form class="tc-portal__pw-form" id="tc-pw-form">
                    <input type="password" id="tc-pw-input" placeholder="Enter password" required>
                    <button type="submit" class="tc-btn tc-btn--primary">Unlock</button>
                </form>
                <p class="tc-portal__pw-error" id="tc-pw-error" style="display:none;">Incorrect password.</p>
            </div>

            <!-- Loading -->
            <div class="tc-portal__loading" id="tc-loading">
                <div class="tc-spinner"></div>
                <p>Loading your proofs...</p>
            </div>

            <!-- Not ready -->
            <div class="tc-portal__message" id="tc-not-ready" style="display:none;">
                <p>Your proofs are being prepared. We'll send you an email when they're ready!</p>
            </div>

            <!-- Already submitted -->
            <div class="tc-portal__message tc-portal__message--success" id="tc-submitted" style="display:none;">
                <h3>Selections Received!</h3>
                <p>Thank you! We've received your photo selections and will begin retouching them shortly.</p>
                <p>Track your session progress: <a href="<?php echo esc_url( TwellerFlow2_Notifications::get_tracker_url( $session->tracking_code ) ); ?>">View Tracker</a></p>
            </div>

            <!-- Selection counter (sticky) -->
            <div class="tc-counter" id="tc-counter" style="display:none;">
                <div class="tc-counter__inner">
                    <div class="tc-counter__left">
                        <span class="tc-counter__count" id="tc-count">0</span>
                        <span class="tc-counter__sep">/</span>
                        <span class="tc-counter__max" id="tc-max">0</span>
                        <span class="tc-counter__label">selected</span>
                    </div>
                    <div class="tc-counter__right">
                        <button class="tc-btn tc-btn--primary tc-btn--submit" id="tc-submit-btn" disabled>Submit Selections</button>
                    </div>
                </div>
            </div>

            <!-- Photo grid -->
            <div class="tc-grid" id="tc-grid" style="display:none;"></div>

            <!-- Confirm modal -->
            <div class="tc-modal" id="tc-confirm-modal" style="display:none;">
                <div class="tc-modal__backdrop"></div>
                <div class="tc-modal__content">
                    <h3>Confirm Your Selections</h3>
                    <p>You've selected <strong id="tc-confirm-count">0</strong> photos for retouching.</p>
                    <p id="tc-confirm-upsell" style="display:none; background:#FEF9EC; border:1px solid #FCD34D; border-radius:8px; padding:12px 14px; color:#92400E;"></p>
                    <p><strong>This cannot be undone.</strong> Are you sure?</p>
                    <div class="tc-modal__actions">
                        <button class="tc-btn tc-btn--secondary" id="tc-confirm-cancel">Go Back</button>
                        <button class="tc-btn tc-btn--primary" id="tc-confirm-yes">Confirm &amp; Submit</button>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    // ── Email ──────────────────────────────────────────

    public static function send_culling_email( $session ) {
        if ( empty( $session->client_email ) ) return;

        $culling_url = self::get_culling_page_url( $session->tracking_code );
        $packages = get_option( 'tweller_flow_2_packages', array() );
        $pkg = $packages[ $session->package_type ] ?? array();
        $pkg_name = $pkg['name'] ?? ucfirst( $session->package_type );
        $included = $pkg['images'] ?? 15;

        $first_name = trim( explode( ' ', trim( $session->client_name ) )[0] );
        $subject = "Time to choose your photos, {$first_name} ✨";

        $package_details = TwellerFlow2_Notifications::email_card( 'Your Package', "
                <p style='margin:6px 0; color:#3D3630;'><strong>{$pkg_name}:</strong> {$included} retouched photos included" . ( self::FREEBIES > 0 ? " + " . self::FREEBIES . " bonus free" : "" ) . "</p>
                <p style='margin:6px 0; color:#3D3630;'>Select up to <strong>" . ( $included + self::FREEBIES ) . "</strong> photos at no extra cost.</p>
                <p style='margin:6px 0; color:#8A8178;'><em>Want more? You can add extra photos during selection.</em></p>
            " );
        $choose_button = "<div style='text-align:center; margin:30px 0;'>" . TwellerFlow2_Notifications::email_button( $culling_url, 'Choose My Photos' ) . "</div>";
        $before_card   = TwellerFlow2_Notifications::email_card( 'Before You Start', "
                <p style='margin:0; color:#3D3630; line-height:1.7;'>Once you submit your selections they can't be changed, so take your time — there's no rush.</p>
            " );

        $body = "
            <h2 style='color:#101010; font-weight:600;'>Your proofs are ready</h2>
            <p style='color:#3D3630;'>Hi {$session->client_name},</p>
            <p style='color:#3D3630; line-height:1.7;'>The exciting part — your photo proofs are ready for viewing. Take your time browsing, and pick the ones you'd love us to retouch and finish for you.</p>

            {$package_details}

            {$choose_button}

            {$before_card}

            <p style='color:#3D3630;'>Warm regards,<br><strong>The Tweller Studios Team</strong></p>
        ";

        list( $subject, $body ) = TwellerFlow2_Email_Templates::resolve( 'culling_ready', $subject, $body, array(
            'client_name'     => esc_html( $session->client_name ),
            'first_name'      => esc_html( $first_name ),
            'package'         => esc_html( $pkg_name ),
            'package_details' => $package_details,
            'choose_button'   => $choose_button,
        ) );

        TwellerFlow2_Notifications::send_email( $session, $subject, $body );
    }

    // ── Helpers ────────────────────────────────────────

    public static function is_culling_enabled( $session_id ) {
        return (bool) get_option( 'tweller_culling_enabled_' . $session_id, false );
    }

    public static function enable_culling( $session_id, $password = '' ) {
        update_option( 'tweller_culling_enabled_' . $session_id, true );
        if ( $password ) {
            self::set_password( $session_id, $password );
        }
    }

    public static function disable_culling( $session_id ) {
        delete_option( 'tweller_culling_enabled_' . $session_id );
    }

    /**
     * Reset a culling round: wipe selections and the submitted/ready/upsell
     * flags so the portal goes back to the selection view. Used when all
     * proofs are removed or a fresh set is uploaded after a wipe.
     */
    public static function reset_culling_round( $session_id ) {
        global $wpdb;
        $sel_table = $wpdb->prefix . self::TABLE_SELECTIONS;
        $wpdb->delete( $sel_table, array( 'session_id' => $session_id ) );

        delete_option( 'tweller_culling_submitted_' . $session_id );
        delete_option( 'tweller_culling_ready_' . $session_id );
        delete_option( 'tweller_culling_upsell_' . $session_id );
    }

    public static function set_password( $session_id, $password ) {
        $hash = wp_hash_password( $password );
        update_option( 'tweller_culling_pw_' . $session_id, $hash );
    }

    public static function get_proofs( $session_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PROOFS;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE session_id = %d ORDER BY sort_order ASC", $session_id
        ));
    }

    public static function count_proofs( $session_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PROOFS;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE session_id = %d", $session_id
        ));
    }

    public static function get_selections( $session_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SELECTIONS;
        $selections = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE session_id = %d ORDER BY id ASC", $session_id
        ));

        // Ensure star ratings are set correctly
        if ( ! empty( $selections ) ) {
            $session = TwellerFlow2_Session::get( $session_id );
            if ( $session ) {
                $packages = get_option( 'tweller_flow_2_packages', array() );
                $pkg      = $packages[ $session->package_type ] ?? array();
                $included = ( $pkg['images'] ?? 15 ) + self::FREEBIES;

                $idx = 0;
                foreach ( $selections as $sel ) {
                    $idx++;
                    $correct_star = ( $idx <= $included ) ? 1 : 2;
                    if ( intval( $sel->star_rating ) !== $correct_star ) {
                        $wpdb->update( $table, array( 'star_rating' => $correct_star ), array( 'id' => $sel->id ) );
                        $sel->star_rating = $correct_star;
                    }
                }
            }
        }

        // Re-order by star rating for display
        usort( $selections, function( $a, $b ) {
            return intval( $a->star_rating ) <=> intval( $b->star_rating );
        });

        return $selections;
    }

    public static function get_proof_dir( $session_code ) {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/' . self::UPLOAD_SUBDIR . '/' . $session_code;
    }

    public static function get_proof_url( $session_code ) {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/' . self::UPLOAD_SUBDIR . '/' . $session_code;
    }

    public static function get_culling_page_url( $code ) {
        $page_url = get_option( 'tweller_flow_2_culling_page', '' );
        if ( $page_url ) {
            return $page_url . ( strpos( $page_url, '?' ) !== false ? '&' : '?' ) . 'code=' . $code;
        }
        return home_url( '/culling-portal/?code=' . $code );
    }

    public static function get_summary( $session_id ) {
        $enabled   = self::is_culling_enabled( $session_id );
        $ready     = get_option( 'tweller_culling_ready_' . $session_id, false );
        $submitted = get_option( 'tweller_culling_submitted_' . $session_id, false );
        $upsell    = get_option( 'tweller_culling_upsell_' . $session_id, null );

        return array(
            'enabled'      => $enabled,
            'ready'        => $ready,
            'submitted'    => $submitted,
            'proof_count'  => $enabled ? self::count_proofs( $session_id ) : 0,
            'selection_count' => $submitted ? count( self::get_selections( $session_id ) ) : 0,
            'upsell'       => $upsell,
        );
    }

    /**
     * Turn an uploaded proof into a compressed, watermarked preview:
     * downscale to PROOF_MAX_EDGE, re-encode at PROOF_QUALITY, and lay a
     * subtle diagonal "Tweller Studios" watermark across the middle.
     */
    public static function process_proof_image( $path ) {
        // 1) Downscale + recompress
        $editor = wp_get_image_editor( $path );
        if ( ! is_wp_error( $editor ) ) {
            $size = $editor->get_size();
            if ( max( $size['width'], $size['height'] ) > self::PROOF_MAX_EDGE ) {
                if ( $size['width'] >= $size['height'] ) {
                    $editor->resize( self::PROOF_MAX_EDGE, null, false );
                } else {
                    $editor->resize( null, self::PROOF_MAX_EDGE, false );
                }
            }
            $editor->set_quality( self::PROOF_QUALITY );
            $saved = $editor->save( $path );
            if ( is_wp_error( $saved ) ) {
                error_log( '[Tweller Bookings] Proof resize/save failed for ' . $path . ': ' . $saved->get_error_message() );
            }
        }

        // 2) Watermark (GD; skipped silently if GD or the asset is missing)
        if ( ! function_exists( 'imagecreatefrompng' ) ) return;
        $wm_file = TWELLER_FLOW_2_PLUGIN_DIR . 'public/img/watermark.png';
        if ( ! file_exists( $wm_file ) ) return;

        $info = @getimagesize( $path );
        if ( ! $info ) return;

        switch ( $info['mime'] ) {
            case 'image/jpeg': $img = @imagecreatefromjpeg( $path ); break;
            case 'image/png':  $img = @imagecreatefrompng( $path );  break;
            case 'image/webp': $img = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : null; break;
            default: return;
        }
        if ( ! $img ) return;

        $wm = @imagecreatefrompng( $wm_file );
        if ( ! $wm ) { imagedestroy( $img ); return; }

        $img_w = imagesx( $img );
        $img_h = imagesy( $img );
        $wm_w  = imagesx( $wm );
        $wm_h  = imagesy( $wm );

        // Scale the watermark to ~78% of the photo's width, centered
        $target_w = (int) round( $img_w * 0.78 );
        $target_h = (int) round( $wm_h * ( $target_w / $wm_w ) );
        if ( $target_h > $img_h ) {
            $target_h = (int) round( $img_h * 0.9 );
            $target_w = (int) round( $wm_w * ( $target_h / $wm_h ) );
        }
        $dst_x = (int) round( ( $img_w - $target_w ) / 2 );
        $dst_y = (int) round( ( $img_h - $target_h ) / 2 );

        imagealphablending( $img, true );
        imagecopyresampled( $img, $wm, $dst_x, $dst_y, 0, 0, $target_w, $target_h, $wm_w, $wm_h );

        switch ( $info['mime'] ) {
            case 'image/jpeg': imagejpeg( $img, $path, self::PROOF_QUALITY ); break;
            case 'image/png':  imagepng( $img, $path, 8 ); break;
            case 'image/webp': if ( function_exists( 'imagewebp' ) ) imagewebp( $img, $path, self::PROOF_QUALITY ); break;
        }

        imagedestroy( $img );
        imagedestroy( $wm );
    }

    private static function create_thumbnail( $source, $dest, $max_width = 600 ) {
        $editor = wp_get_image_editor( $source );
        if ( is_wp_error( $editor ) ) {
            @copy( $source, $dest );
            return;
        }

        $size = $editor->get_size();
        // Compare against the longest edge, not just width — a portrait
        // photo (width < max_width, height > max_width) was skipping the
        // resize and then still hitting whatever made save() fail below.
        if ( max( $size['width'], $size['height'] ) > $max_width ) {
            if ( $size['width'] >= $size['height'] ) {
                $editor->resize( $max_width, null, false );
            } else {
                $editor->resize( null, $max_width, false );
            }
        }
        $editor->set_quality( 75 );
        $saved = $editor->save( $dest );

        // save() can fail without throwing (bad path, engine quirk with a
        // given source, disk issue) and this went unchecked — the proof
        // grid was then pointing at a thumbnail that never got written,
        // rendering as a broken/blank image even though the full photo
        // (a different file) was perfectly fine. Guarantee the URL never
        // 404s by falling back to the full processed image.
        if ( is_wp_error( $saved ) || ! file_exists( $dest ) || filesize( $dest ) === 0 ) {
            error_log( '[Tweller Bookings] Thumbnail save failed for ' . $source . ( is_wp_error( $saved ) ? ': ' . $saved->get_error_message() : ' (no error, empty output)' ) );
            @copy( $source, $dest );
        }
    }

    // ── Admin: Upload Proof ────────────────────────────

    public static function rest_upload_admin( $request ) {
        $code = sanitize_text_field( $request['code'] );
        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        // Auto-enable culling when admin uploads proofs
        if ( ! self::is_culling_enabled( $session->id ) ) {
            self::enable_culling( $session->id );
        }

        // Fresh round after a wipe: clear the stale "already submitted" state
        if ( self::count_proofs( $session->id ) === 0
             && get_option( 'tweller_culling_submitted_' . $session->id, false ) ) {
            self::reset_culling_round( $session->id );
        }

        $files = $request->get_file_params();
        if ( empty( $files['photo'] ) ) {
            return new WP_Error( 'no_file', 'No photo file provided', array( 'status' => 400 ) );
        }

        $file = $files['photo'];
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return new WP_Error( 'upload_error', 'Upload failed', array( 'status' => 400 ) );
        }

        $allowed = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/webp' );
        $finfo   = finfo_open( FILEINFO_MIME_TYPE );
        $mime    = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );
        if ( ! in_array( $mime, $allowed ) ) {
            return new WP_Error( 'invalid_type', 'Only JPEG, PNG, and WebP allowed', array( 'status' => 400 ) );
        }

        $proof_dir  = self::get_proof_dir( $code );
        $thumbs_dir = $proof_dir . '/thumbs';
        wp_mkdir_p( $proof_dir );
        wp_mkdir_p( $thumbs_dir );

        $filename = sanitize_file_name( $file['name'] );
        $dest     = $proof_dir . '/' . $filename;

        $i = 1;
        while ( file_exists( $dest ) ) {
            $info     = pathinfo( $filename );
            $filename = $info['filename'] . '-' . $i . '.' . $info['extension'];
            $dest     = $proof_dir . '/' . $filename;
            $i++;
        }

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            return new WP_Error( 'move_failed', 'Could not save file', array( 'status' => 500 ) );
        }

        // Same treatment as watcher/LR proofs: compress + watermark
        self::process_proof_image( $dest );

        $thumb_path = $thumbs_dir . '/' . $filename;
        self::create_thumbnail( $dest, $thumb_path, 600 );

        global $wpdb;
        $table     = $wpdb->prefix . self::TABLE_PROOFS;
        $max_order = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(sort_order) FROM $table WHERE session_id = %d", $session->id
        ));

        $wpdb->insert( $table, array(
            'session_id'        => $session->id,
            'session_code'      => $code,
            'filename'          => $filename,
            'original_filename' => $filename,
            'sort_order'        => ( $max_order ?? -1 ) + 1,
            'uploaded_at'       => current_time( 'mysql' ),
        ));

        $proof_url = self::get_proof_url( $code );

        return rest_ensure_response( array(
            'ok'        => true,
            'proof_id'  => $wpdb->insert_id,
            'filename'  => $filename,
            'thumb_url' => $proof_url . '/thumbs/' . $filename,
            'url'       => $proof_url . '/' . $filename,
        ));
    }

    // ── Admin: Get Proof List ──────────────────────────

    public static function rest_get_admin_proofs( $request ) {
        $code    = sanitize_text_field( $request['code'] );
        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $proofs    = self::get_proofs( $session->id );
        $proof_url = self::get_proof_url( $code );
        $list      = array();

        foreach ( $proofs as $p ) {
            $list[] = array(
                'id'        => $p->id,
                'filename'  => $p->filename,
                'thumb_url' => $proof_url . '/thumbs/' . $p->filename,
                'url'       => $proof_url . '/' . $p->filename,
            );
        }

        $packages  = get_option( 'tweller_flow_2_packages', array() );
        $pkg       = $packages[ $session->package_type ] ?? array();
        $included  = ( $pkg['images'] ?? 15 ) + self::FREEBIES;

        return rest_ensure_response( array(
            'ok'             => true,
            'proofs'         => $list,
            'proof_count'    => count( $list ),
            'culling_enabled'=> self::is_culling_enabled( $session->id ),
            'ready'          => (bool) get_option( 'tweller_culling_ready_' . $session->id, false ),
            'submitted'      => (bool) get_option( 'tweller_culling_submitted_' . $session->id, false ),
            'included_images'=> $included,
            'price_per_photo'=> self::UPSELL_PRICE_PER_PHOTO,
        ));
    }

    // ── Admin: Get Proofs with Selection Status ────────

    public static function rest_get_proofs_with_status( $request ) {
        $code    = sanitize_text_field( $request['code'] );
        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $proofs     = self::get_proofs( $session->id );
        $selections = self::get_selections( $session->id );
        $proof_url  = self::get_proof_url( $code );

        // Build map of selected proof IDs with their star ratings
        $selected_map = array();
        $idx = 0;
        foreach ( $selections as $sel ) {
            $idx++;
            $selected_map[ $sel->proof_id ] = intval( $sel->star_rating );
        }

        $packages = get_option( 'tweller_flow_2_packages', array() );
        $pkg      = $packages[ $session->package_type ] ?? array();
        $included = ( $pkg['images'] ?? 15 ) + self::FREEBIES;

        $list = array();
        foreach ( $proofs as $p ) {
            $is_selected = isset( $selected_map[ $p->id ] );
            $star_rating = $selected_map[ $p->id ] ?? null;

            $list[] = array(
                'id'            => $p->id,
                'filename'      => $p->filename,
                'thumb_url'     => $proof_url . '/thumbs/' . $p->filename,
                'url'           => $proof_url . '/' . $p->filename,
                'selected'      => $is_selected,
                'star_rating'   => $star_rating,
                'is_extra'      => $is_selected && $star_rating === 2,
            );
        }

        return rest_ensure_response( array(
            'ok'              => true,
            'proofs'          => $list,
            'total_proofs'    => count( $list ),
            'total_selected'  => count( $selections ),
            'included'        => $included,
            'price_per_photo' => self::UPSELL_PRICE_PER_PHOTO,
            'submitted'       => (bool) get_option( 'tweller_culling_submitted_' . $session->id, false ),
        ));
    }

    // ── Admin: Delete Proof ────────────────────────────

    public static function rest_delete_proof_admin( $request ) {
        $proof_id = intval( $request['proof_id'] );
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_PROOFS;
        $proof = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $proof_id ) );

        if ( ! $proof ) {
            return new WP_Error( 'not_found', 'Proof not found', array( 'status' => 404 ) );
        }

        // Delete files
        $proof_dir = self::get_proof_dir( $proof->session_code );
        $file_path = $proof_dir . '/' . $proof->filename;
        $thumb     = $proof_dir . '/thumbs/' . $proof->filename;
        if ( file_exists( $file_path ) ) unlink( $file_path );
        if ( file_exists( $thumb ) ) unlink( $thumb );

        $wpdb->delete( $table, array( 'id' => $proof_id ) );

        // Last proof gone -> the round is over; reset so a re-upload starts fresh
        if ( self::count_proofs( $proof->session_id ) === 0 ) {
            self::reset_culling_round( $proof->session_id );
        }

        return rest_ensure_response( array( 'ok' => true ) );
    }

    // ── Admin: Delete ALL Proofs ───────────────────────

    /**
     * Wipe every proof for a session (files + thumbs + DB rows) and reset
     * the culling round: selections, submitted/ready flags, and upsell info
     * are cleared so a fresh set of proofs can be uploaded.
     */
    public static function rest_delete_all_proofs( $request ) {
        $code    = sanitize_text_field( $request['code'] );
        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        global $wpdb;
        $proof_table = $wpdb->prefix . self::TABLE_PROOFS;
        $sel_table   = $wpdb->prefix . self::TABLE_SELECTIONS;

        $proofs  = self::get_proofs( $session->id );
        $deleted = 0;

        $proof_dir = self::get_proof_dir( $code );
        foreach ( $proofs as $proof ) {
            $file_path = $proof_dir . '/' . $proof->filename;
            $thumb     = $proof_dir . '/thumbs/' . $proof->filename;
            if ( file_exists( $file_path ) ) @unlink( $file_path );
            if ( file_exists( $thumb ) ) @unlink( $thumb );
            $deleted++;
        }

        $wpdb->delete( $proof_table, array( 'session_id' => $session->id ) );
        $wpdb->delete( $sel_table, array( 'session_id' => $session->id ) );

        delete_option( 'tweller_culling_submitted_' . $session->id );
        delete_option( 'tweller_culling_ready_' . $session->id );
        delete_option( 'tweller_culling_upsell_' . $session->id );

        return rest_ensure_response( array( 'ok' => true, 'deleted' => $deleted ) );
    }

    // ── Admin: Mark Ready ──────────────────────────────

    public static function rest_mark_ready_admin( $request ) {
        $code    = sanitize_text_field( $request['code'] );
        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $password = sanitize_text_field( $request->get_param( 'password' ) );
        if ( $password ) {
            self::set_password( $session->id, $password );
        }

        if ( ! self::is_culling_enabled( $session->id ) ) {
            self::enable_culling( $session->id );
        }

        update_option( 'tweller_culling_ready_' . $session->id, current_time( 'mysql' ) );

        $culling_url = self::get_culling_page_url( $code );
        update_option( 'tweller_culling_url_' . $session->id, $culling_url );

        // Send client email
        self::send_culling_email( $session );

        return rest_ensure_response( array(
            'ok'          => true,
            'proof_count' => self::count_proofs( $session->id ),
            'culling_url' => $culling_url,
        ));
    }

    // ── Admin: Toggle Culling ──────────────────────────

    public static function rest_toggle_culling( $request ) {
        $code    = sanitize_text_field( $request['code'] );
        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $enable = (bool) $request->get_param( 'enabled' );
        if ( $enable ) {
            self::enable_culling( $session->id );
        } else {
            self::disable_culling( $session->id );
        }

        return rest_ensure_response( array( 'ok' => true, 'enabled' => $enable ) );
    }

    // ── Admin: Download Selections as CSV ─────────────

    public static function rest_download_selections( $request ) {
        $code    = sanitize_text_field( $request['code'] );
        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $selections = self::get_selections( $session->id );
        if ( empty( $selections ) ) {
            return new WP_Error( 'no_selections', 'No selections found', array( 'status' => 404 ) );
        }

        $packages = get_option( 'tweller_flow_2_packages', array() );
        $pkg      = $packages[ $session->package_type ] ?? array();
        $included = ( $pkg['images'] ?? 15 ) + self::FREEBIES;

        // Build CSV
        $lines   = array();
        $lines[] = 'Filename,Star Rating,Selection Order,Type';
        $idx     = 0;
        foreach ( $selections as $sel ) {
            $idx++;
            $star   = isset( $sel->star_rating ) ? (int) $sel->star_rating : ( $idx <= $included ? 1 : 2 );
            $type   = $star === 1 ? 'Included' : 'Extra (+$' . self::UPSELL_PRICE_PER_PHOTO . ' TTD)';
            $lines[] = '"' . str_replace( '"', '""', $sel->filename ) . '",' . $star . ',' . $idx . ',"' . $type . '"';
        }

        $csv = implode( "\n", $lines );
        $filename = 'selections-' . $code . '.csv';

        // Return as a data response the admin JS can download
        return rest_ensure_response( array(
            'ok'       => true,
            'filename' => $filename,
            'csv'      => $csv,
            'count'    => count( $selections ),
        ));
    }

    // ── Admin: Download XMP Metadata (for Lightroom) ────

    public static function rest_download_xmp( $request ) {
        $code    = sanitize_text_field( $request['code'] );
        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $selections = self::get_selections( $session->id );
        if ( empty( $selections ) ) {
            return new WP_Error( 'no_selections', 'No selections found', array( 'status' => 404 ) );
        }

        $packages = get_option( 'tweller_flow_2_packages', array() );
        $pkg      = $packages[ $session->package_type ] ?? array();
        $included = ( $pkg['images'] ?? 15 ) + self::FREEBIES;

        // Create temporary zip file
        $temp_zip = tempnam( sys_get_temp_dir(), 'xmp_' );
        $zip      = new ZipArchive();
        $zip->open( $temp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );

        $idx = 0;
        foreach ( $selections as $sel ) {
            $idx++;
            $star = isset( $sel->star_rating ) ? (int) $sel->star_rating : ( $idx <= $included ? 1 : 2 );

            // Generate XMP sidecar content
            $xmp_content = self::generate_xmp( $sel->filename, $star );

            // Add to zip with .xmp extension
            $xmp_filename = pathinfo( $sel->filename, PATHINFO_FILENAME ) . '.xmp';
            $zip->addFromString( $xmp_filename, $xmp_content );
        }

        $zip->close();

        // Read zip and return as data response
        if ( ! file_exists( $temp_zip ) ) {
            return new WP_Error( 'zip_failed', 'Could not create zip file', array( 'status' => 500 ) );
        }

        $zip_data = file_get_contents( $temp_zip );
        @unlink( $temp_zip );

        return rest_ensure_response( array(
            'ok'           => true,
            'filename'     => 'selections-' . $code . '.zip',
            'zip_base64'   => base64_encode( $zip_data ),
            'count'        => count( $selections ),
            'instruction'  => 'Extract the .xmp files into the same folder as your RAW files, then in Lightroom select those photos and run Metadata > Read Metadata from Files.',
        ));
    }

    private static function generate_xmp( $filename, $star_rating ) {
        // Lightroom sidecar: star rating + a standard colour label so LR
        // shows real label colours (Blue = included, Purple = extra).
        // The xpacket wrapper + padding is required for Adobe apps to
        // reliably recognize the file as a valid XMP sidecar.
        $label = ( intval( $star_rating ) === 1 ) ? 'Blue' : 'Purple';

        $xmp = '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>' . "\n" .
            '<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="Tweller Bookings WP">' . "\n" .
            ' <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">' . "\n" .
            '  <rdf:Description rdf:about=""' . "\n" .
            '    xmlns:xmp="http://ns.adobe.com/xap/1.0/"' . "\n" .
            '    xmlns:lr="http://ns.adobe.com/lightroom/1.0/"' . "\n" .
            '   xmp:Rating="' . intval( $star_rating ) . '"' . "\n" .
            '   xmp:Label="' . esc_xml( $label ) . '"/>' . "\n" .
            ' </rdf:RDF>' . "\n" .
            '</x:xmpmeta>' . "\n" .
            str_repeat( ' ', 200 ) . "\n" .
            '<?xpacket end="w"?>';

        return $xmp;
    }

    // ── Selection Confirmation Email ───────────────────

    public static function send_selection_email( $session, $total_selected, $included, $extra_count, $extra_cost_ttd ) {
        if ( empty( $session->client_email ) ) return;

        $banking    = get_option( 'tweller_flow_2_banking', '' );
        $wipay_url  = get_option( 'tweller_flow_2_wipay_url', '' );
        $packages   = get_option( 'tweller_flow_2_packages', array() );
        $pkg        = $packages[ $session->package_type ] ?? array();
        $pkg_name   = $pkg['name'] ?? ucfirst( $session->package_type );
        $tracker_url = TwellerFlow2_Notifications::get_tracker_url( $session->tracking_code );

        $subject = "We've Received Your Photo Selections! 📸";

        $extra_section = '';
        if ( $extra_count > 0 ) {
            $payment_html = '';
            if ( $banking ) {
                $banking_escaped = nl2br( esc_html( $banking ) );
                $payment_html .= "
                    <div style='margin-bottom:16px;'>
                        <strong style='display:block; color:#374151; margin-bottom:6px;'>Bank Transfer</strong>
                        <div style='background:#F9FAFB; padding:14px 16px; border-radius:8px; border:1px solid #E5E7EB; font-size:14px; color:#374151; line-height:1.8;'>
                            {$banking_escaped}
                        </div>
                    </div>";
            }
            if ( $wipay_url ) {
                // Brand gold button via the shared helper — this was a
                // hard-coded blue anchor that ignored the email design system.
                $payment_html .= "
                    <div style='margin-bottom:16px;'>
                        <strong style='display:block; color:#374151; margin-bottom:6px;'>Pay Online (Credit / Debit Card)</strong>"
                        . TwellerFlow2_Notifications::email_button( $wipay_url, 'Pay via WiPay' ) . "
                    </div>";
            }

            $extra_section = "
                <div style='background:#FEF9EC; border:1px solid #FCD34D; border-radius:10px; padding:20px 24px; margin:24px 0;'>
                    <h3 style='margin:0 0 12px; color:#92400E; font-size:16px;'>Additional Photos Cost</h3>
                    <table style='width:100%; border-collapse:collapse; font-size:14px; color:#374151;'>
                        <tr>
                            <td style='padding:6px 0;'>Photos in your package</td>
                            <td style='padding:6px 0; text-align:right; font-weight:600;'>{$included}</td>
                        </tr>
                        <tr>
                            <td style='padding:6px 0;'>Photos you selected</td>
                            <td style='padding:6px 0; text-align:right; font-weight:600;'>{$total_selected}</td>
                        </tr>
                        <tr>
                            <td style='padding:6px 0;'>Extra photos</td>
                            <td style='padding:6px 0; text-align:right; color:#D97706; font-weight:600;'>{$extra_count} × \$" . self::UPSELL_PRICE_PER_PHOTO . " TTD</td>
                        </tr>
                        <tr style='border-top:2px solid #FCD34D;'>
                            <td style='padding:10px 0 0; font-size:16px; font-weight:700; color:#92400E;'>Additional Amount Due</td>
                            <td style='padding:10px 0 0; text-align:right; font-size:18px; font-weight:700; color:#92400E;'>\${$extra_cost_ttd} TTD</td>
                        </tr>
                    </table>
                </div>
                <div style='margin:20px 0;'>
                    <h3 style='font-size:16px; color:#111827; margin:0 0 14px;'>How to Pay</h3>
                    {$payment_html}
                    <p style='font-size:13px; color:#6B7280; margin-top:8px;'>Please use your shoot code <strong>{$session->tracking_code}</strong> as a reference when paying.</p>
                </div>";
        }

        $next_steps = "<div style='background:#F8FAFC; border-radius:10px; padding:20px 24px; margin:24px 0;'>
                <h3 style='font-size:15px; color:#374151; margin:0 0 10px;'>What Happens Next?</h3>
                <ol style='margin:0; padding-left:20px; font-size:14px; color:#374151; line-height:2;'>
                    <li>Our team reviews your selections</li>
                    <li>We begin retouching your photos with care</li>
                    <li>Your gallery will be ready for download</li>
                    <li>You'll receive an email the moment it's live</li>
                </ol>
            </div>";
        $track_button = TwellerFlow2_Notifications::email_button_row( $tracker_url, 'Track My Session' );

        $body = "
            <h2 style='color:#111827; font-size:22px; margin:0 0 8px;'>Thank you, {$session->client_name}! 🎉</h2>
            <p style='color:#6B7280; font-size:15px; margin:0 0 24px;'>We've received your photo selections and we're excited to start editing!</p>

            <div style='background:#F0FDF4; border:1px solid #BBF7D0; border-radius:10px; padding:20px 24px; margin-bottom:24px;'>
                <div style='display:flex; align-items:center; margin-bottom:12px;'>
                    <span style='font-size:28px; margin-right:12px;'>📸</span>
                    <div>
                        <div style='font-size:18px; font-weight:700; color:#15803D;'>{$total_selected} Photos Selected</div>
                        <div style='font-size:13px; color:#16A34A;'>{$pkg_name} package &mdash; {$included} photos included</div>
                    </div>
                </div>
                <p style='margin:0; font-size:14px; color:#166534;'>Our editing team will get started on your photos right away. We can't wait for you to see them!</p>
            </div>

            {$extra_section}

            {$next_steps}

            <p style='font-size:14px; color:#374151;'>You can track your session progress anytime:</p>
            {$track_button}

            <p style='font-size:13px; color:#9CA3AF; text-align:center; margin-top:24px;'>Questions? Reply to this email — we're happy to help! 😊</p>
        ";

        list( $subject, $body ) = TwellerFlow2_Email_Templates::resolve( 'culling_selection', $subject, $body, array(
            'client_name'        => esc_html( $session->client_name ),
            'total_selected'     => (int) $total_selected,
            'package'            => esc_html( $pkg_name ),
            'included'           => (int) $included,
            'extra_cost_section' => $extra_section,
            'next_steps'         => $next_steps,
            'track_button'       => $track_button,
        ) );

        TwellerFlow2_Notifications::send_email( $session, $subject, $body );
    }
}
