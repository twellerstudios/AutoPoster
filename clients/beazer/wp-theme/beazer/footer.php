<?php
/**
 * Site footer.
 *
 * @package beazer
 */

?>
<footer class="site-footer">
	<ul class="footer-social">
		<?php foreach ( beazer_platforms() as $key => $p ) : ?>
			<li><a href="<?php echo esc_url( $p['url'] ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( $p['name'] ); ?>"><?php echo beazer_icon( $key ); // phpcs:ignore ?></a></li>
		<?php endforeach; ?>
	</ul>
	<?php
	if ( has_nav_menu( 'footer' ) ) {
		wp_nav_menu( array( 'theme_location' => 'footer', 'container' => 'nav', 'container_class' => 'footer-nav', 'depth' => 1 ) );
	}
	?>
	<p class="footer-legal">&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> BEAZER. <?php esc_html_e( 'All rights reserved.', 'beazer' ); ?>
		<?php if ( beazer_mod( 'footer_credit' ) ) : ?>
			<span class="sep">|</span> <a href="<?php echo esc_url( beazer_mod( 'footer_credit_url' ) ); ?>"><?php echo esc_html( beazer_mod( 'footer_credit' ) ); ?></a>
		<?php endif; ?>
	</p>
</footer>
</div><!-- .site -->
<?php wp_footer(); ?>
</body>
</html>
