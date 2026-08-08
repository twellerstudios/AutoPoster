<?php
/**
 * Custom database tables for the Event Photo Engine.
 *
 * All tables are created with dbDelta and are self-healing via
 * TEPE_Database::install(), which runs on activation and whenever the stored
 * db version is behind TEPE_DB_VERSION.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TEPE_Database {

    /** Resolve a full, prefixed table name. */
    public static function table( $name ) {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    public static function install() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $uploads = self::table( TEPE_TABLE_UPLOADS );
        $guests  = self::table( TEPE_TABLE_GUESTS );
        $orders  = self::table( TEPE_TABLE_ORDERS );
        $promos  = self::table( TEPE_TABLE_PROMOS );
        $shares  = self::table( TEPE_TABLE_SHARES );

        // ── Uploads: one row per guest-contributed photo ──────────────────
        // category is the slug of a bucket/sub-album on the event; empty means
        // the public album. status supports light moderation (host can lock).
        dbDelta( "CREATE TABLE $uploads (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            guest_id bigint(20) unsigned DEFAULT NULL,
            category varchar(120) NOT NULL DEFAULT '',
            filename varchar(255) NOT NULL,
            original_name varchar(255) NOT NULL DEFAULT '',
            mime varchar(60) NOT NULL DEFAULT '',
            width int DEFAULT 0,
            height int DEFAULT 0,
            filesize bigint(20) unsigned DEFAULT 0,
            caption varchar(500) NOT NULL DEFAULT '',
            ip_hash char(64) NOT NULL DEFAULT '',
            fingerprint_hash char(64) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'approved',
            sort_order int DEFAULT 0,
            likes int unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY event_id (event_id),
            KEY guest_id (guest_id),
            KEY category (category),
            KEY status (status)
        ) $charset_collate;" );

        // ── Guests: zero-signup device identities ─────────────────────────
        // A guest is identified by the strongest of (fingerprint, cookie uid,
        // ip) available. We keep denormalised counters so the host dashboard
        // and checkout can read them cheaply.
        dbDelta( "CREATE TABLE $guests (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            guest_uid char(40) NOT NULL DEFAULT '',
            fingerprint_hash char(64) NOT NULL DEFAULT '',
            ip_hash char(64) NOT NULL DEFAULT '',
            display_name varchar(190) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            phone varchar(64) NOT NULL DEFAULT '',
            upload_count int unsigned NOT NULL DEFAULT 0,
            order_count int unsigned NOT NULL DEFAULT 0,
            user_agent varchar(255) NOT NULL DEFAULT '',
            first_seen datetime DEFAULT CURRENT_TIMESTAMP,
            last_seen datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY event_id (event_id),
            KEY guest_uid (guest_uid),
            KEY fingerprint_hash (fingerprint_hash)
        ) $charset_collate;" );

        // ── Native print orders (no WooCommerce) ──────────────────────────
        // items is a JSON array of line objects. payment_method is one of
        // bank | cash. delivery_option is delivery | event | meetup.
        dbDelta( "CREATE TABLE $orders (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_ref varchar(32) NOT NULL,
            event_id bigint(20) unsigned DEFAULT NULL,
            guest_id bigint(20) unsigned DEFAULT NULL,
            customer_name varchar(190) NOT NULL DEFAULT '',
            customer_email varchar(190) NOT NULL DEFAULT '',
            customer_phone varchar(64) NOT NULL DEFAULT '',
            items longtext,
            subtotal decimal(10,2) NOT NULL DEFAULT 0,
            discount decimal(10,2) NOT NULL DEFAULT 0,
            total decimal(10,2) NOT NULL DEFAULT 0,
            payment_method varchar(20) NOT NULL DEFAULT 'bank',
            delivery_option varchar(30) NOT NULL DEFAULT 'event',
            delivery_detail varchar(255) NOT NULL DEFAULT '',
            promo_code varchar(40) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'new',
            notes text,
            bridged_order_ref varchar(32) NOT NULL DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY order_ref (order_ref),
            KEY event_id (event_id),
            KEY guest_id (guest_id),
            KEY status (status)
        ) $charset_collate;" );

        // ── Share-reward promo codes (single use) ─────────────────────────
        dbDelta( "CREATE TABLE $promos (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(40) NOT NULL,
            event_id bigint(20) unsigned DEFAULT NULL,
            guest_id bigint(20) unsigned DEFAULT NULL,
            reward varchar(40) NOT NULL DEFAULT 'free_4x6',
            reward_label varchar(190) NOT NULL DEFAULT 'Free 4x6 Print',
            reward_value decimal(10,2) NOT NULL DEFAULT 0,
            platform varchar(40) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'issued',
            order_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            redeemed_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY event_id (event_id),
            KEY guest_id (guest_id),
            KEY status (status)
        ) $charset_collate;" );

        // ── Social-share action log ───────────────────────────────────────
        dbDelta( "CREATE TABLE $shares (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            guest_id bigint(20) unsigned DEFAULT NULL,
            platform varchar(40) NOT NULL DEFAULT '',
            ip_hash char(64) NOT NULL DEFAULT '',
            promo_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY event_id (event_id),
            KEY guest_id (guest_id)
        ) $charset_collate;" );
    }

    /**
     * Convenience: does a column exist? Used by future migrations.
     */
    public static function column_exists( $table, $column ) {
        global $wpdb;
        $found = $wpdb->get_results( $wpdb->prepare(
            "SHOW COLUMNS FROM `$table` LIKE %s", $column
        ) );
        return ! empty( $found );
    }
}
