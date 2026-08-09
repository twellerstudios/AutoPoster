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

    // ── Inline SVG icons (crisp, symmetric, theme-inheriting) ─────────────────

    public static function icon_x() {
        return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>';
    }
    public static function icon_chevron( $dir = 'right' ) {
        $pts = ( $dir === 'left' ) ? '15 18 9 12 15 6' : '9 18 15 12 9 6';
        return '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="' . $pts . '"/></svg>';
    }
    public static function icon_check() {
        return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
    }
    public static function icon_arrow_left() {
        return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>';
    }
    public static function icon_plus() {
        return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
    }
    public static function icon_heart() {
        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 00-7.8 0L12 5.6l-1-1a5.5 5.5 0 00-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 000-7.8z"/></svg>';
    }
    public static function icon_play() {
        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 001.54.84l10.5-6.86a1 1 0 000-1.68L9.54 4.3A1 1 0 008 5.14z"/></svg>';
    }
    public static function icon_pause() {
        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>';
    }

    // ── Brand social logos (true colour) ──────────────────────────────────────
    public static function icon_whatsapp() {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="#ffffff" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2zm5.8 14.13c-.24.68-1.42 1.32-1.95 1.36-.5.05-.5.42-3.16-.66-2.66-1.08-4.31-3.85-4.44-4.03-.13-.18-1.06-1.41-1.06-2.69 0-1.28.67-1.91.91-2.17.24-.26.53-.32.7-.32.18 0 .35.002.5.01.16.007.38-.06.59.45.24.58.79 2 .86 2.14.07.14.12.31.02.5-.09.18-.14.29-.28.45-.14.16-.29.35-.42.47-.14.13-.28.28-.12.55.16.27.72 1.19 1.55 1.93 1.06.95 1.96 1.24 2.23 1.38.27.14.43.12.59-.07.16-.19.68-.79.86-1.06.18-.27.36-.23.61-.14.25.09 1.58.75 1.85.88.27.14.45.2.52.31.06.12.06.66-.18 1.34z"/></svg>';
    }
    public static function icon_facebook() {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="#ffffff" aria-hidden="true"><path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z"/></svg>';
    }
    public static function icon_instagram() {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="#ffffff" aria-hidden="true"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41-.56-.22-.96-.48-1.38-.9-.42-.42-.68-.82-.9-1.38-.16-.42-.36-1.06-.41-2.23-.06-1.27-.07-1.65-.07-4.85s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16zm0 3.68A6.16 6.16 0 1018.16 12 6.16 6.16 0 0012 5.84zm0 10.16A4 4 0 1116 12a4 4 0 01-4 4zm6.41-10.4a1.44 1.44 0 11-1.44-1.44 1.44 1.44 0 011.44 1.44z"/></svg>';
    }
    public static function icon_tiktok() {
        return '<svg viewBox="0 0 24 24" width="22" height="22" fill="#ffffff" aria-hidden="true"><path d="M12.53.02C13.84 0 15.14.01 16.44 0c.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>';
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
            'eventTitle'    => tepe_title( $event ),
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
                'shareText' => sprintf( 'Photos from %s 📸', tepe_title( $event ) ),
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

        // Count one gallery view per device per 12h.
        TEPE_Gallery::maybe_count_view( $event );

        ob_start(); ?>
        <div class="tepe tepe-gallery" id="tepe-app">
            <header class="tepe-hero<?php echo $cover ? ' tepe-hero--img' : ''; ?>"
                <?php if ( $cover ) : ?>style="background-image:linear-gradient(rgba(16,16,16,.35),rgba(16,16,16,.7)),url('<?php echo esc_url( $cover['url'] ); ?>');"<?php endif; ?>>
                <div class="tepe-hero__inner">
                    <h1 class="tepe-hero__title"><?php echo esc_html( tepe_title( $event ) ); ?></h1>
                    <p class="tepe-hero__brand">Tweller Studios</p>
                    <?php if ( $welcome !== '' ) : ?><p class="tepe-hero__welcome"><?php echo esc_html( $welcome ); ?></p><?php endif; ?>
                    <p class="tepe-hero__meta"><span id="tepe-count"><?php echo (int) $count; ?></span> photos · <span id="tepe-views"><?php echo (int) TEPE_Gallery::get_views( $event->ID ); ?></span> views</p>
                    <div class="tepe-hero__actions">
                        <a class="tepe-btn tepe-btn--gold tepe-btn--icon" href="<?php echo esc_url( $upload ); ?>"><?php echo self::icon_plus(); ?> Add photos</a>
                    </div>
                </div>
            </header>

            <section class="tepe-sharebar" aria-label="Share this gallery">
                <span class="tepe-sharebar__label">Share this gallery</span>
                <div class="tepe-sharebar__icons">
                    <button type="button" class="tepe-social tepe-social--whatsapp" data-share="whatsapp" aria-label="Share on WhatsApp"><?php echo self::icon_whatsapp(); ?></button>
                    <button type="button" class="tepe-social tepe-social--facebook" data-share="facebook" aria-label="Share on Facebook"><?php echo self::icon_facebook(); ?></button>
                    <button type="button" class="tepe-social tepe-social--instagram" data-share="instagram" aria-label="Share on Instagram"><?php echo self::icon_instagram(); ?></button>
                    <button type="button" class="tepe-social tepe-social--tiktok" data-share="tiktok" aria-label="Share on TikTok"><?php echo self::icon_tiktok(); ?></button>
                </div>
            </section>

            <?php if ( trim( wp_strip_all_tags( $content ) ) !== '' ) : ?>
                <div class="tepe-intro"><?php echo wp_kses_post( $content ); ?></div>
            <?php endif; ?>

            <div class="tepe-share-reward" id="tepe-reward" hidden></div>

            <!-- Top liked -->
            <section class="tepe-top" id="tepe-top" hidden>
                <div class="tepe-section-head"><span class="tepe-section-head__icon">♥</span><h2>Most loved</h2></div>
                <div class="tepe-top__strip" id="tepe-top-strip"></div>
            </section>

            <!-- Guestbook / Photo Notes -->
            <section class="tepe-guestbook" id="tepe-guestbook" hidden>
                <div class="tepe-section-head"><span class="tepe-section-head__icon">✎</span><h2>Photo Notes</h2></div>
                <p class="tepe-guestbook__sub">A little guestbook — heartfelt notes left with a photo.</p>
                <div class="tepe-guestbook__feed" id="tepe-guestbook-feed"></div>
            </section>

            <div class="tepe-toolbar">
                <nav class="tepe-cats" id="tepe-cats" aria-label="Albums"></nav>
                <div class="tepe-viewtoggle" id="tepe-viewtoggle" role="tablist" aria-label="Gallery view">
                    <button type="button" class="tepe-viewtoggle__btn tepe-viewtoggle__btn--on" data-mode="recent">Recent</button>
                    <button type="button" class="tepe-viewtoggle__btn" data-mode="people">By person</button>
                </div>
            </div>

            <div class="tepe-masonry" id="tepe-masonry" aria-live="polite"></div>
            <div class="tepe-groups" id="tepe-groups" hidden></div>
            <div class="tepe-empty" id="tepe-empty" hidden>
                <p>No photos yet — be the first to add one!</p>
                <a class="tepe-btn tepe-btn--gold tepe-btn--icon" href="<?php echo esc_url( $upload ); ?>"><?php echo self::icon_plus(); ?> Add photos</a>
            </div>
            <div class="tepe-loading" id="tepe-loading">Loading gallery…</div>

            <!-- Print drawer (slides out) -->
            <div class="tepe-drawer" id="tepe-drawer" hidden>
                <div class="tepe-drawer__scrim" data-close></div>
                <aside class="tepe-drawer__panel" role="dialog" aria-modal="true" aria-label="Order prints">
                    <div class="tepe-drawer__head">
                        <h2>Order prints</h2>
                        <button type="button" class="tepe-iconbtn tepe-drawer__x" data-close aria-label="Close"><?php echo self::icon_x(); ?></button>
                    </div>
                    <div class="tepe-drawer__body" id="tepe-drawer-body"></div>
                </aside>
            </div>

            <!-- Lightbox (immersive, fading chrome, persistent caption) -->
            <div class="tepe-lightbox" id="tepe-lightbox" hidden>
                <div class="tepe-lightbox__chrome">
                    <button class="tepe-heartbtn tepe-lightbox__like" id="tepe-lightbox-like" type="button" aria-label="Like this photo"><?php echo self::icon_heart(); ?><span id="tepe-lightbox-likes">0</span></button>
                    <button class="tepe-iconbtn tepe-lightbox__play" id="tepe-lightbox-play" type="button" aria-label="Play slideshow" aria-pressed="false"><span class="tepe-play-i"><?php echo self::icon_play(); ?></span><span class="tepe-pause-i"><?php echo self::icon_pause(); ?></span></button>
                    <button class="tepe-iconbtn tepe-lightbox__x" data-close aria-label="Close"><?php echo self::icon_x(); ?></button>
                    <button class="tepe-iconbtn tepe-lightbox__nav tepe-lightbox__prev" data-prev aria-label="Previous photo"><?php echo self::icon_chevron( 'left' ); ?></button>
                    <button class="tepe-iconbtn tepe-lightbox__nav tepe-lightbox__next" data-next aria-label="Next photo"><?php echo self::icon_chevron( 'right' ); ?></button>
                </div>
                <figure class="tepe-lightbox__stage">
                    <img class="tepe-lightbox__img" id="tepe-lightbox-img" alt="">
                </figure>
                <figcaption class="tepe-lightbox__caption" id="tepe-lightbox-cap" hidden>
                    <p class="tepe-lightbox__note" id="tepe-lightbox-note-text"></p>
                    <p class="tepe-lightbox__by" id="tepe-lightbox-by"></p>
                </figcaption>
                <div class="tepe-lightbox__bar">
                    <button class="tepe-lbtn" id="tepe-lightbox-note" type="button" hidden>Leave note</button>
                    <button class="tepe-lbtn tepe-lbtn--gold" id="tepe-lightbox-print" type="button">Order print</button>
                    <a class="tepe-lbtn" id="tepe-lightbox-dl" download>Download</a>
                </div>
            </div>

            <!-- Photo-note editor -->
            <div class="tepe-notemodal" id="tepe-notemodal" hidden>
                <div class="tepe-notemodal__scrim" data-noteclose></div>
                <div class="tepe-notemodal__card" role="dialog" aria-modal="true" aria-label="Leave a photo note">
                    <div class="tepe-notemodal__head">
                        <h2>Leave a photo note</h2>
                        <button type="button" class="tepe-iconbtn" data-noteclose aria-label="Close"><?php echo self::icon_x(); ?></button>
                    </div>
                    <img class="tepe-notemodal__thumb" id="tepe-note-thumb" alt="">
                    <label class="tepe-field"><span>Your name</span><input type="text" id="tepe-note-author" autocomplete="name" placeholder="Your name"></label>
                    <label class="tepe-field"><span>Your note</span><textarea id="tepe-note-msg" rows="4" maxlength="600" placeholder="Share a wish, a memory, a thank-you…"></textarea></label>
                    <input type="text" id="tepe-note-website" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
                    <p class="tepe-error" id="tepe-note-error" hidden></p>
                    <button type="button" class="tepe-btn tepe-btn--gold tepe-btn--full" id="tepe-note-save">Add to the guestbook</button>
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
                <a class="tepe-back" href="<?php echo esc_url( TEPE_Gallery::gallery_url( $event ) ); ?>"><?php echo self::icon_arrow_left(); ?> Back to gallery</a>
                <h1><?php echo esc_html( tepe_title( $event ) ); ?></h1>
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

                <?php $camera_only = (bool) get_post_meta( $event->ID, TEPE_CPT::META_CAMERA_ONLY, true ); ?>
                <div class="tepe-dropzone" id="tepe-dropzone" role="button" tabindex="0" aria-label="Add photos">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    <p class="tepe-dropzone__title"><?php echo $camera_only ? 'Tap to take a photo' : 'Tap to add photos'; ?></p>
                    <p class="tepe-dropzone__note"><?php echo $camera_only ? 'Camera' : 'Camera or photo library'; ?> · JPEG, PNG, HEIC · up to <?php echo (int) TEPE_Uploads::MAX_FILES_PER_REQUEST; ?> at a time</p>
                    <?php // Omitting `capture` lets the OS picker offer BOTH the camera and the
                          // saved photo library; setting it forces the live camera. ?>
                    <input type="file" id="tepe-file" accept="image/jpeg,image/png,image/heic,image/heif,.heic,.heif" multiple<?php echo $camera_only ? ' capture="environment"' : ''; ?> hidden>
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

            <!-- Events created on this device (filled from localStorage by JS) -->
            <section class="tepe-myevents" id="tepe-myevents" hidden>
                <h2>Your event galleries</h2>
                <div class="tepe-myevents__list" id="tepe-myevents-list"></div>
            </section>

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
                <button type="button" class="tepe-btn tepe-btn--ghost tepe-btn--full" id="tepe-create-another" style="margin-top:1rem;color:#2a2521;border-color:#e7e1d8">Create another gallery</button>
            </div>

            <!-- Find galleries by email -->
            <div class="tepe-create__card tepe-find" id="tepe-find">
                <h3>Already made one? Find it by email</h3>
                <p class="tepe-create__sub">Changed device or cleared your browser? We’ll email your gallery links.</p>
                <label class="tepe-field"><span>Your email</span>
                    <input type="email" id="tepe-find-email" placeholder="you@email.com"></label>
                <p class="tepe-error" id="tepe-find-msg" hidden></p>
                <button type="button" class="tepe-btn tepe-btn--dark tepe-btn--full" id="tepe-find-go">Email me my links</button>
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

        $og    = TEPE_Gallery::og_image( $event );
        $title = tepe_title( $event );
        $url   = TEPE_Gallery::gallery_url( $event );
        $desc  = sprintf( 'See and share the photos from %s.', $title );
        ?>
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="Tweller Studios">
        <meta property="og:title" content="<?php echo esc_attr( $title ); ?>">
        <meta property="og:description" content="<?php echo esc_attr( $desc ); ?>">
        <meta property="og:url" content="<?php echo esc_url( $url ); ?>">
        <meta name="twitter:title" content="<?php echo esc_attr( $title ); ?>">
        <meta name="twitter:description" content="<?php echo esc_attr( $desc ); ?>">
        <?php if ( $og ) : ?>
        <meta property="og:image" content="<?php echo esc_url( $og['url'] ); ?>">
        <meta property="og:image:secure_url" content="<?php echo esc_url( $og['url'] ); ?>">
        <meta property="og:image:type" content="image/jpeg">
        <meta property="og:image:width" content="<?php echo (int) $og['width']; ?>">
        <meta property="og:image:height" content="<?php echo (int) $og['height']; ?>">
        <meta property="og:image:alt" content="<?php echo esc_attr( $title ); ?>">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="<?php echo esc_url( $og['url'] ); ?>">
        <?php else : ?>
        <meta name="twitter:card" content="summary">
        <?php endif; ?>
        <?php
    }
}
