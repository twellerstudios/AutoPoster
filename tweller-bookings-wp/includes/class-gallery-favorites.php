<?php
/**
 * Tweller Flow — Gallery Visitors & Favorites (CloudSpot-style)
 *
 * Two jobs:
 *   1. A lightweight visitor identity. Anyone opening a delivered gallery
 *      gives their name + email once; we mint a token, hand it back, and the
 *      browser stores it so they are never asked again. No WordPress user,
 *      no password — just enough to know who is looking and who liked what.
 *   2. Favorites. An identified visitor can heart any photo; those hearts
 *      are theirs (keyed to their token), survive a return visit, drive a
 *      "Liked" tab, and can be downloaded on their own. The studio sees
 *      every visitor's favourites grouped in wp-admin, plus which photos are
 *      most-loved across everyone.
 *
 * Security model: the visitor token is the only credential. It is minted
 * server-side, never guessable, and every like / download call is checked
 * to belong to the gallery it targets. A photo can only be liked if it
 * really exists in that gallery.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Gallery_Favorites {

    const TABLE_VISITORS = 'tweller_gallery_visitors';
    const TABLE_LIKES    = 'tweller_gallery_likes';
    const DB_VERSION     = '1';
    const DB_OPTION      = 'tweller_flow_2_favorites_db';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    // ── Database ───────────────────────────────────────

    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $visitors = $wpdb->prefix . self::TABLE_VISITORS;
        $likes    = $wpdb->prefix . self::TABLE_LIKES;

        $sql_visitors = "CREATE TABLE $visitors (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            session_code varchar(120) NOT NULL,
            name varchar(150) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            token varchar(64) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            last_seen_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            UNIQUE KEY session_email (session_id, email),
            KEY session_id (session_id)
        ) $charset_collate;";

        $sql_likes = "CREATE TABLE $likes (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            visitor_id bigint(20) unsigned NOT NULL,
            photo_id bigint(20) unsigned NOT NULL,
            filename varchar(255) NOT NULL DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY visitor_photo (visitor_id, photo_id),
            KEY session_id (session_id),
            KEY photo_id (photo_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql_visitors );
        dbDelta( $sql_likes );
    }

    /**
     * Create the tables the first time this version loads, without needing a
     * plugin reactivation. The option check is a single autoloaded read, so
     * it costs effectively nothing on later requests — and because it runs
     * on any request (a visitor's included), the tables are guaranteed to
     * exist before the first like ever lands.
     */
    public static function maybe_create_tables() {
        if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) return;
        self::create_tables();
        update_option( self::DB_OPTION, self::DB_VERSION );
    }

    private static function visitors_table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_VISITORS;
    }

    private static function likes_table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_LIKES;
    }

    // ── REST ───────────────────────────────────────────

    public static function register_rest_routes() {
        $ns = 'tweller-flow-2/v1';

        // Register a new visitor (name+email) or resume an existing one (token).
        register_rest_route( $ns, '/gallery/(?P<code>[a-zA-Z0-9\-]+)/visitor', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_visitor' ),
            'permission_callback' => '__return_true',
        ) );

        // Toggle a like on one photo.
        register_rest_route( $ns, '/gallery/(?P<code>[a-zA-Z0-9\-]+)/like', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_like' ),
            'permission_callback' => '__return_true',
        ) );

        // Download only the visitor's liked photos as a zip.
        register_rest_route( $ns, '/gallery/(?P<code>[a-zA-Z0-9\-]+)/download-liked', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_download_liked' ),
            'permission_callback' => '__return_true',
        ) );

        // Admin: every visitor's favourites + the aggregate most-loved list.
        register_rest_route( $ns, '/gallery/(?P<code>[a-zA-Z0-9\-]+)/favorites', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_favorites' ),
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ) );
    }

    /** POST /gallery/{code}/visitor — register (name+email) or resume (token). */
    public static function rest_visitor( $request ) {
        self::maybe_create_tables();

        $session = self::gallery_session( $request['code'] );
        if ( is_wp_error( $session ) ) return $session;

        global $wpdb;
        $token = sanitize_text_field( (string) $request->get_param( 'token' ) );

        // Resume path — a returning visitor whose browser kept their token.
        if ( $token !== '' ) {
            $visitor = self::visitor_by_token( $token, $session->id );
            if ( $visitor ) {
                $wpdb->update( self::visitors_table(), array( 'last_seen_at' => current_time( 'mysql' ) ), array( 'id' => $visitor->id ) );
                return rest_ensure_response( self::visitor_payload( $visitor ) );
            }
            // Token didn't match this gallery — fall through to registration
            // if a name+email was also supplied, otherwise ask them to sign in.
        }

        $name  = sanitize_text_field( (string) $request->get_param( 'name' ) );
        $email = sanitize_email( (string) $request->get_param( 'email' ) );

        if ( $name === '' || ! is_email( $email ) ) {
            return new WP_Error( 'need_details', 'Please enter your name and a valid email address.', array( 'status' => 400 ) );
        }

        // One visitor per (gallery, email): a repeat sign-in with the same
        // email — even from a wiped browser — returns to the same favourites
        // rather than orphaning them under a second identity.
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::visitors_table() . " WHERE session_id = %d AND email = %s",
            $session->id, $email
        ) );

        if ( $existing ) {
            $wpdb->update( self::visitors_table(), array(
                'name'         => $name,
                'last_seen_at' => current_time( 'mysql' ),
            ), array( 'id' => $existing->id ) );
            $existing->name = $name;
            return rest_ensure_response( self::visitor_payload( $existing ) );
        }

        $new_token = self::make_token();
        $wpdb->insert( self::visitors_table(), array(
            'session_id'   => $session->id,
            'session_code' => $session->tracking_code,
            'name'         => $name,
            'email'        => $email,
            'token'        => $new_token,
            'created_at'   => current_time( 'mysql' ),
            'last_seen_at' => current_time( 'mysql' ),
        ) );
        $visitor = self::visitor_by_token( $new_token, $session->id );
        if ( ! $visitor ) {
            return new WP_Error( 'create_failed', 'Could not sign you in. Please try again.', array( 'status' => 500 ) );
        }

        // Log it alongside the existing gallery activity trail.
        if ( class_exists( 'TwellerFlow2_Client_Activity' ) ) {
            TwellerFlow2_Client_Activity::log( $session->id, $session->tracking_code, 'gallery_signin', $name . ' <' . $email . '>' );
        }

        return rest_ensure_response( self::visitor_payload( $visitor ) );
    }

    /** POST /gallery/{code}/like — body: token, photo_id, liked (0/1). */
    public static function rest_like( $request ) {
        self::maybe_create_tables();

        $session = self::gallery_session( $request['code'] );
        if ( is_wp_error( $session ) ) return $session;

        $visitor = self::visitor_by_token( sanitize_text_field( (string) $request->get_param( 'token' ) ), $session->id );
        if ( ! $visitor ) {
            return new WP_Error( 'not_signed_in', 'Please enter your name and email to like photos.', array( 'status' => 401 ) );
        }

        $photo_id = absint( $request->get_param( 'photo_id' ) );
        $liked    = self::truthy( $request->get_param( 'liked' ) );

        // The photo must really be in THIS gallery — never trust the id.
        $photo = self::gallery_photo( $session->id, $photo_id );
        if ( ! $photo ) {
            return new WP_Error( 'bad_photo', 'That photo is not part of this gallery.', array( 'status' => 400 ) );
        }

        global $wpdb;
        $likes = self::likes_table();

        if ( $liked ) {
            // INSERT IGNORE via the unique (visitor_id, photo_id) key — a
            // double-tap can never create two hearts.
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO $likes (session_id, visitor_id, photo_id, filename, created_at) VALUES (%d, %d, %d, %s, %s)",
                $session->id, $visitor->id, $photo_id, $photo->filename, current_time( 'mysql' )
            ) );
        } else {
            $wpdb->delete( $likes, array( 'visitor_id' => $visitor->id, 'photo_id' => $photo_id ) );
        }

        return rest_ensure_response( array(
            'ok'    => true,
            'liked' => $liked,
            'count' => self::visitor_like_count( $visitor->id ),
        ) );
    }

    /** GET /gallery/{code}/download-liked?token= — zip of just this visitor's likes. */
    public static function rest_download_liked( $request ) {
        self::maybe_create_tables();

        $session = self::gallery_session( $request['code'] );
        if ( is_wp_error( $session ) ) return $session;

        $visitor = self::visitor_by_token( sanitize_text_field( (string) $request->get_param( 'token' ) ), $session->id );
        if ( ! $visitor ) {
            return new WP_Error( 'not_signed_in', 'Please sign in to download your favourites.', array( 'status' => 401 ) );
        }

        $liked = self::visitor_liked_filenames( $session->id, $visitor->id );
        if ( empty( $liked ) ) {
            return new WP_Error( 'empty', 'You have not liked any photos yet.', array( 'status' => 404 ) );
        }

        $gallery_dir = TwellerFlow2_Gallery::get_gallery_dir( $session->tracking_code );
        $zip_name    = sanitize_file_name( $session->client_name . '-favourites' ) . '.zip';
        // Per-request build path. The old shared path was keyed only on the
        // client name, so two VISITORS of the same gallery downloading their
        // own favourites at once collided on one file.
        $zip_path    = TwellerFlow2_Gallery::temp_zip_path( $gallery_dir );

        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'no_zip', 'Downloads are unavailable on this server.', array( 'status' => 500 ) );
        }

        TwellerFlow2_Gallery::prepare_for_large_download();

        $zip = new ZipArchive();
        if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            return new WP_Error( 'zip_failed', 'Could not build your download. Please try again.', array( 'status' => 500 ) );
        }
        $added = 0;
        foreach ( $liked as $filename ) {
            $file_path = trailingslashit( $gallery_dir ) . $filename;
            if ( file_exists( $file_path ) ) { $zip->addFile( $file_path, $filename ); $added++; }
        }
        $zip->close();

        if ( $added === 0 ) {
            @unlink( $zip_path );
            return new WP_Error( 'empty', 'Your liked photos could not be found.', array( 'status' => 404 ) );
        }

        if ( class_exists( 'TwellerFlow2_Client_Activity' ) ) {
            TwellerFlow2_Client_Activity::log( $session->id, $session->tracking_code, 'liked_downloaded', $added . ' liked photos' );
        }

        TwellerFlow2_Gallery::stream_zip( $zip_path, $zip_name, $request->get_param( 'dl' ) );
    }

    /** GET /gallery/{code}/favorites — admin JSON (also powers the app later). */
    public static function rest_favorites( $request ) {
        $session = TwellerFlow2_Session::get_by_code( sanitize_text_field( $request['code'] ) );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Gallery not found.', array( 'status' => 404 ) );
        }
        return rest_ensure_response( array(
            'ok'        => true,
            'visitors'  => self::visitors_with_likes( $session ),
            'aggregate' => self::aggregate_likes( $session ),
            'totals'    => array(
                'visitors' => self::count_visitors( $session->id ),
                'likes'    => self::count_likes( $session->id ),
            ),
        ) );
    }

    // ── Backend query helpers (used by the admin view) ─

    /** Every visitor of a gallery, each with their liked photos (thumbs). */
    public static function visitors_with_likes( $session ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::visitors_table() . " WHERE session_id = %d ORDER BY last_seen_at DESC",
            $session->id
        ) );
        if ( ! is_array( $rows ) ) $rows = array();

        $gallery_url = TwellerFlow2_Gallery::get_gallery_url( $session->tracking_code );
        $likes_table = self::likes_table();
        $out = array();

        foreach ( $rows as $v ) {
            $likes = $wpdb->get_results( $wpdb->prepare(
                "SELECT photo_id, filename, created_at FROM $likes_table WHERE visitor_id = %d ORDER BY created_at ASC",
                $v->id
            ) );
            if ( ! is_array( $likes ) ) $likes = array();

            $photos = array();
            foreach ( $likes as $l ) {
                $photos[] = array(
                    'photo_id'  => (int) $l->photo_id,
                    'filename'  => $l->filename,
                    'thumb_url' => $gallery_url . '/thumbs/' . rawurlencode( $l->filename ),
                    'url'       => $gallery_url . '/' . rawurlencode( $l->filename ),
                );
            }

            $out[] = array(
                'id'         => (int) $v->id,
                'name'       => $v->name,
                'email'      => $v->email,
                'created_at' => $v->created_at,
                'last_seen'  => $v->last_seen_at,
                'like_count' => count( $photos ),
                'photos'     => $photos,
            );
        }
        return $out;
    }

    /**
     * Most-loved photos across every visitor, most likes first. This is the
     * "everyone starred these" view — the shortlist worth editing or pushing
     * for prints.
     */
    public static function aggregate_likes( $session ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT photo_id, filename, COUNT(*) AS likes FROM " . self::likes_table() . "
             WHERE session_id = %d GROUP BY photo_id, filename ORDER BY likes DESC, filename ASC",
            $session->id
        ) );
        if ( ! is_array( $rows ) ) $rows = array();

        $gallery_url = TwellerFlow2_Gallery::get_gallery_url( $session->tracking_code );
        $out = array();
        foreach ( $rows as $r ) {
            $out[] = array(
                'photo_id'  => (int) $r->photo_id,
                'filename'  => $r->filename,
                'likes'     => (int) $r->likes,
                'thumb_url' => $gallery_url . '/thumbs/' . rawurlencode( $r->filename ),
                'url'       => $gallery_url . '/' . rawurlencode( $r->filename ),
            );
        }
        return $out;
    }

    public static function count_visitors( $session_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::visitors_table() . " WHERE session_id = %d", $session_id
        ) );
    }

    public static function count_likes( $session_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::likes_table() . " WHERE session_id = %d", $session_id
        ) );
    }

    /**
     * Every visitor of a gallery as flat rows for CSV export — name, email,
     * when they first signed in, when last seen, and how many photos they
     * liked. No WordPress account is involved: this reads only the visitor
     * table the gallery sign-in already writes. Pass a session to scope to
     * one gallery, or 0 for every gallery across the studio.
     *
     * @param int $session_id  A session id, or 0 for all galleries.
     * @return array<int, array{name:string, email:string, session_code:string, client_name:string, created_at:string, last_seen_at:string, like_count:int}>
     */
    public static function export_visitor_rows( $session_id = 0 ) {
        global $wpdb;
        $visitors_table = self::visitors_table();
        $likes_table    = self::likes_table();
        $session_id     = (int) $session_id;

        if ( $session_id > 0 ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $visitors_table WHERE session_id = %d ORDER BY created_at ASC",
                $session_id
            ) );
        } else {
            $rows = $wpdb->get_results( "SELECT * FROM $visitors_table ORDER BY created_at ASC" );
        }
        if ( ! is_array( $rows ) ) return array();

        $out = array();
        foreach ( $rows as $v ) {
            $like_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $likes_table WHERE visitor_id = %d", $v->id
            ) );
            $session     = TwellerFlow2_Session::get( (int) $v->session_id );
            $client_name = $session ? $session->client_name : '';
            $out[] = array(
                'name'         => (string) $v->name,
                'email'        => (string) $v->email,
                'session_code' => (string) $v->session_code,
                'client_name'  => (string) $client_name,
                'created_at'   => (string) $v->created_at,
                'last_seen_at' => (string) $v->last_seen_at,
                'like_count'   => $like_count,
            );
        }
        return $out;
    }

    // ── Internals ──────────────────────────────────────

    /** Resolve a delivered/uploaded gallery session, or a WP_Error. */
    private static function gallery_session( $code ) {
        $session = TwellerFlow2_Session::get_by_code( sanitize_text_field( $code ) );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Gallery not found.', array( 'status' => 404 ) );
        }
        if ( ! in_array( $session->current_stage, array( 'uploaded', 'delivered' ), true ) ) {
            return new WP_Error( 'not_ready', 'This gallery is not ready yet.', array( 'status' => 403 ) );
        }
        return $session;
    }

    private static function visitor_by_token( $token, $session_id ) {
        $token = sanitize_text_field( (string) $token );
        if ( $token === '' ) return null;
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::visitors_table() . " WHERE token = %s AND session_id = %d",
            $token, $session_id
        ) );
    }

    private static function gallery_photo( $session_id, $photo_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'tweller_gallery_photos';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, filename FROM $table WHERE id = %d AND session_id = %d",
            $photo_id, $session_id
        ) );
    }

    private static function visitor_like_count( $visitor_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::likes_table() . " WHERE visitor_id = %d", $visitor_id
        ) );
    }

    /** Photo ids this visitor has liked — the list the gallery re-hydrates from. */
    private static function visitor_liked_ids( $visitor_id ) {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT photo_id FROM " . self::likes_table() . " WHERE visitor_id = %d", $visitor_id
        ) );
        return array_map( 'intval', (array) $ids );
    }

    private static function visitor_liked_filenames( $session_id, $visitor_id ) {
        global $wpdb;
        $names = $wpdb->get_col( $wpdb->prepare(
            "SELECT filename FROM " . self::likes_table() . " WHERE session_id = %d AND visitor_id = %d",
            $session_id, $visitor_id
        ) );
        return array_values( array_filter( (array) $names ) );
    }

    /** What the browser gets back after sign-in or resume. */
    private static function visitor_payload( $visitor ) {
        return array(
            'ok'    => true,
            'token' => $visitor->token,
            'name'  => $visitor->name,
            'email' => $visitor->email,
            'likes' => self::visitor_liked_ids( $visitor->id ),
        );
    }

    private static function make_token() {
        if ( function_exists( 'random_bytes' ) ) {
            try { return bin2hex( random_bytes( 16 ) ); } catch ( Exception $e ) {}
        }
        return md5( wp_generate_password( 32, true, true ) . microtime() );
    }

    private static function truthy( $v ) {
        if ( is_bool( $v ) ) return $v;
        $v = strtolower( (string) $v );
        return in_array( $v, array( '1', 'true', 'yes', 'on' ), true );
    }
}
