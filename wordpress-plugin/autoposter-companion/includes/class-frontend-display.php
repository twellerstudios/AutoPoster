<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Appends a small "Follow us" social icon row to the end of every published
 * post's content, using the links configured in Settings → AutoPoster
 * Companion. Also enqueues a light stylesheet so the row (and the
 * TikTok/YouTube embed blocks the app inserts) look tidy on any theme.
 */
class AutoPoster_Frontend_Display {

    private $settings;

    public function __construct(AutoPoster_Social_Settings $settings) {
        $this->settings = $settings;
    }

    public function register() {
        add_filter('the_content', array($this, 'append_follow_row'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
    }

    public function enqueue_styles() {
        if (!is_singular('post')) {
            return;
        }
        wp_enqueue_style(
            'autoposter-companion-frontend',
            AUTOPOSTER_COMPANION_URL . 'assets/css/autoposter-frontend.css',
            array(),
            AUTOPOSTER_COMPANION_VERSION
        );
    }

    public function append_follow_row($content) {
        if (!is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $links = $this->settings->get_links();
        if (empty($links)) {
            return $content;
        }

        $icons = '';
        foreach (AutoPoster_Social_Settings::platforms() as $key => $label) {
            if (empty($links[$key])) {
                continue;
            }
            $icons .= sprintf(
                '<a class="autoposter-follow-icon autoposter-follow-%1$s" href="%2$s" target="_blank" rel="noopener noreferrer" aria-label="%3$s">%4$s</a>',
                esc_attr($key),
                esc_url($links[$key]),
                esc_attr($label),
                $this->icon_svg($key)
            );
        }

        if ($icons === '') {
            return $content;
        }

        $row = sprintf(
            '<div class="autoposter-follow-row"><p class="autoposter-follow-label">%s</p><div class="autoposter-follow-icons">%s</div></div>',
            esc_html__('Follow Journey To', 'autoposter-companion'),
            $icons
        );

        return $content . $row;
    }

    /** Minimal inline monogram icons — no external requests, no trademarked artwork. */
    private function icon_svg($key) {
        $initials = array(
            'facebook'  => 'f',
            'instagram' => 'ig',
            'tiktok'    => 'tt',
            'youtube'   => '▶',
            'x'         => 'x',
            'pinterest' => 'p',
            'linkedin'  => 'in',
            'website'   => '🌐',
        );
        $letter = isset($initials[$key]) ? $initials[$key] : '?';
        return '<span class="autoposter-follow-glyph">' . esc_html($letter) . '</span>';
    }
}
