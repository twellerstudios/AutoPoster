<?php
/**
 * One-time site setup when the theme is activated.
 *
 * Creates the seven approved pages (only if missing), the main menu in the
 * approved order, sets Home as the static front page and turns on pretty
 * permalinks. Safe to run again: Appearance > BEAZER Setup > Run setup.
 *
 * @package beazer
 */

defined( 'ABSPATH' ) || exit;

function beazer_starter_pages() {
	$confirm = '<!-- wp:paragraph {"className":"note"} --><p class="note">Draft content: to be confirmed by BEAZER before launch.</p><!-- /wp:paragraph -->';
	$p       = function ( $text ) {
		return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
	};
	$h       = function ( $text ) {
		return '<!-- wp:heading --><h2 class="wp-block-heading">' . $text . '</h2><!-- /wp:heading -->';
	};
	$code    = function ( $shortcode ) {
		return '<!-- wp:shortcode -->' . $shortcode . '<!-- /wp:shortcode -->';
	};
	return array(
		'home'     => array( 'Home', '' ),
		'merch'    => array( 'Merch', $p( 'Official BEAZER merchandise is on the way: tees, hoodies and limited tour gear.' ) . $p( 'Join the fan club to be first in line when the store opens.' ) . $confirm ),
		'tour'     => array( 'Tour', $h( 'Upcoming shows' ) . $p( 'New dates are being announced soon. Fan club members hear first.' ) . $confirm ),
		'fan-club' => array( 'Fan Club', $p( 'Get new releases, show announcements and members-only drops straight to your inbox.' ) . '<!-- wp:html --><div id="join"></div><!-- /wp:html -->' . $code( '[beazer_fan_club_form]' ) ),
		'media'    => array( 'Media', $code( '[beazer_featured_video]' ) . $h( 'Photos' ) . $p( 'Live photos and press images coming soon.' ) . $confirm ),
		'about'    => array( 'About', $p( 'BEAZER: Caribbean rock with global ambition.' ) . $p( 'Biography to be supplied by BEAZER.' ) . $confirm ),
		'contact'  => array( 'Contact', $p( 'Bookings, press, merch and general enquiries. Send a message and the team will get back to you.' ) . '<!-- wp:html --><div id="contact"></div><!-- /wp:html -->' . $code( '[beazer_contact_form]' ) ),
	);
}

function beazer_run_setup() {
	$ids = array();
	foreach ( beazer_starter_pages() as $slug => list( $title, $content ) ) {
		$page = get_page_by_path( $slug );
		if ( $page ) {
			$ids[ $slug ] = $page->ID;
			continue;
		}
		$ids[ $slug ] = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
		) );
	}

	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $ids['home'] );

	if ( ! get_option( 'permalink_structure' ) ) {
		update_option( 'permalink_structure', '/%postname%/' );
	}

	$locations = get_theme_mod( 'nav_menu_locations', array() );
	if ( empty( $locations['primary'] ) ) {
		$menu = wp_get_nav_menu_object( 'Main Menu' );
		$menu_id = $menu ? $menu->term_id : wp_create_nav_menu( 'Main Menu' );
		if ( ! is_wp_error( $menu_id ) && ! wp_get_nav_menu_items( $menu_id ) ) {
			$order = array( 'home' => 'HOME', 'merch' => 'MERCH', 'tour' => 'TOUR', 'fan-club' => 'FAN CLUB', 'media' => 'MEDIA', 'about' => 'ABOUT', 'contact' => 'CONTACT' );
			$pos   = 1;
			foreach ( $order as $slug => $label ) {
				wp_update_nav_menu_item( $menu_id, 0, array(
					'menu-item-title'     => $label,
					'menu-item-object'    => 'page',
					'menu-item-object-id' => $ids[ $slug ],
					'menu-item-type'      => 'post_type',
					'menu-item-status'    => 'publish',
					'menu-item-position'  => $pos++,
				) );
			}
		}
		if ( ! is_wp_error( $menu_id ) ) {
			$locations['primary'] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $locations );
		}
	}

	update_option( 'beazer_setup_done', BEAZER_VERSION );
	flush_rewrite_rules();
}

add_action( 'after_switch_theme', function () {
	if ( ! get_option( 'beazer_setup_done' ) ) {
		beazer_run_setup();
	}
} );

/* Appearance > BEAZER Setup: re-run setup and see the launch checklist. */
add_action( 'admin_menu', function () {
	add_theme_page( 'BEAZER Setup', 'BEAZER Setup', 'manage_options', 'beazer-setup', 'beazer_setup_screen' );
} );

add_action( 'admin_post_beazer_setup', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'beazer_setup' ) ) {
		wp_die( 'Not allowed' );
	}
	beazer_run_setup();
	wp_safe_redirect( admin_url( 'themes.php?page=beazer-setup&done=1' ) );
	exit;
} );

function beazer_setup_screen() {
	?>
	<div class="wrap">
		<h1>BEAZER Setup</h1>
		<?php if ( isset( $_GET['done'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-success"><p>Setup complete. Pages, menu and front page are in place.</p></div>
		<?php endif; ?>
		<p>Creates any missing pages (Home, Merch, Tour, Fan Club, Media, About, Contact), the main menu in the approved order, and sets Home as the front page. Existing pages are never overwritten.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="beazer_setup">
			<?php wp_nonce_field( 'beazer_setup' ); ?>
			<?php submit_button( 'Run setup' ); ?>
		</form>
		<h2>Before launch, confirm with BEAZER</h2>
		<ol>
			<li>Release title, cover art, release date, copy and streaming links (<a href="<?php echo esc_url( admin_url( 'customize.php?autofocus[section]=beazer_release' ) ); ?>">New release</a>).</li>
			<li>Featured video link and thumbnail (<a href="<?php echo esc_url( admin_url( 'customize.php?autofocus[section]=beazer_video' ) ); ?>">Featured video</a>).</li>
			<li>Official Spotify, Apple Music, YouTube, Instagram, TikTok and Facebook profile links (<a href="<?php echo esc_url( admin_url( 'customize.php?autofocus[section]=beazer_platforms' ) ); ?>">Platform links</a>).</li>
			<li>Merch, Tour, About and Contact page copy (Pages).</li>
			<li>Notification email for the fan club and contact forms (<a href="<?php echo esc_url( admin_url( 'customize.php?autofocus[section]=beazer_site' ) ); ?>">Contact, cart &amp; footer</a>). Configure SMTP if the host does not send mail.</li>
		</ol>
	</div>
	<?php
}
