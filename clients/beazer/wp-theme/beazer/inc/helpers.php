<?php
/**
 * Content defaults and template helpers.
 *
 * Defaults are BEAZER's real details (line-up, Hutt Shutts show, Titan Music).
 * Empty platform links render as "official profile coming soon" until the
 * band supplies them in the Customizer.
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
		'listen_url'       => '', // Empty = the Media page until music is out.
		'listen_label'     => 'LISTEN NOW',
		'fanclub_label'    => 'JOIN THE FAN CLUB',
		'fanclub_url'      => '', // Empty = the Fan Club page.
		'hero_art_desktop' => '', // Empty = bundled approved artwork.
		'hero_art_mobile'  => '',

		// Platforms row: empty = "official profile coming soon" (Apple Music for now).
		'spotify_url'      => 'https://open.spotify.com/artist/3kswRiVAXwC63MHPktvgL0',
		'apple_url'        => '',
		'youtube_url'      => 'https://www.youtube.com/@beazerofficial',
		'instagram_url'    => 'https://www.instagram.com/beazermusic',
		'tiktok_url'       => 'https://www.tiktok.com/@beazermusic',
		'facebook_url'     => 'https://www.facebook.com/BeazerBand',

		// Feature panel (the approved NEW RELEASE slot): live recap until the debut record.
		'release_label'    => 'LIVE',
		'release_title'    => 'HUTT SHUTTS',
		'release_subtitle' => '3 OCTOBER 2026 · TACARIGUA',
		'release_copy'     => 'Beazer with Supernormal, in a room that looks like a castle from the road. New band, old hands.',
		'release_cover'    => '', // Empty = bundled live photo of Joel.
		'release_button'   => 'SEE THE ROOM',
		'release_spotify'  => '',
		'release_apple'    => '',
		'release_youtube'  => '',
		'release_listen'   => '', // Empty = the Media page.

		// Featured video.
		'video_label'      => 'FEATURED VIDEO',
		'video_url'        => '', // Any YouTube link; plays in place when set.
		'video_thumb'      => '', // Empty = bundled first-jam still.
		'watch_url'        => '', // Empty = the video, or the Media page.

		// Site.
		'contact_email'    => 'titanmusicgroupltd@gmail.com',
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

/**
 * Link attributes for a platform: its profile URL, or, until the band supplies
 * one, the fan club sign-up with a "coming soon" note (see main.js).
 */
function beazer_platform_link( $url, $name ) {
	if ( $url ) {
		return sprintf( 'href="%s" target="_blank" rel="noopener"', esc_url( $url ) );
	}
	return sprintf( 'href="%s" data-soon="%s"', esc_url( beazer_fanclub_url() . '#join' ), esc_attr( $name ) );
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
