<?php
/**
 * Inner pages (Merch, Tour, Fan Club, Media, About, Contact and any new page).
 *
 * @package beazer
 */

get_header();
while ( have_posts() ) :
	the_post();
	?>
	<main id="main" class="inner">
		<?php get_template_part( 'parts/page-banner', null, array( 'title' => get_the_title() ) ); ?>
		<article <?php post_class( 'inner-content' ); ?>>
			<?php
			the_content();
			wp_link_pages();
			?>
		</article>
	</main>
	<?php
endwhile;
get_footer();
