</main><!-- #site-content -->

<footer class="tw-footer" id="site-footer">
    <div class="tw-container">
        <div class="tw-footer__grid">
            <div class="tw-footer__brand">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="tw-header__logo">
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
                <p class="tw-footer__brand-desc">
                    Because the best moments deserve more than memory. Photography & videography that feels like you.
                </p>
                <?php
                $socials = tweller_get_social_links();
                if ( ! empty( $socials ) ) : ?>
                <div class="tw-footer__social">
                    <?php foreach ( $socials as $platform => $url ) : ?>
                        <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( ucfirst( $platform ) ); ?>">
                            <?php echo tweller_social_icon( $platform ); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="tw-footer__col">
                <h4 class="tw-footer__heading">Navigate</h4>
                <ul class="tw-footer__links">
                    <li><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a></li>
                    <li><a href="<?php echo esc_url( home_url( '/#about' ) ); ?>">About</a></li>
                    <li><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>">Services</a></li>
                    <li><a href="<?php echo esc_url( home_url( '/#gallery' ) ); ?>">Gallery</a></li>
                    <li><a href="<?php echo esc_url( home_url( '/blog/' ) ); ?>">Blog</a></li>
                </ul>
            </div>

            <div class="tw-footer__col">
                <h4 class="tw-footer__heading">Services</h4>
                <ul class="tw-footer__links">
                    <li><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>">Wedding Photography</a></li>
                    <li><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>">Family Portraits</a></li>
                    <li><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>">Cinematic Videography</a></li>
                    <li><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>">Event Coverage</a></li>
                    <li><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>">Web & Graphic Design</a></li>
                </ul>
            </div>

            <div class="tw-footer__col">
                <h4 class="tw-footer__heading">Get in Touch</h4>
                <ul class="tw-footer__links">
                    <?php
                    $phone = get_theme_mod( 'tweller_phone', '+1 868-342-2948' );
                    $email = get_theme_mod( 'tweller_email', 'hello@twellerstudios.com' );
                    $location = get_theme_mod( 'tweller_location', 'Trinidad & Tobago' );
                    ?>
                    <li><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a></li>
                    <li><a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a></li>
                    <li><?php echo esc_html( $location ); ?></li>
                </ul>
            </div>
        </div>

        <div class="tw-footer__bottom">
            <p>&copy; <?php echo date( 'Y' ); ?> <?php bloginfo( 'name' ); ?>. All rights reserved.</p>
            <p>Crafted with care in Trinidad & Tobago</p>
        </div>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
