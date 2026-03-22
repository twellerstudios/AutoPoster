<?php
/**
 * Search Results Template
 *
 * @package TwellerStudios
 */

get_header(); ?>

<div class="tw-page-header">
    <div class="tw-container">
        <span class="section-label">Search Results</span>
        <h1 class="tw-page-header__title">
            <?php printf( esc_html__( 'Results for: %s', 'tweller-studios' ), get_search_query() ); ?>
        </h1>
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
        <?php else : ?>
            <div class="text-center">
                <h3>Nothing found</h3>
                <p style="margin: 1rem 0 2rem;">We couldn't find what you're looking for. Try a different search.</p>
                <?php get_search_form(); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php get_footer(); ?>
