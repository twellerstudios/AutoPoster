<?php
/**
 * Tweller Flow — Print Fulfilment
 *
 * Turns a print order into a print-ready job package for an outsourced
 * photo lab, sends it to a provider, and closes the loop with a scannable
 * shipping label.
 *
 * FILE-PREP STANDARD (matches how professional labs — WHCC, Bay Photo,
 * Miller's, Nations — want files delivered):
 *
 *   • One file per line item, pre-cropped to the EXACT aspect ratio of the
 *     ordered product. Labs explicitly do not want crop marks, guides or
 *     borders burned into the image — the file itself defines the crop.
 *   • Sized to exact pixel dimensions = inches × 300 DPI
 *     (4×6 → 1200×1800, 5×7 → 1500×2100, 8×10 → 2400×3000 …).
 *   • Never upsampled. If the customer's crop cannot supply 300 DPI we
 *     render at the largest native size available and record the effective
 *     DPI in the manifest, flagging anything under 240 DPI (soft) and
 *     150 DPI (hard) so nobody prints a mush.
 *   • 8-bit sRGB JPEG, quality 92 (≈ Photoshop 10–11), metadata stripped.
 *   • Prints are edge-to-edge files; the manifest states the 0.125in safe
 *     area so nothing important sits in the trim zone.
 *   • Canvas gets a real gallery-wrap allowance pulled from the source
 *     image where the pixels exist (up to 1.5in per side); when they don't,
 *     the manifest instructs a mirror wrap.
 *   • Quantities live in the FILENAME and in the manifest, so a lab prints
 *     the right count from a single file.
 *   • Package = ZIP containing print-files/, manifest.csv (machine
 *     readable) and packing-slip.html (human readable) + README.txt.
 *
 * WHITE LABEL: nothing in the ZIP, manifest or provider email identifies
 * the end customer or implies resale. The customer never sees provider
 * information anywhere.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Print_Fulfillment {

	const VERSION = '1.0.0';

	const OPT_VERSION   = 'tweller_print_fulfillment_version';
	const OPT_PROVIDERS = 'tweller_print_providers';
	const OPT_SETTINGS  = 'tweller_print_fulfillment_settings';

	const JOBS_SUBDIR = 'tweller-print-jobs';

	/** File-prep constants (see class docblock). */
	const TARGET_DPI   = 300;
	const WARN_DPI     = 240; // below the lab-recommended standard
	const LOW_DPI      = 150; // hard warning — visibly soft
	const JPEG_QUALITY = 92;
	const SAFE_AREA_IN = 0.125;
	const CANVAS_WRAP_IN = 1.5;

	/** Signed provider/label link lifetime. */
	const LINK_TTL_DAYS = 14;

	/** Chunked rendering budget so PHP timeouts can never corrupt a job. */
	const CHUNK_ITEMS   = 8;
	const CHUNK_SECONDS = 15;

	const ADMIN_PAGE     = 'tweller-flow-2-prints';
	const PROVIDERS_PAGE = 'tweller-flow-2-print-providers';

	// ── Bootstrap ──────────────────────────────────────

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 21 );
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_actions' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'admin_notices', array( __CLASS__, 'capability_notice' ) );

		self::maybe_install();
	}

	/**
	 * Self-healing schema: adds our own `fulfillment` JSON column to the
	 * shared print-orders table. Deliberately additive (SHOW COLUMNS +
	 * ALTER) rather than a full dbDelta of a table we do not own, so we can
	 * never fight or revert the print store's own installer.
	 */
	public static function maybe_install() {
		if ( get_option( self::OPT_VERSION, '' ) === self::VERSION ) return;
		self::install();
		update_option( self::OPT_VERSION, self::VERSION );
	}

	public static function install() {
		global $wpdb;
		$table = self::orders_table();
		if ( ! $table ) return;

		// Only touch a table that actually exists yet.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) return;

		$col = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'fulfillment' ) );
		if ( empty( $col ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `fulfillment` longtext NULL" );
		}

		self::ensure_jobs_dir();
	}

	private static function orders_table() {
		global $wpdb;
		if ( ! class_exists( 'TwellerFlow2_Prints' ) ) return '';
		return $wpdb->prefix . TwellerFlow2_Prints::TABLE_ORDERS;
	}

	// ── Providers ──────────────────────────────────────

	/**
	 * @param bool $active_only Only providers that are switched on.
	 * @return array<int, array>
	 */
	public static function get_providers( $active_only = false ) {
		$raw = get_option( self::OPT_PROVIDERS, array() );
		if ( ! is_array( $raw ) ) $raw = array();

		$out = array();
		foreach ( $raw as $p ) {
			if ( ! is_array( $p ) || empty( $p['id'] ) ) continue;
			$row = array(
				'id'            => sanitize_key( $p['id'] ),
				'name'          => sanitize_text_field( (string) ( $p['name'] ?? '' ) ),
				'contact_name'  => sanitize_text_field( (string) ( $p['contact_name'] ?? '' ) ),
				'email'         => sanitize_email( (string) ( $p['email'] ?? '' ) ),
				'phone'         => sanitize_text_field( (string) ( $p['phone'] ?? '' ) ),
				'address'       => sanitize_textarea_field( (string) ( $p['address'] ?? '' ) ),
				'notes'         => sanitize_textarea_field( (string) ( $p['notes'] ?? '' ) ),
				'active'        => empty( $p['active'] ) ? 0 : 1,
				'default'       => empty( $p['default'] ) ? 0 : 1,
				// Provider portal: the WP account that logs in as this lab
				// (0 = no linked account) and whether THEY ship to the
				// client rather than the studio delivering.
				'user_id'       => isset( $p['user_id'] ) ? (int) $p['user_id'] : 0,
				'does_delivery' => empty( $p['does_delivery'] ) ? 0 : 1,
			);
			if ( $row['name'] === '' ) continue;
			if ( $active_only && ! $row['active'] ) continue;
			$out[] = $row;
		}
		return $out;
	}

	public static function get_provider( $provider_id ) {
		$provider_id = sanitize_key( (string) $provider_id );
		foreach ( self::get_providers() as $p ) {
			if ( $p['id'] === $provider_id ) return $p;
		}
		return null;
	}

	public static function get_default_provider() {
		$active = self::get_providers( true );
		foreach ( $active as $p ) {
			if ( ! empty( $p['default'] ) ) return $p;
		}
		return ! empty( $active ) ? $active[0] : null;
	}

	public static function get_settings() {
		$defaults = array(
			'provider_instructions' => "Please print exactly as supplied — the files are already cropped and sized to the ordered dimensions.\nPack each order together and label it with the order reference.\nReturn the finished order to Tweller Studios; do not include any of your own branding, packing slips or marketing material in the package.",
			'return_address'        => '',
			'link_expiry_days'      => self::LINK_TTL_DAYS,
			'paper_finish'          => 'Professional lustre',
		);
		$saved = get_option( self::OPT_SETTINGS, array() );
		if ( ! is_array( $saved ) ) $saved = array();
		$merged = array_merge( $defaults, $saved );
		$merged['link_expiry_days'] = max( 1, min( 90, (int) $merged['link_expiry_days'] ) );
		return $merged;
	}

	// ── Fulfilment record on the order ─────────────────

	public static function default_fulfillment() {
		return array(
			'delivery_address' => '',
			'delivery_notes'   => '',
			'build'            => null,
			'sends'            => array(),
			'delivered_at'     => '',
			'delivered_via'    => '',
			// ── Provider portal ──
			'provider_id'      => '',   // lab currently responsible for the job
			'assigned_at'      => '',
			'started_at'       => '',   // provider hit "start printing"
			'ready_at'         => '',   // provider marked ready
			'shipped_at'       => '',   // provider handed over / shipped
			'delivered_by'     => array(), // actor_type + actor_id of the closing scan
			'audit'            => array(), // {at, actor_type, actor_id, action}
		);
	}

	/** @param object $order Row from the print-orders table. */
	public static function get_fulfillment( $order ) {
		$data = array();
		if ( is_object( $order ) && isset( $order->fulfillment ) && $order->fulfillment !== '' ) {
			$decoded = json_decode( (string) $order->fulfillment, true );
			if ( is_array( $decoded ) ) $data = $decoded;
		}
		$merged = array_merge( self::default_fulfillment(), $data );
		if ( ! is_array( $merged['sends'] ) ) $merged['sends'] = array();
		if ( ! is_array( $merged['build'] ) ) $merged['build'] = null;
		if ( ! is_array( $merged['audit'] ) ) $merged['audit'] = array();
		if ( ! is_array( $merged['delivered_by'] ) ) $merged['delivered_by'] = array();

		// Legacy orders were assigned by email only — infer the provider
		// from the most recent send so the portal can still see them.
		if ( (string) $merged['provider_id'] === '' && ! empty( $merged['sends'] ) ) {
			$last = end( $merged['sends'] );
			if ( is_array( $last ) && ! empty( $last['provider_id'] ) ) {
				$merged['provider_id'] = sanitize_key( (string) $last['provider_id'] );
				if ( (string) $merged['assigned_at'] === '' ) {
					$merged['assigned_at'] = (string) ( $last['sent_at'] ?? '' );
				}
			}
		}
		return $merged;
	}

	/**
	 * Public writer so the provider portal can persist its own state
	 * (status timestamps, audit entries) without duplicating the encode.
	 *
	 * @param int   $order_id
	 * @param array $fulfillment
	 * @return bool
	 */
	public static function set_fulfillment( $order_id, $fulfillment ) {
		if ( ! is_array( $fulfillment ) ) return false;
		return self::save_fulfillment( (int) $order_id, $fulfillment );
	}

	/** Provider id currently responsible for an order ('' when unassigned). */
	public static function assigned_provider_id( $order ) {
		$f = self::get_fulfillment( $order );
		return sanitize_key( (string) $f['provider_id'] );
	}

	/** Append one audit entry: {at, actor_type, actor_id, action}. */
	public static function log_audit( $order_id, $actor_type, $actor_id, $action, $meta = array() ) {
		$order = self::get_order( (int) $order_id );
		if ( ! $order ) return false;

		$f     = self::get_fulfillment( $order );
		$entry = array(
			'at'         => current_time( 'mysql' ),
			'actor_type' => ( $actor_type === 'provider' ) ? 'provider' : 'studio',
			'actor_id'   => (int) $actor_id,
			'action'     => sanitize_key( (string) $action ),
		);
		if ( ! empty( $meta ) && is_array( $meta ) ) {
			$entry['meta'] = array_map( 'sanitize_text_field', array_map( 'strval', $meta ) );
		}
		$f['audit'][] = $entry;
		if ( count( $f['audit'] ) > 200 ) {
			$f['audit'] = array_slice( $f['audit'], -200 );
		}
		self::save_fulfillment( (int) $order_id, $f );

		do_action( 'tweller_print_order_audit', (int) $order_id, $entry );
		return true;
	}

	private static function save_fulfillment( $order_id, $fulfillment ) {
		global $wpdb;
		$table = self::orders_table();
		if ( ! $table ) return false;

		return (bool) $wpdb->update(
			$table,
			array(
				'fulfillment' => wp_json_encode( $fulfillment ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => (int) $order_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
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
		if ( ! class_exists( 'TwellerFlow2_Prints' ) ) return array();
		$items = TwellerFlow2_Prints::get_order_items( $order );
		return is_array( $items ) ? $items : array();
	}

	// ── Environment capability checks ──────────────────

	public static function has_zip() {
		return class_exists( 'ZipArchive' );
	}

	public static function has_image_editor() {
		if ( ! function_exists( 'wp_image_editor_supports' ) ) return false;
		return (bool) wp_image_editor_supports( array( 'mime_type' => 'image/jpeg', 'methods' => array( 'crop', 'resize', 'save' ) ) );
	}

	public static function capability_notice() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page !== self::ADMIN_PAGE && $page !== self::PROVIDERS_PAGE ) return;
		unset( $screen );

		$problems = array();
		if ( ! self::has_image_editor() ) {
			$problems[] = 'No image editor is available (GD or Imagick). Print-ready files cannot be rendered on this server.';
		}
		if ( ! self::has_zip() ) {
			$problems[] = 'The PHP <code>ZipArchive</code> extension is missing. Files can still be rendered, but they cannot be packaged into a ZIP for the provider.';
		}
		if ( empty( $problems ) ) return;

		echo '<div class="notice notice-warning"><p><strong>Print fulfilment:</strong></p><ul style="list-style:disc; margin-left:20px;">';
		foreach ( $problems as $p ) {
			echo '<li>' . wp_kses( $p, array( 'code' => array() ) ) . '</li>';
		}
		echo '</ul></div>';
	}

	// ── Job storage ────────────────────────────────────

	public static function jobs_basedir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) return '';
		return wp_normalize_path( trailingslashit( $uploads['basedir'] ) . self::JOBS_SUBDIR );
	}

	private static function ensure_jobs_dir() {
		$dir = self::jobs_basedir();
		if ( ! $dir ) return '';
		if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );

		// Print files are customer photographs — never browsable directly.
		// They are only ever served through the signed download endpoint.
		$htaccess = $dir . '/.htaccess';
		if ( is_dir( $dir ) && ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Order allow,deny\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
		}
		$index = $dir . '/index.html';
		if ( is_dir( $dir ) && ! file_exists( $index ) ) {
			@file_put_contents( $index, '' );
		}
		return $dir;
	}

	public static function job_dir( $order_ref ) {
		$base = self::ensure_jobs_dir();
		if ( ! $base ) return '';
		return $base . '/' . sanitize_file_name( (string) $order_ref );
	}

	public static function zip_path( $order_ref ) {
		$dir = self::job_dir( $order_ref );
		if ( ! $dir ) return '';
		return $dir . '/' . sanitize_file_name( (string) $order_ref ) . '-print-files.zip';
	}

	private static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) return;
		$base = self::jobs_basedir();
		$real = realpath( $dir );
		if ( ! $base || ! $real || strpos( wp_normalize_path( $real ), trailingslashit( $base ) ) !== 0 ) return;

		foreach ( (array) glob( $real . '/*' ) as $f ) {
			if ( is_dir( $f ) ) {
				self::rrmdir( $f );
			} elseif ( is_file( $f ) ) {
				@unlink( $f );
			}
		}
		@rmdir( $real );
	}

	// ── Source resolution (hard uploads-dir guard) ─────

	/** Upload roots a print source image is allowed to come from. */
	private static function allowed_roots() {
		$roots = array( 'tweller-gallery', 'tweller-prints' );
		return apply_filters( 'tweller_print_fulfillment_source_roots', $roots );
	}

	/**
	 * Map a photo URL back to an absolute path inside wp-content/uploads.
	 * Returns '' when the URL is outside the allowed roots, escapes the
	 * uploads directory, or does not resolve to a readable file.
	 */
	public static function resolve_source_path( $url, $order_ref = '', $filename = '' ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) return '';

		$basedir_real = realpath( $uploads['basedir'] );
		if ( ! $basedir_real ) return '';
		$basedir_real = wp_normalize_path( $basedir_real );

		$rel = '';
		$url = trim( (string) $url );
		if ( $url !== '' ) {
			$u = preg_replace( '#^\w+:#', '', $url );          // strip scheme
			$b = preg_replace( '#^\w+:#', '', $uploads['baseurl'] );
			if ( $u !== null && $b !== null && strpos( $u, $b ) === 0 ) {
				$rel = ltrim( substr( $u, strlen( $b ) ), '/' );
			}
		}

		// Fall back to the public print-upload folder for this order.
		if ( $rel === '' && $order_ref !== '' && $filename !== '' && class_exists( 'TwellerFlow2_Prints' ) ) {
			$rel = TwellerFlow2_Prints::UPLOAD_SUBDIR . '/' . sanitize_file_name( $order_ref ) . '/' . sanitize_file_name( $filename );
		}
		if ( $rel === '' ) return '';

		$rel = (string) preg_replace( '/[?#].*$/', '', $rel );
		$rel = rawurldecode( $rel );
		if ( strpos( $rel, "\0" ) !== false ) return '';
		if ( strpos( $rel, '..' ) !== false ) return '';
		$rel = ltrim( wp_normalize_path( $rel ), '/' );

		$root_ok = false;
		foreach ( self::allowed_roots() as $root ) {
			if ( strpos( $rel, trim( $root, '/' ) . '/' ) === 0 ) { $root_ok = true; break; }
		}
		if ( ! $root_ok ) return '';

		$real = realpath( $basedir_real . '/' . $rel );
		if ( ! $real ) return '';
		$real = wp_normalize_path( $real );

		if ( strpos( $real, trailingslashit( $basedir_real ) ) !== 0 ) return '';
		if ( ! is_file( $real ) || ! is_readable( $real ) ) return '';

		return $real;
	}

	// ── Crop maths ─────────────────────────────────────

	/** Normalise the item's crop into floats 0..1, or null when absent. */
	public static function normalize_crop( $crop ) {
		if ( is_string( $crop ) ) {
			$decoded = json_decode( $crop, true );
			$crop    = is_array( $decoded ) ? $decoded : null;
		}
		if ( ! is_array( $crop ) ) return null;
		if ( ! isset( $crop['w'], $crop['h'] ) ) return null;

		$x = (float) ( $crop['x'] ?? 0 );
		$y = (float) ( $crop['y'] ?? 0 );
		$w = (float) $crop['w'];
		$h = (float) $crop['h'];

		if ( $w <= 0 || $h <= 0 ) return null;
		// Values must look normalised; anything wilder is untrustworthy.
		if ( $w > 1.0001 || $h > 1.0001 || $x < -0.0001 || $y < -0.0001 ) return null;

		$x = max( 0.0, min( 1.0, $x ) );
		$y = max( 0.0, min( 1.0, $y ) );
		$w = min( $w, 1.0 - $x );
		$h = min( $h, 1.0 - $y );
		if ( $w <= 0 || $h <= 0 ) return null;

		return array( 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h );
	}

	/**
	 * Crop rectangle in source pixels, forced to the product aspect ratio
	 * (shrunk-to-fit inside the customer's rect so nothing is stretched).
	 * Falls back to a centred maximal crop when no crop was supplied.
	 */
	public static function crop_rect( $crop, $src_w, $src_h, $ratio ) {
		$src_w = max( 1, (int) $src_w );
		$src_h = max( 1, (int) $src_h );
		$ratio = $ratio > 0 ? (float) $ratio : ( $src_w / $src_h );

		if ( $crop ) {
			$cx = $crop['x'] * $src_w;
			$cy = $crop['y'] * $src_h;
			$cw = $crop['w'] * $src_w;
			$ch = $crop['h'] * $src_h;
		} else {
			$cx = 0; $cy = 0; $cw = $src_w; $ch = $src_h;
		}

		// Force the exact product ratio inside the selected rect.
		if ( $cw / $ch > $ratio ) {
			$new_w = $ch * $ratio;
			$cx   += ( $cw - $new_w ) / 2;
			$cw    = $new_w;
		} else {
			$new_h = $cw / $ratio;
			$cy   += ( $ch - $new_h ) / 2;
			$ch    = $new_h;
		}

		$cx = max( 0.0, min( (float) $src_w - 1, $cx ) );
		$cy = max( 0.0, min( (float) $src_h - 1, $cy ) );
		$cw = max( 1.0, min( (float) $src_w - $cx, $cw ) );
		$ch = max( 1.0, min( (float) $src_h - $cy, $ch ) );

		return array(
			'x' => (int) round( $cx ),
			'y' => (int) round( $cy ),
			'w' => (int) round( $cw ),
			'h' => (int) round( $ch ),
		);
	}

	/**
	 * Gallery-wrap allowance for canvas: grow the crop outwards using real
	 * source pixels, up to CANVAS_WRAP_IN per side. Returns the (possibly
	 * unchanged) rect plus how many inches of wrap we actually achieved.
	 */
	private static function apply_canvas_wrap( $rect, $src_w, $src_h, $w_in, $h_in ) {
		if ( $w_in <= 0 || $h_in <= 0 ) return array( $rect, 0.0 );

		$px_per_in_w = $rect['w'] / $w_in;
		$px_per_in_h = $rect['h'] / $h_in;
		$px_per_in   = min( $px_per_in_w, $px_per_in_h );
		if ( $px_per_in <= 0 ) return array( $rect, 0.0 );

		$want_px = self::CANVAS_WRAP_IN * $px_per_in;

		$room = min(
			$rect['x'],
			$rect['y'],
			$src_w - ( $rect['x'] + $rect['w'] ),
			$src_h - ( $rect['y'] + $rect['h'] )
		);
		$grow = (int) floor( max( 0, min( $want_px, $room ) ) );
		if ( $grow < 2 ) return array( $rect, 0.0 );

		$rect = array(
			'x' => $rect['x'] - $grow,
			'y' => $rect['y'] - $grow,
			'w' => $rect['w'] + 2 * $grow,
			'h' => $rect['h'] + 2 * $grow,
		);
		return array( $rect, round( $grow / $px_per_in, 3 ) );
	}

	// ── Rendering ──────────────────────────────────────

	private static function product_for_item( $item ) {
		if ( ! class_exists( 'TwellerFlow2_Prints' ) ) return null;
		$id = isset( $item['product_id'] ) ? (string) $item['product_id'] : '';
		if ( $id === '' ) return null;
		return TwellerFlow2_Prints::get_product( $id );
	}

	private static function size_label( $w_in, $h_in ) {
		$fmt = function( $n ) {
			$s = rtrim( rtrim( number_format( (float) $n, 2, '.', '' ), '0' ), '.' );
			return $s === '' ? '0' : $s;
		};
		return $fmt( $w_in ) . 'x' . $fmt( $h_in );
	}

	/**
	 * Signature of everything that affects the rendered output. When it
	 * changes the cached job is rebuilt from scratch.
	 */
	private static function items_hash( $order ) {
		$items = self::order_items( $order );
		$sig   = array( 'v' => self::VERSION, 'items' => array() );
		foreach ( $items as $it ) {
			$product = self::product_for_item( $it );
			$sig['items'][] = array(
				'p'  => (string) ( $it['product_id'] ?? '' ),
				'q'  => (int) ( $it['qty'] ?? 1 ),
				'f'  => (string) ( $it['filename'] ?? '' ),
				'u'  => (string) ( $it['photo_url'] ?? '' ),
				'c'  => self::normalize_crop( $it['crop'] ?? null ),
				'w'  => $product ? (float) ( $product['width_in'] ?? 0 ) : 0,
				'h'  => $product ? (float) ( $product['height_in'] ?? 0 ) : 0,
			);
		}
		return md5( (string) wp_json_encode( $sig ) );
	}

	/**
	 * Render one line item to a print-ready JPEG.
	 * Never throws; failures come back as a record with an `error` key.
	 */
	private static function render_item( $order, $item, $seq ) {
		$order_ref = (string) $order->order_ref;
		$qty       = max( 1, (int) ( $item['qty'] ?? 1 ) );
		$orig      = sanitize_file_name( (string) ( $item['filename'] ?? ( 'photo-' . $seq ) ) );
		$orig_base = pathinfo( $orig, PATHINFO_FILENAME );
		if ( $orig_base === '' ) $orig_base = 'photo-' . $seq;

		$record = array(
			'seq'          => $seq,
			'product_id'   => (string) ( $item['product_id'] ?? '' ),
			'product_name' => (string) ( $item['product_name'] ?? '' ),
			'category'     => (string) ( $item['category'] ?? 'print' ),
			'qty'          => $qty,
			'source_name'  => $orig,
			'file'         => '',
			'width_in'     => 0,
			'height_in'    => 0,
			'out_w'        => 0,
			'out_h'        => 0,
			'dpi'          => 0,
			'bleed_in'     => 0,
			'low_dpi'      => false,
			'error'        => '',
		);

		$product = self::product_for_item( $item );
		$w_in    = $product ? (float) ( $product['width_in'] ?? 0 ) : 0;
		$h_in    = $product ? (float) ( $product['height_in'] ?? 0 ) : 0;
		if ( $w_in <= 0 || $h_in <= 0 ) {
			$record['error'] = 'No physical size is set for this product — add width/height (inches) in Products & Settings, then rebuild.';
			return $record;
		}
		$record['width_in']  = $w_in;
		$record['height_in'] = $h_in;

		$src = self::resolve_source_path( $item['photo_url'] ?? '', $order_ref, $orig );
		if ( $src === '' ) {
			$record['error'] = 'Source image could not be found in the uploads folder.';
			return $record;
		}

		if ( ! self::has_image_editor() ) {
			$record['error'] = 'No image editor available on this server.';
			return $record;
		}

		$editor = wp_get_image_editor( $src );
		if ( is_wp_error( $editor ) ) {
			$record['error'] = 'Image could not be opened: ' . $editor->get_error_message();
			return $record;
		}

		// get_size() is post-EXIF-rotation, which is what the customer saw
		// in the cropper — so normalised crop coordinates line up.
		$size  = $editor->get_size();
		$src_w = (int) ( $size['width'] ?? 0 );
		$src_h = (int) ( $size['height'] ?? 0 );
		if ( $src_w < 2 || $src_h < 2 ) {
			$src_w = (int) ( $item['source_w'] ?? 0 );
			$src_h = (int) ( $item['source_h'] ?? 0 );
		}
		if ( $src_w < 2 || $src_h < 2 ) {
			$record['error'] = 'Source image dimensions could not be read.';
			return $record;
		}

		$crop  = self::normalize_crop( $item['crop'] ?? null );
		$ratio = $w_in / $h_in;
		$rect  = self::crop_rect( $crop, $src_w, $src_h, $ratio );

		$total_w_in = $w_in;
		$total_h_in = $h_in;
		if ( $record['category'] === 'canvas' ) {
			list( $rect, $wrap ) = self::apply_canvas_wrap( $rect, $src_w, $src_h, $w_in, $h_in );
			if ( $wrap > 0 ) {
				$record['bleed_in'] = $wrap;
				$total_w_in = $w_in + 2 * $wrap;
				$total_h_in = $h_in + 2 * $wrap;
			}
		}

		// Never upsample: cap the 300 DPI target at the native crop size.
		$target_w = (int) round( $total_w_in * self::TARGET_DPI );
		$target_h = (int) round( $total_h_in * self::TARGET_DPI );
		$scale    = min( 1.0, $rect['w'] / max( 1, $target_w ), $rect['h'] / max( 1, $target_h ) );
		$out_w    = max( 1, (int) round( $target_w * $scale ) );
		$out_h    = max( 1, (int) round( $target_h * $scale ) );

		$dpi = (int) round( min( $out_w / max( 0.01, $total_w_in ), $out_h / max( 0.01, $total_h_in ) ) );

		$filename = sprintf(
			'%s_%02d_%sin_%dx_%s.jpg',
			$order_ref,
			$seq,
			self::size_label( $w_in, $h_in ),
			$qty,
			$orig_base
		);
		$filename = sanitize_file_name( $filename );

		$dir = self::job_dir( $order_ref ) . '/print-files';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			$record['error'] = 'Could not create the job folder on disk.';
			return $record;
		}
		$dest = $dir . '/' . $filename;

		$cropped = $editor->crop( $rect['x'], $rect['y'], $rect['w'], $rect['h'], $out_w, $out_h );
		if ( is_wp_error( $cropped ) ) {
			$record['error'] = 'Crop failed: ' . $cropped->get_error_message();
			return $record;
		}

		$editor->set_quality( self::JPEG_QUALITY );
		$saved = $editor->save( $dest, 'image/jpeg' );
		if ( is_wp_error( $saved ) ) {
			$record['error'] = 'Save failed: ' . $saved->get_error_message();
			return $record;
		}
		if ( ! empty( $saved['path'] ) ) {
			$dest     = $saved['path'];
			$filename = basename( $dest );
		}

		self::force_srgb( $dest );

		$record['file']    = $filename;
		$record['out_w']   = $out_w;
		$record['out_h']   = $out_h;
		$record['dpi']     = $dpi;
		$record['bytes']   = (int) @filesize( $dest );
		$record['low_dpi'] = ( $dpi < self::WARN_DPI );
		$record['very_low_dpi'] = ( $dpi < self::LOW_DPI );

		return $record;
	}

	/**
	 * Guarantee an 8-bit sRGB JPEG with no stray colour profile or
	 * metadata. GD output is already sRGB with everything stripped; the
	 * Imagick path converts wide-gamut sources properly before stripping.
	 */
	private static function force_srgb( $path ) {
		if ( ! class_exists( 'Imagick' ) || ! is_file( $path ) ) return;
		try {
			$img = new Imagick( $path );
			if ( method_exists( $img, 'transformImageColorspace' ) && defined( 'Imagick::COLORSPACE_SRGB' ) ) {
				if ( $img->getImageColorspace() !== Imagick::COLORSPACE_SRGB ) {
					$img->transformImageColorspace( Imagick::COLORSPACE_SRGB );
				}
			}
			if ( method_exists( $img, 'setImageDepth' ) ) $img->setImageDepth( 8 );
			if ( method_exists( $img, 'stripImage' ) ) $img->stripImage();
			if ( method_exists( $img, 'setImageResolution' ) ) {
				$img->setImageResolution( self::TARGET_DPI, self::TARGET_DPI );
				if ( defined( 'Imagick::RESOLUTION_PIXELSPERINCH' ) ) {
					$img->setImageUnits( Imagick::RESOLUTION_PIXELSPERINCH );
				}
			}
			$img->setImageFormat( 'jpeg' );
			$img->setImageCompressionQuality( self::JPEG_QUALITY );
			$img->writeImage( $path );
			$img->clear();
			$img->destroy();
		} catch ( Exception $e ) {
			// A colour-management hiccup must never fail an order.
			return;
		}
	}

	// ── Build (chunked, resumable) ─────────────────────

	/**
	 * Render one chunk of the job. Safe to call repeatedly; returns the
	 * build state so the caller can decide whether to continue.
	 */
	public static function build_chunk( $order_id, $force = false ) {
		$order = self::get_order( $order_id );
		if ( ! $order ) return array( 'done' => true, 'error' => 'Order not found.' );

		$fulfillment = self::get_fulfillment( $order );
		$items       = self::order_items( $order );
		$hash        = self::items_hash( $order );

		$build = $fulfillment['build'];
		if ( $force || ! is_array( $build ) || ( $build['hash'] ?? '' ) !== $hash ) {
			self::rrmdir( self::job_dir( $order->order_ref ) );
			$build = array(
				'hash'       => $hash,
				'started_at' => current_time( 'mysql' ),
				'next'       => 0,
				'total'      => count( $items ),
				'files'      => array(),
				'errors'     => array(),
				'done'       => false,
				'zip'        => '',
				'zip_bytes'  => 0,
				'built_at'   => '',
			);
		}
		if ( ! empty( $build['done'] ) ) {
			$fulfillment['build'] = $build;
			return $build;
		}

		$build['total'] = count( $items );
		$started        = microtime( true );
		$processed      = 0;

		while ( $build['next'] < count( $items ) ) {
			if ( $processed >= self::CHUNK_ITEMS ) break;
			if ( $processed > 0 && ( microtime( true ) - $started ) > self::CHUNK_SECONDS ) break;

			$idx  = (int) $build['next'];
			$item = $items[ $idx ];
			$rec  = self::render_item( $order, $item, $idx + 1 );

			if ( $rec['error'] !== '' ) {
				$build['errors'][] = array(
					'seq'     => $rec['seq'],
					'file'    => $rec['source_name'],
					'product' => $rec['product_name'],
					'message' => $rec['error'],
				);
			}
			$build['files'][] = $rec;
			$build['next']    = $idx + 1;
			$processed++;
		}

		if ( $build['next'] >= count( $items ) ) {
			$pack = self::package_job( $order, $build );
			$build['zip']       = $pack['zip'];
			$build['zip_bytes'] = $pack['bytes'];
			if ( $pack['error'] !== '' ) {
				$build['errors'][] = array( 'seq' => 0, 'file' => '', 'product' => '', 'message' => $pack['error'] );
			}
			$build['done']     = true;
			$build['built_at'] = current_time( 'mysql' );
		}

		$fulfillment['build'] = $build;
		self::save_fulfillment( $order->id, $fulfillment );

		return $build;
	}

	public static function build_stats( $build ) {
		$stats = array( 'files' => 0, 'pieces' => 0, 'low' => 0, 'very_low' => 0, 'errors' => 0 );
		if ( ! is_array( $build ) ) return $stats;
		foreach ( (array) ( $build['files'] ?? array() ) as $f ) {
			if ( ! empty( $f['error'] ) ) { $stats['errors']++; continue; }
			$stats['files']++;
			$stats['pieces'] += (int) ( $f['qty'] ?? 1 );
			if ( ! empty( $f['very_low_dpi'] ) ) { $stats['very_low']++; }
			elseif ( ! empty( $f['low_dpi'] ) ) { $stats['low']++; }
		}
		return $stats;
	}

	// ── Manifest + ZIP ─────────────────────────────────

	private static function manifest_csv( $order, $build ) {
		$rows = array();
		$rows[] = array(
			'seq', 'file_name', 'product', 'type', 'print_size_in', 'quantity',
			'file_pixels', 'effective_dpi', 'colour_space', 'bleed_in', 'resolution_flag', 'notes',
		);

		foreach ( (array) ( $build['files'] ?? array() ) as $f ) {
			if ( ! empty( $f['error'] ) ) {
				$rows[] = array(
					$f['seq'], '', $f['product_name'], $f['category'], '', $f['qty'],
					'', '', '', '', 'MISSING', $f['error'],
				);
				continue;
			}
			$flag = 'OK';
			if ( ! empty( $f['very_low_dpi'] ) )      $flag = 'LOW RESOLUTION';
			elseif ( ! empty( $f['low_dpi'] ) )       $flag = 'BELOW 300DPI';

			$rows[] = array(
				$f['seq'],
				$f['file'],
				$f['product_name'],
				ucfirst( (string) $f['category'] ),
				self::size_label( $f['width_in'], $f['height_in'] ) . ' in',
				$f['qty'],
				$f['out_w'] . 'x' . $f['out_h'],
				$f['dpi'],
				'sRGB',
				$f['bleed_in'] ? $f['bleed_in'] : '0',
				$flag,
				( $f['category'] === 'canvas' && empty( $f['bleed_in'] ) ) ? 'No wrap bleed available — mirror wrap required' : '',
			);
		}

		$out = '';
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $row as $cell ) {
				$cell    = str_replace( '"', '""', (string) $cell );
				$cells[] = '"' . $cell . '"';
			}
			$out .= implode( ',', $cells ) . "\r\n";
		}
		return $out;
	}

	/**
	 * Human-readable packing slip. Deliberately carries NO customer
	 * identity — the lab only needs the reference, the pieces and the specs.
	 */
	private static function packing_slip_html( $order, $build ) {
		$settings = self::get_settings();
		$stats    = self::build_stats( $build );
		$ref      = esc_html( (string) $order->order_ref );
		$date     = esc_html( date_i18n( 'F j, Y', strtotime( (string) $order->created_at ) ) );

		$rows = '';
		foreach ( (array) ( $build['files'] ?? array() ) as $f ) {
			if ( ! empty( $f['error'] ) ) {
				$rows .= '<tr class="err"><td>' . (int) $f['seq'] . '</td><td colspan="6">'
					. esc_html( $f['product_name'] ) . ' — FILE MISSING: ' . esc_html( $f['error'] ) . '</td></tr>';
				continue;
			}
			$flag = '';
			if ( ! empty( $f['very_low_dpi'] ) ) {
				$flag = '<span class="warn">LOW RES</span>';
			} elseif ( ! empty( $f['low_dpi'] ) ) {
				$flag = '<span class="soft">&lt;300dpi</span>';
			}
			$rows .= '<tr>'
				. '<td>' . (int) $f['seq'] . '</td>'
				. '<td class="mono">' . esc_html( $f['file'] ) . '</td>'
				. '<td>' . esc_html( $f['product_name'] ) . '</td>'
				. '<td>' . esc_html( self::size_label( $f['width_in'], $f['height_in'] ) ) . '&Prime;</td>'
				. '<td class="qty">' . (int) $f['qty'] . '</td>'
				. '<td>' . (int) $f['out_w'] . ' &times; ' . (int) $f['out_h'] . ' px</td>'
				. '<td>' . (int) $f['dpi'] . ' dpi ' . $flag . '</td>'
				. '</tr>';
		}

		$instructions = nl2br( esc_html( (string) $settings['provider_instructions'] ) );
		$return_addr  = trim( (string) $settings['return_address'] ) !== ''
			? '<div class="block"><h2>Return to</h2><p>' . nl2br( esc_html( $settings['return_address'] ) ) . '</p></div>'
			: '';

		return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Print job ' . $ref . '</title>
<style>
 body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#101010;margin:0;padding:32px;background:#fff;}
 h1{font-size:20px;margin:0 0 4px;letter-spacing:.5px;}
 .brand{font-size:11px;letter-spacing:6px;text-transform:uppercase;color:#8A8178;margin:0 0 18px;}
 .meta{display:flex;gap:28px;flex-wrap:wrap;margin:0 0 22px;padding:14px 18px;background:#FAF9F6;border:1px solid #ECE9E2;border-radius:10px;}
 .meta div{font-size:12px;color:#8A8178;}
 .meta strong{display:block;font-size:15px;color:#101010;margin-top:2px;}
 table{width:100%;border-collapse:collapse;font-size:12.5px;}
 th{text-align:left;padding:8px 6px;border-bottom:2px solid #101010;font-size:10.5px;letter-spacing:1px;text-transform:uppercase;color:#8A8178;}
 td{padding:8px 6px;border-bottom:1px solid #ECE9E2;vertical-align:top;}
 .mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11px;}
 .qty{font-weight:700;}
 .warn{background:#FEE2E2;color:#B91C1C;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;}
 .soft{background:#FEF3C7;color:#92400E;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;}
 tr.err td{background:#FEF2F2;color:#B91C1C;}
 .block{margin-top:26px;padding-top:16px;border-top:1px solid #ECE9E2;}
 .block h2{font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#8A8178;margin:0 0 8px;}
 .block p{margin:0;font-size:12.5px;line-height:1.7;color:#3D3630;}
 @media print{body{padding:10mm;}}
</style></head><body>
<p class="brand">Tweller Studios</p>
<h1>Print job ' . $ref . '</h1>
<div class="meta">
 <div>Order reference<strong>' . $ref . '</strong></div>
 <div>Order date<strong>' . $date . '</strong></div>
 <div>Files<strong>' . (int) $stats['files'] . '</strong></div>
 <div>Total pieces<strong>' . (int) $stats['pieces'] . '</strong></div>
 <div>Finish<strong>' . esc_html( $settings['paper_finish'] ) . '</strong></div>
</div>
<table>
 <thead><tr><th>#</th><th>File</th><th>Product</th><th>Size</th><th>Qty</th><th>Pixels</th><th>Resolution</th></tr></thead>
 <tbody>' . $rows . '</tbody>
</table>
<div class="block"><h2>File specification</h2><p>
 Every file is pre-cropped to the exact aspect ratio of its ordered size and supplied at ' . (int) self::TARGET_DPI . ' DPI where the source allows.
 Colour space sRGB, 8-bit JPEG, quality ' . (int) self::JPEG_QUALITY . '. No crop marks or guides are burned into the images.
 Please print the files as supplied — do not re-crop, rotate, auto-enhance or colour-correct.
 Keep critical content ' . self::SAFE_AREA_IN . '&Prime; inside the trim edge.
</p></div>
<div class="block"><h2>Instructions</h2><p>' . $instructions . '</p></div>
' . $return_addr . '
</body></html>';
	}

	private static function readme_txt( $order, $build ) {
		$stats = self::build_stats( $build );
		$lines = array();
		$lines[] = 'TWELLER STUDIOS — PRINT JOB ' . $order->order_ref;
		$lines[] = str_repeat( '=', 52 );
		$lines[] = '';
		$lines[] = 'Files:        ' . $stats['files'];
		$lines[] = 'Total pieces: ' . $stats['pieces'] . '  (see the quantity in each filename and in manifest.csv)';
		$lines[] = '';
		$lines[] = 'HOW TO READ THE FILENAMES';
		$lines[] = '  {ORDER REF}_{seq}_{WxH inches}_{qty}x_{original name}.jpg';
		$lines[] = '  e.g. ' . $order->order_ref . '_03_5x7in_2x_beach-sunset.jpg  =  two 5x7in prints.';
		$lines[] = '';
		$lines[] = 'FILE SPECIFICATION';
		$lines[] = '  • Pre-cropped to the exact aspect ratio of the ordered size.';
		$lines[] = '  • Sized to inches x ' . self::TARGET_DPI . ' DPI wherever the source allows; never upsampled.';
		$lines[] = '    Any file below ' . self::TARGET_DPI . ' DPI is listed with its true effective DPI in manifest.csv.';
		$lines[] = '  • 8-bit sRGB JPEG, quality ' . self::JPEG_QUALITY . ', metadata stripped.';
		$lines[] = '  • No crop marks, guides or borders are drawn on the images.';
		$lines[] = '  • Keep critical content ' . self::SAFE_AREA_IN . 'in inside the trim edge.';
		$lines[] = '  • Canvas files include real gallery-wrap bleed where the source allowed it';
		$lines[] = '    (see the bleed_in column). Where bleed_in is 0, please mirror wrap.';
		$lines[] = '';
		$lines[] = 'PLEASE PRINT AS SUPPLIED — no re-cropping, rotation, auto-enhance or colour correction.';
		$lines[] = '';
		$lines[] = 'CONTENTS';
		$lines[] = '  print-files/        the print-ready JPEGs';
		$lines[] = '  manifest.csv        machine-readable line items';
		$lines[] = '  packing-slip.html   printable order summary';
		$lines[] = '';
		return implode( "\r\n", $lines );
	}

	/** Write manifest files and zip the job folder. */
	private static function package_job( $order, $build ) {
		$result = array( 'zip' => '', 'bytes' => 0, 'error' => '' );

		$dir = self::job_dir( $order->order_ref );
		if ( ! $dir ) {
			$result['error'] = 'Uploads directory is not writable.';
			return $result;
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			$result['error'] = 'Could not create the job folder.';
			return $result;
		}

		$csv  = self::manifest_csv( $order, $build );
		$html = self::packing_slip_html( $order, $build );
		$txt  = self::readme_txt( $order, $build );

		@file_put_contents( $dir . '/manifest.csv', "\xEF\xBB\xBF" . $csv );
		@file_put_contents( $dir . '/packing-slip.html', $html );
		@file_put_contents( $dir . '/README.txt', $txt );

		if ( ! self::has_zip() ) {
			$result['error'] = 'ZipArchive is not available on this server — the rendered files are on disk but could not be packaged.';
			return $result;
		}

		$zip_path = self::zip_path( $order->order_ref );
		if ( file_exists( $zip_path ) ) @unlink( $zip_path );

		$zip = new ZipArchive();
		if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
			$result['error'] = 'The ZIP file could not be created.';
			return $result;
		}

		$zip->addFromString( 'manifest.csv', "\xEF\xBB\xBF" . $csv );
		$zip->addFromString( 'packing-slip.html', $html );
		$zip->addFromString( 'README.txt', $txt );

		foreach ( (array) ( $build['files'] ?? array() ) as $f ) {
			if ( empty( $f['file'] ) ) continue;
			$path = $dir . '/print-files/' . $f['file'];
			if ( is_file( $path ) ) {
				$zip->addFile( $path, 'print-files/' . $f['file'] );
			}
		}
		$zip->close();

		if ( ! is_file( $zip_path ) ) {
			$result['error'] = 'The ZIP file could not be written.';
			return $result;
		}

		$result['zip']   = basename( $zip_path );
		$result['bytes'] = (int) @filesize( $zip_path );
		return $result;
	}

	// ── Signed links ───────────────────────────────────

	private static function sign( $order_ref, $purpose, $expires ) {
		return substr( hash_hmac( 'sha256', $order_ref . '|' . $purpose . '|' . $expires, wp_salt( 'auth' ) ), 0, 24 );
	}

	private static function verify_signature( $order_ref, $purpose, $expires, $token ) {
		$expires = (int) $expires;
		if ( $expires <= 0 || $expires < time() ) return false;
		return hash_equals( self::sign( $order_ref, $purpose, $expires ), (string) $token );
	}

	/** Signed, expiring ZIP link a provider can use without a WP login. */
	public static function signed_zip_url( $order_ref, $days = 0 ) {
		$days    = $days > 0 ? (int) $days : (int) self::get_settings()['link_expiry_days'];
		$expires = time() + ( $days * DAY_IN_SECONDS );
		return add_query_arg(
			array( 'exp' => $expires, 't' => self::sign( $order_ref, 'zip', $expires ) ),
			rest_url( 'tweller-flow-2/v1/prints/job/' . rawurlencode( $order_ref ) )
		);
	}

	/** Signed label URL — works from a phone, no admin session needed. */
	public static function label_url( $order_ref, $days = 0 ) {
		$days    = $days > 0 ? (int) $days : (int) self::get_settings()['link_expiry_days'];
		$expires = time() + ( $days * DAY_IN_SECONDS );
		return add_query_arg(
			array( 'exp' => $expires, 't' => self::sign( $order_ref, 'label', $expires ) ),
			rest_url( 'tweller-flow-2/v1/prints/label/' . rawurlencode( $order_ref ) )
		);
	}

	/** Barcode payload token — stable for the life of the order. */
	public static function delivery_token( $order_ref ) {
		return substr( hash_hmac( 'sha256', $order_ref . '|delivery', wp_salt( 'auth' ) ), 0, 16 );
	}

	/** The exact string encoded in the shipping-label barcode. */
	public static function scan_payload( $order_ref ) {
		return $order_ref . '|' . self::delivery_token( $order_ref );
	}

	// ── Code 128-B barcode (pure PHP, inline SVG) ──────

	/**
	 * Code 128 symbol widths, values 0–102 plus Start A/B/C (103/104/105)
	 * and Stop (106). Each data symbol is 3 bars + 3 spaces = 11 modules;
	 * the stop symbol is 13 modules.
	 */
	private static function code128_patterns() {
		return array(
			'212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
			'221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
			'221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
			'212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
			'231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
			'231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
			'314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
			'112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
			'111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
			'214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
			'114131','311141','411131','211412','211214','211232','2331112',
		);
	}

	/**
	 * Encode ASCII 32–126 in Code 128 subset B.
	 * Start B = 104, checksum = (104 + Σ value_i × position_i) mod 103,
	 * stop = 106 (pattern 2331112, ending in the termination bar).
	 *
	 * @return array{bars: array<int, array{x:int, w:int}>, modules:int, check:int}
	 */
	public static function code128b_bars( $text ) {
		$patterns = self::code128_patterns();

		$values = array( 104 );
		$len    = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = ord( $text[ $i ] );
			if ( $c < 32 || $c > 126 ) $c = 32;
			$values[] = $c - 32;
		}

		$sum   = 104;
		$count = count( $values );
		for ( $i = 1; $i < $count; $i++ ) {
			$sum += $values[ $i ] * $i;
		}
		$check    = $sum % 103;
		$values[] = $check;
		$values[] = 106;

		$bars = array();
		$x    = 0;
		foreach ( $values as $v ) {
			$pattern = $patterns[ $v ];
			$is_bar  = true;
			foreach ( str_split( $pattern ) as $w ) {
				$w = (int) $w;
				if ( $is_bar ) {
					$bars[] = array( 'x' => $x, 'w' => $w );
				}
				$x     += $w;
				$is_bar = ! $is_bar;
			}
		}

		return array( 'bars' => $bars, 'modules' => $x, 'check' => $check );
	}

	/** Inline SVG barcode — no external libraries, prints crisply. */
	public static function barcode_svg( $text, $height = 90, $module = 2 ) {
		$enc     = self::code128b_bars( $text );
		$module  = max( 1, (int) $module );
		$height  = max( 20, (int) $height );
		$width   = $enc['modules'] * $module;

		$rects = '';
		foreach ( $enc['bars'] as $bar ) {
			$rects .= '<rect x="' . ( (int) $bar['x'] * $module ) . '" y="0" width="' . ( (int) $bar['w'] * $module ) . '" height="' . $height . '"/>';
		}

		return '<svg class="tf2-barcode" xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" '
			. 'viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Order barcode" shape-rendering="crispEdges">'
			. '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" fill="#FFFFFF"/>'
			. '<g fill="#000000">' . $rects . '</g></svg>';
	}

	// ── Shipping label ─────────────────────────────────

	public static function render_label_html( $order ) {
		$fulfillment = self::get_fulfillment( $order );
		$items       = self::order_items( $order );

		$pieces = 0;
		foreach ( $items as $it ) { $pieces += max( 1, (int) ( $it['qty'] ?? 1 ) ); }

		$payload   = self::scan_payload( (string) $order->order_ref );
		$barcode   = self::barcode_svg( $payload, 78, 2 );
		$delivered = ! empty( $fulfillment['delivered_at'] );

		$address = trim( (string) $fulfillment['delivery_address'] );
		if ( $address === '' ) $address = 'No delivery address on file';

		$notes = trim( (string) $fulfillment['delivery_notes'] );

		ob_start();
		?><!DOCTYPE html>
<html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Label <?php echo esc_html( $order->order_ref ); ?></title>
<style>
	@page { size: 4in 6in; margin: 0; }
	* { box-sizing: border-box; }
	html, body { margin:0; padding:0; background:#EEE; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
	.label { width:4in; height:6in; background:#fff; margin:16px auto; padding:0.22in; display:flex; flex-direction:column; color:#101010; }
	.hdr { background:#101010; margin:-0.22in -0.22in 0.14in; padding:0.16in 0.22in; text-align:center; }
	.hdr .n { color:#fff; font-size:14px; font-weight:600; letter-spacing:7px; }
	.hdr .s { color:#C9A227; font-size:8px; font-weight:600; letter-spacing:4px; text-transform:uppercase; margin-top:3px; }
	.lbl { font-size:7.5px; letter-spacing:1.6px; text-transform:uppercase; color:#8A8178; margin:0 0 3px; }
	.to { font-size:15px; font-weight:700; line-height:1.25; margin:0 0 3px; }
	.addr { font-size:11.5px; line-height:1.5; color:#3D3630; white-space:pre-line; margin:0; }
	.phone { font-size:12px; font-weight:600; margin-top:5px; }
	.rule { border-top:1px solid #ECE9E2; margin:0.13in 0; }
	.row { display:flex; gap:0.18in; }
	.row > div { flex:1; }
	.big { font-size:13px; font-weight:700; letter-spacing:0.5px; }
	.notes { font-size:9.5px; color:#3D3630; line-height:1.45; margin:0.06in 0 0; }
	.bc { margin-top:auto; text-align:center; padding-top:0.1in; }
	.bc svg { width:100%; height:0.82in; display:block; }
	.tok { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:8.5px; letter-spacing:0.6px; color:#101010; margin-top:3px; word-break:break-all; }
	.hint { font-size:7.5px; color:#8A8178; margin-top:2px; }
	.done { position:absolute; }
	.stamp { display:inline-block; border:2px solid #065F46; color:#065F46; font-size:9px; font-weight:800; letter-spacing:2px; padding:2px 8px; border-radius:4px; text-transform:uppercase; }
	.actions { text-align:center; margin:14px auto 24px; }
	.actions button { background:#101010; color:#C9A227; border:0; border-radius:8px; padding:11px 26px; font-size:13px; font-weight:700; cursor:pointer; }
	@media print { html, body { background:#fff; } .label { margin:0; box-shadow:none; } .actions { display:none; } }
</style></head>
<body>
<div class="label">
	<div class="hdr"><div class="n">TWELLER</div><div class="s">Studios</div></div>

	<p class="lbl">Deliver to</p>
	<p class="to"><?php echo esc_html( $order->customer_name ); ?></p>
	<p class="addr"><?php echo esc_html( $address ); ?></p>
	<?php if ( ! empty( $order->customer_phone ) ) : ?>
		<p class="phone"><?php echo esc_html( $order->customer_phone ); ?></p>
	<?php endif; ?>

	<div class="rule"></div>

	<div class="row">
		<div>
			<p class="lbl">Order</p>
			<p class="big"><?php echo esc_html( $order->order_ref ); ?></p>
		</div>
		<div>
			<p class="lbl">Pieces</p>
			<p class="big"><?php echo (int) $pieces; ?></p>
		</div>
		<div>
			<p class="lbl">Date</p>
			<p class="big"><?php echo esc_html( date_i18n( 'j M Y', strtotime( (string) $order->created_at ) ) ); ?></p>
		</div>
	</div>

	<?php if ( $notes !== '' ) : ?>
		<p class="notes"><strong>Note:</strong> <?php echo esc_html( $notes ); ?></p>
	<?php endif; ?>
	<?php if ( $delivered ) : ?>
		<p class="notes"><span class="stamp">Delivered <?php echo esc_html( date_i18n( 'j M Y', strtotime( (string) $fulfillment['delivered_at'] ) ) ); ?></span></p>
	<?php endif; ?>

	<div class="bc">
		<?php echo $barcode; // phpcs:ignore WordPress.Security.EscapeOutput -- generated SVG, numeric attributes only ?>
		<p class="tok"><?php echo esc_html( $payload ); ?></p>
		<p class="hint">Scan on delivery to close this order</p>
	</div>
</div>

<div class="actions"><button type="button" onclick="window.print()">Print label</button></div>
</body></html>
		<?php
		return ob_get_clean();
	}

	// ── REST ───────────────────────────────────────────

	public static function register_rest_routes() {
		$ns = 'tweller-flow-2/v1';

		// Courier / mobile app scans the shipping-label barcode.
		register_rest_route( $ns, '/prints/scan', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_scan' ),
			'permission_callback' => array( 'TwellerFlow2_Photo_Automation', 'verify_api_key' ),
		) );

		// Printable 4x6 shipping label (admin, or signed link).
		register_rest_route( $ns, '/prints/label/(?P<order_ref>[A-Za-z0-9\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_label' ),
			'permission_callback' => '__return_true',
		) );

		// Provider ZIP download via signed, expiring link (no WP login).
		register_rest_route( $ns, '/prints/job/(?P<order_ref>[A-Za-z0-9\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_download_job' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * POST /prints/scan  { code: "TS-PRINT-XXXXXX|<16 hex>" }
	 * Idempotent: scanning twice never re-emails.
	 */
	public static function rest_scan( $request ) {
		$code = (string) $request->get_param( 'code' );
		$code = trim( preg_replace( '/\s+/', '', $code ) );
		if ( $code === '' ) {
			return new WP_Error( 'no_code', 'No barcode was supplied.', array( 'status' => 400 ) );
		}

		$parts = explode( '|', $code );
		if ( count( $parts ) !== 2 ) {
			return new WP_Error( 'bad_code', 'That barcode is not a Tweller delivery code.', array( 'status' => 400 ) );
		}

		$order_ref = strtoupper( sanitize_text_field( $parts[0] ) );
		$hmac      = strtolower( preg_replace( '/[^a-fA-F0-9]/', '', $parts[1] ) );

		$order = self::get_order_by_ref( $order_ref );
		if ( ! $order ) {
			return new WP_Error( 'not_found', 'No order matches that barcode.', array( 'status' => 404 ) );
		}
		if ( ! hash_equals( self::delivery_token( $order->order_ref ), $hmac ) ) {
			return new WP_Error( 'bad_signature', 'That barcode failed verification.', array( 'status' => 403 ) );
		}

		$fulfillment = self::get_fulfillment( $order );
		if ( ! empty( $fulfillment['delivered_at'] ) ) {
			return rest_ensure_response( array(
				'ok'                => true,
				'order_ref'         => $order->order_ref,
				'status'            => $order->status,
				'status_label'      => 'Delivered',
				'already_delivered' => true,
				'delivered_at'      => (string) $fulfillment['delivered_at'],
				'message'           => 'This order was already marked delivered on ' . date_i18n( 'j M Y g:ia', strtotime( (string) $fulfillment['delivered_at'] ) ) . '.',
			) );
		}

		// A studio API-key scan is attributed to the studio; the provider
		// portal scan endpoint attributes itself to the provider account.
		$result   = self::mark_delivered( $order, 'scan', array( 'type' => 'studio', 'id' => get_current_user_id() ) );
		$provider = self::get_provider( $fulfillment['provider_id'] );

		return rest_ensure_response( array(
			'ok'                => true,
			'order_ref'         => $order->order_ref,
			'status'            => 'completed',
			'status_label'      => 'Delivered',
			'already_delivered' => false,
			'delivered_at'      => $result['delivered_at'],
			'notified'          => (bool) $result['notified'],
			'scanned_by'        => 'studio',
			'provider_id'       => $provider ? $provider['id'] : '',
			'provider_name'     => $provider ? $provider['name'] : '',
			'message'           => 'Order ' . $order->order_ref . ' marked delivered.',
		) );
	}

	/**
	 * Close the order out: status completed (shown as "Delivered"),
	 * timestamp, customer email, and a hook for anything else.
	 */
	public static function mark_delivered( $order, $via = 'scan', $actor = array() ) {
		$fulfillment = self::get_fulfillment( $order );
		if ( ! empty( $fulfillment['delivered_at'] ) ) {
			return array( 'delivered_at' => (string) $fulfillment['delivered_at'], 'notified' => false, 'already' => true );
		}

		$actor_type = ( is_array( $actor ) && ( $actor['type'] ?? '' ) === 'provider' ) ? 'provider' : 'studio';
		$actor_id   = is_array( $actor ) ? (int) ( $actor['id'] ?? 0 ) : 0;

		$now = current_time( 'mysql' );
		$fulfillment['delivered_at']  = $now;
		$fulfillment['delivered_via'] = sanitize_key( $via );
		$fulfillment['delivered_by']  = array( 'actor_type' => $actor_type, 'actor_id' => $actor_id );
		$fulfillment['audit'][]       = array(
			'at'         => $now,
			'actor_type' => $actor_type,
			'actor_id'   => $actor_id,
			'action'     => 'delivered',
			'meta'       => array( 'via' => sanitize_key( $via ) ),
		);
		self::save_fulfillment( $order->id, $fulfillment );

		$notified = false;
		if ( class_exists( 'TwellerFlow2_Prints' ) && method_exists( 'TwellerFlow2_Prints', 'update_status' ) ) {
			$has_email = method_exists( 'TwellerFlow2_Prints', 'send_status_email' );
			TwellerFlow2_Prints::update_status( $order->id, 'completed', $has_email );
			$notified = $has_email;
		} else {
			global $wpdb;
			$table = self::orders_table();
			if ( $table ) {
				$wpdb->update( $table, array( 'status' => 'completed', 'updated_at' => $now ), array( 'id' => (int) $order->id ) );
			}
		}

		if ( ! $notified ) {
			$notified = self::send_delivered_email( self::get_order( $order->id ) );
		}

		do_action( 'tweller_print_order_delivered', $order->order_ref, $via, array( 'actor_type' => $actor_type, 'actor_id' => $actor_id ) );

		return array( 'delivered_at' => $now, 'notified' => (bool) $notified, 'already' => false );
	}

	/** Fallback delivered email using the shared branded helpers. */
	private static function send_delivered_email( $order ) {
		if ( ! $order || empty( $order->customer_email ) || ! class_exists( 'TwellerFlow2_Notifications' ) ) return false;

		$first = trim( (string) strtok( trim( (string) $order->customer_name ), ' ' ) );
		$rows  = TwellerFlow2_Notifications::email_detail_row( 'Order', esc_html( $order->order_ref ) );
		$rows .= TwellerFlow2_Notifications::email_detail_row( 'Delivered', esc_html( date_i18n( 'F j, Y', current_time( 'timestamp' ) ) ) );

		$body = "
			<h2 style='color:#101010; font-weight:600;'>Delivered</h2>
			<p style='color:#3D3630;'>Hi " . esc_html( $order->customer_name ) . ",</p>
			<p style='color:#3D3630; line-height:1.7;'>Your order <strong>" . esc_html( $order->order_ref ) . "</strong> has been delivered. We hope they look beautiful.</p>
			" . TwellerFlow2_Notifications::email_card( 'Delivery', $rows ) . "
			<p style='color:#3D3630;'>Warm regards,<br><strong>The Tweller Studios Team</strong></p>
		";

		return TwellerFlow2_Notifications::send_raw( $order->customer_email, "Your prints have been delivered, {$first}", $body );
	}

	/** GET /prints/label/{order_ref} — admin cookie auth OR signed token. */
	public static function rest_label( $request ) {
		$order_ref = strtoupper( sanitize_text_field( (string) $request['order_ref'] ) );
		$exp       = (int) $request->get_param( 'exp' );
		$token     = sanitize_text_field( (string) $request->get_param( 't' ) );

		$allowed = current_user_can( 'manage_options' ) || self::verify_signature( $order_ref, 'label', $exp, $token );
		if ( ! $allowed ) {
			return new WP_Error( 'forbidden', 'This label link is invalid or has expired.', array( 'status' => 403 ) );
		}

		$order = self::get_order_by_ref( $order_ref );
		if ( ! $order ) {
			return new WP_Error( 'not_found', 'Order not found.', array( 'status' => 404 ) );
		}

		$html = self::render_label_html( $order );

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			nocache_headers();
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- fully built + escaped above
		exit;
	}

	/** GET /prints/job/{order_ref} — signed ZIP download for a provider. */
	public static function rest_download_job( $request ) {
		$order_ref = strtoupper( sanitize_text_field( (string) $request['order_ref'] ) );
		$exp       = (int) $request->get_param( 'exp' );
		$token     = sanitize_text_field( (string) $request->get_param( 't' ) );

		$allowed = current_user_can( 'manage_options' ) || self::verify_signature( $order_ref, 'zip', $exp, $token );
		if ( ! $allowed ) {
			return new WP_Error( 'forbidden', 'This download link is invalid or has expired.', array( 'status' => 403 ) );
		}

		$order = self::get_order_by_ref( $order_ref );
		if ( ! $order ) {
			return new WP_Error( 'not_found', 'Order not found.', array( 'status' => 404 ) );
		}

		self::stream_zip( $order->order_ref );
		return null; // stream_zip exits
	}

	private static function stream_zip( $order_ref ) {
		$path = self::zip_path( $order_ref );
		$real = $path ? realpath( $path ) : false;
		$base = self::jobs_basedir();

		if ( ! $real || ! $base || strpos( wp_normalize_path( $real ), trailingslashit( $base ) ) !== 0 || ! is_file( $real ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'The print package for this order has not been built yet.' ), '', array( 'response' => 404 ) );
		}

		if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 0 );
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $real ) . '"' );
		header( 'Content-Length: ' . filesize( $real ) );
		header( 'X-Robots-Tag: noindex, nofollow' );

		while ( ob_get_level() > 0 ) { ob_end_clean(); }
		readfile( $real );
		exit;
	}

	// ── Provider email ─────────────────────────────────

	public static function send_to_provider( $order_id, $provider_id, $note = '' ) {
		$order = self::get_order( $order_id );
		if ( ! $order ) return new WP_Error( 'not_found', 'Order not found.' );

		$provider = self::get_provider( $provider_id );
		if ( ! $provider || ! is_email( $provider['email'] ) ) {
			return new WP_Error( 'bad_provider', 'That provider has no valid email address.' );
		}
		if ( ! class_exists( 'TwellerFlow2_Notifications' ) ) {
			return new WP_Error( 'no_mailer', 'The notification system is unavailable.' );
		}

		$fulfillment = self::get_fulfillment( $order );
		$build       = $fulfillment['build'];
		if ( ! is_array( $build ) || empty( $build['done'] ) ) {
			return new WP_Error( 'not_built', 'Build the print files before sending this order to a provider.' );
		}

		$settings = self::get_settings();
		$stats    = self::build_stats( $build );
		$expires  = time() + ( (int) $settings['link_expiry_days'] * DAY_IN_SECONDS );
		$zip_url  = add_query_arg(
			array( 'exp' => $expires, 't' => self::sign( $order->order_ref, 'zip', $expires ) ),
			rest_url( 'tweller-flow-2/v1/prints/job/' . rawurlencode( $order->order_ref ) )
		);

		// WHITE LABEL: no customer identity anywhere in this email.
		$rows  = '';
		foreach ( (array) $build['files'] as $f ) {
			if ( ! empty( $f['error'] ) ) continue;
			$flag = '';
			if ( ! empty( $f['very_low_dpi'] ) ) {
				$flag = " <span style='background:#FEE2E2; color:#B91C1C; font-size:10px; font-weight:700; padding:1px 5px; border-radius:4px;'>LOW RES</span>";
			}
			$rows .= "
				<tr>
					<td style='padding:7px 10px 7px 0; border-bottom:1px solid #ECE9E2; font-size:12.5px; color:#101010;'>"
						. esc_html( $f['product_name'] ) . "<br><span style='color:#8A8178; font-size:11px;'>" . esc_html( $f['file'] ) . "</span></td>
					<td style='padding:7px 10px; border-bottom:1px solid #ECE9E2; font-size:12.5px; color:#3D3630; white-space:nowrap;'>"
						. esc_html( self::size_label( $f['width_in'], $f['height_in'] ) ) . "&Prime;</td>
					<td style='padding:7px 10px; border-bottom:1px solid #ECE9E2; font-size:12.5px; color:#3D3630; white-space:nowrap;'>"
						. (int) $f['dpi'] . " dpi{$flag}</td>
					<td style='padding:7px 0; border-bottom:1px solid #ECE9E2; text-align:right; font-weight:700; font-size:13px; color:#101010;'>&times; "
						. (int) $f['qty'] . "</td>
				</tr>";
		}

		$meta  = TwellerFlow2_Notifications::email_detail_row( 'Job reference', esc_html( $order->order_ref ) );
		$meta .= TwellerFlow2_Notifications::email_detail_row( 'Files', (int) $stats['files'] );
		$meta .= TwellerFlow2_Notifications::email_detail_row( 'Total pieces', (int) $stats['pieces'] );
		$meta .= TwellerFlow2_Notifications::email_detail_row( 'Finish', esc_html( $settings['paper_finish'] ) );
		$meta .= TwellerFlow2_Notifications::email_detail_row( 'Download expires', esc_html( date_i18n( 'F j, Y', $expires ) ) );

		$note_block = '';
		if ( trim( (string) $note ) !== '' ) {
			$note_block = TwellerFlow2_Notifications::email_card( 'Note for this job', "<p style='margin:0; color:#3D3630; line-height:1.7;'>" . nl2br( esc_html( $note ) ) . "</p>" );
		}

		$spec = "<p style='margin:0; color:#3D3630; line-height:1.7; font-size:13.5px;'>"
			. 'Every file is pre-cropped to the exact aspect ratio of its size and supplied at ' . (int) self::TARGET_DPI . ' DPI where the source allows &mdash; '
			. 'sRGB, 8-bit JPEG, quality ' . (int) self::JPEG_QUALITY . ', no crop marks burned in. '
			. 'Quantities are in each filename and in <strong>manifest.csv</strong>. Please print as supplied &mdash; no re-cropping, rotation or auto-enhancement.'
			. '</p>';

		$instructions = "<p style='margin:0; color:#3D3630; line-height:1.7; font-size:13.5px;'>" . nl2br( esc_html( $settings['provider_instructions'] ) ) . '</p>';
		if ( trim( (string) $settings['return_address'] ) !== '' ) {
			$instructions .= "<p style='margin:12px 0 0; color:#3D3630; line-height:1.7; font-size:13.5px;'><strong>Return to:</strong><br>" . nl2br( esc_html( $settings['return_address'] ) ) . '</p>';
		}

		$greeting = $provider['contact_name'] !== '' ? 'Hi ' . esc_html( $provider['contact_name'] ) . ',' : 'Hello,';

		$body = "
			<h2 style='color:#101010; font-weight:600;'>Print job " . esc_html( $order->order_ref ) . "</h2>
			<p style='color:#3D3630;'>{$greeting}</p>
			<p style='color:#3D3630; line-height:1.7;'>Please find our print job below &mdash; <strong>" . (int) $stats['pieces'] . " piece" . ( (int) $stats['pieces'] === 1 ? '' : 's' ) . "</strong> across " . (int) $stats['files'] . " file" . ( (int) $stats['files'] === 1 ? '' : 's' ) . ". The ZIP contains the print-ready files, a packing slip and a CSV manifest.</p>

			" . TwellerFlow2_Notifications::email_card( 'Job Summary', $meta ) . "

			<div style='text-align:center; margin:28px 0;'>
				" . TwellerFlow2_Notifications::email_button( esc_url( $zip_url ), 'Download print files (ZIP)' ) . "
			</div>

			" . TwellerFlow2_Notifications::email_card( 'Items', "<table style='width:100%; border-collapse:collapse;'>{$rows}</table>" ) . "
			{$note_block}
			" . TwellerFlow2_Notifications::email_card( 'File Specification', $spec ) . "
			" . TwellerFlow2_Notifications::email_card( 'Instructions', $instructions ) . "

			<p style='color:#3D3630;'>Thank you,<br><strong>Tweller Studios</strong></p>
		";

		$subject = 'Print job ' . $order->order_ref . ' — ' . (int) $stats['pieces'] . ' piece' . ( (int) $stats['pieces'] === 1 ? '' : 's' ) . ' — Tweller Studios';
		$sent    = TwellerFlow2_Notifications::send_raw( $provider['email'], $subject, $body );

		$user = wp_get_current_user();
		$fulfillment['sends'][] = array(
			'provider_id'   => $provider['id'],
			'provider_name' => $provider['name'],
			'email'         => $provider['email'],
			'sent_at'       => current_time( 'mysql' ),
			'note'          => sanitize_textarea_field( (string) $note ),
			'by'            => $user && $user->exists() ? $user->display_name : '',
			'pieces'        => (int) $stats['pieces'],
			'expires'       => $expires,
			'sent'          => $sent ? 1 : 0,
		);

		// The job is now this provider's responsibility — that assignment
		// is what the provider portal filters on.
		$fulfillment['provider_id'] = $provider['id'];
		$fulfillment['assigned_at'] = current_time( 'mysql' );
		$fulfillment['audit'][]     = array(
			'at'         => current_time( 'mysql' ),
			'actor_type' => 'studio',
			'actor_id'   => ( $user && $user->exists() ) ? (int) $user->ID : 0,
			'action'     => 'sent_to_provider',
			'meta'       => array( 'provider' => $provider['id'] ),
		);
		self::save_fulfillment( $order->id, $fulfillment );

		do_action( 'tweller_print_order_assigned', (int) $order->id, $provider['id'] );

		if ( ! $sent ) {
			return new WP_Error( 'send_failed', 'The email to ' . $provider['name'] . ' could not be sent — check the SMTP settings.' );
		}
		return true;
	}

	// ── Admin menu + actions ───────────────────────────

	public static function register_admin_menu() {
		add_submenu_page(
			'tweller-flow-2',
			'Print Providers',
			'Print Providers',
			'manage_options',
			self::PROVIDERS_PAGE,
			array( __CLASS__, 'page_providers' )
		);
	}

	public static function page_providers() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.' ) );
		}
		$providers = self::get_providers();
		$settings  = self::get_settings();
		include TWELLER_FLOW_2_PLUGIN_DIR . 'admin/views/print-providers.php';
	}

	private static function orders_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::ADMIN_PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	public static function build_url( $order_id, $force = false ) {
		$args = array( 'tf2pf' => 'build', 'order_id' => (int) $order_id );
		if ( $force ) $args['force'] = 1;
		return wp_nonce_url( self::orders_url( $args ), 'tf2pf_build_' . (int) $order_id );
	}

	public static function download_url( $order_id ) {
		return wp_nonce_url(
			self::orders_url( array( 'tf2pf' => 'download', 'order_id' => (int) $order_id ) ),
			'tf2pf_download_' . (int) $order_id
		);
	}

	public static function handle_admin_actions() {
		if ( ! is_admin() ) return;

		$action = isset( $_GET['tf2pf'] ) ? sanitize_key( wp_unslash( $_GET['tf2pf'] ) ) : '';

		// ── Build print files (chunked) ──
		if ( $action === 'build' ) {
			if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Permission denied.' ) );
			$order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
			check_admin_referer( 'tf2pf_build_' . $order_id );

			$force = ! empty( $_GET['force'] );
			$build = self::build_chunk( $order_id, $force );

			$args = array( 'order_id' => $order_id );
			if ( empty( $build['done'] ) ) {
				$args['fp_building'] = 1;
				$args['fp_done']     = (int) ( $build['next'] ?? 0 );
				$args['fp_total']    = (int) ( $build['total'] ?? 0 );
			} else {
				$args['fp_built'] = 1;
			}
			wp_safe_redirect( self::orders_url( $args ) );
			exit;
		}

		// ── Download the ZIP (admin) ──
		if ( $action === 'download' ) {
			if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Permission denied.' ) );
			$order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
			check_admin_referer( 'tf2pf_download_' . $order_id );

			$order = self::get_order( $order_id );
			if ( ! $order ) wp_die( esc_html__( 'Order not found.' ) );
			self::stream_zip( $order->order_ref );
			exit;
		}

		// ── Save delivery address ──
		if ( isset( $_POST['tf2pf_save_delivery'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Permission denied.' ) );
			check_admin_referer( 'tf2pf_save_delivery' );

			$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
			$order    = self::get_order( $order_id );
			if ( $order ) {
				$f = self::get_fulfillment( $order );
				$f['delivery_address'] = sanitize_textarea_field( wp_unslash( $_POST['delivery_address'] ?? '' ) );
				$f['delivery_notes']   = sanitize_textarea_field( wp_unslash( $_POST['delivery_notes'] ?? '' ) );
				self::save_fulfillment( $order_id, $f );
			}
			wp_safe_redirect( self::orders_url( array( 'order_id' => $order_id, 'fp_saved' => 1 ) ) );
			exit;
		}

		// ── Send to provider ──
		if ( isset( $_POST['tf2pf_send_provider'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Permission denied.' ) );
			check_admin_referer( 'tf2pf_send_provider' );

			$order_id    = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
			$provider_id = sanitize_key( wp_unslash( $_POST['provider_id'] ?? '' ) );
			$note        = sanitize_textarea_field( wp_unslash( $_POST['provider_note'] ?? '' ) );

			$result = self::send_to_provider( $order_id, $provider_id, $note );
			$args   = array( 'order_id' => $order_id );
			if ( is_wp_error( $result ) ) {
				$args['fp_error'] = rawurlencode( $result->get_error_message() );
			} else {
				$args['fp_sent'] = 1;
			}
			wp_safe_redirect( self::orders_url( $args ) );
			exit;
		}

		// ── Mark delivered from the desk (no scanner to hand) ──
		if ( isset( $_POST['tf2pf_mark_delivered'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Permission denied.' ) );
			check_admin_referer( 'tf2pf_mark_delivered' );

			$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
			$order    = self::get_order( $order_id );
			if ( $order ) self::mark_delivered( $order, 'admin' );

			wp_safe_redirect( self::orders_url( array( 'order_id' => $order_id, 'fp_delivered' => 1 ) ) );
			exit;
		}

		// ── Save providers + fulfilment settings ──
		if ( isset( $_POST['tf2pf_save_providers'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Permission denied.' ) );
			check_admin_referer( 'tf2pf_save_providers' );

			$default_id = sanitize_key( wp_unslash( $_POST['default_provider'] ?? '' ) );
			$providers  = array();
			$used       = array();

			foreach ( (array) ( $_POST['pv'] ?? array() ) as $row ) {
				if ( ! is_array( $row ) ) continue;
				$name = sanitize_text_field( wp_unslash( $row['name'] ?? '' ) );
				if ( $name === '' ) continue;

				$id = sanitize_key( wp_unslash( $row['id'] ?? '' ) );
				if ( $id === '' || strpos( $id, 'new' ) === 0 ) {
					$id = sanitize_key( substr( 'pv_' . sanitize_title( $name ), 0, 40 ) );
				}
				if ( $id === '' ) $id = 'pv_' . substr( md5( $name . microtime() ), 0, 8 );
				$base = $id;
				$i    = 2;
				while ( isset( $used[ $id ] ) ) { $id = $base . '_' . $i++; }
				$used[ $id ] = true;

				// Preserve fields this legacy form does not post (linked
				// portal account, address, delivery responsibility) so a
				// save here can never silently unlink a provider account.
				$prev = self::get_provider( $id );

				$providers[] = array(
					'id'            => $id,
					'name'          => $name,
					'contact_name'  => sanitize_text_field( wp_unslash( $row['contact_name'] ?? '' ) ),
					'email'         => sanitize_email( wp_unslash( $row['email'] ?? '' ) ),
					'phone'         => sanitize_text_field( wp_unslash( $row['phone'] ?? '' ) ),
					'address'       => isset( $row['address'] )
						? sanitize_textarea_field( wp_unslash( $row['address'] ) )
						: (string) ( $prev['address'] ?? '' ),
					'notes'         => sanitize_textarea_field( wp_unslash( $row['notes'] ?? '' ) ),
					'active'        => empty( $row['active'] ) ? 0 : 1,
					'default'       => 0,
					'user_id'       => isset( $row['user_id'] ) ? (int) $row['user_id'] : (int) ( $prev['user_id'] ?? 0 ),
					'does_delivery' => isset( $row['does_delivery'] )
						? ( empty( $row['does_delivery'] ) ? 0 : 1 )
						: (int) ( $prev['does_delivery'] ?? 0 ),
				);
			}

			// Exactly one default, and only among active providers.
			$set = false;
			foreach ( $providers as &$p ) {
				if ( ! $set && $p['id'] === $default_id && $p['active'] ) { $p['default'] = 1; $set = true; }
			}
			unset( $p );
			if ( ! $set ) {
				foreach ( $providers as &$p2 ) {
					if ( $p2['active'] ) { $p2['default'] = 1; break; }
				}
				unset( $p2 );
			}

			update_option( self::OPT_PROVIDERS, $providers );
			update_option( self::OPT_SETTINGS, array(
				'provider_instructions' => sanitize_textarea_field( wp_unslash( $_POST['pf_instructions'] ?? '' ) ),
				'return_address'        => sanitize_textarea_field( wp_unslash( $_POST['pf_return_address'] ?? '' ) ),
				'paper_finish'          => sanitize_text_field( wp_unslash( $_POST['pf_paper_finish'] ?? '' ) ),
				'link_expiry_days'      => max( 1, min( 90, (int) ( $_POST['pf_link_expiry_days'] ?? self::LINK_TTL_DAYS ) ) ),
			) );

			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PROVIDERS_PAGE . '&saved=1' ) );
			exit;
		}
	}

	// ── Admin UI (rendered into prints-orders.php) ─────

	/** Page-level notices + the auto-continue driver for chunked builds. */
	public static function render_page_notices() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		if ( ! empty( $_GET['fp_saved'] ) ) {
			echo '<div class="tf2-alert tf2-alert--success">Delivery details saved.</div>';
		}
		if ( ! empty( $_GET['fp_sent'] ) ) {
			echo '<div class="tf2-alert tf2-alert--success">Order sent to the print provider.</div>';
		}
		if ( ! empty( $_GET['fp_built'] ) ) {
			echo '<div class="tf2-alert tf2-alert--success">Print files built and packaged.</div>';
		}
		if ( ! empty( $_GET['fp_delivered'] ) ) {
			echo '<div class="tf2-alert tf2-alert--success">Order marked delivered.</div>';
		}
		if ( ! empty( $_GET['fp_error'] ) ) {
			echo '<div class="tf2-alert tf2-alert--error">' . esc_html( rawurldecode( wp_unslash( $_GET['fp_error'] ) ) ) . '</div>';
		}

		if ( empty( $_GET['fp_building'] ) ) return;

		$order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
		if ( ! $order_id ) return;

		$done  = isset( $_GET['fp_done'] ) ? (int) $_GET['fp_done'] : 0;
		$total = isset( $_GET['fp_total'] ) ? max( 1, (int) $_GET['fp_total'] ) : 1;
		$pct   = min( 100, (int) round( ( $done / $total ) * 100 ) );
		$next  = self::build_url( $order_id );
		?>
		<div class="tf2-alert" style="background:#FDFAF1; border:1px solid #C9A227; color:#3D3630;">
			<strong>Building print files…</strong> <?php echo (int) $done; ?> of <?php echo (int) $total; ?> items rendered.
			<div style="height:8px; background:#ECE9E2; border-radius:4px; margin:8px 0 6px; overflow:hidden;">
				<div style="height:100%; width:<?php echo (int) $pct; ?>%; background:#C9A227;"></div>
			</div>
			<a href="<?php echo esc_url( $next ); ?>" id="tf2pf-continue" class="tf2-btn tf2-btn--secondary tf2-btn--sm">Continue</a>
			<span style="color:#8A8178; font-size:12px;">Keep this tab open — it continues automatically.</span>
		</div>
		<script>
		(function(){
			var a = document.getElementById('tf2pf-continue');
			if (a) { setTimeout(function(){ window.location.href = a.href; }, 400); }
		})();
		</script>
		<?php
	}

	private static function print_panel_css() {
		static $done = false;
		if ( $done ) return;
		$done = true;
		?>
		<style>
		.tf2pf { border-top:1px solid #EEF0F3; margin-top:14px; padding-top:12px; }
		.tf2pf h4 { margin:0 0 8px; }
		.tf2pf__grid { display:flex; flex-wrap:wrap; gap:16px; }
		.tf2pf__col { flex:1 1 260px; min-width:240px; }
		.tf2pf__stat { display:flex; gap:14px; flex-wrap:wrap; margin:0 0 8px; }
		.tf2pf__stat span { font-size:12px; color:#6B7280; }
		.tf2pf__stat strong { display:block; font-size:15px; color:#111827; }
		.tf2pf__warn { background:#FEF2F2; border:1px solid #FECACA; color:#B91C1C; border-radius:8px; padding:8px 10px; font-size:12.5px; margin:8px 0; }
		.tf2pf__soft { background:#FFFBEB; border:1px solid #FDE68A; color:#92400E; border-radius:8px; padding:8px 10px; font-size:12.5px; margin:8px 0; }
		.tf2pf__ok { background:#ECFDF5; border:1px solid #A7F3D0; color:#065F46; border-radius:8px; padding:8px 10px; font-size:12.5px; margin:8px 0; }
		.tf2pf__files { width:100%; border-collapse:collapse; font-size:12px; margin-top:6px; }
		.tf2pf__files td { padding:4px 6px 4px 0; border-bottom:1px solid #F3F4F6; vertical-align:top; }
		.tf2pf__files .n { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:11px; color:#374151; word-break:break-all; }
		.tf2pf__sends { list-style:none; margin:6px 0 0; padding:0; font-size:12px; color:#4B5563; }
		.tf2pf__sends li { padding:4px 0; border-bottom:1px solid #F3F4F6; }
		.tf2pf textarea, .tf2pf select, .tf2pf input[type=text] { width:100%; max-width:100%; }
		.tf2pf__delivered { background:#101010; color:#C9A227; border-radius:8px; padding:8px 10px; font-size:12.5px; font-weight:700; margin:8px 0; }
		</style>
		<?php
	}

	/**
	 * Fulfilment panel for one order. Called from admin/views/prints-orders.php.
	 * Nothing here is ever exposed to the customer.
	 */
	public static function render_order_panel( $order ) {
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( ! is_object( $order ) || empty( $order->order_ref ) ) return;

		self::print_panel_css();

		$fulfillment = self::get_fulfillment( $order );
		$build       = $fulfillment['build'];
		$items       = self::order_items( $order );
		$hash_now    = self::items_hash( $order );
		$is_stale    = is_array( $build ) && ( $build['hash'] ?? '' ) !== $hash_now;
		$is_built    = is_array( $build ) && ! empty( $build['done'] ) && ! $is_stale;
		$stats       = self::build_stats( $build );
		$providers   = self::get_providers( true );
		$default     = self::get_default_provider();
		$delivered   = ! empty( $fulfillment['delivered_at'] );

		$ordered_pieces = 0;
		foreach ( $items as $it ) { $ordered_pieces += max( 1, (int) ( $it['qty'] ?? 1 ) ); }
		?>
		<div class="tf2pf">
			<h4>Fulfilment</h4>

			<?php if ( $delivered ) : ?>
				<div class="tf2pf__delivered">
					&#10003; Delivered <?php echo esc_html( date_i18n( 'M j, Y g:ia', strtotime( (string) $fulfillment['delivered_at'] ) ) ); ?>
					<?php if ( $fulfillment['delivered_via'] === 'scan' ) : ?>(barcode scan)<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="tf2pf__grid">

				<!-- ── Print files ── -->
				<div class="tf2pf__col">
					<div class="tf2pf__stat">
						<span>Pieces ordered<strong><?php echo (int) $ordered_pieces; ?></strong></span>
						<?php if ( $is_built ) : ?>
							<span>Files built<strong><?php echo (int) $stats['files']; ?></strong></span>
							<span>Pieces packaged<strong><?php echo (int) $stats['pieces']; ?></strong></span>
						<?php endif; ?>
					</div>

					<?php if ( $is_built && $stats['very_low'] > 0 ) : ?>
						<div class="tf2pf__warn">
							<strong><?php echo (int) $stats['very_low']; ?> file<?php echo $stats['very_low'] === 1 ? '' : 's'; ?> below <?php echo (int) self::LOW_DPI; ?> DPI.</strong>
							These will look soft at the ordered size — consider a smaller size or a better source file.
						</div>
					<?php endif; ?>
					<?php if ( $is_built && $stats['low'] > 0 ) : ?>
						<div class="tf2pf__soft"><?php echo (int) $stats['low']; ?> file<?php echo $stats['low'] === 1 ? '' : 's'; ?> between <?php echo (int) self::LOW_DPI; ?> and <?php echo (int) self::TARGET_DPI; ?> DPI — printable, just under the lab standard.</div>
					<?php endif; ?>
					<?php if ( $is_built && $stats['errors'] > 0 ) : ?>
						<div class="tf2pf__warn">
							<strong><?php echo (int) $stats['errors']; ?> item<?php echo $stats['errors'] === 1 ? '' : 's'; ?> could not be rendered:</strong>
							<ul style="margin:6px 0 0 16px;">
								<?php foreach ( (array) $build['errors'] as $err ) : ?>
									<li><?php echo esc_html( trim( ( $err['product'] ?? '' ) . ' ' . ( $err['file'] ?? '' ) ) ); ?> — <?php echo esc_html( $err['message'] ?? '' ); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
					<?php if ( $is_built && $stats['errors'] === 0 && $stats['very_low'] === 0 && $stats['low'] === 0 ) : ?>
						<div class="tf2pf__ok">All files are print-ready at <?php echo (int) self::TARGET_DPI; ?> DPI, sRGB.</div>
					<?php endif; ?>
					<?php if ( $is_stale ) : ?>
						<div class="tf2pf__soft">This order changed since the files were built — rebuild before sending.</div>
					<?php endif; ?>

					<p style="margin:8px 0;">
						<a href="<?php echo esc_url( self::build_url( (int) $order->id, $is_built || $is_stale ) ); ?>" class="tf2-btn tf2-btn--primary tf2-btn--sm">
							<?php echo $is_built && ! $is_stale ? 'Rebuild print files' : 'Build print files'; ?>
						</a>
						<?php if ( $is_built && ! empty( $build['zip'] ) ) : ?>
							<a href="<?php echo esc_url( self::download_url( (int) $order->id ) ); ?>" class="tf2-btn tf2-btn--secondary tf2-btn--sm">
								Download ZIP<?php if ( ! empty( $build['zip_bytes'] ) ) : ?> (<?php echo esc_html( size_format( (int) $build['zip_bytes'] ) ); ?>)<?php endif; ?>
							</a>
						<?php endif; ?>
					</p>

					<?php if ( $is_built && ! empty( $build['files'] ) ) : ?>
						<table class="tf2pf__files">
							<?php foreach ( (array) $build['files'] as $f ) : ?>
								<?php if ( ! empty( $f['error'] ) ) continue; ?>
								<tr>
									<td class="n"><?php echo esc_html( $f['file'] ); ?></td>
									<td style="white-space:nowrap; text-align:right;">
										<?php echo (int) $f['out_w']; ?>&times;<?php echo (int) $f['out_h']; ?>px<br>
										<span style="color:<?php echo ! empty( $f['very_low_dpi'] ) ? '#B91C1C' : ( ! empty( $f['low_dpi'] ) ? '#92400E' : '#065F46' ); ?>;">
											<?php echo (int) $f['dpi']; ?> dpi
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>
					<?php endif; ?>
				</div>

				<!-- ── Provider ── -->
				<div class="tf2pf__col">
					<p style="margin:0 0 6px; font-size:12px; color:#6B7280;"><strong>Send to a print provider</strong></p>
					<?php if ( empty( $providers ) ) : ?>
						<p style="font-size:12.5px; color:#6B7280;">
							No active providers yet —
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PROVIDERS_PAGE ) ); ?>">add one</a>.
						</p>
					<?php else : ?>
						<form method="post" style="display:flex; flex-direction:column; gap:6px;">
							<?php wp_nonce_field( 'tf2pf_send_provider' ); ?>
							<input type="hidden" name="tf2pf_send_provider" value="1">
							<input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
							<select name="provider_id">
								<?php foreach ( $providers as $p ) : ?>
									<option value="<?php echo esc_attr( $p['id'] ); ?>" <?php selected( $default && $default['id'] === $p['id'] ); ?>>
										<?php echo esc_html( $p['name'] ); ?><?php echo ! empty( $p['default'] ) ? ' (default)' : ''; ?>
									</option>
								<?php endforeach; ?>
							</select>
							<textarea name="provider_note" rows="2" placeholder="Optional note for this job (turnaround, finish, packing…)"></textarea>
							<button type="submit" class="tf2-btn tf2-btn--sm tf2-po-confirmpay" <?php disabled( ! $is_built ); ?>
								onclick="return confirm('Send <?php echo esc_js( $order->order_ref ); ?> to the selected provider?');">
								&#9993; Send order to provider
							</button>
							<?php if ( ! $is_built ) : ?>
								<span style="font-size:11.5px; color:#9CA3AF;">Build the print files first.</span>
							<?php endif; ?>
						</form>
					<?php endif; ?>

					<?php if ( ! empty( $fulfillment['sends'] ) ) : ?>
						<p style="margin:12px 0 0; font-size:12px; color:#6B7280;"><strong>Send history</strong></p>
						<ul class="tf2pf__sends">
							<?php foreach ( array_reverse( (array) $fulfillment['sends'] ) as $s ) : ?>
								<li>
									<strong><?php echo esc_html( $s['provider_name'] ?? '' ); ?></strong>
									· <?php echo esc_html( date_i18n( 'M j, Y g:ia', strtotime( (string) ( $s['sent_at'] ?? '' ) ) ) ); ?>
									<?php if ( empty( $s['sent'] ) ) : ?><span style="color:#B91C1C;"> · failed</span><?php endif; ?>
									<?php if ( ! empty( $s['note'] ) ) : ?><br><span style="color:#9CA3AF;"><?php echo esc_html( $s['note'] ); ?></span><?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>

				<!-- ── Delivery + label ── -->
				<div class="tf2pf__col">
					<form method="post" style="display:flex; flex-direction:column; gap:6px;">
						<?php wp_nonce_field( 'tf2pf_save_delivery' ); ?>
						<input type="hidden" name="tf2pf_save_delivery" value="1">
						<input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
						<label style="font-size:12px; color:#6B7280;"><strong>Delivery address</strong></label>
						<textarea name="delivery_address" rows="3" placeholder="Street, town, Trinidad &amp; Tobago"><?php echo esc_textarea( (string) $fulfillment['delivery_address'] ); ?></textarea>
						<label style="font-size:12px; color:#6B7280;">Delivery note (printed on the label)</label>
						<input type="text" name="delivery_notes" value="<?php echo esc_attr( (string) $fulfillment['delivery_notes'] ); ?>" placeholder="e.g. call on arrival">
						<button type="submit" class="tf2-btn tf2-btn--secondary tf2-btn--sm">Save delivery details</button>
					</form>

					<p style="margin:10px 0 0;">
						<a href="<?php echo esc_url( self::label_url( (string) $order->order_ref ) ); ?>" target="_blank" rel="noopener" class="tf2-btn tf2-btn--secondary tf2-btn--sm">
							&#128465; Print shipping label
						</a>
					</p>
					<p style="margin:6px 0 0; font-size:11.5px; color:#9CA3AF;">
						4&times;6in label with a scannable Code&nbsp;128 barcode. Scanning it marks the order delivered and emails the client.
					</p>

					<?php if ( ! $delivered ) : ?>
						<form method="post" style="margin-top:8px;">
							<?php wp_nonce_field( 'tf2pf_mark_delivered' ); ?>
							<input type="hidden" name="tf2pf_mark_delivered" value="1">
							<input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
							<button type="submit" class="tf2-btn tf2-btn--ghost tf2-btn--sm"
								onclick="return confirm('Mark <?php echo esc_js( $order->order_ref ); ?> delivered and email the client?');">
								Mark delivered manually
							</button>
						</form>
					<?php endif; ?>
				</div>

			</div>
		</div>
		<?php
	}
}
