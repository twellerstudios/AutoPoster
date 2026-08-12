<?php
/**
 * Tweller Flow — Client Dashboard
 *
 * The page a signed-in client (or gallery guest) lands on from the "My
 * Gallery" / "My Account" nav link. Shows their own bookings — package,
 * date, stage, payment/balance, a button into their gallery — and every
 * gallery they've liked photos in, across sessions. Nothing here is
 * fetched by anyone else's account: both lists are scoped to the current
 * WordPress user, resolved by TwellerFlow2_Client_Account.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Client_Dashboard {

    const SHORTCODE  = 'tweller_client_dashboard';
    const PAGE_SLUG  = 'my-account';

    public static function init() {
        add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
    }

    /**
     * Auto-create the page once, same pattern as the tracker/culling pages
     * — reuse an existing page carrying the shortcode if one already
     * exists, otherwise create it and remember the URL.
     */
    public static function ensure_page() {
        $existing_url = get_option( TwellerFlow2_Client_Account::OPT_PAGE, '' );
        if ( $existing_url ) {
            $page_id = url_to_postid( $existing_url );
            if ( $page_id && get_post_status( $page_id ) === 'publish' ) return;
        }

        $existing = get_posts( array(
            'post_type'   => 'page',
            'post_status' => 'publish',
            's'           => '[' . self::SHORTCODE . ']',
            'numberposts' => 1,
        ) );
        if ( ! empty( $existing ) ) {
            update_option( TwellerFlow2_Client_Account::OPT_PAGE, get_permalink( $existing[0]->ID ) );
            return;
        }

        $page_id = wp_insert_post( array(
            'post_title'   => 'My Account',
            'post_name'    => self::PAGE_SLUG,
            'post_content' => '[' . self::SHORTCODE . ']',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ) );
        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( TwellerFlow2_Client_Account::OPT_PAGE, get_permalink( $page_id ) );
        }
    }

    public static function render( $atts ) {
        unset( $atts );

        if ( ! is_user_logged_in() || ! TwellerFlow2_Client_Account::is_client_user() ) {
            return self::render_signed_out();
        }

        $user     = wp_get_current_user();
        $sessions = TwellerFlow2_Client_Account::current_client_sessions();

        // Sessions already have their own gallery card below — don't also
        // list them a second time under "liked photos in". Shared with the
        // nav-menu filter's link-vs-dashboard decision so the two can't
        // disagree about what counts as "extra".
        $liked = TwellerFlow2_Client_Account::liked_galleries_excluding_own_sessions( $sessions );

        ob_start();
        ?>
        <div class="tf2-dash">
            <div class="tf2-dash__head">
                <h1 class="tf2-dash__title">Welcome<?php echo $user->display_name ? ', ' . esc_html( explode( ' ', $user->display_name )[0] ) : ''; ?></h1>
                <a class="tf2-dash__logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Sign out</a>
            </div>

            <?php if ( empty( $sessions ) && empty( $liked ) ) : ?>
                <div class="tf2-dash__empty">
                    <p>Nothing here yet. Once your session is booked or you've liked a few photos in a gallery, they'll show up on this page.</p>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $sessions ) ) : ?>
                <h2 class="tf2-dash__section">Your Sessions</h2>
                <div class="tf2-dash__grid">
                    <?php foreach ( $sessions as $session ) : echo self::session_card( $session ); endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $liked ) ) : ?>
                <h2 class="tf2-dash__section">Galleries You've Liked Photos In</h2>
                <div class="tf2-dash__grid">
                    <?php foreach ( $liked as $row ) : echo self::liked_card( $row ); endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function session_card( $session ) {
        $stages = get_option( 'tweller_flow_2_stages', array() );
        $label  = isset( $stages[ $session->current_stage ]['client_label'] )
            ? $stages[ $session->current_stage ]['client_label']
            : ucfirst( $session->current_stage );

        $packages = get_option( 'tweller_flow_2_packages', array() );
        $pkg_name = isset( $packages[ $session->package_type ]['name'] )
            ? $packages[ $session->package_type ]['name']
            : ucfirst( $session->package_type );

        $total   = (float) $session->total_amount;
        $paid    = (float) $session->deposit_amount;
        $balance = max( 0, round( $total - $paid, 2 ) );
        $gallery_ready = in_array( $session->current_stage, array( 'uploaded', 'delivered' ), true );

        $url = TwellerFlow2_Notifications::get_tracker_url( $session->tracking_code );

        ob_start();
        ?>
        <div class="tf2-dash__card">
            <div class="tf2-dash__card-top">
                <span class="tf2-dash__pkg"><?php echo esc_html( $pkg_name ); ?></span>
                <span class="tf2-dash__stage"><?php echo esc_html( $label ); ?></span>
            </div>
            <?php if ( $session->session_date ) : ?>
                <div class="tf2-dash__date"><?php echo esc_html( date( 'F j, Y', strtotime( $session->session_date ) ) ); ?></div>
            <?php endif; ?>
            <?php if ( $total > 0 ) : ?>
                <div class="tf2-dash__money">
                    <span><?php echo $balance > 0 ? 'Balance due' : 'Paid in full'; ?></span>
                    <strong><?php echo $balance > 0 ? esc_html( 'TT$' . number_format( $balance, 2 ) ) : ''; ?></strong>
                </div>
            <?php endif; ?>
            <a class="tf2-dash__btn<?php echo $gallery_ready ? ' tf2-dash__btn--primary' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
                <?php echo $gallery_ready ? 'View My Gallery' : 'View My Session'; ?>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function liked_card( $row ) {
        $url = TwellerFlow2_Notifications::get_tracker_url( $row['tracking_code'] ) . '&view=liked';
        ob_start();
        ?>
        <div class="tf2-dash__card">
            <div class="tf2-dash__card-top">
                <span class="tf2-dash__pkg"><?php echo esc_html( $row['client_name'] ); ?></span>
            </div>
            <div class="tf2-dash__money">
                <span><?php echo (int) $row['like_count']; ?> photo<?php echo (int) $row['like_count'] === 1 ? '' : 's'; ?> liked</span>
            </div>
            <a class="tf2-dash__btn" href="<?php echo esc_url( $url ); ?>">View Liked Photos</a>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_signed_out() {
        ob_start();
        ?>
        <div class="tf2-dash tf2-dash--signedout">
            <div class="tf2-dash__empty">
                <p>This page is just for signed-in clients. Open your gallery or booking link from your confirmation email, and you'll be recognised automatically here on your next visit.</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
