<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Session {

    /**
     * Generate a human-readable "Shoot Code" for a session.
     * Format: 04-July-2024-JohnDoe-Mini (date-ClientName-Package).
     * Falls back to a random code only when no client data is available.
     */
    public static function generate_tracking_code( $data = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;

        $date_ts  = ! empty( $data['session_date'] ) ? strtotime( $data['session_date'] ) : current_time( 'timestamp' );
        $date_str = date( 'd-F-Y', $date_ts );

        $name = preg_replace( '/[^A-Za-z0-9]/', '', ucwords( strtolower( trim( $data['client_name'] ?? '' ) ) ) );
        if ( $name === '' ) {
            $name = strtoupper( substr( md5( uniqid( mt_rand(), true ) ), 0, 6 ) );
        }

        $pkg = preg_replace( '/[^A-Za-z0-9]/', '', ucwords( str_replace( '_', ' ', strtolower( $data['package_type'] ?? '' ) ) ) );

        $base = substr( $date_str . '-' . $name . ( $pkg ? '-' . $pkg : '' ), 0, 110 );

        $code = $base;
        $i = 2;
        while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE tracking_code = %s", $code ) ) > 0 ) {
            $code = $base . '-' . $i;
            $i++;
        }
        return $code;
    }

    public static function create( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;

        $tracking_code = self::generate_tracking_code( $data );
        $delivery_days = get_option( 'tweller_flow_2_delivery_days', 14 );

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
            'folder_name'        => '',
            'photo_count'        => 0,
            'notes'              => sanitize_textarea_field( $data['notes'] ?? '' ),
            'surecart_order_id'  => sanitize_text_field( $data['surecart_order_id'] ?? '' ),
            'created_at'         => current_time( 'mysql' ),
            'updated_at'         => current_time( 'mysql' ),
        );

        $wpdb->insert( $table, $insert_data );
        $session_id = $wpdb->insert_id;

        if ( $session_id ) {
            self::record_stage_history( $session_id, 'booked', 0, 'Session created' );

            if ( empty( $data['skip_notifications'] ) ) {
                TwellerFlow2_Notifications::on_stage_change( $session_id, 'booked' );
            }
            do_action( 'tweller_flow_2_session_created', $session_id, $data );

            // Send tentative calendar hold if date is set
            if ( empty( $data['skip_notifications'] ) ) {
                $session = self::get( $session_id );
                if ( $session && !empty($session->session_date) ) {
                    TwellerFlow2_Notifications::send_calendar_invite( $session, 'TENTATIVE', 0 );
                }
            }
        }

        return $session_id;
    }

    public static function get( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
    }

    public static function get_by_code( $code ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE tracking_code = %s", $code ) );
    }

    public static function get_all( $args = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;

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

    public static function count( $args = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;

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

    public static function update( $id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;
        $data['updated_at'] = current_time( 'mysql' );
        return $wpdb->update( $table, $data, array( 'id' => $id ) );
    }

    public static function delete( $id ) {
        global $wpdb;
        $sessions_table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;
        $history_table  = $wpdb->prefix . TWELLER_FLOW_2_TABLE_STAGE_HISTORY;
        $notif_table    = $wpdb->prefix . TWELLER_FLOW_2_TABLE_NOTIFICATIONS;

        $wpdb->delete( $history_table, array( 'session_id' => $id ) );
        $wpdb->delete( $notif_table, array( 'session_id' => $id ) );
        return $wpdb->delete( $sessions_table, array( 'id' => $id ) );
    }

    public static function advance_stage( $id, $notes = '', $notify = true ) {
        $session = self::get( $id );
        if ( ! $session ) return false;

        $stage_keys   = TwellerFlow2_Database::get_stage_keys();
        $current_idx  = $session->current_stage_index;
        $next_idx     = $current_idx + 1;

        if ( $next_idx >= count( $stage_keys ) ) {
            return false;
        }

        $next_stage = $stage_keys[ $next_idx ];

        self::update( $id, array(
            'current_stage'       => $next_stage,
            'current_stage_index' => $next_idx,
        ));

        self::record_stage_history( $id, $next_stage, $next_idx, $notes );

        $stages = TwellerFlow2_Database::get_stages();
        if ( $notify && ! empty( $stages[ $next_stage ]['notify'] ) ) {
            TwellerFlow2_Notifications::on_stage_change( $id, $next_stage );
        }

        return $next_stage;
    }

    public static function set_stage( $id, $stage, $notes = '', $notify = true ) {
        $stage_keys = TwellerFlow2_Database::get_stage_keys();
        $idx = array_search( $stage, $stage_keys );
        if ( $idx === false ) return false;

        self::update( $id, array(
            'current_stage'       => $stage,
            'current_stage_index' => $idx,
        ));

        self::record_stage_history( $id, $stage, $idx, $notes );

        $stages = TwellerFlow2_Database::get_stages();
        if ( $notify && ! empty( $stages[ $stage ]['notify'] ) ) {
            TwellerFlow2_Notifications::on_stage_change( $id, $stage );
        }

        return $stage;
    }

    public static function record_stage_history( $id, $stage, $stage_index, $notes = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_STAGE_HISTORY;
        $wpdb->insert( $table, array(
            'session_id'  => $id,
            'stage'       => $stage,
            'stage_index' => $stage_index,
            'timestamp'   => current_time( 'mysql' ),
            'notes'       => $notes,
            'notified'    => 0,
        ));
    }

    public static function get_history( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_STAGE_HISTORY;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE session_id = %d ORDER BY timestamp ASC",
            $id
        ));
    }

    public static function rest_track( $request ) {
        $code    = sanitize_text_field( $request['code'] );
        $session = self::get_by_code( $code );

        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
        }

        $client_stages   = TwellerFlow2_Database::get_client_stages();
        $client_stage    = TwellerFlow2_Database::get_client_stage( $session->current_stage );
        $client_stage_idx = TwellerFlow2_Database::get_client_stage_index( $session->current_stage );

        $history = self::get_history( $session->id );
        $client_history = array();
        $seen = array();
        foreach ( $history as $entry ) {
            $cl = TwellerFlow2_Database::get_client_stage( $entry->stage );
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
            'internal_stage'     => $session->current_stage,
            'stages'             => $client_stages,
            'estimated_delivery' => $session->estimated_delivery,
            'gallery_url'        => in_array( $session->current_stage, array( 'uploaded', 'delivered' ), true ) ? $session->gallery_url : '',
            'history'            => $client_history,
        ));
    }

    public static function rest_list( $request ) {
        $sessions = self::get_all( array(
            'search'   => $request->get_param( 'search' ),
            'stage'    => $request->get_param( 'stage' ),
            'per_page' => $request->get_param( 'per_page' ) ?: 20,
            'offset'   => $request->get_param( 'offset' ) ?: 0,
        ));
        return rest_ensure_response( $sessions );
    }

    public static function rest_advance( $request ) {
        $id    = intval( $request['id'] );
        $notes = sanitize_text_field( $request->get_param( 'notes' ) ?? '' );
        $result = self::advance_stage( $id, $notes );
        if ( ! $result ) {
            return new WP_Error( 'advance_failed', 'Could not advance stage', array( 'status' => 400 ) );
        }
        return rest_ensure_response( array( 'new_stage' => $result ) );
    }

    public static function count_active() {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE current_stage != 'delivered'" );
    }

    public static function get_stage_counts() {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;
        $results = $wpdb->get_results( "SELECT current_stage, COUNT(*) as count FROM $table GROUP BY current_stage" );
        $counts = array();
        foreach ( $results as $row ) {
            $counts[ $row->current_stage ] = (int) $row->count;
        }
        return $counts;
    }

    public static function get_revenue_stats() {
        global $wpdb;
        $table = $wpdb->prefix . TWELLER_FLOW_2_TABLE_SESSIONS;

        $this_month = date( 'Y-m-01' );
        $total = $wpdb->get_var( "SELECT SUM(total_amount) FROM $table" );
        $month = $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(total_amount) FROM $table WHERE created_at >= %s",
            $this_month
        ));

        return array(
            'total_revenue' => floatval( $total ?? 0 ),
            'month_revenue' => floatval( $month ?? 0 ),
        );
    }
}
