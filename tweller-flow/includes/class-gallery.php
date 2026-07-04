<?php
/**
 * Tweller Flow Gallery — Photo upload, storage, and client gallery
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Gallery {

    /** Gallery uploads directory under wp-content/uploads/ */
    const UPLOAD_SUBDIR = 'tweller-gallery';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    // ── REST Routes ─────────────────────────────────────

    public static function register_rest_routes() {
        // Upload photo (from watcher / LR plugin / admin drag-drop)
        // Route is /photo-upload (not under /gallery/) to avoid conflicting
        // with the /gallery/{code} regex route when auth fails.
        register_rest_route( 'tweller-flow-2/v1', '/photo-upload', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_upload_photo' ),
            'permission_callback' => array( 'TwellerFlow2_Photo_Automation', 'verify_api_key' ),
        ));

        // Get gallery photos (public — used by client tracker)
        register_rest_route( 'tweller-flow-2/v1', '/gallery/(?P<code>[a-zA-Z0-9\-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_gallery' ),
            'permission_callback' => '__return_true',
        ));

        // Verify gallery password
        register_rest_route( 'tweller-flow-2/v1', '/gallery/(?P<code>[a-zA-Z0-9\-]+)/verify', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_verify_password' ),
            'permission_callback' => '__return_true',
        ));

        // Download single photo (proxied for password-protected galleries)
        register_rest_route( 'tweller-flow-2/v1', '/gallery/(?P<code>[a-zA-Z0-9\-]+)/download', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_download_photo' ),
            'permission_callback' => '__return_true',
        ));

        // Download all photos as zip
        register_rest_route( 'tweller-flow-2/v1', '/gallery/(?P<code>[a-zA-Z0-9\-]+)/download-all', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_download_all' ),
            'permission_callback' => '__return_true',
        ));

        // Admin: delete gallery photo
        register_rest_route( 'tweller-flow-2/v1', '/gallery/(?P<code>[a-zA-Z0-9\-]+)/photo/(?P<photo_id>\d+)', array(
            'methods'             => 'DELETE',
            'callback'            => array( __CLASS__, 'rest_delete_photo' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));

        // Check existing photo filenames (used by watcher to avoid re-uploads)
        register_rest_route( 'tweller-flow-2/v1', '/gallery/(?P<code>[a-zA-Z0-9\-]+)/filenames', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_filenames' ),
            'permission_callback' => array( 'TwellerFlow2_Photo_Automation', 'verify_api_key' ),
        ));

        // Admin: batch delete photos
        register_rest_route( 'tweller-flow-2/v1', '/gallery/(?P<code>[a-zA-Z0-9\-]+)/batch-delete', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_batch_delete' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));

        // Admin: reorder photos
        register_rest_route( 'tweller-flow-2/v1', '/gallery/(?P<code>[a-zA-Z0-9\-]+)/reorder', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_reorder' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));

        // Admin: set gallery password
        register_rest_route( 'tweller-flow-2/v1', '/gallery/(?P<code>[a-zA-Z0-9\-]+)/password', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_set_password' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));

        // Admin: set gallery cover photo + focal position
        register_rest_route( 'tweller-flow-2/v1', '/gallery/(?P<code>[a-zA-Z0-9\-]+)/cover', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_set_cover' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ));
    }

    // ── Admin: Cover Photo & Position ──────────────────

    public static function rest_set_cover( $request ) {
        $code     = sanitize_text_field( $request['code'] );
        $photo_id = intval( $request->get_param( 'photo_id' ) ?? 0 );
        $pos_x    = $request->get_param( 'pos_x' );
        $pos_y    = $request->get_param( 'pos_y' );

        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        if ( $photo_id ) {
            update_option( 'tweller_gallery_cover_' . $session->id, $photo_id );
        }
        if ( $pos_x !== null && $pos_y !== null ) {
            $pos_x = max( 0, min( 100, floatval( $pos_x ) ) );
            $pos_y = max( 0, min( 100, floatval( $pos_y ) ) );
            update_option( 'tweller_gallery_cover_pos_' . $session->id, round( $pos_x, 1 ) . ',' . round( $pos_y, 1 ) );
        }

        // Regenerate the social-share (og:image) crop from the new cover/position
        self::generate_og_image( $session );

        $cover = self::get_cover( $session );
        return rest_ensure_response( array( 'ok' => true, 'cover' => $cover ) );
    }

    /**
     * Resolve the gallery cover: chosen photo (or first photo) + focal position.
     * Returns null when the gallery is empty.
     */
    public static function get_cover( $session ) {
        global $wpdb;
        $table = $wpdb->prefix . 'tweller_gallery_photos';

        $cover_id = (int) get_option( 'tweller_gallery_cover_' . $session->id, 0 );
        $photo = null;
        if ( $cover_id ) {
            $photo = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $table WHERE id = %d AND session_id = %d", $cover_id, $session->id
            ));
        }
        if ( ! $photo ) {
            $photo = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $table WHERE session_id = %d ORDER BY sort_order ASC LIMIT 1", $session->id
            ));
        }
        if ( ! $photo ) return null;

        $pos = get_option( 'tweller_gallery_cover_pos_' . $session->id, '50,50' );
        $parts = explode( ',', $pos );
        $pos_x = isset( $parts[0] ) ? floatval( $parts[0] ) : 50;
        $pos_y = isset( $parts[1] ) ? floatval( $parts[1] ) : 50;

        $gallery_url = self::get_gallery_url( $session->tracking_code );

        return array(
            'photo_id'  => (int) $photo->id,
            'url'       => $gallery_url . '/' . $photo->filename,
            'thumb_url' => $gallery_url . '/thumbs/' . $photo->filename,
            'pos_x'     => $pos_x,
            'pos_y'     => $pos_y,
            'position'  => $pos_x . '% ' . $pos_y . '%',
        );
    }

    /**
     * Generate a 1200x630 crop of the cover photo centred on the focal point.
     * Used as og:image so shared links show the right part of the photo.
     */
    public static function generate_og_image( $session ) {
        $cover = self::get_cover( $session );
        if ( ! $cover ) return false;

        global $wpdb;
        $table = $wpdb->prefix . 'tweller_gallery_photos';
        $photo = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d", $cover['photo_id']
        ));
        if ( ! $photo ) return false;

        $gallery_dir = self::get_gallery_dir( $session->tracking_code );
        $source = $gallery_dir . '/' . $photo->filename;
        if ( ! file_exists( $source ) ) return false;

        $editor = wp_get_image_editor( $source );
        if ( is_wp_error( $editor ) ) return false;

        $size = $editor->get_size();
        $src_w = $size['width'];
        $src_h = $size['height'];

        // Target 1200x630 (1.9:1). Crop the largest region at that ratio
        // centred on the focal point, then scale down.
        $target_ratio = 1200 / 630;
        $crop_w = $src_w;
        $crop_h = (int) round( $crop_w / $target_ratio );
        if ( $crop_h > $src_h ) {
            $crop_h = $src_h;
            $crop_w = (int) round( $crop_h * $target_ratio );
        }

        $focal_x = (int) round( $src_w * $cover['pos_x'] / 100 );
        $focal_y = (int) round( $src_h * $cover['pos_y'] / 100 );

        $crop_x = max( 0, min( $src_w - $crop_w, $focal_x - (int) ( $crop_w / 2 ) ) );
        $crop_y = max( 0, min( $src_h - $crop_h, $focal_y - (int) ( $crop_h / 2 ) ) );

        $editor->crop( $crop_x, $crop_y, $crop_w, $crop_h, 1200, 630 );
        $editor->set_quality( 82 );
        $saved = $editor->save( $gallery_dir . '/og-cover.jpg', 'image/jpeg' );

        return ! is_wp_error( $saved );
    }

    public static function get_og_image_url( $session ) {
        $gallery_dir = self::get_gallery_dir( $session->tracking_code );
        if ( ! file_exists( $gallery_dir . '/og-cover.jpg' ) ) {
            // Try to build it on the fly (first share before admin repositioned)
            if ( ! self::generate_og_image( $session ) ) return '';
        }
        return self::get_gallery_url( $session->tracking_code ) . '/og-cover.jpg?v=' . filemtime( $gallery_dir . '/og-cover.jpg' );
    }

    // ── Upload ─────────────────────────────────────────

    /**
     * Save a single uploaded photo file to a session gallery.
     * $file is a standard $_FILES entry (or REST file params entry).
     * Returns array( 'ok', 'photo_id', 'filename' ) or WP_Error.
     */
    public static function save_photo_file( $session, $file ) {
        $session_code = $session->tracking_code;

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
        $tracker_url = get_option( 'tweller_flow_2_tracker_page', '' );
        if ( $tracker_url ) {
            $gallery_url = $tracker_url . ( strpos( $tracker_url, '?' ) !== false ? '&' : '?' ) . 'code=' . $session_code . '#gallery';
            TwellerFlow2_Session::update( $session->id, array( 'gallery_url' => $gallery_url ) );
        }

        return array(
            'ok'       => true,
            'photo_id' => $photo_id,
            'filename' => $filename,
        );
    }

    public static function rest_upload_photo( $request ) {
        $session_code = sanitize_text_field( $request->get_param( 'session_code' ) );
        $password     = sanitize_text_field( $request->get_param( 'gallery_password' ) );

        if ( empty( $session_code ) ) {
            return new WP_Error( 'missing_code', 'session_code is required', array( 'status' => 400 ) );
        }

        $session = TwellerFlow2_Session::get_by_code( $session_code );
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

        $result = self::save_photo_file( $session, $files['photo'] );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Auto-advance: once photos start arriving, move the session to
        // "uploading" if it's still at an earlier stage (booked, editing, etc.)
        $stage_keys    = TwellerFlow2_Database::get_stage_keys();
        $current_idx   = array_search( $session->current_stage, $stage_keys );
        $uploading_idx = array_search( 'uploading', $stage_keys );
        if ( $current_idx !== false && $uploading_idx !== false && $current_idx < $uploading_idx ) {
            TwellerFlow2_Session::set_stage( $session->id, 'uploading', 'Photos uploading from Lightroom', false );
        }

        return rest_ensure_response( $result );
    }

    // ── Get Gallery ────────────────────────────────────

    public static function rest_get_gallery( $request ) {
        $code     = sanitize_text_field( $request['code'] );
        $token    = sanitize_text_field( $request->get_param( 'token' ) );

        $session = TwellerFlow2_Session::get_by_code( $code );
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

        // Show gallery once images are uploaded or delivered
        if ( ! in_array( $session->current_stage, array( 'uploaded', 'delivered' ), true ) ) {
            return rest_ensure_response( array(
                'ok'            => true,
                'ready'         => false,
                'has_password'  => $has_password,
                'photos'        => array(),
                'client_name'   => $session->client_name,
                'session_date'  => $session->session_date,
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
                'session_date' => $session->session_date,
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
            'session_date' => $session->session_date,
            'photos'       => $photo_list,
            'photo_count'  => count( $photo_list ),
            'cover'        => self::get_cover( $session ),
        ));
    }

    // ── Verify Password ────────────────────────────────

    public static function rest_verify_password( $request ) {
        $code     = sanitize_text_field( $request['code'] );
        $password = sanitize_text_field( $request->get_param( 'password' ) );

        $session = TwellerFlow2_Session::get_by_code( $code );
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

        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session || ! in_array( $session->current_stage, array( 'uploaded', 'delivered' ), true ) ) {
            return new WP_Error( 'not_found', 'Not found', array( 'status' => 404 ) );
        }

        $file_path = self::get_gallery_dir( $code ) . '/' . $filename;
        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'not_found', 'Photo not found', array( 'status' => 404 ) );
        }

        // Track download
        if ( class_exists( 'TwellerFlow2_Client_Activity' ) ) {
            TwellerFlow2_Client_Activity::log( $session->id, $code, 'photo_downloaded', $filename );
        }

        header( 'Content-Type: image/jpeg' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $file_path ) );
        readfile( $file_path );
        exit;
    }

    public static function rest_download_all( $request ) {
        $code = sanitize_text_field( $request['code'] );

        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session || ! in_array( $session->current_stage, array( 'uploaded', 'delivered' ), true ) ) {
            return new WP_Error( 'not_found', 'Not found', array( 'status' => 404 ) );
        }

        $gallery_dir = self::get_gallery_dir( $code );
        $photos = self::get_photos( $session->id );
        if ( empty( $photos ) ) {
            return new WP_Error( 'empty', 'No photos in gallery', array( 'status' => 404 ) );
        }

        // Track download
        if ( class_exists( 'TwellerFlow2_Client_Activity' ) ) {
            TwellerFlow2_Client_Activity::log( $session->id, $code, 'all_downloaded', count( $photos ) . ' photos' );
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

    // ── Admin: Batch Delete Photos ─────────────────────

    public static function rest_batch_delete( $request ) {
        $code      = sanitize_text_field( $request['code'] );
        $photo_ids = $request->get_param( 'photo_ids' );

        if ( ! is_array( $photo_ids ) || empty( $photo_ids ) ) {
            return new WP_Error( 'no_ids', 'No photo IDs provided', array( 'status' => 400 ) );
        }

        global $wpdb;
        $table       = $wpdb->prefix . 'tweller_gallery_photos';
        $gallery_dir = self::get_gallery_dir( $code );
        $deleted     = 0;

        foreach ( $photo_ids as $photo_id ) {
            $photo_id = intval( $photo_id );
            $photo = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND session_code = %s", $photo_id, $code ) );
            if ( ! $photo ) continue;

            @unlink( $gallery_dir . '/' . $photo->filename );
            @unlink( $gallery_dir . '/thumbs/' . $photo->filename );
            $wpdb->delete( $table, array( 'id' => $photo_id ) );
            $deleted++;
        }

        return rest_ensure_response( array( 'ok' => true, 'deleted' => $deleted ) );
    }

    // ── Admin: Reorder Photos ────────────────────────────

    public static function rest_reorder( $request ) {
        $code  = sanitize_text_field( $request['code'] );
        $order = $request->get_param( 'order' );

        if ( ! is_array( $order ) || empty( $order ) ) {
            return new WP_Error( 'no_order', 'No order provided', array( 'status' => 400 ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tweller_gallery_photos';

        foreach ( $order as $idx => $photo_id ) {
            $wpdb->update(
                $table,
                array( 'sort_order' => intval( $idx ) ),
                array( 'id' => intval( $photo_id ), 'session_code' => $code )
            );
        }

        return rest_ensure_response( array( 'ok' => true ) );
    }

    // ── Admin: Set Password ────────────────────────────

    public static function rest_set_password( $request ) {
        $code     = sanitize_text_field( $request['code'] );
        $password = sanitize_text_field( $request->get_param( 'password' ) );

        $session = TwellerFlow2_Session::get_by_code( $code );
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

    // ── Get Filenames (for watcher dedup) ───────────────

    public static function rest_get_filenames( $request ) {
        $code = sanitize_text_field( $request['code'] );
        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $photos = self::get_photos( $session->id );
        $filenames = array();
        foreach ( $photos as $photo ) {
            $filenames[] = $photo->filename;
        }

        return rest_ensure_response( array(
            'ok'        => true,
            'filenames' => $filenames,
            'count'     => count( $filenames ),
        ));
    }

    // ── Database ───────────────────────────────────────

    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'tweller_gallery_photos';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            session_code varchar(120) NOT NULL,
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
