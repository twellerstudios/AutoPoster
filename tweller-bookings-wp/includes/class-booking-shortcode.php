<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow2_Booking_Shortcode {

    public static function init() {
        add_shortcode( 'tweller_booking', array( __CLASS__, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function enqueue_assets() {
        // We only enqueue if the shortcode is present on the page, but WP sometimes needs it registered early.
        wp_register_style(
            'tweller-flow-2-booking',
            TWELLER_FLOW_2_PLUGIN_URL . 'public/css/booking.css',
            array(),
            TWELLER_FLOW_2_VERSION
        );
        wp_register_script(
            'tweller-flow-2-booking',
            TWELLER_FLOW_2_PLUGIN_URL . 'public/js/booking.js',
            array('jquery'),
            TWELLER_FLOW_2_VERSION,
            true
        );
        
        $session_types = get_option('tweller_flow_2_session_types');
        if ( empty( $session_types ) ) {
            TwellerFlow2_Database::seed_defaults();
            $session_types = get_option('tweller_flow_2_session_types', array());
        }
        $packages = get_option('tweller_flow_2_packages', array());
        
        wp_localize_script( 'tweller-flow-2-booking', 'twellerBooking', array(
            'restUrl' => rest_url( 'tweller-flow-2/v1/' ),
            'packages' => $packages,
            'sessionTypes' => $session_types,
            'trackerUrl' => get_option( 'tweller_flow_2_tracker_page', '' )
        ));
    }

    public static function render( $atts ) {
        wp_enqueue_style( 'tweller-flow-2-booking' );
        wp_enqueue_script( 'tweller-flow-2-booking' );

        ob_start();
        ?>
        <div id="tweller-booking-app" class="tweller-booking">
            <div class="tweller-booking__header">
                <h2>Book A Session</h2>
                <p>What date/time are you interested in?</p>
                <div class="tweller-booking__progress">
                    <div class="tf2-step active" data-step="1">1</div>
                    <div class="tf2-step-line"></div>
                    <div class="tf2-step" data-step="2">2</div>
                    <div class="tf2-step-line"></div>
                    <div class="tf2-step" data-step="3">3</div>
                    <div class="tf2-step-line"></div>
                    <div class="tf2-step" data-step="4">4</div>
                </div>
            </div>

            <!-- Shown only when a saved draft was picked back up. -->
            <div class="tf2-resume" id="tf2-resume-notice" style="display:none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><polyline points="3 4 3 9 8 9"/></svg>
                <span class="tf2-resume__text"></span>
                <button type="button" class="tf2-resume__reset" id="tf2-resume-reset">Start over</button>
            </div>

            <!-- STEP 1: Session Type Selection -->
            <div class="tweller-booking__step active" id="tf2-step-1">
                <div class="tf2-packages-grid" id="tf2-sessions-container">
                    <!-- Session types injected via JS -->
                </div>
            </div>

            <!-- STEP 2: Package Selection -->
            <div class="tweller-booking__step" id="tf2-step-2" style="display:none;">
                <div class="tf2-back-btn" onclick="tfBooking.prevStep()">&#8592; Back to Sessions</div>
                <h3>Select a Package</h3>
                <div class="tf2-packages-grid" id="tf2-packages-container">
                    <!-- Packages injected via JS -->
                </div>
            </div>

            <!-- STEP 3: Date & Time -->
            <div class="tweller-booking__step" id="tf2-step-3" style="display:none;">
                <div class="tf2-back-btn" onclick="tfBooking.prevStep()">&#8592; Back to Packages</div>
                <h3>Select Date & Time</h3>
                <div class="tf2-datetime-layout">
                    <div class="tf2-calendar">
                        <input type="date" id="tf2-booking-date" class="tf2-input-field" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                    </div>
                    <div class="tf2-slots" id="tf2-slots-container">
                        <p class="tf2-slots-empty">Please select a date first.</p>
                    </div>
                </div>
            </div>

            <!-- STEP 4: Details -->
            <div class="tweller-booking__step" id="tf2-step-4" style="display:none;">
                <div class="tf2-back-btn" onclick="tfBooking.prevStep()">&#8592; Back to Time</div>
                <h3>Your Details</h3>
                
                <div class="tf2-summary-card" id="tf2-summary-card">
                    <!-- JS fills -->
                </div>

                <form id="tf2-booking-form" onsubmit="event.preventDefault(); tfBooking.submitBooking();">
                    <div class="tf2-form-row">
                        <div class="tf2-field">
                            <label>Full Name *</label>
                            <input type="text" id="tf2-client-name" required>
                        </div>
                        <div class="tf2-field">
                            <label>Email *</label>
                            <input type="email" id="tf2-client-email" required>
                        </div>
                    </div>
                    <div class="tf2-field">
                        <label>Phone *</label>
                        <input type="tel" id="tf2-client-phone" required>
                    </div>
                    <div class="tf2-field">
                        <label>Location Preference / Notes</label>
                        <textarea id="tf2-client-notes" rows="3" placeholder="Studio or ON Location? Tell us any details you want included..."></textarea>
                    </div>

                    <?php
                    $tf2_fee     = class_exists( 'TwellerFlow2_Image_Consent' ) ? TwellerFlow2_Image_Consent::privacy_fee() : 50;
                    $tf2_pct     = class_exists( 'TwellerFlow2_Image_Consent' ) ? TwellerFlow2_Image_Consent::next_discount_pct() : 50;
                    // The explanation is read in a modal on this page now, not
                    // on the standalone page — that page still exists for links
                    // from emails, but a booking should never navigate away.
                    $tf2_fee_str = 'TT$' . number_format( $tf2_fee, ( $tf2_fee == (int) $tf2_fee ) ? 0 : 2 );
                    ?>
                    <div class="tf2-consent" id="tf2-consent-cfg" data-fee="<?php echo esc_attr( $tf2_fee ); ?>">
                        <div class="tf2-consent__head">
                            <h4 class="tf2-consent__title">Sharing your photos</h4>
                            <p class="tf2-consent__sub">Your call &mdash; we respect it either way.</p>
                        </div>

                        <div class="tf2-consent__opts">
                            <label class="tf2-consent-opt">
                                <input type="radio" name="tf2_image_consent" value="limited" checked onchange="tfBooking.renderSummary()">
                                <span class="tf2-consent-opt__mark" aria-hidden="true"></span>
                                <span class="tf2-consent-opt__body">
                                    <span class="tf2-consent-opt__name">Allow 1&ndash;5 images</span>
                                    <span class="tf2-consent-opt__note">Our standard &mdash; a small, tasteful selection.</span>
                                </span>
                                <span class="tf2-consent-opt__tag tf2-consent-opt__tag--free">No fee</span>
                            </label>

                            <label class="tf2-consent-opt">
                                <input type="radio" name="tf2_image_consent" value="unlimited" onchange="tfBooking.renderSummary()">
                                <span class="tf2-consent-opt__mark" aria-hidden="true"></span>
                                <span class="tf2-consent-opt__body">
                                    <span class="tf2-consent-opt__name">Share as many as you like</span>
                                    <span class="tf2-consent-opt__note">A real help to us &mdash; and we say thank you.</span>
                                </span>
                                <span class="tf2-consent-opt__tag tf2-consent-opt__tag--gift"><?php echo esc_html( (int) $tf2_pct ); ?>% off next</span>
                            </label>

                            <label class="tf2-consent-opt">
                                <input type="radio" name="tf2_image_consent" value="declined" onchange="tfBooking.renderSummary()">
                                <span class="tf2-consent-opt__mark" aria-hidden="true"></span>
                                <span class="tf2-consent-opt__body">
                                    <span class="tf2-consent-opt__name">Keep my session private</span>
                                    <span class="tf2-consent-opt__note">Nothing shared, anywhere.</span>
                                </span>
                                <span class="tf2-consent-opt__tag tf2-consent-opt__tag--fee">+<?php echo esc_html( $tf2_fee_str ); ?></span>
                            </label>
                        </div>

                        <button type="button" class="tf2-consent__why" id="tf2-why-open">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <span>Why is there a privacy fee?</span>
                            <span class="tf2-consent__why-cta">Read more</span>
                        </button>
                    </div>

                    <!-- Why-this-fee reader. Kept on the page: sending someone
                         away mid-booking is how you lose the booking. -->
                    <div class="tf2-why-modal" id="tf2-why-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="tf2-why-modal-title">
                        <div class="tf2-why-modal__backdrop" id="tf2-why-close-bg"></div>
                        <div class="tf2-why-modal__card">
                            <div class="tf2-why-modal__head">
                                <h3 class="tf2-why-modal__title" id="tf2-why-modal-title">Why is there a privacy fee?</h3>
                                <button type="button" class="tf2-why-modal__x" id="tf2-why-close" aria-label="Close">&times;</button>
                            </div>
                            <div class="tf2-why-modal__body">
                                <?php echo TwellerFlow2_Image_Consent::why_body_html(); ?>
                            </div>
                            <div class="tf2-why-modal__foot">
                                <button type="button" class="tf2-btn-submit tf2-why-modal__done" id="tf2-why-done">Got it</button>
                            </div>
                        </div>
                    </div>

                    <div class="tf2-field tf2-confirm-availability" style="background:#F9FAFB; padding:12px; border:1px solid #E5E7EB; border-radius:6px; margin-bottom:15px;">
                        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; font-weight:normal; margin:0;">
                            <input type="checkbox" id="tf2-client-confirmed" required style="width:18px;height:18px; margin-top:3px; flex-shrink:0;">
                            <span style="font-size:14px; color:#374151;">I have confirmed availability with the photographer for this specific date and time.</span>
                        </label>
                        <p style="font-size:12px; color:#6B7280; margin:8px 0 0 28px;">If not, please send a quick inquiry message below first:</p>
                        <div style="display:flex; gap:10px; margin-top:10px; margin-left:28px;">
                            <button type="button" class="tf2-btn-submit" style="background:#25D366; padding:8px 12px; font-size:13px; flex:1;" onclick="tfBooking.sendWhatsApp()">WhatsApp Inquiry</button>
                            <button type="button" class="tf2-btn-submit" style="background:#4F46E5; padding:8px 12px; font-size:13px; flex:1;" onclick="tfBooking.sendEmail()">Email Inquiry</button>
                        </div>
                    </div>

                    <button type="submit" class="tf2-btn-submit" id="tf2-submit-btn">Reserve Booking &middot; Continue to Payment</button>
                    <p class="tf2-submit-hint">This step reserves your session &mdash; nothing is charged yet. On the next screen you&rsquo;ll pay by debit/credit card or bank transfer.</p>
                    <div id="tf2-booking-error" class="tf2-error-msg" style="display:none;"></div>
                </form>
            </div>
            
            <div class="tweller-booking__loading" id="tf2-booking-loading" style="display:none;">
                <div class="tf2-spinner"></div>
                <p>Securing your spot...</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
