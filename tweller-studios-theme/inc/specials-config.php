<?php
/**
 * Seasonal Specials Configuration
 *
 * ============================================================
 * HOW TO UPDATE SPECIALS:
 * ============================================================
 * To change the seasonal specials, simply edit the arrays below.
 * Each special set has:
 *   - 'active'  => true/false (which set is currently displayed)
 *   - 'title'   => Section heading
 *   - 'label'   => Small label above the heading
 *   - 'icon'    => Emoji/icon for the section
 *   - 'subtitle'=> Description below the heading
 *   - 'packages'=> Array of package cards (mini, full, extended)
 *
 * Each package has:
 *   - 'name'     => Package name
 *   - 'price'    => Display price
 *   - 'original' => Original/regular price (shown as strikethrough)
 *   - 'badge'    => Small badge text (e.g., "Limited Time")
 *   - 'featured' => true/false (dark card styling)
 *   - 'features' => Array of bullet points
 *   - 'btn_text' => Button text
 *   - 'btn_url'  => Button link (use SureCart product URL)
 *
 * To switch specials: set 'active' => true on the one you want,
 * and 'active' => false on all others.
 * ============================================================
 *
 * @package TwellerStudios
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function tweller_get_specials() {
    $specials = array(

        // ============================================================
        // EASTER SPECIALS
        // ============================================================
        'easter' => array(
            'active'   => true,
            'title'    => 'Easter Specials',
            'label'    => 'Limited Time Offer',
            'icon'     => '&#10024;', // sparkle
            'subtitle' => 'Celebrate the season with portraits your family will treasure forever. Book your Easter session before spots fill up.',
            'packages' => array(
                array(
                    'name'     => 'Mini Session',
                    'price'    => '$500',
                    'original' => '$550',
                    'badge'    => 'Easter Special',
                    'featured' => false,
                    'features' => array(
                        'Up to 30 minutes',
                        '10 fully edited images',
                        'All digital copies included',
                        'Studio or on-location',
                    ),
                    'btn_text' => 'Book Mini',
                    'btn_url'  => '/checkout/?product=easter-mini',
                ),
                array(
                    'name'     => 'Full Session',
                    'price'    => '$700',
                    'original' => '$750',
                    'badge'    => 'Most Popular',
                    'featured' => true,
                    'features' => array(
                        'Up to 1 hour',
                        '30 fully edited images',
                        'All digital copies included',
                        'Studio or on-location',
                        'Outfit change included',
                    ),
                    'btn_text' => 'Book Full',
                    'btn_url'  => '/checkout/?product=easter-full',
                ),
                array(
                    'name'     => 'Extended Session',
                    'price'    => '$900',
                    'original' => '$950',
                    'badge'    => 'Best Value',
                    'featured' => false,
                    'features' => array(
                        'Up to 2 hours',
                        '50+ fully edited images',
                        'All digital copies included',
                        'Studio or on-location',
                        'Multiple outfit changes',
                        'Family groupings included',
                    ),
                    'btn_text' => 'Book Extended',
                    'btn_url'  => '/checkout/?product=easter-extended',
                ),
            ),
        ),

        // ============================================================
        // MOTHER'S DAY SPECIALS
        // ============================================================
        'mothers_day' => array(
            'active'   => true,
            'title'    => "Mommie & Me Sessions",
            'label'    => "Mother's Day Special",
            'icon'     => '&#10084;', // heart
            'subtitle' => "Celebrate the bond that means everything. Give mom the gift of memories she'll hold close forever.",
            'packages' => array(
                array(
                    'name'     => 'Mini Session',
                    'price'    => '$500',
                    'original' => '$550',
                    'badge'    => "Mother's Day",
                    'featured' => false,
                    'features' => array(
                        'Up to 30 minutes',
                        '10 fully edited images',
                        'All digital copies included',
                        'Studio or on-location',
                        'Mom + kids session',
                    ),
                    'btn_text' => 'Book Mini',
                    'btn_url'  => '/checkout/?product=mothers-day-mini',
                ),
                array(
                    'name'     => 'Full Session',
                    'price'    => '$700',
                    'original' => '$750',
                    'badge'    => 'Most Popular',
                    'featured' => true,
                    'features' => array(
                        'Up to 1 hour',
                        '30 fully edited images',
                        'All digital copies included',
                        'Studio or on-location',
                        'Mom + kids session',
                        'Outfit change included',
                    ),
                    'btn_text' => 'Book Full',
                    'btn_url'  => '/checkout/?product=mothers-day-full',
                ),
                array(
                    'name'     => 'Extended Session',
                    'price'    => '$900',
                    'original' => '$950',
                    'badge'    => 'Best Value',
                    'featured' => false,
                    'features' => array(
                        'Up to 2 hours',
                        '50+ fully edited images',
                        'All digital copies included',
                        'Studio or on-location',
                        'Mom + kids + family combos',
                        'Multiple outfit changes',
                    ),
                    'btn_text' => 'Book Extended',
                    'btn_url'  => '/checkout/?product=mothers-day-extended',
                ),
            ),
        ),

        // ============================================================
        // TEMPLATE: Copy this block for new specials
        // ============================================================
        /*
        'summer' => array(
            'active'   => false,
            'title'    => 'Summer Sessions',
            'label'    => 'Summer Special',
            'icon'     => '&#9728;', // sun
            'subtitle' => 'Your summer subtitle here.',
            'packages' => array(
                array(
                    'name'     => 'Mini Session',
                    'price'    => '$500',
                    'original' => '$550',
                    'badge'    => 'Summer Special',
                    'featured' => false,
                    'features' => array( 'Feature 1', 'Feature 2' ),
                    'btn_text' => 'Book Mini',
                    'btn_url'  => '/checkout/?product=summer-mini',
                ),
                // ... add Full and Extended
            ),
        ),
        */

    );

    return $specials;
}

/**
 * Get only the active specials
 */
function tweller_get_active_specials() {
    $all = tweller_get_specials();
    return array_filter( $all, function( $special ) {
        return ! empty( $special['active'] );
    } );
}
