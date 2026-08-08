<?php
/**
 * Front-end rendering: gallery view, upload view, the self-service creation
 * form, the free-tier promo branding, share meta tags and asset/config wiring.
 *
 * The heavy lifting (masonry, drag/drop, chunked upload, print drawer, share
 * flow) is done client-side by public/js/tepe-app.js against the REST API.
 * PHP renders a progressively-enhanced skeleton and localises config.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TEPE_Frontend {

    public static function init() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
        // Priority 20 so our markup lands AFTER wpautop (10) / do_shortcode (11)
        // and isn't re-paragraphed into broken HTML.
        add_filter( 'the_content', array( __CLASS__, 'render_event' ), 20 );
        add_action( 'wp_head', array( __CLASS__, 'share_meta' ), 5 );
        add_shortcode( 'tepe_create_event', array( __CLASS__, 'shortcode_create' ) );
        // Free-tier sticky banner sits outside the content, at the end of <body>.
        add_action( 'wp_footer', array( __CLASS__, 'maybe_render_banner' ) );
    }

    public static function register_assets() {
        wp_register_style( 'tepe', TEPE_PLUGIN_URL . 'public/css/tepe.css', array(), TEPE_VERSION );
        wp_register_script( 'tepe-fingerprint', TEPE_PLUGIN_URL . 'public/js/tepe-fingerprint.js', array(), TEPE_VERSION, true );
        wp_register_script( 'tepe-app', TEPE_PLUGIN_URL . 'public/js/tepe-app.js', array( 'tepe-fingerprint' ), TEPE_VERSION, true );
    }

    private static function is_event_singular() {
        return is_singular( TEPE_CPT );
    }

    /** Enqueue + localise once, when we know which view we're on. */
    private static function enqueue( $event, $view ) {
        wp_enqueue_style( 'tepe' );
        wp_enqueue_script( 'tepe-fingerprint' );
        wp_enqueue_script( 'tepe-app' );
        wp_localize_script( 'tepe-app', 'tepeConfig', self::config( $event, $view ) );
    }

    public static function config( $event, $view ) {
        $free = TEPE_Gallery::is_free_tier( $event->ID );
        return array(
            'view'          => $view,
            'restBase'      => esc_url_raw( rest_url( TEPE_REST::NS . '/' ) ),
            'nonce'         => wp_create_nonce( 'wp_rest' ),
            'slug'          => $event->post_name,
            'eventId'       => (int) $event->ID,
            'eventTitle'    => get_the_title( $event ),
            'uploadUrl'     => TEPE_Gallery::upload_url( $event ),
            'galleryUrl'    => TEPE_Gallery::gallery_url( $event ),
            'qrSvg'         => TEPE_Rewrite::qr_url( $event, 'svg' ),
            'qrPng'         => TEPE_Rewrite::qr_url( $event, 'png' ),
            'bookUrl'       => home_url( '/book-us/' ),
            'categories'    => TEPE_Categories::get( $event->ID ),
            'allowAlbums'   => (bool) get_post_meta( $event->ID, TEPE_CPT::META_ALLOW_ALBUMS, true ),
            'allowPrints'   => (bool) get_post_meta( $event->ID, TEPE_CPT::META_ALLOW_PRINTS, true ),
            'shareReward'   => (bool) get_post_meta( $event->ID, TEPE_CPT::META_SHARE_REWARD, true ),
            'locked'        => TEPE_Gallery::is_locked( $event->ID ),
            'freeTier'      => $free,
            'promoEveryN'   => 11,
            'promoCards'    => $free ? self::promo_cards() : array(),
            'maxFiles'      => TEPE_Uploads::MAX_FILES_PER_REQUEST,
            'maxFileSize'   => TEPE_Uploads::MAX_FILE_SIZE,
            'currency'      => 'TT$',
            'catalog'       => TEPE_Prints::catalog(),
            'deliveryFee'   => TEPE_Prints::delivery_fee(),
            'bankInstructions' => TEPE_Prints::bank_instructions(),
            'printEngineUrl'   => TEPE_Prints::print_engine_url(),
            'welcome'       => (string) get_post_meta( $event->ID, TEPE_CPT::META_WELCOME, true ),
            'strings'       => array(
                'shareText' => sprintf( 'Photos from %s 📸', get_the_title( $event ) ),
            ),
        );
    }

    /** Promo cards for the free-tier masonry, from booking packages if present. */
    public static function promo_cards() {
        $book = home_url( '/book-us/' );
        $cards = array();

        $packages = get_option( 'tweller_flow_2_packages', array() );
        $picks = array( 'event_2hr', 'full', 'mini' );
        foreach ( $picks as $key ) {
            if ( isset( $packages[ $key ] ) ) {
                $p = $packages[ $key ];
                $cards[] = array(
                    'title' => $p['name'] ?? 'Book a Session',
                    'blurb' => is_array( $p['features'] ?? null ) ? implode( ' · ', array_slice( $p['features'], 0, 2 ) ) : '',
                    'price' => isset( $p['price'] ) ? 'TT$ ' . number_format( (float) $p['price'], 0 ) : '',
                    'cta'   => 'Book this',
                    'url'   => $book,
                );
            }
        }
        if ( empty( $cards ) ) {
            $cards[] = array(
                'title' => 'Capture your next event with us',
                'blurb' => 'Professional event photography across Trinidad & Tobago.',
                'price' => '',
                'cta'   => 'Book a session',
                'url'   => $book,
            );
        }
        return $cards;
    }

    // ── Content injection ─────────────────────────────────────────────────────

    public static function render_event( $content ) {
        if ( ! self::is_event_singular() || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }
        $event = get_post();
        if ( TEPE_Rewrite::is_upload_view() ) {
            self::enqueue( $event, 'upload' );
            return self::render_upload( $event );
        }
        self::enqueue( $event, 'gallery' );
        return self::render_gallery( $event, $content );
    }

    private static function render_gallery( $event, $content ) {
        $cover   = TEPE_Gallery::get_cover( $event );
        $count   = TEPE_Gallery::count_uploads( $event->ID, 'approved' );
        $upload  = TEPE_Gallery::upload_url( $event );
        $welcome = (string) get_post_meta( $event->ID, TEPE_CPT::META_WELCOME, true );

        ob_start(); ?>
        <div class="tepe tepe-gallery" id="tepe-app">
            <header class="tepe-hero<?php echo $cover ? ' tepe-hero--img' : ''; ?>"
                <?php if ( $cover ) : ?>style="background-image:linear-gradient(rgba(16,16,16,.35),rgba(16,16,16,.7)),url('<?php echo esc_url( $cover['url'] ); ?>');"<?php endif; ?>>
                <div class="tepe-hero__inner">
                    <h1 class="tepe-hero__title"><?php echo esc_html( get_the_title( $event ) ); ?></h1>
                    <?php if ( $welcome !== '' ) : ?><p class="tepe-hero__welcome"><?php echo esc_html( $welcome ); ?></p><?php endif; ?>
                    <p class="tepe-hero__meta"><span id="tepe-count"><?php echo (int) $count; ?></span> photos shared</p>
                    <div class="tepe-hero__actions">
                        <a class="tepe-btn tepe-btn--gold" href="<?php echo esc_url( $upload ); ?>">＋ Add your photos</a>
                        <button class="tepe-btn tepe-btn--ghost" type="button" id="tepe-share-btn">Share to socials</button>
                    </div>
                </div>
            </header>

            <?php if ( trim( wp_strip_all_tags( $content ) ) !== '' ) : ?>
                <div class="tepe-intro"><?php echo wp_kses_post( $content ); ?></div>
            <?php endif; ?>

            <nav class="tepe-cats" id="tepe-cats" aria-label="Albums"></nav>

            <div class="tepe-share-reward" id="tepe-reward" hidden></div>

            <div class="tepe-masonry" id="tepe-masonry" aria-live="polite"></div>
            <div class="tepe-empty" id="tepe-empty" hidden>
                <p>No photos yet — be the first to add one!</p>
                <a class="tepe-btn tepe-btn--gold" href="<?php echo esc_url( $upload ); ?>">Add photos</a>
            </div>
            <div class="tepe-loading" id="tepe-loading">Loading gallery…</div>

            <!-- Print drawer (slides out) -->
            <div class="tepe-drawer" id="tepe-drawer" hidden>
                <div class="tepe-drawer__scrim" data-close></div>
                <aside class="tepe-drawer__panel" role="dialog" aria-modal="true" aria-label="Order prints">
                    <div class="tepe-drawer__head">
                        <h2>Order prints</h2>
                        <button type="button" class="tepe-drawer__x" data-close aria-label="Close">✕</button>
                    </div>
                    <div class="tepe-drawer__body" id="tepe-drawer-body"></div>
                </aside>
            </div>

            <!-- Lightbox -->
            <div class="tepe-lightbox" id="tepe-lightbox" hidden>
                <button class="tepe-lightbox__x" data-close aria-label="Close">✕</button>
                <button class="tepe-lightbox__nav tepe-lightbox__prev" data-prev aria-label="Previous">‹</button>
                <img class="tepe-lightbox__img" id="tepe-lightbox-img" alt="">
                <button class="tepe-lightbox__nav tepe-lightbox__next" data-next aria-label="Next">›</button>
                <div class="tepe-lightbox__bar">
                    <button class="tepe-btn tepe-btn--gold" id="tepe-lightbox-print" type="button">Order a print</button>
                    <a class="tepe-btn tepe-btn--ghost" id="tepe-lightbox-dl" download>Download</a>
                </div>
            </div>

            <div class="tepe-toast" id="tepe-toast" role="status" aria-live="polite" hidden></div>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_upload( $event ) {
        $locked = TEPE_Gallery::is_locked( $event->ID );
        $qr_svg = TEPE_Rewrite::qr_url( $event, 'svg' );

        ob_start(); ?>
        <div class="tepe tepe-upload" id="tepe-app">
            <header class="tepe-uphead">
                <a class="tepe-back" href="<?php echo esc_url( TEPE_Gallery::gallery_url( $event ) ); ?>">‹ Back to gallery</a>
                <h1><?php echo esc_html( get_the_title( $event ) ); ?></h1>
                <p class="tepe-uphead__sub">Add your photos — no app, no sign-up.</p>
            </header>

            <?php if ( $locked ) : ?>
                <div class="tepe-locked">Uploads for this event are closed. Thanks for sharing!</div>
            <?php else : ?>

            <div class="tepe-upcard">
                <label class="tepe-field">
                    <span>Your name <em>(optional — so the host knows who to thank)</em></span>
                    <input type="text" id="tepe-guest-name" autocomplete="name" placeholder="e.g. Aunty Cheryl">
                </label>

                <div class="tepe-field">
                    <span>Add to album</span>
                    <div class="tepe-dest" id="tepe-dest"></div>
                    <button type="button" class="tepe-linkbtn" id="tepe-new-album" hidden>＋ New album</button>
                </div>

                <div class="tepe-dropzone" id="tepe-dropzone" role="button" tabindex="0" aria-label="Add photos">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    <p class="tepe-dropzone__title">Tap to choose photos</p>
                    <p class="tepe-dropzone__note">or drag &amp; drop · JPEG, PNG, HEIC · up to <?php echo (int) TEPE_Uploads::MAX_FILES_PER_REQUEST; ?> at a time</p>
                    <input type="file" id="tepe-file" accept="image/jpeg,image/png,image/heic,image/heif,.heic,.heif" multiple capture="environment" hidden>
                </div>
                <!-- honeypot -->
                <input type="text" id="tepe-website" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">

                <div class="tepe-queue" id="tepe-queue"></div>
                <button type="button" class="tepe-btn tepe-btn--gold tepe-btn--full" id="tepe-upload-go" hidden>Upload photos</button>
            </div>

            <div class="tepe-qrhint">
                <img src="<?php echo esc_url( $qr_svg ); ?>" alt="QR code to this upload page" width="120" height="120" loading="lazy">
                <p>Share this QR so others can add their photos too.</p>
            </div>

            <?php endif; ?>
            <div class="tepe-toast" id="tepe-toast" role="status" aria-live="polite" hidden></div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── Self-service creation form ────────────────────────────────────────────

    public static function shortcode_create( $atts ) {
        wp_enqueue_style( 'tepe' );
        wp_enqueue_script( 'tepe-fingerprint' );
        wp_enqueue_script( 'tepe-app' );
        wp_localize_script( 'tepe-app', 'tepeConfig', array(
            'view'     => 'create',
            'restBase' => esc_url_raw( rest_url( TEPE_REST::NS . '/' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'presets'  => TEPE_Categories::presets(),
            'bookUrl'  => home_url( '/book-us/' ),
        ) );

        ob_start(); ?>
        <div class="tepe tepe-create" id="tepe-app">
            <div class="tepe-create__card" id="tepe-create-form">
                <h2>Create your event gallery</h2>
                <p class="tepe-create__sub">Collect every guest’s photos in one place. Free — just your email, no account needed.</p>

                <label class="tepe-field"><span>Event name</span>
                    <input type="text" id="tepe-c-title" placeholder="e.g. Aisha &amp; Marcus — Wedding" required></label>
                <label class="tepe-field"><span>Your email</span>
                    <input type="email" id="tepe-c-email" placeholder="you@email.com" required></label>
                <label class="tepe-field"><span>Your name <em>(optional)</em></span>
                    <input type="text" id="tepe-c-name" placeholder="Host name"></label>
                <label class="tepe-field"><span>Event date <em>(optional)</em></span>
                    <input type="date" id="tepe-c-date"></label>
                <label class="tepe-field"><span>Welcome message for guests <em>(optional)</em></span>
                    <textarea id="tepe-c-welcome" rows="2" placeholder="Thanks for celebrating with us — drop your photos here!"></textarea></label>
                <label class="tepe-field"><span>Albums <em>(comma separated)</em></span>
                    <input type="text" id="tepe-c-cats" value="<?php echo esc_attr( implode( ', ', TEPE_Categories::presets() ) ); ?>"></label>

                <input type="text" id="tepe-website" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
                <p class="tepe-error" id="tepe-c-error" hidden></p>
                <button type="button" class="tepe-btn tepe-btn--gold tepe-btn--full" id="tepe-c-go">Create gallery</button>
            </div>

            <div class="tepe-create__done" id="tepe-create-done" hidden>
                <h2>🎉 Your gallery is live!</h2>
                <p>We’ve emailed the links to you as well.</p>
                <div class="tepe-create__links" id="tepe-create-links"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── Free-tier promo banner ────────────────────────────────────────────────

    public static function maybe_render_banner() {
        if ( ! self::is_event_singular() ) return;
        $event = get_post();
        if ( ! $event || ! TEPE_Gallery::is_free_tier( $event->ID ) ) return;

        $book = home_url( '/book-us/' );
        ?>
        <div class="tepe-banner" id="tepe-banner" role="complementary">
            <span class="tepe-banner__text"><strong>Powered by Tweller Studios</strong> — Capture your next event with us</span>
            <a class="tepe-banner__cta" href="<?php echo esc_url( $book ); ?>">Book a session</a>
            <button class="tepe-banner__x" type="button" aria-label="Dismiss" onclick="this.parentNode.style.display='none'">✕</button>
        </div>
        <?php
    }

    // ── Share meta (og:image etc.) ────────────────────────────────────────────

    public static function share_meta() {
        if ( ! self::is_event_singular() ) return;
        $event = get_post();
        if ( ! $event ) return;

        $cover = TEPE_Gallery::get_cover( $event );
        $title = get_the_title( $event );
        $url   = TEPE_Gallery::gallery_url( $event );
        $desc  = sprintf( 'See and share photos from %s.', $title );
        ?>
        <meta property="og:type" content="website">
        <meta property="og:title" content="<?php echo esc_attr( $title ); ?>">
        <meta property="og:description" content="<?php echo esc_attr( $desc ); ?>">
        <meta property="og:url" content="<?php echo esc_url( $url ); ?>">
        <?php if ( $cover ) : ?>
        <meta property="og:image" content="<?php echo esc_url( $cover['url'] ); ?>">
        <meta name="twitter:card" content="summary_large_image">
        <?php endif; ?>
        <?php
    }
}
