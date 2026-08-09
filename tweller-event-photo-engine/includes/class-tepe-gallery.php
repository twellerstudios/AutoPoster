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

    /** Every published event whose host email matches (case-insensitive). */
    public static function get_by_host_email( $email ) {
        $email = sanitize_email( $email );
        if ( ! $email || ! is_email( $email ) ) return array();
        $ids = get_posts( array(
            'post_type'   => TEPE_CPT,
            'post_status' => 'publish',
            'numberposts' => 100,
            'meta_key'    => TEPE_CPT::META_HOST_EMAIL,
            'meta_value'  => $email,
            'fields'      => 'ids',
            'orderby'     => 'date',
            'order'       => 'DESC',
        ) );
        return array_map( array( __CLASS__, 'get' ), $ids );
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

    /** The upload row to use as the cover (chosen cover, else first photo). */
    public static function cover_upload( $event ) {
        $cover_id = (int) get_post_meta( $event->ID, TEPE_CPT::META_COVER, true );
        $upload = $cover_id ? self::get_upload( $cover_id ) : null;
        if ( ! $upload || (int) $upload->event_id !== (int) $event->ID ) {
            $rows = self::get_uploads( $event->ID, array( 'status' => 'approved', 'per_page' => 1 ) );
            $upload = ! empty( $rows ) ? $rows[0] : null;
        }
        return $upload ?: null;
    }

    public static function get_cover( $event ) {
        $upload = self::cover_upload( $event );
        if ( ! $upload ) return null;
        $base = self::url( $event );
        return array(
            'url'       => $base . '/' . $upload->filename,
            'thumb_url' => $base . '/thumbs/' . tepe_thumb_name( $upload->filename ),
        );
    }

    /**
     * A 1200×630 social-share (og:image) crop of the cover, centred, cached to
     * disk. This is the thumbnail WhatsApp/Facebook/etc. show for the link.
     * Returns [ url, width, height ] or null.
     */
    public static function og_image( $event ) {
        $upload = self::cover_upload( $event );
        if ( ! $upload ) return null;

        $dir  = self::dir( $event );
        $base = self::url( $event );
        $out  = $dir . '/og-cover.jpg';

        // Prefer the JPEG thumb as source (HEIC originals can't be read by GD).
        $src = $dir . '/thumbs/' . tepe_thumb_name( $upload->filename );
        if ( ! file_exists( $src ) ) $src = $dir . '/' . $upload->filename;
        if ( ! file_exists( $src ) ) return null;

        // Rebuild only when missing or older than the source.
        if ( ! file_exists( $out ) || filemtime( $out ) < filemtime( $src ) ) {
            $editor = wp_get_image_editor( $src );
            if ( is_wp_error( $editor ) ) {
                // Can't crop — fall back to the thumb as-is.
                $turl = $base . '/thumbs/' . tepe_thumb_name( $upload->filename );
                return array( 'url' => $turl, 'width' => (int) $upload->width, 'height' => (int) $upload->height );
            }
            $size = $editor->get_size();
            $sw = (int) ( $size['width'] ?? 0 );
            $sh = (int) ( $size['height'] ?? 0 );
            if ( $sw > 0 && $sh > 0 ) {
                $target = 1200 / 630;
                $crop_w = $sw;
                $crop_h = (int) round( $crop_w / $target );
                if ( $crop_h > $sh ) { $crop_h = $sh; $crop_w = (int) round( $crop_h * $target ); }
                $crop_x = (int) round( ( $sw - $crop_w ) / 2 );
                $crop_y = (int) round( ( $sh - $crop_h ) / 2 );
                $editor->crop( $crop_x, $crop_y, $crop_w, $crop_h, 1200, 630 );
                $editor->set_quality( 82 );
                $editor->save( $out, 'image/jpeg' );
            }
        }

        if ( file_exists( $out ) ) {
            return array( 'url' => $base . '/og-cover.jpg?v=' . filemtime( $out ), 'width' => 1200, 'height' => 630 );
        }
        return null;
    }

    // ── Host email (branded) ───────────────────────────────────────────────

    /**
     * Email the host their gallery + guest-upload links. Uses the Tweller
     * Bookings branded email template (send_raw wraps it in the black/gold
     * shell) when that plugin is present; falls back to plain text otherwise.
     */
    public static function email_host( $event, $email, $intro = '' ) {
        $email = sanitize_email( $email );
        if ( ! $email || ! is_email( $email ) ) return false;

        $title   = tepe_title( $event );
        $gallery = self::gallery_url( $event );
        $upload  = self::upload_url( $event );
        $subject = 'Your Tweller event gallery is ready';

        if ( class_exists( 'TwellerFlow2_Notifications' ) && method_exists( 'TwellerFlow2_Notifications', 'send_raw' ) ) {
            $N = 'TwellerFlow2_Notifications';
            if ( $intro === '' ) {
                $intro = 'Your shared gallery is live. Guests can scan a QR code and upload their photos straight from their phones — no app, no sign-up. Everything lands in one place for you.';
            }
            $body  = "<h2 style='color:#101010;font-weight:600;margin:0 0 12px;'>Your event gallery is ready 🎉</h2>";
            $body .= "<p style='color:#3D3630;line-height:1.7;margin:0 0 6px;'><strong>" . esc_html( $title ) . "</strong></p>";
            $body .= "<p style='color:#3D3630;line-height:1.7;'>" . esc_html( $intro ) . "</p>";
            $body .= $N::email_card( 'Your links',
                $N::email_detail_row( 'Gallery', esc_html( $gallery ) ) .
                $N::email_detail_row( 'Guest upload page', esc_html( $upload ) )
            );
            $body .= "<div style='text-align:center;margin:22px 0 6px;'>" . $N::email_button( $gallery, 'View your gallery', true ) . "</div>";
            $body .= "<div style='text-align:center;margin:0 0 18px;'>" . $N::email_button( $upload, 'Open the guest upload page', false ) . "</div>";
            $body .= "<p style='color:#3D3630;line-height:1.7;'>Open the gallery to print your QR code and place it on tables or signage on the day.</p>";
            $body .= "<p style='color:#3D3630;margin-top:20px;'>Warm regards,<br><strong>The Tweller Studios Team</strong></p>";
            return $N::send_raw( $email, $subject, $body );
        }

        $msg = "Your event gallery is ready!\n\n" . $title . "\n\n"
            . "Gallery: $gallery\nGuest upload link (put the QR on your tables): $upload\n\n"
            . "Guests just scan and upload — no app, no sign-up.\n\n— Tweller Studios";
        return wp_mail( $email, $subject, $msg );
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

/**
 * Plain-text event title.
 *
 * get_the_title() runs wptexturize/convert_chars, which turn "&" into the
 * numeric entity "&#038;" and straight quotes into curly entities. When that
 * output is escaped a second time — by esc_html() for HTML, or by the JS esc()
 * helper after JSON transport — the entity shows up literally on screen
 * ("Sam &#038; Dave"). Decoding here yields clean UTF-8 that each consumer can
 * escape exactly once.
 */
if ( ! function_exists( 'tepe_title' ) ) {
    function tepe_title( $post ) {
        return html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
    }
}
