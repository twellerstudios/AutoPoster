<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Notifications {

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

        self::send_email( $session, $template['subject'], $template['body'] );
    }

    public static function send_email( $session, $subject, $body ) {
        add_action( 'phpmailer_init', array( __CLASS__, 'configure_smtp' ) );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $html_body = self::wrap_email_html( $body, $session->client_name );
        $sent = wp_mail( $session->client_email, $subject, $html_body, $headers );

        remove_action( 'phpmailer_init', array( __CLASS__, 'configure_smtp' ) );
        self::log_notification( $session->id, $session->client_email, $subject, $body, $sent ? 'sent' : 'failed' );
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

    public static function get_email_template( $stage, $session ) {
        $banking   = get_option( 'tweller_flow_2_banking', '' );
        $packages  = get_option( 'tweller_flow_2_packages', array() );
        $pkg       = $packages[ $session->package_type ] ?? array();
        $pkg_name  = $pkg['name'] ?? ucfirst( $session->package_type );
        $tracker_url = self::get_tracker_url( $session->tracking_code );
        $delivery_days = get_option( 'tweller_flow_2_delivery_days', 14 );
        $review_url  = get_option( 'tweller_flow_2_review_url', 'https://g.page/r/CbntSRvzXVrSEBM/review' );

        $templates = array(
            'booked' => array(
                'subject' => "Booking Confirmed — {$pkg_name} with Tweller Studios",
                'body'    => "
                    <h2>Your session is booked!</h2>
                    <p>Hi {$session->client_name},</p>
                    <p>Thank you for booking your <strong>{$pkg_name}</strong> with Tweller Studios!</p>

                    <div style='background:#f8f9fa; padding:20px; border-radius:8px; margin:20px 0;'>
                        <h3 style='margin-top:0;'>Session Details</h3>
                        <p><strong>Package:</strong> {$pkg_name}</p>
                        " . ( $session->session_date ? "<p><strong>Date:</strong> " . date( 'F j, Y', strtotime( $session->session_date ) ) . "</p>" : "" ) . "
                        " . ( $session->location ? "<p><strong>Location:</strong> {$session->location}</p>" : "" ) . "
                        <p><strong>Total:</strong> $" . number_format( $session->total_amount, 2 ) . "</p>
                    </div>

                    <div style='background:#fff3cd; padding:20px; border-radius:8px; margin:20px 0;'>
                        <h3 style='margin-top:0;'>Payment Information</h3>
                        <p>To secure your session, please make a deposit of <strong>$" . number_format( $session->total_amount / 2, 2 ) . "</strong> (50%).</p>
                        <pre style='background:#fff; padding:15px; border-radius:4px; white-space:pre-wrap;'>{$banking}</pre>
                        <p><em>Balance due on the day of the session.</em></p>
                    </div>

                    <div style='background:#d4edda; padding:20px; border-radius:8px; margin:20px 0;'>
                        <h3 style='margin-top:0;'>Track Your Session</h3>
                        <p>Track your session progress at any time:</p>
                        <p><a href='{$tracker_url}' style='display:inline-block; background:#2c3e50; color:#fff; padding:12px 24px; border-radius:6px; text-decoration:none;'>Track My Session</a></p>
                        <p><small>Your shoot code: <strong>{$session->tracking_code}</strong></small></p>
                    </div>
                ",
            ),

            'edited' => array(
                'subject' => "Your Photos Are Almost Ready!",
                'body'    => "
                    <h2>Editing Complete</h2>
                    <p>Hi {$session->client_name},</p>
                    <p>Great news! We've finished editing your photos. Your gallery will be ready shortly.</p>
                    <p><a href='{$tracker_url}' style='display:inline-block; background:#2c3e50; color:#fff; padding:10px 20px; border-radius:6px; text-decoration:none;'>Track My Session</a></p>
                ",
            ),

            'delivered' => array(
                'subject' => "Your Gallery is Ready — {$pkg_name} with Tweller Studios",
                'body'    => "
                    <h2>Your photos have been delivered!</h2>
                    <p>Hi {$session->client_name},</p>
                    <p>Great news! Your <strong>{$pkg_name}</strong> photos are ready for viewing and download.</p>

                    <div style='text-align:center; margin:30px 0;'>
                        <a href='{$tracker_url}' style='display:inline-block; background:#10B981; color:#fff; padding:16px 36px; border-radius:10px; text-decoration:none; font-size:18px; font-weight:600;'>View Your Album</a>
                    </div>

                    <p style='text-align:center; color:#6B7280; font-size:14px;'>Click the button above to open your client portal and browse your gallery.</p>

                    <div style='background:#f0fdf4; padding:20px; border-radius:8px; margin:20px 0; border:1px solid #bbf7d0;'>
                        <h3 style='margin-top:0; color:#166534;'>Gallery Tips</h3>
                        <ul style='color:#374151;'>
                            <li>Browse your album with our full-screen slideshow</li>
                            <li>Download individual photos or the entire gallery</li>
                            <li>All images are high resolution and print-ready</li>
                        </ul>
                    </div>

                    <p>We hope you love your photos!</p>

                    <div style='background:#f8f9fa; padding:20px; border-radius:8px; margin:20px 0;'>
                        <h3 style='margin-top:0;'>Share the Love</h3>
                        <p>Enjoyed your experience? We'd appreciate a review!</p>
                        <p><a href='{$review_url}' style='display:inline-block; background:#4285f4; color:#fff; padding:10px 20px; border-radius:6px; text-decoration:none;'>Leave Us a Review</a></p>
                    </div>

                    <div style='text-align:center; margin-top:20px;'>
                        <p style='font-size:12px; color:#9CA3AF;'>Shoot code: <strong>{$session->tracking_code}</strong></p>
                    </div>
                ",
            ),
        );

        return $templates[ $stage ] ?? null;
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

    public static function wrap_email_html( $body, $client_name = '' ) {
        return '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="margin:0; padding:0; background:#f4f4f4; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">
            <div style="max-width:600px; margin:0 auto; background:#ffffff;">
                <div style="background:#2c3e50; padding:30px; text-align:center;">
                    <h1 style="color:#ffffff; margin:0; font-size:24px; letter-spacing:2px;">TWELLER STUDIOS</h1>
                </div>
                <div style="padding:30px 40px;">
                    ' . $body . '
                </div>
                <div style="background:#f8f9fa; padding:20px 40px; text-align:center; font-size:12px; color:#888;">
                    <p>Tweller Studios — Capturing Moments That Last</p>
                    <p><a href="https://twellerstudios.com" style="color:#2c3e50;">twellerstudios.com</a></p>
                </div>
            </div>
        </body>
        </html>';
    }

    public static function send_calendar_invite( $session, $status = 'TENTATIVE', $sequence = 0 ) {
        $packages = get_option('tweller_flow_2_packages', array());
        $pkg = $packages[$session->package_type] ?? array();
        $pkg_name = $pkg['name'] ?? ucfirst($session->package_type);
        $pkg_name_clean = trim( explode('—', $pkg_name)[0] );

        $session_types = get_option('tweller_flow_2_session_types', array());
        $type = $session_types[$session->session_type] ?? array();
        $type_name = $type['name'] ?? ucfirst($session->session_type);

        // Remove typical am/pm logic to build a valid timestamp
        $timestamp = strtotime($session->session_date . ' ' . $session->session_time);
        if (!$timestamp) return;

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
        $ics .= "PRODID:-//Tweller Studios//Tweller Flow//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:REQUEST\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "DTSTART:{$date_start}\r\n";
        $ics .= "DTEND:{$date_end}\r\n";
        $ics .= "DTSTAMP:{$now}\r\n";
        $ics .= "ORGANIZER;CN=\"Tweller Studios\":mailto:stephen.twellerstudios@gmail.com\r\n";
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

        $subject = ($status === 'TENTATIVE' ? "Tentative Calendar Hold: " : "Confirmed Calendar Event: ") . $title;
        $body = self::wrap_email_html("<h2>Calendar Invitation Update</h2><p>Hi {$session->client_name},</p><p>Please find the official calendar event attached for your photography session. It will automatically be added to your calendar if you are using Gmail or Apple Calendar.</p><p><a href='{$tracker_url}' style='color:#10B981; font-weight:bold;'>View your progress here</a></p>", $session->client_name);

        add_action('phpmailer_init', array(__CLASS__, 'configure_smtp'));
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $recipients = array($session->client_email, 'stephen.twellerstudios@gmail.com');
        $sent = wp_mail( $recipients, $subject, $body, $headers, array($file_path) );
        remove_action('phpmailer_init', array(__CLASS__, 'configure_smtp'));
        
        @unlink($file_path);
        return $sent;
    }
}
