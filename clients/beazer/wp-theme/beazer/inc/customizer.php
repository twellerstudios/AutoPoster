<?php
/**
 * Appearance > Customize > BEAZER: every piece of homepage content.
 *
 * @package beazer
 */

defined( 'ABSPATH' ) || exit;

add_action( 'customize_register', function ( WP_Customize_Manager $wp ) {
	$d = beazer_defaults();

	$wp->add_panel( 'beazer', array(
		'title'       => __( 'BEAZER', 'beazer' ),
		'description' => __( 'Homepage content. Leave a field empty to use the approved default.', 'beazer' ),
		'priority'    => 20,
	) );

	$sections = array(
		'beazer_hero'      => __( 'Hero & buttons', 'beazer' ),
		'beazer_platforms' => __( 'Platform links (icon row)', 'beazer' ),
		'beazer_release'   => __( 'New release', 'beazer' ),
		'beazer_video'     => __( 'Featured video', 'beazer' ),
		'beazer_site'      => __( 'Contact, cart & footer', 'beazer' ),
	);
	foreach ( $sections as $id => $title ) {
		$wp->add_section( $id, array( 'title' => $title, 'panel' => 'beazer' ) );
	}

	$add = function ( $id, $section, $label, $type = 'text', $description = '' ) use ( $wp, $d ) {
		$sanitize = array(
			'url'      => 'esc_url_raw',
			'email'    => 'sanitize_email',
			'textarea' => 'sanitize_textarea_field',
			'image'    => 'esc_url_raw',
		)[ $type ] ?? 'sanitize_text_field';
		$wp->add_setting( $id, array(
			'default'           => $d[ $id ],
			'sanitize_callback' => $sanitize,
			'transport'         => 'refresh',
		) );
		if ( 'image' === $type ) {
			$wp->add_control( new WP_Customize_Image_Control( $wp, $id, array(
				'label'       => $label,
				'section'     => $section,
				'description' => $description,
			) ) );
			return;
		}
		$wp->add_control( $id, array(
			'label'       => $label,
			'section'     => $section,
			'type'        => $type,
			'description' => $description,
		) );
	};

	$add( 'tagline_white', 'beazer_hero', __( 'Tagline (white part)', 'beazer' ) );
	$add( 'tagline_red', 'beazer_hero', __( 'Tagline (red part)', 'beazer' ) );
	$add( 'listen_label', 'beazer_hero', __( 'Red button text', 'beazer' ) );
	$add( 'listen_url', 'beazer_hero', __( 'LISTEN NOW link', 'beazer' ), 'url' );
	$add( 'fanclub_label', 'beazer_hero', __( 'Dark button text', 'beazer' ) );
	$add( 'fanclub_url', 'beazer_hero', __( 'Fan club link', 'beazer' ), 'url', __( 'Empty = the Fan Club page on this site.', 'beazer' ) );
	$add( 'hero_art_desktop', 'beazer_hero', __( 'Desktop artwork (3:2)', 'beazer' ), 'image', __( 'Optional. Full scene WITHOUT buttons or text, 3072×2048 recommended. Empty = approved artwork.', 'beazer' ) );
	$add( 'hero_art_mobile', 'beazer_hero', __( 'Phone / tablet hero photo', 'beazer' ), 'image', __( 'Optional. Portrait-friendly crop of Joel, about 1440×1240.', 'beazer' ) );

	foreach ( beazer_platforms() as $key => $p ) {
		$add( $key . '_url', 'beazer_platforms', $p['name'], 'url' );
	}

	$add( 'release_label', 'beazer_release', __( 'Label', 'beazer' ) );
	$add( 'release_title', 'beazer_release', __( 'Title', 'beazer' ) );
	$add( 'release_subtitle', 'beazer_release', __( 'Subtitle', 'beazer' ) );
	$add( 'release_copy', 'beazer_release', __( 'Description', 'beazer' ), 'textarea' );
	$add( 'release_cover', 'beazer_release', __( 'Cover art', 'beazer' ), 'image', __( 'Square artwork, 1000×1000 or larger.', 'beazer' ) );
	$add( 'release_spotify', 'beazer_release', __( 'Release on Spotify', 'beazer' ), 'url', __( 'Empty = artist Spotify link.', 'beazer' ) );
	$add( 'release_apple', 'beazer_release', __( 'Release on Apple Music / iTunes', 'beazer' ), 'url' );
	$add( 'release_youtube', 'beazer_release', __( 'Release on YouTube', 'beazer' ), 'url' );
	$add( 'release_listen', 'beazer_release', __( 'Release LISTEN NOW link', 'beazer' ), 'url', __( 'Empty = main LISTEN NOW link.', 'beazer' ) );

	$add( 'video_label', 'beazer_video', __( 'Label', 'beazer' ) );
	$add( 'video_url', 'beazer_video', __( 'YouTube video link', 'beazer' ), 'url', __( 'Plays in place when visitors press play.', 'beazer' ) );
	$add( 'video_thumb', 'beazer_video', __( 'Thumbnail', 'beazer' ), 'image', __( 'Empty = YouTube thumbnail when a video is set, otherwise the approved sample.', 'beazer' ) );
	$add( 'watch_url', 'beazer_video', __( 'WATCH NOW link', 'beazer' ), 'url', __( 'Empty = the video on YouTube, or the Media page.', 'beazer' ) );

	$add( 'contact_email', 'beazer_site', __( 'Email that receives contact & fan club notifications', 'beazer' ), 'email', __( 'Empty = the site admin email.', 'beazer' ) );
	$add( 'cart_url', 'beazer_site', __( 'Cart icon link', 'beazer' ), 'url', __( 'Empty = WooCommerce cart if installed, otherwise the Merch page.', 'beazer' ) );
	$add( 'footer_credit', 'beazer_site', __( 'Footer credit text', 'beazer' ) );
	$add( 'footer_credit_url', 'beazer_site', __( 'Footer credit link', 'beazer' ), 'url' );
} );
