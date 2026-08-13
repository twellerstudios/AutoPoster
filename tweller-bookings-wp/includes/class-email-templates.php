<?php
/**
 * Tweller Flow — Email Templates & Studio Copy
 *
 * Two jobs, both driven from the admin "Emails" screen:
 *
 *   1. STUDIO COPY. Every email the plugin sends funnels through
 *      TwellerFlow2_Notifications::send_raw(); this class supplies the Cc/Bcc
 *      header lines it adds there, so the studio gets a copy of everything.
 *      Defaults: Cc hello@twellerstudios.com, Bcc stephen.twellerstudios@gmail.com.
 *
 *   2. EDITABLE TEMPLATES. Each client email has a default subject + body
 *      baked into the plugin. The studio can override either from the Emails
 *      screen; the override is stored as text with {{merge_tags}} that get
 *      substituted per send. CRUCIALLY, until a template is actually
 *      customised, resolve() returns the code-supplied default byte-for-byte
 *      — so this layer changes nothing about existing emails on its own.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Email_Templates {

    const OPT_OVERRIDES = 'tweller_flow_2_email_overrides';
    const OPT_COPY      = 'tweller_flow_2_email_copy';

    const DEFAULT_CC  = 'hello@twellerstudios.com';
    const DEFAULT_BCC = 'stephen.twellerstudios@gmail.com';

    // ── Studio copy (Cc/Bcc on every plugin email) ─────────

    public static function copy_settings() {
        $saved = get_option( self::OPT_COPY, array() );
        if ( ! is_array( $saved ) ) $saved = array();
        return array(
            'enabled' => array_key_exists( 'enabled', $saved ) ? (bool) $saved['enabled'] : true,
            'cc'      => array_key_exists( 'cc', $saved )  ? (string) $saved['cc']  : self::DEFAULT_CC,
            'bcc'     => array_key_exists( 'bcc', $saved ) ? (string) $saved['bcc'] : self::DEFAULT_BCC,
        );
    }

    public static function save_copy_settings( $enabled, $cc, $bcc ) {
        update_option( self::OPT_COPY, array(
            'enabled' => (bool) $enabled,
            'cc'      => self::clean_addresses( $cc ),
            'bcc'     => self::clean_addresses( $bcc ),
        ) );
    }

    /** Keep only valid emails from a comma-separated list, re-joined. */
    private static function clean_addresses( $csv ) {
        $out = array();
        foreach ( preg_split( '/\s*,\s*/', (string) $csv ) as $addr ) {
            $addr = sanitize_email( trim( $addr ) );
            if ( $addr !== '' && is_email( $addr ) && ! in_array( $addr, $out, true ) ) {
                $out[] = $addr;
            }
        }
        return implode( ', ', $out );
    }

    /**
     * Cc/Bcc header lines to append to EVERY plugin email. $existing is the
     * list of addresses already on the message (its To + any extra direct
     * recipients), so the studio is never copied on an address that is
     * already a direct recipient.
     *
     * @param array $existing Direct recipient addresses.
     * @return array<int,string> Header lines, e.g. ['Cc: a@b', 'Bcc: c@d'].
     */
    public static function studio_headers( $existing = array() ) {
        $c = self::copy_settings();
        if ( ! $c['enabled'] ) return array();

        $seen = array();
        foreach ( (array) $existing as $addr ) {
            $addr = strtolower( trim( (string) $addr ) );
            if ( $addr !== '' ) $seen[ $addr ] = true;
        }

        $headers = array();
        foreach ( array( 'Cc' => $c['cc'], 'Bcc' => $c['bcc'] ) as $label => $csv ) {
            $addrs = array();
            foreach ( preg_split( '/\s*,\s*/', (string) $csv ) as $addr ) {
                $addr = trim( $addr );
                if ( $addr === '' || ! is_email( $addr ) ) continue;
                $key = strtolower( $addr );
                if ( isset( $seen[ $key ] ) ) continue; // already a recipient / already copied
                $seen[ $key ] = true;
                $addrs[] = $addr;
            }
            if ( $addrs ) $headers[] = $label . ': ' . implode( ', ', $addrs );
        }
        return $headers;
    }

    // ── Editable templates ─────────────────────────────────

    public static function overrides() {
        $o = get_option( self::OPT_OVERRIDES, array() );
        return is_array( $o ) ? $o : array();
    }

    public static function get_override( $key ) {
        $o = self::overrides();
        return ( isset( $o[ $key ] ) && is_array( $o[ $key ] ) ) ? $o[ $key ] : null;
    }

    public static function is_customised( $key ) {
        $ov = self::get_override( $key );
        return $ov && ! empty( $ov['enabled'] );
    }

    public static function save_override( $key, $subject, $body ) {
        if ( ! isset( self::registry()[ $key ] ) ) return false;
        $o = self::overrides();
        $o[ $key ] = array(
            'enabled' => true,
            'subject' => (string) $subject,
            'body'    => (string) $body,
        );
        update_option( self::OPT_OVERRIDES, $o );
        return true;
    }

    /** Reset a template to its built-in default (removes the override). */
    public static function reset_override( $key ) {
        $o = self::overrides();
        if ( isset( $o[ $key ] ) ) {
            unset( $o[ $key ] );
            update_option( self::OPT_OVERRIDES, $o );
        }
    }

    /**
     * Resolve an email's final subject + body.
     *
     * With no active override this returns ($default_subject, $default_body)
     * UNCHANGED — the exact strings the plugin's own code produced — so a
     * template nobody has touched behaves precisely as before. With an
     * override, the stored subject/body are used and their {{tokens}} are
     * filled from $tokens.
     *
     * @return array{0:string,1:string}
     */
    public static function resolve( $key, $default_subject, $default_body, $tokens = array() ) {
        $ov = self::get_override( $key );
        if ( ! $ov || empty( $ov['enabled'] ) ) {
            return array( $default_subject, $default_body );
        }
        return array(
            self::apply_tokens( isset( $ov['subject'] ) ? (string) $ov['subject'] : $default_subject, $tokens ),
            self::apply_tokens( isset( $ov['body'] ) ? (string) $ov['body'] : $default_body, $tokens ),
        );
    }

    public static function apply_tokens( $text, $tokens ) {
        foreach ( (array) $tokens as $k => $v ) {
            $text = str_replace( '{{' . $k . '}}', (string) $v, $text );
        }
        return $text;
    }

    /**
     * The merge-tag values for one session — plain values plus a few
     * pre-rendered branded blocks (buttons, cards) so an edited template can
     * still drop in a real gold CTA or the session-details panel without the
     * studio hand-writing email HTML.
     */
    public static function session_tokens( $session ) {
        $N = 'TwellerFlow2_Notifications';

        $packages = get_option( 'tweller_flow_2_packages', array() );
        $pkg      = isset( $packages[ $session->package_type ] ) ? $packages[ $session->package_type ] : array();
        $pkg_name = isset( $pkg['name'] ) ? $pkg['name'] : ucfirst( (string) $session->package_type );

        $tracker = $N::get_tracker_url( $session->tracking_code );
        $prints  = $tracker . ( strpos( $tracker, '?' ) !== false ? '&' : '?' ) . 'prints=1';
        $review  = get_option( 'tweller_flow_2_review_url', 'https://g.page/r/CbntSRvzXVrSEBM/review' );
        $banking = (string) get_option( 'tweller_flow_2_banking', '' );
        $first   = trim( explode( ' ', trim( (string) $session->client_name ) )[0] );

        $total   = (float) $session->total_amount;
        $paid    = (float) $session->deposit_amount;
        $balance = max( 0, round( $total - $paid, 2 ) );

        return array(
            'client_name'     => esc_html( $session->client_name ),
            'first_name'      => esc_html( $first ),
            'package'         => esc_html( $pkg_name ),
            'shoot_code'      => esc_html( $session->tracking_code ),
            'tracker_url'     => esc_url( $tracker ),
            'prints_url'      => esc_url( $prints ),
            'review_url'      => esc_url( $review ),
            'total'           => 'TTD $' . number_format( $total, 2 ),
            'deposit'         => 'TTD $' . number_format( $total / 2, 2 ),
            'balance'         => 'TTD $' . number_format( $balance, 2 ),
            'banking_details' => nl2br( esc_html( trim( $banking ) ) ),
            // Branded blocks (already HTML — do not escape when inserted)
            'portal_button'   => $N::email_button_row( $tracker, 'Open My Client Portal' ),
            'track_button'    => $N::email_button_row( $tracker, 'Track My Session' ),
            'gallery_button'  => $N::email_button_row( $tracker, 'View Your Album', true, 32 ),
            'prints_button'   => $N::email_button_row( $prints, 'Order Prints', true, 6 ),
            'review_button'   => $N::email_button_row( $review, 'Leave Us a Review', false, 6 ),
            'session_details' => $N::session_details_card( $session, $pkg_name ),
            'payment_summary' => $N::payment_summary_card( $session ),
        );
    }

    /**
     * Every editable template: its group, label, the merge tags it can use,
     * and its default subject + body (the starting point shown in the editor
     * and restored by "Reset to default"). Keys for lifecycle emails match
     * the pipeline stage so on_stage_change can look them up directly.
     */
    public static function registry() {
        $common = array( 'client_name', 'first_name', 'package', 'shoot_code', 'tracker_url' );

        return array(
            'booked' => array(
                'group'   => 'Client — booking',
                'label'   => 'Booking welcome (spot reserved)',
                'desc'    => 'Sent the moment a session is booked: warm welcome, session summary and the deposit instructions.',
                'tokens'  => array_merge( $common, array( 'deposit', 'banking_details', 'session_details', 'portal_button' ) ),
                'subject' => "Thanks for booking, {{first_name}} — we've reserved your spot ✨",
                'body'    => "<h2>Your spot is reserved</h2>\n"
                    . "<p>Hi {{client_name}},</p>\n"
                    . "<p>Thank you for choosing Tweller Studios — we're delighted to be capturing this for you. Your <strong>{{package}}</strong> has been reserved, and a tentative calendar hold is attached to this email.</p>\n"
                    . "{{session_details}}\n"
                    . "<p>To confirm your session, kindly make a deposit of <strong>{{deposit}}</strong> (50%) by bank transfer:</p>\n"
                    . "<p>{{banking_details}}</p>\n"
                    . "<p>Then upload a screenshot of your receipt through your client portal — we'll verify it and confirm your booking right away. Until then, your date is held for you.</p>\n"
                    . "{{portal_button}}\n"
                    . "<p style='text-align:center;'>Your shoot code: <strong>{{shoot_code}}</strong></p>\n"
                    . "<p>Warm regards,<br><strong>The Tweller Studios Team</strong></p>",
            ),
            'confirmed' => array(
                'group'   => 'Client — booking',
                'label'   => 'Booking confirmed (payment verified)',
                'desc'    => 'Sent when the deposit is verified and the session is officially confirmed. The confirmed calendar invite is attached.',
                'tokens'  => array_merge( $common, array( 'session_details', 'payment_summary', 'track_button' ) ),
                'subject' => "You're all set, {{first_name}} — your booking is confirmed 🎉",
                'body'    => "<h2>Your booking is confirmed</h2>\n"
                    . "<p>Hi {{client_name}},</p>\n"
                    . "<p>Lovely news — we've verified your payment and your session is now <strong>officially confirmed</strong>. Thank you for trusting Tweller Studios.</p>\n"
                    . "{{session_details}}\n"
                    . "{{payment_summary}}\n"
                    . "<p>The confirmed calendar event is attached — if you use Gmail or Apple Calendar it will update automatically.</p>\n"
                    . "{{track_button}}\n"
                    . "<p style='text-align:center;'>Your shoot code: <strong>{{shoot_code}}</strong></p>\n"
                    . "<p>Warm regards,<br><strong>The Tweller Studios Team</strong></p>",
            ),
            'edited' => array(
                'group'   => 'Client — gallery',
                'label'   => 'Editing complete (almost ready)',
                'desc'    => 'A short heads-up that editing is done and the gallery is coming soon.',
                'tokens'  => array_merge( $common, array( 'track_button' ) ),
                'subject' => "Your photos are almost ready, {{first_name}}",
                'body'    => "<h2>Editing complete</h2>\n"
                    . "<p>Hi {{client_name}},</p>\n"
                    . "<p>Wonderful news — we've finished editing your photos and your gallery will be ready very soon. We can't wait for you to see them.</p>\n"
                    . "{{track_button}}",
            ),
            'delivered' => array(
                'group'   => 'Client — gallery',
                'label'   => 'Gallery delivered (photos ready)',
                'desc'    => 'The big one: the client\'s gallery is ready to view and download, with prints and review prompts.',
                'tokens'  => array_merge( $common, array( 'gallery_button', 'prints_button', 'review_button' ) ),
                'subject' => "Your gallery is ready, {{first_name}} — {{package}}",
                'body'    => "<h2>Your photos have arrived</h2>\n"
                    . "<p>Hi {{client_name}},</p>\n"
                    . "<p>The moment you've been waiting for — your <strong>{{package}}</strong> photos are ready to view and download.</p>\n"
                    . "{{gallery_button}}\n"
                    . "<p>Love them in print? Turn your favourites into professional prints, canvases and photobooks:</p>\n"
                    . "{{prints_button}}\n"
                    . "<p>If you enjoyed your experience, a review would mean the world to us:</p>\n"
                    . "{{review_button}}\n"
                    . "<p style='text-align:center;'>Shoot code: <strong>{{shoot_code}}</strong></p>",
            ),
            'receipt_rejected' => array(
                'group'   => 'Client — booking',
                'label'   => 'Receipt could not be verified',
                'desc'    => 'Asks the client to re-upload their bank-transfer receipt when the first one could not be verified.',
                'tokens'  => array_merge( $common, array( 'portal_button' ) ),
                'subject' => "Quick fix needed — we couldn't verify your receipt",
                'body'    => "<h2>We couldn't verify your receipt</h2>\n"
                    . "<p>Hi {{client_name}},</p>\n"
                    . "<p>We ran into a problem verifying the bank transfer receipt you uploaded — it may have been the wrong image, or the amount didn't match. No worries at all; these things happen.</p>\n"
                    . "<p>Please re-upload the correct receipt through your client portal and we'll take another look right away. Your date is still being held for you.</p>\n"
                    . "{{portal_button}}\n"
                    . "<p>Warm regards,<br><strong>The Tweller Studios Team</strong></p>",
            ),
        );
    }

    public static function registry_entry( $key ) {
        $r = self::registry();
        return isset( $r[ $key ] ) ? $r[ $key ] : null;
    }

    /**
     * The subject/body to show in the editor for a template: the studio's
     * saved override if there is one, otherwise the built-in default.
     */
    public static function editable( $key ) {
        $entry = self::registry_entry( $key );
        if ( ! $entry ) return null;
        $ov = self::get_override( $key );
        return array(
            'subject' => ( $ov && isset( $ov['subject'] ) ) ? (string) $ov['subject'] : $entry['subject'],
            'body'    => ( $ov && isset( $ov['body'] ) ) ? (string) $ov['body'] : $entry['body'],
        );
    }
}
