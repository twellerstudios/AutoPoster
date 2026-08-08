<?php
/**
 * Pretty URLs + the QR image endpoint.
 *
 *   /event/{slug}/            → gallery view          (CPT single)
 *   /event/{slug}/upload/     → guest upload view     (tepe_view=upload)
 *   /event/{slug}/qr.svg      → QR code (SVG) → upload URL
 *   /event/{slug}/qr.png      → QR code (PNG) → upload URL
 *
 * Rules are registered at the top so they beat the CPT's own single rule.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TEPE_Rewrite {

    public static function init() {
        add_action( 'init', array( __CLASS__, 'add_rules' ), 10 );
        add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
        add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_qr' ), 1 );
    }

    public static function add_rules() {
        add_rewrite_rule(
            '^event/([^/]+)/upload/?$',
            'index.php?' . TEPE_CPT . '=$matches[1]&tepe_view=upload',
            'top'
        );
        add_rewrite_rule(
            '^event/([^/]+)/qr\.(svg|png)$',
            'index.php?' . TEPE_CPT . '=$matches[1]&tepe_qr=$matches[2]',
            'top'
        );
    }

    public static function query_vars( $vars ) {
        $vars[] = 'tepe_view';
        $vars[] = 'tepe_qr';
        return $vars;
    }

    /** True on the guest upload view. */
    public static function is_upload_view() {
        return get_query_var( 'tepe_view' ) === 'upload';
    }

    /**
     * Serve the QR image for /event/{slug}/qr.(svg|png). The QR always encodes
     * the *upload* URL — that's what guests scan.
     */
    public static function maybe_serve_qr() {
        $fmt = get_query_var( 'tepe_qr' );
        if ( ! $fmt ) return;

        $slug  = get_query_var( TEPE_CPT );
        $event = TEPE_Gallery::get_by_slug( $slug );
        if ( ! $event ) {
            status_header( 404 );
            exit;
        }

        $upload_url = TEPE_Gallery::upload_url( $event );

        if ( $fmt === 'png' ) {
            $png = TEPE_QR::png( $upload_url, 10 );
            if ( $png === false ) {
                // No GD — fall back to SVG so the endpoint always returns art.
                $fmt = 'svg';
            } else {
                header( 'Content-Type: image/png' );
                header( 'Cache-Control: public, max-age=86400' );
                header( 'Content-Disposition: inline; filename="' . $event->post_name . '-qr.png"' );
                echo $png; // phpcs:ignore -- binary image
                exit;
            }
        }

        $svg = TEPE_QR::svg( $upload_url, 8 );
        header( 'Content-Type: image/svg+xml' );
        header( 'Cache-Control: public, max-age=86400' );
        header( 'Content-Disposition: inline; filename="' . $event->post_name . '-qr.svg"' );
        echo $svg; // phpcs:ignore -- generated, escaped SVG
        exit;
    }

    /** Public helpers other modules use to build QR URLs. */
    public static function qr_url( $event, $fmt = 'svg' ) {
        $fmt = ( $fmt === 'png' ) ? 'png' : 'svg';
        if ( get_option( 'permalink_structure' ) ) {
            return trailingslashit( get_permalink( $event ) ) . 'qr.' . $fmt;
        }
        // Plain permalinks fallback.
        return add_query_arg( array( TEPE_CPT => $event->post_name, 'tepe_qr' => $fmt ), home_url( '/' ) );
    }
}
