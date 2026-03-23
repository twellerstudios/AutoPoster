<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow_Database {

    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sessions_table = $wpdb->prefix . TWELLER_FLOW_TABLE_SESSIONS;
        $history_table  = $wpdb->prefix . TWELLER_FLOW_TABLE_STAGE_HISTORY;
        $notif_table    = $wpdb->prefix . TWELLER_FLOW_TABLE_NOTIFICATIONS;

        $sql_sessions = "CREATE TABLE $sessions_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tracking_code varchar(10) NOT NULL,
            client_name varchar(255) NOT NULL,
            client_email varchar(255) DEFAULT '',
            client_phone varchar(50) DEFAULT '',
            package_type varchar(50) DEFAULT 'mini',
            session_date date DEFAULT NULL,
            session_time time DEFAULT NULL,
            location varchar(255) DEFAULT '',
            members_count int DEFAULT 1,
            payment_status varchar(20) DEFAULT 'pending',
            deposit_amount decimal(10,2) DEFAULT 0,
            total_amount decimal(10,2) DEFAULT 0,
            payment_method varchar(50) DEFAULT '',
            current_stage varchar(50) DEFAULT 'booked',
            current_stage_index int DEFAULT 0,
            estimated_delivery date DEFAULT NULL,
            gallery_url varchar(500) DEFAULT '',
            folder_name varchar(255) DEFAULT '',
            photo_count int DEFAULT 0,
            notes text,
            surecart_order_id varchar(100) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY tracking_code (tracking_code),
            KEY current_stage (current_stage),
            KEY session_date (session_date)
        ) $charset_collate;";

        $sql_history = "CREATE TABLE $history_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            stage varchar(50) NOT NULL,
            stage_index int NOT NULL,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            notes text,
            notified tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY session_id (session_id)
        ) $charset_collate;";

        $sql_notifications = "CREATE TABLE $notif_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            type varchar(20) DEFAULT 'email',
            recipient varchar(255) NOT NULL,
            subject varchar(255) DEFAULT '',
            body text,
            status varchar(20) DEFAULT 'sent',
            sent_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql_sessions );
        dbDelta( $sql_history );
        dbDelta( $sql_notifications );
    }

    public static function seed_defaults() {
        $defaults = array(
            'tweller_flow_stages' => array(
                'booked'     => array( 'label' => 'Booked',     'client_label' => 'Booked',       'icon' => 'calendar',     'notify' => true ),
                'imported'   => array( 'label' => 'Imported',   'client_label' => 'Editing',      'icon' => 'download',     'notify' => false ),
                'culling'    => array( 'label' => 'Culling',    'client_label' => 'Editing',      'icon' => 'filter',       'notify' => false ),
                'culled'     => array( 'label' => 'Culled',     'client_label' => 'Editing',      'icon' => 'check-square', 'notify' => false ),
                'editing'    => array( 'label' => 'Editing',    'client_label' => 'Editing',      'icon' => 'edit-2',       'notify' => false ),
                'edited'     => array( 'label' => 'Edited',     'client_label' => 'Done Editing', 'icon' => 'check-circle', 'notify' => true ),
                'delivering' => array( 'label' => 'Delivering', 'client_label' => 'Done Editing', 'icon' => 'send',         'notify' => false ),
                'delivered'  => array( 'label' => 'Delivered',  'client_label' => 'Delivered',    'icon' => 'check-circle', 'notify' => true ),
            ),
            'tweller_flow_client_stages' => array(
                'Booked', 'Editing', 'Done Editing', 'Delivered'
            ),
            'tweller_flow_packages' => array(
                'mini' => array(
                    'name'     => 'Mini Session',
                    'duration' => '30 minutes',
                    'images'   => 15,
                    'members'  => 4,
                    'price'    => 600,
                ),
                'full' => array(
                    'name'     => 'Full Session',
                    'duration' => '45 min - 1 hour',
                    'images'   => 40,
                    'members'  => 8,
                    'price'    => 800,
                ),
                'extended' => array(
                    'name'     => 'Extended Session',
                    'duration' => '1.5 hours',
                    'images'   => 60,
                    'members'  => 10,
                    'price'    => 1100,
                ),
            ),
            'tweller_flow_banking' => "Account Number: 2430006\nName: Tweller Studios\nBank: FCB\nBusiness savings\n\nPlease send a picture of the transaction to confirm.",
            'tweller_flow_smtp' => array(
                'host'       => 'smtp.zoho.com',
                'port'       => 465,
                'encryption' => 'ssl',
                'username'   => '',
                'password'   => '',
                'from_name'  => 'Tweller Studios',
                'from_email' => '',
            ),
            'tweller_flow_delivery_days' => 14,
        );

        foreach ( $defaults as $key => $value ) {
            // Always update stages to ensure new pipeline is in place
            if ( $key === 'tweller_flow_stages' || $key === 'tweller_flow_client_stages' ) {
                update_option( $key, $value );
            } elseif ( get_option( $key ) === false ) {
                add_option( $key, $value );
            }
        }
    }

    public static function get_stages() {
        return get_option( 'tweller_flow_stages', array() );
    }

    public static function get_stage_keys() {
        return array_keys( self::get_stages() );
    }

    public static function get_client_stages() {
        return get_option( 'tweller_flow_client_stages', array() );
    }

    public static function get_client_stage( $internal_stage ) {
        $stages = self::get_stages();
        if ( isset( $stages[ $internal_stage ] ) ) {
            return $stages[ $internal_stage ]['client_label'];
        }
        return 'Unknown';
    }

    public static function get_client_stage_index( $internal_stage ) {
        $client_stages = self::get_client_stages();
        $client_label  = self::get_client_stage( $internal_stage );
        $index = array_search( $client_label, $client_stages );
        return $index !== false ? $index : 0;
    }
}
