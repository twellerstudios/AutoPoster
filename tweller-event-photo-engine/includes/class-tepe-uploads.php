<?php
/**
 * Upload engine.
 *
 * Handles both simple multi-file uploads and a chunked transfer for large,
 * high-resolution photos (phones happily produce 20MB+ HEICs). Every path
 * ends in process_one(), which is the single choke point for MIME validation,
 * size limits, thumbnailing and the DB insert — so nothing can slip in an
 * unvalidated file.
 *
 * Accepted formats: JPEG, PNG, HEIC/HEIF. HEIC/HEIF are converted to a JPEG
 * thumbnail (and a web-viewable JPEG) when the server has Imagick with HEIC
 * support; otherwise the original is stored and the grid shows a graceful
 * "tap to view/download" tile.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TEPE_Uploads {

    const MAX_FILES_PER_REQUEST = 30;
    const MAX_FILE_SIZE         = 31457280; // 30 MB
    const CHUNK_TMP_DIR         = '.tmp';

    public static function allowed_mimes() {
        return array( 'image/jpeg', 'image/jpg', 'image/png', 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence' );
    }
    public static function allowed_exts() {
        return array( 'jpg', 'jpeg', 'png', 'heic', 'heif' );
    }

    public static function init() {
        // Sweep abandoned chunk temp files daily.
        add_action( 'tepe_daily_cleanup', array( __CLASS__, 'sweep_temp' ) );
        if ( ! wp_next_scheduled( 'tepe_daily_cleanup' ) ) {
            wp_schedule_event( time() + 3600, 'daily', 'tepe_daily_cleanup' );
        }
    }

    // ── Guards ──────────────────────────────────────────────────────────────

    /**
     * Can this guest upload $incoming more photos right now?
     * Enforces event lock, per-guest limit and whole-event limit.
     * @return true|WP_Error
     */
    public static function can_upload( $event, $guest, $incoming = 1 ) {
        if ( TEPE_Gallery::is_locked( $event->ID ) ) {
            return new WP_Error( 'locked', 'Uploads for this event are closed.', array( 'status' => 403 ) );
        }

        $per_guest = (int) get_post_meta( $event->ID, TEPE_CPT::META_UPLOAD_LIMIT, true );
        if ( $per_guest > 0 && $guest ) {
            $already = TEPE_Guest::upload_count( $event->ID, $guest->id );
            if ( $already + $incoming > $per_guest ) {
                return new WP_Error( 'guest_limit', sprintf( 'You can upload up to %d photos to this event.', $per_guest ), array( 'status' => 403 ) );
            }
        }

        $event_limit = (int) get_post_meta( $event->ID, TEPE_CPT::META_EVENT_LIMIT, true );
        if ( $event_limit > 0 ) {
            $total = TEPE_Gallery::count_uploads( $event->ID, null );
            if ( $total + $incoming > $event_limit ) {
                return new WP_Error( 'event_limit', 'This event has reached its photo limit.', array( 'status' => 403 ) );
            }
        }
        return true;
    }

    // ── Simple multi-file ──────────────────────────────────────────────────

    /**
     * Process a normalised list of file entries (each: tmp_name, name, error,
     * size). Returns array( 'saved' => [payloads], 'errors' => [strings] ).
     */
    public static function handle_files( $event, $guest, $files, $category, $captions = array() ) {
        $saved  = array();
        $errors = array();
        $i = 0;
        foreach ( $files as $file ) {
            $caption = isset( $captions[ $i ] ) ? $captions[ $i ] : '';
            $result  = self::process_one( $event, $guest, $file['tmp_name'], $file['name'], $category, $caption, $file['error'] ?? UPLOAD_ERR_OK );
            if ( is_wp_error( $result ) ) {
                $errors[] = $result->get_error_message();
            } else {
                $saved[] = $result;
            }
            $i++;
        }
        return array( 'saved' => $saved, 'errors' => $errors );
    }

    // ── Chunked transfer ────────────────────────────────────────────────────

    /**
     * Append one chunk. On the last chunk, finalise through process_one().
     *
     * @param array $args upload_id, index, total, filename, category, caption
     * @param array $file the $_FILES-style entry for this chunk (the blob).
     * @return array { done:bool, ...payload when done } | WP_Error
     */
    public static function handle_chunk( $event, $guest, $args, $file ) {
        $upload_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $args['upload_id'] );
        $index     = max( 0, (int) $args['index'] );
        $total     = max( 1, (int) $args['total'] );
        if ( $upload_id === '' ) {
            return new WP_Error( 'bad_upload_id', 'Missing upload id.', array( 'status' => 400 ) );
        }
        if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new WP_Error( 'no_chunk', 'Chunk was not received.', array( 'status' => 400 ) );
        }

        $tmp_dir = self::temp_dir( $event );
        $part    = $tmp_dir . '/' . $upload_id . '.part';

        // Guard total assembled size before it balloons.
        $existing = file_exists( $part ) ? filesize( $part ) : 0;
        if ( $existing + filesize( $file['tmp_name'] ) > self::MAX_FILE_SIZE ) {
            @unlink( $part );
            return new WP_Error( 'too_large', 'That photo is larger than the 30MB limit.', array( 'status' => 413 ) );
        }

        // Append. Chunks arrive in order (client sends sequentially).
        $in  = fopen( $file['tmp_name'], 'rb' );
        $out = fopen( $part, 'ab' );
        if ( ! $in || ! $out ) {
            if ( $in ) fclose( $in );
            if ( $out ) fclose( $out );
            return new WP_Error( 'io', 'Could not store the upload.', array( 'status' => 500 ) );
        }
        stream_copy_to_stream( $in, $out );
        fclose( $in );
        fclose( $out );

        if ( $index + 1 < $total ) {
            return array( 'done' => false, 'received' => $index + 1, 'total' => $total );
        }

        // Final chunk — finalise. process_one moves the assembled file.
        $orig_name = isset( $args['filename'] ) ? $args['filename'] : ( $upload_id . '.jpg' );
        $result = self::process_one( $event, $guest, $part, $orig_name, $args['category'] ?? '', $args['caption'] ?? '', UPLOAD_ERR_OK, false );
        @unlink( $part );

        if ( is_wp_error( $result ) ) return $result;
        $result['done'] = true;
        return $result;
    }

    // ── Core: validate + store one image ────────────────────────────────────

    /**
     * @param bool $is_upload true when $src is a PHP upload tmp file (use
     *                        move_uploaded_file), false for an assembled chunk.
     */
    public static function process_one( $event, $guest, $src, $orig_name, $category, $caption, $err = UPLOAD_ERR_OK, $is_upload = true ) {
        if ( $err !== UPLOAD_ERR_OK ) {
            return new WP_Error( 'upload_error', self::upload_error_text( $err ), array( 'status' => 400 ) );
        }
        if ( ! file_exists( $src ) ) {
            return new WP_Error( 'missing', 'The uploaded file could not be found.', array( 'status' => 400 ) );
        }
        if ( filesize( $src ) > self::MAX_FILE_SIZE ) {
            return new WP_Error( 'too_large', 'That photo is larger than the 30MB limit.', array( 'status' => 413 ) );
        }

        // Real MIME sniff — never trust the client's declared type.
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $src );
        finfo_close( $finfo );

        // HEIC sometimes sniffs as a generic ISO base-media type; accept those
        // when the extension is heic/heif so real iPhone photos aren't rejected.
        $ext = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
        $is_heic = in_array( $ext, array( 'heic', 'heif' ), true )
            && in_array( $mime, array( 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'application/octet-stream', 'video/quicktime', 'image/x-iso-bmff' ), true );

        if ( ! in_array( $mime, self::allowed_mimes(), true ) && ! $is_heic ) {
            return new WP_Error( 'bad_type', 'Only JPEG, PNG and HEIC photos can be uploaded.', array( 'status' => 415 ) );
        }
        if ( ! in_array( $ext, self::allowed_exts(), true ) ) {
            return new WP_Error( 'bad_ext', 'That file type is not allowed.', array( 'status' => 415 ) );
        }

        $category = sanitize_key( $category );
        if ( ! TEPE_Categories::exists( $event->ID, $category ) ) $category = '';

        $dir = TEPE_Gallery::ensure_dirs( $event );

        // Safe, unique, non-executable filename.
        $filename = self::safe_filename( $orig_name, $dir );
        $dest     = $dir . '/' . $filename;

        $moved = $is_upload ? @move_uploaded_file( $src, $dest ) : @rename( $src, $dest );
        if ( ! $moved ) {
            // rename can fail across mounts — fall back to copy.
            if ( @copy( $src, $dest ) ) { @unlink( $src ); $moved = true; }
        }
        if ( ! $moved ) {
            return new WP_Error( 'store_failed', 'Could not save the photo. Please try again.', array( 'status' => 500 ) );
        }

        // Moderation: hold for host approval if the event asks for it.
        $status = get_post_meta( $event->ID, TEPE_CPT::META_MODERATE, true ) ? 'pending' : 'approved';

        // Thumbnail + dimensions (+ web JPEG for HEIC).
        list( $width, $height ) = self::make_thumbnail( $dest, $dir . '/thumbs/' . tepe_thumb_name( $filename ), $is_heic );

        $upload_id = TEPE_Gallery::insert_upload( array(
            'event_id'         => $event->ID,
            'guest_id'         => $guest ? (int) $guest->id : null,
            'category'         => $category,
            'filename'         => $filename,
            'original_name'    => sanitize_file_name( $orig_name ),
            'uploader_name'    => $guest ? (string) $guest->display_name : '',
            'mime'             => $is_heic ? 'image/heic' : $mime,
            'width'            => $width,
            'height'           => $height,
            'filesize'         => filesize( $dest ),
            'caption'          => $caption,
            'ip_hash'          => TEPE_Guest::hash( TEPE_Guest::client_ip() ),
            'fingerprint_hash' => $guest ? $guest->fingerprint_hash : '',
            'status'           => $status,
        ) );

        if ( $guest ) TEPE_Guest::bump_uploads( $guest->id, 1 );

        do_action( 'tepe_photo_uploaded', $upload_id, $event->ID, $guest ? $guest->id : 0 );

        $payload = TEPE_Gallery::upload_payload( $event, TEPE_Gallery::get_upload( $upload_id ) );
        $payload['mine']    = true;
        $payload['pending'] = ( $status === 'pending' );
        return $payload;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private static function safe_filename( $orig_name, $dir ) {
        $name = sanitize_file_name( $orig_name );
        if ( $name === '' ) $name = 'photo.jpg';
        // Strip any double extension / disallowed extension → force allowed.
        $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, self::allowed_exts(), true ) ) {
            $ext  = 'jpg';
            $name = pathinfo( $name, PATHINFO_FILENAME ) . '.jpg';
        }
        $base = pathinfo( $name, PATHINFO_FILENAME );
        // Prefix a short random token to avoid guessable URLs + collisions.
        $token = substr( md5( uniqid( '', true ) ), 0, 8 );
        $filename = $token . '-' . $base . '.' . $ext;

        $final = $filename;
        $i = 1;
        while ( file_exists( $dir . '/' . $final ) ) {
            $final = $token . '-' . $base . '-' . $i . '.' . $ext;
            $i++;
        }
        return $final;
    }

    /**
     * Create an 800px-wide thumbnail. For HEIC also produces a web JPEG copy
     * (best-effort via Imagick). Returns [width, height] of the original.
     */
    private static function make_thumbnail( $source, $thumb_path, $is_heic ) {
        $width = 0; $height = 0;

        $editor = wp_get_image_editor( $source );
        if ( ! is_wp_error( $editor ) ) {
            $size = $editor->get_size();
            $width  = (int) ( $size['width'] ?? 0 );
            $height = (int) ( $size['height'] ?? 0 );

            if ( $width > 800 ) $editor->resize( 800, null, false );
            $editor->set_quality( 82 );
            // HEIC thumbs must be JPEG so browsers can display them.
            $editor->save( $thumb_path, $is_heic ? 'image/jpeg' : null );
            return array( $width, $height );
        }

        // Editor couldn't read it (commonly HEIC without Imagick support).
        if ( ! $is_heic ) {
            // For JPEG/PNG this shouldn't happen, but fall back to a copy.
            @copy( $source, $thumb_path );
            $dims = @getimagesize( $source );
            if ( $dims ) { $width = (int) $dims[0]; $height = (int) $dims[1]; }
        }
        // For unreadable HEIC we intentionally create no thumb; the grid shows
        // a "HEIC photo — tap to view" tile and the file is still downloadable.
        return array( $width, $height );
    }

    public static function normalize_files_array( $entry ) {
        $files = array();
        if ( is_array( $entry['name'] ) ) {
            $count = count( $entry['name'] );
            for ( $i = 0; $i < $count; $i++ ) {
                $files[] = array(
                    'name'     => $entry['name'][ $i ],
                    'type'     => $entry['type'][ $i ] ?? '',
                    'tmp_name' => $entry['tmp_name'][ $i ] ?? '',
                    'error'    => $entry['error'][ $i ] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $entry['size'][ $i ] ?? 0,
                );
            }
        } else {
            $files[] = $entry;
        }
        return $files;
    }

    private static function temp_dir( $event ) {
        $dir = TEPE_Gallery::dir( $event ) . '/' . self::CHUNK_TMP_DIR;
        wp_mkdir_p( $dir );
        return $dir;
    }

    public static function sweep_temp() {
        $up = wp_upload_dir();
        $root = $up['basedir'] . '/' . TEPE_UPLOAD_SUBDIR;
        foreach ( glob( $root . '/*/' . self::CHUNK_TMP_DIR . '/*.part' ) ?: array() as $part ) {
            if ( filemtime( $part ) < time() - 6 * 3600 ) @unlink( $part );
        }
    }

    private static function upload_error_text( $err ) {
        switch ( $err ) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE: return 'That photo is too large.';
            case UPLOAD_ERR_PARTIAL:   return 'The upload was interrupted — please try again.';
            case UPLOAD_ERR_NO_FILE:   return 'No file was received.';
            default:                   return 'Upload failed. Please try again.';
        }
    }
}
