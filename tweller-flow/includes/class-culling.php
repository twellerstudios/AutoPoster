<?php
/**
 * Tweller Flow — Client Culling Portal
 *
 * Allows clients to view proof images and select which ones to retouch.
 * Handles proof uploads, selections, package limits, and upsell pricing.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow_Culling {

    const TABLE_PROOFS     = 'tweller_culling_proofs';
    const TABLE_SELECTIONS = 'tweller_culling_selections';
    const UPLOAD_SUBDIR    = 'tweller-culling';

    /** Upsell pricing tiers */
    const UPSELL_TIERS = array(
        5  => 100,  // +5 photos = $100
        10 => 150,  // +10 photos = $150
        15 => 200,  // +15 photos = $200
        0  => 250,  // all remaining = $250
    );

    const FREEBIES = 5;

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
        add_shortcode( 'tweller_culling', array( __CLASS__, 'render_shortcode' ) );
    }

    // ── REST Routes ─────────────────────────────────────

    public static function register_rest_routes() {
        $ns = 'tweller-flow/v1';

        // Upload proof photo (from watcher, auth required)
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9]+)/upload', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_upload_proof' ),
            'permission_callback' => array( 'TwellerFlow_Photo_Automation', 'verify_api_key' ),
        ));

        // Get proofs for client (public, password-protected)
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9]+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_proofs' ),
            'permission_callback' => '__return_true',
        ));

        // Submit selections (public)
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9]+)/select', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_submit_selections' ),
            'permission_callback' => '__return_true',
        ));

        // Get selections (watcher pulls these)
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9]+)/selections', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_selections' ),
            'permission_callback' => array( 'TwellerFlow_Photo_Automation', 'verify_api_key' ),
        ));

        // Verify culling password
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9]+)/verify', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_verify_password' ),
            'permission_callback' => '__return_true',
        ));

        // Batch upload status / mark culling ready (from watcher)
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9]+)/ready', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_mark_ready' ),
            'permission_callback' => array( 'TwellerFlow_Photo_Automation', 'verify_api_key' ),
        ));

        // Get existing proof filenames (dedup for watcher)
        register_rest_route( $ns, '/culling/(?P<code>[a-zA-Z0-9]+)/filenames', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_filenames' ),
            'permission_callback' => array( 'TwellerFlow_Photo_Automation', 'verify_api_key' ),
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
            session_code varchar(10) NOT NULL,
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
            session_code varchar(10) NOT NULL,
            proof_id bigint(20) unsigned NOT NULL,
            filename varchar(255) NOT NULL,
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

        $session = TwellerFlow_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        if ( ! self::is_culling_enabled( $session->id ) ) {
            return new WP_Error( 'not_enabled', 'Culling not enabled for this session', array( 'status' => 400 ) );
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

        // Generate thumbnail (600px for proof grid)
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

        $session = TwellerFlow_Session::get_by_code( $code );
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
            $is_unlocked = TwellerFlow_Gallery::verify_token( $session->id, $token );
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
        $packages = get_option( 'tweller_flow_packages', array() );
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
            'ok'              => true,
            'culling_enabled' => true,
            'ready'           => (bool) $is_ready,
            'has_password'    => $has_password,
            'unlocked'        => true,
            'submitted'       => (bool) $submitted,
            'client_name'     => $session->client_name,
            'session_date'    => $session->session_date,
            'package_name'    => $pkg['name'] ?? ucfirst( $session->package_type ),
            'included_images' => $included_images,
            'freebies'        => self::FREEBIES,
            'max_free'        => $included_images + self::FREEBIES,
            'upsell_tiers'    => self::UPSELL_TIERS,
            'proofs'          => $proof_list,
            'proof_count'     => count( $proof_list ),
            'selected_ids'    => $selected_ids,
        ));
    }

    // ── Submit Selections ──────────────────────────────

    public static function rest_submit_selections( $request ) {
        $code      = sanitize_text_field( $request['code'] );
        $token     = sanitize_text_field( $request->get_param( 'token' ) );
        $proof_ids = $request->get_param( 'proof_ids' );
        $upsell    = sanitize_text_field( $request->get_param( 'upsell_tier' ) );

        $session = TwellerFlow_Session::get_by_code( $code );
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

        // Validate selection count against package
        $packages = get_option( 'tweller_flow_packages', array() );
        $pkg = $packages[ $session->package_type ] ?? array();
        $included = ( $pkg['images'] ?? 15 ) + self::FREEBIES;
        $max_allowed = $included;

        // If upsell, allow more
        if ( $upsell ) {
            $upsell_amount = intval( $upsell );
            if ( $upsell_amount === 0 ) {
                // "All" tier — no limit
                $max_allowed = PHP_INT_MAX;
            } else {
                $max_allowed = $included + $upsell_amount;
            }
        }

        if ( count( $proof_ids ) > $max_allowed ) {
            return new WP_Error( 'too_many', 'Selected more than allowed (' . $max_allowed . ')', array( 'status' => 400 ) );
        }

        // Save selections
        global $wpdb;
        $sel_table   = $wpdb->prefix . self::TABLE_SELECTIONS;
        $proof_table = $wpdb->prefix . self::TABLE_PROOFS;

        // Clear old selections
        $wpdb->delete( $sel_table, array( 'session_id' => $session->id ) );

        foreach ( $proof_ids as $pid ) {
            $pid = intval( $pid );
            $proof = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $proof_table WHERE id = %d AND session_id = %d", $pid, $session->id
            ));
            if ( ! $proof ) continue;

            $wpdb->insert( $sel_table, array(
                'session_id'   => $session->id,
                'session_code' => $code,
                'proof_id'     => $pid,
                'filename'     => $proof->original_filename,
                'selected_at'  => current_time( 'mysql' ),
            ));
        }

        // Mark as submitted
        update_option( 'tweller_culling_submitted_' . $session->id, current_time( 'mysql' ) );

        // Store upsell info
        if ( $upsell ) {
            $upsell_amount = intval( $upsell );
            $upsell_price = self::UPSELL_TIERS[ $upsell_amount ] ?? 0;
            update_option( 'tweller_culling_upsell_' . $session->id, array(
                'tier'   => $upsell_amount,
                'price'  => $upsell_price,
                'count'  => count( $proof_ids ),
            ));
        }

        // Track activity
        if ( class_exists( 'TwellerFlow_Client_Activity' ) ) {
            TwellerFlow_Client_Activity::log(
                $session->id, $code, 'culling_submitted',
                count( $proof_ids ) . ' photos selected' . ( $upsell ? ' (upsell: +' . $upsell . ')' : '' )
            );
        }

        return rest_ensure_response( array(
            'ok'       => true,
            'selected' => count( $proof_ids ),
        ));
    }

    // ── Get Selections (Watcher) ───────────────────────

    public static function rest_get_selections( $request ) {
        $code = sanitize_text_field( $request['code'] );
        $session = TwellerFlow_Session::get_by_code( $code );
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

        $session = TwellerFlow_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Not found', array( 'status' => 404 ) );
        }

        $password_hash = get_option( 'tweller_culling_pw_' . $session->id, '' );
        if ( empty( $password_hash ) ) {
            $token = TwellerFlow_Gallery::generate_token( $session->id );
            return rest_ensure_response( array( 'ok' => true, 'token' => $token ) );
        }

        if ( ! wp_check_password( $password, $password_hash ) ) {
            return new WP_Error( 'wrong_password', 'Incorrect password', array( 'status' => 403 ) );
        }

        $token = TwellerFlow_Gallery::generate_token( $session->id );
        return rest_ensure_response( array( 'ok' => true, 'token' => $token ) );
    }

    // ── Mark Ready (Watcher) ───────────────────────────

    public static function rest_mark_ready( $request ) {
        $code = sanitize_text_field( $request['code'] );
        $session = TwellerFlow_Session::get_by_code( $code );
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
        $session = TwellerFlow_Session::get_by_code( $code );
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
        wp_enqueue_style( 'tweller-flow-culling' );
        wp_enqueue_script( 'tweller-flow-culling' );

        wp_localize_script( 'tweller-flow-culling', 'twellerCulling', array(
            'apiUrl' => rest_url( 'tweller-flow/v1/culling/' ),
            'nonce'  => wp_create_nonce( 'wp_rest' ),
        ));

        $code = isset( $_GET['code'] ) ? sanitize_text_field( $_GET['code'] ) : '';

        ob_start();

        if ( empty( $code ) ) {
            echo '<div class="tc-portal tc-portal--empty"><p>No session code provided.</p></div>';
        } else {
            $session = TwellerFlow_Session::get_by_code( $code );
            if ( ! $session ) {
                echo '<div class="tc-portal tc-portal--error"><p>Session not found.</p></div>';
            } else {
                self::render_portal( $session );
            }
        }

        return ob_get_clean();
    }

    private static function render_portal( $session ) {
        $packages = get_option( 'tweller_flow_packages', array() );
        $pkg = $packages[ $session->package_type ] ?? array();
        $pkg_name = $pkg['name'] ?? ucfirst( $session->package_type );
        $included = $pkg['images'] ?? 15;
        ?>
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
                <p>Track your session progress: <a href="<?php echo esc_url( TwellerFlow_Notifications::get_tracker_url( $session->tracking_code ) ); ?>">View Tracker</a></p>
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

            <!-- Upsell modal -->
            <div class="tc-modal" id="tc-upsell-modal" style="display:none;">
                <div class="tc-modal__backdrop"></div>
                <div class="tc-modal__content">
                    <h3>Want More Photos?</h3>
                    <p>Your package includes <strong id="tc-upsell-included">0</strong> photos (+ <?php echo self::FREEBIES; ?> free). You've selected <strong id="tc-upsell-selected">0</strong>.</p>
                    <p>Add more retouched photos:</p>
                    <div class="tc-upsell__tiers" id="tc-upsell-tiers"></div>
                    <div class="tc-modal__actions">
                        <button class="tc-btn tc-btn--secondary" id="tc-upsell-cancel">Go Back &amp; Deselect</button>
                    </div>
                </div>
            </div>

            <!-- Confirm modal -->
            <div class="tc-modal" id="tc-confirm-modal" style="display:none;">
                <div class="tc-modal__backdrop"></div>
                <div class="tc-modal__content">
                    <h3>Confirm Your Selections</h3>
                    <p>You've selected <strong id="tc-confirm-count">0</strong> photos for retouching.</p>
                    <p id="tc-confirm-upsell" style="display:none;"></p>
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
        $packages = get_option( 'tweller_flow_packages', array() );
        $pkg = $packages[ $session->package_type ] ?? array();
        $pkg_name = $pkg['name'] ?? ucfirst( $session->package_type );
        $included = $pkg['images'] ?? 15;

        $subject = "Choose Your Photos — {$pkg_name} with Tweller Studios";
        $body = "
            <h2>Your Proofs Are Ready!</h2>
            <p>Hi {$session->client_name},</p>
            <p>Your photo proofs are ready for viewing! Browse through them and select the ones you'd like us to retouch.</p>

            <div style='background:#f0f9ff; padding:20px; border-radius:8px; margin:20px 0; border:1px solid #bae6fd;'>
                <h3 style='margin-top:0; color:#0369a1;'>Your Package</h3>
                <p><strong>{$pkg_name}:</strong> {$included} retouched photos included + " . self::FREEBIES . " bonus free</p>
                <p>Select up to <strong>" . ( $included + self::FREEBIES ) . "</strong> photos at no extra cost.</p>
                <p><em>Want more? You can add extra photos during selection.</em></p>
            </div>

            <div style='text-align:center; margin:30px 0;'>
                <a href='{$culling_url}' style='display:inline-block; background:#6366F1; color:#fff; padding:16px 36px; border-radius:10px; text-decoration:none; font-size:18px; font-weight:600;'>Choose My Photos</a>
            </div>

            <div style='background:#fefce8; padding:16px 20px; border-radius:8px; margin:20px 0; border:1px solid #fde68a;'>
                <p style='margin:0; font-size:14px; color:#854d0e;'><strong>Important:</strong> Once you submit your selections, they cannot be changed. Take your time browsing!</p>
            </div>
        ";

        TwellerFlow_Notifications::send_email( $session, $subject, $body );
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
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE session_id = %d ORDER BY selected_at ASC", $session_id
        ));
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
        $page_url = get_option( 'tweller_flow_culling_page', '' );
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

    private static function create_thumbnail( $source, $dest, $max_width = 600 ) {
        $editor = wp_get_image_editor( $source );
        if ( is_wp_error( $editor ) ) {
            copy( $source, $dest );
            return;
        }
        $size = $editor->get_size();
        if ( $size['width'] > $max_width ) {
            $editor->resize( $max_width, null, false );
        }
        $editor->set_quality( 75 );
        $editor->save( $dest );
    }
}
