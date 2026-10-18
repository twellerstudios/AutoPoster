<?php
/**
 * FEATURED VIDEO panel. With a YouTube link set it plays in a lightbox
 * (youtube-nocookie, loaded only on click). Without one it shows a first-jam
 * still that leads to the Media page, where it reads "film coming soon".
 *
 * @package beazer
 */

$video_url = beazer_mod( 'video_url' );
$video_id  = beazer_youtube_id( $video_url );
$on_media  = is_page( 'media' );
$watch_url = beazer_mod( 'watch_url' ) ?: ( $video_url ?: ( $on_media ? '' : beazer_page_url( 'media' ) ) );
$thumb     = beazer_mod( 'video_thumb' );
$label     = sprintf( /* translators: %s: video label */ __( 'Play %s', 'beazer' ), ucwords( strtolower( beazer_mod( 'video_label' ) ) ) );
$external  = $watch_url && 0 !== strpos( $watch_url, home_url() );
if ( $video_id ) {
	$tag = 'button';
} elseif ( $on_media ) {
	$tag = 'div';
} else {
	$tag = 'a';
}
?>
<div class="featured-head">
	<h2 class="featured-title" id="featured-title"><?php echo esc_html( beazer_mod( 'video_label' ) ); ?></h2>
	<?php if ( $watch_url ) : ?>
		<a class="watch-now" href="<?php echo esc_url( $watch_url ); ?>"<?php echo $external ? ' target="_blank" rel="noopener"' : ''; ?>><?php esc_html_e( 'WATCH NOW', 'beazer' ); ?><?php echo beazer_icon( 'chevron' ); // phpcs:ignore ?></a>
	<?php endif; ?>
</div>
<div class="video-frame">
	<<?php echo $tag; // phpcs:ignore ?> class="video-play<?php echo 'div' === $tag ? ' is-soon' : ''; ?>"
		<?php if ( $video_id ) : ?>
			type="button" data-video="<?php echo esc_attr( $video_id ); ?>" aria-label="<?php echo esc_attr( $label ); ?>"
		<?php elseif ( 'a' === $tag ) : ?>
			href="<?php echo esc_url( $watch_url ); ?>"<?php echo $external ? ' target="_blank" rel="noopener"' : ''; ?> aria-label="<?php echo esc_attr( $label ); ?>"
		<?php endif; ?>>
		<?php if ( $thumb ) : ?>
			<img src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy">
		<?php elseif ( $video_id ) : ?>
			<img src="<?php echo esc_url( 'https://i.ytimg.com/vi/' . $video_id . '/hqdefault.jpg' ); ?>" alt="" loading="lazy">
		<?php else : ?>
			<img src="<?php echo esc_url( beazer_asset( 'img/band/room-between-takes.webp' ) ); ?>" width="760" height="428" alt="<?php esc_attr_e( 'Beazer with arms up between takes at the first jam', 'beazer' ); ?>" loading="lazy">
		<?php endif; ?>
		<?php if ( 'div' === $tag ) : ?>
			<span class="soon-badge"><?php esc_html_e( 'FIRST-JAM FILM · COMING SOON', 'beazer' ); ?></span>
		<?php else : ?>
			<span class="play-circle" aria-hidden="true"><?php echo beazer_icon( 'play' ); // phpcs:ignore ?></span>
		<?php endif; ?>
	</<?php echo $tag; // phpcs:ignore ?>>
</div>
<?php if ( $video_id ) : ?>
<dialog class="video-dialog" aria-label="<?php echo esc_attr( beazer_mod( 'video_label' ) ); ?>">
	<button class="video-close" type="button" aria-label="<?php esc_attr_e( 'Close video', 'beazer' ); ?>"><?php echo beazer_icon( 'close' ); // phpcs:ignore ?></button>
	<div class="video-embed"></div>
</dialog>
<?php endif; ?>
