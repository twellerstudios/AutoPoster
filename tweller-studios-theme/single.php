<?php
/**
 * Single Post Template
 *
 * @package TwellerStudios
 */

get_header(); ?>

<?php while ( have_posts() ) : the_post(); ?>

<div class="tw-page-header">
    <div class="tw-container">
        <span class="section-label"><?php echo get_the_date( 'F j, Y' ); ?></span>
        <h1 class="tw-page-header__title"><?php the_title(); ?></h1>
        <p class="tw-page-header__subtitle"><?php echo get_the_category_list( ', ' ); ?></p>
    </div>
</div>

<article class="tw-section">
    <div class="tw-container">
        <div class="tw-page-content tw-page-content--narrow">
            <?php if ( has_post_thumbnail() ) : ?>
                <div style="margin-bottom: 3rem;">
                    <?php the_post_thumbnail( 'tweller-hero' ); ?>
                </div>
            <?php endif; ?>

            <?php the_content(); ?>

            <div style="margin-top: 4rem; padding-top: 2rem; border-top: 1px solid var(--tw-gray-100);">
                <?php
                the_post_navigation( array(
                    'prev_text' => '<span class="section-label">Previous</span><br>%title',
                    'next_text' => '<span class="section-label">Next</span><br>%title',
                ) );
                ?>
            </div>
        </div>
    </div>
</article>

<?php endwhile; ?>

<?php get_footer(); ?>
