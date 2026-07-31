<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds GET /wp-json/autoposter/v1/site-info — a small, public, read-only
 * endpoint the Android app calls once after login to render its
 * "Follow us" icon row. Everything else the app does (posts, media,
 * categories, tags) goes straight through WordPress core's own wp/v2 routes.
 */
class AutoPoster_Site_Info_Rest {

    private $settings;

    public function __construct(AutoPoster_Social_Settings $settings) {
        $this->settings = $settings;
    }

    public function register() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        register_rest_route('autoposter/v1', '/site-info', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_request'),
            'permission_callback' => '__return_true',
        ));
    }

    public function handle_request() {
        return new WP_REST_Response(array(
            'site_name'      => get_bloginfo('name'),
            'site_icon_url'  => get_site_icon_url() ?: null,
            'social_links'   => $this->settings->get_links(),
        ), 200);
    }
}
