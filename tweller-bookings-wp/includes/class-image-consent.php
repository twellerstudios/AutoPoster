<?php
/**
 * Tweller Flow — Image-Use Consent & Client Credit
 *
 * At booking, a client chooses how their session photos may be used on the
 * studio's social media — a "privacy" question the studio asks up front:
 *
 *   limited   — we may post 1–5 images   (standard, no fee)
 *   declined  — please post none of them (+ privacy fee, default TT$50)
 *   unlimited — post as many as you like  (earns 50% off their NEXT session)
 *
 * Why a fee at all? The studio's social feed is how most clients discover
 * and book them; when a client asks for full privacy, that reach is lost,
 * and the fee reflects that. The reasoning is spelled out on the auto-built
 * "Why this fee?" page (the [tweller_privacy_fee] shortcode).
 *
 * Two client-level credits (keyed by email, so they follow the person across
 * sessions) back the incentives:
 *   - next-session discount: granted when a client picks "unlimited",
 *     auto-applied to the total of their next booking.
 *   - print credit: a dollar balance spendable in the print store, granted
 *     when a client who declined later changes their mind after delivery
 *     (handled in the post-delivery flow).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Image_Consent {

    const LIMITED   = 'limited';
    const DECLINED  = 'declined';
    const UNLIMITED = 'unlimited';

    const OPT_CONSENT_PREFIX = 'tweller_flow_2_consent_';       // per session id
    const OPT_CREDITS        = 'tweller_flow_2_client_credits'; // email => credit bucket
    const OPT_FEE            = 'tweller_flow_2_privacy_fee';
    const OPT_DISCOUNT_PCT   = 'tweller_flow_2_next_session_discount_pct';
    const OPT_PAGE           = 'tweller_flow_2_privacy_fee_page';
    const SHORTCODE          = 'tweller_privacy_fee';

    public static function init() {
        add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_why_page' ) );
    }

    public static function valid_choices() {
        return array( self::LIMITED, self::DECLINED, self::UNLIMITED );
    }

    public static function sanitize_choice( $choice ) {
        $choice = is_string( $choice ) ? strtolower( trim( $choice ) ) : '';
        return in_array( $choice, self::valid_choices(), true ) ? $choice : self::LIMITED;
    }

    // ── Config ─────────────────────────────────────────

    /** Privacy fee (TT$) added when a client declines all posting. */
    public static function privacy_fee() {
        return max( 0, (float) get_option( self::OPT_FEE, 50 ) );
    }

    /** Percentage off the next session for choosing "unlimited". */
    public static function next_discount_pct() {
        $pct = (float) get_option( self::OPT_DISCOUNT_PCT, 50 );
        return min( 100, max( 0, $pct ) );
    }

    public static function fee_for_choice( $choice ) {
        return self::sanitize_choice( $choice ) === self::DECLINED ? self::privacy_fee() : 0.0;
    }

    // ── Per-session consent ────────────────────────────

    public static function set( $session_id, $choice ) {
        $session_id = (int) $session_id;
        if ( ! $session_id ) return;
        update_option( self::OPT_CONSENT_PREFIX . $session_id, self::sanitize_choice( $choice ) );
    }

    public static function get( $session_id ) {
        $session_id = (int) $session_id;
        if ( ! $session_id ) return '';
        $v = get_option( self::OPT_CONSENT_PREFIX . $session_id, '' );
        return in_array( $v, self::valid_choices(), true ) ? $v : '';
    }

    /** May the studio post any images from this session? */
    public static function can_post( $session_id ) {
        return self::get( $session_id ) !== self::DECLINED && self::get( $session_id ) !== '';
    }

    public static function choice_label( $choice ) {
        switch ( self::sanitize_choice( $choice ) ) {
            case self::UNLIMITED: return 'May post unlimited images';
            case self::DECLINED:  return 'Do not post any images';
            default:              return 'May post 1–5 images';
        }
    }

    // ── Client credit (keyed by email) ─────────────────

    private static function all_credits() {
        $c = get_option( self::OPT_CREDITS, array() );
        return is_array( $c ) ? $c : array();
    }

    private static function key_for( $email ) {
        $email = sanitize_email( (string) $email );
        return is_email( $email ) ? strtolower( $email ) : '';
    }

    /** A client's credit bucket: next-session discount pending + print balance. */
    public static function credits( $email ) {
        $key = self::key_for( $email );
        $all = self::all_credits();
        $bucket = ( $key !== '' && isset( $all[ $key ] ) && is_array( $all[ $key ] ) ) ? $all[ $key ] : array();
        return array(
            'next_session_discount' => ! empty( $bucket['next_session_discount'] ),
            'print_credit'          => isset( $bucket['print_credit'] ) ? (float) $bucket['print_credit'] : 0.0,
        );
    }

    private static function save_bucket( $key, $bucket ) {
        $all = self::all_credits();
        $all[ $key ] = $bucket;
        update_option( self::OPT_CREDITS, $all );
    }

    public static function grant_next_session_discount( $email ) {
        $key = self::key_for( $email );
        if ( $key === '' ) return;
        $bucket = self::credits( $email );
        $bucket['next_session_discount'] = true;
        self::save_bucket( $key, $bucket );
    }

    public static function has_next_session_discount( $email ) {
        return self::credits( $email )['next_session_discount'];
    }

    public static function redeem_next_session_discount( $email ) {
        $key = self::key_for( $email );
        if ( $key === '' ) return;
        $bucket = self::credits( $email );
        $bucket['next_session_discount'] = false;
        self::save_bucket( $key, $bucket );
    }

    public static function print_credit_balance( $email ) {
        return self::credits( $email )['print_credit'];
    }

    public static function add_print_credit( $email, $amount ) {
        $key = self::key_for( $email );
        if ( $key === '' || (float) $amount <= 0 ) return;
        $bucket = self::credits( $email );
        $bucket['print_credit'] = round( $bucket['print_credit'] + (float) $amount, 2 );
        self::save_bucket( $key, $bucket );
    }

    /** Spend up to $amount of a client's print credit; returns the amount used. */
    public static function spend_print_credit( $email, $amount ) {
        $key = self::key_for( $email );
        if ( $key === '' || (float) $amount <= 0 ) return 0.0;
        $bucket = self::credits( $email );
        $use = min( $bucket['print_credit'], round( (float) $amount, 2 ) );
        if ( $use <= 0 ) return 0.0;
        $bucket['print_credit'] = round( $bucket['print_credit'] - $use, 2 );
        self::save_bucket( $key, $bucket );
        return $use;
    }

    // ── Booking-time application ───────────────────────

    /**
     * Compute a booking's total for a chosen consent option, folding in the
     * privacy fee and any next-session discount this email has banked. Does
     * NOT mutate anything — the caller records the choice and redeems the
     * discount once the session actually exists.
     *
     * @return array{ total:float, base:float, fee:float, discount:float, discount_applied:bool }
     */
    public static function price_booking( $base_price, $choice, $email ) {
        $base = (float) $base_price;
        $fee  = self::fee_for_choice( $choice );
        $subtotal = $base + $fee;

        $discount = 0.0;
        $applied  = false;
        if ( self::has_next_session_discount( $email ) ) {
            $discount = round( $subtotal * ( self::next_discount_pct() / 100 ), 2 );
            $applied  = true;
        }

        return array(
            'total'            => max( 0, round( $subtotal - $discount, 2 ) ),
            'base'             => $base,
            'fee'              => $fee,
            'discount'         => $discount,
            'discount_applied' => $applied,
        );
    }

    // ── "Why this fee?" page ───────────────────────────

    public static function why_url() {
        $url = get_option( self::OPT_PAGE, '' );
        return $url ? $url : home_url( '/why-this-fee/' );
    }

    public static function ensure_page() {
        $existing_url = get_option( self::OPT_PAGE, '' );
        if ( $existing_url && function_exists( 'url_to_postid' ) ) {
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
            update_option( self::OPT_PAGE, get_permalink( $existing[0]->ID ) );
            return;
        }

        $page_id = wp_insert_post( array(
            'post_title'   => 'Why This Fee',
            'post_name'    => 'why-this-fee',
            'post_content' => '[' . self::SHORTCODE . ']',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ) );
        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( self::OPT_PAGE, get_permalink( $page_id ) );
        }
    }

    public static function render_why_page( $atts = array() ) {
        unset( $atts );
        $fee = self::privacy_fee();
        $pct = self::next_discount_pct();
        $fee_str = 'TT$' . number_format( $fee, ( $fee == (int) $fee ) ? 0 : 2 );

        ob_start();
        ?>
        <div class="tf2-why" style="max-width:720px; margin:0 auto; padding:8px 4px; line-height:1.75; color:#3D3630; font-size:16px;">
            <h1 style="font-size:28px; color:#101010; margin:0 0 6px;">Why we ask about sharing your photos</h1>
            <p style="color:#8A8178; margin:0 0 24px;">A short, honest note on the image-use choice in your booking.</p>

            <p>When you book with Tweller Studios, we ask one simple question: <strong>may we share a few images from your session on our social media?</strong> We ask because it matters — to you and to us — and we want the choice to be entirely yours.</p>

            <h2 style="font-size:20px; color:#101010; margin:28px 0 8px;">Your privacy comes first</h2>
            <p>Some sessions are personal, and not everyone wants their images seen publicly. That is completely understandable, and we will always respect it. You are never obligated to let us post anything.</p>

            <h2 style="font-size:20px; color:#101010; margin:28px 0 8px;">Why sharing helps — and why there's a fee to opt out</h2>
            <p>Our social media is, quite simply, how most people find us. It is very likely how <em>you</em> came across our work and decided to book. Every image we're able to share keeps that feed alive and brings the next client to our door.</p>
            <p>When a client asks us to keep their session completely private, we fully respect it — but it also means we lose the ability to show that work. To balance that, sessions with <strong>no sharing at all</strong> carry a small <strong>privacy fee of <?php echo esc_html( $fee_str ); ?></strong>. Some studios call it exactly that — a privacy fee. It isn't a penalty; it simply reflects the value of the reach we set aside to honour your request.</p>

            <h2 style="font-size:20px; color:#101010; margin:28px 0 8px;">Your options</h2>
            <ul style="padding-left:20px; margin:0 0 8px;">
                <li style="margin:8px 0;"><strong>Allow 1–5 images</strong> — our standard. No fee. We may feature a small, tasteful selection.</li>
                <li style="margin:8px 0;"><strong>Let us share freely</strong> — a wonderful help to us, and our thank-you is <strong><?php echo esc_html( (int) $pct ); ?>% off your next session</strong>.</li>
                <li style="margin:8px 0;"><strong>Keep it private</strong> — no images shared, with the <?php echo esc_html( $fee_str ); ?> privacy fee.</li>
            </ul>

            <h2 style="font-size:20px; color:#101010; margin:28px 0 8px;">Changed your mind is always okay</h2>
            <p>If you chose privacy and later — once you've seen your gallery — feel happy to let us share after all, you can simply say so, and we'll thank you with studio credit toward prints. The decision stays yours, always.</p>

            <p style="margin-top:24px; color:#8A8178;">Questions? Reply to your booking email or write to <a href="mailto:hello@twellerstudios.com" style="color:#101010;">hello@twellerstudios.com</a> — we're happy to talk it through.</p>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── Admin badge ────────────────────────────────────

    /**
     * A very noticeable tag for admin session/gallery views showing whether
     * the studio may post from this album. Green = yes, amber for the
     * unlimited perk, red = do not post.
     */
    public static function badge_html( $session_id ) {
        $choice = self::get( $session_id );
        if ( $choice === '' ) return '';

        if ( $choice === self::DECLINED ) {
            $bg = '#FEE2E2'; $fg = '#991B1B'; $icon = '&#128683;'; $text = 'DO NOT POST';
        } elseif ( $choice === self::UNLIMITED ) {
            $bg = '#DCFCE7'; $fg = '#166534'; $icon = '&#128247;'; $text = 'OK TO POST · UNLIMITED';
        } else {
            $bg = '#DCFCE7'; $fg = '#166534'; $icon = '&#128247;'; $text = 'OK TO POST · 1–5';
        }

        return '<span class="tf2-consent-badge" style="display:inline-flex; align-items:center; gap:6px; background:' . $bg . '; color:' . $fg . '; font-weight:800; font-size:12px; letter-spacing:0.4px; padding:5px 11px; border-radius:100px; text-transform:uppercase;">'
            . '<span style="font-size:14px;">' . $icon . '</span>' . esc_html( $text ) . '</span>';
    }
}
