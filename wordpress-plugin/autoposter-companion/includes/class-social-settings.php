<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings → AutoPoster Companion: lets the site owner fill in their social
 * profile URLs once, from wp-admin. The Android app and the public-facing
 * "Follow us" row both read from the same option.
 */
class AutoPoster_Social_Settings {

    const OPTION_KEY = 'autoposter_social_links';

    /** Platform key => human label. Keys match what the Android app expects. */
    public static function platforms() {
        return array(
            'facebook'  => __('Facebook', 'autoposter-companion'),
            'instagram' => __('Instagram', 'autoposter-companion'),
            'tiktok'    => __('TikTok', 'autoposter-companion'),
            'youtube'   => __('YouTube', 'autoposter-companion'),
            'x'         => __('X (Twitter)', 'autoposter-companion'),
            'pinterest' => __('Pinterest', 'autoposter-companion'),
            'linkedin'  => __('LinkedIn', 'autoposter-companion'),
            'website'   => __('Website', 'autoposter-companion'),
        );
    }

    public function register() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function add_settings_page() {
        add_options_page(
            __('AutoPoster Companion', 'autoposter-companion'),
            __('AutoPoster Companion', 'autoposter-companion'),
            'manage_options',
            'autoposter-companion',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('autoposter_companion', self::OPTION_KEY, array(
            'type'              => 'array',
            'sanitize_callback' => array($this, 'sanitize_links'),
            'default'           => array(),
        ));

        add_settings_section(
            'autoposter_companion_social',
            __('Social media links', 'autoposter-companion'),
            function () {
                echo '<p>' . esc_html__('Shown as tappable icons in the Journey To Poster app and appended to every published post. Leave a field blank to hide that icon.', 'autoposter-companion') . '</p>';
            },
            'autoposter-companion'
        );

        foreach (self::platforms() as $key => $label) {
            add_settings_field(
                'autoposter_social_' . $key,
                esc_html($label),
                array($this, 'render_field'),
                'autoposter-companion',
                'autoposter_companion_social',
                array('key' => $key)
            );
        }
    }

    public function render_field($args) {
        $key   = $args['key'];
        $links = $this->get_links();
        $value = isset($links[$key]) ? $links[$key] : '';
        printf(
            '<input type="url" class="regular-text" name="%1$s[%2$s]" value="%3$s" placeholder="https://…" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($key),
            esc_attr($value)
        );
    }

    public function sanitize_links($input) {
        $clean = array();
        if (!is_array($input)) {
            return $clean;
        }
        foreach (self::platforms() as $key => $label) {
            if (!empty($input[$key])) {
                $url = esc_url_raw(trim($input[$key]));
                if ($url) {
                    $clean[$key] = $url;
                }
            }
        }
        return $clean;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('AutoPoster Companion', 'autoposter-companion'); ?></h1>
            <p><?php esc_html_e('Companion settings for the Journey To Poster Android app.', 'autoposter-companion'); ?></p>
            <form method="post" action="options.php">
                <?php
                settings_fields('autoposter_companion');
                do_settings_sections('autoposter-companion');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /** @return array<string,string> platform key => URL, only configured entries. */
    public function get_links() {
        $links = get_option(self::OPTION_KEY, array());
        return is_array($links) ? $links : array();
    }
}
