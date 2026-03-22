<?php
/**
 * Default Page Template
 *
 * @package TwellerStudios
 */

get_header(); ?>

<div class="tw-page-header">
    <div class="tw-container">
        <h1 class="tw-page-header__title"><?php the_title(); ?></h1>
    </div>
</div>

<div class="tw-section">
    <div class="tw-container">
        <div class="tw-page-content tw-page-content--narrow">
            <?php
            while ( have_posts() ) :
                the_post();
                the_content();
            endwhile;
            ?>
        </div>
    </div>
</div>

<?php get_footer(); ?>
