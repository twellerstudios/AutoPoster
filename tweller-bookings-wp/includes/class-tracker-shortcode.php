<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Tracker_Shortcode {

    public static function init() {
        add_shortcode( 'tweller_tracker', array( __CLASS__, 'render' ) );
        add_action( 'wp_head', array( __CLASS__, 'output_og_tags' ), 5 );
    }

    /**
     * Social share (Open Graph) tags for tracker/gallery links.
     * Uses the admin-positioned gallery cover crop so shared links
     * preview the right part of the photo.
     */
    public static function output_og_tags() {
        if ( empty( $_GET['code'] ) || is_admin() ) return;

        $code    = sanitize_text_field( $_GET['code'] );
        $session = TwellerFlow2_Session::get_by_code( $code );
        if ( ! $session ) return;

        $og_image = '';
        if ( class_exists( 'TwellerFlow2_Gallery' ) && in_array( $session->current_stage, array( 'uploaded', 'delivered' ), true ) ) {
            $og_image = TwellerFlow2_Gallery::get_og_image_url( $session );
        }
        if ( ! $og_image ) return;

        $title = esc_attr( $session->client_name . ' — Your Gallery | Tweller Studios' );
        echo "\n<meta property=\"og:title\" content=\"{$title}\" />\n";
        echo "<meta property=\"og:image\" content=\"" . esc_url( $og_image ) . "\" />\n";
        echo "<meta property=\"og:image:width\" content=\"1200\" />\n";
        echo "<meta property=\"og:image:height\" content=\"630\" />\n";
        echo "<meta name=\"twitter:card\" content=\"summary_large_image\" />\n";
        echo "<meta name=\"twitter:image\" content=\"" . esc_url( $og_image ) . "\" />\n";
    }

    public static function render( $atts ) {
        wp_enqueue_style( 'tweller-flow-2-tracker' );
        wp_enqueue_script( 'tweller-flow-2-tracker' );
        wp_enqueue_script( 'tesseract-js', 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js', array(), null, true );
        
        wp_localize_script( 'tweller-flow-2-tracker', 'twellerFlow2Tracker', array(
            'apiUrl'      => rest_url( 'tweller-flow-2/v1/track/' ),
            'galleryUrl'  => rest_url( 'tweller-flow-2/v1/gallery/' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'wipay'       => array(
                'enabled' => class_exists( 'TwellerFlow2_WiPay' ) && TwellerFlow2_WiPay::is_enabled(),
            ),
        ));

        $code = isset( $_GET['code'] ) ? sanitize_text_field( $_GET['code'] ) : '';

        ob_start();

        if ( empty( $code ) ) {
            self::render_lookup_form();
        } else {
            $session = TwellerFlow2_Session::get_by_code( $code );
            if ( ! $session ) {
                self::render_not_found( $code );
            } else {
                // Print store rides along with the delivered gallery
                if ( class_exists( 'TwellerFlow2_Prints' )
                    && in_array( $session->current_stage, array( 'uploaded', 'delivered' ), true ) ) {
                    TwellerFlow2_Prints::enqueue_store_assets( array(
                        'mode'          => 'gallery',
                        'code'          => $session->tracking_code,
                        'customerName'  => $session->client_name,
                        'customerEmail' => $session->client_email,
                    ));
                }
                self::render_tracker( $session );
            }
        }

        return ob_get_clean();
    }

    private static function render_lookup_form() {
        ?>
        <div class="tf2-tracker tf2-tracker--lookup">
            <div class="tf2-tracker__header">
                <h2>Track Your Session</h2>
                <p>Enter your shoot code to see the progress of your photo session.</p>
            </div>
            <form class="tf2-tracker__form" method="get">
                <div class="tf2-tracker__input-group">
                    <input type="text" name="code" placeholder="e.g. 04-July-2024-JohnDoe-Mini" class="tf2-tracker__input" maxlength="120" required pattern="[A-Za-z0-9\-]+">
                    <button type="submit" class="tf2-tracker__btn">Track</button>
                </div>
            </form>
        </div>
        <?php
    }

    private static function render_not_found( $code ) {
        ?>
        <div class="tf2-tracker tf2-tracker--error">
            <div class="tf2-tracker__header">
                <h2>Session Not Found</h2>
                <p>We couldn't find a session with code <strong><?php echo esc_html( $code ); ?></strong>. Please check and try again.</p>
            </div>
            <form class="tf2-tracker__form" method="get">
                <div class="tf2-tracker__input-group">
                    <input type="text" name="code" placeholder="Enter shoot code" class="tf2-tracker__input" maxlength="120" required>
                    <button type="submit" class="tf2-tracker__btn">Try Again</button>
                </div>
            </form>
        </div>
        <?php
    }

    private static function render_tracker( $session ) {
        $client_stages    = TwellerFlow2_Database::get_client_stages();
        $client_stage     = TwellerFlow2_Database::get_client_stage( $session->current_stage );
        $client_stage_idx = TwellerFlow2_Database::get_client_stage_index( $session->current_stage );
        $history          = TwellerFlow2_Session::get_history( $session->id );

        $stage_timestamps = array();
        foreach ( $history as $entry ) {
            $cl = TwellerFlow2_Database::get_client_stage( $entry->stage );
            if ( ! isset( $stage_timestamps[ $cl ] ) ) {
                $stage_timestamps[ $cl ] = $entry->timestamp;
            }
        }

        $packages = get_option( 'tweller_flow_2_packages', array() );
        $pkg = $packages[ $session->package_type ] ?? array();
        $pkg_name = $pkg['name'] ?? ucfirst( $session->package_type );

        $stage_icons = array(
            'Reserved'      => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
            'Booking Confirmed' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>',
            'Select Photos for Editing' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
            'Editing'       => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
            'Done Editing'  => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
            'Exporting'     => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
            'Gallery Ready' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/><path d="M2 12l5 5 10-10"/></svg>',
            'Delivered'     => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        );
        ?>
        <style>
            /* Hide WordPress page title/hero banner for tracker page */
            .entry-header, .page-header, .ast-archive-description,
            article > header, .hero-section, .page-hero,
            .entry-title, .page-title, h1.page-title, h1.entry-title,
            .ast-hero-section, .fl-module-heading,
            .elementor-page-title, .has-page-header,
            .page-title-section, .flavor-page-header,
            .flavor-hero, .flavor-title-bar,
            .flavor-page-title, .flavor-banner,
            #flavor-page-title, .flavor_header_title_container,
            .flavor_page_header, .flavor-header-title,
            .flavor-page-hero, .flavor-archive-title,
            .flavor-breadcrumbs { display: none !important; }
        </style>
        <script>
        (function() {
            // Find and hide any page title/hero containing "Session Tracker"
            var els = document.querySelectorAll('h1, h2, .page-title, .entry-title, [class*="title"], [class*="hero"], [class*="banner"], [class*="header"]');
            for (var i = 0; i < els.length; i++) {
                var el = els[i];
                if (el.classList.contains('tf2-tracker__greeting') || el.closest('.tf2-tracker')) continue;
                var text = (el.textContent || '').trim();
                if (text === 'Session Tracker') {
                    // Hide the element and its parent if the parent is a section/header wrapper
                    el.style.display = 'none';
                    var parent = el.parentElement;
                    if (parent && parent !== document.body) {
                        var tag = parent.tagName.toLowerCase();
                        if (tag === 'section' || tag === 'header' || tag === 'div') {
                            var cls = parent.className || '';
                            if (/hero|title|header|banner|page-head/i.test(cls) || parent.children.length <= 2) {
                                parent.style.display = 'none';
                                // Also try grandparent (some themes wrap in 2 levels)
                                var gp = parent.parentElement;
                                if (gp && gp !== document.body && /hero|title|header|banner|page-head/i.test(gp.className || '')) {
                                    gp.style.display = 'none';
                                }
                            }
                        }
                    }
                }
            }
        })();
        </script>
        <?php
        // When the gallery is live, it takes over the page: hero first,
        // pipeline compressed and moved below the photos.
        $gallery_live = in_array( $session->current_stage, array( 'uploaded', 'delivered' ), true );

        // Browser tab shows the album, not the generic page name
        $tab_title = $gallery_live
            ? $session->client_name . ' — Gallery | Tweller Studios'
            : $session->client_name . ' — Session Tracker | Tweller Studios';
        ?>
        <script>document.title = <?php echo wp_json_encode( $tab_title ); ?>;</script>
        <div class="tf2-tracker<?php echo $gallery_live ? ' tf2-tracker--gallery-first' : ''; ?>" data-code="<?php echo esc_attr( $session->tracking_code ); ?>">
            <div class="tf2-tracker__header">
                <p class="tf2-tracker__greeting">Hi <?php echo esc_html( $session->client_name ); ?>! Here's the progress of your <strong><?php echo esc_html( $pkg_name ); ?></strong>.</p>
                <div class="tf2-tracker__meta">
                    <?php if ( $session->session_date ) : ?>
                        <span class="tf2-tracker__meta-item">Session: <?php echo date( 'F j, Y', strtotime( $session->session_date ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $session->estimated_delivery ) : ?>
                        <span class="tf2-tracker__meta-item">Est. delivery: <?php echo date( 'F j, Y', strtotime( $session->estimated_delivery ) ); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tf2-tracker__stages">
                <?php foreach ( $client_stages as $idx => $stage_name ) :
                    $is_completed = $idx < $client_stage_idx;
                    $is_current   = $idx === $client_stage_idx;
                    $status_class = $is_completed ? 'completed' : ( $is_current ? 'current' : 'upcoming' );
                    $timestamp    = $stage_timestamps[ $stage_name ] ?? '';
                    $icon         = $stage_icons[ $stage_name ] ?? '';
                ?>
                <?php 
                    $display_name = $stage_name;
                    if ( $stage_name === 'Booking Confirmed' ) {
                        if ( $is_completed ) {
                            $display_name = 'Session Completed';
                        } else {
                            $session_stamp = strtotime($session->session_date . ' ' . $session->session_time);
                            $now = current_time('timestamp');
                            if ( $session_stamp && $now >= $session_stamp ) {
                                $display_name = 'Session In Progress';
                            } else {
                                $display_name = 'Waiting for Session';
                            }
                        }
                    }
                ?>
                    <div class="tf2-tracker__stage tf2-tracker__stage--<?php echo $status_class; ?>">
                        <div class="tf2-tracker__stage-connector">
                            <div class="tf2-tracker__stage-line"></div>
                            <div class="tf2-tracker__stage-dot">
                                <?php if ( $is_completed ) : ?>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><polyline points="20 6 9 17 4 12" fill="none" stroke="currentColor" stroke-width="3"/></svg>
                                <?php elseif ( $is_current ) : ?>
                                    <div class="tf2-tracker__stage-pulse"></div>
                                <?php endif; ?>
                            </div>
                            <div class="tf2-tracker__stage-line"></div>
                        </div>
                        <div class="tf2-tracker__stage-content">
                            <div class="tf2-tracker__stage-icon"><?php echo $icon; ?></div>
                            <div class="tf2-tracker__stage-info">
                                <h3 class="tf2-tracker__stage-name"><?php echo esc_html( $display_name ); ?></h3>
                                <?php if ( $is_current ) : ?>
                                    <?php
                                        $progress_percent = 50;
                                        if ( $stage_name === 'Editing' ) {
                                            if ( $session->current_stage === 'editing' ) $progress_percent = 33;
                                            elseif ( $session->current_stage === 'edited' ) $progress_percent = 66;
                                            elseif ( in_array( $session->current_stage, array('exporting', 'exported') ) ) $progress_percent = 90;
                                        } elseif ( $stage_name === 'Reserved' ) {
                                            $progress_percent = ($session->payment_status === 'pending') ? 10 : (($session->payment_status === 'verifying') ? 80 : 100);
                                        }
                                    ?>
                                    <div class="tf2-tracker__progress" data-stage="<?php echo esc_attr( $stage_name ); ?>">
                                        <div class="tf2-tracker__progress-bar">
                                            <div class="tf2-tracker__progress-fill" style="width: <?php echo esc_attr( $progress_percent ); ?>%;"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ( $idx === 0 && $session->current_stage === 'booked' && $session->payment_status === 'pending' ) : ?>
                        <?php self::render_wipay_card( $session, (float) $session->total_amount, true ); ?>
                        <div class="tf2-tracker__receipt">
                            <div class="tf2-tracker__receipt-header">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--tf2-gold)" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                                <h3>Make payment to confirm booking</h3>
                            </div>
                            <?php $banking_info = get_option('tweller_flow_2_banking', ''); ?>
                            <?php if ( ! empty($banking_info) ) : ?>
                                <div class="tf2-tracker__banking-info">
                                    <?php echo wpautop( esc_html( $banking_info ) ); ?>
                                </div>
                            <?php endif; ?>
                            <p class="tf2-tracker__receipt-desc">Please transfer <strong>TTD <?php echo number_format($session->total_amount, 2); ?></strong> and upload the receipt screenshot below to verify your booking.</p>
                            <form id="tf2-receipt-form" class="tf2-tracker__receipt-form">
                                <input type="hidden" id="tf2-receipt-code" value="<?php echo esc_attr( $session->tracking_code ); ?>">
                                
                                <label>Sending Bank</label>
                                <select id="tf2-receipt-bank" class="tf2-tracker__receipt-file" required>
                                    <option value="">Select a Bank...</option>
                                    <option value="Republic Bank Limited (RBL)">Republic Bank Limited (RBL)</option>
                                    <option value="First Citizens Bank (FCB)">First Citizens Bank (FCB)</option>
                                    <option value="Royal Bank (RBC)">Royal Bank (RBC)</option>
                                    <option value="Scotiabank">Scotiabank</option>
                                    <option value="JMMB">JMMB</option>
                                    <option value="Other">Other...</option>
                                </select>
                                <input type="text" id="tf2-receipt-bank-other" class="tf2-tracker__receipt-file" style="display:none; margin-bottom:12px;" placeholder="Type bank name...">
                                
                                <label>Receipt Screenshot</label>
                                <input type="file" id="tf2-receipt-file" accept="image/*" required class="tf2-tracker__receipt-file">
                                
                                <button type="submit" id="tf2-receipt-btn" class="tf2-tracker__btn tf2-tracker__btn--full" style="display:flex; align-items:center; justify-content:center; gap:10px;">
                                    <span id="tf2-receipt-spinner" style="display:none; width:18px; height:18px; border:2.5px solid rgba(255,255,255,0.25); border-radius:50%; border-top-color:#fff; animation:tf2-spin 0.7s linear infinite; flex-shrink:0;"></span>
                                    <span id="tf2-receipt-btn-text">Verify Receipt Upload</span>
                                </button>
                            </form>
                            <div id="tf2-receipt-status" class="tf2-tracker__receipt-status" style="display:none;"></div>
                        </div>
                    <?php elseif ( $idx === 0 && $session->current_stage === 'booked' && $session->payment_status === 'verifying' ) : ?>
                        <div class="tf2-tracker__receipt tf2-tracker__receipt--verifying">
                            <div class="tf2-tracker__receipt-header">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                <h3>Receipt Submitted</h3>
                            </div>
                            <?php $receipt = get_option('tf_receipt_' . $session->id); ?>
                            <p class="tf2-tracker__receipt-desc" style="margin-bottom:0;">
                                Your <?php echo esc_html($receipt['bank'] ?? 'bank'); ?> transfer receipt has been successfully uploaded! We are currently verifying your payment. Please check back in 1-2 business days.
                            </p>
                        </div>
                    <?php elseif ( $idx === 0 && $session->payment_status === 'deposit' ) : ?>
                        <?php
                        // Deposit received — offer the card option for the remaining balance
                        self::render_wipay_card( $session, (float) $session->total_amount - (float) $session->deposit_amount, false );
                        ?>
                    <?php endif; ?>
                    
                <?php endforeach; ?>
            </div>

            <?php if ( in_array( $session->current_stage, array( 'uploaded', 'delivered' ), true ) ) : ?>
                <div id="gallery" class="tf2-gallery" data-code="<?php echo esc_attr( $session->tracking_code ); ?>">

                    <!-- Hero Cover (full-width, first photo as background with Ken Burns) -->
                    <div class="tf2-hero-cover" id="tf2-hero-cover" style="display:none;">
                        <div class="tf2-hero-cover__bg" id="tf2-hero-cover-bg"></div>
                        <div class="tf2-hero-cover__overlay"></div>
                        <div class="tf2-hero-cover__content">
                            <h1 class="tf2-hero-cover__name" id="tf2-hero-cover-name"><?php echo esc_html( strtoupper( $session->client_name ) ); ?></h1>
                            <?php if ( $session->session_date ) : ?>
                                <p class="tf2-hero-cover__date" id="tf2-hero-cover-date"><?php echo esc_html( strtoupper( date( 'F jS, Y', strtotime( $session->session_date ) ) ) ); ?></p>
                            <?php endif; ?>
                            <button class="tf2-hero-cover__btn" id="tf2-gallery-view-btn">VIEW GALLERY</button>
                        </div>
                    </div>

                    <!-- Password gate (shown/hidden by JS) -->
                    <div class="tf2-gallery__password" id="tf2-gallery-password" style="display:none;">
                        <div class="tf2-gallery__lock-icon">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1"/></svg>
                        </div>
                        <p>This gallery is password-protected.</p>
                        <form class="tf2-gallery__password-form" id="tf2-gallery-pw-form">
                            <input type="password" id="tf2-gallery-pw-input" placeholder="Enter password" class="tf2-gallery__pw-input" required>
                            <button type="submit" class="tf2-gallery__pw-btn">Unlock</button>
                        </form>
                        <p class="tf2-gallery__pw-error" id="tf2-gallery-pw-error" style="display:none;">Incorrect password. Please try again.</p>
                    </div>

                    <!-- Sign-in gate: name + email once, then remembered -->
                    <div class="tf2-gallery__signin" id="tf2-gallery-signin" style="display:none;">
                        <div class="tf2-signin__card">
                            <div class="tf2-signin__mark">
                                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/></svg>
                            </div>
                            <h2 class="tf2-signin__title">Welcome to the gallery</h2>
                            <p class="tf2-signin__sub">Add your name and email to view the photos, save your favourites, and come back to them anytime.</p>
                            <form class="tf2-signin__form" id="tf2-gallery-signin-form">
                                <input type="text" id="tf2-signin-name" class="tf2-signin__input" placeholder="Your name" autocomplete="name" required>
                                <input type="email" id="tf2-signin-email" class="tf2-signin__input" placeholder="Your email" autocomplete="email" required>
                                <button type="submit" class="tf2-signin__btn" id="tf2-signin-btn">View Gallery</button>
                            </form>
                            <p class="tf2-signin__error" id="tf2-signin-error" style="display:none;">Please enter your name and a valid email.</p>
                            <p class="tf2-signin__fine">We only use this to recognise you on your next visit — never shared.</p>
                        </div>
                    </div>

                    <!-- Gallery toolbar (client name + gallery actions) -->
                    <div class="tf2-gallery__toolbar" id="tf2-gallery-toolbar" style="display:none;">
                        <div class="tf2-gallery__toolbar-inner">
                            <span class="tf2-gallery__toolbar-name" id="tf2-gallery-toolbar-name"><?php echo esc_html( strtoupper( $session->client_name ) ); ?></span>

                            <!-- All / Liked view tabs -->
                            <div class="tf2-gallery__tabs" id="tf2-gallery-tabs" role="tablist">
                                <button type="button" class="tf2-gtab tf2-gtab--active" id="tf2-tab-all" data-view="all" role="tab" aria-selected="true">All Photos</button>
                                <button type="button" class="tf2-gtab" id="tf2-tab-liked" data-view="liked" role="tab" aria-selected="false">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/></svg>
                                    <span>Liked</span>
                                    <span class="tf2-gtab__count" id="tf2-liked-count">0</span>
                                </button>
                            </div>

                            <div class="tf2-gallery__actions" id="tf2-gallery-actions">
                                <a id="tf2-gallery-download-liked" class="tf2-gbtn tf2-gbtn--like" href="#" title="Download your favourites" style="display:none;">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    <span class="tf2-gbtn__label">Download Favourites</span>
                                </a>
                                <button id="tf2-prints-open" class="tf2-gbtn tf2-gbtn--primary" type="button" title="Order Prints" style="display:none;">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                    <span class="tf2-gbtn__label">Order Prints</span>
                                    <span class="tf2-prints-badge" id="tf2-prints-count" style="display:none;">0</span>
                                </button>
                                <button id="tf2-printsel-enter" class="tf2-gbtn" type="button" title="Select photos for print" style="display:none;">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                                    <span class="tf2-gbtn__label">Select Photos</span>
                                </button>
                                <button id="tf2-gallery-slideshow" class="tf2-gbtn" type="button" title="Play Slideshow">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                    <span class="tf2-gbtn__label">Slideshow</span>
                                </button>
                                <a id="tf2-gallery-download-all" class="tf2-gbtn" href="#" title="Download All">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    <span class="tf2-gbtn__label">Download All</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Batch print selection bar (shown while selection mode is on) -->
                    <div class="tf2-printsel-bar" id="tf2-printsel-bar" style="display:none;" role="region" aria-label="Print selection">
                        <div class="tf2-printsel-bar__inner">
                            <div class="tf2-printsel-bar__head">
                                <span class="tf2-printsel-bar__count" id="tf2-printsel-count" aria-live="polite">0 selected</span>
                                <button type="button" class="tf2-printsel-bar__done" id="tf2-printsel-done">Done</button>
                            </div>
                            <div class="tf2-printsel-bar__actions">
                                <button type="button" class="tf2-gbtn" id="tf2-printsel-all">
                                    <span class="tf2-gbtn__label">Select all</span>
                                </button>
                                <button type="button" class="tf2-gbtn" id="tf2-printsel-clear">
                                    <span class="tf2-gbtn__label">Clear</span>
                                </button>
                                <button type="button" class="tf2-gbtn tf2-gbtn--primary tf2-printsel-bar__continue" id="tf2-printsel-continue" disabled>
                                    <span class="tf2-gbtn__label">Continue to sizes</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Slideshow overlay -->
                    <div class="tf2-slideshow" id="tf2-slideshow" style="display:none;">
                        <div class="tf2-slideshow__layer" id="tf2-ss-layer-a"></div>
                        <div class="tf2-slideshow__layer" id="tf2-ss-layer-b"></div>
                        <button type="button" class="tf2-slideshow__close" id="tf2-ss-close" title="Exit slideshow">&times;</button>
                        <button type="button" class="tf2-slideshow__pause" id="tf2-ss-pause" title="Pause">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
                        </button>
                    </div>

                    <!-- Masonry photo grid (populated by JS) -->
                    <div class="tf2-gallery__grid" id="tf2-gallery-grid" style="display:none;"></div>

                    <!-- Lightbox overlay -->
                    <div class="tf2-lightbox" id="tf2-lightbox" style="display:none;">
                        <button class="tf2-lightbox__close" id="tf2-lightbox-close">&times;</button>
                        <button class="tf2-lightbox__nav tf2-lightbox__prev" id="tf2-lightbox-prev">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                        </button>
                        <div class="tf2-lightbox__content">
                            <img class="tf2-lightbox__img" id="tf2-lightbox-img" src="" alt="">
                        </div>
                        <button class="tf2-lightbox__nav tf2-lightbox__next" id="tf2-lightbox-next">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                        <div class="tf2-lightbox__bar">
                            <span class="tf2-lightbox__counter" id="tf2-lightbox-counter"></span>
                            <button class="tf2-lightbox__like" id="tf2-lightbox-like" type="button" title="Like this photo" aria-pressed="false">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/></svg>
                                <span class="tf2-lightbox__like-label">Like</span>
                            </button>
                            <a class="tf2-lightbox__download" id="tf2-lightbox-download" href="#" role="button">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                Download
                            </a>
                            <button class="tf2-lightbox__download tf2-lightbox__print" id="tf2-lightbox-print" type="button" style="display:none;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                Print
                                <span class="tf2-prints-badge tf2-prints-badge--lb" id="tf2-lightbox-print-count" style="display:none;">0</span>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="tf2-tracker__footer">
                <p class="tf2-tracker__code">Shoot Code: <strong><?php echo esc_html( $session->tracking_code ); ?></strong></p>
                <p class="tf2-tracker__refresh">This page auto-refreshes every 60 seconds.</p>
            </div>
        </div>
        <?php
    }

    /**
     * "Pay by card" card (WiPay hosted checkout) — shown above the
     * bank-transfer option whenever card payments are enabled and a
     * balance is due on the session.
     */
    private static function render_wipay_card( $session, $amount, $with_divider = true ) {
        if ( ! class_exists( 'TwellerFlow2_WiPay' ) || ! TwellerFlow2_WiPay::is_enabled() ) return;
        if ( $amount < 1 ) return;

        $pay_url = TwellerFlow2_WiPay::checkout_url( 'booking', $session->tracking_code );
        ?>
        <div class="tf2-wipay">
            <span class="tf2-wipay__label">Pay Online</span>
            <div class="tf2-wipay__amount">TT$<?php echo esc_html( number_format( $amount, 2 ) ); ?></div>
            <a class="tf2-wipay__btn" href="<?php echo esc_url( $pay_url ); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                Pay by card
            </a>
            <p class="tf2-wipay__sub">Secure checkout powered by WiPay &middot; Visa &amp; Mastercard</p>
        </div>
        <?php if ( $with_divider ) : ?>
        <div class="tf2-wipay-divider"><span>or pay by bank transfer</span></div>
        <?php endif;
    }
}
