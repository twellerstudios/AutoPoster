<?php
/**
 * Fallback listing: blog posts, archives and search results.
 *
 * @package beazer
 */

get_header();
if ( is_search() ) {
	/* translators: %s: search query */
	$title = sprintf( __( 'Search: %s', 'beazer' ), get_search_query() );
} elseif ( is_home() ) {
	$title = single_post_title( '', false ) ?: __( 'News', 'beazer' );
} else {
	$title = wp_strip_all_tags( get_the_archive_title() );
}
?>
<main id="main" class="inner">
	<?php get_template_part( 'parts/page-banner', null, array( 'title' => $title ) ); ?>
	<div class="inner-content">
		<?php if ( have_posts() ) : ?>
			<ul class="post-list">
				<?php
				while ( have_posts() ) :
					the_post();
					?>
					<li>
						<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
						<?php if ( 'post' === get_post_type() ) : ?>
							<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
						<?php endif; ?>
						<?php the_excerpt(); ?>
					</li>
				<?php endwhile; ?>
			</ul>
			<?php the_posts_pagination(); ?>
		<?php else : ?>
			<p><?php esc_html_e( 'Nothing found. Try another search.', 'beazer' ); ?></p>
			<?php get_search_form(); ?>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
