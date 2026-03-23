<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TwellerFlow_Tracker_Shortcode {

    public static function init() {
        add_shortcode( 'tweller_tracker', array( __CLASS__, 'render' ) );
    }

    public static function render( $atts ) {
        wp_enqueue_style( 'tweller-flow-tracker' );
        wp_enqueue_script( 'tweller-flow-tracker' );

        wp_localize_script( 'tweller-flow-tracker', 'twellerFlowTracker', array(
            'apiUrl' => rest_url( 'tweller-flow/v1/track/' ),
            'nonce'  => wp_create_nonce( 'wp_rest' ),
        ));

        $code = isset( $_GET['code'] ) ? sanitize_text_field( $_GET['code'] ) : '';

        ob_start();

        if ( empty( $code ) ) {
            self::render_lookup_form();
        } else {
            $session = TwellerFlow_Session::get_by_code( $code );
            if ( ! $session ) {
                self::render_not_found( $code );
            } else {
                self::render_tracker( $session );
            }
        }

        return ob_get_clean();
    }

    private static function render_lookup_form() {
        ?>
        <div class="tf-tracker tf-tracker--lookup">
            <div class="tf-tracker__header">
                <h2>Track Your Session</h2>
                <p>Enter your tracking code to see the progress of your photo session.</p>
            </div>
            <form class="tf-tracker__form" method="get">
                <div class="tf-tracker__input-group">
                    <input type="text" name="code" placeholder="e.g. A1B2C3" class="tf-tracker__input" maxlength="10" required pattern="[A-Za-z0-9]+" style="text-transform:uppercase;">
                    <button type="submit" class="tf-tracker__btn">Track</button>
                </div>
            </form>
        </div>
        <?php
    }

    private static function render_not_found( $code ) {
        ?>
        <div class="tf-tracker tf-tracker--error">
            <div class="tf-tracker__header">
                <h2>Session Not Found</h2>
                <p>We couldn't find a session with code <strong><?php echo esc_html( $code ); ?></strong>. Please check and try again.</p>
            </div>
            <form class="tf-tracker__form" method="get">
                <div class="tf-tracker__input-group">
                    <input type="text" name="code" placeholder="Enter tracking code" class="tf-tracker__input" maxlength="10" required style="text-transform:uppercase;">
                    <button type="submit" class="tf-tracker__btn">Try Again</button>
                </div>
            </form>
        </div>
        <?php
    }

    private static function render_tracker( $session ) {
        $client_stages    = TwellerFlow_Database::get_client_stages();
        $client_stage     = TwellerFlow_Database::get_client_stage( $session->current_stage );
        $client_stage_idx = TwellerFlow_Database::get_client_stage_index( $session->current_stage );
        $history          = TwellerFlow_Session::get_history( $session->id );

        $stage_timestamps = array();
        foreach ( $history as $entry ) {
            $cl = TwellerFlow_Database::get_client_stage( $entry->stage );
            if ( ! isset( $stage_timestamps[ $cl ] ) ) {
                $stage_timestamps[ $cl ] = $entry->timestamp;
            }
        }

        $packages = get_option( 'tweller_flow_packages', array() );
        $pkg = $packages[ $session->package_type ] ?? array();
        $pkg_name = $pkg['name'] ?? ucfirst( $session->package_type );

        $stage_icons = array(
            'Booked'       => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
            'Editing'      => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
            'Done Editing' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
            'Delivered'    => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        );
        ?>
        <div class="tf-tracker" data-code="<?php echo esc_attr( $session->tracking_code ); ?>">
            <div class="tf-tracker__header">
                <h2>Session Tracker</h2>
                <p class="tf-tracker__greeting">Hi <?php echo esc_html( $session->client_name ); ?>! Here's the progress of your <strong><?php echo esc_html( $pkg_name ); ?></strong>.</p>
                <div class="tf-tracker__meta">
                    <?php if ( $session->session_date ) : ?>
                        <span class="tf-tracker__meta-item">Session: <?php echo date( 'F j, Y', strtotime( $session->session_date ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $session->estimated_delivery ) : ?>
                        <span class="tf-tracker__meta-item">Est. delivery: <?php echo date( 'F j, Y', strtotime( $session->estimated_delivery ) ); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tf-tracker__stages">
                <?php foreach ( $client_stages as $idx => $stage_name ) :
                    $is_completed = $idx < $client_stage_idx;
                    $is_current   = $idx === $client_stage_idx;
                    $status_class = $is_completed ? 'completed' : ( $is_current ? 'current' : 'upcoming' );
                    $timestamp    = $stage_timestamps[ $stage_name ] ?? '';
                    $icon         = $stage_icons[ $stage_name ] ?? '';
                ?>
                    <div class="tf-tracker__stage tf-tracker__stage--<?php echo $status_class; ?>">
                        <div class="tf-tracker__stage-connector">
                            <div class="tf-tracker__stage-line"></div>
                            <div class="tf-tracker__stage-dot">
                                <?php if ( $is_completed ) : ?>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><polyline points="20 6 9 17 4 12" fill="none" stroke="currentColor" stroke-width="3"/></svg>
                                <?php elseif ( $is_current ) : ?>
                                    <div class="tf-tracker__stage-pulse"></div>
                                <?php endif; ?>
                            </div>
                            <div class="tf-tracker__stage-line"></div>
                        </div>
                        <div class="tf-tracker__stage-content">
                            <div class="tf-tracker__stage-icon"><?php echo $icon; ?></div>
                            <div class="tf-tracker__stage-info">
                                <h3 class="tf-tracker__stage-name"><?php echo esc_html( $stage_name ); ?></h3>
                                <?php if ( $is_current ) : ?>
                                    <span class="tf-tracker__stage-badge">In Progress</span>
                                <?php elseif ( $is_completed && $timestamp ) : ?>
                                    <span class="tf-tracker__stage-time"><?php echo date( 'M j, g:i A', strtotime( $timestamp ) ); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ( $session->current_stage === 'delivered' && $session->gallery_url ) : ?>
                <div class="tf-tracker__gallery">
                    <h3>Your Gallery is Ready!</h3>
                    <a href="<?php echo esc_url( $session->gallery_url ); ?>" class="tf-tracker__gallery-btn" target="_blank" rel="noopener">
                        View & Download Photos
                    </a>
                </div>
            <?php endif; ?>

            <div class="tf-tracker__footer">
                <p class="tf-tracker__code">Tracking Code: <strong><?php echo esc_html( $session->tracking_code ); ?></strong></p>
                <p class="tf-tracker__refresh">This page auto-refreshes every 60 seconds.</p>
            </div>
        </div>
        <?php
    }
}
