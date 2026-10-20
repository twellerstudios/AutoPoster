<?php
/**
 * Plugin Name:       BEAZER Demo
 * Plugin URI:        https://www.twellerstudios.com/beazertheband/
 * Description:       Serves the multi-page BEAZER website demo at /beazertheband/ without touching the rest of the site: no pages, theme, menu or settings are changed. Fan club sign-ups are kept under BEAZER Demo and emailed through this site's mailer. With WooCommerce active, the BEAZER T-shirts are added as products hidden from the main shop and sold from the demo's Merch page. Deactivate to take the demo offline.
 * Version:           1.1.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Tweller Studios
 * Author URI:        https://www.twellerstudios.com/
 * License:           GPL-2.0-or-later
 * Text Domain:       beazer-demo
 *
 * @package beazer-demo
 */

defined( 'ABSPATH' ) || exit;

define( 'BEAZER_DEMO_VERSION', '1.1.1' );
define( 'BEAZER_DEMO_SLUG', 'beazertheband' );
define( 'BEAZER_DEMO_FILE', __FILE__ );
define( 'BEAZER_DEMO_PATH', __DIR__ . '/' );
define( 'BEAZER_DEMO_DIR', __DIR__ . '/site/' );
define( 'BEAZER_DEMO_NOTIFY_DEFAULT', 'titanmusicgroupltd@gmail.com' );

require __DIR__ . '/includes/merch.php';

/** Pages bundled in site/, by the path after /beazertheband/. */
function beazer_demo_pages() {
	return array(
		''         => 'index.html',
		'merch'    => 'merch/index.html',
		'tour'     => 'tour/index.html',
		'fan-club' => 'fan-club/index.html',
		'media'    => 'media/index.html',
		'about'    => 'about/index.html',
		'contact'  => 'contact/index.html',
	);
}

function beazer_demo_url( $path = '' ) {
	return home_url( '/' . BEAZER_DEMO_SLUG . '/' . ( $path ? trailingslashit( $path ) : '' ) );
}

/*
 * Router: answer /beazertheband/... before WordPress looks for a post, and
 * leave every other URL alone.
 */
add_action( 'init', 'beazer_demo_route', 20 ); // after WooCommerce registers its products (init 5)
function beazer_demo_route() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	$path   = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$home   = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	$prefix = $home . '/' . BEAZER_DEMO_SLUG;
	if ( $path !== $prefix && 0 !== strpos( $path, $prefix . '/' ) ) {
		return;
	}
	if ( $path === $prefix ) {
		wp_safe_redirect( beazer_demo_url() . ( empty( $_SERVER['QUERY_STRING'] ) ? '' : '?' . wp_unslash( $_SERVER['QUERY_STRING'] ) ), 301 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		exit;
	}
	$slug  = trim( substr( $path, strlen( $prefix ) ), '/' );
	$pages = beazer_demo_pages();
	$tee   = 0 === strpos( $slug, 'merch/' ) ? beazer_demo_merch_find( substr( $slug, 6 ) ) : null;
	if ( $tee ) { // product page: built on the Merch page's header, banner and footer
		if ( '/' !== substr( $path, -1 ) ) {
			wp_safe_redirect( beazer_demo_merch_url( $tee ), 301 );
			exit;
		}
		$file   = $pages['merch'];
		$status = 200;
	} elseif ( isset( $pages[ $slug ] ) ) {
		if ( '/' !== substr( $path, -1 ) ) { // keep relative links consistent
			wp_safe_redirect( beazer_demo_url( $slug ), 301 );
			exit;
		}
		$file   = $pages[ $slug ];
		$status = 200;
	} else {
		$file   = '404.html';
		$status = 404;
	}

	$html = file_get_contents( BEAZER_DEMO_DIR . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false === $html ) {
		return;
	}
	$html = strtr( $html, array(
		'{{BASE}}'   => esc_url( beazer_demo_url() ),
		'{{ASSETS}}' => esc_url( plugins_url( 'site/assets/', __FILE__ ) ),
		'{{FORM}}'   => esc_url( admin_url( 'admin-post.php' ) ),
	) );
	if ( $tee ) {
		$html = beazer_demo_product_page( $html, $tee );
	} elseif ( 'merch' === $slug ) {
		$grid = beazer_demo_merch_html(); // contains "$" (prices), so no preg_replace backreferences
		$html = preg_replace_callback( '/<!--beazer-merch-->.*?<!--\/beazer-merch-->/s', function () use ( $grid ) {
			return $grid;
		}, $html );
	}
	if ( beazer_demo_wc_ready() ) { // header cart icon -> the store cart
		$cart = esc_url( wc_get_cart_url() );
		$html = preg_replace_callback( '/(<a class="tool tool-cart" href=")[^"]*"/', function ( $m ) use ( $cart ) {
			return $m[1] . $cart . '"';
		}, $html );
	}

	status_header( $status );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- bundled, pre-built page
	exit;
}

/* ---------- Fan club sign-ups ------------------------------------------- */

add_action( 'init', function () {
	register_post_type( 'beazer_demo_fan', array(
		'labels'          => array(
			'name'          => __( 'BEAZER Demo', 'beazer-demo' ),
			'singular_name' => __( 'Fan', 'beazer-demo' ),
			'all_items'     => __( 'Fan club sign-ups', 'beazer-demo' ),
		),
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => true,
		'menu_icon'       => 'dashicons-format-audio',
		'supports'        => array( 'title' ),
		'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap'    => true,
	) );
} );

function beazer_demo_notify_email() {
	return get_option( 'beazer_demo_notify' ) ?: BEAZER_DEMO_NOTIFY_DEFAULT;
}

function beazer_demo_field( $key, $max = 200 ) {
	$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- public form, honeypot-protected
	return mb_substr( $value, 0, $max );
}

/** Back to the page the form was on, with ?beazer=<status>#anchor (read by the page script). */
function beazer_demo_back( $status, $anchor ) {
	$back = wp_get_referer() ?: beazer_demo_url();
	$back = remove_query_arg( 'beazer', strtok( $back, '#' ) );
	wp_safe_redirect( add_query_arg( 'beazer', $status, $back ) . '#' . $anchor, 303 );
	exit;
}

function beazer_demo_join() {
	if ( beazer_demo_field( 'website' ) ) { // honeypot
		beazer_demo_back( 'joined', 'join' );
	}
	$name    = beazer_demo_field( 'fan_name' );
	$email   = sanitize_email( beazer_demo_field( 'fan_email' ) );
	$country = beazer_demo_field( 'fan_country' );
	if ( ! $name || ! is_email( $email ) || ! beazer_demo_field( 'fan_consent' ) ) {
		beazer_demo_back( 'error', 'join' );
	}
	$existing = get_posts( array( 'post_type' => 'beazer_demo_fan', 'post_status' => 'private', 'title' => $email, 'fields' => 'ids', 'numberposts' => 1 ) );
	if ( ! $existing ) {
		$id = wp_insert_post( array( 'post_type' => 'beazer_demo_fan', 'post_status' => 'private', 'post_title' => $email ) );
		if ( $id && ! is_wp_error( $id ) ) {
			update_post_meta( $id, 'fan_name', $name );
			update_post_meta( $id, 'fan_country', $country );
		}
		wp_mail( beazer_demo_notify_email(), 'New BEAZER fan club member', "Name: $name\nEmail: $email\nCountry: $country\n", array( "Reply-To: $email" ) );
	}
	beazer_demo_back( 'joined', 'join' );
}
add_action( 'admin_post_nopriv_beazer_demo_join', 'beazer_demo_join' );
add_action( 'admin_post_beazer_demo_join', 'beazer_demo_join' );

function beazer_demo_contact() {
	if ( beazer_demo_field( 'website' ) ) { // honeypot
		beazer_demo_back( 'sent', 'contact' );
	}
	$name    = beazer_demo_field( 'c_name' );
	$email   = sanitize_email( beazer_demo_field( 'c_email' ) );
	$topic   = beazer_demo_field( 'c_topic', 40 ) ?: 'General';
	$message = isset( $_POST['c_message'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['c_message'] ) ), 0, 5000 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $name || ! is_email( $email ) || ! $message ) {
		beazer_demo_back( 'error', 'contact' );
	}
	$sent = wp_mail(
		beazer_demo_notify_email(),
		"[BEAZER website] $topic enquiry from $name",
		"Name: $name\nEmail: $email\nTopic: $topic\n\n$message\n",
		array( "Reply-To: $name <$email>" )
	);
	beazer_demo_back( $sent ? 'sent' : 'mailfail', 'contact' );
}
add_action( 'admin_post_nopriv_beazer_demo_contact', 'beazer_demo_contact' );
add_action( 'admin_post_beazer_demo_contact', 'beazer_demo_contact' );

/* ---------- Admin: sign-up list, CSV export, settings ------------------ */

add_filter( 'manage_beazer_demo_fan_posts_columns', function ( $cols ) {
	return array( 'cb' => $cols['cb'], 'title' => __( 'Email', 'beazer-demo' ), 'fan_name' => __( 'Name', 'beazer-demo' ), 'fan_country' => __( 'Country', 'beazer-demo' ), 'date' => $cols['date'] );
} );
add_action( 'manage_beazer_demo_fan_posts_custom_column', function ( $col, $id ) {
	echo esc_html( get_post_meta( $id, $col, true ) );
}, 10, 2 );
add_action( 'restrict_manage_posts', function ( $type ) {
	if ( 'beazer_demo_fan' === $type ) {
		printf( '<a class="button" href="%s">%s</a>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=beazer_demo_csv' ), 'beazer_demo_csv' ) ), esc_html__( 'Export CSV', 'beazer-demo' ) );
	}
} );
add_action( 'admin_post_beazer_demo_csv', function () {
	if ( ! current_user_can( 'edit_posts' ) || ! check_admin_referer( 'beazer_demo_csv' ) ) {
		wp_die( 'Not allowed' );
	}
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=beazer-fan-club.csv' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'email', 'name', 'country', 'joined' ) );
	foreach ( get_posts( array( 'post_type' => 'beazer_demo_fan', 'post_status' => 'private', 'numberposts' => -1 ) ) as $fan ) {
		$row = array( $fan->post_title, get_post_meta( $fan->ID, 'fan_name', true ), get_post_meta( $fan->ID, 'fan_country', true ), $fan->post_date );
		fputcsv( $out, array_map( function ( $v ) { // neutralise spreadsheet formulas
			return preg_match( '/^[=+\-@\t\r]/', (string) $v ) ? "'" . $v : $v;
		}, $row ) );
	}
	fclose( $out );
	exit;
} );

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=beazer_demo_fan', __( 'BEAZER Demo settings', 'beazer-demo' ), __( 'Settings', 'beazer-demo' ), 'manage_options', 'beazer-demo', 'beazer_demo_settings_page' );
} );
add_action( 'admin_init', function () {
	register_setting( 'beazer_demo', 'beazer_demo_notify', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ) );
} );
function beazer_demo_settings_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'BEAZER Demo', 'beazer-demo' ); ?></h1>
		<p><?php esc_html_e( 'The demo is live at', 'beazer-demo' ); ?> <a href="<?php echo esc_url( beazer_demo_url() ); ?>" target="_blank"><?php echo esc_html( beazer_demo_url() ); ?></a>. <?php esc_html_e( 'Search engines are asked not to index it. Deactivate the plugin to take it offline.', 'beazer-demo' ); ?></p>
		<h2><?php esc_html_e( 'Merch (WooCommerce)', 'beazer-demo' ); ?></h2>
		<?php
		$merch = beazer_demo_merch_items();
		if ( ! beazer_demo_wc_ready() ) {
			echo '<p>' . esc_html__( 'Activate WooCommerce to sell the BEAZER T-shirts. Until then the Merch page shows them as coming soon.', 'beazer-demo' ) . '</p>';
		} else {
			if ( isset( $_GET['synced'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Merch products are up to date.', 'beazer-demo' ) . '</p></div>';
			}
			printf(
				'<p>%s</p>',
				esc_html( sprintf( /* translators: 1: count, 2: price */ __( '%1$d T-shirts at %2$s each, sizes S–2XL, in the "BEAZER Merch" category. They are hidden from the main shop and search, and sold from the demo\'s Merch page.', 'beazer-demo' ), count( $merch['items'] ), wp_strip_all_tags( wc_price( $merch['price'] ) ) ) )
			);
			printf( '<p><a class="button" href="%s">%s</a> <a class="button" href="%s">%s</a></p>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=beazer_demo_sync' ), 'beazer_demo_sync' ) ), esc_html__( 'Re-sync merch products', 'beazer-demo' ),
				esc_url( admin_url( 'edit.php?post_type=product&product_cat=beazer-merch' ) ), esc_html__( 'View products', 'beazer-demo' ) );
		}
		?>
		<h2><?php esc_html_e( 'Notifications', 'beazer-demo' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'beazer_demo' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="beazer_demo_notify"><?php esc_html_e( 'Send form emails to', 'beazer-demo' ); ?></label></th>
					<td><input type="email" class="regular-text" id="beazer_demo_notify" name="beazer_demo_notify" value="<?php echo esc_attr( get_option( 'beazer_demo_notify' ) ); ?>" placeholder="<?php echo esc_attr( BEAZER_DEMO_NOTIFY_DEFAULT ); ?>">
					<p class="description"><?php echo esc_html( sprintf( /* translators: %s: email */ __( 'Empty = %s (Titan Music). Mail is sent with this site’s normal mailer (and any SMTP plugin).', 'beazer-demo' ), BEAZER_DEMO_NOTIFY_DEFAULT ) ); ?></p></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

add_action( 'admin_post_beazer_demo_sync', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'beazer_demo_sync' ) ) {
		wp_die( 'Not allowed' );
	}
	beazer_demo_sync_merch();
	wp_safe_redirect( admin_url( 'edit.php?post_type=beazer_demo_fan&page=beazer-demo&synced=1' ) );
	exit;
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( beazer_demo_url() ), esc_html__( 'View demo', 'beazer-demo' ) ) );
	return $links;
} );
