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
                'booked'     => array( 'label' => 'Reserved',     'client_label' => 'Reserved',        'icon' => 'calendar',     'notify' => true ),
                'confirmed'  => array( 'label' => 'Booking Confirmed', 'client_label' => 'Booking Confirmed', 'icon' => 'check-square', 'notify' => true ),
                'imported'   => array( 'label' => 'Imported',   'client_label' => 'Editing',       'icon' => 'download',     'notify' => false ),
                'culling'    => array( 'label' => 'Culling',    'client_label' => 'Select Photos for Editing', 'icon' => 'filter', 'notify' => false ),
                'culled'     => array( 'label' => 'Culled',     'client_label' => 'Editing',       'icon' => 'check-square', 'notify' => false ),
                'editing'    => array( 'label' => 'Editing',    'client_label' => 'Editing',       'icon' => 'edit-2',       'notify' => false ),
                'edited'     => array( 'label' => 'Edited',     'client_label' => 'Editing',       'icon' => 'check-circle', 'notify' => true ),
                'exporting'  => array( 'label' => 'Exporting',  'client_label' => 'Editing',       'icon' => 'package',      'notify' => false ),
                'exported'   => array( 'label' => 'Exported',   'client_label' => 'Editing',       'icon' => 'package',      'notify' => false ),
                'uploading'  => array( 'label' => 'Uploading',  'client_label' => 'Gallery Ready', 'icon' => 'upload',       'notify' => false ),
                'uploaded'   => array( 'label' => 'Uploaded',   'client_label' => 'Gallery Ready', 'icon' => 'upload',       'notify' => false ),
                'delivered'  => array( 'label' => 'Delivered',  'client_label' => 'Delivered',     'icon' => 'check-circle', 'notify' => true ),
            ),
            'tweller_flow_client_stages' => array(
                'Reserved', 'Booking Confirmed', 'Select Photos for Editing', 'Editing', 'Gallery Ready', 'Delivered'
            ),
            'tweller_flow_session_types' => array(
                'mommy_and_me'    => array( 'name' => 'Mommy & Me', 'description' => 'Just mom and baby/kid.', 'allowed_packages' => array('mini', 'full', 'extended') ),
                'mommy_and_us'    => array( 'name' => 'Mommy & Us', 'description' => 'Mom and her entire family.', 'allowed_packages' => array('mini', 'full', 'extended') ),
                'one_year'        => array( 'name' => '1 Year Photos', 'description' => 'Capture the first milestone.', 'allowed_packages' => array('mini', 'full', 'extended') ),
                'family'          => array( 'name' => 'Family Portraits', 'description' => 'Beautiful family memories.', 'allowed_packages' => array('mini', 'full', 'extended') ),
                'headshots'       => array( 'name' => 'Headshots', 'description' => 'Professional headshots.', 'allowed_packages' => array('mini', 'full', 'extended') ),
                'maternity'       => array( 'name' => 'Maternity', 'description' => 'Celebrate expecting a new life.', 'allowed_packages' => array('mini', 'full', 'extended') ),
                'grads'           => array( 'name' => 'Grads', 'description' => 'Celebrate your graduation.', 'allowed_packages' => array('mini', 'full', 'extended') ),
                'cake_smashes'    => array( 'name' => 'Cake Smashes', 'description' => 'Messy fun for their birthday.', 'allowed_packages' => array('full') ),
                'events'          => array( 'name' => 'Events', 'description' => 'Birthdays, Showers, Graduations.', 'allowed_packages' => array('event_1hr', 'event_2hr', 'event_3hr', 'event_4hr') ),
                'weddings'        => array( 'name' => 'Weddings', 'description' => 'Send us an inquiry for your big day!', 'allowed_packages' => array() ),
            ),
            'tweller_flow_packages' => array(
                'mini' => array(
                    'name'     => 'Mini Session',
                    'duration' => 30, // in minutes
                    'images'   => 10,
                    'members'  => 4,
                    'price'    => 500,
                    'old_price'=> 550,
                    'features' => array('Up to 30 minutes', '10 fully edited images', 'All digital copies included', 'Studio or on-location')
                ),
                'full' => array(
                    'name'     => 'Full Session',
                    'duration' => 60,
                    'images'   => 30,
                    'members'  => 8,
                    'price'    => 700,
                    'old_price'=> 750,
                    'features' => array('Up to 1 hour', '30 fully edited images', 'All digital copies included', 'Studio or on-location', 'Outfit change included')
                ),
                'extended' => array(
                    'name'     => 'Extended Session',
                    'duration' => 120,
                    'images'   => 50,
                    'members'  => 10,
                    'price'    => 900,
                    'old_price'=> 1050,
                    'features' => array('Up to 2 hours', '50+ fully edited images', 'All digital copies included', 'Studio or on-location', 'Multiple outfit changes')
                ),
                'event_1hr' => array(
                    'name'     => '1 Hour Event Coverage',
                    'duration' => 60,
                    'images'   => 999,
                    'members'  => 50,
                    'price'    => 550,
                    'old_price'=> 0,
                    'features' => array('Unlimited edited images', 'Candid moments & group shots', 'Perfect for intimate gatherings')
                ),
                'event_2hr' => array(
                    'name'     => '2 Hour Event Coverage',
                    'duration' => 120,
                    'images'   => 999,
                    'members'  => 50,
                    'price'    => 750,
                    'old_price'=> 0,
                    'features' => array('Unlimited edited images', 'Full event storytelling', 'Great for medium-sized parties')
                ),
                'event_3hr' => array(
                    'name'     => '3 Hour Event Coverage',
                    'duration' => 180,
                    'images'   => 999,
                    'members'  => 50,
                    'price'    => 950,
                    'old_price'=> 0,
                    'features' => array('Unlimited edited images', 'Comprehensive coverage', 'Ideal for large celebrations')
                ),
                'event_4hr' => array(
                    'name'     => '4 Hour Event Coverage',
                    'duration' => 240,
                    'images'   => 999,
                    'members'  => 50,
                    'price'    => 1150,
                    'old_price'=> 0,
                    'features' => array('Unlimited edited images', 'Non-stop documentation', 'Best for major events')
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
            if ( $key === 'tweller_flow_stages' || $key === 'tweller_flow_client_stages' || $key === 'tweller_flow_packages' || $key === 'tweller_flow_session_types' ) {
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
