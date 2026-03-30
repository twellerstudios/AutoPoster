<?php
/**
 * Tweller Studios Theme Functions
 *
 * @package TwellerStudios
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TWELLER_VERSION', '1.0.0' );
define( 'TWELLER_DIR', get_template_directory() );
define( 'TWELLER_URI', get_template_directory_uri() );

/**
 * Load the specials configuration
 */
require_once TWELLER_DIR . '/inc/specials-config.php';

/**
 * Theme Setup
 */
function tweller_setup() {
    // Add title tag support
    add_theme_support( 'title-tag' );

    // Post thumbnails
    add_theme_support( 'post-thumbnails' );
    add_image_size( 'tweller-hero', 1920, 1080, true );
    add_image_size( 'tweller-gallery', 800, 600, true );
    add_image_size( 'tweller-card', 600, 400, true );

    // Custom logo
    add_theme_support( 'custom-logo', array(
        'height'      => 100,
        'width'       => 300,
        'flex-height' => true,
        'flex-width'  => true,
    ) );

    // Custom header image (used as hero background)
    add_theme_support( 'custom-header', array(
        'default-image' => '',
        'width'         => 1920,
        'height'        => 1080,
        'flex-width'    => true,
        'flex-height'   => true,
    ) );

    // HTML5 support
    add_theme_support( 'html5', array(
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
        'style',
        'script',
    ) );

    // Register nav menus
    register_nav_menus( array(
        'primary'   => __( 'Primary Menu', 'tweller-studios' ),
        'footer'    => __( 'Footer Menu', 'tweller-studios' ),
    ) );
}
add_action( 'after_setup_theme', 'tweller_setup' );

/**
 * Enqueue styles and scripts
 */
function tweller_scripts() {
    // Google Fonts
    wp_enqueue_style(
        'tweller-google-fonts',
        'https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700&family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&display=swap',
        array(),
        null
    );

    // Main stylesheet
    wp_enqueue_style(
        'tweller-style',
        get_stylesheet_uri(),
        array( 'tweller-google-fonts' ),
        TWELLER_VERSION
    );

    // Main JS
    wp_enqueue_script(
        'tweller-main',
        TWELLER_URI . '/assets/js/main.js',
        array(),
        TWELLER_VERSION,
        true
    );

    // Pass data to JS
    wp_localize_script( 'tweller-main', 'twellerData', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'siteUrl' => home_url(),
    ) );
}
add_action( 'wp_enqueue_scripts', 'tweller_scripts' );

/**
 * Custom Walker for primary nav
 */
class Tweller_Nav_Walker extends Walker_Nav_Menu {
    public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
        $classes = empty( $item->classes ) ? array() : (array) $item->classes;
        $is_cta = in_array( 'menu-cta', $classes, true );

        $class_string = 'tw-nav__link';
        if ( in_array( 'current-menu-item', $classes, true ) ) {
            $class_string .= ' tw-nav__link--active';
        }
        if ( $is_cta ) {
            $class_string = 'tw-btn tw-btn--outline tw-nav__cta';
        }

        $output .= sprintf(
            '<a href="%s" class="%s">%s</a>',
            esc_url( $item->url ),
            esc_attr( $class_string ),
            esc_html( $item->title )
        );
    }

    public function end_el( &$output, $item, $depth = 0, $args = null ) {
        // No closing li tag needed since we output links directly
    }

    public function start_lvl( &$output, $depth = 0, $args = null ) {}
    public function end_lvl( &$output, $depth = 0, $args = null ) {}
}

/**
 * Customizer settings
 */
function tweller_customizer( $wp_customize ) {
    // Hero Section
    $wp_customize->add_section( 'tweller_hero', array(
        'title'    => __( 'Hero Section', 'tweller-studios' ),
        'priority' => 30,
    ) );

    $wp_customize->add_setting( 'tweller_hero_bg', array(
        'default'           => '',
        'sanitize_callback' => 'esc_url_raw',
    ) );
    $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'tweller_hero_bg', array(
        'label'   => __( 'Hero Background Image', 'tweller-studios' ),
        'section' => 'tweller_hero',
    ) ) );

    $wp_customize->add_setting( 'tweller_hero_tagline', array(
        'default'           => 'Tweller Studios',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_hero_tagline', array(
        'label'   => __( 'Hero Tagline (above title)', 'tweller-studios' ),
        'section' => 'tweller_hero',
        'type'    => 'text',
    ) );

    $wp_customize->add_setting( 'tweller_hero_title_1', array(
        'default'           => 'Your Story',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_hero_title_1', array(
        'label'   => __( 'Hero Title Line 1', 'tweller-studios' ),
        'section' => 'tweller_hero',
        'type'    => 'text',
    ) );

    $wp_customize->add_setting( 'tweller_hero_title_2', array(
        'default'           => 'Deserves This',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_hero_title_2', array(
        'label'   => __( 'Hero Title Line 2 (gold)', 'tweller-studios' ),
        'section' => 'tweller_hero',
        'type'    => 'text',
    ) );

    $wp_customize->add_setting( 'tweller_hero_subtitle', array(
        'default'           => 'Photography & videography that feels like you. No stiff poses, no awkward smiles — just the real, beautiful moments that matter most.',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_hero_subtitle', array(
        'label'   => __( 'Hero Subtitle', 'tweller-studios' ),
        'section' => 'tweller_hero',
        'type'    => 'textarea',
    ) );

    $wp_customize->add_setting( 'tweller_hero_btn_text', array(
        'default'           => 'View Specials',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_hero_btn_text', array(
        'label'   => __( 'Primary Button Text', 'tweller-studios' ),
        'section' => 'tweller_hero',
        'type'    => 'text',
    ) );

    $wp_customize->add_setting( 'tweller_hero_btn_url', array(
        'default'           => '#specials',
        'sanitize_callback' => 'esc_url_raw',
    ) );
    $wp_customize->add_control( 'tweller_hero_btn_url', array(
        'label'   => __( 'Primary Button URL', 'tweller-studios' ),
        'section' => 'tweller_hero',
        'type'    => 'url',
    ) );

    $wp_customize->add_setting( 'tweller_hero_btn2_text', array(
        'default'           => 'Our Work',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_hero_btn2_text', array(
        'label'   => __( 'Secondary Button Text', 'tweller-studios' ),
        'section' => 'tweller_hero',
        'type'    => 'text',
    ) );

    $wp_customize->add_setting( 'tweller_hero_btn2_url', array(
        'default'           => '#gallery',
        'sanitize_callback' => 'esc_url_raw',
    ) );
    $wp_customize->add_control( 'tweller_hero_btn2_url', array(
        'label'   => __( 'Secondary Button URL', 'tweller-studios' ),
        'section' => 'tweller_hero',
        'type'    => 'url',
    ) );

    // Announcement Banner
    $wp_customize->add_section( 'tweller_banner', array(
        'title'    => __( 'Announcement Banner', 'tweller-studios' ),
        'priority' => 31,
    ) );

    $wp_customize->add_setting( 'tweller_banner_enabled', array(
        'default'           => true,
        'sanitize_callback' => 'wp_validate_boolean',
    ) );
    $wp_customize->add_control( 'tweller_banner_enabled', array(
        'label'   => __( 'Show Announcement Banner', 'tweller-studios' ),
        'section' => 'tweller_banner',
        'type'    => 'checkbox',
    ) );

    $wp_customize->add_setting( 'tweller_banner_text', array(
        'default'           => 'Easter Specials are here — Book your session today!',
        'sanitize_callback' => 'wp_kses_post',
    ) );
    $wp_customize->add_control( 'tweller_banner_text', array(
        'label'   => __( 'Banner Text (HTML allowed)', 'tweller-studios' ),
        'section' => 'tweller_banner',
        'type'    => 'textarea',
    ) );

    $wp_customize->add_setting( 'tweller_banner_link_text', array(
        'default'           => 'Book Now',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_banner_link_text', array(
        'label'   => __( 'Banner Link Text', 'tweller-studios' ),
        'section' => 'tweller_banner',
        'type'    => 'text',
    ) );

    $wp_customize->add_setting( 'tweller_banner_link_url', array(
        'default'           => '#specials',
        'sanitize_callback' => 'esc_url_raw',
    ) );
    $wp_customize->add_control( 'tweller_banner_link_url', array(
        'label'   => __( 'Banner Link URL', 'tweller-studios' ),
        'section' => 'tweller_banner',
        'type'    => 'url',
    ) );

    // About Section
    $wp_customize->add_section( 'tweller_about', array(
        'title'    => __( 'About Section', 'tweller-studios' ),
        'priority' => 33,
    ) );

    $wp_customize->add_setting( 'tweller_about_image', array(
        'default'           => '',
        'sanitize_callback' => 'esc_url_raw',
    ) );
    $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'tweller_about_image', array(
        'label'   => __( 'About Section Image', 'tweller-studios' ),
        'section' => 'tweller_about',
    ) ) );

    $wp_customize->add_setting( 'tweller_about_text', array(
        'default'           => "We're not just photographers — we're people who genuinely love what we do. Every family, every couple, every little milestone matters to us because we know how fast these moments go. We're here to make sure you never lose them.",
        'sanitize_callback' => 'wp_kses_post',
    ) );
    $wp_customize->add_control( 'tweller_about_text', array(
        'label'   => __( 'About Text', 'tweller-studios' ),
        'section' => 'tweller_about',
        'type'    => 'textarea',
    ) );

    $wp_customize->add_setting( 'tweller_about_stat1_num', array(
        'default'           => '500+',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_about_stat1_num', array(
        'label'   => __( 'Stat 1 Number', 'tweller-studios' ),
        'section' => 'tweller_about',
        'type'    => 'text',
    ) );

    $wp_customize->add_setting( 'tweller_about_stat1_label', array(
        'default'           => 'Sessions Shot',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_about_stat1_label', array(
        'label'   => __( 'Stat 1 Label', 'tweller-studios' ),
        'section' => 'tweller_about',
        'type'    => 'text',
    ) );

    $wp_customize->add_setting( 'tweller_about_stat2_num', array(
        'default'           => '96%',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_about_stat2_num', array(
        'label'   => __( 'Stat 2 Number', 'tweller-studios' ),
        'section' => 'tweller_about',
        'type'    => 'text',
    ) );

    $wp_customize->add_setting( 'tweller_about_stat2_label', array(
        'default'           => 'Would Recommend',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_about_stat2_label', array(
        'label'   => __( 'Stat 2 Label', 'tweller-studios' ),
        'section' => 'tweller_about',
        'type'    => 'text',
    ) );

    $wp_customize->add_setting( 'tweller_about_stat3_num', array(
        'default'           => '8+',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_about_stat3_num', array(
        'label'   => __( 'Stat 3 Number', 'tweller-studios' ),
        'section' => 'tweller_about',
        'type'    => 'text',
    ) );

    $wp_customize->add_setting( 'tweller_about_stat3_label', array(
        'default'           => 'Years Experience',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_about_stat3_label', array(
        'label'   => __( 'Stat 3 Label', 'tweller-studios' ),
        'section' => 'tweller_about',
        'type'    => 'text',
    ) );

    // CTA Section
    $wp_customize->add_section( 'tweller_cta', array(
        'title'    => __( 'Call to Action Section', 'tweller-studios' ),
        'priority' => 35,
    ) );

    $wp_customize->add_setting( 'tweller_cta_bg', array(
        'default'           => '',
        'sanitize_callback' => 'esc_url_raw',
    ) );
    $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'tweller_cta_bg', array(
        'label'   => __( 'CTA Background Image', 'tweller-studios' ),
        'section' => 'tweller_cta',
    ) ) );

    $wp_customize->add_setting( 'tweller_cta_title', array(
        'default'           => "Let's Create Something Beautiful",
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_cta_title', array(
        'label'   => __( 'CTA Title', 'tweller-studios' ),
        'section' => 'tweller_cta',
        'type'    => 'text',
    ) );

    $wp_customize->add_setting( 'tweller_cta_text', array(
        'default'           => 'Your next favorite photo is one session away.',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_cta_text', array(
        'label'   => __( 'CTA Text', 'tweller-studios' ),
        'section' => 'tweller_cta',
        'type'    => 'text',
    ) );

    $wp_customize->add_setting( 'tweller_cta_btn_text', array(
        'default'           => 'Book Your Session',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_cta_btn_text', array(
        'label'   => __( 'CTA Button Text', 'tweller-studios' ),
        'section' => 'tweller_cta',
        'type'    => 'text',
    ) );

    $wp_customize->add_setting( 'tweller_cta_btn_url', array(
        'default'           => '/contact',
        'sanitize_callback' => 'esc_url_raw',
    ) );
    $wp_customize->add_control( 'tweller_cta_btn_url', array(
        'label'   => __( 'CTA Button URL', 'tweller-studios' ),
        'section' => 'tweller_cta',
        'type'    => 'url',
    ) );

    // Social Media
    $wp_customize->add_section( 'tweller_social', array(
        'title'    => __( 'Social Media Links', 'tweller-studios' ),
        'priority' => 36,
    ) );

    $socials = array( 'facebook', 'instagram', 'tiktok', 'pinterest', 'twitter' );
    foreach ( $socials as $social ) {
        $wp_customize->add_setting( "tweller_social_{$social}", array(
            'default'           => '',
            'sanitize_callback' => 'esc_url_raw',
        ) );
        $wp_customize->add_control( "tweller_social_{$social}", array(
            'label'   => ucfirst( $social ) . ' URL',
            'section' => 'tweller_social',
            'type'    => 'url',
        ) );
    }

    // Contact Info
    $wp_customize->add_section( 'tweller_contact', array(
        'title'    => __( 'Contact Information', 'tweller-studios' ),
        'priority' => 37,
    ) );

    $wp_customize->add_setting( 'tweller_phone', array(
        'default'           => '+1 868-342-2948',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_phone', array(
        'label'   => __( 'Phone Number', 'tweller-studios' ),
        'section' => 'tweller_contact',
        'type'    => 'text',
    ) );

    $wp_customize->add_setting( 'tweller_email', array(
        'default'           => 'hello@twellerstudios.com',
        'sanitize_callback' => 'sanitize_email',
    ) );
    $wp_customize->add_control( 'tweller_email', array(
        'label'   => __( 'Email Address', 'tweller-studios' ),
        'section' => 'tweller_contact',
        'type'    => 'email',
    ) );

    $wp_customize->add_setting( 'tweller_location', array(
        'default'           => 'Trinidad & Tobago',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    $wp_customize->add_control( 'tweller_location', array(
        'label'   => __( 'Location', 'tweller-studios' ),
        'section' => 'tweller_contact',
        'type'    => 'text',
    ) );
}
add_action( 'customize_register', 'tweller_customizer' );

/**
 * Register widget areas
 */
function tweller_widgets_init() {
    register_sidebar( array(
        'name'          => __( 'Footer Widget Area', 'tweller-studios' ),
        'id'            => 'footer-widgets',
        'before_widget' => '<div class="tw-footer__widget">',
        'after_widget'  => '</div>',
        'before_title'  => '<h4 class="tw-footer__heading">',
        'after_title'   => '</h4>',
    ) );
}
add_action( 'widgets_init', 'tweller_widgets_init' );

/**
 * Excerpt length
 */
function tweller_excerpt_length( $length ) {
    return 20;
}
add_filter( 'excerpt_length', 'tweller_excerpt_length' );

/**
 * Excerpt more text
 */
function tweller_excerpt_more( $more ) {
    return '&hellip;';
}
add_filter( 'excerpt_more', 'tweller_excerpt_more' );

/**
 * Helper: Get social links array
 */
function tweller_get_social_links() {
    $socials = array( 'facebook', 'instagram', 'tiktok', 'pinterest', 'twitter' );
    $links = array();
    foreach ( $socials as $social ) {
        $url = get_theme_mod( "tweller_social_{$social}", '' );
        if ( ! empty( $url ) ) {
            $links[ $social ] = $url;
        }
    }
    return $links;
}

/**
 * Social icon SVGs
 */
function tweller_social_icon( $platform ) {
    $icons = array(
        'facebook'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
        'instagram' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
        'tiktok'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>',
        'pinterest' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 01.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12.017 24c6.624 0 11.99-5.367 11.99-11.988C24.007 5.367 18.641 0 12.017 0z"/></svg>',
        'twitter'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
    );
    return isset( $icons[ $platform ] ) ? $icons[ $platform ] : '';
}
