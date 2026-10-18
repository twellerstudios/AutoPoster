<?php
/**
 * Inner-page banner: the top of the approved artwork with the page title.
 *
 * @package beazer
 */

$title = $args['title'] ?? '';
?>
<div class="page-banner">
	<picture class="page-banner-art">
		<source media="(min-width: 700px)" type="image/webp" sizes="100vw" srcset="<?php echo beazer_srcset( 'scene-banner', array( 1024, 1536, 3072 ) ); // phpcs:ignore ?>">
		<source type="image/webp" sizes="100vw" srcset="<?php echo beazer_srcset( 'scene-hero', array( 720, 1080, 1440 ) ); // phpcs:ignore ?>">
		<img src="<?php echo esc_url( beazer_asset( 'img/scene-banner-1024.jpg' ) ); ?>" alt="">
	</picture>
	<h1 class="page-title"><?php echo esc_html( $title ); ?></h1>
</div>
