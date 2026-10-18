<?php
/**
 * Content defaults and template helpers.
 *
 * Defaults marked "PLACEHOLDER" are the sample content from the approved mockup
 * and must be confirmed by the client before launch.
 *
 * @package beazer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every editable value with its default. Keys match the Customizer setting IDs.
 */
function beazer_defaults() {
	return array(
		// Hero.
		'tagline_white'    => 'CARIBBEAN ROCK.',
		'tagline_red'      => 'GLOBAL AMBITION.',
		'listen_url'       => 'https://open.spotify.com/search/BEAZER',
		'listen_label'     => 'LISTEN NOW',
		'fanclub_label'    => 'JOIN THE FAN CLUB',
		'fanclub_url'      => '', // Empty = the Fan Club page.
		'hero_art_desktop' => '', // Empty = bundled approved artwork.
		'hero_art_mobile'  => '',

		// Platforms row (official profile URLs to be supplied by the client).
		'spotify_url'      => 'https://open.spotify.com/search/BEAZER',
		'apple_url'        => 'https://music.apple.com/search?term=BEAZER',
		'youtube_url'      => 'https://www.youtube.com/results?search_query=BEAZER',
		'instagram_url'    => 'https://www.instagram.com/explore/search/keyword/?q=BEAZER',
		'tiktok_url'       => 'https://www.tiktok.com/search?q=BEAZER',
		'facebook_url'     => 'https://www.facebook.com/search/top?q=BEAZER',

		// New release (PLACEHOLDER: "STORM SEASON" sample from the mockup).
		'release_label'    => 'NEW RELEASE',
		'release_title'    => 'STORM SEASON',
		'release_subtitle' => 'NEW SINGLE OUT NOW',
		'release_copy'     => 'A thunderous fusion of island soul and rock rebellion. STORM SEASON is BEAZER at full power.',
		'release_cover'    => '',
		'release_spotify'  => '',
		'release_apple'    => '',
		'release_youtube'  => '',
		'release_listen'   => '',

		// Featured video (PLACEHOLDER).
		'video_label'      => 'FEATURED VIDEO',
		'video_url'        => '', // Any YouTube link; plays in place when set.
		'video_thumb'      => '',
		'watch_url'        => '',

		// Site.
		'contact_email'    => '',
		'cart_url'         => '',
		'footer_credit'    => 'Website by Tweller Studios',
		'footer_credit_url' => 'https://twellerstudios.com/',
	);
}

/** Theme mod with the bundled default. */
function beazer_mod( $key ) {
	$defaults = beazer_defaults();
	$value    = get_theme_mod( $key, $defaults[ $key ] ?? '' );
	return '' === $value || null === $value ? ( $defaults[ $key ] ?? '' ) : $value;
}

/** Theme mod that falls back to another one when empty. */
function beazer_mod_or( $key, $fallback ) {
	$value = beazer_mod( $key );
	return '' !== $value ? $value : beazer_mod( $fallback );
}

function beazer_asset( $path ) {
	return get_template_directory_uri() . '/assets/' . ltrim( $path, '/' );
}

/** Inline SVG reference from the sprite printed in the footer. */
function beazer_icon( $id, $class = '' ) {
	return sprintf(
		'<svg class="icon %1$s" aria-hidden="true" focusable="false"><use href="#i-%2$s"></use></svg>',
		esc_attr( $class ),
		esc_attr( $id )
	);
}

/** URL of a page created by the theme, by slug, with a sensible fallback. */
function beazer_page_url( $slug ) {
	$page = get_page_by_path( $slug );
	return $page ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
}

function beazer_fanclub_url() {
	return beazer_mod( 'fanclub_url' ) ?: beazer_page_url( 'fan-club' );
}

function beazer_cart_url() {
	if ( beazer_mod( 'cart_url' ) ) {
		return beazer_mod( 'cart_url' );
	}
	if ( function_exists( 'wc_get_cart_url' ) ) {
		return wc_get_cart_url();
	}
	return beazer_page_url( 'merch' );
}

/** The six platform destinations, in the approved order. */
function beazer_platforms() {
	return array(
		'spotify'   => array( 'label' => 'SPOTIFY', 'name' => 'Spotify', 'url' => beazer_mod( 'spotify_url' ) ),
		'apple'     => array( 'label' => 'iTunes', 'name' => 'iTunes / Apple Music', 'url' => beazer_mod( 'apple_url' ) ),
		'youtube'   => array( 'label' => 'YOUTUBE', 'name' => 'YouTube', 'url' => beazer_mod( 'youtube_url' ) ),
		'instagram' => array( 'label' => 'INSTAGRAM', 'name' => 'Instagram', 'url' => beazer_mod( 'instagram_url' ) ),
		'tiktok'    => array( 'label' => 'TIKTOK', 'name' => 'TikTok', 'url' => beazer_mod( 'tiktok_url' ) ),
		'facebook'  => array( 'label' => 'FACEBOOK', 'name' => 'Facebook', 'url' => beazer_mod( 'facebook_url' ) ),
	);
}

/** Extract an 11-character YouTube video ID from any common URL form. */
function beazer_youtube_id( $url ) {
	if ( preg_match( '~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/))([A-Za-z0-9_-]{11})~', (string) $url, $m ) ) {
		return $m[1];
	}
	return '';
}

/** Responsive <img> for a bundled image exported at several widths. */
function beazer_srcset( $name, $widths ) {
	$set = array();
	foreach ( $widths as $w ) {
		$set[] = esc_url( beazer_asset( "img/$name-$w.webp" ) ) . " {$w}w";
	}
	return implode( ', ', $set );
}

/** Fallback nav when no menu is assigned: the approved seven items, in order. */
function beazer_nav_fallback() {
	$items = array(
		'HOME'     => home_url( '/' ),
		'MERCH'    => beazer_page_url( 'merch' ),
		'TOUR'     => beazer_page_url( 'tour' ),
		'FAN CLUB' => beazer_page_url( 'fan-club' ),
		'MEDIA'    => beazer_page_url( 'media' ),
		'ABOUT'    => beazer_page_url( 'about' ),
		'CONTACT'  => beazer_page_url( 'contact' ),
	);
	$current = home_url( add_query_arg( array() ) );
	echo '<ul class="menu">';
	foreach ( $items as $label => $url ) {
		$is_current = ( 'HOME' === $label && is_front_page() ) || ( 'HOME' !== $label && trailingslashit( $url ) === trailingslashit( strtok( $current, '?' ) ) );
		printf(
			'<li class="menu-item%s"><a href="%s"%s>%s</a></li>',
			$is_current ? ' current-menu-item' : '',
			esc_url( $url ),
			$is_current ? ' aria-current="page"' : '',
			esc_html( $label )
		);
	}
	echo '</ul>';
}

function beazer_primary_nav() {
	wp_nav_menu( array(
		'theme_location' => 'primary',
		'container'      => false,
		'menu_class'     => 'menu',
		'depth'          => 1,
		'fallback_cb'    => 'beazer_nav_fallback',
	) );
}

/** Open Graph / Twitter tags so shared links show the artwork. */
function beazer_social_meta() {
	$title = wp_get_document_title();
	if ( is_front_page() ) {
		$desc = beazer_mod( 'tagline_white' ) . ' ' . beazer_mod( 'tagline_red' ) . ' ' . beazer_mod( 'release_copy' );
	} elseif ( is_singular() ) {
		$desc = get_the_excerpt( get_queried_object_id() );
	} else {
		$desc = get_bloginfo( 'description' );
	}
	$image = beazer_asset( 'img/og-image.jpg' );
	printf( '<meta name="description" content="%s">' . "\n", esc_attr( wp_strip_all_tags( $desc ) ) );
	printf( '<meta property="og:type" content="website"><meta property="og:title" content="%s"><meta property="og:description" content="%s"><meta property="og:image" content="%s"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"><meta name="twitter:card" content="summary_large_image">' . "\n",
		esc_attr( $title ), esc_attr( wp_strip_all_tags( $desc ) ), esc_url( $image ) );
}
