<?php
/**
 * Photo Automation — WordPress-side integration with local folder watcher
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Photo_Automation {

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
        add_action( 'tweller_flow_2_session_created', array( __CLASS__, 'on_session_created' ), 10, 2 );
    }

    public static function register_rest_routes() {
        // Local agent advances a session's stage (allow GET+POST — some hosts block POST to REST API)
        register_rest_route( 'tweller-flow-2/v1', '/automation/advance', array(
            'methods'  => array( 'GET', 'POST' ),
            'callback' => array( __CLASS__, 'rest_advance_stage' ),
            'permission_callback' => array( __CLASS__, 'verify_api_key' ),
        ));

        // Local agent queries sessions by date (for folder-to-session matching)
        register_rest_route( 'tweller-flow-2/v1', '/automation/sessions', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'rest_sessions_by_date' ),
            'permission_callback' => array( __CLASS__, 'verify_api_key' ),
        ));

        // Get automation status for a session
        register_rest_route( 'tweller-flow-2/v1', '/automation/status/(?P<code>[a-zA-Z0-9\-]+)', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'rest_get_status' ),
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
        ));

        // LR plugin: find-or-create a session (for last-minute / unregistered shoots)
        register_rest_route( 'tweller-flow-2/v1', '/automation/create-session', array(
            'methods'  => array( 'GET', 'POST' ),
            'callback' => array( __CLASS__, 'rest_create_session' ),
            'permission_callback' => array( __CLASS__, 'verify_api_key' ),
        ));

        // Mobile app: pipeline overview for the dashboard
        register_rest_route( 'tweller-flow-2/v1', '/automation/overview', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'rest_overview' ),
            'permission_callback' => array( __CLASS__, 'verify_api_key' ),
        ));

        // Mobile app: full session detail — info, timeline, culling, gallery
        register_rest_route( 'tweller-flow-2/v1', '/automation/session/(?P<code>[a-zA-Z0-9\-]+)', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'rest_session_detail' ),
            'permission_callback' => array( __CLASS__, 'verify_api_key' ),
        ));

        // Mobile app: update editable session fields (payment status, notes)
        register_rest_route( 'tweller-flow-2/v1', '/automation/session/(?P<code>[a-zA-Z0-9\-]+)/update', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'rest_session_update' ),
            'permission_callback' => array( __CLASS__, 'verify_api_key' ),
        ));
    }

    /**
     * Dashboard payload for the mobile app: how many sessions sit at each
     * stage, who's shooting next, and which sessions are waiting on the
     * photographer (selections in, receipt to verify).
     */
    public static function rest_overview( $request ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;

        $stage_counts = TwellerFlow2_Session::get_stage_counts();

        $today = current_time( 'Y-m-d' );
        $upcoming = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, tracking_code, client_name, package_type, session_date, session_time, location, current_stage
             FROM $table WHERE session_date >= %s AND current_stage NOT IN ('delivered')
             ORDER BY session_date ASC, session_time ASC LIMIT 5",
            $today
        ));

        // Sessions needing the photographer's attention
        $attention = array();
        $active = $wpdb->get_results(
            "SELECT id, tracking_code, client_name, package_type, session_date, current_stage, payment_status
             FROM $table WHERE current_stage != 'delivered' ORDER BY session_date DESC LIMIT 50"
        );
        foreach ( $active as $s ) {
            $reasons = array();
            if ( $s->payment_status === 'verifying' ) {
                $reasons[] = 'Receipt to verify';
            }
            if ( get_option( 'tweller_culling_submitted_' . $s->id, false )
                 && ! in_array( $s->current_stage, array( 'edited', 'exporting', 'exported', 'uploading', 'uploaded' ), true ) ) {
                $reasons[] = 'Selections in — ready to edit';
            }
            if ( $s->current_stage === 'culling' && class_exists( 'TwellerFlow2_Culling' )
                 && ! get_option( 'tweller_culling_submitted_' . $s->id, false ) ) {
                $reasons[] = 'Client choosing photos';
            }
            if ( $reasons ) {
                $attention[] = array(
                    'tracking_code' => $s->tracking_code,
                    'client_name'   => $s->client_name,
                    'session_date'  => $s->session_date,
                    'current_stage' => $s->current_stage,
                    'reasons'       => $reasons,
                );
            }
        }

        return rest_ensure_response( array(
            'ok'            => true,
            'stage_counts'  => $stage_counts,
            'active_count'  => TwellerFlow2_Session::count_active(),
            'total_count'   => array_sum( $stage_counts ),
            'upcoming'      => $upcoming ?: array(),
            'attention'     => $attention,
        ));
    }

    /**
     * Everything the mobile session screen shows: the full session row,
     * stage timeline, culling + gallery status, selections, receipt info.
     */
    public static function rest_session_detail( $request ) {
        $code    = sanitize_text_field( $request['code'] );
        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $stages_conf = TwellerFlow2_Database::get_stages();
        $stage_keys  = TwellerFlow2_Database::get_stage_keys();
        $stages = array();
        foreach ( $stage_keys as $i => $key ) {
            $stages[] = array(
                'key'    => $key,
                'label'  => $stages_conf[ $key ]['label'] ?? ucfirst( $key ),
                'notify' => ! empty( $stages_conf[ $key ]['notify'] ),
            );
        }

        $timeline = array();
        foreach ( TwellerFlow2_Session::get_history( $session->id ) as $h ) {
            $timeline[] = array(
                'stage'       => $h->stage,
                'stage_index' => (int) $h->stage_index,
                'label'       => $stages_conf[ $h->stage ]['label'] ?? ucfirst( $h->stage ),
                'timestamp'   => $h->timestamp,
                'notes'       => $h->notes,
            );
        }

        $culling    = null;
        $selections = array();
        if ( class_exists( 'TwellerFlow2_Culling' ) ) {
            $culling = TwellerFlow2_Culling::get_summary( $session->id );
            if ( ! empty( $culling['submitted'] ) ) {
                foreach ( TwellerFlow2_Culling::get_selections( $session->id ) as $sel ) {
                    $selections[] = array(
                        'filename' => $sel->filename,
                        'star'     => (int) $sel->star_rating,
                    );
                }
            }
        }

        $gallery = null;
        if ( class_exists( 'TwellerFlow2_Gallery' ) ) {
            $info = TwellerFlow2_Gallery::get_gallery_info( $session->id, $session->tracking_code );
            $gallery = array(
                'photo_count'   => $info['photo_count'],
                'total_size_mb' => $info['total_size_mb'],
                'has_password'  => $info['has_password'],
            );
        }

        $receipt     = get_option( 'tf_receipt_' . $session->id, null );
        $receipt_out = null;
        if ( is_array( $receipt ) ) {
            $receipt_out = array(
                'url'       => $receipt['url'] ?? '',
                'ocr'       => $receipt['ocr'] ?? '',
                'confirmed' => ! empty( $receipt['confirmed'] ),
                'bank'      => $receipt['bank'] ?? '',
                'date'      => $receipt['date'] ?? '',
            );
        }

        $tracker_page = get_option( 'tweller_flow_2_tracker_page', '' );
        $links = array(
            'tracker' => $tracker_page ? $tracker_page . ( strpos( $tracker_page, '?' ) !== false ? '&' : '?' ) . 'code=' . $session->tracking_code : '',
            'culling' => class_exists( 'TwellerFlow2_Culling' ) ? TwellerFlow2_Culling::get_culling_page_url( $session->tracking_code ) : '',
            'gallery' => $session->gallery_url,
        );

        return rest_ensure_response( array(
            'ok'         => true,
            'session'    => $session,
            'stages'     => $stages,
            'timeline'   => $timeline,
            'culling'    => $culling,
            'selections' => $selections,
            'gallery'    => $gallery,
            'receipt'    => $receipt_out,
            'links'      => $links,
        ));
    }

    /** Update the fields the mobile app can edit. */
    public static function rest_session_update( $request ) {
        $code    = sanitize_text_field( $request['code'] );
        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $update  = array();
        $changed = array();

        $payment = sanitize_text_field( $request->get_param( 'payment_status' ) ?? '' );
        if ( $payment && in_array( $payment, array( 'pending', 'verifying', 'deposit', 'paid' ), true ) ) {
            $update['payment_status'] = $payment;
            $changed[] = 'payment: ' . $payment;
        }

        $notes = $request->get_param( 'notes' );
        if ( $notes !== null ) {
            $update['notes'] = sanitize_textarea_field( $notes );
            $changed[] = 'notes';
        }

        if ( empty( $update ) ) {
            return new WP_Error( 'nothing_to_update', 'No editable fields provided', array( 'status' => 400 ) );
        }

        TwellerFlow2_Session::update( $session->id, $update );
        TwellerFlow2_Session::record_stage_history(
            $session->id, $session->current_stage, $session->current_stage_index,
            '[Mobile] Updated ' . implode( ', ', $changed )
        );

        return rest_ensure_response( array( 'ok' => true, 'updated' => array_keys( $update ) ) );
    }

    /**
     * Find-or-create a session from the Lightroom plugin.
     * If a session already exists for the same client name + date, it is
     * returned instead of creating a duplicate.
     */
    public static function rest_create_session( $request ) {
        $client_name  = sanitize_text_field( $request->get_param( 'client_name' ) );
        $client_email = sanitize_email( $request->get_param( 'client_email' ) ?? '' );
        $session_date = sanitize_text_field( $request->get_param( 'session_date' ) ?? '' );
        $package_type = sanitize_text_field( $request->get_param( 'package_type' ) ?? 'mini' );

        if ( ! $client_name ) {
            return new WP_Error( 'missing_params', 'client_name is required', array( 'status' => 400 ) );
        }
        if ( $session_date && strtotime( $session_date ) ) {
            // Normalize whatever format was sent to Y-m-d
            $session_date = date( 'Y-m-d', strtotime( $session_date ) );
        } else {
            $session_date = current_time( 'Y-m-d' );
        }

        // Reuse an existing session for the same client + date
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE client_name = %s AND session_date = %s ORDER BY id DESC LIMIT 1",
            $client_name, $session_date
        ));
        if ( $existing ) {
            return rest_ensure_response( array(
                'ok'            => true,
                'existing'      => true,
                'session_id'    => $existing->id,
                'tracking_code' => $existing->tracking_code,
                'client_name'   => $existing->client_name,
            ));
        }

        $session_id = TwellerFlow2_Session::create( array(
            'client_name'        => $client_name,
            'client_email'       => $client_email,
            'package_type'       => $package_type,
            'session_date'       => $session_date,
            'payment_status'     => 'paid',
            'notes'              => 'Created from Lightroom plugin',
            'skip_notifications' => true,
        ));

        if ( ! $session_id ) {
            return new WP_Error( 'create_failed', 'Could not create session', array( 'status' => 500 ) );
        }

        $session = TwellerFlow2_Session::get( $session_id );
        self::log_activity( $session_id, 'booked', 'Session created from Lightroom plugin' );

        return rest_ensure_response( array(
            'ok'            => true,
            'existing'      => false,
            'session_id'    => $session_id,
            'tracking_code' => $session->tracking_code,
            'client_name'   => $session->client_name,
        ));
    }

    public static function verify_api_key( $request ) {
        $settings = self::get_settings();
        $api_key = $settings['api_key'] ?? '';

        if ( empty( $api_key ) ) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            return in_array( $ip, array( '127.0.0.1', '::1' ) );
        }

        $provided_key = $request->get_param( 'api_key' );
        if ( ! $provided_key ) {
            $auth_header = $request->get_header( 'Authorization' );
            if ( $auth_header && str_starts_with( $auth_header, 'Bearer ' ) ) {
                $provided_key = substr( $auth_header, 7 );
            }
        }

        return hash_equals( $api_key, $provided_key ?? '' );
    }

    public static function rest_advance_stage( $request ) {
        $session_code = sanitize_text_field( $request->get_param( 'session_code' ) );
        $target_stage = sanitize_text_field( $request->get_param( 'target_stage' ) );
        $notes        = sanitize_text_field( $request->get_param( 'notes' ) ?? '' );
        $gallery_url  = esc_url_raw( $request->get_param( 'gallery_url' ) ?? '' );
        $photo_count  = intval( $request->get_param( 'photo_count' ) ?? 0 );

        if ( ! $session_code || ! $target_stage ) {
            return new WP_Error( 'missing_params', 'session_code and target_stage are required', array( 'status' => 400 ) );
        }

        $session = TwellerFlow2_Session::get_by_code( $session_code );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $update_data = array();
        if ( $gallery_url ) $update_data['gallery_url'] = $gallery_url;
        if ( $photo_count )  $update_data['photo_count'] = $photo_count;
        if ( ! empty( $update_data ) ) {
            TwellerFlow2_Session::update( $session->id, $update_data );
        }

        $result = TwellerFlow2_Session::set_stage( $session->id, $target_stage, '[Watcher] ' . $notes );

        if ( ! $result ) {
            return new WP_Error( 'advance_failed', 'Could not set stage to ' . $target_stage, array( 'status' => 400 ) );
        }

        self::log_activity( $session->id, $target_stage, $notes );

        $stages_conf = TwellerFlow2_Database::get_stages();
        $notified = ! empty( $stages_conf[ $result ]['notify'] ) && ! empty( $session->client_email );

        return rest_ensure_response( array(
            'ok'         => true,
            'session_id' => $session->id,
            'new_stage'  => $result,
            'notified'   => $notified,
        ));
    }

    public static function rest_sessions_by_date( $request ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;

        $date   = sanitize_text_field( $request->get_param( 'date' ) ?? '' );
        $range  = sanitize_text_field( $request->get_param( 'range' ) ?? '' );
        $search = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
        $stage  = sanitize_text_field( $request->get_param( 'stage' ) ?? '' );

        // Columns the mobile app + Lightroom picker need (extra fields are
        // ignored by older clients).
        $cols = "id, tracking_code, client_name, client_email, client_phone,
                 package_type, session_date, session_time, location, members_count,
                 current_stage, current_stage_index, folder_name, photo_count,
                 payment_status, deposit_amount, total_amount, estimated_delivery,
                 notes, created_at, updated_at";

        if ( $range === 'all' || $search || $stage ) {
            // Mobile client manager: every session, filterable.
            $where  = '1=1';
            $values = array();
            if ( $stage ) {
                $where   .= ' AND current_stage = %s';
                $values[] = $stage;
            }
            if ( $search ) {
                $like    = '%' . $wpdb->esc_like( $search ) . '%';
                $where  .= ' AND (client_name LIKE %s OR client_email LIKE %s OR tracking_code LIKE %s)';
                array_push( $values, $like, $like, $like );
            }
            $sql = "SELECT $cols FROM $table WHERE $where ORDER BY session_date DESC, id DESC LIMIT 300";
            $sessions = $wpdb->get_results( $values ? $wpdb->prepare( $sql, $values ) : $sql );
        } elseif ( $range === 'recent' ) {
            // Lightroom session picker: the latest sessions, newest first.
            // No date window and no stage filter — a session created moments
            // ago from Lightroom (or already delivered) must always appear.
            $sessions = $wpdb->get_results(
                "SELECT $cols FROM $table ORDER BY session_date DESC, id DESC LIMIT 30"
            );
        } elseif ( $date ) {
            $sessions = $wpdb->get_results( $wpdb->prepare(
                "SELECT $cols FROM $table WHERE session_date = %s ORDER BY session_time ASC",
                $date
            ));
        } else {
            // Return all non-delivered sessions
            $sessions = $wpdb->get_results(
                "SELECT $cols FROM $table WHERE current_stage != 'delivered'
                 ORDER BY session_date DESC LIMIT 50"
            );
        }

        // Add culling_enabled flag to each session
        if ( class_exists( 'TwellerFlow2_Culling' ) ) {
            foreach ( $sessions as &$s ) {
                $s->culling_enabled = TwellerFlow2_Culling::is_culling_enabled( $s->id );
                $s->culling_submitted = (bool) get_option( 'tweller_culling_submitted_' . $s->id, false );
            }
        }

        return rest_ensure_response( $sessions ?: array() );
    }

    public static function rest_get_status( $request ) {
        $code = sanitize_text_field( $request['code'] );
        $session = TwellerFlow2_Session::get_by_code( $code );

        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $log = self::get_activity_log( $session->id );

        return rest_ensure_response( array(
            'ok'            => true,
            'session_code'  => $code,
            'current_stage' => $session->current_stage,
            'folder_name'   => $session->folder_name,
            'photo_count'   => $session->photo_count,
            'activity_log'  => $log,
        ));
    }

    public static function on_session_created( $session_id, $data ) {
        // Nothing to do on creation for now — folder watcher handles detection
    }

    // ── Settings ─────────────────────────────────────────

    public static function get_settings() {
        return get_option( 'tweller_flow_2_automation', array(
            'enabled'          => false,
            'backend_url'      => 'http://localhost:3001',
            'api_key'          => '',
            'watch_dir'        => '',
            'gallery_base_url' => '',
        ));
    }

    public static function save_settings( $data ) {
        $settings = array(
            'enabled'           => ! empty( $data['automation_enabled'] ),
            'backend_url'       => esc_url_raw( $data['automation_backend_url'] ?? 'http://localhost:3001' ),
            'api_key'           => sanitize_text_field( $data['automation_api_key'] ?? '' ),
            'watch_dir'         => sanitize_text_field( $data['automation_watch_dir'] ?? '' ),
            'gallery_base_url'  => esc_url_raw( $data['automation_gallery_base_url'] ?? '' ),
        );
        update_option( 'tweller_flow_2_automation', $settings );
        return $settings;
    }

    // ── Activity log ─────────────────────────────────────

    public static function log_activity( $session_id, $stage, $message ) {
        $log = get_option( 'tweller_flow_2_automation_log', array() );

        array_unshift( $log, array(
            'session_id' => $session_id,
            'stage'      => $stage,
            'message'    => $message,
            'timestamp'  => current_time( 'mysql' ),
        ));

        $log = array_slice( $log, 0, 200 );
        update_option( 'tweller_flow_2_automation_log', $log );
    }

    public static function get_activity_log( $session_id = null, $limit = 50 ) {
        $log = get_option( 'tweller_flow_2_automation_log', array() );

        if ( $session_id ) {
            $log = array_filter( $log, function( $entry ) use ( $session_id ) {
                return $entry['session_id'] == $session_id;
            });
        }

        return array_slice( array_values( $log ), 0, $limit );
    }
}
