<?php
/**
 * Tweller Flow — Print Provider Accounts & Portal
 *
 * Turns the print providers stored in `tweller_print_providers` into real,
 * logged-in accounts with their own branded dashboard:
 *
 *   • A dedicated WP role (`tweller_print_provider`) with one custom
 *     capability (`tweller_view_print_jobs`) — read-only on the site,
 *     write access only to the print jobs assigned to that provider.
 *   • Studio admin UI to add/edit providers, link an existing WP user or
 *     create a brand new one (WordPress sends the password-set email;
 *     we never store or transmit a plaintext password).
 *   • A front-end dashboard ([tweller_print_provider], page /print-provider)
 *     showing only the jobs assigned to the logged-in provider: stats,
 *     order list, item detail with thumbnails, the existing signed
 *     print-ready ZIP link, shipping label, and the two status
 *     transitions a lab is allowed to make (printing → ready).
 *   • Barcode scanning that closes an order out and records WHO closed it
 *     (studio API key vs provider user) in the fulfilment audit trail.
 *
 * PRIVACY: a provider never sees the customer's name, email or order
 * value per line. The white-label promise made by the fulfilment class is
 * preserved here — the only customer data a provider can reach is the
 * shipping label, and only when that provider is the one delivering.
 *
 * Ownership is ALWAYS derived server-side from the logged-in user. A
 * provider_id supplied in a request is never trusted.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Print_Providers {

	const VERSION = '1.0.1';

	const OPT_VERSION   = 'tweller_print_providers_version';
	const OPT_PROVIDERS = 'tweller_print_providers';
	const OPT_PAGE      = 'tweller_flow_2_provider_page';

	const ROLE = 'tweller_print_provider';
	const CAP  = 'tweller_view_print_jobs';

	const ADMIN_PAGE = 'tweller-flow-2-provider-accounts';
	const REST_NS    = 'tweller-flow-2/v1';

	const PAGE_SLUG  = 'print-provider';
	const SHORTCODE  = 'tweller_print_provider';

	/** Statuses a provider is allowed to set. Never confirmed/cancelled. */
	const PROVIDER_STATUSES = 'printing,ready';

	// ── Bootstrap ──────────────────────────────────────

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 22 );
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_actions' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );

		// Providers have no business in wp-admin.
		add_filter( 'show_admin_bar', array( __CLASS__, 'filter_admin_bar' ) );
		add_action( 'admin_init', array( __CLASS__, 'block_admin_access' ), 1 );

		self::maybe_install();
	}

	/** Version-gated self-heal so activation is never required. */
	public static function maybe_install() {
		if ( get_option( self::OPT_VERSION, '' ) === self::VERSION ) return;
		self::install();
		update_option( self::OPT_VERSION, self::VERSION );
	}

	public static function install() {
		self::register_role();
		self::ensure_page();
	}

	/** Minimal role: read the site, view print jobs. Nothing else. */
	public static function register_role() {
		$role = get_role( self::ROLE );
		if ( ! $role ) {
			add_role( self::ROLE, 'Print Provider', array(
				'read'     => true,
				self::CAP  => true,
			) );
			$role = get_role( self::ROLE );
		}
		if ( $role ) {
			if ( ! $role->has_cap( 'read' ) )    $role->add_cap( 'read' );
			if ( ! $role->has_cap( self::CAP ) ) $role->add_cap( self::CAP );
		}

		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAP ) ) {
			$admin->add_cap( self::CAP );
		}
	}

	/**
	 * Auto-create the "Print Provider" page — same pattern as the tracker,
	 * culling and print-store pages.
	 */
	public static function ensure_page() {
		$existing_url = get_option( self::OPT_PAGE, '' );
		if ( $existing_url ) {
			$page_id = url_to_postid( $existing_url );
			if ( $page_id && get_post_status( $page_id ) === 'publish' ) return;
		}

		$existing = get_posts( array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			's'           => '[' . self::SHORTCODE . ']',
			'numberposts' => 1,
		) );
		if ( ! empty( $existing ) ) {
			update_option( self::OPT_PAGE, get_permalink( $existing[0]->ID ) );
			return;
		}

		$page_id = wp_insert_post( array(
			'post_title'   => 'Print Provider',
			'post_name'    => self::PAGE_SLUG,
			'post_content' => '[' . self::SHORTCODE . ']',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		) );

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( self::OPT_PAGE, get_permalink( $page_id ) );
		}
	}

	public static function page_url() {
		$url = get_option( self::OPT_PAGE, '' );
		return $url ? $url : home_url( '/' . self::PAGE_SLUG . '/' );
	}

	// ── Keep providers out of wp-admin ─────────────────

	public static function filter_admin_bar( $show ) {
		if ( self::is_provider_user() && ! current_user_can( 'manage_options' ) ) return false;
		return $show;
	}

	public static function block_admin_access() {
		if ( ! self::is_provider_user() ) return;
		if ( current_user_can( 'manage_options' ) ) return;
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
		wp_safe_redirect( self::page_url() );
		exit;
	}

	private static function is_provider_user( $user_id = 0 ) {
		$user = $user_id ? get_userdata( (int) $user_id ) : wp_get_current_user();
		if ( ! $user || ! $user->exists() ) return false;
		return in_array( self::ROLE, (array) $user->roles, true );
	}

	// ── Provider records ───────────────────────────────

	/** Canonical shape for one provider record. */
	private static function normalize_provider( $p ) {
		if ( ! is_array( $p ) || empty( $p['id'] ) ) return null;
		$row = array(
			'id'            => sanitize_key( (string) $p['id'] ),
			'name'          => sanitize_text_field( (string) ( isset( $p['name'] ) ? $p['name'] : '' ) ),
			'contact_name'  => sanitize_text_field( (string) ( isset( $p['contact_name'] ) ? $p['contact_name'] : '' ) ),
			'email'         => sanitize_email( (string) ( isset( $p['email'] ) ? $p['email'] : '' ) ),
			'phone'         => sanitize_text_field( (string) ( isset( $p['phone'] ) ? $p['phone'] : '' ) ),
			'address'       => sanitize_textarea_field( (string) ( isset( $p['address'] ) ? $p['address'] : '' ) ),
			'notes'         => sanitize_textarea_field( (string) ( isset( $p['notes'] ) ? $p['notes'] : '' ) ),
			'active'        => empty( $p['active'] ) ? 0 : 1,
			'default'       => empty( $p['default'] ) ? 0 : 1,
			'user_id'       => isset( $p['user_id'] ) ? (int) $p['user_id'] : 0,
			'does_delivery' => empty( $p['does_delivery'] ) ? 0 : 1,
		);
		if ( $row['id'] === '' || $row['name'] === '' ) return null;
		return $row;
	}

	/** @return array<int, array> All providers, normalised. */
	public static function providers() {
		$raw = get_option( self::OPT_PROVIDERS, array() );
		if ( ! is_array( $raw ) ) $raw = array();

		$out = array();
		foreach ( $raw as $p ) {
			$row = self::normalize_provider( $p );
			if ( $row ) $out[] = $row;
		}
		return $out;
	}

	public static function get_provider( $provider_id ) {
		$provider_id = sanitize_key( (string) $provider_id );
		if ( $provider_id === '' ) return null;
		foreach ( self::providers() as $p ) {
			if ( $p['id'] === $provider_id ) return $p;
		}
		return null;
	}

	private static function save_providers( $providers ) {
		$clean = array();
		$used  = array();
		foreach ( (array) $providers as $p ) {
			$row = self::normalize_provider( $p );
			if ( ! $row ) continue;
			if ( isset( $used[ $row['id'] ] ) ) continue;
			$used[ $row['id'] ] = true;
			$clean[]            = $row;
		}

		// Exactly one default, and only among active providers.
		$set = false;
		foreach ( $clean as $i => $p ) {
			if ( ! $set && $p['default'] && $p['active'] ) { $set = true; continue; }
			$clean[ $i ]['default'] = 0;
		}
		if ( ! $set ) {
			foreach ( $clean as $i => $p ) {
				if ( $p['active'] ) { $clean[ $i ]['default'] = 1; break; }
			}
		}

		update_option( self::OPT_PROVIDERS, $clean );
		return $clean;
	}

	/** The provider record linked to a WP user, or null. */
	public static function provider_for_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) return null;
		foreach ( self::providers() as $p ) {
			if ( (int) $p['user_id'] === $user_id ) return $p;
		}
		return null;
	}

	/** Provider record for the logged-in user (server-side truth). */
	public static function current_provider() {
		if ( ! is_user_logged_in() ) return null;
		return self::provider_for_user( get_current_user_id() );
	}

	private static function unique_provider_id( $name, $existing_ids ) {
		$id = sanitize_key( substr( 'pv_' . sanitize_title( $name ), 0, 40 ) );
		if ( $id === '' || $id === 'pv_' ) $id = 'pv_' . substr( md5( $name . microtime() ), 0, 8 );
		$base = $id;
		$i    = 2;
		while ( in_array( $id, $existing_ids, true ) ) { $id = $base . '_' . $i++; }
		return $id;
	}

	// ── Orders belonging to a provider ─────────────────

	private static function orders_table() {
		global $wpdb;
		if ( ! class_exists( 'TwellerFlow2_Prints' ) ) return '';
		return $wpdb->prefix . TwellerFlow2_Prints::TABLE_ORDERS;
	}

	private static function get_order( $order_id ) {
		if ( ! class_exists( 'TwellerFlow2_Prints' ) ) return null;
		return TwellerFlow2_Prints::get_order( (int) $order_id );
	}

	private static function get_order_by_ref( $order_ref ) {
		if ( ! class_exists( 'TwellerFlow2_Prints' ) ) return null;
		return TwellerFlow2_Prints::get_order_by_ref( (string) $order_ref );
	}

	private static function order_items( $order ) {
		if ( ! $order ) return array();
		if ( class_exists( 'TwellerFlow2_Prints' ) ) {
			$items = TwellerFlow2_Prints::get_order_items( $order );
			return is_array( $items ) ? $items : array();
		}
		$items = json_decode( (string) ( isset( $order->items ) ? $order->items : '' ), true );
		return is_array( $items ) ? $items : array();
	}

	/** Fulfilment array for an order — works even without the fulfilment class. */
	public static function fulfillment( $order ) {
		if ( class_exists( 'TwellerFlow2_Print_Fulfillment' ) ) {
			return TwellerFlow2_Print_Fulfillment::get_fulfillment( $order );
		}
		$data = array();
		if ( is_object( $order ) && ! empty( $order->fulfillment ) ) {
			$decoded = json_decode( (string) $order->fulfillment, true );
			if ( is_array( $decoded ) ) $data = $decoded;
		}
		$defaults = array(
			'delivery_address' => '', 'delivery_notes' => '', 'build' => null, 'sends' => array(),
			'delivered_at' => '', 'delivered_via' => '', 'provider_id' => '', 'assigned_at' => '',
			'started_at' => '', 'ready_at' => '', 'shipped_at' => '', 'delivered_by' => array(),
			'audit' => array(),
		);
		$merged = array_merge( $defaults, $data );
		foreach ( array( 'sends', 'audit', 'delivered_by' ) as $k ) {
			if ( ! is_array( $merged[ $k ] ) ) $merged[ $k ] = array();
		}
		if ( (string) $merged['provider_id'] === '' && ! empty( $merged['sends'] ) ) {
			$last = end( $merged['sends'] );
			if ( is_array( $last ) && ! empty( $last['provider_id'] ) ) {
				$merged['provider_id'] = sanitize_key( (string) $last['provider_id'] );
			}
		}
		return $merged;
	}

	private static function save_fulfillment( $order_id, $fulfillment ) {
		if ( class_exists( 'TwellerFlow2_Print_Fulfillment' )
			&& method_exists( 'TwellerFlow2_Print_Fulfillment', 'set_fulfillment' ) ) {
			return TwellerFlow2_Print_Fulfillment::set_fulfillment( (int) $order_id, $fulfillment );
		}

		global $wpdb;
		$table = self::orders_table();
		if ( ! $table ) return false;
		return (bool) $wpdb->update(
			$table,
			array( 'fulfillment' => wp_json_encode( $fulfillment ), 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $order_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/** Provider id an order is assigned to ('' when unassigned). */
	public static function order_provider_id( $order ) {
		$f = self::fulfillment( $order );
		return sanitize_key( (string) $f['provider_id'] );
	}

	/**
	 * Every order assigned to a provider.
	 *
	 * The assignment lives inside a JSON column, so we prefilter in SQL on
	 * the encoded key (fast, indexed-enough for this volume) and confirm
	 * the match in PHP — never trusting the LIKE alone.
	 *
	 * @param string $provider_id
	 * @param int    $limit
	 * @return array<int, object>
	 */
	public static function provider_orders( $provider_id, $limit = 300 ) {
		global $wpdb;

		$provider_id = sanitize_key( (string) $provider_id );
		$table       = self::orders_table();
		if ( $provider_id === '' || ! $table ) return array();

		$needle = '%' . $wpdb->esc_like( '"provider_id":"' . $provider_id . '"' ) . '%';
		$limit  = max( 1, min( 1000, (int) $limit ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE fulfillment LIKE %s ORDER BY created_at DESC LIMIT %d",
			$needle,
			$limit
		) );
		if ( ! is_array( $rows ) ) return array();

		$out = array();
		foreach ( $rows as $row ) {
			if ( self::order_provider_id( $row ) === $provider_id ) $out[] = $row;
		}
		return $out;
	}

	/** Hard ownership gate used by every provider write. */
	private static function owns_order( $provider, $order ) {
		if ( ! $provider || ! $order ) return false;
		return self::order_provider_id( $order ) === $provider['id'];
	}

	// ── Stats ──────────────────────────────────────────

	private static function month_start_ts() {
		return strtotime( date_i18n( 'Y-m-01 00:00:00', current_time( 'timestamp' ) ) );
	}

	/**
	 * Counts + this-month totals for one provider.
	 *
	 * @return array
	 */
	public static function provider_stats( $provider_id ) {
		$stats = array(
			'awaiting'         => 0,
			'in_production'    => 0,
			'ready'            => 0,
			'completed_month'  => 0,
			'value_month'      => 0.0,
			'total'            => 0,
			'by_status'        => array(),
		);

		$statuses = class_exists( 'TwellerFlow2_Prints' )
			? TwellerFlow2_Prints::get_statuses()
			: array( 'new', 'confirmed', 'printing', 'ready', 'completed', 'cancelled' );
		foreach ( $statuses as $s ) { $stats['by_status'][ $s ] = 0; }

		$month = self::month_start_ts();

		foreach ( self::provider_orders( $provider_id ) as $order ) {
			$status = (string) $order->status;
			$f      = self::fulfillment( $order );

			$stats['total']++;
			if ( isset( $stats['by_status'][ $status ] ) ) $stats['by_status'][ $status ]++;

			if ( $status === 'printing' ) {
				$stats['in_production']++;
			} elseif ( $status === 'ready' ) {
				$stats['ready']++;
			} elseif ( $status === 'completed' ) {
				$done = strtotime( (string) ( $f['delivered_at'] !== '' ? $f['delivered_at'] : $order->updated_at ) );
				if ( $done && $done >= $month ) {
					$stats['completed_month']++;
					$stats['value_month'] += (float) $order->subtotal;
				}
			} elseif ( $status !== 'cancelled' ) {
				// Sent to them, nothing started yet.
				$stats['awaiting']++;
			}
		}

		$stats['value_month'] = round( $stats['value_month'], 2 );
		return $stats;
	}

	/**
	 * Studio-side view: per-provider counts by status + this-month totals.
	 * Safe for the admin dashboard and the mobile app to call.
	 *
	 * @return array<int, array>
	 */
	public static function provider_summary() {
		$out = array();
		foreach ( self::providers() as $p ) {
			$stats = self::provider_stats( $p['id'] );
			$user  = $p['user_id'] ? get_userdata( $p['user_id'] ) : null;
			$out[] = array(
				'id'             => $p['id'],
				'name'           => $p['name'],
				'active'         => (int) $p['active'],
				'does_delivery'  => (int) $p['does_delivery'],
				'has_account'    => ( $user && $user->exists() ) ? 1 : 0,
				'account_login'  => ( $user && $user->exists() ) ? $user->user_login : '',
				'stats'          => $stats,
			);
		}
		return $out;
	}

	// ── Admin: provider accounts ───────────────────────

	public static function register_admin_menu() {
		add_submenu_page(
			'tweller-flow-2',
			'Provider Accounts',
			'Provider Accounts',
			'manage_options',
			self::ADMIN_PAGE,
			array( __CLASS__, 'page_accounts' )
		);
	}

	private static function admin_url_for( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::ADMIN_PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	public static function handle_admin_actions() {
		if ( ! is_admin() ) return;
		if ( empty( $_POST['tfpv_action'] ) && empty( $_GET['tfpv_action'] ) ) return;

		$action = isset( $_POST['tfpv_action'] )
			? sanitize_key( wp_unslash( $_POST['tfpv_action'] ) )
			: sanitize_key( wp_unslash( $_GET['tfpv_action'] ) );

		if ( ! in_array( $action, array( 'save_provider', 'delete_provider', 'unlink_user', 'resend_password' ), true ) ) return;
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Permission denied.' ) );

		check_admin_referer( 'tfpv_' . $action );

		if ( $action === 'save_provider' ) {
			self::admin_save_provider();
			return;
		}

		$provider_id = isset( $_REQUEST['provider_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['provider_id'] ) ) : '';

		if ( $action === 'delete_provider' ) {
			$providers = self::providers();
			$kept      = array();
			foreach ( $providers as $p ) {
				if ( $p['id'] !== $provider_id ) $kept[] = $p;
			}
			self::save_providers( $kept );
			wp_safe_redirect( self::admin_url_for( array( 'tfpv_msg' => 'deleted' ) ) );
			exit;
		}

		if ( $action === 'unlink_user' ) {
			$providers = self::providers();
			foreach ( $providers as $i => $p ) {
				if ( $p['id'] === $provider_id ) $providers[ $i ]['user_id'] = 0;
			}
			self::save_providers( $providers );
			wp_safe_redirect( self::admin_url_for( array( 'tfpv_msg' => 'unlinked' ) ) );
			exit;
		}

		if ( $action === 'resend_password' ) {
			$provider = self::get_provider( $provider_id );
			if ( $provider && $provider['user_id'] ) {
				$user = get_userdata( $provider['user_id'] );
				if ( $user && $user->exists() ) {
					wp_send_new_user_notifications( $user->ID, 'user' );
				}
			}
			wp_safe_redirect( self::admin_url_for( array( 'tfpv_msg' => 'sent' ) ) );
			exit;
		}
	}

	private static function admin_save_provider() {
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- verified by caller

		$name = sanitize_text_field( (string) ( isset( $post['name'] ) ? $post['name'] : '' ) );
		if ( $name === '' ) {
			wp_safe_redirect( self::admin_url_for( array( 'tfpv_error' => rawurlencode( 'A provider name is required.' ) ) ) );
			exit;
		}

		$providers   = self::providers();
		$existing_id = sanitize_key( (string) ( isset( $post['provider_id'] ) ? $post['provider_id'] : '' ) );
		$index       = -1;
		foreach ( $providers as $i => $p ) {
			if ( $p['id'] === $existing_id && $existing_id !== '' ) { $index = $i; break; }
		}

		$record = $index >= 0 ? $providers[ $index ] : array(
			'id' => '', 'name' => '', 'contact_name' => '', 'email' => '', 'phone' => '',
			'address' => '', 'notes' => '', 'active' => 1, 'default' => 0, 'user_id' => 0, 'does_delivery' => 0,
		);

		if ( $record['id'] === '' ) {
			$ids          = array();
			foreach ( $providers as $p ) { $ids[] = $p['id']; }
			$record['id'] = self::unique_provider_id( $name, $ids );
		}

		$record['name']          = $name;
		$record['contact_name']  = sanitize_text_field( (string) ( isset( $post['contact_name'] ) ? $post['contact_name'] : '' ) );
		$record['email']         = sanitize_email( (string) ( isset( $post['email'] ) ? $post['email'] : '' ) );
		$record['phone']         = sanitize_text_field( (string) ( isset( $post['phone'] ) ? $post['phone'] : '' ) );
		$record['address']       = sanitize_textarea_field( (string) ( isset( $post['address'] ) ? $post['address'] : '' ) );
		$record['notes']         = sanitize_textarea_field( (string) ( isset( $post['notes'] ) ? $post['notes'] : '' ) );
		$record['active']        = empty( $post['active'] ) ? 0 : 1;
		$record['does_delivery'] = empty( $post['does_delivery'] ) ? 0 : 1;

		$error = '';

		// ── Account: link an existing user, or create a new one ──
		$account_mode = isset( $post['account_mode'] ) ? sanitize_key( (string) $post['account_mode'] ) : 'keep';

		if ( $account_mode === 'link' ) {
			$user_id = isset( $post['user_id'] ) ? (int) $post['user_id'] : 0;
			if ( $user_id > 0 ) {
				$user = get_userdata( $user_id );
				if ( ! $user || ! $user->exists() ) {
					$error = 'That user account no longer exists.';
				} elseif ( self::user_taken( $user_id, $record['id'] ) ) {
					$error = 'That user is already linked to another provider.';
				} else {
					if ( ! user_can( $user_id, self::CAP ) ) {
						$user->add_role( self::ROLE );
					}
					$record['user_id'] = $user_id;
				}
			} else {
				$record['user_id'] = 0;
			}
		} elseif ( $account_mode === 'create' ) {
			$login = sanitize_user( (string) ( isset( $post['new_username'] ) ? $post['new_username'] : '' ), true );
			$email = sanitize_email( (string) ( isset( $post['new_email'] ) ? $post['new_email'] : '' ) );
			$fname = sanitize_text_field( (string) ( isset( $post['new_name'] ) ? $post['new_name'] : '' ) );

			if ( $login === '' || ! is_email( $email ) ) {
				$error = 'A username and a valid email address are required to create a provider account.';
			} elseif ( username_exists( $login ) ) {
				$error = 'That username is already taken.';
			} elseif ( email_exists( $email ) ) {
				$error = 'That email address already belongs to a WordPress user — link the existing account instead.';
			} else {
				// Never store or email a plaintext password: WordPress
				// generates one and mails the user a set-password link.
				$new_id = wp_insert_user( array(
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 24, true, true ),
					'display_name' => $fname !== '' ? $fname : $name,
					'first_name'   => $fname,
					'role'         => self::ROLE,
				) );

				if ( is_wp_error( $new_id ) ) {
					$error = $new_id->get_error_message();
				} else {
					$record['user_id'] = (int) $new_id;
					wp_send_new_user_notifications( (int) $new_id, 'both' );
				}
			}
		}

		if ( $index >= 0 ) {
			$providers[ $index ] = $record;
		} else {
			$providers[] = $record;
		}
		self::save_providers( $providers );

		$args = $error !== ''
			? array( 'tfpv_error' => rawurlencode( $error ), 'edit' => $record['id'] )
			: array( 'tfpv_msg' => 'saved' );

		wp_safe_redirect( self::admin_url_for( $args ) );
		exit;
	}

	private static function user_taken( $user_id, $except_provider_id ) {
		foreach ( self::providers() as $p ) {
			if ( $p['id'] === $except_provider_id ) continue;
			if ( (int) $p['user_id'] === (int) $user_id ) return true;
		}
		return false;
	}

	public static function page_accounts() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.' ) );
		}
		self::register_role(); // cheap self-heal if the role was wiped

		$providers = self::providers();
		$edit_id   = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$editing   = $edit_id !== '' ? self::get_provider( $edit_id ) : null;
		$msg       = isset( $_GET['tfpv_msg'] ) ? sanitize_key( wp_unslash( $_GET['tfpv_msg'] ) ) : '';
		$error     = isset( $_GET['tfpv_error'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['tfpv_error'] ) ) ) : '';

		$messages = array(
			'saved'    => 'Provider saved.',
			'deleted'  => 'Provider deleted.',
			'unlinked' => 'Account unlinked. The provider can no longer sign in to that dashboard.',
			'sent'     => 'Password-set email sent.',
		);

		echo '<div class="wrap"><h1>Provider Accounts</h1>';
		echo '<p style="max-width:760px; color:#50575e;">Give a print lab its own login. They see only the jobs you send them — never your customer\'s name, email or prices — and can move a job to <strong>Printing</strong> and <strong>Ready</strong>, download the print-ready ZIP, and scan the delivery barcode to close it out.</p>';

		echo '<p><strong>Provider dashboard:</strong> <a href="' . esc_url( self::page_url() ) . '" target="_blank" rel="noopener">' . esc_html( self::page_url() ) . '</a></p>';

		if ( $error !== '' ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( $msg !== '' && isset( $messages[ $msg ] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $messages[ $msg ] ) . '</p></div>';
		}

		self::render_provider_table( $providers );
		self::render_provider_form( $editing );

		echo '</div>';
	}

	private static function render_provider_table( $providers ) {
		echo '<h2 style="margin-top:28px;">Providers</h2>';

		if ( empty( $providers ) ) {
			echo '<p>No print providers yet. Add your first one below.</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>'
			. '<th>Provider</th><th>Contact</th><th>Account</th><th>Delivers</th><th>Jobs (open / month)</th><th>Status</th><th></th>'
			. '</tr></thead><tbody>';

		foreach ( $providers as $p ) {
			$user  = $p['user_id'] ? get_userdata( $p['user_id'] ) : null;
			$stats = self::provider_stats( $p['id'] );
			$open  = (int) $stats['awaiting'] + (int) $stats['in_production'] + (int) $stats['ready'];

			$account = '<span style="color:#b32d2e;">No account</span>';
			if ( $user && $user->exists() ) {
				$account = '<strong>' . esc_html( $user->user_login ) . '</strong><br><span style="color:#646970;">' . esc_html( $user->user_email ) . '</span>';
			}

			$edit_url   = self::admin_url_for( array( 'edit' => $p['id'] ) );
			$delete_url = wp_nonce_url( self::admin_url_for( array( 'tfpv_action' => 'delete_provider', 'provider_id' => $p['id'] ) ), 'tfpv_delete_provider' );
			$unlink_url = wp_nonce_url( self::admin_url_for( array( 'tfpv_action' => 'unlink_user', 'provider_id' => $p['id'] ) ), 'tfpv_unlink_user' );
			$resend_url = wp_nonce_url( self::admin_url_for( array( 'tfpv_action' => 'resend_password', 'provider_id' => $p['id'] ) ), 'tfpv_resend_password' );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $p['name'] ) . '</strong>' . ( $p['default'] ? ' <span style="color:#C9A227;">(default)</span>' : '' ) . '<br><code>' . esc_html( $p['id'] ) . '</code></td>';
			echo '<td>' . esc_html( $p['contact_name'] ) . '<br><span style="color:#646970;">' . esc_html( $p['email'] ) . '</span></td>';
			echo '<td>' . wp_kses_post( $account ) . '</td>';
			echo '<td>' . ( $p['does_delivery'] ? 'Provider ships' : 'Studio delivers' ) . '</td>';
			echo '<td>' . (int) $open . ' open &middot; ' . (int) $stats['completed_month'] . ' done<br><span style="color:#646970;">TT$ ' . esc_html( number_format( (float) $stats['value_month'], 2 ) ) . '</span></td>';
			echo '<td>' . ( $p['active'] ? 'Active' : 'Inactive' ) . '</td>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">Edit</a>';
			if ( $user && $user->exists() ) {
				echo ' | <a href="' . esc_url( $resend_url ) . '">Resend password email</a>';
				echo ' | <a href="' . esc_url( $unlink_url ) . '" onclick="return confirm(\'Unlink this account?\');">Unlink</a>';
			}
			echo ' | <a href="' . esc_url( $delete_url ) . '" style="color:#b32d2e;" onclick="return confirm(\'Delete this provider? Jobs already sent to them keep their history.\');">Delete</a>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private static function render_provider_form( $editing ) {
		$p = $editing ? $editing : array(
			'id' => '', 'name' => '', 'contact_name' => '', 'email' => '', 'phone' => '',
			'address' => '', 'notes' => '', 'active' => 1, 'user_id' => 0, 'does_delivery' => 0,
		);
		$linked = ! empty( $p['user_id'] ) ? get_userdata( $p['user_id'] ) : null;

		echo '<h2 style="margin-top:32px;">' . ( $editing ? 'Edit provider' : 'Add a provider' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::ADMIN_PAGE ) ) . '" style="max-width:760px;">';
		wp_nonce_field( 'tfpv_save_provider' );
		echo '<input type="hidden" name="tfpv_action" value="save_provider">';
		echo '<input type="hidden" name="provider_id" value="' . esc_attr( $p['id'] ) . '">';

		echo '<table class="form-table"><tbody>';

		self::field_row( 'Provider name', '<input type="text" class="regular-text" name="name" required value="' . esc_attr( $p['name'] ) . '">' );
		self::field_row( 'Contact person', '<input type="text" class="regular-text" name="contact_name" value="' . esc_attr( $p['contact_name'] ) . '">' );
		self::field_row( 'Email', '<input type="email" class="regular-text" name="email" value="' . esc_attr( $p['email'] ) . '"><p class="description">Where job packages are emailed.</p>' );
		self::field_row( 'Phone', '<input type="text" class="regular-text" name="phone" value="' . esc_attr( $p['phone'] ) . '">' );
		self::field_row( 'Address', '<textarea name="address" rows="3" class="large-text">' . esc_textarea( $p['address'] ) . '</textarea>' );
		self::field_row( 'Notes', '<textarea name="notes" rows="3" class="large-text">' . esc_textarea( $p['notes'] ) . '</textarea>' );
		self::field_row( 'Delivery',
			'<label><input type="checkbox" name="does_delivery" value="1" ' . checked( ! empty( $p['does_delivery'] ), true, false ) . '> This provider ships finished orders to the client</label>'
			. '<p class="description">Leave unchecked when the studio collects and delivers.</p>' );
		self::field_row( 'Active',
			'<label><input type="checkbox" name="active" value="1" ' . checked( ! empty( $p['active'] ), true, false ) . '> Available for new jobs</label>' );

		// ── Portal account ──
		$account_html  = '<fieldset>';
		if ( $linked && $linked->exists() ) {
			$account_html .= '<p><strong>Linked:</strong> ' . esc_html( $linked->user_login ) . ' &lt;' . esc_html( $linked->user_email ) . '&gt;</p>';
		} else {
			$account_html .= '<p style="color:#b32d2e;">No login yet — this provider cannot use the dashboard.</p>';
		}

		$account_html .= '<p><label><input type="radio" name="account_mode" value="keep" checked> Leave the account as it is</label></p>';

		$account_html .= '<p><label><input type="radio" name="account_mode" value="link"> Link an existing WordPress user</label><br>';
		$account_html .= self::user_select_html( (int) $p['user_id'] ) . '</p>';

		$account_html .= '<p><label><input type="radio" name="account_mode" value="create"> Create a new provider login</label></p>';
		$account_html .= '<p style="margin-left:24px;">'
			. '<label style="display:block; margin-bottom:6px;">Username<br><input type="text" class="regular-text" name="new_username" autocomplete="off"></label>'
			. '<label style="display:block; margin-bottom:6px;">Email<br><input type="email" class="regular-text" name="new_email" autocomplete="off"></label>'
			. '<label style="display:block;">Name<br><input type="text" class="regular-text" name="new_name" autocomplete="off"></label>'
			. '<span class="description">WordPress emails them a link to set their own password. No password is ever stored or sent by us.</span></p>';
		$account_html .= '</fieldset>';

		self::field_row( 'Dashboard account', $account_html );

		echo '</tbody></table>';
		submit_button( $editing ? 'Save provider' : 'Add provider' );
		if ( $editing ) {
			echo '<a href="' . esc_url( self::admin_url_for() ) . '" class="button">Cancel</a>';
		}
		echo '</form>';
	}

	private static function field_row( $label, $html ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . wp_kses( $html, self::form_kses() ) . '</td></tr>';
	}

	/** Allowed markup for the admin form rows. */
	private static function form_kses() {
		$attrs = array(
			'name' => true, 'id' => true, 'class' => true, 'style' => true, 'type' => true,
			'value' => true, 'checked' => true, 'selected' => true, 'required' => true,
			'rows' => true, 'placeholder' => true, 'autocomplete' => true, 'for' => true,
		);
		return array(
			'input'    => $attrs,
			'textarea' => $attrs,
			'select'   => $attrs,
			'option'   => $attrs,
			'label'    => $attrs,
			'p'        => $attrs,
			'span'     => $attrs,
			'strong'   => $attrs,
			'br'       => array(),
			'fieldset' => $attrs,
		);
	}

	/** Native searchable select of candidate users (type-to-find works). */
	private static function user_select_html( $selected ) {
		$users = get_users( array(
			'number'  => 500,
			'orderby' => 'display_name',
			'fields'  => array( 'ID', 'user_login', 'user_email', 'display_name' ),
		) );

		$html = '<select name="user_id" style="max-width:420px; margin-left:24px;"><option value="0">— none —</option>';
		foreach ( $users as $u ) {
			$label = $u->display_name . ' (' . $u->user_login . ' · ' . $u->user_email . ')';
			$html .= '<option value="' . (int) $u->ID . '" ' . selected( (int) $selected, (int) $u->ID, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select>';
		return $html;
	}

	// ── Front end ──────────────────────────────────────

	public static function register_assets() {
		wp_register_style(
			'tweller-flow-2-provider',
			TWELLER_FLOW_2_PLUGIN_URL . 'public/css/provider.css',
			array(),
			defined( 'TWELLER_FLOW_2_VERSION' ) ? TWELLER_FLOW_2_VERSION : self::VERSION
		);
		wp_register_script(
			'tweller-flow-2-provider',
			TWELLER_FLOW_2_PLUGIN_URL . 'public/js/provider.js',
			array(),
			defined( 'TWELLER_FLOW_2_VERSION' ) ? TWELLER_FLOW_2_VERSION : self::VERSION,
			true
		);
	}

	public static function render_shortcode( $atts ) {
		unset( $atts );
		wp_enqueue_style( 'tweller-flow-2-provider' );

		if ( ! is_user_logged_in() ) {
			return self::render_login();
		}

		$provider = self::current_provider();
		if ( ! current_user_can( self::CAP ) || ! $provider ) {
			return self::render_no_access();
		}
		if ( empty( $provider['active'] ) ) {
			return self::render_card(
				'Account paused',
				'This provider account is currently inactive. Please contact Tweller Studios.',
				true
			);
		}

		wp_enqueue_script( 'tweller-flow-2-provider' );
		wp_localize_script( 'tweller-flow-2-provider', 'twellerProvider', array(
			'restUrl'  => esc_url_raw( rest_url( self::REST_NS . '/prints/provider/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'name'     => $provider['name'],
			'delivers' => (int) $provider['does_delivery'],
			'logout'   => esc_url_raw( wp_logout_url( self::page_url() ) ),
		) );

		$user = wp_get_current_user();

		ob_start();
		?>
		<div class="tfpv">
			<header class="tfpv__top">
				<div class="tfpv__brand">
					<span class="tfpv__brand-name">TWELLER</span>
					<span class="tfpv__brand-sub">Studios &middot; Print Partner</span>
				</div>
				<div class="tfpv__whoami">
					<span class="tfpv__provider"><?php echo esc_html( $provider['name'] ); ?></span>
					<a class="tfpv__logout" href="<?php echo esc_url( wp_logout_url( self::page_url() ) ); ?>">Sign out</a>
				</div>
			</header>

			<div class="tfpv__hello">
				<h1 class="tfpv__title">Your print jobs</h1>
				<p class="tfpv__sub">Signed in as <?php echo esc_html( $user->display_name ); ?>. Everything here is a live job from Tweller Studios.</p>
			</div>

			<div class="tfpv__alert" id="tfpv-alert" hidden></div>

			<section class="tfpv__scan">
				<h2 class="tfpv__h2">Scan to complete</h2>
				<p class="tfpv__hint">Scan or type the code printed under the barcode on the shipping label to close a job out.</p>
				<div class="tfpv__scan-row">
					<input type="text" id="tfpv-scan-input" class="tfpv__input" placeholder="TS-PRINT-XXXXXX|code" autocomplete="off" spellcheck="false">
					<button type="button" class="tfpv__btn tfpv__btn--gold" id="tfpv-scan-go">Verify</button>
					<button type="button" class="tfpv__btn tfpv__btn--ghost" id="tfpv-scan-cam" hidden>Use camera</button>
				</div>
				<video id="tfpv-scan-video" class="tfpv__video" playsinline hidden></video>
			</section>

			<div id="tfpv-stats" class="tfpv__stats" aria-live="polite"></div>
			<div id="tfpv-orders" class="tfpv__orders">
				<p class="tfpv__loading">Loading your jobs&hellip;</p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_card( $title, $body, $muted = false ) {
		ob_start();
		?>
		<div class="tfpv tfpv--centered">
			<div class="tfpv__card<?php echo $muted ? ' tfpv__card--muted' : ''; ?>">
				<div class="tfpv__brand tfpv__brand--stack">
					<span class="tfpv__brand-name">TWELLER</span>
					<span class="tfpv__brand-sub">Studios &middot; Print Partner</span>
				</div>
				<h1 class="tfpv__card-title"><?php echo esc_html( $title ); ?></h1>
				<p class="tfpv__card-body"><?php echo esc_html( $body ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_login() {
		$redirect = self::page_url();

		ob_start();
		?>
		<div class="tfpv tfpv--centered">
			<div class="tfpv__card">
				<div class="tfpv__brand tfpv__brand--stack">
					<span class="tfpv__brand-name">TWELLER</span>
					<span class="tfpv__brand-sub">Studios &middot; Print Partner</span>
				</div>
				<h1 class="tfpv__card-title">Print partner sign in</h1>
				<p class="tfpv__card-body">Sign in to pick up the print jobs assigned to you.</p>
				<div class="tfpv__login">
					<?php
					wp_login_form( array(
						'redirect'       => $redirect,
						'label_username' => 'Username or email',
						'label_password' => 'Password',
						'label_log_in'   => 'Sign in',
						'remember'       => true,
					) );
					?>
				</div>
				<p class="tfpv__card-foot"><a href="<?php echo esc_url( wp_lostpassword_url( $redirect ) ); ?>">Forgot your password?</a></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_no_access() {
		return self::render_card(
			'No print jobs here',
			'This account is signed in, but it is not linked to a print provider. If you print for Tweller Studios, contact the studio and they will connect your login.',
			true
		);
	}

	// ── REST ───────────────────────────────────────────

	public static function register_rest_routes() {
		$ns   = self::REST_NS;
		$auth = array( __CLASS__, 'rest_provider_permission' );

		register_rest_route( $ns, '/prints/provider/me', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_me' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( $ns, '/prints/provider/orders/(?P<order_ref>[A-Za-z0-9\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_order' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( $ns, '/prints/provider/orders/(?P<order_ref>[A-Za-z0-9\-]+)/status', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_set_status' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( $ns, '/prints/provider/orders/(?P<order_ref>[A-Za-z0-9\-]+)/shipped', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_shipped' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( $ns, '/prints/provider/orders/(?P<order_ref>[A-Za-z0-9\-]+)/build', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_build' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( $ns, '/prints/provider/scan', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_provider_scan' ),
			'permission_callback' => $auth,
		) );

		// Studio / mobile app: per-provider stats.
		register_rest_route( $ns, '/prints/providers/summary', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_summary' ),
			'permission_callback' => array( __CLASS__, 'rest_studio_permission' ),
		) );
	}

	/**
	 * Cookie-authenticated (X-WP-Nonce) provider access. WordPress rejects
	 * the request before this runs when the REST nonce is missing or stale.
	 */
	public static function rest_provider_permission() {
		if ( ! is_user_logged_in() || ! current_user_can( self::CAP ) ) {
			return new WP_Error( 'forbidden', 'You are not signed in as a print provider.', array( 'status' => 401 ) );
		}
		$provider = self::current_provider();
		if ( ! $provider || empty( $provider['active'] ) ) {
			return new WP_Error( 'no_provider', 'This account is not linked to an active print provider.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function rest_studio_permission( $request ) {
		if ( current_user_can( 'manage_options' ) ) return true;
		if ( class_exists( 'TwellerFlow2_Photo_Automation' )
			&& method_exists( 'TwellerFlow2_Photo_Automation', 'verify_api_key' ) ) {
			return TwellerFlow2_Photo_Automation::verify_api_key( $request );
		}
		return new WP_Error( 'forbidden', 'Not allowed.', array( 'status' => 403 ) );
	}

	/** Resolve the order for this request, enforcing ownership. */
	private static function require_own_order( $request, &$provider ) {
		$provider = self::current_provider();
		if ( ! $provider ) {
			return new WP_Error( 'no_provider', 'This account is not linked to a print provider.', array( 'status' => 403 ) );
		}

		$order_ref = strtoupper( sanitize_text_field( (string) $request['order_ref'] ) );
		$order     = self::get_order_by_ref( $order_ref );
		if ( ! $order ) {
			return new WP_Error( 'not_found', 'Job not found.', array( 'status' => 404 ) );
		}
		// Ownership is derived from the order itself — never from input.
		if ( ! self::owns_order( $provider, $order ) ) {
			return new WP_Error( 'not_yours', 'That job is not assigned to you.', array( 'status' => 403 ) );
		}
		return $order;
	}

	public static function rest_me( $request ) {
		unset( $request );
		$provider = self::current_provider();
		$orders   = array();
		foreach ( self::provider_orders( $provider['id'] ) as $order ) {
			$orders[] = self::order_payload( $order, $provider, false );
		}

		return rest_ensure_response( array(
			'provider' => array(
				'name'          => $provider['name'],
				'does_delivery' => (int) $provider['does_delivery'],
			),
			'stats'    => self::provider_stats( $provider['id'] ),
			'currency' => 'TT$',
			'orders'   => $orders,
		) );
	}

	public static function rest_order( $request ) {
		$provider = null;
		$order    = self::require_own_order( $request, $provider );
		if ( is_wp_error( $order ) ) return $order;

		return rest_ensure_response( self::order_payload( $order, $provider, true ) );
	}

	public static function rest_set_status( $request ) {
		$provider = null;
		$order    = self::require_own_order( $request, $provider );
		if ( is_wp_error( $order ) ) return $order;

		$status  = sanitize_key( (string) $request->get_param( 'status' ) );
		$allowed = explode( ',', self::PROVIDER_STATUSES );
		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'bad_status', 'A print provider can only set a job to Printing or Ready.', array( 'status' => 400 ) );
		}
		if ( in_array( (string) $order->status, array( 'completed', 'cancelled' ), true ) ) {
			return new WP_Error( 'closed', 'This job is already closed.', array( 'status' => 409 ) );
		}

		$f   = self::fulfillment( $order );
		$now = current_time( 'mysql' );
		if ( $status === 'printing' && (string) $f['started_at'] === '' ) $f['started_at'] = $now;
		if ( $status === 'ready' ) {
			$f['ready_at'] = $now;
			if ( (string) $f['started_at'] === '' ) $f['started_at'] = $now;
		}
		self::save_fulfillment( $order->id, $f );

		self::update_status( $order, $status );
		self::audit( $order->id, 'provider', get_current_user_id(), 'status_' . $status );

		$order = self::get_order_by_ref( $order->order_ref );
		return rest_ensure_response( array(
			'ok'    => true,
			'order' => self::order_payload( $order, $provider, true ),
		) );
	}

	public static function rest_shipped( $request ) {
		$provider = null;
		$order    = self::require_own_order( $request, $provider );
		if ( is_wp_error( $order ) ) return $order;

		if ( empty( $provider['does_delivery'] ) ) {
			return new WP_Error( 'not_shipper', 'Tweller Studios delivers this job — leave it marked Ready for collection.', array( 'status' => 403 ) );
		}

		$f = self::fulfillment( $order );
		if ( (string) $f['shipped_at'] === '' ) {
			$f['shipped_at'] = current_time( 'mysql' );
			if ( (string) $f['ready_at'] === '' ) $f['ready_at'] = $f['shipped_at'];
			self::save_fulfillment( $order->id, $f );
		}
		if ( ! in_array( (string) $order->status, array( 'ready', 'completed', 'cancelled' ), true ) ) {
			self::update_status( $order, 'ready' );
		}
		self::audit( $order->id, 'provider', get_current_user_id(), 'shipped' );

		$order = self::get_order_by_ref( $order->order_ref );
		return rest_ensure_response( array(
			'ok'    => true,
			'order' => self::order_payload( $order, $provider, true ),
		) );
	}

	/**
	 * Build (or continue building) the print-ready package on demand, so a
	 * provider is never stuck waiting for the studio to press a button.
	 */
	public static function rest_build( $request ) {
		$provider = null;
		$order    = self::require_own_order( $request, $provider );
		if ( is_wp_error( $order ) ) return $order;

		if ( ! class_exists( 'TwellerFlow2_Print_Fulfillment' )
			|| ! method_exists( 'TwellerFlow2_Print_Fulfillment', 'build_chunk' ) ) {
			return new WP_Error( 'unavailable', 'File preparation is not available on this site.', array( 'status' => 503 ) );
		}

		$build = TwellerFlow2_Print_Fulfillment::build_chunk( (int) $order->id, false );
		$done  = ! empty( $build['done'] );

		if ( $done ) {
			self::audit( $order->id, 'provider', get_current_user_id(), 'built_package' );
		}

		return rest_ensure_response( array(
			'ok'       => true,
			'done'     => $done,
			'progress' => (int) ( isset( $build['next'] ) ? $build['next'] : 0 ),
			'total'    => (int) ( isset( $build['total'] ) ? $build['total'] : 0 ),
			'zip_url'  => $done ? self::zip_url( $order ) : '',
			'error'    => isset( $build['error'] ) ? (string) $build['error'] : '',
		) );
	}

	/**
	 * Provider scan: same verification as the studio scan, but ownership
	 * checked and attributed to the provider account.
	 */
	public static function rest_provider_scan( $request ) {
		$provider = self::current_provider();
		if ( ! $provider ) {
			return new WP_Error( 'no_provider', 'This account is not linked to a print provider.', array( 'status' => 403 ) );
		}

		$code = (string) $request->get_param( 'code' );
		$code = trim( preg_replace( '/\s+/', '', $code ) );
		if ( $code === '' ) {
			return new WP_Error( 'no_code', 'No code was supplied.', array( 'status' => 400 ) );
		}

		$parts = explode( '|', $code );
		if ( count( $parts ) !== 2 ) {
			return new WP_Error( 'bad_code', 'That is not a Tweller delivery code.', array( 'status' => 400 ) );
		}

		$order_ref = strtoupper( sanitize_text_field( $parts[0] ) );
		$hmac      = strtolower( preg_replace( '/[^a-fA-F0-9]/', '', $parts[1] ) );

		$order = self::get_order_by_ref( $order_ref );
		if ( ! $order ) {
			return new WP_Error( 'not_found', 'No job matches that code.', array( 'status' => 404 ) );
		}
		if ( ! self::owns_order( $provider, $order ) ) {
			return new WP_Error( 'not_yours', 'That job is not assigned to you.', array( 'status' => 403 ) );
		}
		if ( ! class_exists( 'TwellerFlow2_Print_Fulfillment' ) ) {
			return new WP_Error( 'unavailable', 'Scanning is not available on this site.', array( 'status' => 503 ) );
		}
		if ( ! hash_equals( TwellerFlow2_Print_Fulfillment::delivery_token( $order->order_ref ), $hmac ) ) {
			return new WP_Error( 'bad_signature', 'That code failed verification.', array( 'status' => 403 ) );
		}

		$f = self::fulfillment( $order );
		if ( ! empty( $f['delivered_at'] ) ) {
			return rest_ensure_response( array(
				'ok'                => true,
				'order_ref'         => $order->order_ref,
				'already_delivered' => true,
				'delivered_at'      => (string) $f['delivered_at'],
				'message'           => 'Job ' . $order->order_ref . ' was already completed.',
			) );
		}
		if ( ! in_array( (string) $order->status, array( 'ready', 'printing' ), true ) ) {
			return new WP_Error(
				'not_ready',
				'Mark this job Ready before scanning it as complete.',
				array( 'status' => 409 )
			);
		}

		$result = TwellerFlow2_Print_Fulfillment::mark_delivered(
			$order,
			'provider_scan',
			array( 'type' => 'provider', 'id' => get_current_user_id() )
		);

		return rest_ensure_response( array(
			'ok'                => true,
			'order_ref'         => $order->order_ref,
			'already_delivered' => ! empty( $result['already'] ),
			'delivered_at'      => (string) $result['delivered_at'],
			'message'           => 'Job ' . $order->order_ref . ' verified and completed.',
		) );
	}

	public static function rest_summary( $request ) {
		unset( $request );
		return rest_ensure_response( array(
			'ok'         => true,
			'currency'   => 'TT$',
			'month'      => date_i18n( 'F Y', current_time( 'timestamp' ) ),
			'providers'  => self::provider_summary(),
		) );
	}

	// ── Payload + mutation helpers ─────────────────────

	private static function audit( $order_id, $actor_type, $actor_id, $action ) {
		if ( class_exists( 'TwellerFlow2_Print_Fulfillment' )
			&& method_exists( 'TwellerFlow2_Print_Fulfillment', 'log_audit' ) ) {
			TwellerFlow2_Print_Fulfillment::log_audit( (int) $order_id, $actor_type, (int) $actor_id, $action );
			return;
		}

		$order = self::get_order( $order_id );
		if ( ! $order ) return;
		$f          = self::fulfillment( $order );
		$f['audit'][] = array(
			'at'         => current_time( 'mysql' ),
			'actor_type' => ( $actor_type === 'provider' ) ? 'provider' : 'studio',
			'actor_id'   => (int) $actor_id,
			'action'     => sanitize_key( (string) $action ),
		);
		self::save_fulfillment( $order_id, $f );
	}

	private static function update_status( $order, $status ) {
		if ( class_exists( 'TwellerFlow2_Prints' ) && method_exists( 'TwellerFlow2_Prints', 'update_status' ) ) {
			// Notify the customer on the same terms the studio would.
			TwellerFlow2_Prints::update_status( (int) $order->id, $status, true );
			return;
		}

		global $wpdb;
		$table = self::orders_table();
		if ( ! $table ) return;
		$wpdb->update(
			$table,
			array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $order->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	private static function status_label( $status ) {
		if ( class_exists( 'TwellerFlow2_Prints' ) && method_exists( 'TwellerFlow2_Prints', 'get_status_label' ) ) {
			return TwellerFlow2_Prints::get_status_label( $status );
		}
		return ucfirst( (string) $status );
	}

	private static function zip_url( $order ) {
		if ( ! class_exists( 'TwellerFlow2_Print_Fulfillment' )
			|| ! method_exists( 'TwellerFlow2_Print_Fulfillment', 'signed_zip_url' ) ) return '';
		return TwellerFlow2_Print_Fulfillment::signed_zip_url( (string) $order->order_ref );
	}

	private static function label_url( $order ) {
		if ( ! class_exists( 'TwellerFlow2_Print_Fulfillment' )
			|| ! method_exists( 'TwellerFlow2_Print_Fulfillment', 'label_url' ) ) return '';
		return TwellerFlow2_Print_Fulfillment::label_url( (string) $order->order_ref );
	}

	/** "4×6 ×6 · 8×10 ×2" — what a lab actually needs at a glance. */
	private static function size_summary( $items ) {
		$counts = array();
		foreach ( $items as $it ) {
			$name = trim( (string) ( isset( $it['product_name'] ) ? $it['product_name'] : 'Print' ) );
			if ( $name === '' ) $name = 'Print';
			$qty  = max( 1, (int) ( isset( $it['qty'] ) ? $it['qty'] : 1 ) );
			if ( ! isset( $counts[ $name ] ) ) $counts[ $name ] = 0;
			$counts[ $name ] += $qty;
		}
		$parts = array();
		foreach ( $counts as $name => $qty ) {
			$parts[] = $name . ' ×' . $qty;
		}
		return implode( ' · ', $parts );
	}

	/**
	 * What a provider is allowed to see about a job. Deliberately excludes
	 * the customer's name, email, phone and every price.
	 */
	private static function order_payload( $order, $provider, $detail = false ) {
		$f     = self::fulfillment( $order );
		$items = self::order_items( $order );

		$pieces = 0;
		foreach ( $items as $it ) { $pieces += max( 1, (int) ( isset( $it['qty'] ) ? $it['qty'] : 1 ) ); }

		$build     = is_array( $f['build'] ) ? $f['build'] : null;
		$built     = $build && ! empty( $build['done'] );
		$status    = (string) $order->status;
		$closed    = in_array( $status, array( 'completed', 'cancelled' ), true );

		$payload = array(
			'order_ref'     => (string) $order->order_ref,
			'status'        => $status,
			'status_label'  => self::status_label( $status ),
			'sent_at'       => (string) ( $f['assigned_at'] !== '' ? $f['assigned_at'] : $order->created_at ),
			'sent_label'    => date_i18n( 'j M Y', strtotime( (string) ( $f['assigned_at'] !== '' ? $f['assigned_at'] : $order->created_at ) ) ),
			'item_count'    => count( $items ),
			'pieces'        => (int) $pieces,
			'sizes'         => self::size_summary( $items ),
			'delivered_by'  => ! empty( $provider['does_delivery'] ) ? 'Provider' : 'Studio',
			'does_delivery' => (int) ! empty( $provider['does_delivery'] ),
			'started_at'    => (string) $f['started_at'],
			'ready_at'      => (string) $f['ready_at'],
			'shipped_at'    => (string) $f['shipped_at'],
			'delivered_at'  => (string) $f['delivered_at'],
			'built'         => $built ? 1 : 0,
			'closed'        => $closed ? 1 : 0,
			'can_start'     => ( ! $closed && $status !== 'printing' && $status !== 'ready' ) ? 1 : 0,
			'can_ready'     => ( ! $closed && $status !== 'ready' ) ? 1 : 0,
			'can_ship'      => ( ! $closed && ! empty( $provider['does_delivery'] ) && (string) $f['shipped_at'] === '' ) ? 1 : 0,
		);

		if ( ! $detail ) return $payload;

		$rows = array();
		foreach ( $items as $i => $it ) {
			$rows[] = array(
				'seq'   => $i + 1,
				'name'  => (string) ( isset( $it['product_name'] ) ? $it['product_name'] : 'Print' ),
				'qty'   => max( 1, (int) ( isset( $it['qty'] ) ? $it['qty'] : 1 ) ),
				'file'  => (string) ( isset( $it['filename'] ) ? $it['filename'] : '' ),
				'thumb' => esc_url_raw( (string) ( isset( $it['thumb_url'] ) && $it['thumb_url'] !== '' ? $it['thumb_url'] : ( isset( $it['photo_url'] ) ? $it['photo_url'] : '' ) ) ),
			);
		}

		$payload['items']    = $rows;
		$payload['zip_url']  = $built ? self::zip_url( $order ) : '';
		// The label carries the customer's address — only the party who is
		// actually delivering ever gets to see it.
		$payload['label_url'] = ! empty( $provider['does_delivery'] ) ? self::label_url( $order ) : '';
		$payload['notes']     = sanitize_textarea_field( (string) $f['delivery_notes'] );

		$notes = array();
		foreach ( (array) $f['sends'] as $send ) {
			if ( ! is_array( $send ) ) continue;
			if ( sanitize_key( (string) ( isset( $send['provider_id'] ) ? $send['provider_id'] : '' ) ) !== $provider['id'] ) continue;
			$note = trim( (string) ( isset( $send['note'] ) ? $send['note'] : '' ) );
			if ( $note !== '' ) $notes[] = $note;
		}
		$payload['studio_notes'] = $notes;

		return $payload;
	}
}
