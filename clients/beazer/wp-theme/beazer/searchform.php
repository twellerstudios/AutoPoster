<?php
/**
 * Search form.
 *
 * @package beazer
 */

$beazer_sid = wp_unique_id( 'search-' );
?>
<form role="search" method="get" class="search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label class="screen-reader-text" for="<?php echo esc_attr( $beazer_sid ); ?>"><?php esc_html_e( 'Search for', 'beazer' ); ?></label>
	<input type="search" id="<?php echo esc_attr( $beazer_sid ); ?>" class="search-field" placeholder="<?php esc_attr_e( 'Search BEAZER', 'beazer' ); ?>" value="<?php echo get_search_query(); ?>" name="s">
	<button type="submit" class="search-submit" aria-label="<?php esc_attr_e( 'Search', 'beazer' ); ?>"><?php echo beazer_icon( 'search' ); // phpcs:ignore ?></button>
</form>
