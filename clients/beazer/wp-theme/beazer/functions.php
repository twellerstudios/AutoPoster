<?php
/**
 * BEAZER theme bootstrap.
 *
 * @package beazer
 */

defined( 'ABSPATH' ) || exit;

define( 'BEAZER_VERSION', '1.0.0' );

require get_template_directory() . '/inc/helpers.php';
require get_template_directory() . '/inc/customizer.php';
require get_template_directory() . '/inc/forms.php';
require get_template_directory() . '/inc/setup.php';

add_action( 'after_setup_theme', function () {
	load_theme_textdomain( 'beazer', get_template_directory() . '/languages' );
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'woocommerce' );
	add_theme_support( 'custom-logo', array( 'height' => 216, 'width' => 640, 'flex-width' => true, 'flex-height' => true ) );
	register_nav_menus( array(
		'primary' => __( 'Main menu (top bar)', 'beazer' ),
		'footer'  => __( 'Footer links', 'beazer' ),
	) );
} );

add_action( 'wp_enqueue_scripts', function () {
	$uri = get_template_directory_uri() . '/assets';
	wp_enqueue_style( 'beazer-fonts', $uri . '/css/fonts.css', array(), BEAZER_VERSION );
	wp_enqueue_style( 'beazer', $uri . '/css/main.css', array( 'beazer-fonts' ), BEAZER_VERSION );
	wp_enqueue_script( 'beazer', $uri . '/js/main.js', array(), BEAZER_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
} );

/* Preload the fonts and hero art the first screen needs. */
add_action( 'wp_head', function () {
	$uri = get_template_directory_uri() . '/assets';
	foreach ( array( 'barlow-condensed-600', 'noto-serif-400', 'barlow-400' ) as $font ) {
		printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( "$uri/fonts/$font.woff2" ) );
	}
	printf( '<link rel="icon" href="%s" sizes="48x48">' . "\n", esc_url( "$uri/img/favicon.png" ) );
	printf( '<link rel="apple-touch-icon" href="%s">' . "\n", esc_url( "$uri/img/apple-touch-icon.png" ) );
	echo '<meta name="theme-color" content="#000000">' . "\n";
	beazer_social_meta();
}, 2 );

/* A lean, fast front end: drop WordPress extras this site does not use. */
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'wp_head', 'wp_generator' );
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wp_shortlink_wp_head' );
remove_action( 'wp_head', 'rest_output_link_wp_head' );
remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() || ! has_blocks() ) {
		wp_dequeue_style( 'wp-block-library' );
		wp_dequeue_style( 'global-styles' );
		wp_dequeue_style( 'classic-theme-styles' );
	}
}, 100 );

add_filter( 'body_class', function ( $classes ) {
	$classes[] = is_front_page() ? 'is-home' : 'is-inner';
	return $classes;
} );
