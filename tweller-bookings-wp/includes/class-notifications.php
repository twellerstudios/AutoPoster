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

    /** System font stack used across all email markup */
    const FONT_STACK = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

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
     * Primary (solid gold, dark text) or secondary (quiet outline) button.
     * Inline-styled anchor + .tf2e-btn class so the wrapper's media query
     * can stretch it full-width on small screens.
     */
    public static function email_button( $url, $label, $solid = true ) {
        $base = 'display:inline-block; padding:14px 34px; border-radius:8px; font-family:' . self::FONT_STACK . '; font-size:14px; font-weight:700; text-decoration:none; letter-spacing:0.3px; line-height:1.3;';
        if ( $solid ) {
            $style = $base . ' background:' . self::C_GOLD . '; color:' . self::C_BLACK . ';';
        } else {
            $style = $base . ' background:' . self::C_CARD . '; color:' . self::C_BLACK . '; border:1px solid #D8D2C6; font-weight:600;';
        }
        return "<a href='{$url}' class='tf2e-btn' style='{$style}'>{$label}</a>";
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

                    <div style='text-align:center; margin:30px 0;'>
                        " . self::email_button( $tracker_url, 'Open My Client Portal' ) . "
                    </div>

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
                    <div style='text-align:center; margin:30px 0;'>
                        " . self::email_button( $tracker_url, 'Track My Session' ) . "
                    </div>
                ",
            ),

            'delivered' => array(
                'subject' => "Your gallery is ready, {$first_name} — {$pkg_name}",
                'body'    => "
                    <h2 style='color:" . self::C_BLACK . "; font-weight:600;'>Your photos have arrived</h2>
                    <p style='color:" . self::C_TEXT . ";'>Hi {$session->client_name},</p>
                    <p style='color:" . self::C_TEXT . "; line-height:1.7;'>The moment you've been waiting for — your <strong>{$pkg_name}</strong> photos are ready to view and download.</p>

                    <div style='text-align:center; margin:32px 0;'>
                        " . self::email_button( $tracker_url, 'View Your Album' ) . "
                    </div>

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
                        " . self::email_button( $prints_url, 'Order Prints' ) . "
                    " ) . "

                    " . self::email_card( 'Share the Love', "
                        <p style='margin:6px 0 14px; color:" . self::C_TEXT . ";'>If you enjoyed your experience, a review would mean the world to us.</p>
                        " . self::email_button( $review_url, 'Leave Us a Review', false ) . "
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

            <p style='color:" . self::C_TEXT . "; line-height:1.7;'>The confirmed calendar event is attached to this email — if you use Gmail or Apple Calendar it will update automatically, replacing the earlier tentative hold.</p>

            <div style='text-align:center; margin:30px 0;'>
                " . self::email_button( $tracker_url, 'Track My Session' ) . "
            </div>

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
                    .tf2e-btn    { display: block !important; width: 100% !important; box-sizing: border-box !important; text-align: center !important; }
                }
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
