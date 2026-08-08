<?php
/**
 * Event gallery model — creation (booking + self-service), lookups, counts,
 * cover resolution, filesystem paths and the upload/photo query layer.
 *
 * This is the class the rest of the engine talks to; it hides the CPT/meta
 * and custom-table details behind a small, stable API.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TEPE_Gallery {

    public static function init() {
        // Clean up files when an event is permanently deleted.
        add_action( 'before_delete_post', array( __CLASS__, 'on_delete_event' ) );
    }

    // ── Creation ───────────────────────────────────────────────────────────

    /**
     * Create an event gallery.
     *
     * @param array $args title, host_email, host_name, session_id, session_code,
     *                    is_booking_linked, event_date, categories (labels[]),
     *                    welcome, pricing_tier, is_monetized, upload_limit.
     * @return int|WP_Error post id.
     */
    public static function create( $args ) {
        $args = wp_parse_args( $args, array(
            'title'             => '',
            'host_email'        => '',
            'host_name'         => '',
            'session_id'        => 0,
            'session_code'      => '',
            'is_booking_linked' => 0,
            'event_date'        => '',
            'categories'        => array(),
            'welcome'           => '',
            'pricing_tier'      => 'free',
            'is_monetized'      => 0,
            'upload_limit'      => 0,
            'status'            => 'publish',
        ) );

        $title = trim( sanitize_text_field( $args['title'] ) );
        if ( $title === '' ) $title = 'Event Gallery';

        $email = sanitize_email( $args['host_email'] );
        if ( $email !== '' && ! is_email( $email ) ) {
            return new WP_Error( 'bad_email', 'Please provide a valid host email address.' );
        }

        $post_id = wp_insert_post( array(
            'post_type'    => TEPE_CPT,
            'post_status'  => $args['status'],
            'post_title'   => $title,
            'post_content' => '',
            'post_name'    => self::unique_slug( $title ),
        ), true );

        if ( is_wp_error( $post_id ) ) return $post_id;

        $meta = TEPE_CPT::default_meta();
        $meta[ TEPE_CPT::META_HOST_EMAIL ]  = $email;
        $meta[ TEPE_CPT::META_HOST_NAME ]   = sanitize_text_field( $args['host_name'] );
        $meta[ TEPE_CPT::META_SESSION_ID ]  = (int) $args['session_id'];
        $meta[ TEPE_CPT::META_SESSION_CODE ]= sanitize_text_field( $args['session_code'] );
        $meta[ TEPE_CPT::META_IS_BOOKED ]   = $args['is_booking_linked'] ? 1 : 0;
        $meta[ TEPE_CPT::META_EVENT_DATE ]  = sanitize_text_field( $args['event_date'] );
        $meta[ TEPE_CPT::META_WELCOME ]     = sanitize_textarea_field( $args['welcome'] );
        $meta[ TEPE_CPT::META_PRICING_TIER ]= sanitize_key( $args['pricing_tier'] );
        $meta[ TEPE_CPT::META_IS_MONETIZED ]= $args['is_monetized'] ? 1 : 0;
        $meta[ TEPE_CPT::META_UPLOAD_LIMIT ]= max( 0, (int) $args['upload_limit'] );

        foreach ( $meta as $k => $v ) update_post_meta( $post_id, $k, $v );

        // Seed categories (presets when none supplied).
        $labels = ! empty( $args['categories'] ) ? $args['categories'] : TEPE_Categories::presets();
        TEPE_Categories::set( $post_id, $labels );

        do_action( 'tepe_event_created', $post_id, $args );
        return $post_id;
    }

    private static function unique_slug( $title ) {
        $base = sanitize_title( $title );
        if ( $base === '' ) $base = 'event';
        $slug = $base;
        $i = 2;
        while ( self::slug_exists( $slug ) ) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    private static function slug_exists( $slug ) {
        $existing = get_page_by_path( $slug, OBJECT, TEPE_CPT );
        return ! empty( $existing );
    }

    // ── Lookups ────────────────────────────────────────────────────────────

    public static function get( $event_id ) {
        $post = get_post( $event_id );
        if ( ! $post || $post->post_type !== TEPE_CPT ) return null;
        return $post;
    }

    public static function get_by_slug( $slug ) {
        $post = get_page_by_path( sanitize_title( $slug ), OBJECT, TEPE_CPT );
        return ( $post && $post->post_type === TEPE_CPT ) ? $post : null;
    }

    public static function get_by_session( $session_id ) {
        $q = get_posts( array(
            'post_type'   => TEPE_CPT,
            'post_status' => 'any',
            'numberposts' => 1,
            'meta_key'    => TEPE_CPT::META_SESSION_ID,
            'meta_value'  => (int) $session_id,
            'fields'      => 'ids',
        ) );
        return ! empty( $q ) ? self::get( $q[0] ) : null;
    }

    public static function meta( $event_id, $key, $default = '' ) {
        $v = get_post_meta( $event_id, $key, true );
        return ( $v === '' || $v === false ) ? $default : $v;
    }

    public static function is_locked( $event_id ) {
        return (bool) get_post_meta( $event_id, TEPE_CPT::META_IS_LOCKED, true );
    }

    public static function is_free_tier( $event_id ) {
        // Free-tier == not created from a paid booking. Drives the "Powered by
        // Tweller Studios" promo branding.
        return ! get_post_meta( $event_id, TEPE_CPT::META_IS_BOOKED, true );
    }

    // ── URLs ───────────────────────────────────────────────────────────────

    public static function gallery_url( $event ) {
        return get_permalink( $event );
    }

    public static function upload_url( $event ) {
        return trailingslashit( get_permalink( $event ) ) . 'upload/';
    }

    // ── Filesystem ─────────────────────────────────────────────────────────

    public static function dir( $event ) {
        $slug = is_object( $event ) ? $event->post_name : sanitize_title( $event );
        $up = wp_upload_dir();
        return $up['basedir'] . '/' . TEPE_UPLOAD_SUBDIR . '/' . $slug;
    }

    public static function url( $event ) {
        $slug = is_object( $event ) ? $event->post_name : sanitize_title( $event );
        $up = wp_upload_dir();
        return $up['baseurl'] . '/' . TEPE_UPLOAD_SUBDIR . '/' . $slug;
    }

    public static function ensure_dirs( $event ) {
        $dir = self::dir( $event );
        wp_mkdir_p( $dir );
        wp_mkdir_p( $dir . '/thumbs' );
        // Keep a directory index out of prying eyes.
        if ( ! file_exists( $dir . '/index.html' ) ) {
            @file_put_contents( $dir . '/index.html', '' );
        }
        return $dir;
    }

    // ── Photo / upload query layer ─────────────────────────────────────────

    public static function insert_upload( $data ) {
        global $wpdb;
        $table = TEPE_Database::table( TEPE_TABLE_UPLOADS );

        $event_id = (int) $data['event_id'];
        $max_order = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(sort_order) FROM $table WHERE event_id = %d", $event_id
        ) );

        $wpdb->insert( $table, array(
            'event_id'         => $event_id,
            'guest_id'         => isset( $data['guest_id'] ) ? (int) $data['guest_id'] : null,
            'category'         => isset( $data['category'] ) ? sanitize_key( $data['category'] ) : '',
            'filename'         => $data['filename'],
            'original_name'    => isset( $data['original_name'] ) ? $data['original_name'] : '',
            'mime'             => isset( $data['mime'] ) ? $data['mime'] : '',
            'width'            => isset( $data['width'] ) ? (int) $data['width'] : 0,
            'height'           => isset( $data['height'] ) ? (int) $data['height'] : 0,
            'filesize'         => isset( $data['filesize'] ) ? (int) $data['filesize'] : 0,
            'caption'          => isset( $data['caption'] ) ? sanitize_text_field( $data['caption'] ) : '',
            'ip_hash'          => isset( $data['ip_hash'] ) ? $data['ip_hash'] : '',
            'fingerprint_hash' => isset( $data['fingerprint_hash'] ) ? $data['fingerprint_hash'] : '',
            'status'           => isset( $data['status'] ) ? $data['status'] : 'approved',
            'sort_order'       => ( $max_order === null ? -1 : (int) $max_order ) + 1,
            'created_at'       => current_time( 'mysql' ),
        ) );
        return (int) $wpdb->insert_id;
    }

    /**
     * Fetch uploads for an event.
     *
     * @param array $args category (slug|null for all), status, guest_id,
     *                    per_page, offset.
     */
    public static function get_uploads( $event_id, $args = array() ) {
        global $wpdb;
        $table = TEPE_Database::table( TEPE_TABLE_UPLOADS );

        $where  = 'event_id = %d';
        $values = array( (int) $event_id );

        if ( isset( $args['status'] ) ) {
            $where .= ' AND status = %s';
            $values[] = $args['status'];
        }
        if ( isset( $args['category'] ) && $args['category'] !== null ) {
            $where .= ' AND category = %s';
            $values[] = sanitize_key( $args['category'] );
        }
        if ( ! empty( $args['guest_id'] ) ) {
            $where .= ' AND guest_id = %d';
            $values[] = (int) $args['guest_id'];
        }

        $sql = "SELECT * FROM $table WHERE $where ORDER BY sort_order ASC, id ASC";
        if ( ! empty( $args['per_page'] ) ) {
            $sql .= ' LIMIT %d OFFSET %d';
            $values[] = (int) $args['per_page'];
            $values[] = (int) ( $args['offset'] ?? 0 );
        }
        return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
    }

    public static function get_upload( $upload_id ) {
        global $wpdb;
        $table = TEPE_Database::table( TEPE_TABLE_UPLOADS );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $upload_id ) );
    }

    public static function count_uploads( $event_id, $status = 'approved' ) {
        global $wpdb;
        $table = TEPE_Database::table( TEPE_TABLE_UPLOADS );
        if ( $status === null ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE event_id = %d", $event_id ) );
        }
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE event_id = %d AND status = %s", $event_id, $status
        ) );
    }

    public static function delete_upload( $upload_id ) {
        global $wpdb;
        $table  = TEPE_Database::table( TEPE_TABLE_UPLOADS );
        $upload = self::get_upload( $upload_id );
        if ( ! $upload ) return false;

        $event = self::get( $upload->event_id );
        if ( $event ) {
            $dir = self::dir( $event );
            @unlink( $dir . '/' . $upload->filename );
            @unlink( $dir . '/thumbs/' . $upload->filename );
        }
        return (bool) $wpdb->delete( $table, array( 'id' => (int) $upload_id ) );
    }

    /** Public payload for one upload row. */
    public static function upload_payload( $event, $upload ) {
        $base = self::url( $event );
        return array(
            'id'        => (int) $upload->id,
            'category'  => $upload->category,
            'url'       => $base . '/' . $upload->filename,
            'thumb_url' => $base . '/thumbs/' . tepe_thumb_name( $upload->filename ),
            'width'     => (int) $upload->width,
            'height'    => (int) $upload->height,
            'caption'   => $upload->caption,
            'likes'     => (int) $upload->likes,
            'mine'      => false,
        );
    }

    // ── Cover ──────────────────────────────────────────────────────────────

    public static function get_cover( $event ) {
        $cover_id = (int) get_post_meta( $event->ID, TEPE_CPT::META_COVER, true );
        $upload = $cover_id ? self::get_upload( $cover_id ) : null;
        if ( ! $upload ) {
            $rows = self::get_uploads( $event->ID, array( 'status' => 'approved', 'per_page' => 1 ) );
            $upload = ! empty( $rows ) ? $rows[0] : null;
        }
        if ( ! $upload ) return null;
        $base = self::url( $event );
        return array(
            'url'       => $base . '/' . $upload->filename,
            'thumb_url' => $base . '/thumbs/' . $upload->filename,
        );
    }

    // ── Cleanup ────────────────────────────────────────────────────────────

    public static function on_delete_event( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== TEPE_CPT ) return;

        global $wpdb;
        $wpdb->delete( TEPE_Database::table( TEPE_TABLE_UPLOADS ), array( 'event_id' => $post_id ) );
        $wpdb->delete( TEPE_Database::table( TEPE_TABLE_GUESTS ), array( 'event_id' => $post_id ) );
        $wpdb->delete( TEPE_Database::table( TEPE_TABLE_SHARES ), array( 'event_id' => $post_id ) );
        // Orders & promos are financial records — keep them, just unlink the event.

        // Remove the upload directory.
        $dir = self::dir( $post );
        if ( is_dir( $dir ) ) self::rrmdir( $dir );
    }

    private static function rrmdir( $dir ) {
        foreach ( glob( $dir . '/*' ) as $item ) {
            is_dir( $item ) ? self::rrmdir( $item ) : @unlink( $item );
        }
        @rmdir( $dir );
    }
}

/**
 * Tiny helper so upload rows can compute a thumb filename (HEIC/HEIF become
 * .jpg thumbnails). Added to the stdClass rows via a wrapper method emulation.
 */
if ( ! function_exists( 'tepe_thumb_name' ) ) {
    function tepe_thumb_name( $filename ) {
        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        if ( in_array( $ext, array( 'heic', 'heif' ), true ) ) {
            return preg_replace( '/\.(heic|heif)$/i', '.jpg', $filename );
        }
        return $filename;
    }
}
