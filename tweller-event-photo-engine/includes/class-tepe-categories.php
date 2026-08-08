<?php
/**
 * Category buckets & guest sub-albums.
 *
 * Each event carries an ordered list of categories, stored in the
 * TEPE_CPT::META_CATEGORIES post-meta array. A category is:
 *   [ 'slug' => 'ceremony', 'label' => 'Ceremony', 'guest' => 0 ]
 * where `guest` marks a public sub-album a guest created on the fly.
 *
 * The empty-string slug is the implicit "Public Album" that always exists.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TEPE_Categories {

    /** Pre-made buckets offered when an event is created. */
    public static function presets() {
        return array( 'Ceremony', 'Reception', 'Candid', 'Group Photos', 'Speeches', 'Dance Floor' );
    }

    /** Sanitise a label into a URL-safe slug. */
    public static function slugify( $label ) {
        $slug = sanitize_title( $label );
        return $slug !== '' ? $slug : substr( md5( $label . microtime() ), 0, 8 );
    }

    /** All categories for an event, always including the Public Album first. */
    public static function get( $event_id ) {
        $stored = get_post_meta( $event_id, TEPE_CPT::META_CATEGORIES, true );
        if ( ! is_array( $stored ) ) $stored = array();

        $out = array( array( 'slug' => '', 'label' => 'Public Album', 'guest' => 0 ) );
        foreach ( $stored as $cat ) {
            if ( empty( $cat['slug'] ) ) continue;
            $out[] = array(
                'slug'  => sanitize_key( $cat['slug'] ),
                'label' => sanitize_text_field( $cat['label'] ),
                'guest' => ! empty( $cat['guest'] ) ? 1 : 0,
            );
        }
        return $out;
    }

    /** Just the host/guest-defined buckets (excludes the Public Album). */
    public static function get_buckets( $event_id ) {
        $all = self::get( $event_id );
        return array_values( array_filter( $all, function ( $c ) { return $c['slug'] !== ''; } ) );
    }

    /** Replace the whole category set (host admin editing). */
    public static function set( $event_id, $labels ) {
        $cats = array();
        $seen = array();
        foreach ( (array) $labels as $entry ) {
            $label = is_array( $entry ) ? ( $entry['label'] ?? '' ) : $entry;
            $label = trim( sanitize_text_field( $label ) );
            if ( $label === '' ) continue;
            $slug = self::slugify( $label );
            if ( isset( $seen[ $slug ] ) ) continue;
            $seen[ $slug ] = true;
            $cats[] = array(
                'slug'  => $slug,
                'label' => $label,
                'guest' => ( is_array( $entry ) && ! empty( $entry['guest'] ) ) ? 1 : 0,
            );
        }
        update_post_meta( $event_id, TEPE_CPT::META_CATEGORIES, $cats );
        return $cats;
    }

    /** Does a slug resolve to a real category (or the public album)? */
    public static function exists( $event_id, $slug ) {
        if ( $slug === '' ) return true;
        foreach ( self::get( $event_id ) as $cat ) {
            if ( $cat['slug'] === $slug ) return true;
        }
        return false;
    }

    public static function label_for( $event_id, $slug ) {
        foreach ( self::get( $event_id ) as $cat ) {
            if ( $cat['slug'] === $slug ) return $cat['label'];
        }
        return 'Public Album';
    }

    /**
     * Add a guest-created public sub-album. Returns the new slug, or an
     * existing slug if it collides, or WP_Error if the event forbids it.
     */
    public static function add_guest_album( $event_id, $label ) {
        if ( ! get_post_meta( $event_id, TEPE_CPT::META_ALLOW_ALBUMS, true ) ) {
            return new WP_Error( 'albums_disabled', 'This event is not accepting new albums.' );
        }
        $label = trim( sanitize_text_field( $label ) );
        if ( $label === '' ) {
            return new WP_Error( 'empty_label', 'Please give the album a name.' );
        }
        $slug = self::slugify( $label );
        $buckets = self::get_buckets( $event_id );
        foreach ( $buckets as $b ) {
            if ( $b['slug'] === $slug ) return $slug; // already exists — reuse
        }
        if ( count( $buckets ) >= 50 ) {
            return new WP_Error( 'too_many', 'This event already has the maximum number of albums.' );
        }
        $buckets[] = array( 'slug' => $slug, 'label' => $label, 'guest' => 1 );
        update_post_meta( $event_id, TEPE_CPT::META_CATEGORIES, $buckets );
        return $slug;
    }
}
