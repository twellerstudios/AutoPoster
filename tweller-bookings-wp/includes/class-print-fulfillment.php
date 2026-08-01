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

	/**
	 * Deferred build continuation.
	 *
	 * Assignment is a decision and happens instantly; building the files is a
	 * slow process. The two are deliberately decoupled — see assign_provider()
	 * — and this cron hook is what carries a half-built job to completion.
	 */
	const CRON_BUILD = 'tweller_print_build_continue';

	/** Seconds one continuation pass may spend rendering. */
	const CONTINUE_SECONDS = 18;

	/** Throttle for the admin-page-load fallback that backs up WP-Cron. */
	const RESUME_LOCK = 'tweller_print_resume_lock';

	// ── Bootstrap ──────────────────────────────────────

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 21 );
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_actions' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'admin_notices', array( __CLASS__, 'capability_notice' ) );

		// WP-Cron carries a queued build to completion…
		add_action( self::CRON_BUILD, array( __CLASS__, 'cron_continue_build' ) );
		// …and, because WP-Cron never fires on a quiet site (the same lesson
		// the Google contacts sync learned), every admin page load re-checks
		// for a queued build and pushes it along. Belt and braces.
		add_action( 'admin_init', array( __CLASS__, 'resume_pending_builds' ), 20 );

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
			'label_printed_at' => '', // studio opened the 4x6 shipping label
			// ── Provider portal ──
			'provider_id'      => '',   // lab currently responsible for the job
			'assigned_at'      => '',
			'assign_note'      => '',   // note captured at assignment, emailed with the package
			// ── Deferred build ──
			// A job may be assigned long before its files exist. These three
			// fields are the whole state machine for "we still owe this lab a
			// package": queued → building → sent, or → failed (never silent).
			'send_pending'     => 0,    // 1 = email the package as soon as the build finishes
			'build_error'      => '',   // last hard failure, surfaced in admin + emailed
			'build_queued_at'  => '',
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

	/**
	 * The single source of truth for "where is this order in fulfilment?".
	 *
	 * Only `delivered_at` ever produces a delivered reading. An order that has
	 * merely been assigned to a lab reports "Sent to {lab} — awaiting
	 * production", never "Delivered": handing the job over does not complete it.
	 *
	 * Every stage also carries `provider_id` / `provider_name`, so every
	 * surface (orders list, panel, app, emails) can answer "who is this with?"
	 * from this one call rather than growing a second stage vocabulary.
	 *
	 * @return array{key:string, label:string, short:string, tone:string, provider_id:string, provider_name:string}
	 */
	public static function fulfillment_stage( $order ) {
		$f    = self::get_fulfillment( $order );
		$name = self::provider_name_for( $order, $f );
		$id   = sanitize_key( (string) $f['provider_id'] );

		// Only used for wording; never let it leak as a real provider name.
		$who = $name !== '' ? $name : 'the provider';

		$build = is_array( $f['build'] ) ? $f['build'] : null;
		$built = $build && ! empty( $build['done'] );

		$stage = function ( $key, $label, $short, $tone ) use ( $id, $name ) {
			return array(
				'key'           => $key,
				'label'         => $label,
				'short'         => $short,
				'tone'          => $tone,
				'provider_id'   => $id,
				'provider_name' => $name,
			);
		};

		if ( (string) $f['delivered_at'] !== '' ) {
			return $stage(
				'delivered',
				'Delivered ' . date_i18n( 'M j, Y g:ia', strtotime( (string) $f['delivered_at'] ) )
					. ( $f['delivered_via'] === 'scan' ? ' (label scan)' : '' ),
				'Delivered',
				'done'
			);
		}
		if ( (string) $f['shipped_at'] !== '' ) {
			return $stage(
				'shipped',
				'Shipped by ' . $who . ' ' . date_i18n( 'M j', strtotime( (string) $f['shipped_at'] ) ) . ' — awaiting delivery scan',
				'Shipped by ' . $who . ' — awaiting delivery',
				'active'
			);
		}
		if ( (string) $f['ready_at'] !== '' ) {
			return $stage(
				'ready',
				$who . ' marked this job ready ' . date_i18n( 'M j', strtotime( (string) $f['ready_at'] ) ) . ' — awaiting collection',
				'Ready — ' . $who,
				'active'
			);
		}
		if ( (string) $f['started_at'] !== '' ) {
			return $stage(
				'printing',
				$who . ' started printing ' . date_i18n( 'M j', strtotime( (string) $f['started_at'] ) ),
				'In printing — ' . $who,
				'active'
			);
		}
		if ( ! empty( $f['sends'] ) || $id !== '' ) {
			// Assigned. The files may still be building behind it — that is a
			// property of the package, not a different place in fulfilment, so
			// it is spelled out in the label rather than inventing a stage.
			$suffix = '';
			if ( (string) $f['build_error'] !== '' ) {
				$suffix = ' — files failed to build';
			} elseif ( ! empty( $f['send_pending'] ) ) {
				$suffix = ' — print files still building';
			}
			return $stage(
				'sent',
				'Sent to ' . $who . ' — awaiting production' . $suffix,
				'Sent to ' . $who . ' — awaiting production' . $suffix,
				'active'
			);
		}
		if ( $built ) {
			return $stage(
				'built',
				'Print files built — not sent to a provider yet',
				'Files built — no lab assigned',
				'idle'
			);
		}
		return $stage(
			'none',
			'Print files have not been built yet',
			'Not started — no lab assigned',
			'idle'
		);
	}

	/**
	 * Display name of the lab an order sits with ('' when unassigned).
	 * Falls back to the last send record so legacy orders still read right.
	 */
	public static function provider_name_for( $order, $f = null ) {
		if ( ! is_array( $f ) ) $f = self::get_fulfillment( $order );

		$prov = self::get_provider( (string) $f['provider_id'] );
		if ( $prov && $prov['name'] !== '' ) return $prov['name'];

		if ( ! empty( $f['sends'] ) ) {
			$last = end( $f['sends'] );
			if ( is_array( $last ) && (string) ( $last['provider_name'] ?? '' ) !== '' ) {
				return (string) $last['provider_name'];
			}
		}
		// Assigned to an id we no longer have a record for — never silently
		// report "unassigned" when the JSON says otherwise.
		return (string) $f['provider_id'] !== '' ? (string) $f['provider_id'] : '';
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

	/**
	 * Printable lines only. Fee lines (delivery, meet-up, crop service) are
	 * real money on the order but have no photo, so they must never reach
	 * the renderer, the manifest, the piece counts or the ZIP.
	 */
	private static function printable_items( $order ) {
		$items = self::order_items( $order );
		if ( class_exists( 'TwellerFlow2_Prints' ) ) {
			return TwellerFlow2_Prints::printable_items( $items );
		}
		$out = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['category'] ) && $item['category'] === 'service' ) continue;
			$out[] = $item;
		}
		return array_values( $out );
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
		$base_real = $base ? realpath( $base ) : false;
		if ( $base_real ) $base = wp_normalize_path( $base_real );
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
		$items = self::printable_items( $order );
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
		$items       = self::printable_items( $order );
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

	// ── QR code (dependency-free, inline SVG) ──────────
	//
	// Byte mode, error-correction level M, versions 1–10 (payloads up to
	// 213 bytes — the delivery payload is ~30–60). Phone cameras and the
	// browser BarcodeDetector API read a QR far more reliably than a thin
	// 1-D barcode, so this is the primary mark on the shipping label.
	// No composer package, no external library, no remote API.

	/** GF(256) exp/log tables for the Reed–Solomon maths (primitive 0x11D). */
	private static function qr_gf() {
		static $tables = null;
		if ( $tables !== null ) return $tables;

		$exp = array_fill( 0, 512, 0 );
		$log = array_fill( 0, 256, 0 );
		$x   = 1;
		for ( $i = 0; $i < 255; $i++ ) {
			$exp[ $i ] = $x;
			$log[ $x ] = $i;
			$x <<= 1;
			if ( $x & 0x100 ) $x ^= 0x11D;
		}
		for ( $i = 255; $i < 512; $i++ ) $exp[ $i ] = $exp[ $i - 255 ];

		$tables = array( $exp, $log );
		return $tables;
	}

	/** Multiply two GF(256) values. */
	private static function qr_mul( $a, $b ) {
		$a = (int) $a;
		$b = (int) $b;
		if ( $a === 0 || $b === 0 ) return 0;
		list( $exp, $log ) = self::qr_gf();
		return $exp[ $log[ $a ] + $log[ $b ] ];
	}

	/**
	 * Reed–Solomon block layout at error-correction level M.
	 * version => array( ec_codewords_per_block, array( array( blocks, data_codewords ), … ) )
	 */
	private static function qr_blocks( $version ) {
		$table = array(
			1  => array( 10, array( array( 1, 16 ) ) ),
			2  => array( 16, array( array( 1, 28 ) ) ),
			3  => array( 26, array( array( 1, 44 ) ) ),
			4  => array( 18, array( array( 2, 32 ) ) ),
			5  => array( 24, array( array( 2, 43 ) ) ),
			6  => array( 16, array( array( 4, 27 ) ) ),
			7  => array( 18, array( array( 4, 31 ) ) ),
			8  => array( 22, array( array( 2, 38 ), array( 2, 39 ) ) ),
			9  => array( 22, array( array( 3, 36 ), array( 2, 37 ) ) ),
			10 => array( 26, array( array( 4, 43 ), array( 1, 44 ) ) ),
		);
		return isset( $table[ $version ] ) ? $table[ $version ] : null;
	}

	/** Alignment-pattern centre coordinates per version. */
	private static function qr_alignment( $version ) {
		$table = array(
			1  => array(),
			2  => array( 6, 18 ),
			3  => array( 6, 22 ),
			4  => array( 6, 26 ),
			5  => array( 6, 30 ),
			6  => array( 6, 34 ),
			7  => array( 6, 22, 38 ),
			8  => array( 6, 24, 42 ),
			9  => array( 6, 26, 46 ),
			10 => array( 6, 28, 50 ),
		);
		return isset( $table[ $version ] ) ? $table[ $version ] : array();
	}

	/** Total data codewords available at level M for a version. */
	private static function qr_data_codewords( $version ) {
		$spec = self::qr_blocks( $version );
		if ( ! $spec ) return 0;
		$total = 0;
		foreach ( $spec[1] as $group ) $total += $group[0] * $group[1];
		return $total;
	}

	/** Smallest version that fits $len bytes; 0 when the payload is too long. */
	private static function qr_version( $len ) {
		for ( $v = 1; $v <= 10; $v++ ) {
			$count_bits = ( $v < 10 ) ? 8 : 16;
			if ( ( $len * 8 ) + 4 + $count_bits <= self::qr_data_codewords( $v ) * 8 ) return $v;
		}
		return 0;
	}

	/** Generator polynomial of the given degree (highest coefficient first). */
	private static function qr_rs_generator( $degree ) {
		list( $exp, ) = self::qr_gf();
		$g = array( 1 );
		for ( $i = 0; $i < $degree; $i++ ) {
			$next = array_fill( 0, count( $g ) + 1, 0 );
			foreach ( $g as $j => $coef ) {
				$next[ $j ]     ^= $coef;
				$next[ $j + 1 ] ^= self::qr_mul( $coef, $exp[ $i ] );
			}
			$g = $next;
		}
		return $g;
	}

	/** Reed–Solomon error-correction codewords for one data block. */
	private static function qr_rs_ec( $data, $degree ) {
		$g   = self::qr_rs_generator( $degree );
		$n   = count( $data );
		$res = array_merge( array_values( $data ), array_fill( 0, $degree, 0 ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$coef = $res[ $i ];
			if ( $coef === 0 ) continue;
			for ( $j = 1; $j <= $degree; $j++ ) {
				$res[ $i + $j ] ^= self::qr_mul( $g[ $j ], $coef );
			}
		}
		return array_slice( $res, $n, $degree );
	}

	/** Mode indicator + character count + payload, as a bit string. */
	private static function qr_bit_stream( $text, $version ) {
		$len   = strlen( $text );
		$count = ( $version < 10 ) ? 8 : 16;
		$bits  = '0100' . str_pad( decbin( $len ), $count, '0', STR_PAD_LEFT );
		for ( $i = 0; $i < $len; $i++ ) {
			$bits .= str_pad( decbin( ord( $text[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}
		return $bits;
	}

	/** Final, interleaved codeword sequence (data blocks then EC blocks). */
	private static function qr_codewords( $text, $version ) {
		$spec = self::qr_blocks( $version );
		if ( ! $spec ) return array();
		list( $ec_per_block, $groups ) = $spec;

		$total_data = self::qr_data_codewords( $version );
		$capacity   = $total_data * 8;

		$bits = self::qr_bit_stream( $text, $version );
		$bits .= str_repeat( '0', max( 0, min( 4, $capacity - strlen( $bits ) ) ) );
		if ( strlen( $bits ) % 8 !== 0 ) {
			$bits .= str_repeat( '0', 8 - ( strlen( $bits ) % 8 ) );
		}

		$data = array();
		foreach ( str_split( $bits, 8 ) as $byte ) $data[] = bindec( $byte );
		$pad = array( 0xEC, 0x11 );
		$i   = 0;
		while ( count( $data ) < $total_data ) {
			$data[] = $pad[ $i % 2 ];
			$i++;
		}

		$blocks   = array();
		$ecs      = array();
		$pos      = 0;
		$max_data = 0;
		foreach ( $groups as $group ) {
			for ( $b = 0; $b < $group[0]; $b++ ) {
				$block    = array_slice( $data, $pos, $group[1] );
				$pos     += $group[1];
				$blocks[] = $block;
				$ecs[]    = self::qr_rs_ec( $block, $ec_per_block );
				if ( $group[1] > $max_data ) $max_data = $group[1];
			}
		}

		$out = array();
		for ( $i = 0; $i < $max_data; $i++ ) {
			foreach ( $blocks as $block ) {
				if ( isset( $block[ $i ] ) ) $out[] = $block[ $i ];
			}
		}
		for ( $i = 0; $i < $ec_per_block; $i++ ) {
			foreach ( $ecs as $ec ) $out[] = $ec[ $i ];
		}
		return $out;
	}

	/**
	 * Finder, separator, timing, alignment, dark-module and version patterns.
	 * Returns array( matrix, function-module mask, size ).
	 */
	private static function qr_function_matrix( $version ) {
		$size = 17 + 4 * $version;
		$m    = array_fill( 0, $size, array_fill( 0, $size, 0 ) );
		$fn   = array_fill( 0, $size, array_fill( 0, $size, 0 ) );

		// Finder patterns and their separators.
		foreach ( array( array( 0, 0 ), array( 0, $size - 7 ), array( $size - 7, 0 ) ) as $origin ) {
			for ( $dr = -1; $dr <= 7; $dr++ ) {
				for ( $dc = -1; $dc <= 7; $dc++ ) {
					$r = $origin[0] + $dr;
					$c = $origin[1] + $dc;
					if ( $r < 0 || $c < 0 || $r >= $size || $c >= $size ) continue;
					$in_eye = ( $dr >= 0 && $dr <= 6 && $dc >= 0 && $dc <= 6 );
					$dark   = $in_eye && ( $dr === 0 || $dr === 6 || $dc === 0 || $dc === 6
						|| ( $dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4 ) );
					$m[ $r ][ $c ]  = $dark ? 1 : 0;
					$fn[ $r ][ $c ] = 1;
				}
			}
		}

		// Timing patterns.
		for ( $i = 8; $i < $size - 8; $i++ ) {
			$bit = ( $i % 2 === 0 ) ? 1 : 0;
			$m[6][ $i ]  = $bit;
			$fn[6][ $i ] = 1;
			$m[ $i ][6]  = $bit;
			$fn[ $i ][6] = 1;
		}

		// Alignment patterns (skipping the three that collide with finders).
		$pos  = self::qr_alignment( $version );
		$last = count( $pos ) - 1;
		foreach ( $pos as $i => $r ) {
			foreach ( $pos as $j => $c ) {
				if ( ( $i === 0 && $j === 0 ) || ( $i === 0 && $j === $last ) || ( $i === $last && $j === 0 ) ) continue;
				for ( $dr = -2; $dr <= 2; $dr++ ) {
					for ( $dc = -2; $dc <= 2; $dc++ ) {
						$m[ $r + $dr ][ $c + $dc ]  = ( max( abs( $dr ), abs( $dc ) ) === 1 ) ? 0 : 1;
						$fn[ $r + $dr ][ $c + $dc ] = 1;
					}
				}
			}
		}

		// Format-information areas are reserved now, written per mask later.
		for ( $i = 0; $i <= 8; $i++ ) {
			if ( $i !== 6 ) {
				$fn[ $i ][8] = 1;
				$fn[8][ $i ] = 1;
			}
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$fn[8][ $size - 1 - $i ] = 1;
			$fn[ $size - 1 - $i ][8] = 1;
		}
		$m[ $size - 8 ][8]  = 1; // always-dark module
		$fn[ $size - 8 ][8] = 1;

		// Version information (versions 7 and up).
		if ( $version >= 7 ) {
			$rem = $version;
			for ( $i = 0; $i < 12; $i++ ) {
				$rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
			}
			$vbits = ( $version << 12 ) | ( $rem & 0xFFF );
			for ( $i = 0; $i < 18; $i++ ) {
				$bit = ( $vbits >> $i ) & 1;
				$a   = $size - 11 + ( $i % 3 );
				$b   = intdiv( $i, 3 );
				$m[ $b ][ $a ]  = $bit;
				$fn[ $b ][ $a ] = 1;
				$m[ $a ][ $b ]  = $bit;
				$fn[ $a ][ $b ] = 1;
			}
		}

		return array( $m, $fn, $size );
	}

	/** Zig-zag placement of the codeword bit stream around the function patterns. */
	private static function qr_place_data( &$m, $fn, $size, $codewords ) {
		$bits = '';
		foreach ( $codewords as $cw ) {
			$bits .= str_pad( decbin( (int) $cw ), 8, '0', STR_PAD_LEFT );
		}
		$len = strlen( $bits );
		$idx = 0;
		$dir = -1;
		$row = $size - 1;

		for ( $col = $size - 1; $col > 0; $col -= 2 ) {
			if ( $col === 6 ) $col = 5; // skip the vertical timing column
			while ( true ) {
				for ( $i = 0; $i < 2; $i++ ) {
					$c = $col - $i;
					if ( empty( $fn[ $row ][ $c ] ) ) {
						$m[ $row ][ $c ] = ( $idx < $len && $bits[ $idx ] === '1' ) ? 1 : 0;
						$idx++;
					}
				}
				$row += $dir;
				if ( $row < 0 || $row >= $size ) {
					$row -= $dir;
					$dir  = -$dir;
					break;
				}
			}
		}
	}

	/** The eight standard data-mask conditions. */
	private static function qr_mask_bit( $mask, $r, $c ) {
		switch ( (int) $mask ) {
			case 0: return ( ( $r + $c ) % 2 ) === 0;
			case 1: return ( $r % 2 ) === 0;
			case 2: return ( $c % 3 ) === 0;
			case 3: return ( ( $r + $c ) % 3 ) === 0;
			case 4: return ( ( intdiv( $r, 2 ) + intdiv( $c, 3 ) ) % 2 ) === 0;
			case 5: return ( ( ( $r * $c ) % 2 ) + ( ( $r * $c ) % 3 ) ) === 0;
			case 6: return ( ( ( ( $r * $c ) % 2 ) + ( ( $r * $c ) % 3 ) ) % 2 ) === 0;
			default: return ( ( ( ( $r + $c ) % 2 ) + ( ( $r * $c ) % 3 ) ) % 2 ) === 0;
		}
	}

	/** 15-bit BCH format information for EC level M and the chosen mask. */
	private static function qr_format_bits( $mask ) {
		$data = ( 0x00 << 3 ) | ( (int) $mask & 7 ); // level M = 0b00
		$rem  = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
		}
		return ( ( $data << 10 ) | ( $rem & 0x3FF ) ) ^ 0x5412;
	}

	private static function qr_write_format( &$m, $size, $mask ) {
		$bits = self::qr_format_bits( $mask );

		for ( $i = 0; $i <= 5; $i++ )  $m[ $i ][8] = ( $bits >> $i ) & 1;
		$m[7][8] = ( $bits >> 6 ) & 1;
		$m[8][8] = ( $bits >> 7 ) & 1;
		$m[8][7] = ( $bits >> 8 ) & 1;
		for ( $i = 9; $i < 15; $i++ ) $m[8][ 14 - $i ] = ( $bits >> $i ) & 1;

		for ( $i = 0; $i < 8; $i++ )  $m[8][ $size - 1 - $i ] = ( $bits >> $i ) & 1;
		for ( $i = 8; $i < 15; $i++ ) $m[ $size - 15 + $i ][8] = ( $bits >> $i ) & 1;

		$m[ $size - 8 ][8] = 1; // always dark
	}

	/** Standard mask-selection penalty (rules 1–4). Lower is better. */
	private static function qr_penalty( $m, $size ) {
		$score = 0;

		for ( $pass = 0; $pass < 2; $pass++ ) {
			for ( $i = 0; $i < $size; $i++ ) {
				$line = array();
				for ( $j = 0; $j < $size; $j++ ) {
					$line[] = ( $pass === 0 ) ? $m[ $i ][ $j ] : $m[ $j ][ $i ];
				}

				// Rule 1 — runs of five or more identical modules.
				$run = 1;
				for ( $j = 1; $j < $size; $j++ ) {
					if ( $line[ $j ] === $line[ $j - 1 ] ) {
						$run++;
					} else {
						if ( $run >= 5 ) $score += 3 + ( $run - 5 );
						$run = 1;
					}
				}
				if ( $run >= 5 ) $score += 3 + ( $run - 5 );

				// Rule 3 — finder-like 1:1:3:1:1 patterns with a light margin.
				$s = implode( '', $line );
				$score += 40 * substr_count( $s, '10111010000' );
				$score += 40 * substr_count( $s, '00001011101' );
			}
		}

		// Rule 2 — 2x2 blocks of one colour.
		for ( $r = 0; $r < $size - 1; $r++ ) {
			for ( $c = 0; $c < $size - 1; $c++ ) {
				$v = $m[ $r ][ $c ];
				if ( $v === $m[ $r ][ $c + 1 ] && $v === $m[ $r + 1 ][ $c ] && $v === $m[ $r + 1 ][ $c + 1 ] ) {
					$score += 3;
				}
			}
		}

		// Rule 4 — deviation from a 50% dark ratio.
		$dark = 0;
		for ( $r = 0; $r < $size; $r++ ) $dark += array_sum( $m[ $r ] );
		$total = $size * $size;
		$score += 10 * intdiv( (int) abs( ( $dark * 20 ) - ( $total * 10 ) ), $total );

		return $score;
	}

	/**
	 * Full QR matrix for $text: byte mode, level M, best of the eight masks.
	 * @return array{size:int, version:int, matrix:array<int, array<int,int>>}|null
	 */
	private static function qr_matrix( $text ) {
		$text    = (string) $text;
		$version = self::qr_version( strlen( $text ) );
		if ( $version < 1 ) return null;

		$codewords = self::qr_codewords( $text, $version );
		list( $base, $fn, $size ) = self::qr_function_matrix( $version );
		self::qr_place_data( $base, $fn, $size, $codewords );

		$best       = null;
		$best_score = null;
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$candidate = $base;
			for ( $r = 0; $r < $size; $r++ ) {
				for ( $c = 0; $c < $size; $c++ ) {
					if ( empty( $fn[ $r ][ $c ] ) && self::qr_mask_bit( $mask, $r, $c ) ) {
						$candidate[ $r ][ $c ] ^= 1;
					}
				}
			}
			self::qr_write_format( $candidate, $size, $mask );

			$score = self::qr_penalty( $candidate, $size );
			if ( $best_score === null || $score < $best_score ) {
				$best_score = $score;
				$best       = $candidate;
			}
		}

		return array( 'size' => $size, 'version' => $version, 'matrix' => $best );
	}

	/**
	 * Inline SVG QR code, quiet zone included. Returns '' when the payload
	 * is longer than a version-10 symbol can carry (213 bytes).
	 *
	 * @param string $text Payload to encode.
	 * @param int    $px   Rendered edge length in CSS pixels.
	 */
	public static function qr_svg( $text, $px = 180 ) {
		$qr = self::qr_matrix( $text );
		if ( ! $qr ) return '';

		$size  = (int) $qr['size'];
		$quiet = 4; // the specification's minimum quiet zone
		$dim   = $size + ( 2 * $quiet );
		$px    = max( 60, (int) $px );

		// One rect per horizontal run of dark modules keeps the SVG small.
		$rects = '';
		for ( $r = 0; $r < $size; $r++ ) {
			$c = 0;
			while ( $c < $size ) {
				if ( empty( $qr['matrix'][ $r ][ $c ] ) ) { $c++; continue; }
				$start = $c;
				while ( $c < $size && ! empty( $qr['matrix'][ $r ][ $c ] ) ) $c++;
				$rects .= '<rect x="' . ( $start + $quiet ) . '" y="' . ( $r + $quiet ) . '" width="' . ( $c - $start ) . '" height="1"/>';
			}
		}

		return '<svg class="tf2-qr" xmlns="http://www.w3.org/2000/svg" width="' . $px . '" height="' . $px . '" '
			. 'viewBox="0 0 ' . $dim . ' ' . $dim . '" role="img" aria-label="Order QR code" shape-rendering="crispEdges">'
			. '<rect x="0" y="0" width="' . $dim . '" height="' . $dim . '" fill="#FFFFFF"/>'
			. '<g fill="#000000">' . $rects . '</g></svg>';
	}

	// ── Shipping label ─────────────────────────────────

	public static function render_label_html( $order ) {
		$fulfillment = self::get_fulfillment( $order );
		$items       = self::printable_items( $order );

		$pieces = 0;
		foreach ( $items as $it ) { $pieces += max( 1, (int) ( $it['qty'] ?? 1 ) ); }

		$payload   = self::scan_payload( (string) $order->order_ref );
		$qr        = self::qr_svg( $payload, 180 );
		// The QR is the primary mark; the 1-D code stays only as a small
		// secondary strip for desk scanners that expect a linear symbol.
		$barcode   = self::barcode_svg( $payload, 40, 1 );
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
	.label { width:4in; height:6in; overflow:hidden; background:#fff; margin:16px auto; padding:0.22in; display:flex; flex-direction:column; color:#101010; }
	.hdr { background:#101010; margin:-0.22in -0.22in 0.14in; padding:0.16in 0.22in; text-align:center; }
	.hdr .n { color:#fff; font-size:14px; font-weight:600; letter-spacing:7px; }
	.hdr .s { color:#C9A227; font-size:8px; font-weight:600; letter-spacing:4px; text-transform:uppercase; margin-top:3px; }
	.lbl { font-size:7.5px; letter-spacing:1.6px; text-transform:uppercase; color:#8A8178; margin:0 0 3px; }
	.to { font-size:15px; font-weight:700; line-height:1.25; margin:0 0 3px; }
	.addr { font-size:11.5px; line-height:1.5; color:#3D3630; white-space:pre-line; margin:0; }
	.phone { font-size:12px; font-weight:600; margin-top:5px; }
	.rule { border-top:1px solid #ECE9E2; margin:0.11in 0; }
	.row { display:flex; gap:0.18in; }
	.row > div { flex:1; }
	.big { font-size:13px; font-weight:700; letter-spacing:0.5px; }
	.notes { font-size:9.5px; color:#3D3630; line-height:1.45; margin:0.06in 0 0; }
	.mark { margin-top:auto; flex:0 0 auto; text-align:center; padding-top:0.08in; }
	.mark .qr { width:1.8in; height:1.8in; display:block; margin:0 auto; }
	.ref { font-size:19px; font-weight:800; letter-spacing:1.2px; margin:4px 0 1px; line-height:1.1; }
	.tok { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:8px; letter-spacing:0.4px; color:#3D3630; margin:0; word-break:break-all; }
	.bc { margin-top:5px; }
	.bc svg { width:100%; height:0.26in; display:block; }
	.hint { font-size:7.5px; color:#8A8178; margin-top:3px; }
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

	<div class="mark">
		<?php if ( $qr !== '' ) : ?>
			<?php echo str_replace( 'class="tf2-qr"', 'class="qr"', $qr ); // phpcs:ignore WordPress.Security.EscapeOutput -- generated SVG, numeric attributes only ?>
		<?php endif; ?>
		<p class="ref"><?php echo esc_html( $order->order_ref ); ?></p>
		<p class="tok"><?php echo esc_html( $payload ); ?></p>
		<div class="bc"><?php echo $barcode; // phpcs:ignore WordPress.Security.EscapeOutput -- generated SVG, numeric attributes only ?></div>
		<p class="hint">Scan the QR code on delivery to close this order</p>
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

		// A scanned label carries "REF|hmac". Typing the order reference by
		// hand is the natural fallback, and this endpoint is already behind
		// the studio API key, so the signature adds nothing there — accept
		// a bare reference too rather than rejecting the obvious input.
		$parts = explode( '|', $code );
		if ( count( $parts ) === 1 ) {
			$parts[] = '';
		}
		if ( count( $parts ) !== 2 ) {
			return new WP_Error( 'bad_code', 'That barcode is not a Tweller delivery code.', array( 'status' => 400 ) );
		}

		$order_ref = strtoupper( sanitize_text_field( $parts[0] ) );
		$hmac      = strtolower( preg_replace( '/[^a-fA-F0-9]/', '', $parts[1] ) );

		$order = self::get_order_by_ref( $order_ref );
		if ( ! $order ) {
			return new WP_Error( 'not_found', 'No order matches that barcode.', array( 'status' => 404 ) );
		}
		if ( $hmac !== '' && ! hash_equals( self::delivery_token( $order->order_ref ), $hmac ) ) {
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

		// A delivery scan closing the job is a provider transition the studio
		// wants to hear about; the studio's own "mark delivered" click is not.
		self::notify_studio_provider_update( self::get_order( $order->id ), 'delivered', $actor_type );

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
		// Resolve the base the same way as the file. realpath() follows
		// symlinks, and on hosts where wp-content/uploads is symlinked an
		// un-resolved base never prefix-matches a resolved file — which
		// rejected packages that had in fact been built.
		$base = self::jobs_basedir();
		$base_real = $base ? realpath( $base ) : false;
		if ( $base_real ) $base = wp_normalize_path( $base_real );

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

	// ── Assignment (instant) ───────────────────────────

	/**
	 * Assign an order to a lab. This is a DECISION, not a process: it is
	 * instant, it never waits on the file builder, and once it has run the
	 * order counts everywhere that reads the assignment — the provider portal,
	 * provider_stats(), economics_summary(), the orders list and the app.
	 *
	 * Coupling this to a completed build is what let a 116-photo order report
	 * success from the app while wp-admin showed "No provider assigned yet":
	 * the build could not finish inside the request, so the assignment that
	 * lived on the far side of it never happened.
	 *
	 * Idempotent: re-assigning the same lab records nothing new and sends no
	 * second email. Refuses outright on a delivered or cancelled order.
	 *
	 * @param int    $order_id
	 * @param string $provider_id
	 * @param string $note Optional note carried into the lab's job email.
	 * @return array|WP_Error {provider_id, provider_name, changed, reassigned, previous_id}
	 */
	public static function assign_provider( $order_id, $provider_id, $note = '' ) {
		$order = self::get_order( (int) $order_id );
		if ( ! $order ) return new WP_Error( 'not_found', 'Order not found.' );

		$provider = self::get_provider( $provider_id );
		if ( ! $provider ) {
			return new WP_Error( 'bad_provider', 'That print provider does not exist.' );
		}

		$f = self::get_fulfillment( $order );

		// (f) A closed order must never be re-opened by an assignment — that
		// would corrupt the delivered/cancelled record and the payout figures
		// derived from it.
		if ( (string) $f['delivered_at'] !== '' ) {
			return new WP_Error(
				'already_delivered',
				'Order ' . $order->order_ref . ' has already been delivered — it cannot be reassigned to ' . $provider['name'] . '.'
			);
		}
		if ( (string) $order->status === 'cancelled' ) {
			return new WP_Error(
				'cancelled',
				'Order ' . $order->order_ref . ' is cancelled — it cannot be assigned to a print provider.'
			);
		}

		$previous   = sanitize_key( (string) $f['provider_id'] );
		$changed    = ( $previous !== $provider['id'] );
		$reassigned = ( $changed && $previous !== '' );

		$note = sanitize_textarea_field( (string) $note );
		if ( $note !== '' ) $f['assign_note'] = $note;

		if ( ! $changed ) {
			// Nothing moved. Keep the record honest and stay quiet.
			if ( $note !== '' ) self::save_fulfillment( (int) $order->id, $f );
			return array(
				'provider_id'   => $provider['id'],
				'provider_name' => $provider['name'],
				'changed'       => false,
				'reassigned'    => false,
				'previous_id'   => $previous,
			);
		}

		$user = wp_get_current_user();
		$now  = current_time( 'mysql' );

		$f['provider_id'] = $provider['id'];
		$f['assigned_at'] = $now;

		// (e) A different lab is a genuine transition: audit where it moved
		// from as well as where it moved to.
		$meta = array( 'provider' => $provider['id'] );
		if ( $reassigned ) {
			$prev_record       = self::get_provider( $previous );
			$meta['from']      = $previous;
			$meta['from_name'] = $prev_record ? $prev_record['name'] : $previous;
		}
		$f['audit'][] = array(
			'at'         => $now,
			'actor_type' => 'studio',
			'actor_id'   => ( $user && $user->exists() ) ? (int) $user->ID : 0,
			'action'     => $reassigned ? 'reassigned_provider' : 'assigned_provider',
			'meta'       => array_map( 'sanitize_text_field', $meta ),
		);

		self::save_fulfillment( (int) $order->id, $f );

		do_action( 'tweller_print_order_assigned', (int) $order->id, $provider['id'] );

		self::send_studio_assigned_email( self::get_order( (int) $order->id ), $provider, $reassigned ? $previous : '' );

		return array(
			'provider_id'   => $provider['id'],
			'provider_name' => $provider['name'],
			'changed'       => true,
			'reassigned'    => $reassigned,
			'previous_id'   => $previous,
		);
	}

	/**
	 * The one entry point every "send this order to a lab" surface uses —
	 * wp-admin, the app, the session screen. Assignment happens first and
	 * always; the package follows when (or as soon as) the files exist.
	 *
	 * Handles every permutation:
	 *   (a) provider + files built   → assign, email the lab the package
	 *   (b) provider + files pending → assign now, keep building, email later
	 *   (c) no provider              → nothing assigned, said plainly
	 *   (d) build failure            → stays assigned, flagged + emailed
	 *   (e) different lab            → reassign, audit, notify
	 *   (f) delivered / cancelled    → refused with a clear error
	 *
	 * @param int    $order_id
	 * @param string $provider_id '' = create the order only.
	 * @param string $note
	 * @param float  $budget Seconds this request may spend building.
	 * @return array|WP_Error {state, message, provider_id, provider_name, built, pieces}
	 */
	public static function assign_and_send( $order_id, $provider_id, $note = '', $budget = 0 ) {
		$provider_id = sanitize_key( (string) $provider_id );

		// (c) No lab chosen — an unassigned order, and we say so.
		if ( $provider_id === '' ) {
			return array(
				'state'         => 'unassigned',
				'message'       => 'Order created — no print lab was chosen, so it is not assigned to anyone yet.',
				'provider_id'   => '',
				'provider_name' => '',
				'built'         => false,
				'pieces'        => 0,
			);
		}

		$assigned = self::assign_provider( $order_id, $provider_id, $note );
		if ( is_wp_error( $assigned ) ) return $assigned; // (f)

		$order = self::get_order( (int) $order_id );
		if ( ! $order ) return new WP_Error( 'not_found', 'Order not found.' );

		$f     = self::get_fulfillment( $order );
		$build = is_array( $f['build'] ) ? $f['build'] : null;
		$built = is_array( $build ) && ! empty( $build['done'] );

		// (a) Files already exist — hand the package over right now.
		if ( $built ) {
			$sent = self::send_to_provider( (int) $order_id, $provider_id, $note );
			if ( is_wp_error( $sent ) ) {
				return array(
					'state'         => 'assigned_send_failed',
					'message'       => 'Assigned to ' . $assigned['provider_name'] . ', but the job email failed: ' . $sent->get_error_message(),
					'provider_id'   => $assigned['provider_id'],
					'provider_name' => $assigned['provider_name'],
					'built'         => true,
					'pieces'        => (int) self::build_stats( $build )['pieces'],
				);
			}
			return array(
				'state'         => 'sent',
				'message'       => 'Assigned to ' . $assigned['provider_name'] . ' and the job package has been emailed to them.',
				'provider_id'   => $assigned['provider_id'],
				'provider_name' => $assigned['provider_name'],
				'built'         => true,
				'pieces'        => (int) self::build_stats( $build )['pieces'],
			);
		}

		// (b) Files not built. The order is already assigned — mark that we
		// still owe this lab a package, then spend whatever budget this
		// request has on the build before handing the rest to cron.
		$f['send_pending']    = 1;
		$f['build_error']     = '';
		$f['build_queued_at'] = current_time( 'mysql' );
		self::save_fulfillment( (int) $order_id, $f );

		$result = self::continue_build( (int) $order_id, (float) $budget );

		if ( $result['state'] === 'sent' ) {
			return array(
				'state'         => 'sent',
				'message'       => 'Assigned to ' . $assigned['provider_name'] . ' and the job package has been emailed to them.',
				'provider_id'   => $assigned['provider_id'],
				'provider_name' => $assigned['provider_name'],
				'built'         => true,
				'pieces'        => (int) $result['pieces'],
			);
		}
		if ( $result['state'] === 'failed' ) {
			return array(
				'state'         => 'assigned_build_failed',
				'message'       => 'Assigned to ' . $assigned['provider_name'] . ', but the print files failed to build: ' . $result['message'],
				'provider_id'   => $assigned['provider_id'],
				'provider_name' => $assigned['provider_name'],
				'built'         => false,
				'pieces'        => 0,
			);
		}
		if ( $result['state'] === 'send_failed' ) {
			return array(
				'state'         => 'assigned_send_failed',
				'message'       => 'Assigned to ' . $assigned['provider_name'] . ', but the job email failed: ' . $result['message'],
				'provider_id'   => $assigned['provider_id'],
				'provider_name' => $assigned['provider_name'],
				'built'         => true,
				'pieces'        => (int) $result['pieces'],
			);
		}

		return array(
			'state'         => 'assigned_building',
			'message'       => 'Assigned to ' . $assigned['provider_name'] . '. The print files are still building ('
				. (int) $result['done'] . ' of ' . (int) $result['total'] . ') — they will be emailed to '
				. $assigned['provider_name'] . ' automatically as soon as they finish.',
			'provider_id'   => $assigned['provider_id'],
			'provider_name' => $assigned['provider_name'],
			'built'         => false,
			'pieces'        => 0,
		);
	}

	// ── Deferred build ─────────────────────────────────

	/** Queue (or re-queue) a build continuation on WP-Cron. */
	public static function schedule_build_continue( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) return;
		if ( ! function_exists( 'wp_schedule_single_event' ) ) return;
		if ( wp_next_scheduled( self::CRON_BUILD, array( $order_id ) ) ) return;
		wp_schedule_single_event( time() + 60, self::CRON_BUILD, array( $order_id ) );
	}

	/** WP-Cron entry point. */
	public static function cron_continue_build( $order_id ) {
		self::continue_build( (int) $order_id, self::CONTINUE_SECONDS );
	}

	/**
	 * Push a queued build along for up to $budget seconds, then either send
	 * the package, record a failure, or schedule another pass. This is the
	 * only place that closes out a deferred hand-off.
	 *
	 * @return array{state:string, message:string, done:int, total:int, pieces:int}
	 */
	public static function continue_build( $order_id, $budget = 0 ) {
		$order = self::get_order( (int) $order_id );
		if ( ! $order ) {
			return array( 'state' => 'failed', 'message' => 'Order not found.', 'done' => 0, 'total' => 0, 'pieces' => 0 );
		}

		$budget = (float) $budget;
		if ( $budget <= 0 ) $budget = self::CONTINUE_SECONDS;

		$deadline = microtime( true ) + $budget;
		$build    = null;
		$last     = -1;

		do {
			$build = self::build_chunk( (int) $order->id, false );
			if ( ! is_array( $build ) ) break;
			if ( ! empty( $build['error'] ) ) break;

			$next = (int) ( $build['next'] ?? 0 );
			// A chunk that renders nothing would otherwise spin the deadline
			// away without progressing — stop and let the next pass retry.
			if ( $next <= $last ) break;
			$last = $next;
		} while ( empty( $build['done'] ) && microtime( true ) < $deadline );

		$done  = is_array( $build ) ? (int) ( $build['next'] ?? 0 ) : 0;
		$total = is_array( $build ) ? (int) ( $build['total'] ?? 0 ) : 0;

		// (d) Hard failure — the order stays assigned, but nobody is left
		// guessing: it is flagged in the panel and emailed to the studio.
		if ( ! is_array( $build ) || ! empty( $build['error'] ) ) {
			$message = is_array( $build ) ? (string) $build['error'] : 'The print file builder did not respond.';
			self::record_build_failure( (int) $order->id, $message );
			return array( 'state' => 'failed', 'message' => $message, 'done' => $done, 'total' => $total, 'pieces' => 0 );
		}

		if ( empty( $build['done'] ) ) {
			self::schedule_build_continue( (int) $order->id );
			return array( 'state' => 'building', 'message' => '', 'done' => $done, 'total' => $total, 'pieces' => 0 );
		}

		// Built. Did every item actually render?
		$stats = self::build_stats( $build );
		$f     = self::get_fulfillment( self::get_order( (int) $order->id ) );

		if ( (int) $stats['files'] === 0 && (int) $stats['errors'] > 0 ) {
			$message = 'Every item failed to render (' . (int) $stats['errors'] . ' error'
				. ( (int) $stats['errors'] === 1 ? '' : 's' ) . ') — check the source photos.';
			self::record_build_failure( (int) $order->id, $message );
			return array( 'state' => 'failed', 'message' => $message, 'done' => $done, 'total' => $total, 'pieces' => 0 );
		}

		if ( empty( $f['send_pending'] ) ) {
			// Built for someone who is not waiting on it (a plain rebuild).
			return array( 'state' => 'built', 'message' => '', 'done' => $done, 'total' => $total, 'pieces' => (int) $stats['pieces'] );
		}

		$provider_id = sanitize_key( (string) $f['provider_id'] );
		if ( $provider_id === '' ) {
			$f['send_pending'] = 0;
			self::save_fulfillment( (int) $order->id, $f );
			return array( 'state' => 'built', 'message' => '', 'done' => $done, 'total' => $total, 'pieces' => (int) $stats['pieces'] );
		}

		$sent = self::send_to_provider( (int) $order->id, $provider_id, (string) $f['assign_note'] );

		// Re-read: send_to_provider writes its own send record.
		$f = self::get_fulfillment( self::get_order( (int) $order->id ) );
		$f['send_pending'] = 0;

		if ( is_wp_error( $sent ) ) {
			$f['build_error'] = 'The files built, but the job email failed: ' . $sent->get_error_message();
			self::save_fulfillment( (int) $order->id, $f );
			self::send_studio_build_failed_email( self::get_order( (int) $order->id ), $f['build_error'] );
			return array(
				'state'   => 'send_failed',
				'message' => $sent->get_error_message(),
				'done'    => $done,
				'total'   => $total,
				'pieces'  => (int) $stats['pieces'],
			);
		}

		$f['build_error'] = '';
		self::save_fulfillment( (int) $order->id, $f );

		return array( 'state' => 'sent', 'message' => '', 'done' => $done, 'total' => $total, 'pieces' => (int) $stats['pieces'] );
	}

	/** Flag a failed build on the order, audit it, and tell the studio. */
	private static function record_build_failure( $order_id, $message ) {
		$order = self::get_order( (int) $order_id );
		if ( ! $order ) return;

		$f = self::get_fulfillment( $order );
		$f['build_error']  = sanitize_text_field( (string) $message );
		$f['send_pending'] = 0;
		$f['audit'][]      = array(
			'at'         => current_time( 'mysql' ),
			'actor_type' => 'studio',
			'actor_id'   => 0,
			'action'     => 'build_failed',
			'meta'       => array( 'error' => sanitize_text_field( (string) $message ) ),
		);
		self::save_fulfillment( (int) $order_id, $f );

		self::send_studio_build_failed_email( self::get_order( (int) $order_id ), (string) $message );
	}

	/**
	 * Fallback driver for the deferred build.
	 *
	 * WP-Cron only runs when somebody visits the site, and on a quiet studio
	 * site that can be hours. Every admin page load therefore looks for one
	 * order that still owes a lab its package and pushes it along, throttled
	 * so a busy admin session never turns into a render storm.
	 */
	public static function resume_pending_builds() {
		global $wpdb;

		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		if ( wp_doing_ajax() ) return;
		// Never fight the interactive chunked builder on the orders screen.
		if ( ! empty( $_GET['fp_building'] ) || ( isset( $_GET['tf2pf'] ) && $_GET['tf2pf'] !== '' ) ) return;
		if ( get_transient( self::RESUME_LOCK ) ) return;

		$table = self::orders_table();
		if ( ! $table ) return;

		$needle = '%' . $wpdb->esc_like( '"send_pending":1' ) . '%';
		$row    = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM `{$table}` WHERE fulfillment LIKE %s AND status != 'cancelled' ORDER BY updated_at ASC LIMIT 1",
			$needle
		) );
		if ( ! $row ) return;

		set_transient( self::RESUME_LOCK, 1, 60 );
		self::continue_build( (int) $row->id, 10 );
	}

	// ── Studio notifications ───────────────────────────

	/**
	 * Who at the studio hears about fulfilment movement. Reuses the print
	 * store's own settings rather than hardcoding anything.
	 *
	 * @return array<int,string>
	 */
	public static function studio_recipients() {
		$out = array();

		if ( class_exists( 'TwellerFlow2_Prints' ) ) {
			$settings = TwellerFlow2_Prints::get_settings();
			$notify   = sanitize_email( (string) ( $settings['notify_email'] ?? '' ) );
			if ( $notify !== '' && is_email( $notify ) ) $out[] = $notify;

			if ( method_exists( 'TwellerFlow2_Prints', 'get_partner_alert_emails' ) ) {
				foreach ( (array) TwellerFlow2_Prints::get_partner_alert_emails() as $extra ) {
					$extra = sanitize_email( (string) $extra );
					if ( $extra !== '' && is_email( $extra ) && ! in_array( $extra, $out, true ) ) $out[] = $extra;
				}
			}
		}

		if ( empty( $out ) ) {
			$admin = sanitize_email( (string) get_option( 'admin_email' ) );
			if ( $admin !== '' && is_email( $admin ) ) $out[] = $admin;
		}
		return $out;
	}

	/** Public admin deep link to one order's fulfilment panel. */
	public static function admin_order_url( $order_id ) {
		return self::order_url( (int) $order_id );
	}

	/** Piece count for an order, whether or not its files exist yet. */
	private static function ordered_pieces( $order ) {
		$pieces = 0;
		foreach ( self::printable_items( $order ) as $item ) {
			$pieces += max( 1, (int) ( $item['qty'] ?? 1 ) );
		}
		return $pieces;
	}

	/** Shared shell for the short studio-facing fulfilment emails. */
	private static function send_studio_email( $subject, $heading, $intro, $rows, $order_id, $cta = 'Open the order' ) {
		$to = self::studio_recipients();
		if ( empty( $to ) || ! class_exists( 'TwellerFlow2_Notifications' ) ) return false;

		$body = "
			<h2 style='color:#101010; font-weight:600;'>" . esc_html( $heading ) . "</h2>
			<p style='color:#3D3630; line-height:1.7;'>" . $intro . "</p>
			" . TwellerFlow2_Notifications::email_card( 'Job', $rows ) . "
			" . TwellerFlow2_Notifications::email_button_row( esc_url( self::admin_order_url( (int) $order_id ) ), $cta ) . "
		";

		return TwellerFlow2_Notifications::send_raw( $to, $subject, $body );
	}

	/** "This order is now with {lab}" — fired by assign_provider(). */
	private static function send_studio_assigned_email( $order, $provider, $previous_id = '' ) {
		if ( ! $order || ! is_array( $provider ) ) return false;

		$previous = $previous_id !== '' ? self::get_provider( $previous_id ) : null;
		$pieces   = self::ordered_pieces( $order );

		$rows  = TwellerFlow2_Notifications::email_detail_row( 'Order', esc_html( (string) $order->order_ref ) );
		$rows .= TwellerFlow2_Notifications::email_detail_row( 'Print lab', esc_html( $provider['name'] ) );
		if ( $previous ) {
			$rows .= TwellerFlow2_Notifications::email_detail_row( 'Moved from', esc_html( $previous['name'] ) );
		}
		$rows .= TwellerFlow2_Notifications::email_detail_row( 'Customer', esc_html( (string) $order->customer_name ) );
		$rows .= TwellerFlow2_Notifications::email_detail_row( 'Pieces', (int) $pieces );
		$rows .= TwellerFlow2_Notifications::email_detail_row( 'Order total', 'TT$ ' . esc_html( number_format( (float) $order->subtotal, 2 ) ) );

		$heading = $previous ? 'Order moved to a new lab' : 'Order sent to a print lab';
		$intro   = $previous
			? 'Order <strong>' . esc_html( (string) $order->order_ref ) . '</strong> has been moved from '
				. esc_html( $previous['name'] ) . ' to <strong>' . esc_html( $provider['name'] ) . '</strong>.'
			: 'Order <strong>' . esc_html( (string) $order->order_ref ) . '</strong> is now with <strong>'
				. esc_html( $provider['name'] ) . '</strong>.';

		$subject = ( $previous ? 'Print order ' . $order->order_ref . ' moved to ' : 'Print order ' . $order->order_ref . ' sent to ' )
			. $provider['name'];

		return self::send_studio_email( $subject, $heading, $intro, $rows, (int) $order->id );
	}

	/** "The files for this job did not build" — never a silent stall. */
	private static function send_studio_build_failed_email( $order, $message ) {
		if ( ! $order ) return false;

		$name = self::provider_name_for( $order );
		if ( $name === '' ) $name = 'no lab';

		$rows  = TwellerFlow2_Notifications::email_detail_row( 'Order', esc_html( (string) $order->order_ref ) );
		$rows .= TwellerFlow2_Notifications::email_detail_row( 'Print lab', esc_html( $name ) );
		$rows .= TwellerFlow2_Notifications::email_detail_row( 'Problem', esc_html( (string) $message ) );

		return self::send_studio_email(
			'Print files failed to build — ' . $order->order_ref,
			'Print files failed to build',
			'The print files for <strong>' . esc_html( (string) $order->order_ref ) . '</strong> could not be prepared, so '
				. esc_html( $name ) . ' has not received the package. The order is still assigned to them.',
			$rows,
			(int) $order->id,
			'Fix it in the order'
		);
	}

	/**
	 * A provider moved the job. Called from the provider REST transitions and
	 * from mark_delivered(), and deliberately silent when the studio itself
	 * performed the action from wp-admin — the person who clicked the button
	 * does not need an email about their own click.
	 *
	 * @param object $order
	 * @param string $event printing|ready|shipped|delivered
	 * @param string $actor_type provider|studio
	 */
	public static function notify_studio_provider_update( $order, $event, $actor_type = 'provider' ) {
		if ( ! $order ) return false;
		if ( $actor_type !== 'provider' ) return false; // studio's own click

		$event = sanitize_key( (string) $event );
		$name  = self::provider_name_for( $order );
		if ( $name === '' ) $name = 'The print lab';

		$lines = array(
			'printing'  => array( 'started printing', 'In production' ),
			'ready'     => array( 'marked this job ready', 'Ready' ),
			'shipped'   => array( 'shipped this job', 'Shipped' ),
			'delivered' => array( 'closed this job with a delivery scan', 'Delivered' ),
		);
		if ( ! isset( $lines[ $event ] ) ) return false;

		$rows  = TwellerFlow2_Notifications::email_detail_row( 'Order', esc_html( (string) $order->order_ref ) );
		$rows .= TwellerFlow2_Notifications::email_detail_row( 'Print lab', esc_html( $name ) );
		$rows .= TwellerFlow2_Notifications::email_detail_row( 'Now', esc_html( $lines[ $event ][1] ) );
		$rows .= TwellerFlow2_Notifications::email_detail_row( 'Customer', esc_html( (string) $order->customer_name ) );

		return self::send_studio_email(
			$order->order_ref . ' — ' . $lines[ $event ][1] . ' at ' . $name,
			$lines[ $event ][1],
			'<strong>' . esc_html( $name ) . '</strong> ' . esc_html( $lines[ $event ][0] ) . ' for order <strong>'
				. esc_html( (string) $order->order_ref ) . '</strong>.',
			$rows,
			(int) $order->id
		);
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

		// Ownership of the job is recorded by the one assignment routine —
		// which also audits it, fires tweller_print_order_assigned and tells
		// the studio. Idempotent, so an order assigned a moment ago (while its
		// files were still building) is not re-announced here.
		$assigned = self::assign_provider( (int) $order->id, $provider['id'], $note );
		if ( is_wp_error( $assigned ) ) return $assigned;

		$settings = self::get_settings();
		$stats    = self::build_stats( $build );
		$expires  = time() + ( (int) $settings['link_expiry_days'] * DAY_IN_SECONDS );
		$zip_url  = add_query_arg(
			array( 'exp' => $expires, 't' => self::sign( $order->order_ref, 'zip', $expires ) ),
			rest_url( 'tweller-flow-2/v1/prints/job/' . rawurlencode( $order->order_ref ) )
		);

		// WHITE LABEL: no customer identity, and no retail prices, anywhere
		// in this email. The item block is the SAME grouped summary the
		// customer sees — one row per size, never one row per photo — with
		// the money column switched off, so a 116-photo job is a handful of
		// lines and the piece counts can't drift from the customer's copy.
		$items_block = '';
		if ( class_exists( 'TwellerFlow2_Prints' ) ) {
			$items_block = TwellerFlow2_Prints::order_summary_html( $order, array( 'show_prices' => false ) );
		}

		// Per-file detail stays where it belongs: manifest.csv in the ZIP.
		$low = (int) $stats['low'] + (int) $stats['very_low'];
		if ( $low > 0 ) {
			$items_block .= "<p style='margin:14px 0 0; color:#B91C1C; font-size:12.5px; line-height:1.6;'>"
				. $low . ' file' . ( $low === 1 ? ' is' : 's are' ) . " below our preferred resolution — they are flagged in <strong>manifest.csv</strong>. Please print as supplied.</p>";
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

			" . TwellerFlow2_Notifications::email_card( 'Items', $items_block ) . "
			{$note_block}
			" . TwellerFlow2_Notifications::email_card( 'File Specification', $spec ) . "
			" . TwellerFlow2_Notifications::email_card( 'Instructions', $instructions ) . "

			<p style='color:#3D3630;'>Thank you,<br><strong>Tweller Studios</strong></p>
		";

		$subject = 'Print job ' . $order->order_ref . ' — ' . (int) $stats['pieces'] . ' piece' . ( (int) $stats['pieces'] === 1 ? '' : 's' ) . ' — Tweller Studios';
		$sent    = TwellerFlow2_Notifications::send_raw( $provider['email'], $subject, $body );

		// Re-read: assign_provider() has already written to this row.
		$fulfillment = self::get_fulfillment( self::get_order( (int) $order->id ) );

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

		// The package has actually gone out — the assignment itself was
		// recorded (and audited) by assign_provider() above.
		$fulfillment['send_pending'] = 0;
		$fulfillment['audit'][]      = array(
			'at'         => current_time( 'mysql' ),
			'actor_type' => 'studio',
			'actor_id'   => ( $user && $user->exists() ) ? (int) $user->ID : 0,
			'action'     => 'sent_to_provider',
			'meta'       => array( 'provider' => $provider['id'] ),
		);
		self::save_fulfillment( $order->id, $fulfillment );

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

	/** Anchor for the expanded order row, so a redirect lands on the panel. */
	public static function row_anchor( $order_id ) {
		return 'tf2-po-detail-' . (int) $order_id;
	}

	/**
	 * Orders-page URL that re-opens one order's detail row and jumps to it.
	 * Every fulfilment redirect goes through here — landing on a collapsed
	 * dashboard after an action is what made Build look like it did nothing.
	 */
	private static function order_url( $order_id, $args = array() ) {
		$args['order_id'] = (int) $order_id;
		return self::orders_url( $args ) . '#' . self::row_anchor( $order_id );
	}

	public static function build_url( $order_id, $force = false, $extra = array() ) {
		$args = array( 'tf2pf' => 'build', 'order_id' => (int) $order_id );
		if ( $force ) $args['force'] = 1;
		if ( is_array( $extra ) ) $args = array_merge( $args, $extra );
		return wp_nonce_url( self::orders_url( $args ), 'tf2pf_build_' . (int) $order_id );
	}

	public static function download_url( $order_id ) {
		return wp_nonce_url(
			self::orders_url( array( 'tf2pf' => 'download', 'order_id' => (int) $order_id ) ),
			'tf2pf_download_' . (int) $order_id
		);
	}

	/**
	 * Label link that records that the label was actually produced, then
	 * hands off to the signed label view. Without this the "Print shipping
	 * label" button looks identical before and after it has been used.
	 */
	public static function label_action_url( $order_id ) {
		return wp_nonce_url(
			self::orders_url( array( 'tf2pf' => 'label', 'order_id' => (int) $order_id ) ),
			'tf2pf_label_' . (int) $order_id
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

			$pass = isset( $_GET['fp_pass'] ) ? max( 0, (int) $_GET['fp_pass'] ) : 0;
			$prev = isset( $_GET['fp_from'] ) ? (int) $_GET['fp_from'] : -1;

			// "force" wipes the job folder and starts over, so it may only
			// ever apply to the very first pass of a run.
			$force = ! empty( $_GET['force'] ) && $pass === 0;
			$build = self::build_chunk( $order_id, $force );

			$next  = (int) ( $build['next'] ?? 0 );
			$total = (int) ( $build['total'] ?? 0 );

			// A build runs in chunks so it can never hit the PHP time limit.
			// Each chunk returns to the orders page with the row re-opened and
			// a live progress bar, which then continues itself — instead of
			// dumping the user on a collapsed dashboard mid-build.
			if ( empty( $build['done'] ) ) {
				if ( $pass >= 200 || $next <= $prev ) {
					wp_safe_redirect( self::order_url( $order_id, array(
						'fp_error' => rawurlencode( 'The build stopped after ' . $next . ' of ' . $total . ' items. Check the item errors below and try again.' ),
					) ) );
					exit;
				}

				wp_safe_redirect( self::order_url( $order_id, array(
					'fp_building' => 1,
					'fp_done'     => $next,
					'fp_total'    => $total,
					'fp_pass'     => $pass + 1,
					'fp_from'     => $next,
				) ) );
				exit;
			}

			wp_safe_redirect( self::order_url( $order_id, array( 'fp_built' => 1 ) ) );
			exit;
		}

		// ── Open the shipping label (and record that we did) ──
		if ( $action === 'label' ) {
			if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Permission denied.' ) );
			$order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
			check_admin_referer( 'tf2pf_label_' . $order_id );

			$order = self::get_order( $order_id );
			if ( ! $order ) wp_die( esc_html__( 'Order not found.' ) );

			$f = self::get_fulfillment( $order );
			if ( (string) $f['label_printed_at'] === '' ) {
				$user                   = wp_get_current_user();
				$f['label_printed_at']  = current_time( 'mysql' );
				$f['audit'][]           = array(
					'at'         => current_time( 'mysql' ),
					'actor_type' => 'studio',
					'actor_id'   => ( $user && $user->exists() ) ? (int) $user->ID : 0,
					'action'     => 'label_printed',
				);
				self::save_fulfillment( $order_id, $f );
			}

			wp_safe_redirect( self::label_url( (string) $order->order_ref ) );
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
			wp_safe_redirect( self::order_url( $order_id, array( 'fp_saved' => 1 ) ) );
			exit;
		}

		// ── Send to provider ──
		if ( isset( $_POST['tf2pf_send_provider'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Permission denied.' ) );
			check_admin_referer( 'tf2pf_send_provider' );

			$order_id    = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
			$provider_id = sanitize_key( wp_unslash( $_POST['provider_id'] ?? '' ) );
			$note        = sanitize_textarea_field( wp_unslash( $_POST['provider_note'] ?? '' ) );

			// Assignment first, package second — a big job is assigned (and so
			// counts in economics, stats and the portal) even when its files
			// cannot finish inside this request.
			$result = self::assign_and_send( $order_id, $provider_id, $note, 15 );
			$args   = array();
			if ( is_wp_error( $result ) ) {
				$args['fp_error'] = rawurlencode( $result->get_error_message() );
			} elseif ( in_array( $result['state'], array( 'assigned_build_failed', 'assigned_send_failed' ), true ) ) {
				$args['fp_error'] = rawurlencode( $result['message'] );
			} elseif ( $result['state'] === 'assigned_building' ) {
				$args['fp_queued'] = rawurlencode( $result['message'] );
			} else {
				$args['fp_sent'] = 1;
			}
			wp_safe_redirect( self::order_url( $order_id, $args ) );
			exit;
		}

		// ── Mark delivered from the desk (no scanner to hand) ──
		if ( isset( $_POST['tf2pf_mark_delivered'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Permission denied.' ) );
			check_admin_referer( 'tf2pf_mark_delivered' );

			$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
			$order    = self::get_order( $order_id );
			if ( $order ) self::mark_delivered( $order, 'admin' );

			wp_safe_redirect( self::order_url( $order_id, array( 'fp_delivered' => 1 ) ) );
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

	/** True when this request is mid-build for the given order. */
	private static function is_building( $order_id ) {
		if ( empty( $_GET['fp_building'] ) ) return false;
		$building_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
		return $building_id > 0 && $building_id === (int) $order_id;
	}

	/** The order whose detail row should be open on this request (0 = none). */
	public static function open_order_id() {
		if ( ! current_user_can( 'manage_options' ) ) return 0;
		return isset( $_GET['order_id'] ) ? max( 0, (int) $_GET['order_id'] ) : 0;
	}

	/** Gold progress bar for a chunked build, with live counts. */
	private static function render_progress( $order_id, $with_driver = false ) {
		$done  = isset( $_GET['fp_done'] ) ? max( 0, (int) $_GET['fp_done'] ) : 0;
		$total = isset( $_GET['fp_total'] ) ? max( 1, (int) $_GET['fp_total'] ) : 1;
		$pass  = isset( $_GET['fp_pass'] ) ? max( 0, (int) $_GET['fp_pass'] ) : 0;
		$from  = isset( $_GET['fp_from'] ) ? max( 0, (int) $_GET['fp_from'] ) : 0;
		$done  = min( $done, $total );
		$pct   = max( 3, min( 100, (int) round( ( $done / $total ) * 100 ) ) );

		$next = self::build_url( (int) $order_id, false, array( 'fp_pass' => $pass, 'fp_from' => $from ) );
		?>
		<div class="tf2pf__progress">
			<p class="tf2pf__progress-label">
				<strong>Preparing print files</strong> — <?php echo (int) $done; ?> of <?php echo (int) $total; ?>
			</p>
			<div class="tf2pf__bar"><div class="tf2pf__bar-fill" style="width:<?php echo (int) $pct; ?>%;"></div></div>
			<p class="tf2pf__progress-hint">
				Keep this tab open — it continues by itself.
				<a href="<?php echo esc_url( $next ); ?>" <?php echo $with_driver ? 'id="tf2pf-continue"' : ''; ?>>Continue now</a>
			</p>
		</div>
		<?php
		if ( ! $with_driver ) return;
		?>
		<script>
		(function(){
			var a = document.getElementById('tf2pf-continue');
			if (!a) return;
			var row = document.getElementById('<?php echo esc_js( self::row_anchor( (int) $order_id ) ); ?>');
			if (row && row.scrollIntoView) { try { row.scrollIntoView({block:'center'}); } catch (e) { row.scrollIntoView(); } }
			setTimeout(function(){ window.location.href = a.href; }, 500);
		})();
		</script>
		<?php
	}

	/** Page-level notices + the auto-continue driver for chunked builds. */
	public static function render_page_notices() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		if ( ! empty( $_GET['fp_saved'] ) ) {
			echo '<div class="tf2-alert tf2-alert--success">Delivery details saved.</div>';
		}
		if ( ! empty( $_GET['fp_sent'] ) ) {
			echo '<div class="tf2-alert tf2-alert--success">Order sent to the print provider — it is now awaiting production.</div>';
		}
		if ( ! empty( $_GET['fp_queued'] ) ) {
			echo '<div class="tf2-alert tf2pf__progress-notice">'
				. esc_html( sanitize_text_field( rawurldecode( wp_unslash( $_GET['fp_queued'] ) ) ) ) . '</div>';
		}
		if ( ! empty( $_GET['fp_built'] ) ) {
			echo '<div class="tf2-alert tf2-alert--success">Print files built and packaged.</div>';
		}
		if ( ! empty( $_GET['fp_delivered'] ) ) {
			echo '<div class="tf2-alert tf2-alert--success">Order marked delivered.</div>';
		}
		if ( ! empty( $_GET['fp_error'] ) ) {
			// Decode first — sanitize_text_field() strips percent-encoded octets.
			echo '<div class="tf2-alert tf2-alert--error">'
				. esc_html( sanitize_text_field( rawurldecode( wp_unslash( $_GET['fp_error'] ) ) ) ) . '</div>';
		}

		$order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
		if ( ! $order_id || ! self::is_building( $order_id ) ) return;

		// The driver lives here so it fires even when a status filter or a
		// search has hidden this order's row from the table below.
		self::print_panel_css();
		echo '<div class="tf2-alert tf2pf__progress-notice">';
		self::render_progress( $order_id, true );
		echo '</div>';
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

		/* Fulfilment stage — only 'delivered' is ever black-and-gold. */
		.tf2pf__stage { display:flex; align-items:center; gap:8px; border-radius:8px; padding:8px 11px; font-size:12.5px; font-weight:600; margin:0 0 10px; }
		.tf2pf__stage--idle   { background:#F3F4F6; border:1px solid #E5E7EB; color:#4B5563; }
		.tf2pf__stage--active { background:#FDFAF1; border:1px solid #E7CF87; color:#7A5C08; }
		.tf2pf__stage--done   { background:#101010; border:1px solid #101010; color:#C9A227; }
		.tf2pf__stage .dot { width:8px; height:8px; border-radius:50%; background:currentColor; flex:0 0 8px; }

		/* Who has this job — answered without opening anything else. */
		.tf2pf__with { margin:-4px 0 10px; font-size:12.5px; color:#4B5563; }
		.tf2pf__with strong { color:#111827; }
		.tf2pf__with--none { color:#9CA3AF; }

		/* Build progress */
		.tf2pf__progress-notice { background:#FDFAF1; border:1px solid #C9A227; color:#3D3630; }
		.tf2pf__progress { margin:0 0 10px; }
		.tf2pf__progress-label { margin:0 0 6px; font-size:12.5px; color:#3D3630; }
		.tf2pf__progress-hint { margin:5px 0 0; font-size:11.5px; color:#8A8178; }
		.tf2pf__bar { height:8px; background:#ECE9E2; border-radius:5px; overflow:hidden; }
		.tf2pf__bar-fill { height:100%; background:#C9A227; border-radius:5px; transition:width .25s ease; }

		/* One balanced, state-driven action row */
		.tf2pf__actions { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 12px; }
		.tf2pf__act {
			flex:1 1 190px; min-width:168px; box-sizing:border-box;
			display:inline-flex; align-items:center; justify-content:center; gap:6px;
			min-height:38px; padding:9px 12px; border-radius:8px;
			font-size:12.5px; font-weight:600; line-height:1.2; text-align:center;
			text-decoration:none; cursor:pointer; border:1px solid transparent;
			font-family:inherit;
		}
		.tf2pf__act--next { background:#C9A227; border-color:#C9A227; color:#101010; }
		.tf2pf__act--next:hover { background:#E7C55C; border-color:#E7C55C; color:#101010; }
		.tf2pf__act--todo { background:#FFFFFF; border-color:#D1D5DB; color:#374151; }
		.tf2pf__act--todo:hover { border-color:#9CA3AF; color:#111827; }
		.tf2pf__act--done { background:#ECFDF5; border-color:#A7F3D0; color:#065F46; }
		.tf2pf__act--done:hover { background:#D1FAE5; color:#065F46; }
		.tf2pf__act[disabled], .tf2pf__act--off { opacity:0.5; cursor:not-allowed; pointer-events:none; }
		.tf2pf__acthint { margin:-4px 0 12px; font-size:11.5px; color:#9CA3AF; }
		@media (max-width:640px) { .tf2pf__act { flex:1 1 100%; } }
		</style>
		<?php
	}

	/** One action button, rendered identically whether it is a link or a submit. */
	private static function action_button( $args ) {
		$state = isset( $args['state'] ) ? $args['state'] : 'todo';
		$class = 'tf2pf__act tf2pf__act--' . ( in_array( $state, array( 'next', 'todo', 'done' ), true ) ? $state : 'todo' );
		$label = (string) ( $args['label'] ?? '' );
		$title = empty( $args['title'] ) ? '' : ' title="' . esc_attr( (string) $args['title'] ) . '"';
		$click = empty( $args['confirm'] ) ? '' : ' onclick="return confirm(\'' . esc_js( (string) $args['confirm'] ) . '\');"';

		if ( ! empty( $args['disabled'] ) ) {
			echo '<button type="button" class="' . esc_attr( $class ) . '"' . $title . ' disabled>' . esc_html( $label ) . '</button>';
			return;
		}

		if ( ! empty( $args['form'] ) ) {
			echo '<button type="submit" form="' . esc_attr( $args['form'] ) . '" class="' . esc_attr( $class ) . '"'
				. $title . $click . '>' . esc_html( $label ) . '</button>';
			return;
		}

		echo '<a href="' . esc_url( (string) ( $args['href'] ?? '#' ) ) . '" class="' . esc_attr( $class ) . '"'
			. ( ! empty( $args['blank'] ) ? ' target="_blank" rel="noopener"' : '' )
			. $title . $click . '>' . esc_html( $label ) . '</a>';
	}

	/**
	 * Fulfilment panel for one order. Called from admin/views/prints-orders.php.
	 * Nothing here is ever exposed to the customer.
	 */
	public static function render_order_panel( $order ) {
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( ! is_object( $order ) || empty( $order->order_ref ) ) return;

		self::print_panel_css();

		$order_id    = (int) $order->id;
		$fulfillment = self::get_fulfillment( $order );
		$build       = $fulfillment['build'];
		$items       = self::printable_items( $order );
		$hash_now    = self::items_hash( $order );
		$is_stale    = is_array( $build ) && ( $build['hash'] ?? '' ) !== $hash_now;
		$is_built    = is_array( $build ) && ! empty( $build['done'] ) && ! $is_stale;
		$stats       = self::build_stats( $build );
		$providers   = self::get_providers( true );
		$default     = self::get_default_provider();
		$stage       = self::fulfillment_stage( $order );
		$delivered   = ( $stage['key'] === 'delivered' );
		$building    = self::is_building( $order_id );

		$is_sent     = ! empty( $fulfillment['sends'] ) || (string) $fulfillment['provider_id'] !== '';
		$has_label   = (string) $fulfillment['label_printed_at'] !== '';
		$send_form   = 'tf2pf-send-' . $order_id;

		// Who has this job, and does the lab still owe us a package?
		$assigned_id   = sanitize_key( (string) $fulfillment['provider_id'] );
		$assigned_name = self::provider_name_for( $order, $fulfillment );
		$send_pending  = ! empty( $fulfillment['send_pending'] );
		$build_error   = (string) $fulfillment['build_error'];
		$package_sent  = false;
		foreach ( (array) $fulfillment['sends'] as $s ) {
			if ( ! empty( $s['sent'] ) ) { $package_sent = true; break; }
		}

		// Exactly one action is the natural next step at any moment.
		$next_step = 'build';
		if ( $is_built && ! $is_stale ) $next_step = $is_sent ? ( $has_label ? '' : 'label' ) : 'send';
		if ( $delivered ) $next_step = '';

		$ordered_pieces = 0;
		foreach ( $items as $it ) { $ordered_pieces += max( 1, (int) ( $it['qty'] ?? 1 ) ); }
		?>
		<div class="tf2pf" id="tf2pf-<?php echo (int) $order_id; ?>">
			<h4>Fulfilment</h4>

			<div class="tf2pf__stage tf2pf__stage--<?php echo esc_attr( $stage['tone'] ); ?>">
				<span class="dot"></span>
				<span><?php echo esc_html( ( $delivered ? "✓ " : '' ) . $stage['label'] ); ?></span>
			</div>

			<?php if ( $assigned_name !== '' ) : ?>
				<p class="tf2pf__with">
					With <strong><?php echo esc_html( $assigned_name ); ?></strong>
					<?php if ( (string) $fulfillment['assigned_at'] !== '' ) : ?>
						since <?php echo esc_html( date_i18n( 'M j, Y g:ia', strtotime( (string) $fulfillment['assigned_at'] ) ) ); ?>
					<?php endif; ?>
					· <?php echo $package_sent ? 'job package emailed' : 'package not emailed yet'; ?>
				</p>
			<?php else : ?>
				<p class="tf2pf__with tf2pf__with--none">No print lab assigned yet — this order is not with anyone.</p>
			<?php endif; ?>

			<?php if ( $build_error !== '' ) : ?>
				<div class="tf2pf__warn">
					<strong>Print files failed to build.</strong>
					<?php echo esc_html( $build_error ); ?>
					<?php if ( $assigned_name !== '' ) : ?>
						<br><?php echo esc_html( $assigned_name ); ?> is still assigned to this order but has not received the package.
					<?php endif; ?>
					<br>Fix the source photos, then use <strong>Rebuild print files</strong> above.
				</div>
			<?php elseif ( $send_pending ) : ?>
				<div class="tf2pf__soft">
					<strong>Print files are still building.</strong>
					They will be emailed to <?php echo esc_html( $assigned_name !== '' ? $assigned_name : 'the assigned lab' ); ?>
					automatically as soon as they finish. This continues in the background and on every admin page load.
				</div>
			<?php endif; ?>

			<?php if ( $building ) : ?>
				<?php self::render_progress( $order_id, false ); ?>
			<?php endif; ?>

			<!-- ── One balanced action row, coloured by state ── -->
			<div class="tf2pf__actions">
				<?php
				self::action_button( array(
					'href'  => self::build_url( $order_id, $is_built || $is_stale ),
					'label' => $is_built ? 'Files built ✓' : ( $is_stale ? 'Rebuild print files' : 'Build print files' ),
					'state' => $is_built ? 'done' : ( $next_step === 'build' ? 'next' : 'todo' ),
					'title' => $is_built ? 'Rebuild the print-ready files from scratch' : 'Render the print-ready files and package them',
				) );

				if ( empty( $providers ) ) {
					self::action_button( array( 'label' => 'No provider set up', 'state' => 'todo', 'disabled' => true ) );
				} else {
					// Assignment does not wait on the build. A big job is
					// assigned immediately and the package follows by itself.
					self::action_button( array(
						'form'     => $send_form,
						'label'    => $is_sent ? ( $package_sent ? 'Sent ✓' : 'Assigned ✓' ) : 'Send order to provider',
						'state'    => $is_sent ? 'done' : ( in_array( $next_step, array( 'send', 'build' ), true ) ? 'next' : 'todo' ),
						'disabled' => $delivered,
						'confirm'  => 'Send ' . $order->order_ref . ' to the selected provider'
							. ( $is_sent ? ' again?' : '?' ),
						'title'    => $delivered
							? 'This order has been delivered — it cannot be reassigned'
							: ( $is_built
								? ( $is_sent ? 'Already assigned — use this to send the job again or move it to another lab' : 'Assign the lab and email them the job package' )
								: 'Assign the lab now — the files keep building and are emailed to them automatically' ),
					) );
				}

				self::action_button( array(
					'href'  => self::label_action_url( $order_id ),
					'blank' => true,
					'label' => $has_label ? 'Label printed ✓' : 'Print shipping label',
					'state' => $has_label ? 'done' : ( $next_step === 'label' ? 'next' : 'todo' ),
					'title' => $has_label ? 'Open the 4x6 shipping label again' : 'Open the 4x6 shipping label with the delivery QR code',
				) );
				?>
			</div>
			<?php if ( ! $is_built && ! $delivered ) : ?>
				<p class="tf2pf__acthint">You can assign a lab now — the print files keep building and are emailed to them the moment they are ready.</p>
			<?php endif; ?>

			<div class="tf2pf__grid">

				<!-- ── Print files ── -->
				<div class="tf2pf__col">
					<div class="tf2pf__stat">
						<span>Pieces ordered<strong><?php echo (int) $ordered_pieces; ?></strong></span>
						<?php if ( $is_built ) : ?>
							<span>Files built<strong><?php echo (int) $stats['files']; ?></strong></span>
							<span>Pieces packaged<strong><?php echo (int) $stats['pieces']; ?></strong></span>
							<?php if ( ! empty( $build['zip_bytes'] ) ) : ?>
								<span>Package<strong><?php echo esc_html( size_format( (int) $build['zip_bytes'] ) ); ?></strong></span>
							<?php endif; ?>
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
					<?php if ( ! $is_built && ! $building && ! is_array( $build ) ) : ?>
						<p style="margin:6px 0; font-size:12.5px; color:#6B7280;">No print package yet.</p>
					<?php endif; ?>

					<?php if ( $is_built && ! empty( $build['zip'] ) ) : ?>
						<p style="margin:8px 0;">
							<a href="<?php echo esc_url( self::download_url( $order_id ) ); ?>" class="tf2-btn tf2-btn--secondary tf2-btn--sm">
								Download ZIP<?php if ( ! empty( $build['zip_bytes'] ) ) : ?> (<?php echo esc_html( size_format( (int) $build['zip_bytes'] ) ); ?>)<?php endif; ?>
							</a>
						</p>
					<?php endif; ?>

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
					<p style="margin:0 0 6px; font-size:12px; color:#6B7280;">
						<strong>Send to a print provider</strong>
						<?php if ( $assigned_name !== '' ) : ?>
							<br><span style="color:#7A5C08;">Currently with <?php echo esc_html( $assigned_name ); ?></span>
						<?php endif; ?>
					</p>
					<?php if ( empty( $providers ) ) : ?>
						<p style="font-size:12.5px; color:#6B7280;">
							No active providers yet —
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PROVIDERS_PAGE ) ); ?>">add one</a>.
						</p>
					<?php else : ?>
						<form method="post" id="<?php echo esc_attr( $send_form ); ?>" style="display:flex; flex-direction:column; gap:6px;">
							<?php wp_nonce_field( 'tf2pf_send_provider' ); ?>
							<input type="hidden" name="tf2pf_send_provider" value="1">
							<input type="hidden" name="order_id" value="<?php echo (int) $order_id; ?>">
							<select name="provider_id">
								<?php foreach ( $providers as $p ) : ?>
									<?php
									// Pre-select the lab that already has it, so
									// "send again" cannot silently reassign.
									$is_selected = $assigned_id !== ''
										? ( $assigned_id === $p['id'] )
										: ( $default && $default['id'] === $p['id'] );
									?>
									<option value="<?php echo esc_attr( $p['id'] ); ?>" <?php selected( $is_selected ); ?>>
										<?php echo esc_html( $p['name'] ); ?><?php echo ! empty( $p['default'] ) ? ' (default)' : ''; ?><?php echo $assigned_id === $p['id'] ? ' — currently assigned' : ''; ?>
									</option>
								<?php endforeach; ?>
							</select>
							<textarea name="provider_note" rows="2" placeholder="Optional note for this job (turnaround, finish, packing…)"></textarea>
						</form>
						<p style="margin:6px 0 0; font-size:11.5px; color:#9CA3AF;">
							Pick the lab and any note, then use <strong>Send order to provider</strong> above.
						</p>
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
						<input type="hidden" name="order_id" value="<?php echo (int) $order_id; ?>">
						<label style="font-size:12px; color:#6B7280;"><strong>Delivery address</strong></label>
						<textarea name="delivery_address" rows="3" placeholder="Street, town, Trinidad &amp; Tobago"><?php echo esc_textarea( (string) $fulfillment['delivery_address'] ); ?></textarea>
						<label style="font-size:12px; color:#6B7280;">Delivery note (printed on the label)</label>
						<input type="text" name="delivery_notes" value="<?php echo esc_attr( (string) $fulfillment['delivery_notes'] ); ?>" placeholder="e.g. call on arrival">
						<button type="submit" class="tf2-btn tf2-btn--secondary tf2-btn--sm">Save delivery details</button>
					</form>

					<p style="margin:10px 0 0; font-size:11.5px; color:#9CA3AF;">
						4&times;6in label with a large QR code. Scanning it marks the order delivered and emails the client.
						<?php if ( $has_label ) : ?>
							<br>Label first printed <?php echo esc_html( date_i18n( 'M j, Y g:ia', strtotime( (string) $fulfillment['label_printed_at'] ) ) ); ?>.
						<?php endif; ?>
					</p>

					<?php if ( ! $delivered ) : ?>
						<form method="post" style="margin-top:8px;">
							<?php wp_nonce_field( 'tf2pf_mark_delivered' ); ?>
							<input type="hidden" name="tf2pf_mark_delivered" value="1">
							<input type="hidden" name="order_id" value="<?php echo (int) $order_id; ?>">
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
