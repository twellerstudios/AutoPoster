<?php
/**
 * Tweller Flow — Client Accounts
 *
 * A real (but powerless) WordPress login for the two kinds of people who
 * visit a gallery: the client who booked the session, and anyone else who
 * signed into a gallery via the name+email gate (class-gallery-favorites.php).
 *
 * Neither ever sets a password. Their "credential" is exactly what already
 * grants them access today — the client's unique tracking-code URL, or the
 * gallery's own name+email sign-in — so logging them into WordPress adds no
 * new way in. What it buys us: is_user_logged_in() becomes true for them
 * site-wide, so a single "My Gallery" link can appear in the nav menu for
 * the right person only, and a dashboard page can show them their own
 * bookings and the galleries they've liked photos in.
 *
 * The account can never reach wp-admin — see block_wp_admin() — and its
 * capability list is empty; it is not a "user" in the WordPress-admin
 * sense, just an identity WordPress happens to recognise between requests.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Client_Account {

    const ROLE          = 'tweller_client';
    const META_SESSIONS = 'tweller_client_sessions'; // array of session ids linked to this account
    const OPT_PAGE       = 'tweller_flow_2_client_dashboard_page';

    public static function init() {
        self::register_role();

        // The client, via their tracker/gallery link. This MUST run before
        // any output: login_as() sets an auth cookie, which needs the HTTP
        // headers still unsent. wp_head fires inside <head>, after the theme
        // has already emitted the doctype — on a host without output
        // buffering that flushes the headers, so login_as() silently bails
        // on headers_sent() and the cookie never lands (the login, and thus
        // the nav link, quietly never happens). template_redirect is the
        // last front-end hook before template output; headers still unsent.
        add_action( 'template_redirect', array( __CLASS__, 'maybe_login_from_tracker' ) );

        // Anyone who signs into a gallery (client or guest) — hooked from
        // class-gallery-favorites.php's rest_visitor(), not registered here,
        // to keep the favourites REST flow the single owner of that request.

        add_filter( 'wp_nav_menu_items', array( __CLASS__, 'filter_nav_menu_items' ), 10, 2 );
        add_action( 'admin_init', array( __CLASS__, 'block_wp_admin' ) );
        add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
    }

    // ── Role ───────────────────────────────────────────

    /**
     * Zero capabilities on purpose — this role can be logged in (WordPress
     * only needs a valid auth cookie for that) but can do nothing an
     * ordinary anonymous visitor couldn't already do through the plugin's
     * own REST routes. block_wp_admin() is the actual backstop.
     */
    public static function register_role() {
        if ( ! get_role( self::ROLE ) ) {
            add_role( self::ROLE, 'Studio Client', array() );
        }
    }

    // ── Provisioning ───────────────────────────────────

    /**
     * Find or create the WordPress account for an email address. One
     * account per email, reused across every session/gallery that email
     * ever touches — so a repeat client, or a guest who later becomes a
     * client, lands on the same account instead of a fresh disconnected one.
     *
     * @param string $email
     * @param string $name  Display name; only used when creating.
     * @return int|WP_Error User id, or an error if $email is unusable.
     */
    public static function get_or_create_user( $email, $name = '' ) {
        $email = sanitize_email( (string) $email );
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'bad_email', 'A valid email is required.' );
        }

        $existing = get_user_by( 'email', $email );
        if ( $existing ) return (int) $existing->ID;

        $login = self::unique_login_for( $email );
        $user_id = wp_insert_user( array(
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => wp_generate_password( 32, true, true ), // never used — no password login
            'display_name' => $name !== '' ? sanitize_text_field( $name ) : $email,
            'role'         => self::ROLE,
            'nickname'     => $name !== '' ? sanitize_text_field( $name ) : $email,
        ) );

        if ( is_wp_error( $user_id ) ) return $user_id;
        return (int) $user_id;
    }

    private static function unique_login_for( $email ) {
        $base = sanitize_user( current( explode( '@', $email ) ), true );
        if ( $base === '' ) $base = 'client';
        $login = $base;
        $i = 1;
        while ( username_exists( $login ) ) {
            $login = $base . $i;
            $i++;
        }
        return $login;
    }

    /**
     * Attach a session to this account (idempotent — a session already
     * linked is not duplicated). This is what the dashboard reads to know
     * which bookings belong to the signed-in visitor.
     */
    public static function link_session( $user_id, $session_id ) {
        $user_id = (int) $user_id;
        $session_id = (int) $session_id;
        if ( ! $user_id || ! $session_id ) return;

        $ids = get_user_meta( $user_id, self::META_SESSIONS, true );
        $ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
        if ( in_array( $session_id, $ids, true ) ) return;

        $ids[] = $session_id;
        update_user_meta( $user_id, self::META_SESSIONS, $ids );
    }

    /**
     * Log this browser in as $user_id, unless it already is. Guarded by
     * headers_sent() because this can run from a REST callback as well as a
     * normal page load — if the response has already started, setting an
     * auth cookie would silently fail (or throw a notice), so we skip
     * rather than error. The favourites feature this rides alongside keeps
     * working via its own token either way; only the site-wide nav/
     * dashboard link would be delayed to the next request.
     */
    public static function login_as( $user_id ) {
        $user_id = (int) $user_id;
        if ( ! $user_id ) return false;
        if ( get_current_user_id() === $user_id ) return true;
        if ( headers_sent() ) return false;

        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );
        return true;
    }

    // ── Current visitor ────────────────────────────────

    public static function is_client_user( $user = null ) {
        $user = $user ?: wp_get_current_user();
        return $user && $user->exists() && in_array( self::ROLE, (array) $user->roles, true );
    }

    /** Session objects linked to the currently logged-in client account. */
    public static function current_client_sessions() {
        if ( ! is_user_logged_in() || ! self::is_client_user() ) return array();

        $ids = get_user_meta( get_current_user_id(), self::META_SESSIONS, true );
        $ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
        if ( empty( $ids ) ) return array();

        $sessions = array();
        foreach ( $ids as $id ) {
            $s = TwellerFlow2_Session::get( $id );
            if ( $s ) $sessions[] = $s;
        }
        usort( $sessions, function ( $a, $b ) {
            return strcmp( (string) $b->created_at, (string) $a->created_at );
        } );
        return $sessions;
    }

    // ── Auto-login: the client, via their tracker link ──

    /**
     * Runs on template_redirect — the last front-end hook before any
     * template output, so the auth cookie login_as() sets still lands in
     * unsent headers. Only does anything when the URL carries a real
     * session code, which is exactly the bearer credential that already
     * fully unlocks that session's tracker page today — this does not
     * grant access to anything the code didn't already grant.
     */
    public static function maybe_login_from_tracker() {
        if ( is_admin() || empty( $_GET['code'] ) ) return;

        $code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session || empty( $session->client_email ) ) return;

        self::login_and_link( $session->client_email, $session->client_name, (int) $session->id );
    }

    /**
     * Shared by the tracker auto-login above and the gallery sign-in gate
     * (called from class-gallery-favorites.php): resolve-or-create the
     * account, log it in, and link a session if one applies.
     */
    public static function login_and_link( $email, $name, $session_id = 0 ) {
        $user_id = self::get_or_create_user( $email, $name );
        if ( is_wp_error( $user_id ) ) return;

        self::login_as( $user_id );
        if ( $session_id ) self::link_session( $user_id, $session_id );
    }

    // ── Nav menu link ──────────────────────────────────

    /**
     * Appends a personalised link to rendered nav menus for a logged-in
     * client account only — every other visitor (anonymous, or a real
     * wp-admin user browsing the site) sees no change at all. Scoped to a
     * configurable theme_location so it doesn't have to land in every menu
     * on the page (header AND footer); left blank in Settings, it applies
     * everywhere, which is a safe default for themes with a single nav.
     */
    public static function filter_nav_menu_items( $items, $args ) {
        if ( ! is_user_logged_in() || ! self::is_client_user() ) return $items;

        $target = get_option( 'tweller_flow_2_client_nav_location', '' );
        if ( $target !== '' && ( empty( $args->theme_location ) || $args->theme_location !== $target ) ) {
            return $items;
        }

        $sessions    = self::current_client_sessions();
        $extra_liked = self::liked_galleries_excluding_own_sessions( $sessions );

        // Exactly one thing to look at — their own session — even if they
        // also liked photos inside it: nothing else to disambiguate, so the
        // link goes straight there rather than via the dashboard.
        if ( count( $sessions ) === 1 && empty( $extra_liked ) ) {
            $label = 'My Gallery';
            $url   = TwellerFlow2_Notifications::get_tracker_url( $sessions[0]->tracking_code );
        } elseif ( count( $sessions ) >= 1 || ! empty( $extra_liked ) ) {
            $label = 'My Account';
            $url   = self::dashboard_url();
        } else {
            // Logged in (e.g. a guest who only liked photos, matched to no
            // session) but nothing to show yet — no link is more honest
            // than one that opens an empty dashboard.
            return $items;
        }

        $link = '<li class="menu-item tf2-client-nav-item"><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
        return $items . $link;
    }

    /**
     * Galleries the current user liked photos in, minus the sessions they
     * already own — used to decide whether there's anything for "My
     * Account" to add beyond what "My Gallery" already covers. Liking
     * photos in your own gallery is not a second destination.
     *
     * @param array $owned_sessions Session objects, as from current_client_sessions().
     * @return array
     */
    public static function liked_galleries_excluding_own_sessions( $owned_sessions ) {
        if ( ! class_exists( 'TwellerFlow2_Gallery_Favorites' ) ) return array();

        $email = wp_get_current_user()->user_email;
        $liked = TwellerFlow2_Gallery_Favorites::sessions_liked_by_email( $email );
        if ( empty( $liked ) ) return array();

        $owned_ids = array_map( function ( $s ) { return (int) $s->id; }, $owned_sessions );
        return array_values( array_filter( $liked, function ( $row ) use ( $owned_ids ) {
            return ! in_array( (int) $row['session_id'], $owned_ids, true );
        } ) );
    }

    // ── Dashboard page ─────────────────────────────────

    public static function dashboard_url() {
        $url = get_option( self::OPT_PAGE, '' );
        return $url ? $url : home_url( '/my-account/' );
    }

    // ── Lock the account out of wp-admin ───────────────

    public static function block_wp_admin() {
        if ( wp_doing_ajax() ) return;
        if ( ! is_user_logged_in() || ! self::is_client_user() ) return;

        wp_redirect( self::dashboard_url() );
        exit;
    }

    public static function hide_admin_bar( $show ) {
        if ( is_user_logged_in() && self::is_client_user() ) return false;
        return $show;
    }
}
