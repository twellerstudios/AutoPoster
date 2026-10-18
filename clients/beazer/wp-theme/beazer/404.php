<?php
/**
 * Not found.
 *
 * @package beazer
 */

get_header();
?>
<main id="main" class="inner">
	<?php get_template_part( 'parts/page-banner', null, array( 'title' => __( 'Lost in the storm', 'beazer' ) ) ); ?>
	<div class="inner-content">
		<p><?php esc_html_e( 'That page does not exist. Head back home or search below.', 'beazer' ); ?></p>
		<?php get_search_form(); ?>
		<p><a class="btn btn-red btn-form" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'BACK HOME', 'beazer' ); ?></a></p>
	</div>
</main>
<?php
get_footer();
