<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Notifications {

    /** Studio inbox that receives copies of important client emails */
    const STUDIO_EMAIL = 'hello@twellerstudios.com';

    /** Calendar inbox — receives ICS copies so events land on the studio calendar */
    const CALENDAR_EMAIL = 'stephen.twellerstudios@gmail.com';

    // ── Brand palette (black & gold, matching the booking site) ──
    const C_BLACK  = '#101010';
    const C_GOLD   = '#C9A227';
    const C_GOLD_L = '#E7C55C';
    const C_IVORY  = '#FAF9F6'; // page background
    const C_CARD   = '#FFFFFF'; // content card
    const C_BORDER = '#ECE9E2'; // hairline borders
    const C_TEXT   = '#3D3630';
    const C_MUTED  = '#8A8178';

    /**
     * System font stack used across all email markup.
     *
     * DELIBERATELY UNQUOTED. Every inline style in this plugin's emails is
     * written inside a single-quoted HTML attribute (style='…'), so a quoted
     * family name like 'Segoe UI' would close the attribute early and throw
     * the rest of the declaration away — which is exactly why CTA buttons
     * were arriving as plain blue links. Unquoted multi-word family names
     * are valid CSS, so this stack is safe in both quoting styles.
     */
    const FONT_STACK = "-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif";

    public static function configure_smtp( $phpmailer ) {
        $smtp = get_option( 'tweller_flow_2_smtp', array() );
        if ( empty( $smtp['username'] ) || empty( $smtp['password'] ) ) return;

        $phpmailer->isSMTP();
        $phpmailer->Host       = $smtp['host'] ?? 'smtp.zoho.com';
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Port       = $smtp['port'] ?? 465;
        $phpmailer->SMTPSecure = $smtp['encryption'] ?? 'ssl';
        $phpmailer->Username   = $smtp['username'];
        $phpmailer->Password   = $smtp['password'];
        $phpmailer->From       = $smtp['from_email'] ?? $smtp['username'];
        $phpmailer->FromName   = $smtp['from_name'] ?? 'Tweller Studios';
    }

    public static function on_stage_change( $session_id, $stage ) {
        $session = TwellerFlow2_Session::get( $session_id );
        if ( ! $session || empty( $session->client_email ) ) return;

        $template = self::get_email_template( $stage, $session );
        if ( ! $template ) return;

        $attachments = array();
        $extra_to    = array();

        // The single booking email carries the tentative calendar hold —
        // no separate "calendar invite" email is sent.
        if ( $stage === 'booked' && ! empty( $session->session_date ) && ! empty( $session->session_time ) ) {
            $ics = self::build_ics( $session, 'TENTATIVE', 0 );
            if ( $ics ) {
                $attachments[] = $ics;
                $extra_to[]    = self::CALENDAR_EMAIL; // so the hold lands on the studio calendar
            }
        }

        self::send_email( $session, $template['subject'], $template['body'], $attachments, $extra_to );

        foreach ( $attachments as $file ) {
            @unlink( $file );
        }
    }

    /**
     * Studio-facing "new booking" alert.
     *
     * Goes to the studio inbox(es) ONLY, never the client — sent through
     * send_raw() rather than send_email() so the client's address is never
     * prepended. This is the notification the owner used to rely on: the
     * booking create path emails the client and drops an ICS on the
     * calendar Gmail, but nothing ever told hello@ that a booking arrived.
     *
     * @param int $session_id
     * @return bool
     */
    public static function notify_studio_new_booking( $session_id ) {
        $session = TwellerFlow2_Session::get( $session_id );
        if ( ! $session ) return false;

        $recipients = array_values( array_unique( array_filter( array(
            get_option( 'admin_email' ),
            self::STUDIO_EMAIL,
        ) ) ) );
        if ( empty( $recipients ) ) return false;

        $packages    = get_option( 'tweller_flow_2_packages', array() );
        $pkg         = $packages[ $session->package_type ] ?? array();
        $pkg_name    = $pkg['name'] ?? ucfirst( $session->package_type );
        $review_link = admin_url( 'admin.php?page=tweller-flow-2-session&id=' . (int) $session->id );

        $rows  = self::email_detail_row( 'Client', esc_html( $session->client_name ) );
        if ( $session->client_email ) $rows .= self::email_detail_row( 'Email', esc_html( $session->client_email ) );
        if ( $session->client_phone ) $rows .= self::email_detail_row( 'Phone', esc_html( $session->client_phone ) );
        $rows .= self::email_detail_row( 'Package', esc_html( $pkg_name ) );
        if ( $session->session_date ) {
            $rows .= self::email_detail_row( 'Date', date( 'l, F j, Y', strtotime( $session->session_date ) ) );
        }
        if ( ! empty( $session->session_time ) ) {
            $rows .= self::email_detail_row( 'Time', date( 'g:i A', strtotime( $session->session_date . ' ' . $session->session_time ) ) );
        }
        if ( $session->location ) $rows .= self::email_detail_row( 'Location', esc_html( $session->location ) );
        if ( $session->total_amount > 0 ) {
            $rows .= self::email_detail_row( 'Total', 'TTD $' . number_format( $session->total_amount, 2 ) );
            $rows .= self::email_detail_row( 'Deposit to confirm (50%)', 'TTD $' . number_format( $session->total_amount / 2, 2 ) );
        }
        $rows .= self::email_detail_row( 'Shoot code', $session->tracking_code );

        $subject = "New booking — {$session->client_name}";
        $body = "
            <h2 style='color:" . self::C_BLACK . "; font-weight:600;'>A new booking just came in</h2>
            <p style='color:" . self::C_TEXT . "; line-height:1.7;'><strong>" . esc_html( $session->client_name ) . "</strong> just booked a session. Their welcome email with payment details has already gone out &mdash; they'll send a bank-transfer receipt or pay by card to confirm.</p>
            " . self::email_card( 'Booking Details', $rows ) . "
            " . self::email_button_row( $review_link, 'Open in Tweller Bookings Dashboard' ) . "
        ";

        return self::send_raw( $recipients, $subject, $body );
    }

    /**
     * Client email when a receipt could not be verified — asks them to
     * re-upload. Extracted from the admin handler so the app's reject
     * action sends the identical message.
     *
     * @param object $session
     * @return bool
     */
    public static function send_receipt_rejected_email( $session ) {
        if ( empty( $session->client_email ) ) return false;
        $tracker_url = self::get_tracker_url( $session->tracking_code );
        $body = "
            <h2 style='color:" . self::C_BLACK . "; font-weight:600;'>We couldn't verify your receipt</h2>
            <p style='color:" . self::C_TEXT . ";'>Hi {$session->client_name},</p>
            <p style='color:" . self::C_TEXT . "; line-height:1.7;'>We ran into a problem verifying the bank transfer receipt you uploaded &mdash; it may have been the wrong image, or the amount didn't match. No worries at all; these things happen.</p>
            <p style='color:" . self::C_TEXT . "; line-height:1.7;'>Please re-upload the correct receipt through your client portal and we'll take another look right away. Your date is still being held for you.</p>
            " . self::email_button_row( $tracker_url, 'Re-upload My Receipt' ) . "
            <p style='color:" . self::C_TEXT . ";'>Warm regards,<br><strong>The Tweller Studios Team</strong></p>";
        return self::send_email( $session, "Quick fix needed — we couldn't verify your receipt", $body );
    }

    public static function send_email( $session, $subject, $body, $attachments = array(), $extra_recipients = array() ) {
        $recipients = array_merge( array( $session->client_email ), $extra_recipients );
        $sent = self::send_raw( $recipients, $subject, $body, $attachments );

        self::log_notification( $session->id, $session->client_email, $subject, $body, $sent ? 'sent' : 'failed' );
        return $sent;
    }

    /** Low-level branded send: wraps $body in the black & gold template */
    public static function send_raw( $to, $subject, $body, $attachments = array() ) {
        add_action( 'phpmailer_init', array( __CLASS__, 'configure_smtp' ) );

        $headers   = array( 'Content-Type: text/html; charset=UTF-8' );
        $html_body = self::wrap_email_html( $body );
        $sent = wp_mail( $to, $subject, $html_body, $headers, $attachments );

        remove_action( 'phpmailer_init', array( __CLASS__, 'configure_smtp' ) );
        return $sent;
    }

    private static function log_notification( $session_id, $recipient, $subject, $body, $status ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_NOTIFICATIONS;
        $wpdb->insert( $table, array(
            'session_id' => $session_id,
            'type'       => 'email',
            'recipient'  => $recipient,
            'subject'    => $subject,
            'body'       => $body,
            'status'     => $status,
            'sent_at'    => current_time( 'mysql' ),
        ));
    }

    // ── Reusable branded building blocks ─────────────────

    /**
     * THE call-to-action button. This is the single button implementation in
     * the plugin — never hand-write an <a> for a CTA inside an email body.
     *
     * Bulletproof, in the boring email sense:
     *   • table-based (email clients ignore flex, and many strip <div> margins)
     *   • solid gold #C9A227 with near-black #101010 bold text for primary,
     *     a quiet white/outlined variant for secondary actions
     *   • padding baked onto the anchor AND the cell, so it still looks like a
     *     button when the anchor's padding is dropped
     *   • a real VML <v:roundrect> fallback for Outlook 2007–2019 (Word engine)
     *   • .tf2e-btn / .tf2e-btn-wrap classes so wrap_email_html()'s media query
     *     stretches it edge-to-edge on a phone
     *
     * @param string $url   Destination.
     * @param string $label Button text (plain text — it is escaped here).
     * @param bool   $solid true = primary gold, false = secondary outline.
     * @param string $align left|center|right (default centre).
     * @return string
     */
    public static function email_button( $url, $label, $solid = true, $align = 'center' ) {
        $url = esc_url( (string) $url );
        if ( $url === '' ) return '';

        $label = trim( wp_strip_all_tags( (string) $label ) );
        if ( $label === '' ) $label = 'Open';
        $label = esc_html( $label );

        $align = in_array( $align, array( 'left', 'center', 'right' ), true ) ? $align : 'center';
        $font  = self::FONT_STACK;

        if ( $solid ) {
            $bg     = self::C_GOLD;
            $stroke = self::C_GOLD;
            $weight = '700';
            $size   = '15px';
            $shadow = ' box-shadow:0 1px 2px rgba(16,16,16,0.16);';
        } else {
            $bg     = self::C_CARD;
            $stroke = '#D8D2C6';
            $weight = '600';
            $size   = '14px';
            $shadow = '';
        }
        $fg = self::C_BLACK;

        // Outlook needs a fixed pixel width; approximate from the label.
        $chars = function_exists( 'mb_strlen' )
            ? mb_strlen( html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ) )
            : strlen( $label );
        $vml_w = max( 190, min( 460, ( $chars * 9 ) + 70 ) );

        $vml = '<!--[if mso]>'
            . '<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"'
            . ' href="' . $url . '" style="height:48px;v-text-anchor:middle;width:' . (int) $vml_w . 'px;"'
            . ' arcsize="17%" stroke="t" strokecolor="' . $stroke . '" fillcolor="' . $bg . '">'
            . '<w:anchorlock/>'
            . '<center style="color:' . $fg . ';font-family:Arial,Helvetica,sans-serif;font-size:' . $size . ';font-weight:bold;">' . $label . '</center>'
            . '</v:roundrect>'
            . '<![endif]-->';

        $a_style = 'display:inline-block; padding:14px 28px; border-radius:8px;'
            . ' background:' . $bg . '; color:' . $fg . ';'
            . ' border:1px solid ' . $stroke . ';'
            . ' font-family:' . $font . '; font-size:' . $size . '; font-weight:' . $weight . ';'
            . ' text-decoration:none; text-align:center; letter-spacing:0.3px; line-height:1.25;'
            . ' mso-hide:all;' . $shadow;

        $margin = ( $align === 'center' ) ? 'margin:0 auto;' : 'margin:0;';

        return "
            <table role='presentation' class='tf2e-btn-wrap' border='0' cellpadding='0' cellspacing='0' align='{$align}' style='border-collapse:separate; {$margin}'>
                <tr>
                    <td class='tf2e-btn-cell' align='center' bgcolor='{$bg}' style='border-radius:8px; background:{$bg}; mso-padding-alt:0;'>
                        {$vml}
                        <!--[if !mso]><!-- -->
                        <a href='{$url}' class='tf2e-btn' style='{$a_style}'>{$label}</a>
                        <!--<![endif]-->
                    </td>
                </tr>
            </table>";
    }

    /**
     * A CTA button on its own centred row — the standard way to drop a primary
     * action between paragraphs of an email body.
     */
    public static function email_button_row( $url, $label, $solid = true, $margin = 30 ) {
        $button = self::email_button( $url, $label, $solid );
        if ( $button === '' ) return '';
        $margin = max( 0, (int) $margin );
        return "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0'><tr><td align='center' style='padding:{$margin}px 0;'>{$button}</td></tr></table>";
    }

    /**
     * Quiet section card: soft ivory panel, hairline border, small
     * uppercase letterspaced gray label. No accent bars.
     */
    public static function email_card( $title, $inner ) {
        return "
            <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='margin:24px 0; border-collapse:separate;'>
                <tr>
                    <td style='background:" . self::C_IVORY . "; border:1px solid " . self::C_BORDER . "; border-radius:12px; padding:22px 24px;'>
                        <p style='margin:0 0 14px; font-family:" . self::FONT_STACK . "; font-size:11px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:" . self::C_MUTED . ";'>{$title}</p>
                        {$inner}
                    </td>
                </tr>
            </table>";
    }

    /** Two-column label / value row for inside email cards */
    public static function email_detail_row( $label, $value ) {
        return "
            <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0'>
                <tr>
                    <td style='padding:5px 12px 5px 0; font-family:" . self::FONT_STACK . "; font-size:13px; color:" . self::C_MUTED . "; white-space:nowrap; vertical-align:top;'>{$label}</td>
                    <td align='right' style='padding:5px 0; font-family:" . self::FONT_STACK . "; font-size:13.5px; color:" . self::C_BLACK . "; font-weight:600; vertical-align:top;'>{$value}</td>
                </tr>
            </table>";
    }

    public static function session_details_card( $session, $pkg_name ) {
        $rows = self::email_detail_row( 'Package', $pkg_name );
        if ( $session->session_date ) {
            $rows .= self::email_detail_row( 'Date', date( 'l, F j, Y', strtotime( $session->session_date ) ) );
        }
        if ( ! empty( $session->session_time ) ) {
            $rows .= self::email_detail_row( 'Time', date( 'g:i A', strtotime( $session->session_date . ' ' . $session->session_time ) ) );
        }
        if ( $session->location ) {
            $rows .= self::email_detail_row( 'Location', esc_html( $session->location ) );
        }
        if ( $session->total_amount > 0 ) {
            $rows .= self::email_detail_row( 'Total', 'TTD $' . number_format( $session->total_amount, 2 ) );
        }
        return self::email_card( 'Your Session', $rows );
    }

    /**
     * Payment breakdown for the confirmation email: what we received and
     * what remains. Only rendered when there is a total and a balance
     * story worth telling — a fully-paid booking shows "Paid in full"
     * rather than a zero balance line.
     *
     * @param object $session
     * @return string Card HTML, or '' when there is nothing to say.
     */
    public static function payment_summary_card( $session ) {
        $total = (float) $session->total_amount;
        if ( $total <= 0 ) return '';

        $paid    = (float) $session->deposit_amount;
        $balance = max( 0, round( $total - $paid, 2 ) );

        $rows  = self::email_detail_row( 'Package total', 'TTD $' . number_format( $total, 2 ) );
        $rows .= self::email_detail_row( 'Received', 'TTD $' . number_format( $paid, 2 ) );

        if ( $balance <= 0 ) {
            $rows .= self::email_detail_row( 'Balance due', 'Paid in full — thank you!' );
            return self::email_card( 'Payment', $rows );
        }

        $rows .= self::email_detail_row( 'Balance due', 'TTD $' . number_format( $balance, 2 ) );
        $rows .= "<p style='margin:12px 0 0; font-family:" . self::FONT_STACK . "; font-size:13px; color:" . self::C_MUTED . "; line-height:1.6;'>The balance is due on the day of your session.</p>";

        return self::email_card( 'Payment', $rows );
    }

    public static function get_email_template( $stage, $session ) {
        $banking   = get_option( 'tweller_flow_2_banking', '' );
        $packages  = get_option( 'tweller_flow_2_packages', array() );
        $pkg       = $packages[ $session->package_type ] ?? array();
        $pkg_name  = $pkg['name'] ?? ucfirst( $session->package_type );
        $tracker_url = self::get_tracker_url( $session->tracking_code );
        $review_url  = get_option( 'tweller_flow_2_review_url', 'https://g.page/r/CbntSRvzXVrSEBM/review' );
        $first_name  = trim( explode( ' ', trim( $session->client_name ) )[0] );

        // Deep-link into the gallery's print store (prints.js auto-opens on ?prints=1)
        $prints_url = $tracker_url . ( strpos( $tracker_url, '?' ) !== false ? '&' : '?' ) . 'prints=1';

        $templates = array(
            // The one-and-only booking email: warm welcome + reserved spot +
            // payment details + tracker + calendar hold (ICS attached by caller).
            'booked' => array(
                'subject' => "Thanks for booking, {$first_name} — we've reserved your spot ✨",
                'body'    => "
                    <h2 style='color:" . self::C_BLACK . "; font-weight:600;'>Your spot is reserved</h2>
                    <p style='color:" . self::C_TEXT . ";'>Hi {$session->client_name},</p>
                    <p style='color:" . self::C_TEXT . "; line-height:1.7;'>Thank you for choosing Tweller Studios — we're delighted to be capturing this for you. Your <strong>{$pkg_name}</strong> has been reserved, and a tentative calendar hold is attached to this email. If you use Gmail or Apple Calendar, it will slide into your calendar automatically.</p>

                    " . self::session_details_card( $session, $pkg_name ) . "

                    " . self::email_card( 'Securing Your Booking', "
                        <p style='margin:6px 0; color:" . self::C_TEXT . "; line-height:1.7;'>To officially confirm your session, kindly make a deposit of <strong>TTD $" . number_format( $session->total_amount / 2, 2 ) . "</strong> (50%) by bank transfer. The remaining balance is due on the day of your session.</p>
                        <pre style='background:#fff; border:1px solid " . self::C_BORDER . "; padding:16px; border-radius:8px; white-space:pre-wrap; color:" . self::C_TEXT . "; font-size:14px; font-family:" . self::FONT_STACK . ";'>{$banking}</pre>
                        <p style='margin:6px 0; color:" . self::C_TEXT . "; line-height:1.7;'>Once you've made the transfer, simply upload a screenshot of your receipt through your client portal below — we'll verify it and confirm your booking right away. Until then, your date is held for you.</p>
                    " ) . "

                    " . self::email_button_row( $tracker_url, 'Open My Client Portal' ) . "

                    <p style='text-align:center; color:" . self::C_MUTED . "; font-size:13px;'>Your shoot code: <strong style='color:" . self::C_BLACK . ";'>{$session->tracking_code}</strong></p>

                    <p style='color:" . self::C_TEXT . "; line-height:1.7;'>If anything changes or you have questions, just reply to this email — we're happy to help.</p>
                    <p style='color:" . self::C_TEXT . ";'>Warm regards,<br><strong>The Tweller Studios Team</strong></p>
                ",
            ),

            'edited' => array(
                'subject' => "Your photos are almost ready, {$first_name}",
                'body'    => "
                    <h2 style='color:" . self::C_BLACK . "; font-weight:600;'>Editing complete</h2>
                    <p style='color:" . self::C_TEXT . ";'>Hi {$session->client_name},</p>
                    <p style='color:" . self::C_TEXT . "; line-height:1.7;'>Wonderful news — we've finished editing your photos and your gallery will be ready very soon. We can't wait for you to see them.</p>
                    " . self::email_button_row( $tracker_url, 'Track My Session' ) . "
                ",
            ),

            'delivered' => array(
                'subject' => "Your gallery is ready, {$first_name} — {$pkg_name}",
                'body'    => "
                    <h2 style='color:" . self::C_BLACK . "; font-weight:600;'>Your photos have arrived</h2>
                    <p style='color:" . self::C_TEXT . ";'>Hi {$session->client_name},</p>
                    <p style='color:" . self::C_TEXT . "; line-height:1.7;'>The moment you've been waiting for — your <strong>{$pkg_name}</strong> photos are ready to view and download.</p>

                    " . self::email_button_row( $tracker_url, 'View Your Album', true, 32 ) . "

                    " . self::email_card( 'Gallery Tips', "
                        <ul style='color:" . self::C_TEXT . "; margin:0; padding-left:20px; line-height:1.9;'>
                            <li>Browse your album with the full-screen slideshow</li>
                            <li>Download individual photos or the entire gallery</li>
                            <li>All images are high resolution and print-ready</li>
                        </ul>
                    " ) . "

                    <p style='color:" . self::C_TEXT . "; line-height:1.7;'>We hope you love your photos as much as we loved creating them with you.</p>

                    " . self::email_card( 'Love Them in Print?', "
                        <p style='margin:6px 0 14px; color:" . self::C_TEXT . "; line-height:1.7;'>Turn your favourites into professional prints, gallery canvases and layflat photobooks — order straight from your gallery and pick up or arrange delivery in Trinidad &amp; Tobago.</p>
                        " . self::email_button_row( $prints_url, 'Order Prints', true, 6 ) . "
                    " ) . "

                    " . self::email_card( 'Share the Love', "
                        <p style='margin:6px 0 14px; color:" . self::C_TEXT . ";'>If you enjoyed your experience, a review would mean the world to us.</p>
                        " . self::email_button_row( $review_url, 'Leave Us a Review', false, 6 ) . "
                    " ) . "

                    <p style='text-align:center; color:" . self::C_MUTED . "; font-size:12px; margin-top:24px;'>Shoot code: <strong style='color:" . self::C_BLACK . ";'>{$session->tracking_code}</strong></p>
                ",
            ),
        );

        return $templates[ $stage ] ?? null;
    }

    /**
     * The single "booking confirmed" email: payment verified + confirmed
     * calendar event attached. Replaces the old pair of emails.
     */
    public static function send_confirmed_email( $session ) {
        if ( empty( $session->client_email ) ) return false;

        $packages   = get_option( 'tweller_flow_2_packages', array() );
        $pkg        = $packages[ $session->package_type ] ?? array();
        $pkg_name   = $pkg['name'] ?? ucfirst( $session->package_type );
        $tracker_url = self::get_tracker_url( $session->tracking_code );
        $first_name  = trim( explode( ' ', trim( $session->client_name ) )[0] );

        $subject = "You're all set, {$first_name} — your booking is confirmed 🎉";
        $body = "
            <h2 style='color:" . self::C_BLACK . "; font-weight:600;'>Your booking is confirmed</h2>
            <p style='color:" . self::C_TEXT . ";'>Hi {$session->client_name},</p>
            <p style='color:" . self::C_TEXT . "; line-height:1.7;'>Lovely news — we've verified your payment and your session is now <strong>officially confirmed</strong>. Thank you for trusting Tweller Studios; we're truly looking forward to this.</p>

            " . self::session_details_card( $session, $pkg_name ) . "

            " . self::payment_summary_card( $session ) . "

            <p style='color:" . self::C_TEXT . "; line-height:1.7;'>The confirmed calendar event is attached to this email — if you use Gmail or Apple Calendar it will update automatically, replacing the earlier tentative hold.</p>

            " . self::email_button_row( $tracker_url, 'Track My Session' ) . "

            <p style='text-align:center; color:" . self::C_MUTED . "; font-size:13px;'>Your shoot code: <strong style='color:" . self::C_BLACK . ";'>{$session->tracking_code}</strong></p>

            <p style='color:" . self::C_TEXT . ";'>Warm regards,<br><strong>The Tweller Studios Team</strong></p>
        ";

        $attachments = array();
        $extra_to    = array();
        if ( ! empty( $session->session_date ) && ! empty( $session->session_time ) ) {
            $ics = self::build_ics( $session, 'CONFIRMED', 1 );
            if ( $ics ) {
                $attachments[] = $ics;
                $extra_to[]    = self::CALENDAR_EMAIL;
            }
        }

        $sent = self::send_email( $session, $subject, $body, $attachments, $extra_to );

        foreach ( $attachments as $file ) {
            @unlink( $file );
        }
        return $sent;
    }

    public static function get_tracker_url( $tracking_code ) {
        $tracker_page = get_option( 'tweller_flow_2_tracker_page', '' );
        if ( $tracker_page ) {
            return $tracker_page . '?code=' . $tracking_code;
        }
        return home_url( '/session-tracker/?code=' . $tracking_code );
    }

    public static function get_whatsapp_link( $phone, $message ) {
        $phone = preg_replace( '/[^0-9]/', '', $phone );
        return 'https://wa.me/' . $phone . '?text=' . urlencode( $message );
    }

    /**
     * Branded email shell — clean Stripe-style system in the studio's
     * black & gold. Table-based, inline styles, no accent bars:
     * black header band with the TWELLER / STUDIOS wordmark, white
     * content card on an ivory page, muted footer.
     */
    public static function wrap_email_html( $body, $client_name = '' ) {
        $font = self::FONT_STACK;
        return '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                @media only screen and (max-width: 620px) {
                    .tf2e-shell  { padding: 14px 10px !important; }
                    .tf2e-header { padding: 28px 22px !important; }
                    .tf2e-body   { padding: 28px 22px !important; }
                    .tf2e-footer { padding: 22px 18px !important; }
                    .tf2e-btn-wrap { width: 100% !important; }
                    .tf2e-btn-cell { width: 100% !important; display: block !important; }
                    .tf2e-btn    { display: block !important; width: 100% !important; box-sizing: border-box !important; text-align: center !important; padding: 15px 18px !important; font-size: 16px !important; }
                }
                a.tf2e-btn:hover { opacity: 0.92; }
            </style>
        </head>
        <body style="margin:0; padding:0; background:' . self::C_IVORY . '; -webkit-text-size-adjust:100%; font-family:' . $font . ';">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . self::C_IVORY . ';">
                <tr>
                    <td align="center" class="tf2e-shell" style="padding:32px 16px;">
                        <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px; border-collapse:separate;">
                            <tr>
                                <td class="tf2e-header" style="background:' . self::C_BLACK . '; border-radius:12px 12px 0 0; padding:34px 40px 30px; text-align:center;">
                                    <div style="font-family:' . $font . '; color:#FFFFFF; font-size:21px; font-weight:600; letter-spacing:9px;">TWELLER</div>
                                    <div style="font-family:' . $font . '; color:' . self::C_GOLD . '; font-size:11px; font-weight:600; letter-spacing:6px; text-transform:uppercase; margin-top:7px;">Studios</div>
                                </td>
                            </tr>
                            <tr>
                                <td class="tf2e-body" style="background:' . self::C_CARD . '; border:1px solid ' . self::C_BORDER . '; border-top:none; border-radius:0 0 12px 12px; padding:36px 40px; font-family:' . $font . '; font-size:15px; color:' . self::C_TEXT . '; line-height:1.7;">
                                    ' . $body . '
                                </td>
                            </tr>
                            <tr>
                                <td class="tf2e-footer" style="padding:26px 24px; text-align:center;">
                                    <p style="margin:0 0 6px; font-family:' . $font . '; color:#9B958A; font-size:12px; letter-spacing:0.4px;">Tweller Studios &middot; Photography &amp; Film &middot; Trinidad &amp; Tobago</p>
                                    <p style="margin:0; font-family:' . $font . ';">
                                        <a href="https://twellerstudios.com" style="color:' . self::C_MUTED . '; font-size:12px; text-decoration:none;">twellerstudios.com</a>
                                        <span style="color:#D8D2C6;">&nbsp;&middot;&nbsp;</span>
                                        <a href="mailto:' . self::STUDIO_EMAIL . '" style="color:' . self::C_MUTED . '; font-size:12px; text-decoration:none;">' . self::STUDIO_EMAIL . '</a>
                                        <span style="color:#D8D2C6;">&nbsp;&middot;&nbsp;</span>
                                        <a href="https://instagram.com/twellerstudios" style="color:' . self::C_MUTED . '; font-size:12px; text-decoration:none;">@twellerstudios</a>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>';
    }

    /**
     * Build an .ics calendar file for the session and return its temp path
     * (or null). Caller attaches it to an email and unlinks it afterwards.
     */
    public static function build_ics( $session, $status = 'TENTATIVE', $sequence = 0 ) {
        $packages = get_option('tweller_flow_2_packages', array());
        $pkg = $packages[$session->package_type] ?? array();
        $pkg_name = $pkg['name'] ?? ucfirst($session->package_type);
        $pkg_name_clean = trim( explode('—', $pkg_name)[0] );

        $session_types = get_option('tweller_flow_2_session_types', array());
        $type = $session_types[$session->session_type] ?? array();
        $type_name = $type['name'] ?? ucfirst($session->session_type ?? '');

        $timestamp = strtotime($session->session_date . ' ' . $session->session_time);
        if (!$timestamp) return null;

        $date_start = gmdate('Ymd\THis\Z', $timestamp);

        $duration = 60; // default 60 mins
        if (!empty($pkg['duration'])) {
            $duration = intval($pkg['duration']);
        }
        $date_end = gmdate('Ymd\THis\Z', $timestamp + ($duration * 60));

        $now = gmdate('Ymd\THis\Z');
        $uid = $session->tracking_code . '@twellerstudios.com';

        $title = $status === 'TENTATIVE' ? "Temp Booking: " : "";
        $title .= "[{$pkg_name_clean}] [{$type_name}] Session - with {$session->client_name}";
        $clean_loc = wp_strip_all_tags($session->location);
        if ($clean_loc) {
            $title .= " at {$clean_loc}";
        }

        $tracker_url = self::get_tracker_url($session->tracking_code);
        $desc = "Client: {$session->client_name}\\nEmail: {$session->client_email}\\nPhone: {$session->client_phone}\\nTracker: {$tracker_url}\\nTotal: TTD " . number_format($session->total_amount, 2);

        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//Tweller Studios//Tweller Bookings//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:REQUEST\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "DTSTART:{$date_start}\r\n";
        $ics .= "DTEND:{$date_end}\r\n";
        $ics .= "DTSTAMP:{$now}\r\n";
        $ics .= "ORGANIZER;CN=\"Tweller Studios\":mailto:" . self::CALENDAR_EMAIL . "\r\n";
        $ics .= "ATTENDEE;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE;CN=\"{$session->client_name}\":mailto:{$session->client_email}\r\n";
        $ics .= "UID:{$uid}\r\n";
        $ics .= "CREATED:{$now}\r\n";
        $ics .= "LAST-MODIFIED:{$now}\r\n";
        $ics .= "LOCATION:{$clean_loc}\r\n";
        $ics .= "SEQUENCE:{$sequence}\r\n";
        $ics .= "STATUS:{$status}\r\n";
        $ics .= "SUMMARY:{$title}\r\n";
        $ics .= "DESCRIPTION:{$desc}\r\n";
        $ics .= "TRANSP:OPAQUE\r\n";
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR";

        $tmp_dir = get_temp_dir();
        $file_path = $tmp_dir . 'invite_' . $session->tracking_code . '.ics';
        file_put_contents($file_path, $ics);

        return $file_path;
    }
}
