<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow_Booking_API {

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( 'tweller-flow/v1', '/availability', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'rest_get_availability' ),
            'permission_callback' => '__return_true',
        ));

        register_rest_route( 'tweller-flow/v1', '/book', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'rest_create_booking' ),
            'permission_callback' => '__return_true',
        ));

        register_rest_route( 'tweller-flow/v1', '/upload-receipt', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'rest_upload_receipt' ),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Parse iCal file to get busy events for a specific date
     */
    private static function get_ical_busy_events( $target_date ) {
        $ical_url = get_option( 'tweller_flow_booking_ical_url', '' );
        if ( empty( $ical_url ) ) return array();

        $response = wp_remote_get( $ical_url, array( 'timeout' => 10 ) );
        if ( is_wp_error( $response ) ) return array();
        
        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) return array();

        $lines = explode( "\n", $body );
        $events = array();
        $current_event = null;

        foreach( $lines as $line ) {
            $line = trim( $line );
            if ( $line === 'BEGIN:VEVENT' ) {
                $current_event = array();
            } elseif ( $line === 'END:VEVENT' ) {
                if ( $current_event ) {
                    // Check if event falls on target date
                    if ( isset( $current_event['DTSTART'] ) && isset( $current_event['DTEND'] ) ) {
                        $start_date = date('Y-m-d', $current_event['DTSTART']);
                        $end_date = date('Y-m-d', $current_event['DTEND'] - 1); // DTEND is exclusive
                        
                        if ( $target_date >= $start_date && $target_date <= $end_date ) {
                            $events[] = array(
                                'start' => date('H:i:s', $current_event['DTSTART']),
                                'end'   => date('H:i:s', $current_event['DTEND'])
                            );
                        }
                    }
                }
                $current_event = null;
            } elseif ( $current_event !== null ) {
                if ( strpos( $line, 'DTSTART' ) === 0 ) {
                    $val = self::parse_ical_datetime($line);
                    if ($val) $current_event['DTSTART'] = $val;
                } elseif ( strpos( $line, 'DTEND' ) === 0 ) {
                    $val = self::parse_ical_datetime($line);
                    if ($val) $current_event['DTEND'] = $val;
                }
            }
        }

        return $events;
    }

    private static function parse_ical_datetime($line) {
        $parts = explode(':', $line, 2);
        if (count($parts) < 2) return false;
        $val = trim($parts[1]);
        if (strpos($val, 'T') !== false) {
            return strtotime($val);
        } else {
            // All day event format: 20231015
            return strtotime($val . " 00:00:00");
        }
    }

    public static function rest_get_availability( $request ) {
        $date = sanitize_text_field( $request->get_param('date') );
        $duration = intval( $request->get_param('duration') ) ?: 60; // duration in minutes

        if ( empty( $date ) ) {
            return new WP_Error( 'missing_date', 'Date is required', array( 'status' => 400 ) );
        }

        // Available from 7 AM to 10 PM
        $start_hour = 7;
        $end_hour = 22;

        $slots = array();
        $current_time = strtotime( "$date " . sprintf("%02d:00:00", $start_hour) );
        $end_time = strtotime( "$date " . sprintf("%02d:00:00", $end_hour) );

        // Generate 30 min intervals
        while ( $current_time + ($duration * 60) <= $end_time ) {
            $slots[] = array(
                'time' => date( 'H:i:s', $current_time ),
                'display' => date( 'g:i A', $current_time ),
                'stamp' => $current_time
            );
            $current_time += 30 * 60; // jump 30 mins
        }

        // Remove times in the past
        $now = current_time('timestamp');
        $slots = array_filter( $slots, function($slot) use ($now) {
            return $slot['stamp'] > $now;
        });

        // Check booked sessions from DB
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_TABLE_SESSIONS;
        $booked_sessions = $wpdb->get_results( $wpdb->prepare(
            "SELECT session_time, package_type FROM $table WHERE session_date = %s AND session_time IS NOT NULL AND current_stage != 'cancelled'",
            $date
        ));

        $packages = get_option('tweller_flow_packages', array());

        $busy_blocks = array();

        foreach ( $booked_sessions as $sess ) {
            $sess_dur = 60; // default
            if ( isset( $packages[ $sess->package_type ] ) ) {
                $sess_dur = intval( $packages[ $sess->package_type ]['duration'] );
            }
            $start_stamp = strtotime( "$date {$sess->session_time}" );
            $end_stamp = $start_stamp + ($sess_dur * 60);
            $busy_blocks[] = array( 'start' => $start_stamp, 'end' => $end_stamp );
        }

        // Add iCal events
        $ical_events = self::get_ical_busy_events( $date );
        foreach ( $ical_events as $ev ) {
            $start_stamp = strtotime( "$date {$ev['start']}" );
            $end_stamp = strtotime( "$date {$ev['end']}" );
            $busy_blocks[] = array( 'start' => $start_stamp, 'end' => $end_stamp );
        }

        // Filter slots
        $final_slots = array();
        foreach ( $slots as $slot ) {
            $slot_start = $slot['stamp'];
            $slot_end   = $slot_start + ($duration * 60);
            $conflict   = false;

            foreach ( $busy_blocks as $block ) {
                // If slot overlaps with block
                if ( $slot_start < $block['end'] && $slot_end > $block['start'] ) {
                    $conflict = true;
                    break;
                }
            }

            if ( ! $conflict ) {
                $final_slots[] = array(
                    'time' => $slot['time'],
                    'display' => $slot['display']
                );
            }
        }

        return rest_ensure_response( array(
            'date' => $date,
            'duration' => $duration,
            'available_slots' => array_values( $final_slots )
        ));
    }

    public static function rest_create_booking( $request ) {
        $data = $request->get_json_params();

        if ( empty($data['client_name']) || empty($data['client_email']) || empty($data['session_date']) || empty($data['session_time']) || empty($data['package_type']) ) {
            return new WP_Error( 'missing_fields', 'Please fill in all required fields.', array( 'status' => 400 ) );
        }

        $packages = get_option('tweller_flow_packages', array());
        $package = $packages[ $data['package_type'] ] ?? null;

        if ( ! $package ) {
            return new WP_Error( 'invalid_package', 'Invalid package selected.', array( 'status' => 400 ) );
        }

        $session_data = array(
            'client_name' => sanitize_text_field( $data['client_name'] ),
            'client_email' => sanitize_email( $data['client_email'] ),
            'client_phone' => sanitize_text_field( $data['client_phone'] ?? '' ),
            'package_type' => sanitize_text_field( $data['package_type'] ),
            'session_date' => sanitize_text_field( $data['session_date'] ),
            'session_time' => sanitize_text_field( $data['session_time'] ),
            'location' => sanitize_text_field( $data['location'] ?? '' ),
            'notes' => sanitize_textarea_field( $data['notes'] ?? '' ),
            'payment_status' => 'pending',
            'deposit_amount' => 0, // Bank transfer to follow
            'total_amount' => $package['price'],
            'members_count' => $package['members']
        );

        $session_id = TwellerFlow_Session::create( $session_data );

        if ( ! $session_id ) {
            return new WP_Error( 'create_failed', 'Could not create booking.', array( 'status' => 500 ) );
        }

        $session = TwellerFlow_Session::get( $session_id );

        // Send explicit 'Reserved' notification
        $tracker_url = get_option( 'tweller_flow_tracker_page', '' ) . '?code=' . $session->tracking_code;
        $reserved_subj = "Action Required: Your Session is Reserved! - Tweller Studios";
        $reserved_body = "Hi {$session->client_name},\n\n" .
                         "Thank you for choosing Tweller Studios! Your desired date and time has been successfully reserved.\n\n" .
                         "To officially confirm this booking, a downpayment or full payment is required via bank transfer.\n" .
                         "Please access your secure client portal link below to view our banking details and upload your transfer screenshot:\n\n" .
                         "🔗 $tracker_url\n\n" .
                         "Note: Your deposit is currently pending payment. Your spot is held but not confirmed until we verify the screenshot.\n\n" .
                         "Thanks,\nTweller Studios";
                         
        if ( ! empty( $session->client_email ) ) {
            wp_mail( $session->client_email, $reserved_subj, $reserved_body );
        }

        return rest_ensure_response( array(
            'success' => true,
            'session_id' => $session_id,
            'tracking_code' => $session->tracking_code
        ) );
    }

    public static function rest_upload_receipt( $request ) {
        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
        }

        $code = sanitize_text_field( $_POST['tracking_code'] ?? '' );
        if ( empty( $code ) ) {
            return new WP_Error( 'missing_code', 'Tracking code is required', array( 'status' => 400 ) );
        }

        $session = TwellerFlow_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        if ( empty( $_FILES['receipt'] ) ) {
            return new WP_Error( 'no_file', 'No receipt file provided', array( 'status' => 400 ) );
        }

        $file = $_FILES['receipt'];
        $upload_overrides = array( 'test_form' => false );
        
        // Add filter to change upload dir to protected space if preferable, for now standard uploads folder is fine
        // as we don't have sensitive imagery yet, but tracking code is pseudo-secure.
        $movefile = wp_handle_upload( $file, $upload_overrides );

        if ( $movefile && ! isset( $movefile['error'] ) ) {
            $ocr_data  = isset($_POST['ocr_amounts']) ? sanitize_text_field($_POST['ocr_amounts']) : '';
            $ocr_ref   = isset($_POST['ocr_reference']) ? sanitize_text_field($_POST['ocr_reference']) : '';
            $bank_name = isset($_POST['bank_name']) ? sanitize_text_field($_POST['bank_name']) : 'Unknown Bank';
            
            // Save dedicated receipt option
            update_option( 'tf_receipt_' . $session->id, array(
                'url'  => $movefile['url'],
                'ocr'  => $ocr_data,
                'ref'  => $ocr_ref,
                'bank' => $bank_name,
                'date' => current_time('mysql')
            ));

            // Set to verifying
            TwellerFlow_Session::update( $session->id, array(
                'payment_status' => 'verifying'
            ));

            TwellerFlow_Session::record_stage_history( $session->id, $session->current_stage, $session->current_stage_index, 'Client uploaded bank transfer receipt for verification.' );

            // Send Emails
            $admin_email = get_option( 'admin_email' );
            $tracker_url = get_option( 'tweller_flow_tracker_page', '' ) . '?code=' . $session->tracking_code;
            
            // To Admin
            $admin_subj = "Receipt Uploaded: {$session->client_name}";
            $admin_body = "Client {$session->client_name} uploaded their bank transfer receipt.\n\n" . 
                          "Please review it in your Tweller Flow dashboard.\n" .
                          admin_url('admin.php?page=tweller-flow-session&id=' . $session->id);
            wp_mail( $admin_email, $admin_subj, $admin_body );

            // To Client
            if ( ! empty($session->client_email) ) {
                $client_subj = "Bank Transfer Receipt Received - Tweller Studios";
                $client_body = "Hi {$session->client_name},\n\nWe have successfully received your bank transfer receipt. We will verify the payment and update your tracker within 1-2 business days.\n\nKeep an eye on your tracker: $tracker_url\n\nThanks,\nTweller Studios";
                wp_mail( $session->client_email, $client_subj, $client_body );
            }

            return rest_ensure_response( array(
                'success' => true,
                'url' => $movefile['url']
            ));
        } else {
            return new WP_Error( 'upload_error', $movefile['error'], array( 'status' => 500 ) );
        }
    }
}
