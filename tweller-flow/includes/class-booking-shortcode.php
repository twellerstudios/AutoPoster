<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow_Booking_Shortcode {

    public static function init() {
        add_shortcode( 'tweller_booking', array( __CLASS__, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function enqueue_assets() {
        // We only enqueue if the shortcode is present on the page, but WP sometimes needs it registered early.
        wp_register_style(
            'tweller-flow-booking',
            TWELLER_FLOW_PLUGIN_URL . 'public/css/booking.css',
            array(),
            TWELLER_FLOW_VERSION
        );
        wp_register_script(
            'tweller-flow-booking',
            TWELLER_FLOW_PLUGIN_URL . 'public/js/booking.js',
            array('jquery'),
            TWELLER_FLOW_VERSION,
            true
        );
        
        $session_types = get_option('tweller_flow_session_types');
        if ( empty( $session_types ) ) {
            TwellerFlow_Database::seed_defaults();
            $session_types = get_option('tweller_flow_session_types', array());
        }
        $packages = get_option('tweller_flow_packages', array());
        
        wp_localize_script( 'tweller-flow-booking', 'twellerBooking', array(
            'restUrl' => rest_url( 'tweller-flow/v1/' ),
            'packages' => $packages,
            'sessionTypes' => $session_types,
            'trackerUrl' => get_option( 'tweller_flow_tracker_page', '' )
        ));
    }

    public static function render( $atts ) {
        wp_enqueue_style( 'tweller-flow-booking' );
        wp_enqueue_script( 'tweller-flow-booking' );

        ob_start();
        ?>
        <div id="tweller-booking-app" class="tweller-booking">
            <div class="tweller-booking__header">
                <h2>Book A Session</h2>
                <p>What date/time are you interested in?</p>
                <div class="tweller-booking__progress">
                    <div class="tf-step active" data-step="1">1</div>
                    <div class="tf-step-line"></div>
                    <div class="tf-step" data-step="2">2</div>
                    <div class="tf-step-line"></div>
                    <div class="tf-step" data-step="3">3</div>
                    <div class="tf-step-line"></div>
                    <div class="tf-step" data-step="4">4</div>
                </div>
            </div>

            <!-- STEP 1: Session Type Selection -->
            <div class="tweller-booking__step active" id="tf-step-1">
                <div class="tf-packages-grid" id="tf-sessions-container">
                    <!-- Session types injected via JS -->
                </div>
            </div>

            <!-- STEP 2: Package Selection -->
            <div class="tweller-booking__step" id="tf-step-2" style="display:none;">
                <div class="tf-back-btn" onclick="tfBooking.prevStep()">&#8592; Back to Sessions</div>
                <h3>Select a Package</h3>
                <div class="tf-packages-grid" id="tf-packages-container">
                    <!-- Packages injected via JS -->
                </div>
            </div>

            <!-- STEP 3: Date & Time -->
            <div class="tweller-booking__step" id="tf-step-3" style="display:none;">
                <div class="tf-back-btn" onclick="tfBooking.prevStep()">&#8592; Back to Packages</div>
                <h3>Select Date & Time</h3>
                <div class="tf-datetime-layout">
                    <div class="tf-calendar">
                        <input type="date" id="tf-booking-date" class="tf-input-field" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                    </div>
                    <div class="tf-slots" id="tf-slots-container">
                        <p class="tf-slots-empty">Please select a date first.</p>
                    </div>
                </div>
            </div>

            <!-- STEP 4: Details -->
            <div class="tweller-booking__step" id="tf-step-4" style="display:none;">
                <div class="tf-back-btn" onclick="tfBooking.prevStep()">&#8592; Back to Time</div>
                <h3>Your Details</h3>
                
                <div class="tf-summary-card" id="tf-summary-card">
                    <!-- JS fills -->
                </div>

                <form id="tf-booking-form" onsubmit="event.preventDefault(); tfBooking.submitBooking();">
                    <div class="tf-form-row">
                        <div class="tf-field">
                            <label>Full Name *</label>
                            <input type="text" id="tf-client-name" required>
                        </div>
                        <div class="tf-field">
                            <label>Email *</label>
                            <input type="email" id="tf-client-email" required>
                        </div>
                    </div>
                    <div class="tf-field">
                        <label>Phone *</label>
                        <input type="tel" id="tf-client-phone" required>
                    </div>
                    <div class="tf-field">
                        <label>Location Preference / Notes</label>
                        <textarea id="tf-client-notes" rows="3" placeholder="Studio or ON Location? Tell us any details you want included..."></textarea>
                    </div>
                    
                    <div class="tf-field tf-confirm-availability" style="background:#F9FAFB; padding:12px; border:1px solid #E5E7EB; border-radius:6px; margin-bottom:15px;">
                        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; font-weight:normal; margin:0;">
                            <input type="checkbox" id="tf-client-confirmed" required style="width:18px;height:18px; margin-top:3px; flex-shrink:0;">
                            <span style="font-size:14px; color:#374151;">I have confirmed availability with the photographer for this specific date and time.</span>
                        </label>
                        <p style="font-size:12px; color:#6B7280; margin:8px 0 0 28px;">If not, please send a quick inquiry message below first:</p>
                        <div style="display:flex; gap:10px; margin-top:10px; margin-left:28px;">
                            <button type="button" class="tf-btn-submit" style="background:#25D366; padding:8px 12px; font-size:13px; flex:1;" onclick="tfBooking.sendWhatsApp()">WhatsApp Inquiry</button>
                            <button type="button" class="tf-btn-submit" style="background:#4F46E5; padding:8px 12px; font-size:13px; flex:1;" onclick="tfBooking.sendEmail()">Email Inquiry</button>
                        </div>
                    </div>

                    <button type="submit" class="tf-btn-submit" id="tf-submit-btn">Complete Booking</button>
                    <div id="tf-booking-error" class="tf-error-msg" style="display:none;"></div>
                </form>
            </div>
            
            <div class="tweller-booking__loading" id="tf-booking-loading" style="display:none;">
                <div class="tf-spinner"></div>
                <p>Securing your spot...</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
