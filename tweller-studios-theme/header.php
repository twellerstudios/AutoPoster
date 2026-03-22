<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="profile" href="https://gmpg.org/xfn/11">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<?php
// Announcement Banner
$banner_enabled = get_theme_mod( 'tweller_banner_enabled', true );
if ( $banner_enabled ) :
    $banner_text = get_theme_mod( 'tweller_banner_text', 'Easter Specials are here — Book your session today!' );
    $banner_link_text = get_theme_mod( 'tweller_banner_link_text', 'Book Now' );
    $banner_link_url = get_theme_mod( 'tweller_banner_link_url', '#specials' );
?>
<div class="tw-specials-banner">
    <div class="tw-container">
        <p class="tw-specials-banner__text">
            <?php echo wp_kses_post( $banner_text ); ?>
            <?php if ( $banner_link_text && $banner_link_url ) : ?>
                &nbsp;&mdash;&nbsp;<a href="<?php echo esc_url( $banner_link_url ); ?>"><?php echo esc_html( $banner_link_text ); ?> &rarr;</a>
            <?php endif; ?>
        </p>
    </div>
</div>
<?php endif; ?>

<header class="tw-header" id="site-header">
    <div class="tw-container">
        <div class="tw-header__inner">
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="tw-header__logo" aria-label="<?php bloginfo( 'name' ); ?>">
                <?php if ( has_custom_logo() ) : ?>
                    <?php
                    $logo_id = get_theme_mod( 'custom_logo' );
                    $logo_url = wp_get_attachment_image_url( $logo_id, 'full' );
                    ?>
                    <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php bloginfo( 'name' ); ?>">
                <?php else : ?>
                    Tweller <span>Studios</span>
                <?php endif; ?>
            </a>

            <nav class="tw-nav" id="site-nav" role="navigation" aria-label="Primary Navigation">
                <?php
                if ( has_nav_menu( 'primary' ) ) {
                    wp_nav_menu( array(
                        'theme_location' => 'primary',
                        'container'      => false,
                        'items_wrap'     => '%3$s',
                        'walker'         => new Tweller_Nav_Walker(),
                    ) );
                } else {
                    // Default fallback navigation
                    ?>
                    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="tw-nav__link tw-nav__link--active">Home</a>
                    <a href="<?php echo esc_url( home_url( '/#about' ) ); ?>" class="tw-nav__link">About</a>
                    <a href="<?php echo esc_url( home_url( '/#services' ) ); ?>" class="tw-nav__link">Services</a>
                    <a href="<?php echo esc_url( home_url( '/#gallery' ) ); ?>" class="tw-nav__link">Gallery</a>
                    <a href="<?php echo esc_url( home_url( '/#testimonials' ) ); ?>" class="tw-nav__link">Reviews</a>
                    <a href="<?php echo esc_url( home_url( '/#specials' ) ); ?>" class="tw-btn tw-btn--outline tw-nav__cta">Book Now</a>
                    <?php
                }
                ?>
            </nav>

            <button class="tw-menu-toggle" id="menu-toggle" aria-label="Toggle Menu" aria-expanded="false">
                <span></span>
                <span></span>
                <span></span>
            </button>
        </div>
    </div>
</header>

<main id="site-content">
