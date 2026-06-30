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

                    <button type="submit" class="tf2-btn-submit" id="tf2-submit-btn">Complete Booking</button>
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
