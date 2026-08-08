<?php
/**
 * The `tepe_event_gallery` custom post type + its meta schema.
 *
 * NOTE: WordPress limits post_type names to 20 characters (wp_posts.post_type
 * is varchar(20)); "tweller_event_gallery" is 21, which is why we use this
 * shorter internal key. The public-facing URL is still /event/{slug}/.
 *
 * One post == one event gallery. The post's slug drives the public URLs
 * (/event/{slug}/ and /event/{slug}/upload/). All per-event configuration
 * lives in post meta so it's easy to query, cache and extend.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TEPE_CPT {

    /** Meta keys (single source of truth). */
    const META_HOST_EMAIL    = '_tepe_host_email';
    const META_HOST_NAME     = '_tepe_host_name';
    const META_IS_MONETIZED  = '_tepe_is_monetized';   // 0|1 — toggle paid features later
    const META_PRICING_TIER  = '_tepe_pricing_tier';   // free|standard|premium (future billing)
    const META_UPLOAD_LIMIT  = '_tepe_upload_limit';    // max photos per guest device (0 = unlimited)
    const META_EVENT_LIMIT   = '_tepe_event_limit';     // max photos for the whole event (0 = unlimited)
    const META_IS_LOCKED     = '_tepe_is_locked';       // 0|1 — freeze uploads
    const META_IS_FEATURED   = '_tepe_is_featured';     // 0|1 — surface in studio showcases
    const META_CATEGORIES    = '_tepe_categories';      // array of buckets (see TEPE_Categories)
    const META_ALLOW_ALBUMS  = '_tepe_allow_guest_albums'; // 0|1 — guests may create public sub-albums
    const META_MODERATE      = '_tepe_moderate';        // 0|1 — hold uploads for host approval
    const META_SESSION_ID    = '_tepe_session_id';      // linked Tweller Bookings session (0 = self-service)
    const META_SESSION_CODE  = '_tepe_session_code';
    const META_IS_BOOKED     = '_tepe_is_booking_linked'; // 0|1 — created from a paid booking?
    const META_WELCOME       = '_tepe_welcome_message';
    const META_COVER         = '_tepe_cover_upload_id';
    const META_EVENT_DATE    = '_tepe_event_date';
    const META_ALLOW_PRINTS  = '_tepe_allow_prints';    // 0|1 — enable print ordering from the gallery
    const META_SHARE_REWARD  = '_tepe_share_reward';    // 0|1 — enable the social-share promo engine

    public static function init() {
        // tepe_init() already runs on `init` (priority 5), so register the CPT
        // directly here rather than queueing another init callback that would
        // never fire. Guarded so a double call is harmless.
        if ( ! post_type_exists( TEPE_CPT ) ) {
            self::register();
        }
    }

    public static function register() {
        register_post_type( TEPE_CPT, array(
            'labels' => array(
                'name'          => 'Event Galleries',
                'singular_name' => 'Event Gallery',
                'add_new_item'  => 'Add New Event Gallery',
                'edit_item'     => 'Edit Event Gallery',
                'menu_name'     => 'Event Galleries',
            ),
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => false, // managed through our own admin screen under Tweller Bookings
            'show_in_menu'       => false,
            'show_in_rest'       => false,
            'has_archive'        => false,
            'hierarchical'       => false,
            'exclude_from_search'=> true,
            'rewrite'            => array( 'slug' => 'event', 'with_front' => false ),
            'supports'           => array( 'title', 'editor', 'thumbnail' ),
            'capability_type'    => 'post',
            'menu_icon'          => 'dashicons-format-gallery',
        ) );
    }

    /** Default meta applied to a freshly created event. */
    public static function default_meta() {
        return array(
            self::META_HOST_EMAIL   => '',
            self::META_HOST_NAME    => '',
            self::META_IS_MONETIZED => 0,
            self::META_PRICING_TIER => 'free',
            self::META_UPLOAD_LIMIT => 0,
            self::META_EVENT_LIMIT  => 0,
            self::META_IS_LOCKED    => 0,
            self::META_IS_FEATURED  => 0,
            self::META_ALLOW_ALBUMS => 1,
            self::META_MODERATE     => 0,
            self::META_SESSION_ID   => 0,
            self::META_SESSION_CODE => '',
            self::META_IS_BOOKED    => 0,
            self::META_WELCOME      => '',
            self::META_COVER        => 0,
            self::META_EVENT_DATE   => '',
            self::META_ALLOW_PRINTS => 1,
            self::META_SHARE_REWARD => 1,
        );
    }
}
