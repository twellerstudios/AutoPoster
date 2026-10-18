<?php
/**
 * Site header: red brand mark, the seven approved menu items, search and cart.
 *
 * @package beazer
 */

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php get_template_part( 'parts/icons' ); ?>
<a class="skip-link" href="#main"><?php esc_html_e( 'Skip to content', 'beazer' ); ?></a>
<div class="site">
<header class="site-header" id="top">
	<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
		<?php
		if ( has_custom_logo() ) {
			echo wp_get_attachment_image( get_theme_mod( 'custom_logo' ), 'full', false, array( 'alt' => 'BEAZER', 'class' => 'brand-logo' ) );
		} else {
			printf( '<img class="brand-logo" src="%s" alt="BEAZER" width="640" height="216">', esc_url( beazer_asset( 'img/logo-red.webp' ) ) );
		}
		?>
	</a>
	<nav class="main-nav" id="main-nav" aria-label="<?php esc_attr_e( 'Main', 'beazer' ); ?>">
		<?php beazer_primary_nav(); ?>
	</nav>
	<div class="header-tools">
		<button class="tool tool-search" type="button" aria-label="<?php esc_attr_e( 'Search', 'beazer' ); ?>" aria-controls="search-panel" aria-expanded="false"><?php echo beazer_icon( 'search' ); // phpcs:ignore ?></button>
		<a class="tool tool-cart" href="<?php echo esc_url( beazer_cart_url() ); ?>" aria-label="<?php esc_attr_e( 'Cart', 'beazer' ); ?>"><?php echo beazer_icon( 'cart' ); // phpcs:ignore ?></a>
		<button class="tool tool-menu" type="button" aria-label="<?php esc_attr_e( 'Menu', 'beazer' ); ?>" aria-controls="main-nav" aria-expanded="false"><?php echo beazer_icon( 'menu', 'when-closed' ) . beazer_icon( 'close', 'when-open' ); // phpcs:ignore ?></button>
	</div>
	<div class="search-panel" id="search-panel" hidden>
		<?php get_search_form(); ?>
	</div>
</header>
