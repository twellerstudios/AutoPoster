<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow_Notifications {

    /**
     * Configure WordPress to use Zoho SMTP
     */
    public static function configure_smtp( $phpmailer ) {
        $smtp = get_option( 'tweller_flow_smtp', array() );

        if ( empty( $smtp['username'] ) || empty( $smtp['password'] ) ) {
            return;
        }

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

    /**
     * Handle stage change notifications
     */
    public static function on_stage_change( $session_id, $stage ) {
        $session = TwellerFlow_Session::get( $session_id );
        if ( ! $session || empty( $session->client_email ) ) return;

        $template = self::get_email_template( $stage, $session );
        if ( ! $template ) return;

        self::send_email( $session, $template['subject'], $template['body'] );
    }

    /**
     * Send email notification
     */
    public static function send_email( $session, $subject, $body ) {
        // Hook into phpmailer for SMTP config
        add_action( 'phpmailer_init', array( __CLASS__, 'configure_smtp' ) );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        $html_body = self::wrap_email_html( $body, $session->client_name );
        $sent = wp_mail( $session->client_email, $subject, $html_body, $headers );

        remove_action( 'phpmailer_init', array( __CLASS__, 'configure_smtp' ) );

        // Log notification
        self::log_notification( $session->id, $session->client_email, $subject, $body, $sent ? 'sent' : 'failed' );

        return $sent;
    }

    /**
     * Log notification to database
     */
    private static function log_notification( $session_id, $recipient, $subject, $body, $status ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_TABLE_NOTIFICATIONS;

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

    /**
     * Get email template for a stage
     */
    public static function get_email_template( $stage, $session ) {
        $banking   = get_option( 'tweller_flow_banking', '' );
        $packages  = get_option( 'tweller_flow_packages', array() );
        $pkg       = $packages[ $session->package_type ] ?? array();
        $pkg_name  = $pkg['name'] ?? ucfirst( $session->package_type );
        $tracker_url = self::get_tracker_url( $session->tracking_code );

        $templates = array(
            'booked' => array(
                'subject' => "Booking Confirmed! — {$pkg_name} with Tweller Studios",
                'body'    => "
                    <h2>Your session is booked! 🎉</h2>
                    <p>Hi {$session->client_name},</p>
                    <p>Thank you for booking your <strong>{$pkg_name}</strong> with Tweller Studios! We're excited to work with you.</p>

                    <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                        <h3>Session Details</h3>
                        <p><strong>Package:</strong> {$pkg_name}</p>
                        " . ( $session->session_date ? "<p><strong>Date:</strong> " . date( 'F j, Y', strtotime( $session->session_date ) ) . "</p>" : "" ) . "
                        " . ( $session->location ? "<p><strong>Location:</strong> {$session->location}</p>" : "" ) . "
                        <p><strong>Total:</strong> $" . number_format( $session->total_amount, 2 ) . "</p>
                    </div>

                    <div style='background: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                        <h3>Payment Information</h3>
                        <p>To secure your session, please make a deposit of <strong>$" . number_format( $session->total_amount / 2, 2 ) . "</strong> (50%).</p>
                        <pre style='background: #fff; padding: 15px; border-radius: 4px;'>{$banking}</pre>
                        <p><em>Balance of $" . number_format( $session->total_amount / 2, 2 ) . " is due on the day of the session (cash or transfer).</em></p>
                    </div>

                    <div style='background: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                        <h3>Track Your Session</h3>
                        <p>You can track the progress of your session at any time:</p>
                        <p><a href='{$tracker_url}' style='display:inline-block; background:#2c3e50; color:#fff; padding:12px 24px; border-radius:6px; text-decoration:none;'>Track My Session</a></p>
                        <p><small>Your tracking code: <strong>{$session->tracking_code}</strong></small></p>
                    </div>

                    <p>Looking forward to capturing amazing moments with you!</p>
                ",
            ),

            'deposit_received' => array(
                'subject' => "Deposit Received — You're All Set!",
                'body'    => "
                    <h2>Payment Confirmed ✓</h2>
                    <p>Hi {$session->client_name},</p>
                    <p>We've received your deposit of <strong>$" . number_format( $session->deposit_amount, 2 ) . "</strong>. Your session is confirmed!</p>

                    <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                        <h3>Session Prep Tips</h3>
                        <ul>
                            <li>Coordinate outfits with solid colors — avoid busy patterns</li>
                            <li>Arrive 10 minutes early to settle in</li>
                            <li>Bring any props or accessories you'd like to use</li>
                            <li>Don't forget to hydrate and have fun!</li>
                        </ul>
                    </div>

                    <p>Remaining balance: <strong>$" . number_format( $session->total_amount - $session->deposit_amount, 2 ) . "</strong> (due on session day)</p>

                    <p><a href='{$tracker_url}' style='display:inline-block; background:#2c3e50; color:#fff; padding:10px 20px; border-radius:6px; text-decoration:none;'>Track My Session</a></p>
                ",
            ),

            'session_complete' => array(
                'subject' => "Great Session Today! Here's What's Next",
                'body'    => "
                    <h2>That was amazing! 📸</h2>
                    <p>Hi {$session->client_name},</p>
                    <p>Thank you for an incredible session today! We had a wonderful time capturing your special moments.</p>

                    <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                        <h3>What Happens Next</h3>
                        <ol>
                            <li><strong>Import & Cull</strong> — We'll go through all the shots and select the best ones</li>
                            <li><strong>Editing</strong> — Each selected photo gets carefully edited</li>
                            <li><strong>Gallery Delivery</strong> — Your final gallery will be ready in approximately <strong>" . get_option( 'tweller_flow_delivery_days', 14 ) . " days</strong></li>
                        </ol>
                    </div>

                    " . ( $session->estimated_delivery ? "<p>Estimated delivery date: <strong>" . date( 'F j, Y', strtotime( $session->estimated_delivery ) ) . "</strong></p>" : "" ) . "

                    <p>Track your session progress in real time:</p>
                    <p><a href='{$tracker_url}' style='display:inline-block; background:#2c3e50; color:#fff; padding:10px 20px; border-radius:6px; text-decoration:none;'>Track My Session</a></p>
                ",
            ),

            'gallery_created' => array(
                'subject' => "Your Gallery is Ready! 🎉",
                'body'    => "
                    <h2>Your photos are ready!</h2>
                    <p>Hi {$session->client_name},</p>
                    <p>Great news! Your edited photos are ready for viewing and download.</p>

                    " . ( $session->gallery_url ? "
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='{$session->gallery_url}' style='display:inline-block; background:#27ae60; color:#fff; padding:15px 30px; border-radius:8px; text-decoration:none; font-size:18px;'>View & Download Your Gallery</a>
                    </div>
                    " : "<p>Your gallery link will be shared shortly.</p>" ) . "

                    <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                        <h3>Gallery Tips</h3>
                        <ul>
                            <li>You can download individual photos or the entire gallery</li>
                            <li>All images are high resolution and print-ready</li>
                            <li>Your gallery will be available for 30 days</li>
                        </ul>
                    </div>

                    <p>We hope you love your photos! If you'd like prints or additional editing, just let us know.</p>
                ",
            ),

            'delivered' => array(
                'subject' => "Thank You! — Tweller Studios",
                'body'    => "
                    <h2>Thank you for choosing Tweller Studios!</h2>
                    <p>Hi {$session->client_name},</p>
                    <p>We hope you're loving your photos! It was truly a pleasure working with you.</p>

                    <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                        <h3>Share the Love</h3>
                        <p>If you enjoyed your experience, we'd really appreciate a review — it helps us reach more people!</p>
                        <p><a href='https://g.page/r/twellerstudios/review' style='display:inline-block; background:#4285f4; color:#fff; padding:10px 20px; border-radius:6px; text-decoration:none;'>Leave a Google Review</a></p>
                    </div>

                    <div style='background: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                        <h3>Refer a Friend</h3>
                        <p>Know someone who'd love a photo session? Refer them and you both get <strong>10% off</strong> your next booking!</p>
                    </div>

                    <p>See you again soon! 💛</p>
                ",
            ),
        );

        return $templates[ $stage ] ?? null;
    }

    /**
     * Get the tracker URL for a session
     */
    public static function get_tracker_url( $tracking_code ) {
        $tracker_page = get_option( 'tweller_flow_tracker_page', '' );
        if ( $tracker_page ) {
            return $tracker_page . '?code=' . $tracking_code;
        }
        return home_url( '/session-tracker/?code=' . $tracking_code );
    }

    /**
     * Generate WhatsApp message link
     */
    public static function get_whatsapp_link( $phone, $message ) {
        $phone = preg_replace( '/[^0-9]/', '', $phone );
        return 'https://wa.me/' . $phone . '?text=' . urlencode( $message );
    }

    /**
     * Wrap email content in HTML template
     */
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
}
