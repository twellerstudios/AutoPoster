<?php
/**
 * Homepage: the approved BEAZER design.
 *
 * At desktop widths (1100px+) every element is placed on the 1536×1024 artwork
 * exactly as in the approved mockup. Below that it becomes a stacked layout.
 *
 * @package beazer
 */

get_header();

$desktop_art = beazer_mod( 'hero_art_desktop' );
$mobile_art  = beazer_mod( 'hero_art_mobile' );
$cover       = beazer_mod( 'release_cover' );
$release     = array(
	'spotify' => beazer_mod_or( 'release_spotify', 'spotify_url' ),
	'apple'   => beazer_mod_or( 'release_apple', 'apple_url' ),
	'youtube' => beazer_mod_or( 'release_youtube', 'youtube_url' ),
);
$platforms   = beazer_platforms();
$listen_url  = beazer_mod( 'listen_url' ) ?: beazer_page_url( 'media' );
$feature_url = beazer_mod( 'release_listen' ) ?: beazer_page_url( 'media' );
?>
<main id="main" class="stage">
	<picture class="stage-art">
		<?php if ( $desktop_art ) : ?>
			<source media="(min-width: 1100px)" srcset="<?php echo esc_url( $desktop_art ); ?>">
		<?php else : ?>
			<source media="(min-width: 1100px)" type="image/webp" sizes="100vw" srcset="<?php echo beazer_srcset( 'scene-desktop', array( 1536, 2304, 3072 ) ); // phpcs:ignore ?>">
			<source media="(min-width: 1100px)" srcset="<?php echo esc_url( beazer_asset( 'img/scene-desktop-1536.jpg' ) ); ?>">
		<?php endif; ?>
		<?php if ( $mobile_art ) : ?>
			<img src="<?php echo esc_url( $mobile_art ); ?>" alt="<?php esc_attr_e( 'Joel of BEAZER singing and playing guitar on stage under a fiery tropical sky', 'beazer' ); ?>" fetchpriority="high">
		<?php else : ?>
			<source type="image/webp" sizes="100vw" srcset="<?php echo beazer_srcset( 'scene-hero', array( 720, 1080, 1440 ) ); // phpcs:ignore ?>">
			<img src="<?php echo esc_url( beazer_asset( 'img/scene-hero-720.jpg' ) ); ?>" width="1440" height="1240" alt="<?php esc_attr_e( 'Joel of BEAZER singing and playing guitar on stage under a fiery tropical sky', 'beazer' ); ?>" fetchpriority="high">
		<?php endif; ?>
	</picture>

	<section class="hero" aria-label="<?php esc_attr_e( 'Introduction', 'beazer' ); ?>">
		<h1 class="wordmark">
			<span class="screen-reader-text">BEAZER</span>
			<img class="wordmark-img" src="<?php echo esc_url( beazer_asset( 'img/logo-silver.webp' ) ); ?>" width="583" height="276" alt="">
		</h1>
		<p class="tagline"><span class="tagline-white"><?php echo esc_html( beazer_mod( 'tagline_white' ) ); ?></span> <span class="tagline-red"><?php echo esc_html( beazer_mod( 'tagline_red' ) ); ?></span></p>
		<div class="cta">
			<a class="btn btn-red btn-listen" href="<?php echo esc_url( $listen_url ); ?>"<?php echo beazer_mod( 'listen_url' ) ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo beazer_icon( 'play', 'btn-icon' ); // phpcs:ignore ?><span><?php echo esc_html( beazer_mod( 'listen_label' ) ); ?></span></a>
			<a class="btn btn-dark btn-fan" href="<?php echo esc_url( beazer_fanclub_url() ); ?>"><?php echo beazer_icon( 'fans', 'btn-icon' ); // phpcs:ignore ?><span><?php echo esc_html( beazer_mod( 'fanclub_label' ) ); ?></span></a>
		</div>
		<ul class="platforms" aria-label="<?php esc_attr_e( 'BEAZER on music and social platforms', 'beazer' ); ?>">
			<?php foreach ( $platforms as $key => $p ) : ?>
				<li class="platform platform-<?php echo esc_attr( $key ); ?>">
					<a <?php echo beazer_platform_link( $p['url'], $p['name'] ); // phpcs:ignore ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: platform name */ __( 'BEAZER on %s', 'beazer' ), $p['name'] ) ); ?>">
						<?php echo beazer_icon( $key, 'platform-icon' ); // phpcs:ignore ?>
						<span class="platform-label"><?php echo esc_html( $p['label'] ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>

	<section class="release" aria-labelledby="release-title">
		<p class="brush-label release-label"><?php echo esc_html( beazer_mod( 'release_label' ) ); ?></p>
		<figure class="cover">
			<?php if ( $cover ) : ?>
				<img src="<?php echo esc_url( $cover ); ?>" alt="<?php echo esc_attr( beazer_mod( 'release_title' ) ); ?>" loading="lazy">
			<?php else : ?>
				<img src="<?php echo esc_url( beazer_asset( 'img/band/joel-live.webp' ) ); ?>" width="680" height="850" alt="<?php esc_attr_e( 'Joel Beazer pointing at the camera mid-song', 'beazer' ); ?>" loading="lazy">
			<?php endif; ?>
		</figure>
		<div class="release-info">
			<h2 class="release-title" id="release-title"><?php echo esc_html( beazer_mod( 'release_title' ) ); ?></h2>
			<p class="release-subtitle"><?php echo esc_html( beazer_mod( 'release_subtitle' ) ); ?></p>
			<p class="release-copy"><?php echo esc_html( beazer_mod( 'release_copy' ) ); ?></p>
			<div class="release-actions">
				<?php foreach ( $release as $key => $url ) : ?>
					<a class="mini" <?php echo beazer_platform_link( $url, $platforms[ $key ]['name'] ); // phpcs:ignore ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: platform */ __( 'BEAZER on %s', 'beazer' ), $platforms[ $key ]['name'] ) ); ?>"><?php echo beazer_icon( $key ); // phpcs:ignore ?></a>
				<?php endforeach; ?>
				<a class="btn btn-red btn-sm" href="<?php echo esc_url( $feature_url ); ?>"><?php echo esc_html( beazer_mod( 'release_button' ) ); ?></a>
			</div>
		</div>
	</section>

	<section class="featured" aria-labelledby="featured-title">
		<?php get_template_part( 'parts/video-player' ); ?>
	</section>
</main>
<?php
get_footer();
