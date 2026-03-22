<?php
/**
 * 404 Page Template
 *
 * @package TwellerStudios
 */

get_header(); ?>

<section class="tw-hero" style="min-height: 80vh;">
    <div class="tw-hero__bg" style="background-color: var(--tw-black)"></div>
    <div class="tw-hero__content" style="opacity: 1;">
        <span class="section-label">Page Not Found</span>
        <h1 class="tw-hero__title" style="opacity: 1; animation: none;">
            404
        </h1>
        <p class="tw-hero__subtitle" style="opacity: 1; animation: none;">
            Looks like this moment wasn't captured. Let's get you back to something beautiful.
        </p>
        <div class="tw-hero__buttons" style="opacity: 1; animation: none;">
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="tw-btn tw-btn--primary">Back Home</a>
            <a href="<?php echo esc_url( home_url( '/#specials' ) ); ?>" class="tw-btn tw-btn--white">View Specials</a>
        </div>
    </div>
</section>

<?php get_footer(); ?>
