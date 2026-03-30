<?php
/**
 * Main Index Template (fallback)
 *
 * @package TwellerStudios
 */

get_header(); ?>

<div class="tw-page-header">
    <div class="tw-container">
        <h1 class="tw-page-header__title"><?php bloginfo( 'name' ); ?></h1>
        <p class="tw-page-header__subtitle">Latest Stories & Updates</p>
    </div>
</div>

<div class="tw-section">
    <div class="tw-container">
        <?php if ( have_posts() ) : ?>
            <div class="tw-posts-grid">
                <?php while ( have_posts() ) : the_post(); ?>
                    <article class="tw-post-card tw-reveal">
                        <?php if ( has_post_thumbnail() ) : ?>
                            <div class="tw-post-card__image">
                                <a href="<?php the_permalink(); ?>">
                                    <?php the_post_thumbnail( 'tweller-card' ); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                        <div class="tw-post-card__content">
                            <span class="tw-post-card__date"><?php echo get_the_date( 'M j, Y' ); ?></span>
                            <h3 class="tw-post-card__title">
                                <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                            </h3>
                            <p class="tw-post-card__excerpt"><?php echo get_the_excerpt(); ?></p>
                        </div>
                    </article>
                <?php endwhile; ?>
            </div>

            <div class="text-center" style="margin-top: 3rem;">
                <?php
                the_posts_pagination( array(
                    'mid_size'  => 2,
                    'prev_text' => '&larr; Previous',
                    'next_text' => 'Next &rarr;',
                ) );
                ?>
            </div>
        <?php else : ?>
            <div class="text-center">
                <p>No posts found.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php get_footer(); ?>
