<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow_Session {

    /**
     * Generate a unique tracking code
     */
    public static function generate_tracking_code() {
        $code = strtoupper( substr( md5( uniqid( mt_rand(), true ) ), 0, 6 ) );
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_TABLE_SESSIONS;
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE tracking_code = %s", $code ) );
        if ( $exists > 0 ) {
            return self::generate_tracking_code();
        }
        return $code;
    }

    /**
     * Create a new session
     */
    public static function create( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_TABLE_SESSIONS;

        $tracking_code = self::generate_tracking_code();
        $delivery_days = get_option( 'tweller_flow_delivery_days', 14 );

        $session_date = ! empty( $data['session_date'] ) ? $data['session_date'] : null;
        $estimated_delivery = null;
        if ( $session_date ) {
            $estimated_delivery = date( 'Y-m-d', strtotime( $session_date . " + $delivery_days days" ) );
        }

        $insert_data = array(
            'tracking_code'      => $tracking_code,
            'client_name'        => sanitize_text_field( $data['client_name'] ?? '' ),
            'client_email'       => sanitize_email( $data['client_email'] ?? '' ),
            'client_phone'       => sanitize_text_field( $data['client_phone'] ?? '' ),
            'package_type'       => sanitize_text_field( $data['package_type'] ?? 'mini' ),
            'session_date'       => $session_date,
            'session_time'       => ! empty( $data['session_time'] ) ? $data['session_time'] : null,
            'location'           => sanitize_text_field( $data['location'] ?? '' ),
            'members_count'      => intval( $data['members_count'] ?? 1 ),
            'payment_status'     => sanitize_text_field( $data['payment_status'] ?? 'pending' ),
            'deposit_amount'     => floatval( $data['deposit_amount'] ?? 0 ),
            'total_amount'       => floatval( $data['total_amount'] ?? 0 ),
            'payment_method'     => sanitize_text_field( $data['payment_method'] ?? '' ),
            'current_stage'      => 'booked',
            'current_stage_index'=> 0,
            'estimated_delivery' => $estimated_delivery,
            'gallery_url'        => '',
            'notes'              => sanitize_textarea_field( $data['notes'] ?? '' ),
            'surecart_order_id'  => sanitize_text_field( $data['surecart_order_id'] ?? '' ),
            'created_at'         => current_time( 'mysql' ),
            'updated_at'         => current_time( 'mysql' ),
        );

        $wpdb->insert( $table, $insert_data );
        $session_id = $wpdb->insert_id;

        if ( $session_id ) {
            // Record initial stage
            self::record_stage_history( $session_id, 'booked', 0, 'Session created' );

            // Send booking confirmation
            TwellerFlow_Notifications::on_stage_change( $session_id, 'booked' );

            // Trigger automation hooks (creates session folders, etc.)
            do_action( 'tweller_flow_session_created', $session_id, $data );
        }

        return $session_id;
    }

    /**
     * Get session by ID
     */
    public static function get( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_TABLE_SESSIONS;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
    }

    /**
     * Get session by tracking code
     */
    public static function get_by_code( $code ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_TABLE_SESSIONS;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE tracking_code = %s", $code ) );
    }

    /**
     * Get all sessions with optional filters
     */
    public static function get_all( $args = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_TABLE_SESSIONS;

        $where   = '1=1';
        $values  = array();
        $orderby = 'created_at';
        $order   = 'DESC';

        if ( ! empty( $args['stage'] ) ) {
            $where .= ' AND current_stage = %s';
            $values[] = $args['stage'];
        }
        if ( ! empty( $args['payment_status'] ) ) {
            $where .= ' AND payment_status = %s';
            $values[] = $args['payment_status'];
        }
        if ( ! empty( $args['search'] ) ) {
            $search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where .= ' AND (client_name LIKE %s OR client_email LIKE %s OR tracking_code LIKE %s)';
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
        }
        if ( ! empty( $args['orderby'] ) ) {
            $allowed = array( 'created_at', 'session_date', 'client_name', 'current_stage_index', 'updated_at' );
            if ( in_array( $args['orderby'], $allowed ) ) {
                $orderby = $args['orderby'];
            }
        }
        if ( ! empty( $args['order'] ) && in_array( strtoupper( $args['order'] ), array( 'ASC', 'DESC' ) ) ) {
            $order = strtoupper( $args['order'] );
        }

        $limit  = intval( $args['per_page'] ?? 20 );
        $offset = intval( $args['offset'] ?? 0 );

        $sql = "SELECT * FROM $table WHERE $where ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $values[] = $limit;
        $values[] = $offset;

        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare( $sql, $values );
        }

        return $wpdb->get_results( $sql );
    }

    /**
     * Count sessions
     */
    public static function count( $args = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_TABLE_SESSIONS;

        $where  = '1=1';
        $values = array();

        if ( ! empty( $args['stage'] ) ) {
            $where .= ' AND current_stage = %s';
            $values[] = $args['stage'];
        }

        $sql = "SELECT COUNT(*) FROM $table WHERE $where";
        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare( $sql, $values );
        }

        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Update a session
     */
    public static function update( $id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_TABLE_SESSIONS;

        $data['updated_at'] = current_time( 'mysql' );

        return $wpdb->update( $table, $data, array( 'id' => $id ) );
    }

    /**
     * Delete a session
     */
    public static function delete( $id ) {
        global $wpdb;
        $sessions_table = $wpdb->prefix . TWELLER_FLOW_TABLE_SESSIONS;
        $history_table  = $wpdb->prefix . TWELLER_FLOW_TABLE_STAGE_HISTORY;
        $notif_table    = $wpdb->prefix . TWELLER_FLOW_TABLE_NOTIFICATIONS;

        $wpdb->delete( $history_table, array( 'session_id' => $id ) );
        $wpdb->delete( $notif_table, array( 'session_id' => $id ) );
        return $wpdb->delete( $sessions_table, array( 'id' => $id ) );
    }

    /**
     * Advance session to next stage
     */
    public static function advance_stage( $id, $notes = '' ) {
        $session = self::get( $id );
        if ( ! $session ) return false;

        $stage_keys   = TwellerFlow_Database::get_stage_keys();
        $current_idx  = $session->current_stage_index;
        $next_idx     = $current_idx + 1;

        if ( $next_idx >= count( $stage_keys ) ) {
            return false; // Already at final stage
        }

        $next_stage = $stage_keys[ $next_idx ];

        self::update( $id, array(
            'current_stage'       => $next_stage,
            'current_stage_index' => $next_idx,
        ));

        self::record_stage_history( $id, $next_stage, $next_idx, $notes );

        // Trigger notifications if configured
        $stages = TwellerFlow_Database::get_stages();
        if ( ! empty( $stages[ $next_stage ]['notify'] ) ) {
            TwellerFlow_Notifications::on_stage_change( $id, $next_stage );
        }

        return $next_stage;
    }

    /**
     * Set session to a specific stage
     */
    public static function set_stage( $id, $stage, $notes = '' ) {
        $stage_keys = TwellerFlow_Database::get_stage_keys();
        $idx = array_search( $stage, $stage_keys );
        if ( $idx === false ) return false;

        self::update( $id, array(
            'current_stage'       => $stage,
            'current_stage_index' => $idx,
        ));

        self::record_stage_history( $id, $stage, $idx, $notes );

        $stages = TwellerFlow_Database::get_stages();
        if ( ! empty( $stages[ $stage ]['notify'] ) ) {
            TwellerFlow_Notifications::on_stage_change( $id, $stage );
        }

        return $stage;
    }

    /**
     * Record stage change in history
     */
    public static function record_stage_history( $id, $stage, $stage_index, $notes = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_TABLE_STAGE_HISTORY;

        $wpdb->insert( $table, array(
            'session_id'  => $id,
            'stage'       => $stage,
            'stage_index' => $stage_index,
            'timestamp'   => current_time( 'mysql' ),
            'notes'       => $notes,
            'notified'    => 0,
        ));
    }

    /**
     * Get stage history for a session
     */
    public static function get_history( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_TABLE_STAGE_HISTORY;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE session_id = %d ORDER BY timestamp ASC",
            $id
        ));
    }

    /**
     * REST: Get tracking info (public, limited fields)
     */
    public static function rest_track( $request ) {
        $code    = sanitize_text_field( $request['code'] );
        $session = self::get_by_code( $code );

        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $client_stages   = TwellerFlow_Database::get_client_stages();
        $client_stage    = TwellerFlow_Database::get_client_stage( $session->current_stage );
        $client_stage_idx = TwellerFlow_Database::get_client_stage_index( $session->current_stage );

        $history = self::get_history( $session->id );
        $client_history = array();
        $seen = array();
        foreach ( $history as $entry ) {
            $cl = TwellerFlow_Database::get_client_stage( $entry->stage );
            if ( ! in_array( $cl, $seen ) ) {
                $seen[] = $cl;
                $client_history[] = array(
                    'stage'     => $cl,
                    'timestamp' => $entry->timestamp,
                );
            }
        }

        return rest_ensure_response( array(
            'tracking_code'      => $session->tracking_code,
            'client_name'        => $session->client_name,
            'package_type'       => $session->package_type,
            'session_date'       => $session->session_date,
            'current_stage'      => $client_stage,
            'current_stage_index'=> $client_stage_idx,
            'stages'             => $client_stages,
            'estimated_delivery' => $session->estimated_delivery,
            'gallery_url'        => $session->current_stage === 'delivered' ? $session->gallery_url : '',
            'history'            => $client_history,
        ));
    }

    /**
     * REST: List sessions (admin only)
     */
    public static function rest_list( $request ) {
        $sessions = self::get_all( array(
            'search'   => $request->get_param( 'search' ),
            'stage'    => $request->get_param( 'stage' ),
            'per_page' => $request->get_param( 'per_page' ) ?: 20,
            'offset'   => $request->get_param( 'offset' ) ?: 0,
        ));
        return rest_ensure_response( $sessions );
    }

    /**
     * REST: Advance stage (admin only)
     */
    public static function rest_advance( $request ) {
        $id    = intval( $request['id'] );
        $notes = sanitize_text_field( $request->get_param( 'notes' ) ?? '' );
        $result = self::advance_stage( $id, $notes );
        if ( ! $result ) {
            return new WP_Error( 'advance_failed', 'Could not advance stage', array( 'status' => 400 ) );
        }
        return rest_ensure_response( array( 'new_stage' => $result ) );
    }

    /**
     * Get active sessions count (not delivered)
     */
    public static function count_active() {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_TABLE_SESSIONS;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE current_stage != 'delivered'" );
    }

    /**
     * Get sessions by stage for dashboard stats
     */
    public static function get_stage_counts() {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_TABLE_SESSIONS;
        $results = $wpdb->get_results( "SELECT current_stage, COUNT(*) as count FROM $table GROUP BY current_stage" );
        $counts = array();
        foreach ( $results as $row ) {
            $counts[ $row->current_stage ] = (int) $row->count;
        }
        return $counts;
    }

    /**
     * Get revenue stats
     */
    public static function get_revenue_stats() {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_TABLE_SESSIONS;

        $this_month = date( 'Y-m-01' );
        $total = $wpdb->get_var( "SELECT SUM(total_amount) FROM $table" );
        $month = $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(total_amount) FROM $table WHERE created_at >= %s",
            $this_month
        ));
        $deposits = $wpdb->get_var(
            "SELECT SUM(deposit_amount) FROM $table WHERE payment_status = 'deposit'"
        );

        return array(
            'total_revenue'     => floatval( $total ?? 0 ),
            'month_revenue'     => floatval( $month ?? 0 ),
            'pending_deposits'  => floatval( $deposits ?? 0 ),
        );
    }
}
