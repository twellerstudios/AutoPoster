<?php
/**
 * Front Page Template
 *
 * @package TwellerStudios
 */

get_header();

$hero_bg       = get_theme_mod( 'tweller_hero_bg', '' );
$hero_tagline  = get_theme_mod( 'tweller_hero_tagline', 'Tweller Studios' );
$hero_title_1  = get_theme_mod( 'tweller_hero_title_1', 'Your Story' );
$hero_title_2  = get_theme_mod( 'tweller_hero_title_2', 'Deserves This' );
$hero_subtitle = get_theme_mod( 'tweller_hero_subtitle', "Photography & videography that feels like you. No stiff poses, no awkward smiles — just the real, beautiful moments that matter most." );
$hero_btn_text = get_theme_mod( 'tweller_hero_btn_text', 'View Specials' );
$hero_btn_url  = get_theme_mod( 'tweller_hero_btn_url', '#specials' );
$hero_btn2_text = get_theme_mod( 'tweller_hero_btn2_text', 'Our Work' );
$hero_btn2_url = get_theme_mod( 'tweller_hero_btn2_url', '#gallery' );
?>

<!-- ============================================================
     HERO SECTION
     ============================================================ -->
<section class="tw-hero">
    <?php if ( $hero_bg ) : ?>
        <div class="tw-hero__bg" style="background-image: url('<?php echo esc_url( $hero_bg ); ?>')"></div>
    <?php else : ?>
        <div class="tw-hero__bg" style="background-image: url('https://images.unsplash.com/photo-1519741497674-611481863552?w=1920&q=80&auto=format&fit=crop'); background-color: var(--tw-black);"></div>
    <?php endif; ?>
    <div class="tw-hero__overlay"></div>

    <div class="tw-hero__content">
        <p class="tw-hero__tagline"><?php echo esc_html( $hero_tagline ); ?></p>
        <h1 class="tw-hero__title">
            <?php echo esc_html( $hero_title_1 ); ?>
            <span><?php echo esc_html( $hero_title_2 ); ?></span>
        </h1>
        <p class="tw-hero__subtitle"><?php echo esc_html( $hero_subtitle ); ?></p>
        <div class="tw-hero__buttons">
            <?php if ( $hero_btn_text ) : ?>
                <a href="<?php echo esc_url( $hero_btn_url ); ?>" class="tw-btn tw-btn--primary"><?php echo esc_html( $hero_btn_text ); ?></a>
            <?php endif; ?>
            <?php if ( $hero_btn2_text ) : ?>
                <a href="<?php echo esc_url( $hero_btn2_url ); ?>" class="tw-btn tw-btn--white"><?php echo esc_html( $hero_btn2_text ); ?></a>
            <?php endif; ?>
        </div>
    </div>

    <div class="tw-hero__scroll">
        <div class="tw-hero__scroll-indicator">
            <span>Scroll</span>
            <div class="tw-hero__scroll-line"></div>
        </div>
    </div>
</section>

<!-- ============================================================
     SEASONAL SPECIALS
     ============================================================ -->
<?php
$active_specials = tweller_get_active_specials();

if ( ! empty( $active_specials ) ) :
    $first_special = true;
    foreach ( $active_specials as $key => $special ) :
?>
<section class="tw-section tw-specials" id="<?php echo $first_special ? 'specials' : 'specials-' . esc_attr( $key ); ?>">
<?php $first_special = false; ?>
    <div class="tw-container">
        <div class="tw-specials__header tw-reveal">
            <div class="tw-specials__icon"><?php echo $special['icon']; ?></div>
            <span class="section-label"><?php echo esc_html( $special['label'] ); ?></span>
            <h2 class="section-title"><?php echo esc_html( $special['title'] ); ?></h2>
            <p class="section-subtitle mx-auto"><?php echo esc_html( $special['subtitle'] ); ?></p>
        </div>

        <div class="tw-specials__cards">
            <?php foreach ( $special['packages'] as $i => $pkg ) : ?>
                <div class="tw-special-card <?php echo $pkg['featured'] ? 'tw-special-card--featured' : ''; ?> tw-reveal tw-reveal--delay-<?php echo $i + 1; ?>">
                    <?php if ( ! empty( $pkg['badge'] ) ) : ?>
                        <div class="tw-special-card__badge"><?php echo esc_html( $pkg['badge'] ); ?></div>
                    <?php endif; ?>
                    <h3 class="tw-special-card__name"><?php echo esc_html( $pkg['name'] ); ?></h3>
                    <div class="tw-special-card__price"><?php echo esc_html( $pkg['price'] ); ?></div>
                    <?php if ( ! empty( $pkg['original'] ) ) : ?>
                        <div class="tw-special-card__original-price">Regular: <?php echo esc_html( $pkg['original'] ); ?></div>
                    <?php endif; ?>
                    <ul class="tw-special-card__details">
                        <?php foreach ( $pkg['features'] as $feature ) : ?>
                            <li><?php echo esc_html( $feature ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="<?php echo esc_url( $pkg['btn_url'] ); ?>" class="tw-btn <?php echo $pkg['featured'] ? 'tw-btn--primary' : 'tw-btn--dark'; ?>">
                        <?php echo esc_html( $pkg['btn_text'] ); ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php
    endforeach;
endif;
?>

<!-- ============================================================
     SERVICES
     ============================================================ -->
<section class="tw-section tw-section--dark" id="services">
    <div class="tw-container">
        <div class="text-center tw-reveal" style="margin-bottom: 4rem;">
            <span class="section-label">What We Do</span>
            <h2 class="section-title">Our Services</h2>
            <p class="section-subtitle mx-auto">Every service we offer starts with the same thing — listening to you, understanding what matters, and delivering something you'll love.</p>
        </div>

        <div class="tw-services__grid">
            <div class="tw-service-card tw-reveal tw-reveal--delay-1">
                <div class="tw-service-card__bg" style="background-image: url('https://images.unsplash.com/photo-1606216794074-735e91aa2c92?w=800&q=80&auto=format&fit=crop'); background-color: var(--tw-gray-800);"></div>
                <div class="tw-service-card__overlay"></div>
                <div class="tw-service-card__content">
                    <h3 class="tw-service-card__title">Wedding Photography</h3>
                    <p class="tw-service-card__desc">Every glance, every laugh, every tear of joy — captured naturally so you can relive your day exactly as it felt.</p>
                    <a href="#specials" class="tw-service-card__link">View Packages &rarr;</a>
                </div>
            </div>

            <div class="tw-service-card tw-reveal tw-reveal--delay-2">
                <div class="tw-service-card__bg" style="background-image: url('https://images.unsplash.com/photo-1581579438747-104c53d7fbc4?w=800&q=80&auto=format&fit=crop'); background-color: var(--tw-gray-800);"></div>
                <div class="tw-service-card__overlay"></div>
                <div class="tw-service-card__content">
                    <h3 class="tw-service-card__title">Family Portraits</h3>
                    <p class="tw-service-card__desc">The messy hair, the real laughs, the way your kids look right now — that's what makes a family portrait worth keeping.</p>
                    <a href="#specials" class="tw-service-card__link">View Packages &rarr;</a>
                </div>
            </div>

            <div class="tw-service-card tw-reveal tw-reveal--delay-3">
                <div class="tw-service-card__bg" style="background-image: url('https://images.unsplash.com/photo-1492691527719-9d1e07e534b4?w=800&q=80&auto=format&fit=crop'); background-color: var(--tw-gray-800);"></div>
                <div class="tw-service-card__overlay"></div>
                <div class="tw-service-card__content">
                    <h3 class="tw-service-card__title">Cinematic Videography</h3>
                    <p class="tw-service-card__desc">Moving images that move you. We turn your moments into films you'll want to watch again and again.</p>
                    <a href="#specials" class="tw-service-card__link">View Packages &rarr;</a>
                </div>
            </div>

            <div class="tw-service-card tw-reveal tw-reveal--delay-4">
                <div class="tw-service-card__bg" style="background-image: url('https://images.unsplash.com/photo-1511795409834-ef04bbd61622?w=800&q=80&auto=format&fit=crop'); background-color: var(--tw-gray-800);"></div>
                <div class="tw-service-card__overlay"></div>
                <div class="tw-service-card__content">
                    <h3 class="tw-service-card__title">Event Coverage</h3>
                    <p class="tw-service-card__desc">Birthdays, reunions, milestones — we blend in and capture the energy so you can actually enjoy the party.</p>
                    <a href="#specials" class="tw-service-card__link">View Packages &rarr;</a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     ABOUT / STORY
     ============================================================ -->
<?php
$about_image = get_theme_mod( 'tweller_about_image', '' );
$about_text  = get_theme_mod( 'tweller_about_text', "We're not just photographers — we're people who genuinely love what we do. Every family, every couple, every little milestone matters to us because we know how fast these moments go. We're here to make sure you never lose them." );
$stat1_num   = get_theme_mod( 'tweller_about_stat1_num', '500+' );
$stat1_label = get_theme_mod( 'tweller_about_stat1_label', 'Sessions Shot' );
$stat2_num   = get_theme_mod( 'tweller_about_stat2_num', '96%' );
$stat2_label = get_theme_mod( 'tweller_about_stat2_label', 'Would Recommend' );
$stat3_num   = get_theme_mod( 'tweller_about_stat3_num', '8+' );
$stat3_label = get_theme_mod( 'tweller_about_stat3_label', 'Years Experience' );
?>
<section class="tw-section" id="about">
    <div class="tw-container">
        <div class="tw-about">
            <div class="tw-about__image tw-reveal">
                <?php if ( $about_image ) : ?>
                    <img src="<?php echo esc_url( $about_image ); ?>" alt="About Tweller Studios">
                <?php else : ?>
                    <img src="https://images.unsplash.com/photo-1554048612-b6a482bc67e5?w=800&q=80&auto=format&fit=crop" alt="Photographer behind the scenes">
                <?php endif; ?>
            </div>

            <div class="tw-about__content tw-reveal tw-reveal--delay-2">
                <span class="section-label">Our Story</span>
                <h2 class="section-title">The People Behind the Lens</h2>
                <p class="tw-about__text"><?php echo wp_kses_post( $about_text ); ?></p>
                <a href="#specials" class="tw-btn tw-btn--primary">Book a Session</a>

                <div class="tw-about__stats">
                    <div class="tw-stat">
                        <span class="tw-stat__number"><?php echo esc_html( $stat1_num ); ?></span>
                        <span class="tw-stat__label"><?php echo esc_html( $stat1_label ); ?></span>
                    </div>
                    <div class="tw-stat">
                        <span class="tw-stat__number"><?php echo esc_html( $stat2_num ); ?></span>
                        <span class="tw-stat__label"><?php echo esc_html( $stat2_label ); ?></span>
                    </div>
                    <div class="tw-stat">
                        <span class="tw-stat__number"><?php echo esc_html( $stat3_num ); ?></span>
                        <span class="tw-stat__label"><?php echo esc_html( $stat3_label ); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     GALLERY
     ============================================================ -->
<section class="tw-section tw-section--cream" id="gallery">
    <div class="tw-container">
        <div class="text-center tw-reveal" style="margin-bottom: 3rem;">
            <span class="section-label">Our Work</span>
            <h2 class="section-title">Recent Sessions</h2>
            <p class="section-subtitle mx-auto">A glimpse into the moments we've had the honor of capturing.</p>
        </div>

        <div class="tw-gallery__grid tw-reveal">
            <?php
            // Pull recent posts with featured images, or show placeholders
            $gallery_query = new WP_Query( array(
                'posts_per_page' => 5,
                'post_status'    => 'publish',
                'meta_key'       => '_thumbnail_id',
            ) );

            if ( $gallery_query->have_posts() ) :
                $count = 0;
                while ( $gallery_query->have_posts() ) :
                    $gallery_query->the_post();
                    $count++;
                    ?>
                    <div class="tw-gallery__item">
                        <?php the_post_thumbnail( 'tweller-gallery' ); ?>
                        <div class="tw-gallery__item-overlay">
                            <span>View</span>
                        </div>
                    </div>
                    <?php
                endwhile;
                wp_reset_postdata();
            else :
                // Default gallery images from Unsplash (replace with your own!)
                $default_gallery = array(
                    array( 'src' => 'https://images.unsplash.com/photo-1537633552985-df8429e8048b?w=800&q=80&auto=format&fit=crop', 'alt' => 'Couple portrait session' ),
                    array( 'src' => 'https://images.unsplash.com/photo-1609220136736-443140cffec6?w=600&q=80&auto=format&fit=crop', 'alt' => 'Family lifestyle photography' ),
                    array( 'src' => 'https://images.unsplash.com/photo-1519741497674-611481863552?w=600&q=80&auto=format&fit=crop', 'alt' => 'Wedding day moments' ),
                    array( 'src' => 'https://images.unsplash.com/photo-1544126592-807ade215a0b?w=600&q=80&auto=format&fit=crop', 'alt' => 'Newborn session' ),
                    array( 'src' => 'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?w=600&q=80&auto=format&fit=crop', 'alt' => 'Outdoor portrait' ),
                );
                foreach ( $default_gallery as $gimg ) : ?>
                    <div class="tw-gallery__item">
                        <img src="<?php echo esc_url( $gimg['src'] ); ?>" alt="<?php echo esc_attr( $gimg['alt'] ); ?>">
                        <div class="tw-gallery__item-overlay">
                            <span>View</span>
                        </div>
                    </div>
                <?php endforeach;
            endif;
            ?>
        </div>

        <div class="text-center tw-reveal" style="margin-top: 3rem;">
            <a href="<?php echo esc_url( home_url( '/#specials' ) ); ?>" class="tw-btn tw-btn--dark">Book a Session</a>
        </div>
    </div>
</section>

<!-- ============================================================
     TESTIMONIALS
     ============================================================ -->
<section class="tw-section" id="testimonials">
    <div class="tw-container">
        <div class="text-center tw-reveal" style="margin-bottom: 3rem;">
            <span class="section-label">Kind Words</span>
            <h2 class="section-title">What Our Clients Say</h2>
        </div>

        <div class="tw-testimonials__slider tw-reveal">
            <div class="tw-testimonial" id="testimonial-active">
                <div class="tw-testimonial__stars">&#9733; &#9733; &#9733; &#9733; &#9733;</div>
                <p class="tw-testimonial__quote">"They made us feel so comfortable from the very first minute. The photos came out more beautiful than we could have ever imagined. Truly, truly grateful."</p>
                <p class="tw-testimonial__author">Happy Client</p>
                <p class="tw-testimonial__role">Family Portrait Session</p>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     CTA
     ============================================================ -->
<?php
$cta_bg      = get_theme_mod( 'tweller_cta_bg', '' );
$cta_title   = get_theme_mod( 'tweller_cta_title', "Let's Create Something Beautiful" );
$cta_text    = get_theme_mod( 'tweller_cta_text', 'Your next favorite photo is one session away.' );
$cta_btn_text = get_theme_mod( 'tweller_cta_btn_text', 'Book Your Session' );
$cta_btn_url = get_theme_mod( 'tweller_cta_btn_url', '/contact' );
?>
<section class="tw-cta">
    <?php if ( $cta_bg ) : ?>
        <div class="tw-cta__bg" style="background-image: url('<?php echo esc_url( $cta_bg ); ?>')"></div>
    <?php else : ?>
        <div class="tw-cta__bg" style="background-image: url('https://images.unsplash.com/photo-1470071459604-3b5ec3a7fe05?w=1920&q=80&auto=format&fit=crop'); background-color: var(--tw-gray-900);"></div>
    <?php endif; ?>
    <div class="tw-cta__overlay"></div>
    <div class="tw-container">
        <div class="tw-cta__content tw-reveal">
            <span class="section-label">Ready?</span>
            <h2 class="tw-cta__title"><?php echo esc_html( $cta_title ); ?></h2>
            <p class="tw-cta__text"><?php echo esc_html( $cta_text ); ?></p>
            <a href="<?php echo esc_url( $cta_btn_url ); ?>" class="tw-btn tw-btn--primary"><?php echo esc_html( $cta_btn_text ); ?></a>
        </div>
    </div>
</section>

<?php get_footer(); ?>
