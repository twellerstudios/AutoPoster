<?php
/**
 * Fan club sign-up and contact forms.
 *
 * Sign-ups are stored under the "Fan Club" admin menu (exportable as CSV) and
 * both forms email the address set in the Customizer. No plugin required.
 *
 * Shortcodes: [beazer_fan_club_form], [beazer_contact_form], [beazer_featured_video]
 *
 * @package beazer
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	register_post_type( 'beazer_fan', array(
		'labels'              => array(
			'name'          => __( 'Fan Club', 'beazer' ),
			'singular_name' => __( 'Fan', 'beazer' ),
			'all_items'     => __( 'Members', 'beazer' ),
		),
		'public'              => false,
		'show_ui'             => true,
		'show_in_menu'        => true,
		'menu_icon'           => 'dashicons-groups',
		'menu_position'       => 25,
		'supports'            => array( 'title' ),
		'capability_type'     => 'post',
		'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap'        => true,
		'exclude_from_search' => true,
	) );
} );

function beazer_notify_email() {
	return beazer_mod( 'contact_email' ) ?: get_option( 'admin_email' );
}

/** Shared form shell: nonce, honeypot, status message. */
function beazer_form_open( $action, $class ) {
	$status = isset( $_GET['beazer'] ) ? sanitize_key( wp_unslash( $_GET['beazer'] ) ) : '';
	$messages = array(
		'joined'   => __( 'You are in. Welcome to the BEAZER fan club!', 'beazer' ),
		'sent'     => __( 'Thanks. Your message has been sent.', 'beazer' ),
		'error'    => __( 'Please check the form and try again.', 'beazer' ),
		'mailfail' => __( 'Sorry, your message could not be sent right now. Please try again later.', 'beazer' ),
	);
	$out = sprintf( '<form class="beazer-form %s" method="post" action="%s">', esc_attr( $class ), esc_url( admin_url( 'admin-post.php' ) ) );
	if ( isset( $messages[ $status ] ) ) {
		$out .= sprintf( '<p class="form-status form-status--%s" role="status">%s</p>', esc_attr( $status ), esc_html( $messages[ $status ] ) );
	}
	$out .= sprintf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );
	$out .= wp_nonce_field( $action, '_beazer_nonce', true, false );
	$out .= '<div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
	return $out;
}

add_shortcode( 'beazer_fan_club_form', function () {
	$out  = beazer_form_open( 'beazer_join', 'fan-form' );
	$out .= '<div class="field"><label for="fan-name">' . esc_html__( 'Name', 'beazer' ) . '</label><input id="fan-name" name="fan_name" type="text" autocomplete="name" required></div>';
	$out .= '<div class="field"><label for="fan-email">' . esc_html__( 'Email', 'beazer' ) . '</label><input id="fan-email" name="fan_email" type="email" autocomplete="email" required></div>';
	$out .= '<div class="field"><label for="fan-country">' . esc_html__( 'Country / island', 'beazer' ) . '</label><input id="fan-country" name="fan_country" type="text" autocomplete="country-name"></div>';
	$out .= '<label class="check"><input type="checkbox" name="fan_consent" value="1" required> ' . esc_html__( 'Send me BEAZER news, releases and tour dates. Unsubscribe any time.', 'beazer' ) . '</label>';
	$out .= '<button class="btn btn-red btn-form" type="submit">' . esc_html__( 'JOIN THE FAN CLUB', 'beazer' ) . '</button></form>';
	return $out;
} );

add_shortcode( 'beazer_contact_form', function () {
	$out  = beazer_form_open( 'beazer_contact', 'contact-form' );
	$out .= '<div class="field"><label for="c-name">' . esc_html__( 'Name', 'beazer' ) . '</label><input id="c-name" name="c_name" type="text" autocomplete="name" required></div>';
	$out .= '<div class="field"><label for="c-email">' . esc_html__( 'Email', 'beazer' ) . '</label><input id="c-email" name="c_email" type="email" autocomplete="email" required></div>';
	$out .= '<div class="field"><label for="c-topic">' . esc_html__( 'Topic', 'beazer' ) . '</label><select id="c-topic" name="c_topic"><option>Booking</option><option>Press</option><option>Merch</option><option>General</option></select></div>';
	$out .= '<div class="field"><label for="c-message">' . esc_html__( 'Message', 'beazer' ) . '</label><textarea id="c-message" name="c_message" rows="6" required></textarea></div>';
	$out .= '<button class="btn btn-red btn-form" type="submit">' . esc_html__( 'SEND MESSAGE', 'beazer' ) . '</button></form>';
	return $out;
} );

add_shortcode( 'beazer_featured_video', function () {
	ob_start();
	get_template_part( 'parts/video-player' );
	return ob_get_clean();
} );

/** Validate nonce + honeypot; returns sanitized POST or redirects with an error. */
function beazer_form_input( $action ) {
	$back = wp_get_referer() ?: home_url( '/' );
	$back = remove_query_arg( 'beazer', $back );
	if ( ! isset( $_POST['_beazer_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_beazer_nonce'] ) ), $action ) || ! empty( $_POST['website'] ) ) {
		wp_safe_redirect( add_query_arg( 'beazer', 'error', $back ) );
		exit;
	}
	return $back;
}

function beazer_handle_join() {
	$back    = beazer_form_input( 'beazer_join' );
	$name    = sanitize_text_field( wp_unslash( $_POST['fan_name'] ?? '' ) );
	$email   = sanitize_email( wp_unslash( $_POST['fan_email'] ?? '' ) );
	$country = sanitize_text_field( wp_unslash( $_POST['fan_country'] ?? '' ) );
	if ( ! $name || ! is_email( $email ) || empty( $_POST['fan_consent'] ) ) {
		wp_safe_redirect( add_query_arg( 'beazer', 'error', $back ) . '#join' );
		exit;
	}
	$existing = get_posts( array( 'post_type' => 'beazer_fan', 'title' => $email, 'post_status' => 'private', 'fields' => 'ids', 'numberposts' => 1 ) );
	if ( ! $existing ) {
		$id = wp_insert_post( array( 'post_type' => 'beazer_fan', 'post_status' => 'private', 'post_title' => $email ) );
		if ( $id && ! is_wp_error( $id ) ) {
			update_post_meta( $id, 'fan_name', $name );
			update_post_meta( $id, 'fan_country', $country );
			update_post_meta( $id, 'fan_consent', current_time( 'mysql' ) );
		}
		wp_mail( beazer_notify_email(), 'New BEAZER fan club member', "Name: $name\nEmail: $email\nCountry: $country" );
	}
	wp_safe_redirect( add_query_arg( 'beazer', 'joined', $back ) . '#join' );
	exit;
}
add_action( 'admin_post_beazer_join', 'beazer_handle_join' );
add_action( 'admin_post_nopriv_beazer_join', 'beazer_handle_join' );

function beazer_handle_contact() {
	$back    = beazer_form_input( 'beazer_contact' );
	$name    = sanitize_text_field( wp_unslash( $_POST['c_name'] ?? '' ) );
	$email   = sanitize_email( wp_unslash( $_POST['c_email'] ?? '' ) );
	$topic   = sanitize_text_field( wp_unslash( $_POST['c_topic'] ?? 'General' ) );
	$message = sanitize_textarea_field( wp_unslash( $_POST['c_message'] ?? '' ) );
	if ( ! $name || ! is_email( $email ) || ! $message ) {
		wp_safe_redirect( add_query_arg( 'beazer', 'error', $back ) . '#contact' );
		exit;
	}
	$sent = wp_mail(
		beazer_notify_email(),
		"[BEAZER website] $topic enquiry from $name",
		"Name: $name\nEmail: $email\nTopic: $topic\n\n$message",
		array( 'Reply-To: ' . $name . ' <' . $email . '>' )
	);
	wp_safe_redirect( add_query_arg( 'beazer', $sent ? 'sent' : 'mailfail', $back ) . '#contact' );
	exit;
}
add_action( 'admin_post_beazer_contact', 'beazer_handle_contact' );
add_action( 'admin_post_nopriv_beazer_contact', 'beazer_handle_contact' );

/* Fan Club admin list: show name/country columns and offer a CSV export. */
add_filter( 'manage_beazer_fan_posts_columns', function ( $cols ) {
	return array( 'cb' => $cols['cb'], 'title' => __( 'Email', 'beazer' ), 'fan_name' => __( 'Name', 'beazer' ), 'fan_country' => __( 'Country', 'beazer' ), 'date' => $cols['date'] );
} );
add_action( 'manage_beazer_fan_posts_custom_column', function ( $col, $id ) {
	echo esc_html( get_post_meta( $id, $col, true ) );
}, 10, 2 );
add_action( 'restrict_manage_posts', function ( $type ) {
	if ( 'beazer_fan' === $type ) {
		printf( '<a class="button" href="%s">%s</a>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=beazer_fans_csv' ), 'beazer_fans_csv' ) ), esc_html__( 'Export CSV', 'beazer' ) );
	}
} );
add_action( 'admin_post_beazer_fans_csv', function () {
	if ( ! current_user_can( 'edit_posts' ) || ! check_admin_referer( 'beazer_fans_csv' ) ) {
		wp_die( 'Not allowed' );
	}
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=beazer-fan-club.csv' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'email', 'name', 'country', 'joined' ) );
	foreach ( get_posts( array( 'post_type' => 'beazer_fan', 'post_status' => 'private', 'numberposts' => -1 ) ) as $fan ) {
		$row = array( $fan->post_title, get_post_meta( $fan->ID, 'fan_name', true ), get_post_meta( $fan->ID, 'fan_country', true ), $fan->post_date );
		// Neutralise spreadsheet formulas typed into the public form.
		$row = array_map( function ( $v ) {
			return preg_match( '/^[=+\-@\t\r]/', (string) $v ) ? "'" . $v : $v;
		}, $row );
		fputcsv( $out, $row );
	}
	fclose( $out );
	exit;
} );
