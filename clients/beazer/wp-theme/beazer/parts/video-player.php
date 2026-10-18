<?php
/**
 * FEATURED VIDEO panel. With a YouTube link set it plays in a lightbox
 * (youtube-nocookie, loaded only on click); otherwise it links out.
 *
 * @package beazer
 */

$video_url = beazer_mod( 'video_url' );
$video_id  = beazer_youtube_id( $video_url );
$watch_url = beazer_mod( 'watch_url' ) ?: ( $video_url ?: beazer_mod( 'youtube_url' ) );
$thumb     = beazer_mod( 'video_thumb' );
$label     = sprintf( /* translators: %s: video label */ __( 'Play %s', 'beazer' ), ucwords( strtolower( beazer_mod( 'video_label' ) ) ) );
$tag       = $video_id ? 'button' : 'a';
?>
<div class="featured-head">
	<h2 class="featured-title" id="featured-title"><?php echo esc_html( beazer_mod( 'video_label' ) ); ?></h2>
	<a class="watch-now" href="<?php echo esc_url( $watch_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'WATCH NOW', 'beazer' ); ?><?php echo beazer_icon( 'chevron' ); // phpcs:ignore ?></a>
</div>
<div class="video-frame">
	<<?php echo $tag; // phpcs:ignore ?> class="video-play"
		<?php if ( $video_id ) : ?>
			type="button" data-video="<?php echo esc_attr( $video_id ); ?>"
		<?php else : ?>
			href="<?php echo esc_url( $watch_url ); ?>" target="_blank" rel="noopener"
		<?php endif; ?>
		aria-label="<?php echo esc_attr( $label ); ?>">
		<?php if ( $thumb ) : ?>
			<img src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy">
		<?php elseif ( $video_id ) : ?>
			<img src="<?php echo esc_url( 'https://i.ytimg.com/vi/' . $video_id . '/hqdefault.jpg' ); ?>" alt="" loading="lazy">
		<?php else : ?>
			<picture>
				<source type="image/webp" sizes="(min-width: 1100px) 40vw, 100vw" srcset="<?php echo beazer_srcset( 'sample-video', array( 640, 1190 ) ); // phpcs:ignore ?>">
				<img src="<?php echo esc_url( beazer_asset( 'img/sample-video-1190.jpg' ) ); ?>" width="1190" height="412" alt="" loading="lazy">
			</picture>
		<?php endif; ?>
		<span class="play-circle" aria-hidden="true"><?php echo beazer_icon( 'play' ); // phpcs:ignore ?></span>
	</<?php echo $tag; // phpcs:ignore ?>>
</div>
<?php if ( $video_id ) : ?>
<dialog class="video-dialog" aria-label="<?php echo esc_attr( beazer_mod( 'video_label' ) ); ?>">
	<button class="video-close" type="button" aria-label="<?php esc_attr_e( 'Close video', 'beazer' ); ?>"><?php echo beazer_icon( 'close' ); // phpcs:ignore ?></button>
	<div class="video-embed"></div>
</dialog>
<?php endif; ?>
