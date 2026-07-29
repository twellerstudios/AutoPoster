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

	const VERSION = '1.1.0';

	const OPT_VERSION   = 'tweller_print_providers_version';
	const OPT_PROVIDERS = 'tweller_print_providers';
	const OPT_PAGE      = 'tweller_flow_2_provider_page';
	const OPT_APPLY_PAGE = 'tweller_flow_2_provider_apply_page';

	const ROLE = 'tweller_print_provider';
	const CAP  = 'tweller_view_print_jobs';

	const ADMIN_PAGE          = 'tweller-flow-2-provider-accounts';
	const ADMIN_PAGE_REQUESTS = 'tweller-flow-2-provider-requests';
	const REST_NS    = 'tweller-flow-2/v1';

	const PAGE_SLUG  = 'print-provider';
	const SHORTCODE  = 'tweller_print_provider';

	/** Public "become a print partner" application. */
	const APPLY_PAGE_SLUG = 'print-partner-application';
	const APPLY_SHORTCODE = 'tweller_provider_apply';
	const TABLE_REQUESTS  = 'tweller_provider_requests';

	/** Applications allowed from one IP per hour. */
	const APPLY_RATE_LIMIT = 5;

	/** Anything faster than this is a bot, not a print lab. */
	const APPLY_MIN_SECONDS = 3;

	/** Statuses a provider is allowed to set. Never confirmed/cancelled. */
	const PROVIDER_STATUSES = 'printing,ready';

	// ── Bootstrap ──────────────────────────────────────

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 22 );
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_actions' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );

		// Provider applications ("Become a Print Partner").
		add_shortcode( self::APPLY_SHORTCODE, array( __CLASS__, 'render_apply_shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_application' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_request_actions' ) );

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
		self::create_requests_table();
		self::ensure_apply_page();
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

	/**
	 * Auto-create the public "Become a Print Partner" application page —
	 * same pattern as the provider dashboard page above.
	 */
	public static function ensure_apply_page() {
		$existing_url = get_option( self::OPT_APPLY_PAGE, '' );
		if ( $existing_url ) {
			$page_id = url_to_postid( $existing_url );
			if ( $page_id && get_post_status( $page_id ) === 'publish' ) return;
		}

		$existing = get_posts( array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			's'           => '[' . self::APPLY_SHORTCODE . ']',
			'numberposts' => 1,
		) );
		if ( ! empty( $existing ) ) {
			update_option( self::OPT_APPLY_PAGE, get_permalink( $existing[0]->ID ) );
			return;
		}

		$page_id = wp_insert_post( array(
			'post_title'   => 'Become a Print Partner',
			'post_name'    => self::APPLY_PAGE_SLUG,
			'post_content' => '[' . self::APPLY_SHORTCODE . ']',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		) );

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( self::OPT_APPLY_PAGE, get_permalink( $page_id ) );
		}
	}

	public static function apply_page_url() {
		$url = get_option( self::OPT_APPLY_PAGE, '' );
		return $url ? $url : home_url( '/' . self::APPLY_PAGE_SLUG . '/' );
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
			// What THIS provider charges Tweller Studios (wholesale/cost, TTD)
			// per catalog product_id — completely separate from the
			// customer-facing price in TwellerFlow2_Prints. A product missing
			// from this map is "not priced", never a silent $0.
			'prices'        => self::sanitize_prices( isset( $p['prices'] ) ? $p['prices'] : array() ),
		);
		if ( $row['id'] === '' || $row['name'] === '' ) return null;
		return $row;
	}

	/** product_id => cost (float, >= 0). Blank/invalid entries are dropped, not zeroed. */
	private static function sanitize_prices( $raw ) {
		$out = array();
		if ( ! is_array( $raw ) ) return $out;
		foreach ( $raw as $product_id => $value ) {
			$product_id = sanitize_key( (string) $product_id );
			if ( $product_id === '' ) continue;
			if ( $value === '' || $value === null ) continue; // not priced
			if ( ! is_numeric( $value ) ) continue;
			$out[ $product_id ] = max( 0, round( (float) $value, 2 ) );
		}
		return $out;
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

	/**
	 * The assignment lives in the fulfilment JSON column, which is added by
	 * the fulfilment class. Never query it blind.
	 */
	private static function has_fulfillment_column() {
		static $has = null;
		if ( $has !== null ) return $has;

		global $wpdb;
		$table = self::orders_table();
		if ( ! $table ) { $has = false; return $has; }

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) { $has = false; return $has; }

		$col = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'fulfillment' ) );
		$has = ! empty( $col );
		return $has;
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

		if ( ! self::has_fulfillment_column() ) return array();

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
			// Payout / earnings — completed & delivered orders only, so this
			// reads as real money earned, never a forecast of jobs in flight.
			'jobs_completed'   => 0,
			'earned_month'     => 0.0,
			'earned_all_time'  => 0.0,
			'avg_per_job'      => 0.0,
		);

		$statuses = class_exists( 'TwellerFlow2_Prints' )
			? TwellerFlow2_Prints::get_statuses()
			: array( 'new', 'confirmed', 'printing', 'ready', 'completed', 'cancelled' );
		foreach ( $statuses as $s ) { $stats['by_status'][ $s ] = 0; }

		$month    = self::month_start_ts();
		$provider = self::get_provider( $provider_id );

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
				$eco  = self::order_economics( $order, $provider );

				$stats['jobs_completed']++;
				$stats['earned_all_time'] += $eco['provider_cost'];

				if ( $done && $done >= $month ) {
					$stats['completed_month']++;
					$stats['value_month']  += (float) $order->subtotal;
					$stats['earned_month'] += $eco['provider_cost'];
				}
			} elseif ( $status !== 'cancelled' ) {
				// Sent to them, nothing started yet.
				$stats['awaiting']++;
			}
		}

		$stats['value_month']     = round( $stats['value_month'], 2 );
		$stats['earned_month']    = round( $stats['earned_month'], 2 );
		$stats['earned_all_time'] = round( $stats['earned_all_time'], 2 );
		$stats['avg_per_job']     = $stats['jobs_completed'] > 0
			? round( $stats['earned_all_time'] / $stats['jobs_completed'], 2 )
			: 0.0;
		return $stats;
	}

	/**
	 * What one provider charges Tweller for a single order line item —
	 * product_id match, multiplied by qty. Returns null (not 0.0) when the
	 * provider has not set a price for that product, so callers can flag
	 * "not priced" instead of silently treating it as free.
	 *
	 * @param array $provider Normalised provider record (see normalize_provider()).
	 * @param array $item     One decoded order line item.
	 * @return float|null
	 */
	public static function provider_cost_for_item( $provider, $item ) {
		if ( ! is_array( $provider ) || ! is_array( $item ) ) return null;
		$prices     = isset( $provider['prices'] ) && is_array( $provider['prices'] ) ? $provider['prices'] : array();
		$product_id = sanitize_key( (string) ( isset( $item['product_id'] ) ? $item['product_id'] : '' ) );
		if ( $product_id === '' || ! isset( $prices[ $product_id ] ) ) return null;

		$qty = max( 1, (int) ( isset( $item['qty'] ) ? $item['qty'] : 1 ) );
		return round( (float) $prices[ $product_id ] * $qty, 2 );
	}

	/**
	 * Full economics for one order: what the customer paid, what the
	 * provider is owed, how many line items are unpriced for that provider
	 * (flagged, never silently treated as $0), and the studio's margin.
	 *
	 * @param object     $order
	 * @param array|null $provider Resolved from the order's own assignment when omitted.
	 * @return array{value:float, provider_cost:float, unpriced_items:int, margin:float, provider_id:string}
	 */
	public static function order_economics( $order, $provider = null ) {
		$value = ( $order && isset( $order->subtotal ) ) ? round( (float) $order->subtotal, 2 ) : 0.0;

		if ( $provider === null ) {
			$provider_id = self::order_provider_id( $order );
			$provider    = $provider_id !== '' ? self::get_provider( $provider_id ) : null;
		}

		$out = array(
			'value'          => $value,
			'provider_cost'  => 0.0,
			'unpriced_items' => 0,
			'margin'         => $value,
			'provider_id'    => $provider ? $provider['id'] : '',
		);

		if ( ! $provider || ! $order ) return $out;

		$cost     = 0.0;
		$unpriced = 0;
		foreach ( self::order_items( $order ) as $item ) {
			$line_cost = self::provider_cost_for_item( $provider, $item );
			if ( $line_cost === null ) { $unpriced++; continue; }
			$cost += $line_cost;
		}

		$out['provider_cost']  = round( $cost, 2 );
		$out['unpriced_items'] = $unpriced;
		$out['margin']         = round( $value - $cost, 2 );
		return $out;
	}

	/**
	 * Studio-wide economics rollup across every order ever assigned to a
	 * provider (cancelled orders excluded) — this month and all time, plus
	 * a per-provider breakdown. Powers the wp-admin economics dashboard.
	 *
	 * @return array{month:array,all_time:array,providers:array<int,array>}
	 */
	public static function economics_summary() {
		global $wpdb;

		$empty_totals = array( 'orders' => 0, 'value' => 0.0, 'provider_cost' => 0.0, 'margin' => 0.0 );
		$totals       = $empty_totals;
		$month_totals = $empty_totals;

		$per_provider = array();
		foreach ( self::providers() as $p ) {
			$per_provider[ $p['id'] ] = array(
				'id'           => $p['id'],
				'name'         => $p['name'],
				'month_jobs'   => 0,
				'month_payout' => 0.0,
				'all_jobs'     => 0,
				'all_payout'   => 0.0,
			);
		}

		$table = self::orders_table();
		if ( ! $table || ! self::has_fulfillment_column() ) {
			return array( 'month' => $month_totals, 'all_time' => $totals, 'providers' => array_values( $per_provider ) );
		}

		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` WHERE fulfillment IS NOT NULL AND fulfillment != '' AND status != 'cancelled'" );
		if ( ! is_array( $rows ) ) $rows = array();

		$month_start = self::month_start_ts();

		foreach ( $rows as $row ) {
			$provider_id = self::order_provider_id( $row );
			if ( $provider_id === '' ) continue; // never actually assigned

			$provider = self::get_provider( $provider_id );
			$eco      = self::order_economics( $row, $provider );

			$totals['orders']++;
			$totals['value']         += $eco['value'];
			$totals['provider_cost'] += $eco['provider_cost'];
			$totals['margin']        += $eco['margin'];

			if ( ! isset( $per_provider[ $provider_id ] ) ) {
				$per_provider[ $provider_id ] = array(
					'id'           => $provider_id,
					'name'         => $provider ? $provider['name'] : $provider_id,
					'month_jobs'   => 0,
					'month_payout' => 0.0,
					'all_jobs'     => 0,
					'all_payout'   => 0.0,
				);
			}
			$per_provider[ $provider_id ]['all_jobs']++;
			$per_provider[ $provider_id ]['all_payout'] += $eco['provider_cost'];

			$ts = strtotime( (string) $row->created_at );
			if ( $ts && $ts >= $month_start ) {
				$month_totals['orders']++;
				$month_totals['value']         += $eco['value'];
				$month_totals['provider_cost'] += $eco['provider_cost'];
				$month_totals['margin']        += $eco['margin'];

				$per_provider[ $provider_id ]['month_jobs']++;
				$per_provider[ $provider_id ]['month_payout'] += $eco['provider_cost'];
			}
		}

		foreach ( array( 'value', 'provider_cost', 'margin' ) as $k ) {
			$totals[ $k ]       = round( $totals[ $k ], 2 );
			$month_totals[ $k ] = round( $month_totals[ $k ], 2 );
		}
		foreach ( $per_provider as $id => $row ) {
			$per_provider[ $id ]['month_payout'] = round( $row['month_payout'], 2 );
			$per_provider[ $id ]['all_payout']   = round( $row['all_payout'], 2 );
		}

		return array( 'month' => $month_totals, 'all_time' => $totals, 'providers' => array_values( $per_provider ) );
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

		// "Provider Requests" carries a pending-count bubble, same visual
		// language as the core comment-moderation counter.
		$pending = self::count_requests( 'new' );
		$label   = 'Provider Requests';
		if ( $pending > 0 ) {
			$label .= ' <span class="awaiting-mod update-plugins count-' . (int) $pending . '"><span class="pending-count">'
				. number_format_i18n( $pending ) . '</span></span>';
		}

		add_submenu_page(
			'tweller-flow-2',
			'Provider Requests',
			$label,
			'manage_options',
			self::ADMIN_PAGE_REQUESTS,
			array( __CLASS__, 'page_requests' )
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
			'prices' => array(),
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

		// ── Cost pricing ("what they charge you") ──
		// Only the products actually rendered in the form (active ones) are
		// touched; a price for a since-deactivated product is left alone
		// rather than silently dropped. A blank field clears that price back
		// to "not priced" instead of saving a false $0.
		$prices = isset( $record['prices'] ) && is_array( $record['prices'] ) ? $record['prices'] : array();
		if ( isset( $post['provider_cost'] ) && is_array( $post['provider_cost'] ) ) {
			foreach ( $post['provider_cost'] as $product_id => $value ) {
				$product_id = sanitize_key( (string) $product_id );
				if ( $product_id === '' ) continue;
				$value = trim( (string) $value );
				if ( $value === '' ) {
					unset( $prices[ $product_id ] );
					continue;
				}
				if ( ! is_numeric( $value ) ) continue;
				$prices[ $product_id ] = max( 0, round( (float) $value, 2 ) );
			}
		}
		$record['prices'] = $prices;

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

			// Fall back to the lab's own details so an account can be made
			// with nothing typed: email from the provider record, username
			// derived from the lab name (de-duplicated if taken).
			if ( $email === '' ) $email = sanitize_email( (string) $record['email'] );
			if ( $fname === '' ) $fname = (string) ( $record['contact_name'] !== '' ? $record['contact_name'] : $name );
			if ( $login === '' ) {
				$base = sanitize_user( sanitize_title( $name ), true );
				if ( $base === '' && $email !== '' ) {
					$base = sanitize_user( substr( $email, 0, strpos( $email, '@' ) ), true );
				}
				if ( $base === '' ) $base = 'provider';
				$login = $base;
				$n = 2;
				while ( username_exists( $login ) ) { $login = $base . $n; $n++; }
			}

			if ( $login === '' || ! is_email( $email ) ) {
				$error = 'Add an email address for this lab (or type one) so an account can be created.';
			} elseif ( username_exists( $login ) ) {
				$error = 'That username is already taken.';
			} elseif ( email_exists( $email ) ) {
				$error = 'That email address already belongs to a WordPress user — link the existing account instead.';
			} else {
				// A strong per-provider password (never a shared default) is
				// generated here, shown to the admin once, and also mailed
				// to the lab as a set-password link by WordPress.
				$pass   = wp_generate_password( 16, true, false );
				$new_id = wp_insert_user( array(
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => $pass,
					'display_name' => $fname !== '' ? $fname : $name,
					'first_name'   => $fname,
					'role'         => self::ROLE,
				) );

				if ( is_wp_error( $new_id ) ) {
					$error = $new_id->get_error_message();
				} else {
					$record['user_id'] = (int) $new_id;
					wp_send_new_user_notifications( (int) $new_id, 'both' );

					// Show the credentials to the admin once, so a lab that
					// never checks the WordPress email can still be given a
					// working login by hand. Stored for a single page load
					// only — never written into the provider record.
					set_transient( 'tf2pv_new_login_' . get_current_user_id(), array(
						'login' => $login,
						'pass'  => $pass,
						'email' => $email,
					), 60 );
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

		// Credentials for a just-created account, shown once.
		self::render_new_login_notice();

		$pending = self::count_requests( 'new' );
		if ( $pending > 0 ) {
			echo '<div class="notice notice-info"><p>'
				. esc_html( sprintf( _n( '%d print lab is waiting to be reviewed.', '%d print labs are waiting to be reviewed.', $pending, 'tweller-bookings' ), $pending ) )
				. ' <a href="' . esc_url( self::requests_url() ) . '">Open Provider Requests</a></p></div>';
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
			'prices' => array(),
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

		self::render_pricing_table( $p );

		submit_button( $editing ? 'Save provider' : 'Add provider' );
		if ( $editing ) {
			echo '<a href="' . esc_url( self::admin_url_for() ) . '" class="button">Cancel</a>';
		}
		echo '</form>';
	}

	/**
	 * "What they charge you" — one TTD cost input per active catalog
	 * product, rides along with the same form/nonce/handler as the rest of
	 * the provider record. Completely separate from the customer-facing
	 * price in TwellerFlow2_Prints. A blank input means "not priced", not
	 * "free" — save leaves it out of the provider's price map.
	 */
	private static function render_pricing_table( $p ) {
		if ( ! class_exists( 'TwellerFlow2_Prints' ) || ! method_exists( 'TwellerFlow2_Prints', 'get_active_products' ) ) return;

		$products = TwellerFlow2_Prints::get_active_products();
		if ( empty( $products ) ) return;

		$prices = isset( $p['prices'] ) && is_array( $p['prices'] ) ? $p['prices'] : array();

		echo '<h3 style="margin:28px 0 4px;">What they charge you</h3>';
		echo '<p class="description" style="max-width:640px; margin:0 0 12px;">Your cost per item from this lab, in TTD — separate from what customers pay. Leave a field blank when this lab does not print that product; a provider never sees a wrong TT$0, they see a "not priced" flag instead.</p>';

		echo '<table class="widefat striped" style="max-width:560px;"><thead><tr><th>Product</th><th style="width:150px;">Your cost (TT$)</th></tr></thead><tbody>';
		foreach ( $products as $product ) {
			$product_id = sanitize_key( (string) ( isset( $product['id'] ) ? $product['id'] : '' ) );
			if ( $product_id === '' ) continue;
			$name  = (string) ( isset( $product['name'] ) ? $product['name'] : $product_id );
			$value = isset( $prices[ $product_id ] ) ? number_format( (float) $prices[ $product_id ], 2, '.', '' ) : '';

			echo '<tr><td>' . esc_html( $name ) . '</td><td>'
				. '<input type="number" step="0.01" min="0" inputmode="decimal" placeholder="Not priced" style="width:120px;" '
				. 'name="provider_cost[' . esc_attr( $product_id ) . ']" value="' . esc_attr( $value ) . '"></td></tr>';
		}
		echo '</tbody></table>';
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
		wp_register_style(
			'tweller-flow-2-provider-apply',
			TWELLER_FLOW_2_PLUGIN_URL . 'public/css/provider-apply.css',
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
				<p class="tfpv__hint">Point at the QR code on the shipping label — it closes the job automatically. If the live view won't lock on, use Capture &amp; scan or a photo instead.</p>
				<div class="tfpv__scan-row">
					<input type="text" id="tfpv-scan-input" class="tfpv__input" placeholder="TS-PRINT-XXXXXX" autocomplete="off" spellcheck="false">
					<button type="button" class="tfpv__btn tfpv__btn--gold" id="tfpv-scan-go">Verify</button>
					<button type="button" class="tfpv__btn tfpv__btn--ghost" id="tfpv-scan-cam" hidden>Use camera</button>
				</div>
				<video id="tfpv-scan-video" class="tfpv__video" playsinline hidden></video>
				<div class="tfpv__scan-row" id="tfpv-scan-camrow" hidden>
					<button type="button" class="tfpv__btn tfpv__btn--dark" id="tfpv-scan-shot">Capture &amp; scan</button>
					<button type="button" class="tfpv__btn tfpv__btn--ghost" id="tfpv-scan-photo-btn">Use a photo instead</button>
					<input type="file" id="tfpv-scan-photo" accept="image/*" capture="environment" hidden>
				</div>
				<p class="tfpv__hint" id="tfpv-scan-state" aria-live="polite"></p>
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
				<p class="tfpv__card-foot">Not a partner yet? <a href="<?php echo esc_url( self::apply_page_url() ); ?>">Apply to print for Tweller Studios &rarr;</a></p>
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
		
		if ( count( $parts ) === 1 ) { $parts[] = ''; } // bare order ref is fine: ownership is already enforced
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
		if ( $hmac !== '' && ! hash_equals( TwellerFlow2_Print_Fulfillment::delivery_token( $order->order_ref ), $hmac ) ) {
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
			// Studio-wide cost/payout/margin rollup — see economics_summary().
			'economics'  => self::economics_summary(),
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
			// Who WILL deliver — deliberately not phrased as "delivered by",
			// which read as though a freshly-sent job was already delivered.
			'delivery_by'   => ! empty( $provider['does_delivery'] ) ? 'You' : 'Tweller Studios',
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

		// Payout line, Printful/Printify style: the order's gross value and
		// what THIS provider earns on it. The studio's margin never appears
		// here — order_economics() computes it, we simply never read it out.
		$eco                        = self::order_economics( $order, $provider );
		$payload['value']           = $eco['value'];
		$payload['your_earnings']   = $eco['provider_cost'];
		$payload['unpriced_count']  = $eco['unpriced_items'];

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

	// ══════════════════════════════════════════════════════
	// PROVIDER REQUESTS — a print lab applies, the studio
	// reviews, approval provisions the account automatically.
	// ══════════════════════════════════════════════════════

	/** @var array<string,string> Field => message, for the public form. */
	private static $apply_errors = array();

	/** @var array<string,mixed> Re-populate the form after a failed submit. */
	private static $apply_values = array();

	// ── Storage ────────────────────────────────────────

	public static function requests_table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_REQUESTS;
	}

	/** @var bool|null Memoised table check, reset after a dbDelta run. */
	private static $requests_table_ready = null;

	private static function requests_table_exists() {
		if ( self::$requests_table_ready !== null ) return self::$requests_table_ready;

		global $wpdb;
		$table = self::requests_table();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		self::$requests_table_ready = ( $found === $table );
		return self::$requests_table_ready;
	}

	public static function create_requests_table() {
		global $wpdb;

		$table   = self::requests_table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status varchar(20) NOT NULL DEFAULT 'new',
			business_name varchar(190) NOT NULL DEFAULT '',
			contact_name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			website varchar(255) NOT NULL DEFAULT '',
			instagram varchar(190) NOT NULL DEFAULT '',
			facebook varchar(190) NOT NULL DEFAULT '',
			social_other varchar(190) NOT NULL DEFAULT '',
			address text NULL,
			country varchar(120) NOT NULL DEFAULT '',
			years_in_business smallint(5) unsigned NOT NULL DEFAULT 0,
			services text NULL,
			turnaround varchar(160) NOT NULL DEFAULT '',
			capacity varchar(160) NOT NULL DEFAULT '',
			sample_url varchar(255) NOT NULL DEFAULT '',
			equipment text NULL,
			papers varchar(255) NOT NULL DEFAULT '',
			business_reg varchar(120) NOT NULL DEFAULT '',
			color_managed tinyint(1) NOT NULL DEFAULT 0,
			does_delivery tinyint(1) NOT NULL DEFAULT 0,
			referral varchar(190) NOT NULL DEFAULT '',
			message text NULL,
			ip varchar(100) NOT NULL DEFAULT '',
			provider_id varchar(64) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			reviewed_at datetime NULL,
			reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			review_notes text NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY email (email),
			KEY created_at (created_at)
		) {$charset};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );

		self::$requests_table_ready = null; // re-check on next use
	}

	/** Services a lab can tick on the application form. */
	public static function service_options() {
		return array(
			'photo_prints' => 'Photo prints',
			'canvas'       => 'Canvas',
			'framing'      => 'Framing',
			'albums'       => 'Albums / photobooks',
			'large_format' => 'Large format',
			'delivery'     => 'Delivery',
		);
	}

	public static function request_statuses() {
		return array(
			'new'      => 'Pending',
			'approved' => 'Approved',
			'rejected' => 'Declined',
		);
	}

	private static function services_label( $csv ) {
		$opts  = self::service_options();
		$out   = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $csv ) ) ) as $key ) {
			$out[] = isset( $opts[ $key ] ) ? $opts[ $key ] : $key;
		}
		return implode( ', ', $out );
	}

	public static function get_request( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 || ! self::requests_table_exists() ) return null;

		$table = self::requests_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ) );
		return $row ? $row : null;
	}

	/**
	 * @param string $status '' for every status.
	 * @return array<int, object>
	 */
	public static function get_requests( $status = '', $limit = 200, $offset = 0 ) {
		global $wpdb;
		if ( ! self::requests_table_exists() ) return array();

		$table  = self::requests_table();
		$limit    = max( 1, min( 500, (int) $limit ) );
		$offset   = max( 0, (int) $offset );
		$status   = sanitize_key( (string) $status );
		$statuses = self::request_statuses();

		if ( $status !== '' && isset( $statuses[ $status ] ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$status, $limit, $offset
			) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$limit, $offset
			) );
		}
		return is_array( $rows ) ? $rows : array();
	}

	public static function count_requests( $status = '' ) {
		global $wpdb;
		if ( ! self::requests_table_exists() ) return 0;

		$table    = self::requests_table();
		$status   = sanitize_key( (string) $status );
		$statuses = self::request_statuses();

		if ( $status !== '' && isset( $statuses[ $status ] ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE status = %s", $status ) );
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	// ── Public application form ────────────────────────

	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( $ip, 0, 100 );
	}

	private static function rate_limit_key() {
		return 'tf2pa_rl_' . md5( self::client_ip() );
	}

	private static function rate_limited() {
		$hits = (int) get_transient( self::rate_limit_key() );
		return $hits >= self::APPLY_RATE_LIMIT;
	}

	private static function bump_rate_limit() {
		$key  = self::rate_limit_key();
		$hits = (int) get_transient( $key );
		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Handle the public application POST before anything is rendered, so a
	 * successful submit can redirect (post/redirect/get — no double sends).
	 * On failure we fall through and the shortcode re-renders with errors.
	 */
	public static function maybe_handle_application() {
		if ( is_admin() ) return;
		if ( empty( $_POST['tfpa_action'] ) ) return;
		if ( sanitize_key( wp_unslash( $_POST['tfpa_action'] ) ) !== 'apply' ) return;

		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked immediately below
		$errors = array();

		// ── Nonce ──
		$nonce = isset( $post['tfpa_nonce'] ) ? (string) $post['tfpa_nonce'] : '';
		if ( ! wp_verify_nonce( $nonce, 'tfpa_apply' ) ) {
			$errors['_'] = 'This page was open for a while and the security token expired. Please send the form again.';
		}

		// ── Honeypot + minimum fill time (silent bot filters) ──
		if ( ! empty( $post['tfpa_company_url'] ) ) {
			// A bot filled the hidden field. Pretend it worked.
			wp_safe_redirect( add_query_arg( 'tfpa', 'ok', self::apply_page_url() ) );
			exit;
		}
		$started = isset( $post['tfpa_t'] ) ? (int) $post['tfpa_t'] : 0;
		if ( $started > 0 && ( time() - $started ) < self::APPLY_MIN_SECONDS ) {
			wp_safe_redirect( add_query_arg( 'tfpa', 'ok', self::apply_page_url() ) );
			exit;
		}

		// ── Rate limit ──
		if ( empty( $errors ) && self::rate_limited() ) {
			$errors['_'] = 'We have received several applications from this connection already. Please try again in an hour, or email ' . self::studio_email() . '.';
		}

		$values = self::sanitize_application( $post );

		// ── Required fields ──
		if ( $values['business_name'] === '' ) {
			$errors['business_name'] = 'Please tell us the name of your business.';
		}
		if ( $values['email'] === '' ) {
			$errors['email'] = 'An email address is required — it is how we reach you.';
		} elseif ( ! is_email( $values['email'] ) ) {
			$errors['email'] = 'That email address does not look right.';
		}
		if ( $values['website'] !== '' && ! self::looks_like_url( $values['website'] ) ) {
			$errors['website'] = 'Please give a full web address, starting with https://';
		}
		if ( $values['sample_url'] !== '' && ! self::looks_like_url( $values['sample_url'] ) ) {
			$errors['sample_url'] = 'Please give a full link, starting with https://';
		}

		// ── Already applied? ──
		if ( empty( $errors ) && $values['email'] !== '' && self::has_pending_application( $values['email'] ) ) {
			$errors['_'] = 'We already have an application from this email address and it is being reviewed. We will be in touch shortly.';
		}

		if ( ! empty( $errors ) ) {
			self::$apply_errors = $errors;
			self::$apply_values = $values;
			return;
		}

		$id = self::insert_request( $values );
		self::bump_rate_limit();

		if ( ! $id ) {
			self::$apply_errors = array( '_' => 'Something went wrong saving your application. Please try again, or email ' . self::studio_email() . '.' );
			self::$apply_values = $values;
			return;
		}

		$row = self::get_request( $id );
		if ( $row ) {
			self::send_application_received_email( $row );
			self::send_studio_application_email( $row );
		}

		wp_safe_redirect( add_query_arg( 'tfpa', 'ok', self::apply_page_url() ) );
		exit;
	}

	private static function studio_email() {
		if ( class_exists( 'TwellerFlow2_Notifications' ) ) {
			return TwellerFlow2_Notifications::STUDIO_EMAIL;
		}
		return get_option( 'admin_email', '' );
	}

	private static function looks_like_url( $url ) {
		$url = trim( (string) $url );
		if ( $url === '' ) return false;
		if ( ! preg_match( '#^https?://#i', $url ) ) return false;
		return (bool) filter_var( $url, FILTER_VALIDATE_URL );
	}

	/** Prefix a bare handle with @, strip a pasted profile URL down to it. */
	private static function clean_handle( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( $value === '' ) return '';
		if ( preg_match( '#^https?://#i', $value ) ) {
			$path = trim( (string) wp_parse_url( $value, PHP_URL_PATH ), '/' );
			if ( $path !== '' ) $value = $path;
		}
		$value = ltrim( $value, '@' );
		return substr( $value, 0, 180 );
	}

	private static function sanitize_application( $post ) {
		$get = function( $key ) use ( $post ) {
			return isset( $post[ $key ] ) ? (string) $post[ $key ] : '';
		};

		$services = array();
		if ( isset( $post['services'] ) && is_array( $post['services'] ) ) {
			$allowed = array_keys( self::service_options() );
			foreach ( $post['services'] as $s ) {
				$s = sanitize_key( (string) $s );
				if ( in_array( $s, $allowed, true ) && ! in_array( $s, $services, true ) ) $services[] = $s;
			}
		}

		return array(
			'business_name'     => substr( sanitize_text_field( $get( 'business_name' ) ), 0, 180 ),
			'contact_name'      => substr( sanitize_text_field( $get( 'contact_name' ) ), 0, 180 ),
			'email'             => sanitize_email( $get( 'email' ) ),
			'phone'             => substr( sanitize_text_field( $get( 'phone' ) ), 0, 55 ),
			'website'           => esc_url_raw( trim( $get( 'website' ) ) ),
			'instagram'         => self::clean_handle( $get( 'instagram' ) ),
			'facebook'          => self::clean_handle( $get( 'facebook' ) ),
			'social_other'      => substr( sanitize_text_field( $get( 'social_other' ) ), 0, 180 ),
			'address'           => sanitize_textarea_field( $get( 'address' ) ),
			'country'           => substr( sanitize_text_field( $get( 'country' ) ), 0, 110 ),
			'years_in_business' => max( 0, min( 200, (int) $get( 'years_in_business' ) ) ),
			'services'          => implode( ',', $services ),
			'turnaround'        => substr( sanitize_text_field( $get( 'turnaround' ) ), 0, 150 ),
			'capacity'          => substr( sanitize_text_field( $get( 'capacity' ) ), 0, 150 ),
			'sample_url'        => esc_url_raw( trim( $get( 'sample_url' ) ) ),
			'equipment'         => sanitize_textarea_field( $get( 'equipment' ) ),
			'papers'            => substr( sanitize_text_field( $get( 'papers' ) ), 0, 240 ),
			'business_reg'      => substr( sanitize_text_field( $get( 'business_reg' ) ), 0, 110 ),
			'color_managed'     => empty( $post['color_managed'] ) ? 0 : 1,
			'does_delivery'     => empty( $post['does_delivery'] ) ? 0 : 1,
			'referral'          => substr( sanitize_text_field( $get( 'referral' ) ), 0, 180 ),
			'message'           => sanitize_textarea_field( $get( 'message' ) ),
		);
	}

	private static function has_pending_application( $email ) {
		global $wpdb;
		if ( ! self::requests_table_exists() ) return false;

		$table = self::requests_table();
		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM `{$table}` WHERE email = %s AND status = %s LIMIT 1",
			sanitize_email( (string) $email ),
			'new'
		) );
		return ! empty( $found );
	}

	private static function insert_request( $values ) {
		global $wpdb;
		if ( ! self::requests_table_exists() ) {
			self::create_requests_table();
			if ( ! self::requests_table_exists() ) return 0;
		}

		$data = array_merge( $values, array(
			'status'     => 'new',
			'ip'         => self::client_ip(),
			'created_at' => current_time( 'mysql' ),
		) );

		$formats = array();
		foreach ( $data as $key => $value ) {
			$formats[] = in_array( $key, array( 'years_in_business', 'color_managed', 'does_delivery' ), true ) ? '%d' : '%s';
		}

		$ok = $wpdb->insert( self::requests_table(), $data, $formats );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	// ── Shortcode: [tweller_provider_apply] ────────────

	public static function render_apply_shortcode( $atts ) {
		unset( $atts );
		wp_enqueue_style( 'tweller-flow-2-provider-apply' );

		$submitted = isset( $_GET['tfpa'] ) && sanitize_key( wp_unslash( $_GET['tfpa'] ) ) === 'ok';
		if ( $submitted && empty( self::$apply_errors ) ) {
			return self::render_apply_success();
		}
		return self::render_apply_form();
	}

	private static function render_apply_success() {
		ob_start();
		?>
		<div class="tfpa tfpa--centered">
			<div class="tfpa__card tfpa__card--done">
				<div class="tfpa__brand">
					<span class="tfpa__brand-name">TWELLER</span>
					<span class="tfpa__brand-sub">Studios &middot; Print Partners</span>
				</div>
				<div class="tfpa__tick" aria-hidden="true">&#10003;</div>
				<h1 class="tfpa__done-title">Application received</h1>
				<p class="tfpa__done-body">Thank you for putting your lab forward. We read every application by hand, so give us a few days &mdash; you will hear from us by email either way.</p>
				<p class="tfpa__done-body tfpa__done-body--muted">If your application is approved we will set up your partner dashboard and send you a sign-in link.</p>
				<p class="tfpa__done-foot"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Back to twellerstudios.com</a></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function apply_value( $key, $default = '' ) {
		if ( isset( self::$apply_values[ $key ] ) ) return self::$apply_values[ $key ];
		return $default;
	}

	private static function apply_error( $key ) {
		return isset( self::$apply_errors[ $key ] ) ? self::$apply_errors[ $key ] : '';
	}

	private static function apply_error_html( $key ) {
		$err = self::apply_error( $key );
		if ( $err === '' ) return '';
		return '<span class="tfpa__err" role="alert">' . esc_html( $err ) . '</span>';
	}

	private static function apply_invalid_class( $key ) {
		return self::apply_error( $key ) !== '' ? ' tfpa__input--invalid' : '';
	}

	private static function render_apply_form() {
		$selected_services = array_filter( array_map( 'trim', explode( ',', (string) self::apply_value( 'services', '' ) ) ) );

		ob_start();
		?>
		<div class="tfpa">
			<header class="tfpa__top">
				<div class="tfpa__brand">
					<span class="tfpa__brand-name">TWELLER</span>
					<span class="tfpa__brand-sub">Studios &middot; Print Partners</span>
				</div>
			</header>

			<div class="tfpa__hero">
				<h1 class="tfpa__title">Become a print partner</h1>
				<p class="tfpa__lede">We hand our clients&rsquo; finished work to a small number of trusted labs in Trinidad &amp; Tobago. If you print, frame, mount or bind to a professional standard, tell us about your shop.</p>
				<ul class="tfpa__points">
					<li>Print-ready files, colour-managed, delivered as one package per job</li>
					<li>Your own dashboard &mdash; jobs, files, delivery labels, barcode sign-off</li>
					<li>We never share your pricing, and you never see our client&rsquo;s details</li>
				</ul>
			</div>

			<?php if ( self::apply_error( '_' ) !== '' ) : ?>
				<div class="tfpa__alert" role="alert"><?php echo esc_html( self::apply_error( '_' ) ); ?></div>
			<?php elseif ( ! empty( self::$apply_errors ) ) : ?>
				<div class="tfpa__alert" role="alert">Please check the highlighted fields below.</div>
			<?php endif; ?>

			<form class="tfpa__form" method="post" action="<?php echo esc_url( self::apply_page_url() ); ?>" novalidate>
				<?php wp_nonce_field( 'tfpa_apply', 'tfpa_nonce' ); ?>
				<input type="hidden" name="tfpa_action" value="apply">
				<input type="hidden" name="tfpa_t" value="<?php echo esc_attr( time() ); ?>">

				<div class="tfpa__hp" aria-hidden="true">
					<label>Company URL<input type="text" name="tfpa_company_url" tabindex="-1" autocomplete="off" value=""></label>
				</div>

				<section class="tfpa__section">
					<h2 class="tfpa__h2">Your business</h2>

					<label class="tfpa__field">
						<span class="tfpa__label">Business name <span class="tfpa__req">*</span></span>
						<input type="text" name="business_name" class="tfpa__input<?php echo esc_attr( self::apply_invalid_class( 'business_name' ) ); ?>" required
							value="<?php echo esc_attr( self::apply_value( 'business_name' ) ); ?>" autocomplete="organization">
						<?php echo self::apply_error_html( 'business_name' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper ?>
					</label>

					<div class="tfpa__grid">
						<label class="tfpa__field">
							<span class="tfpa__label">Contact person</span>
							<input type="text" name="contact_name" class="tfpa__input" value="<?php echo esc_attr( self::apply_value( 'contact_name' ) ); ?>" autocomplete="name">
						</label>

						<label class="tfpa__field">
							<span class="tfpa__label">Email <span class="tfpa__req">*</span></span>
							<input type="email" name="email" class="tfpa__input<?php echo esc_attr( self::apply_invalid_class( 'email' ) ); ?>" required
								value="<?php echo esc_attr( self::apply_value( 'email' ) ); ?>" autocomplete="email">
							<?php echo self::apply_error_html( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper ?>
						</label>
					</div>

					<div class="tfpa__grid">
						<label class="tfpa__field">
							<span class="tfpa__label">Phone / WhatsApp</span>
							<input type="tel" name="phone" class="tfpa__input" value="<?php echo esc_attr( self::apply_value( 'phone' ) ); ?>" autocomplete="tel" placeholder="+1 868 000 0000">
						</label>

						<label class="tfpa__field">
							<span class="tfpa__label">Website</span>
							<input type="url" name="website" class="tfpa__input<?php echo esc_attr( self::apply_invalid_class( 'website' ) ); ?>"
								value="<?php echo esc_attr( self::apply_value( 'website' ) ); ?>" placeholder="https://">
							<?php echo self::apply_error_html( 'website' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper ?>
						</label>
					</div>

					<div class="tfpa__grid tfpa__grid--3">
						<label class="tfpa__field">
							<span class="tfpa__label">Instagram</span>
							<input type="text" name="instagram" class="tfpa__input" value="<?php echo esc_attr( self::apply_value( 'instagram' ) ); ?>" placeholder="@yourlab">
						</label>
						<label class="tfpa__field">
							<span class="tfpa__label">Facebook</span>
							<input type="text" name="facebook" class="tfpa__input" value="<?php echo esc_attr( self::apply_value( 'facebook' ) ); ?>" placeholder="yourlab">
						</label>
						<label class="tfpa__field">
							<span class="tfpa__label">Other social</span>
							<input type="text" name="social_other" class="tfpa__input" value="<?php echo esc_attr( self::apply_value( 'social_other' ) ); ?>" placeholder="TikTok, LinkedIn&hellip;">
						</label>
					</div>

					<label class="tfpa__field">
						<span class="tfpa__label">Business address</span>
						<textarea name="address" rows="3" class="tfpa__input tfpa__textarea" placeholder="Street, city"><?php echo esc_textarea( self::apply_value( 'address' ) ); ?></textarea>
					</label>

					<div class="tfpa__grid tfpa__grid--3">
						<label class="tfpa__field">
							<span class="tfpa__label">Country / region</span>
							<input type="text" name="country" class="tfpa__input" value="<?php echo esc_attr( self::apply_value( 'country', 'Trinidad & Tobago' ) ); ?>">
						</label>
						<label class="tfpa__field">
							<span class="tfpa__label">Years in business</span>
							<input type="number" name="years_in_business" class="tfpa__input" min="0" max="200" inputmode="numeric"
								value="<?php echo esc_attr( self::apply_value( 'years_in_business' ) ); ?>">
						</label>
						<label class="tfpa__field">
							<span class="tfpa__label">Business registration no.</span>
							<input type="text" name="business_reg" class="tfpa__input" value="<?php echo esc_attr( self::apply_value( 'business_reg' ) ); ?>" placeholder="Optional">
						</label>
					</div>
				</section>

				<section class="tfpa__section">
					<h2 class="tfpa__h2">What you produce</h2>

					<fieldset class="tfpa__field tfpa__fieldset">
						<legend class="tfpa__label">Services offered</legend>
						<div class="tfpa__checks">
							<?php foreach ( self::service_options() as $key => $label ) : ?>
								<label class="tfpa__check">
									<input type="checkbox" name="services[]" value="<?php echo esc_attr( $key ); ?>"
										<?php checked( in_array( $key, $selected_services, true ) ); ?>>
									<span><?php echo esc_html( $label ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</fieldset>

					<div class="tfpa__grid">
						<label class="tfpa__field">
							<span class="tfpa__label">Typical turnaround</span>
							<input type="text" name="turnaround" class="tfpa__input" value="<?php echo esc_attr( self::apply_value( 'turnaround' ) ); ?>" placeholder="e.g. 3&ndash;5 working days">
						</label>
						<label class="tfpa__field">
							<span class="tfpa__label">Capacity</span>
							<input type="text" name="capacity" class="tfpa__input" value="<?php echo esc_attr( self::apply_value( 'capacity' ) ); ?>" placeholder="e.g. 500 prints a week">
						</label>
					</div>

					<label class="tfpa__field">
						<span class="tfpa__label">Papers &amp; finishes</span>
						<input type="text" name="papers" class="tfpa__input" value="<?php echo esc_attr( self::apply_value( 'papers' ) ); ?>" placeholder="Lustre, metallic, fine art rag, matte laminate&hellip;">
					</label>

					<label class="tfpa__field">
						<span class="tfpa__label">Sample of your work</span>
						<input type="url" name="sample_url" class="tfpa__input<?php echo esc_attr( self::apply_invalid_class( 'sample_url' ) ); ?>"
							value="<?php echo esc_attr( self::apply_value( 'sample_url' ) ); ?>" placeholder="https:// a portfolio, Drive folder or gallery">
						<?php echo self::apply_error_html( 'sample_url' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper ?>
					</label>

					<label class="tfpa__field">
						<span class="tfpa__label">Equipment &amp; lab notes</span>
						<textarea name="equipment" rows="4" class="tfpa__input tfpa__textarea" placeholder="Printers, mounting and framing kit, in-house vs outsourced work"><?php echo esc_textarea( self::apply_value( 'equipment' ) ); ?></textarea>
					</label>

					<label class="tfpa__check tfpa__check--row">
						<input type="checkbox" name="color_managed" value="1" <?php checked( (int) self::apply_value( 'color_managed', 0 ), 1 ); ?>>
						<span>We are colour managed &mdash; calibrated displays and ICC profiles for our papers</span>
					</label>

					<label class="tfpa__check tfpa__check--row">
						<input type="checkbox" name="does_delivery" value="1" <?php checked( (int) self::apply_value( 'does_delivery', 0 ), 1 ); ?>>
						<span>We can deliver finished orders straight to the customer</span>
					</label>
				</section>

				<section class="tfpa__section">
					<h2 class="tfpa__h2">Anything else</h2>

					<label class="tfpa__field">
						<span class="tfpa__label">Tell us about your shop</span>
						<textarea name="message" rows="5" class="tfpa__input tfpa__textarea" placeholder="What you are proud of, who you already print for, why this would be a good fit"><?php echo esc_textarea( self::apply_value( 'message' ) ); ?></textarea>
					</label>

					<label class="tfpa__field">
						<span class="tfpa__label">How did you hear about us?</span>
						<input type="text" name="referral" class="tfpa__input" value="<?php echo esc_attr( self::apply_value( 'referral' ) ); ?>">
					</label>
				</section>

				<div class="tfpa__submit">
					<button type="submit" class="tfpa__btn">Send my application</button>
					<p class="tfpa__fine">We will only use these details to review your application and to contact you about printing for Tweller Studios.</p>
				</div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	// ── Admin: Provider Requests ───────────────────────

	private static function requests_url( $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::ADMIN_PAGE_REQUESTS ), $args ),
			admin_url( 'admin.php' )
		);
	}

	public static function handle_request_actions() {
		if ( ! is_admin() ) return;
		if ( empty( $_POST['tfpa_admin_action'] ) ) return;

		$action = sanitize_key( wp_unslash( $_POST['tfpa_admin_action'] ) );
		if ( ! in_array( $action, array( 'approve', 'approve_link', 'reject', 'delete' ), true ) ) return;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.' ) );
		}
		check_admin_referer( 'tfpa_' . $action );

		$id    = isset( $_POST['request_id'] ) ? (int) $_POST['request_id'] : 0;
		$notes = isset( $_POST['review_notes'] )
			? sanitize_textarea_field( wp_unslash( $_POST['review_notes'] ) )
			: '';

		if ( $action === 'delete' ) {
			global $wpdb;
			if ( self::requests_table_exists() && $id > 0 ) {
				$wpdb->delete( self::requests_table(), array( 'id' => $id ), array( '%d' ) );
			}
			wp_safe_redirect( self::requests_url( array( 'tfpa_msg' => 'deleted' ) ) );
			exit;
		}

		if ( $action === 'reject' ) {
			$error = self::reject_request( $id, $notes );
			wp_safe_redirect( $error === ''
				? self::requests_url( array( 'tfpa_msg' => 'rejected', 'request' => $id ) )
				: self::requests_url( array( 'tfpa_error' => rawurlencode( $error ), 'request' => $id ) ) );
			exit;
		}

		$error = self::approve_request( $id, $notes, ( $action === 'approve_link' ) ? 'link' : 'create' );
		wp_safe_redirect( $error === ''
			? self::requests_url( array( 'tfpa_msg' => 'approved', 'request' => $id ) )
			: self::requests_url( array( 'tfpa_error' => rawurlencode( $error ), 'request' => $id ) ) );
		exit;
	}

	/**
	 * Claim an application for review. The UPDATE is conditional on the row
	 * still being 'new', so two admins pressing Approve at the same moment
	 * cannot both provision an account.
	 *
	 * @return bool true when this call is the one that claimed it.
	 */
	private static function claim_request( $id, $status, $notes ) {
		global $wpdb;
		if ( ! self::requests_table_exists() ) return false;

		$table = self::requests_table();
		$rows  = $wpdb->query( $wpdb->prepare(
			"UPDATE `{$table}` SET status = %s, reviewed_at = %s, reviewed_by = %d, review_notes = %s WHERE id = %d AND status = %s",
			$status,
			current_time( 'mysql' ),
			get_current_user_id(),
			$notes,
			(int) $id,
			'new'
		) );
		return ( (int) $rows === 1 );
	}

	private static function release_request( $id ) {
		global $wpdb;
		if ( ! self::requests_table_exists() ) return;
		$table = self::requests_table();
		$wpdb->query( $wpdb->prepare(
			"UPDATE `{$table}` SET status = %s, reviewed_at = NULL, reviewed_by = 0 WHERE id = %d",
			'new',
			(int) $id
		) );
	}

	/**
	 * Approve an application: provider record + WordPress account + emails.
	 *
	 * @param int    $id
	 * @param string $notes
	 * @param string $mode 'create' (new login) or 'link' (adopt the existing
	 *                     WordPress user that already owns this email).
	 * @return string '' on success, otherwise a human error message.
	 */
	public static function approve_request( $id, $notes = '', $mode = 'create' ) {
		$row = self::get_request( $id );
		if ( ! $row ) return 'That application no longer exists.';
		if ( $row->status !== 'new' ) {
			return 'This application has already been ' . ( $row->status === 'approved' ? 'approved' : 'declined' ) . '.';
		}

		$email    = sanitize_email( (string) $row->email );
		$existing = $email !== '' ? get_user_by( 'email', $email ) : false;

		if ( $mode !== 'link' && $existing ) {
			return 'A WordPress user already exists for ' . $email . '. Use “Approve &amp; link existing account” to connect it instead of creating a second login.';
		}
		if ( $mode === 'link' && ! $existing ) {
			return 'There is no existing WordPress user for ' . $email . ' to link — approve normally to create one.';
		}

		if ( ! self::claim_request( $id, 'approved', $notes ) ) {
			return 'This application was reviewed by someone else a moment ago.';
		}

		// ── Provider record ──
		$providers = self::providers();
		$ids       = array();
		foreach ( $providers as $p ) { $ids[] = $p['id']; }

		$name = (string) $row->business_name;
		if ( $name === '' ) $name = 'Print partner ' . (int) $row->id;

		$provider = array(
			'id'            => self::unique_provider_id( $name, $ids ),
			'name'          => $name,
			'contact_name'  => (string) $row->contact_name,
			'email'         => $email,
			'phone'         => (string) $row->phone,
			'address'       => (string) $row->address,
			'notes'         => self::provider_notes_from_request( $row ),
			'active'        => 1,
			'default'       => 0,
			'user_id'       => 0,
			'does_delivery' => (int) $row->does_delivery ? 1 : 0,
		);

		// ── Account ──
		$login = '';
		$pass  = '';

		if ( $mode === 'link' ) {
			if ( self::user_taken( (int) $existing->ID, $provider['id'] ) ) {
				self::release_request( $id );
				return 'That WordPress user is already linked to another provider.';
			}
			if ( ! user_can( $existing->ID, self::CAP ) ) {
				$existing->add_role( self::ROLE );
			}
			$provider['user_id'] = (int) $existing->ID;
			$login               = $existing->user_login;
		} else {
			$login = self::unique_login_for( $name, $email );
			$pass  = wp_generate_password( 18, true, false );

			$display = (string) $row->contact_name !== '' ? (string) $row->contact_name : $name;
			$new_id  = wp_insert_user( array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $pass,
				'display_name' => $display,
				'first_name'   => (string) $row->contact_name,
				'user_url'     => (string) $row->website,
				'role'         => self::ROLE,
			) );

			if ( is_wp_error( $new_id ) ) {
				self::release_request( $id );
				return 'The provider login could not be created: ' . $new_id->get_error_message();
			}

			$provider['user_id'] = (int) $new_id;

			// WordPress emails the lab a set-password link; the admin also
			// sees the generated password once, below.
			wp_send_new_user_notifications( (int) $new_id, 'both' );

			set_transient( 'tf2pv_new_login_' . get_current_user_id(), array(
				'login' => $login,
				'pass'  => $pass,
				'email' => $email,
			), 5 * MINUTE_IN_SECONDS );
		}

		$providers[] = $provider;
		self::save_providers( $providers );

		self::link_request_record( $id, $provider['id'], (int) $provider['user_id'] );

		$row = self::get_request( $id );
		if ( $row ) {
			self::send_approved_email( $row, $provider, $login );
		}

		return '';
	}

	public static function reject_request( $id, $notes = '' ) {
		$row = self::get_request( $id );
		if ( ! $row ) return 'That application no longer exists.';
		if ( $row->status !== 'new' ) {
			return 'This application has already been ' . ( $row->status === 'approved' ? 'approved' : 'declined' ) . '.';
		}
		if ( ! self::claim_request( $id, 'rejected', $notes ) ) {
			return 'This application was reviewed by someone else a moment ago.';
		}

		$row = self::get_request( $id );
		if ( $row ) self::send_rejected_email( $row, $notes );
		return '';
	}

	private static function link_request_record( $id, $provider_id, $user_id ) {
		global $wpdb;
		if ( ! self::requests_table_exists() ) return;
		$wpdb->update(
			self::requests_table(),
			array( 'provider_id' => (string) $provider_id, 'user_id' => (int) $user_id ),
			array( 'id' => (int) $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/** Username from the business name, de-duplicated. Never a shared value. */
	private static function unique_login_for( $name, $email ) {
		$base = sanitize_user( sanitize_title( $name ), true );
		if ( $base === '' && $email !== '' ) {
			$base = sanitize_user( substr( $email, 0, (int) strpos( $email, '@' ) ), true );
		}
		if ( $base === '' ) $base = 'printpartner';
		$base = substr( $base, 0, 40 );

		$login = $base;
		$n     = 2;
		while ( username_exists( $login ) ) {
			$login = $base . $n;
			$n++;
			if ( $n > 500 ) { $login = $base . wp_generate_password( 5, false, false ); break; }
		}
		return $login;
	}

	/** Everything worth keeping from the application, folded into the notes. */
	private static function provider_notes_from_request( $row ) {
		$bits = array();
		if ( (string) $row->website !== '' )    $bits[] = 'Website: ' . $row->website;
		if ( (string) $row->instagram !== '' )  $bits[] = 'Instagram: @' . $row->instagram;
		if ( (string) $row->facebook !== '' )   $bits[] = 'Facebook: ' . $row->facebook;
		if ( (string) $row->services !== '' )   $bits[] = 'Services: ' . self::services_label( $row->services );
		if ( (string) $row->turnaround !== '' ) $bits[] = 'Turnaround: ' . $row->turnaround;
		if ( (string) $row->capacity !== '' )   $bits[] = 'Capacity: ' . $row->capacity;
		if ( (string) $row->papers !== '' )     $bits[] = 'Papers: ' . $row->papers;
		if ( (int) $row->color_managed )        $bits[] = 'Colour managed: yes';
		if ( (string) $row->equipment !== '' )  $bits[] = 'Equipment: ' . $row->equipment;
		$bits[] = 'Approved from application #' . (int) $row->id . ' on ' . date_i18n( 'j M Y', current_time( 'timestamp' ) );

		return sanitize_textarea_field( implode( "\n", $bits ) );
	}

	// ── Admin page ─────────────────────────────────────

	public static function page_requests() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.' ) );
		}
		if ( ! self::requests_table_exists() ) self::create_requests_table();

		$msg   = isset( $_GET['tfpa_msg'] ) ? sanitize_key( wp_unslash( $_GET['tfpa_msg'] ) ) : '';
		$error = isset( $_GET['tfpa_error'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['tfpa_error'] ) ) ) : '';
		$view  = isset( $_GET['request'] ) ? (int) $_GET['request'] : 0;
		$tab   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'new';

		$messages = array(
			'approved' => 'Application approved. The provider record and dashboard login have been created.',
			'rejected' => 'Application declined and the applicant has been emailed.',
			'deleted'  => 'Application deleted.',
		);

		echo '<div class="wrap"><h1>Provider Requests</h1>';
		echo '<p style="max-width:780px; color:#50575e;">Print labs apply through <a href="' . esc_url( self::apply_page_url() ) . '" target="_blank" rel="noopener">'
			. esc_html( self::apply_page_url() ) . '</a>. Approving an application creates the provider record <em>and</em> its dashboard login in one step.</p>';

		if ( $error !== '' ) {
			echo '<div class="notice notice-error"><p>' . wp_kses( $error, array( 'em' => array(), 'strong' => array() ) ) . '</p></div>';
		}
		if ( $msg !== '' && isset( $messages[ $msg ] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $messages[ $msg ] ) . '</p></div>';
		}

		self::render_new_login_notice();

		if ( $view > 0 ) {
			self::render_request_detail( $view );
			echo '</div>';
			return;
		}

		self::render_request_tabs( $tab );
		self::render_request_list( $tab );
		echo '</div>';
	}

	/** One-time credentials block, shared with the Provider Accounts page. */
	private static function render_new_login_notice() {
		$key   = 'tf2pv_new_login_' . get_current_user_id();
		$fresh = get_transient( $key );
		if ( ! is_array( $fresh ) || empty( $fresh['login'] ) ) return;
		delete_transient( $key );

		echo '<div class="notice notice-success">'
			. '<p style="margin-bottom:6px;"><strong>Provider login created.</strong> '
			. 'WordPress has emailed ' . esc_html( (string) $fresh['email'] ) . ' a set-password link. '
			. 'These credentials are shown once — copy them now if you want to pass them on directly:</p>'
			. '<p style="font-family:ui-monospace,Menlo,Consolas,monospace; background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:10px 12px; display:inline-block;">'
			. 'Username: <strong>' . esc_html( (string) $fresh['login'] ) . '</strong><br>'
			. 'Password: <strong>' . esc_html( (string) $fresh['pass'] ) . '</strong>'
			. '</p>'
			. '<p style="color:#646970; margin-top:6px;">Ask them to change it after their first sign-in.</p>'
			. '</div>';
	}

	private static function render_request_tabs( $current ) {
		// 'all' is not a real status, so count/list fall through to "everything".
		$tabs = array_merge( array( 'all' => 'All' ), self::request_statuses() );

		echo '<ul class="subsubsub" style="margin-bottom:12px;">';
		$i = 0;
		foreach ( $tabs as $key => $label ) {
			$count = self::count_requests( $key );
			$url   = self::requests_url( array( 'status' => $key ) );
			$is    = ( (string) $key === (string) $current );
			echo '<li>' . ( $i ? ' | ' : '' )
				. '<a href="' . esc_url( $url ) . '"' . ( $is ? ' class="current"' : '' ) . '>'
				. esc_html( $label ) . ' <span class="count">(' . (int) $count . ')</span></a></li>';
			$i++;
		}
		echo '</ul><div style="clear:both;"></div>';
	}

	private static function status_pill( $status ) {
		$labels = self::request_statuses();
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( (string) $status );
		$colors = array(
			'new'      => array( '#F8F1D8', '#7A5D00' ),
			'approved' => array( '#E4F5E9', '#0A6B2D' ),
			'rejected' => array( '#FBE9E9', '#8A1F1F' ),
		);
		$c = isset( $colors[ $status ] ) ? $colors[ $status ] : array( '#EEE', '#333' );

		return '<span style="display:inline-block; padding:2px 10px; border-radius:999px; font-size:11.5px; font-weight:700; letter-spacing:0.4px; background:'
			. esc_attr( $c[0] ) . '; color:' . esc_attr( $c[1] ) . ';">' . esc_html( $label ) . '</span>';
	}

	private static function render_request_list( $status ) {
		$rows = self::get_requests( $status );

		if ( empty( $rows ) ) {
			echo '<p>No applications here yet.</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>'
			. '<th>Business</th><th>Contact</th><th>Services</th><th>Delivers</th><th>Received</th><th>Status</th><th></th>'
			. '</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$detail = self::requests_url( array( 'request' => (int) $r->id ) );

			echo '<tr>';
			echo '<td><strong><a href="' . esc_url( $detail ) . '">' . esc_html( $r->business_name ) . '</a></strong>';
			if ( (string) $r->country !== '' ) {
				echo '<br><span style="color:#646970;">' . esc_html( $r->country ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $r->contact_name ) . '<br><span style="color:#646970;">' . esc_html( $r->email ) . '</span></td>';
			echo '<td style="max-width:260px;">' . esc_html( self::services_label( $r->services ) ) . '</td>';
			echo '<td>' . ( (int) $r->does_delivery ? 'Yes' : 'No' ) . '</td>';
			echo '<td>' . esc_html( date_i18n( 'j M Y', strtotime( (string) $r->created_at ) ) ) . '</td>';
			echo '<td>' . wp_kses_post( self::status_pill( (string) $r->status ) ) . '</td>';
			echo '<td><a href="' . esc_url( $detail ) . '" class="button button-small">Review</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function detail_row( $label, $value_html ) {
		if ( $value_html === '' ) return;
		echo '<tr><th scope="row" style="width:210px;">' . esc_html( $label ) . '</th><td>'
			. wp_kses( $value_html, array(
				'a'      => array( 'href' => true, 'target' => true, 'rel' => true ),
				'br'     => array(),
				'strong' => array(),
				'em'     => array(),
				'span'   => array( 'style' => true ),
				'code'   => array(),
			) )
			. '</td></tr>';
	}

	private static function link_html( $url, $label = '' ) {
		$url = esc_url( (string) $url );
		if ( $url === '' ) return '';
		if ( $label === '' ) $label = (string) $url;
		return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>';
	}

	private static function render_request_detail( $id ) {
		$r = self::get_request( $id );
		if ( ! $r ) {
			echo '<div class="notice notice-error"><p>That application could not be found.</p></div>';
			echo '<p><a href="' . esc_url( self::requests_url() ) . '" class="button">Back to all requests</a></p>';
			return;
		}

		$existing_user = ( (string) $r->email !== '' ) ? get_user_by( 'email', (string) $r->email ) : false;

		echo '<p><a href="' . esc_url( self::requests_url() ) . '">&larr; All requests</a></p>';
		echo '<h2 style="margin-bottom:4px;">' . esc_html( $r->business_name ) . ' ' . wp_kses_post( self::status_pill( (string) $r->status ) ) . '</h2>';
		echo '<p style="color:#646970; margin-top:0;">Application #' . (int) $r->id . ' &middot; received '
			. esc_html( date_i18n( 'j M Y, g:i a', strtotime( (string) $r->created_at ) ) ) . '</p>';

		echo '<table class="form-table"><tbody>';

		self::detail_row( 'Business name', esc_html( (string) $r->business_name ) );
		self::detail_row( 'Contact person', esc_html( (string) $r->contact_name ) );
		self::detail_row( 'Email', (string) $r->email !== '' ? '<a href="mailto:' . esc_attr( $r->email ) . '">' . esc_html( $r->email ) . '</a>' : '' );
		self::detail_row( 'Phone', (string) $r->phone !== '' ? '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', (string) $r->phone ) ) . '">' . esc_html( $r->phone ) . '</a>' : '' );
		self::detail_row( 'Website', self::link_html( (string) $r->website ) );
		self::detail_row( 'Instagram', (string) $r->instagram !== '' ? self::link_html( 'https://instagram.com/' . rawurlencode( (string) $r->instagram ), '@' . $r->instagram ) : '' );
		self::detail_row( 'Facebook', (string) $r->facebook !== '' ? self::link_html( 'https://facebook.com/' . rawurlencode( (string) $r->facebook ), (string) $r->facebook ) : '' );
		self::detail_row( 'Other social', esc_html( (string) $r->social_other ) );
		self::detail_row( 'Address', nl2br( esc_html( (string) $r->address ) ) );
		self::detail_row( 'Country / region', esc_html( (string) $r->country ) );
		self::detail_row( 'Years in business', (int) $r->years_in_business > 0 ? esc_html( (string) (int) $r->years_in_business ) : '' );
		self::detail_row( 'Business registration', esc_html( (string) $r->business_reg ) );
		self::detail_row( 'Services offered', esc_html( self::services_label( (string) $r->services ) ) );
		self::detail_row( 'Turnaround', esc_html( (string) $r->turnaround ) );
		self::detail_row( 'Capacity', esc_html( (string) $r->capacity ) );
		self::detail_row( 'Papers & finishes', esc_html( (string) $r->papers ) );
		self::detail_row( 'Sample work', self::link_html( (string) $r->sample_url ) );
		self::detail_row( 'Equipment / lab notes', nl2br( esc_html( (string) $r->equipment ) ) );
		self::detail_row( 'Colour managed', (int) $r->color_managed ? 'Yes' : 'Not stated' );
		self::detail_row( 'Delivers to customers', (int) $r->does_delivery ? 'Yes' : 'No — studio delivers' );
		self::detail_row( 'Message', nl2br( esc_html( (string) $r->message ) ) );
		self::detail_row( 'Heard about us via', esc_html( (string) $r->referral ) );
		self::detail_row( 'Submitted from', esc_html( (string) $r->ip ) );

		if ( $r->status !== 'new' ) {
			$reviewer = (int) $r->reviewed_by ? get_userdata( (int) $r->reviewed_by ) : null;
			self::detail_row( 'Reviewed', esc_html(
				date_i18n( 'j M Y, g:i a', strtotime( (string) $r->reviewed_at ) )
				. ( $reviewer ? ' by ' . $reviewer->display_name : '' )
			) );
			self::detail_row( 'Review notes', nl2br( esc_html( (string) $r->review_notes ) ) );
			if ( (string) $r->provider_id !== '' ) {
				self::detail_row( 'Provider record', '<a href="' . esc_url( self::admin_url_for( array( 'edit' => (string) $r->provider_id ) ) ) . '">' . esc_html( (string) $r->provider_id ) . '</a>' );
			}
		}

		echo '</tbody></table>';

		if ( $r->status !== 'new' ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::ADMIN_PAGE_REQUESTS ) ) . '" style="margin-top:18px;">';
			wp_nonce_field( 'tfpa_delete' );
			echo '<input type="hidden" name="tfpa_admin_action" value="delete">';
			echo '<input type="hidden" name="request_id" value="' . (int) $r->id . '">';
			echo '<button type="submit" class="button" onclick="return confirm(\'Delete this application permanently?\');">Delete application</button>';
			echo '</form>';
			return;
		}

		// ── Decision ──
		echo '<h2 style="margin-top:30px;">Decision</h2>';

		if ( $existing_user ) {
			echo '<div class="notice notice-warning inline" style="margin:0 0 14px; padding:10px 12px;"><p style="margin:0;">'
				. '<strong>' . esc_html( (string) $r->email ) . '</strong> already belongs to the WordPress user <code>'
				. esc_html( $existing_user->user_login ) . '</code>. Approving normally is blocked — link that account instead so the lab keeps one login.'
				. '</p></div>';
		}

		$form_action = esc_url( admin_url( 'admin.php?page=' . self::ADMIN_PAGE_REQUESTS ) );

		// Each decision is its own form so its nonce matches its action.
		$approve_action = $existing_user ? 'approve_link' : 'approve';
		$approve_label  = $existing_user ? 'Approve &amp; link existing account' : 'Approve &amp; create account';
		$approve_confirm = $existing_user
			? 'Approve this lab and link the existing WordPress account?'
			: 'Approve this lab? A provider record and a new dashboard login will be created and emailed.';

		echo '<form method="post" action="' . $form_action . '" style="max-width:760px;">';
		wp_nonce_field( 'tfpa_' . $approve_action );
		echo '<input type="hidden" name="tfpa_admin_action" value="' . esc_attr( $approve_action ) . '">';
		echo '<input type="hidden" name="request_id" value="' . (int) $r->id . '">';
		echo '<p><label for="tfpa-notes"><strong>Approval notes (optional)</strong></label><br>';
		echo '<textarea id="tfpa-notes" name="review_notes" rows="3" class="large-text" placeholder="Kept on the application for your own records."></textarea></p>';
		echo '<button type="submit" class="button button-primary" onclick="return confirm(\'' . esc_js( $approve_confirm ) . '\');">'
			. wp_kses( $approve_label, array() ) . '</button>';
		echo '</form>';

		echo '<form method="post" action="' . $form_action . '" style="max-width:760px; margin-top:22px; padding-top:18px; border-top:1px solid #dcdcde;">';
		wp_nonce_field( 'tfpa_reject' );
		echo '<input type="hidden" name="tfpa_admin_action" value="reject">';
		echo '<input type="hidden" name="request_id" value="' . (int) $r->id . '">';
		echo '<p><label for="tfpa-notes-reject"><strong>Decline notes (optional)</strong></label><br>';
		echo '<textarea id="tfpa-notes-reject" name="review_notes" rows="2" class="large-text" placeholder="Only included in the email if you write something here."></textarea></p>';
		echo '<button type="submit" class="button" onclick="return confirm(\'Decline this application and email the applicant?\');">Decline application</button>';
		echo '</form>';
	}

	// ── Emails (all branded through class-notifications) ──

	private static function can_email() {
		return class_exists( 'TwellerFlow2_Notifications' );
	}

	private static function mail( $to, $subject, $body ) {
		if ( ! self::can_email() ) return false;
		return TwellerFlow2_Notifications::send_raw( $to, $subject, $body );
	}

	private static function btn( $url, $label, $solid = true ) {
		if ( ! self::can_email() ) return '';
		if ( method_exists( 'TwellerFlow2_Notifications', 'email_button_row' ) ) {
			return TwellerFlow2_Notifications::email_button_row( $url, $label, $solid );
		}
		return "<div style='text-align:center; margin:28px 0;'>"
			. TwellerFlow2_Notifications::email_button( $url, $label, $solid )
			. "</div>";
	}

	private static function card( $title, $inner ) {
		if ( ! self::can_email() ) return $inner;
		return TwellerFlow2_Notifications::email_card( $title, $inner );
	}

	private static function row( $label, $value ) {
		if ( ! self::can_email() ) return '';
		return TwellerFlow2_Notifications::email_detail_row( esc_html( $label ), esc_html( $value ) );
	}

	private static function first_name_of( $row ) {
		$name = trim( (string) $row->contact_name );
		if ( $name === '' ) $name = trim( (string) $row->business_name );
		if ( $name === '' ) return 'there';
		$parts = explode( ' ', $name );
		return $parts[0];
	}

	/** Acknowledgement to the applicant. */
	private static function send_application_received_email( $row ) {
		if ( (string) $row->email === '' ) return false;

		$first = esc_html( self::first_name_of( $row ) );
		$body  = "
			<h2 style='color:#101010; font-weight:600;'>We have your application</h2>
			<p style='color:#3D3630;'>Hi {$first},</p>
			<p style='color:#3D3630; line-height:1.7;'>Thank you for putting <strong>" . esc_html( (string) $row->business_name ) . "</strong> forward as a Tweller Studios print partner. Your application is with us and a person &mdash; not a robot &mdash; will read it.</p>
			" . self::card( 'What you sent us',
				self::row( 'Business', (string) $row->business_name )
				. self::row( 'Contact', (string) $row->email )
				. ( (string) $row->services !== '' ? self::row( 'Services', self::services_label( (string) $row->services ) ) : '' )
				. ( (string) $row->turnaround !== '' ? self::row( 'Turnaround', (string) $row->turnaround ) : '' )
				. self::row( 'Delivery', (int) $row->does_delivery ? 'You deliver to customers' : 'Studio delivers' )
			) . "
			<p style='color:#3D3630; line-height:1.7;'>If we would like to work with you we will email a sign-in link to your own partner dashboard, where your jobs, print-ready files and delivery labels live. If not, we will still write back.</p>
			<p style='color:#3D3630;'>Warm regards,<br><strong>The Tweller Studios Team</strong></p>
		";

		return self::mail( (string) $row->email, 'We received your print partner application', $body );
	}

	/** Heads-up to the studio, with a button straight into the review screen. */
	private static function send_studio_application_email( $row ) {
		$to = self::studio_email();
		if ( $to === '' ) return false;

		$review_url = self::requests_url( array( 'request' => (int) $row->id ) );

		$body = "
			<h2 style='color:#101010; font-weight:600;'>New print partner application</h2>
			<p style='color:#3D3630; line-height:1.7;'><strong>" . esc_html( (string) $row->business_name ) . "</strong> has applied to print for Tweller Studios.</p>
			" . self::card( 'Applicant',
				self::row( 'Business', (string) $row->business_name )
				. self::row( 'Contact', (string) $row->contact_name )
				. self::row( 'Email', (string) $row->email )
				. ( (string) $row->phone !== '' ? self::row( 'Phone', (string) $row->phone ) : '' )
				. ( (string) $row->country !== '' ? self::row( 'Region', (string) $row->country ) : '' )
				. ( (string) $row->services !== '' ? self::row( 'Services', self::services_label( (string) $row->services ) ) : '' )
				. self::row( 'Delivers', (int) $row->does_delivery ? 'Yes' : 'No' )
			) . "
			" . self::btn( $review_url, 'Review this application' ) . "
			<p style='color:#8A8178; font-size:13px; text-align:center;'>Approving creates the provider record and the dashboard login in one step.</p>
		";

		return self::mail( $to, 'New print partner application — ' . (string) $row->business_name, $body );
	}

	/** The welcome. Never carries a password — WordPress sends the set link. */
	private static function send_approved_email( $row, $provider, $login ) {
		if ( (string) $row->email === '' ) return false;

		$first     = esc_html( self::first_name_of( $row ) );
		$dashboard = self::page_url();

		$body = "
			<h2 style='color:#101010; font-weight:600;'>Welcome aboard</h2>
			<p style='color:#3D3630;'>Hi {$first},</p>
			<p style='color:#3D3630; line-height:1.7;'>Good news &mdash; <strong>" . esc_html( (string) $provider['name'] ) . "</strong> has been approved as a Tweller Studios print partner. Your dashboard is live and ready for your first job.</p>

			" . self::btn( $dashboard, 'Open your partner dashboard' ) . "

			" . self::card( 'Your account',
				self::row( 'Username', (string) $login )
				. self::row( 'Sign in with', (string) $row->email )
				. self::row( 'Delivery', ! empty( $provider['does_delivery'] ) ? 'You deliver to customers' : 'Tweller Studios delivers' )
			) . "

			<p style='color:#3D3630; line-height:1.7;'>A separate email from the website carries a link to set your password. If it has not arrived, use <em>Forgot your password?</em> on the sign-in screen.</p>

			" . self::card( 'What happens next', "
				<ol style='color:#3D3630; margin:0; padding-left:20px; line-height:1.9;'>
					<li>We send you a job &mdash; you get an email with the print-ready package.</li>
					<li>Sign in, download the ZIP, and mark the job <strong>Printing</strong>.</li>
					<li>When it is boxed, mark it <strong>Ready</strong>. Print the label if you are delivering.</li>
					<li>Scan the barcode on hand-over and the job closes itself out.</li>
				</ol>
			" ) . "

			<p style='color:#3D3630; line-height:1.7;'>You will only ever see what you need to print a job &mdash; never our client&rsquo;s name, email or what they paid.</p>
			<p style='color:#3D3630;'>Welcome to the team,<br><strong>The Tweller Studios Team</strong></p>
		";

		return self::mail( (string) $row->email, 'You are in — welcome to the Tweller Studios print partners', $body );
	}

	private static function send_rejected_email( $row, $notes = '' ) {
		if ( (string) $row->email === '' ) return false;

		$first  = esc_html( self::first_name_of( $row ) );
		$notes  = trim( (string) $notes );
		$note_block = '';
		if ( $notes !== '' ) {
			$note_block = self::card( 'A note from the studio',
				"<p style='margin:0; color:#3D3630; line-height:1.7;'>" . nl2br( esc_html( $notes ) ) . "</p>"
			);
		}

		$body = "
			<h2 style='color:#101010; font-weight:600;'>Thank you for applying</h2>
			<p style='color:#3D3630;'>Hi {$first},</p>
			<p style='color:#3D3630; line-height:1.7;'>Thank you for offering to print for Tweller Studios, and for the time you spent on your application. We are not able to take on <strong>" . esc_html( (string) $row->business_name ) . "</strong> as a print partner right now.</p>
			{$note_block}
			<p style='color:#3D3630; line-height:1.7;'>This is not a judgement on your work &mdash; we keep the partner list deliberately small. Do get in touch again if what you offer changes; we are always happy to take another look.</p>
			<p style='color:#3D3630;'>With thanks,<br><strong>The Tweller Studios Team</strong></p>
		";

		return self::mail( (string) $row->email, 'Your print partner application', $body );
	}
}
