<?php
/**
 * Tweller Flow Gallery — Photo upload, storage, and client gallery
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow_Gallery {

    /** Gallery uploads directory under wp-content/uploads/ */
    const UPLOAD_SUBDIR = 'tweller-gallery';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    // ── REST Routes ─────────────────────────────────────

    public static function register_rest_routes() {
        // Upload photo (from LR plugin or manual)
        register_rest_route( 'tweller-flow/v1', '/gallery/upload', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_upload_photo' ),
            'permission_callback' => array( 'TwellerFlow_Photo_Automation', 'verify_api_key' ),
        ));

        // Get gallery photos (public — used by client tracker)
        register_rest_route( 'tweller-flow/v1', '/gallery/(?P<code>[a-zA-Z0-9]+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_gallery' ),
            'permission_callback' => '__return_true',
        ));

        // Verify gallery password
        register_rest_route( 'tweller-flow/v1', '/gallery/(?P<code>[a-zA-Z0-9]+)/verify', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_verify_password' ),
            'permission_callback' => '__return_true',
        ));

        // Download single photo (proxied for password-protected galleries)
        register_rest_route( 'tweller-flow/v1', '/gallery/(?P<code>[a-zA-Z0-9]+)/download', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_download_photo' ),
            'permission_callback' => '__return_true',
        ));

        // Download all photos as zip
        register_rest_route( 'tweller-flow/v1', '/gallery/(?P<code>[a-zA-Z0-9]+)/download-all', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_download_all' ),
            'permission_callback' => '__return_true',
        ));

        // Admin: delete gallery photo
        register_rest_route( 'tweller-flow/v1', '/gallery/(?P<code>[a-zA-Z0-9]+)/photo/(?P<photo_id>\d+)', array(
            'methods'             => 'DELETE',
            'callback'            => array( __CLASS__, 'rest_delete_photo' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));

        // Admin: set gallery password
        register_rest_route( 'tweller-flow/v1', '/gallery/(?P<code>[a-zA-Z0-9]+)/password', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_set_password' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));
    }

    // ── Upload ─────────────────────────────────────────

    public static function rest_upload_photo( $request ) {
        $session_code = sanitize_text_field( $request->get_param( 'session_code' ) );
        $password     = sanitize_text_field( $request->get_param( 'gallery_password' ) );

        if ( empty( $session_code ) ) {
            return new WP_Error( 'missing_code', 'session_code is required', array( 'status' => 400 ) );
        }

        $session = TwellerFlow_Session::get_by_code( $session_code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        // Set gallery password if provided
        if ( ! empty( $password ) ) {
            self::set_password( $session->id, $password );
        }

        // Handle file upload
        $files = $request->get_file_params();
        if ( empty( $files['photo'] ) ) {
            return new WP_Error( 'no_file', 'No photo file provided', array( 'status' => 400 ) );
        }

        $file = $files['photo'];
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return new WP_Error( 'upload_error', 'Upload failed with error code ' . $file['error'], array( 'status' => 400 ) );
        }

        // Validate file type
        $allowed = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/webp' );
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );

        if ( ! in_array( $mime, $allowed ) ) {
            return new WP_Error( 'invalid_type', 'Only JPEG, PNG, and WebP images are allowed', array( 'status' => 400 ) );
        }

        // Create gallery directory
        $gallery_dir = self::get_gallery_dir( $session_code );
        $thumbs_dir  = $gallery_dir . '/thumbs';
        wp_mkdir_p( $gallery_dir );
        wp_mkdir_p( $thumbs_dir );

        // Sanitize filename
        $filename = sanitize_file_name( $file['name'] );
        $dest     = $gallery_dir . '/' . $filename;

        // Avoid overwrites
        $i = 1;
        while ( file_exists( $dest ) ) {
            $info = pathinfo( $filename );
            $dest = $gallery_dir . '/' . $info['filename'] . '-' . $i . '.' . $info['extension'];
            $filename = $info['filename'] . '-' . $i . '.' . $info['extension'];
            $i++;
        }

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            return new WP_Error( 'move_failed', 'Could not save uploaded file', array( 'status' => 500 ) );
        }

        // Generate thumbnail (800px wide for gallery grid)
        $thumb_path = $thumbs_dir . '/' . $filename;
        self::create_thumbnail( $dest, $thumb_path, 800 );

        // Store in database
        $photo_id = self::insert_photo( $session->id, $session_code, $filename );

        // Update gallery URL on the session
        $tracker_url = get_option( 'tweller_flow_tracker_page', '' );
        if ( $tracker_url ) {
            $gallery_url = $tracker_url . ( strpos( $tracker_url, '?' ) !== false ? '&' : '?' ) . 'code=' . $session_code . '#gallery';
            TwellerFlow_Session::update( $session->id, array( 'gallery_url' => $gallery_url ) );
        }

        return rest_ensure_response( array(
            'ok'       => true,
            'photo_id' => $photo_id,
            'filename' => $filename,
        ));
    }

    // ── Get Gallery ────────────────────────────────────

    public static function rest_get_gallery( $request ) {
        $code     = sanitize_text_field( $request['code'] );
        $token    = sanitize_text_field( $request->get_param( 'token' ) );

        $session = TwellerFlow_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Gallery not found', array( 'status' => 404 ) );
        }

        // Check if gallery has password
        $password_hash = get_post_meta( $session->id, '_tweller_gallery_password', true );
        // Use option fallback for non-post storage
        if ( ! $password_hash ) {
            $password_hash = get_option( 'tweller_gallery_pw_' . $session->id, '' );
        }

        $has_password  = ! empty( $password_hash );
        $is_unlocked   = false;

        if ( $has_password && $token ) {
            $is_unlocked = self::verify_token( $session->id, $token );
        } elseif ( ! $has_password ) {
            $is_unlocked = true;
        }

        // Only show in delivered stage
        if ( $session->current_stage !== 'delivered' ) {
            return rest_ensure_response( array(
                'ok'            => true,
                'ready'         => false,
                'has_password'  => $has_password,
                'photos'        => array(),
                'client_name'   => $session->client_name,
            ));
        }

        if ( ! $is_unlocked ) {
            return rest_ensure_response( array(
                'ok'           => true,
                'ready'        => true,
                'has_password' => true,
                'unlocked'     => false,
                'photo_count'  => self::count_photos( $session->id ),
                'client_name'  => $session->client_name,
            ));
        }

        $photos = self::get_photos( $session->id );
        $gallery_url = self::get_gallery_url( $code );

        $photo_list = array();
        foreach ( $photos as $photo ) {
            $photo_list[] = array(
                'id'        => $photo->id,
                'filename'  => $photo->filename,
                'url'       => $gallery_url . '/' . $photo->filename,
                'thumb_url' => $gallery_url . '/thumbs/' . $photo->filename,
            );
        }

        return rest_ensure_response( array(
            'ok'           => true,
            'ready'        => true,
            'has_password' => $has_password,
            'unlocked'     => true,
            'client_name'  => $session->client_name,
            'photos'       => $photo_list,
            'photo_count'  => count( $photo_list ),
        ));
    }

    // ── Verify Password ────────────────────────────────

    public static function rest_verify_password( $request ) {
        $code     = sanitize_text_field( $request['code'] );
        $password = sanitize_text_field( $request->get_param( 'password' ) );

        $session = TwellerFlow_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Gallery not found', array( 'status' => 404 ) );
        }

        $password_hash = get_option( 'tweller_gallery_pw_' . $session->id, '' );

        if ( empty( $password_hash ) ) {
            // No password set — auto-unlock
            $token = self::generate_token( $session->id );
            return rest_ensure_response( array( 'ok' => true, 'token' => $token ) );
        }

        if ( ! wp_check_password( $password, $password_hash ) ) {
            return new WP_Error( 'wrong_password', 'Incorrect password', array( 'status' => 403 ) );
        }

        $token = self::generate_token( $session->id );
        return rest_ensure_response( array( 'ok' => true, 'token' => $token ) );
    }

    // ── Download ───────────────────────────────────────

    public static function rest_download_photo( $request ) {
        $code     = sanitize_text_field( $request['code'] );
        $filename = sanitize_file_name( $request->get_param( 'file' ) );

        $session = TwellerFlow_Session::get_by_code( $code );
        if ( ! $session || $session->current_stage !== 'delivered' ) {
            return new WP_Error( 'not_found', 'Not found', array( 'status' => 404 ) );
        }

        $file_path = self::get_gallery_dir( $code ) . '/' . $filename;
        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'not_found', 'Photo not found', array( 'status' => 404 ) );
        }

        header( 'Content-Type: image/jpeg' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $file_path ) );
        readfile( $file_path );
        exit;
    }

    public static function rest_download_all( $request ) {
        $code = sanitize_text_field( $request['code'] );

        $session = TwellerFlow_Session::get_by_code( $code );
        if ( ! $session || $session->current_stage !== 'delivered' ) {
            return new WP_Error( 'not_found', 'Not found', array( 'status' => 404 ) );
        }

        $gallery_dir = self::get_gallery_dir( $code );
        $photos = self::get_photos( $session->id );
        if ( empty( $photos ) ) {
            return new WP_Error( 'empty', 'No photos in gallery', array( 'status' => 404 ) );
        }

        $zip_name = sanitize_file_name( $session->client_name ) . '-photos.zip';
        $zip_path = $gallery_dir . '/' . $zip_name;

        $zip = new ZipArchive();
        if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            return new WP_Error( 'zip_failed', 'Could not create zip file', array( 'status' => 500 ) );
        }

        foreach ( $photos as $photo ) {
            $file_path = $gallery_dir . '/' . $photo->filename;
            if ( file_exists( $file_path ) ) {
                $zip->addFile( $file_path, $photo->filename );
            }
        }
        $zip->close();

        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $zip_name . '"' );
        header( 'Content-Length: ' . filesize( $zip_path ) );
        readfile( $zip_path );

        // Clean up temp zip
        @unlink( $zip_path );
        exit;
    }

    // ── Admin: Delete Photo ────────────────────────────

    public static function rest_delete_photo( $request ) {
        $code     = sanitize_text_field( $request['code'] );
        $photo_id = intval( $request['photo_id'] );

        global $wpdb;
        $table = $wpdb->prefix . 'tweller_gallery_photos';
        $photo = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $photo_id ) );

        if ( ! $photo ) {
            return new WP_Error( 'not_found', 'Photo not found', array( 'status' => 404 ) );
        }

        // Delete files
        $gallery_dir = self::get_gallery_dir( $code );
        @unlink( $gallery_dir . '/' . $photo->filename );
        @unlink( $gallery_dir . '/thumbs/' . $photo->filename );

        // Delete DB record
        $wpdb->delete( $table, array( 'id' => $photo_id ) );

        return rest_ensure_response( array( 'ok' => true ) );
    }

    // ── Admin: Set Password ────────────────────────────

    public static function rest_set_password( $request ) {
        $code     = sanitize_text_field( $request['code'] );
        $password = sanitize_text_field( $request->get_param( 'password' ) );

        $session = TwellerFlow_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        if ( empty( $password ) ) {
            delete_option( 'tweller_gallery_pw_' . $session->id );
        } else {
            self::set_password( $session->id, $password );
        }

        return rest_ensure_response( array( 'ok' => true ) );
    }

    // ── Database ───────────────────────────────────────

    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'tweller_gallery_photos';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            session_code varchar(10) NOT NULL,
            filename varchar(255) NOT NULL,
            sort_order int DEFAULT 0,
            uploaded_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY session_code (session_code)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    public static function insert_photo( $session_id, $session_code, $filename ) {
        global $wpdb;
        $table = $wpdb->prefix . 'tweller_gallery_photos';

        // Get next sort order
        $max_order = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(sort_order) FROM $table WHERE session_id = %d",
            $session_id
        ));

        $wpdb->insert( $table, array(
            'session_id'   => $session_id,
            'session_code' => $session_code,
            'filename'     => $filename,
            'sort_order'   => ( $max_order ?? -1 ) + 1,
            'uploaded_at'  => current_time( 'mysql' ),
        ));

        return $wpdb->insert_id;
    }

    public static function get_photos( $session_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'tweller_gallery_photos';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE session_id = %d ORDER BY sort_order ASC",
            $session_id
        ));
    }

    public static function count_photos( $session_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'tweller_gallery_photos';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE session_id = %d",
            $session_id
        ));
    }

    // ── File Helpers ───────────────────────────────────

    public static function get_gallery_dir( $session_code ) {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/' . self::UPLOAD_SUBDIR . '/' . $session_code;
    }

    public static function get_gallery_url( $session_code ) {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/' . self::UPLOAD_SUBDIR . '/' . $session_code;
    }

    public static function create_thumbnail( $source, $dest, $max_width = 800 ) {
        $editor = wp_get_image_editor( $source );
        if ( is_wp_error( $editor ) ) {
            // Fallback: just copy the original
            copy( $source, $dest );
            return;
        }

        $size = $editor->get_size();
        if ( $size['width'] > $max_width ) {
            $editor->resize( $max_width, null, false );
        }

        $editor->set_quality( 82 );
        $editor->save( $dest );
    }

    // ── Password / Token ──────────────────────────────

    public static function set_password( $session_id, $password ) {
        $hash = wp_hash_password( $password );
        update_option( 'tweller_gallery_pw_' . $session_id, $hash );
    }

    public static function generate_token( $session_id ) {
        $secret = wp_salt( 'auth' );
        $expiry = time() + ( 24 * 60 * 60 ); // 24 hours
        $token  = base64_encode( $session_id . ':' . $expiry . ':' . hash_hmac( 'sha256', $session_id . ':' . $expiry, $secret ) );
        return $token;
    }

    public static function verify_token( $session_id, $token ) {
        $decoded = base64_decode( $token );
        if ( ! $decoded ) return false;

        $parts = explode( ':', $decoded );
        if ( count( $parts ) !== 3 ) return false;

        list( $token_sid, $expiry, $hash ) = $parts;

        if ( intval( $token_sid ) !== intval( $session_id ) ) return false;
        if ( time() > intval( $expiry ) ) return false;

        $secret   = wp_salt( 'auth' );
        $expected = hash_hmac( 'sha256', $token_sid . ':' . $expiry, $secret );

        return hash_equals( $expected, $hash );
    }

    // ── Admin helpers ──────────────────────────────────

    public static function get_gallery_info( $session_id, $session_code ) {
        $photos   = self::get_photos( $session_id );
        $has_pw   = ! empty( get_option( 'tweller_gallery_pw_' . $session_id, '' ) );
        $dir      = self::get_gallery_dir( $session_code );
        $dir_size = 0;
        if ( is_dir( $dir ) ) {
            foreach ( glob( $dir . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE ) as $f ) {
                $dir_size += filesize( $f );
            }
        }

        return array(
            'photos'        => $photos,
            'photo_count'   => count( $photos ),
            'has_password'  => $has_pw,
            'total_size_mb' => round( $dir_size / 1048576, 1 ),
        );
    }
}
