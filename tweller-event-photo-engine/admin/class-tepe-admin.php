<?php
/**
 * Admin interface — lives under the existing "Tweller Bookings" menu when that
 * plugin is present, otherwise stands up its own top-level menu.
 *
 * Screens:
 *   - Event Galleries  : list every event, at-a-glance counts + quick toggles.
 *   - Event detail     : edit config (limits, lock, feature, monetize tier,
 *                        categories, moderation), QR downloads, moderate the
 *                        upload grid, set the cover, view guests + print orders.
 *   - New Event        : create an event gallery by hand.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TEPE_Admin {

    const PARENT = 'tweller-flow-2';

    public static function init() {
        $self = new self();
        add_action( 'admin_menu', array( $self, 'menu' ), 30 );
        add_action( 'admin_init', array( $self, 'handle_post' ) );
        add_action( 'admin_enqueue_scripts', array( $self, 'assets' ) );
    }

    private function has_parent() {
        return class_exists( 'TwellerFlow2_Admin' ) || menu_page_url( self::PARENT, false );
    }

    public function menu() {
        if ( $this->has_parent() ) {
            add_submenu_page( self::PARENT, 'Event Galleries', 'Event Galleries', 'manage_options', 'tepe-events', array( $this, 'route' ) );
            add_submenu_page( self::PARENT, 'New Event Gallery', 'New Event', 'manage_options', 'tepe-new', array( $this, 'page_new' ) );
        } else {
            add_menu_page( 'Event Galleries', 'Event Galleries', 'manage_options', 'tepe-events', array( $this, 'route' ), 'dashicons-format-gallery', 31 );
            add_submenu_page( 'tepe-events', 'New Event Gallery', 'New Event', 'manage_options', 'tepe-new', array( $this, 'page_new' ) );
        }
        // Hidden detail screen.
        add_submenu_page( null, 'Event Detail', 'Event Detail', 'manage_options', 'tepe-event', array( $this, 'page_detail' ) );
    }

    public function assets( $hook ) {
        if ( strpos( (string) $hook, 'tepe-' ) === false && ( ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'tepe-' ) === false ) ) return;
        wp_enqueue_style( 'tepe-admin', TEPE_PLUGIN_URL . 'admin/css/tepe-admin.css', array(), TEPE_VERSION );
        wp_enqueue_script( 'tepe-admin', TEPE_PLUGIN_URL . 'admin/js/tepe-admin.js', array(), TEPE_VERSION, true );
        wp_localize_script( 'tepe-admin', 'tepeAdmin', array(
            'restBase' => esc_url_raw( rest_url( TEPE_REST::NS . '/' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
        ) );
    }

    public function route() {
        $this->page_list();
    }

    // ── POST handling ─────────────────────────────────────────────────────────

    public function handle_post() {
        if ( empty( $_POST['tepe_action'] ) || ! current_user_can( 'manage_options' ) ) return;
        $action = sanitize_key( $_POST['tepe_action'] );
        check_admin_referer( 'tepe_admin_' . $action );

        if ( $action === 'create' ) {
            $id = TEPE_Gallery::create( array(
                'title'      => $_POST['title'] ?? '',
                'host_email' => $_POST['host_email'] ?? '',
                'host_name'  => $_POST['host_name'] ?? '',
                'event_date' => $_POST['event_date'] ?? '',
                'welcome'    => $_POST['welcome'] ?? '',
                'categories' => array_filter( array_map( 'trim', explode( ',', $_POST['categories'] ?? '' ) ) ),
                'is_booking_linked' => 0,
                'pricing_tier'      => 'free',
            ) );
            if ( is_wp_error( $id ) ) {
                $this->redirect( 'tepe-new', array( 'err' => rawurlencode( $id->get_error_message() ) ) );
            }
            $this->redirect( 'tepe-event', array( 'id' => $id, 'msg' => 'created' ) );
        }

        if ( $action === 'save' ) {
            $id = (int) $_POST['event_id'];
            if ( ! TEPE_Gallery::get( $id ) ) $this->redirect( 'tepe-events', array() );

            // Title
            $title = sanitize_text_field( $_POST['title'] ?? '' );
            if ( $title !== '' ) wp_update_post( array( 'ID' => $id, 'post_title' => $title ) );

            update_post_meta( $id, TEPE_CPT::META_HOST_EMAIL, sanitize_email( $_POST['host_email'] ?? '' ) );
            update_post_meta( $id, TEPE_CPT::META_HOST_NAME, sanitize_text_field( $_POST['host_name'] ?? '' ) );
            update_post_meta( $id, TEPE_CPT::META_WELCOME, sanitize_textarea_field( $_POST['welcome'] ?? '' ) );
            update_post_meta( $id, TEPE_CPT::META_EVENT_DATE, sanitize_text_field( $_POST['event_date'] ?? '' ) );
            update_post_meta( $id, TEPE_CPT::META_UPLOAD_LIMIT, max( 0, (int) ( $_POST['upload_limit'] ?? 0 ) ) );
            update_post_meta( $id, TEPE_CPT::META_EVENT_LIMIT, max( 0, (int) ( $_POST['event_limit'] ?? 0 ) ) );
            update_post_meta( $id, TEPE_CPT::META_PRICING_TIER, sanitize_key( $_POST['pricing_tier'] ?? 'free' ) );
            update_post_meta( $id, TEPE_CPT::META_IS_MONETIZED, empty( $_POST['is_monetized'] ) ? 0 : 1 );
            update_post_meta( $id, TEPE_CPT::META_IS_LOCKED, empty( $_POST['is_locked'] ) ? 0 : 1 );
            update_post_meta( $id, TEPE_CPT::META_IS_FEATURED, empty( $_POST['is_featured'] ) ? 0 : 1 );
            update_post_meta( $id, TEPE_CPT::META_ALLOW_ALBUMS, empty( $_POST['allow_albums'] ) ? 0 : 1 );
            update_post_meta( $id, TEPE_CPT::META_MODERATE, empty( $_POST['moderate'] ) ? 0 : 1 );
            update_post_meta( $id, TEPE_CPT::META_ALLOW_PRINTS, empty( $_POST['allow_prints'] ) ? 0 : 1 );
            update_post_meta( $id, TEPE_CPT::META_SHARE_REWARD, empty( $_POST['share_reward'] ) ? 0 : 1 );
            update_post_meta( $id, TEPE_CPT::META_CAMERA_ONLY, empty( $_POST['camera_only'] ) ? 0 : 1 );

            TEPE_Categories::set( $id, array_filter( array_map( 'trim', explode( ',', $_POST['categories'] ?? '' ) ) ) );

            if ( ! empty( $_POST['cover_id'] ) ) {
                update_post_meta( $id, TEPE_CPT::META_COVER, (int) $_POST['cover_id'] );
            }
            $this->redirect( 'tepe-event', array( 'id' => $id, 'msg' => 'saved' ) );
        }

        if ( $action === 'delete' ) {
            $id = (int) $_POST['event_id'];
            wp_delete_post( $id, true );
            $this->redirect( 'tepe-events', array( 'msg' => 'deleted' ) );
        }
    }

    private function redirect( $page, $args ) {
        $args['page'] = $page;
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    // ── List screen ───────────────────────────────────────────────────────────

    public function page_list() {
        $events = get_posts( array( 'post_type' => TEPE_CPT, 'post_status' => 'any', 'numberposts' => 200, 'orderby' => 'date', 'order' => 'DESC' ) );
        ?>
        <?php
        $hub = function_exists( 'tepe_hub_url' ) ? tepe_hub_url() : '';
        ?>
        <div class="wrap tepe-admin">
            <h1 class="wp-heading-inline">Event Galleries</h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=tepe-new' ) ); ?>" class="page-title-action">Add New</a>
            <?php if ( isset( $_GET['msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Done.</p></div><?php endif; ?>

            <div class="tepe-hublinks">
                <strong>Public client page (create &amp; find galleries):</strong>
                <?php if ( $hub ) : ?>
                    <a href="<?php echo esc_url( $hub ); ?>" target="_blank"><?php echo esc_html( $hub ); ?></a>
                    <button type="button" class="button button-small tepe-copy" data-copy="<?php echo esc_attr( $hub ); ?>">Copy link</button>
                <?php else : ?>
                    <em>Not created yet — add the <code>[tepe_create_event]</code> shortcode to any page, or re-save permalinks to auto-create it.</em>
                <?php endif; ?>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th>Event</th><th>Source</th><th>Photos</th><th>Guests</th><th>Views</th><th>Orders</th><th>Status</th><th></th>
                </tr></thead>
                <tbody>
                <?php if ( empty( $events ) ) : ?>
                    <tr><td colspan="7">No event galleries yet. <a href="<?php echo esc_url( admin_url( 'admin.php?page=tepe-new' ) ); ?>">Create one</a>, or they’ll appear automatically when bookings are confirmed.</td></tr>
                <?php endif; ?>
                <?php foreach ( $events as $ev ) :
                    $photos = TEPE_Gallery::count_uploads( $ev->ID, null );
                    $guests = count( TEPE_Guest::list_for_event( $ev->ID ) );
                    $orders = count( TEPE_Prints::list_orders( $ev->ID ) );
                    $booked = get_post_meta( $ev->ID, TEPE_CPT::META_IS_BOOKED, true );
                    $locked = get_post_meta( $ev->ID, TEPE_CPT::META_IS_LOCKED, true );
                    $feat   = get_post_meta( $ev->ID, TEPE_CPT::META_IS_FEATURED, true );
                    $detail = admin_url( 'admin.php?page=tepe-event&id=' . $ev->ID );
                ?>
                    <tr>
                        <td><strong><a href="<?php echo esc_url( $detail ); ?>"><?php echo esc_html( get_the_title( $ev ) ); ?></a></strong>
                            <div class="row-actions"><span>
                                <a href="<?php echo esc_url( get_permalink( $ev ) ); ?>" target="_blank">View gallery</a> |
                                <a href="<?php echo esc_url( TEPE_Gallery::upload_url( $ev ) ); ?>" target="_blank">Upload page</a> |
                                <a href="<?php echo esc_url( TEPE_Rewrite::qr_url( $ev, 'png' ) ); ?>" target="_blank" download>QR</a> |
                                <a href="<?php echo esc_url( $detail ); ?>">Manage</a>
                            </span></div>
                        </td>
                        <td><?php echo $booked ? '<span class="tepe-tag tepe-tag--gold">Booking</span>' : '<span class="tepe-tag">Free / self-serve</span>'; ?></td>
                        <td><?php echo (int) $photos; ?></td>
                        <td><?php echo (int) $guests; ?></td>
                        <td><?php echo (int) TEPE_Gallery::get_views( $ev->ID ); ?></td>
                        <td><?php echo (int) $orders; ?></td>
                        <td><?php echo $locked ? '🔒 Locked' : '✅ Open'; ?><?php echo $feat ? ' · ⭐' : ''; ?></td>
                        <td><a class="button button-small" href="<?php echo esc_url( $detail ); ?>">Manage</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ── New screen ────────────────────────────────────────────────────────────

    public function page_new() {
        ?>
        <div class="wrap tepe-admin">
            <h1>New Event Gallery</h1>
            <?php if ( isset( $_GET['err'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( rawurldecode( $_GET['err'] ) ); ?></p></div><?php endif; ?>
            <form method="post">
                <?php wp_nonce_field( 'tepe_admin_create' ); ?>
                <input type="hidden" name="tepe_action" value="create">
                <table class="form-table">
                    <tr><th><label>Event name</label></th><td><input type="text" name="title" class="regular-text" required></td></tr>
                    <tr><th><label>Host email</label></th><td><input type="email" name="host_email" class="regular-text"></td></tr>
                    <tr><th><label>Host name</label></th><td><input type="text" name="host_name" class="regular-text"></td></tr>
                    <tr><th><label>Event date</label></th><td><input type="date" name="event_date"></td></tr>
                    <tr><th><label>Welcome message</label></th><td><textarea name="welcome" class="large-text" rows="2"></textarea></td></tr>
                    <tr><th><label>Albums</label></th><td><input type="text" name="categories" class="large-text" value="<?php echo esc_attr( implode( ', ', TEPE_Categories::presets() ) ); ?>"><p class="description">Comma-separated.</p></td></tr>
                </table>
                <?php submit_button( 'Create Event Gallery' ); ?>
            </form>
        </div>
        <?php
    }

    // ── Detail screen ─────────────────────────────────────────────────────────

    public function page_detail() {
        $id = (int) ( $_GET['id'] ?? 0 );
        $event = TEPE_Gallery::get( $id );
        if ( ! $event ) { echo '<div class="wrap"><p>Event not found.</p></div>'; return; }

        $m = function ( $k, $d = '' ) use ( $id ) { return TEPE_Gallery::meta( $id, $k, $d ); };
        $uploads = TEPE_Gallery::get_uploads( $id, array() ); // all statuses
        $guests  = TEPE_Guest::list_for_event( $id );
        $orders  = TEPE_Prints::list_orders( $id );
        $notes   = TEPE_Gallery::notes_for_event( $event, null ); // all statuses for moderation
        $cats    = TEPE_Categories::get_buckets( $id );
        $qr_svg  = TEPE_Rewrite::qr_url( $event, 'svg' );
        $qr_png  = TEPE_Rewrite::qr_url( $event, 'png' );
        ?>
        <div class="wrap tepe-admin">
            <h1><?php echo esc_html( get_the_title( $event ) ); ?>
                <a href="<?php echo esc_url( get_permalink( $event ) ); ?>" target="_blank" class="page-title-action">View gallery</a>
                <a href="<?php echo esc_url( TEPE_Gallery::upload_url( $event ) ); ?>" target="_blank" class="page-title-action">Upload page</a>
            </h1>
            <?php if ( isset( $_GET['msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>

            <div class="tepe-grid2">
                <div>
                    <form method="post">
                        <?php wp_nonce_field( 'tepe_admin_save' ); ?>
                        <input type="hidden" name="tepe_action" value="save">
                        <input type="hidden" name="event_id" value="<?php echo (int) $id; ?>">
                        <input type="hidden" name="cover_id" id="tepe-cover-id" value="<?php echo (int) $m( TEPE_CPT::META_COVER, 0 ); ?>">

                        <h2>Settings</h2>
                        <table class="form-table">
                            <tr><th>Event name</th><td><input type="text" name="title" class="regular-text" value="<?php echo esc_attr( get_the_title( $event ) ); ?>"></td></tr>
                            <tr><th>Host email</th><td><input type="email" name="host_email" class="regular-text" value="<?php echo esc_attr( $m( TEPE_CPT::META_HOST_EMAIL ) ); ?>"></td></tr>
                            <tr><th>Host name</th><td><input type="text" name="host_name" class="regular-text" value="<?php echo esc_attr( $m( TEPE_CPT::META_HOST_NAME ) ); ?>"></td></tr>
                            <tr><th>Event date</th><td><input type="date" name="event_date" value="<?php echo esc_attr( $m( TEPE_CPT::META_EVENT_DATE ) ); ?>"></td></tr>
                            <tr><th>Welcome message</th><td><textarea name="welcome" class="large-text" rows="2"><?php echo esc_textarea( $m( TEPE_CPT::META_WELCOME ) ); ?></textarea></td></tr>
                            <tr><th>Albums</th><td><input type="text" name="categories" class="large-text" value="<?php echo esc_attr( implode( ', ', wp_list_pluck( $cats, 'label' ) ) ); ?>"><p class="description">Comma-separated buckets (Ceremony, Reception, …).</p></td></tr>
                            <tr><th>Per-guest upload limit</th><td><input type="number" name="upload_limit" min="0" value="<?php echo (int) $m( TEPE_CPT::META_UPLOAD_LIMIT, 0 ); ?>"> <span class="description">0 = unlimited</span></td></tr>
                            <tr><th>Whole-event limit</th><td><input type="number" name="event_limit" min="0" value="<?php echo (int) $m( TEPE_CPT::META_EVENT_LIMIT, 0 ); ?>"> <span class="description">0 = unlimited</span></td></tr>
                            <tr><th>Pricing tier</th><td>
                                <select name="pricing_tier">
                                    <?php foreach ( array( 'free' => 'Free', 'client' => 'Client (booked)', 'standard' => 'Standard', 'premium' => 'Premium' ) as $k => $lbl ) : ?>
                                        <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $m( TEPE_CPT::META_PRICING_TIER, 'free' ), $k ); ?>><?php echo esc_html( $lbl ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label style="margin-left:1rem"><input type="checkbox" name="is_monetized" value="1" <?php checked( $m( TEPE_CPT::META_IS_MONETIZED ), 1 ); ?>> Monetized</label>
                            </td></tr>
                            <tr><th>Toggles</th><td class="tepe-toggles">
                                <label><input type="checkbox" name="is_locked" value="1" <?php checked( $m( TEPE_CPT::META_IS_LOCKED ), 1 ); ?>> 🔒 Lock uploads</label>
                                <label><input type="checkbox" name="is_featured" value="1" <?php checked( $m( TEPE_CPT::META_IS_FEATURED ), 1 ); ?>> ⭐ Featured</label>
                                <label><input type="checkbox" name="allow_albums" value="1" <?php checked( $m( TEPE_CPT::META_ALLOW_ALBUMS ), 1 ); ?>> Guest sub-albums</label>
                                <label><input type="checkbox" name="moderate" value="1" <?php checked( $m( TEPE_CPT::META_MODERATE ), 1 ); ?>> Hold uploads for approval</label>
                                <label><input type="checkbox" name="allow_prints" value="1" <?php checked( $m( TEPE_CPT::META_ALLOW_PRINTS ), 1 ); ?>> Allow print orders</label>
                                <label><input type="checkbox" name="share_reward" value="1" <?php checked( $m( TEPE_CPT::META_SHARE_REWARD ), 1 ); ?>> Share reward (free 4×6)</label>
                                <label><input type="checkbox" name="camera_only" value="1" <?php checked( $m( TEPE_CPT::META_CAMERA_ONLY ), 1 ); ?>> Camera only (off = guests can also pick saved photos)</label>
                            </td></tr>
                        </table>
                        <?php submit_button( 'Save settings' ); ?>
                    </form>

                    <form method="post" onsubmit="return confirm('Delete this event gallery and all its photos? This cannot be undone.');">
                        <?php wp_nonce_field( 'tepe_admin_delete' ); ?>
                        <input type="hidden" name="tepe_action" value="delete">
                        <input type="hidden" name="event_id" value="<?php echo (int) $id; ?>">
                        <button class="button button-link-delete">Delete event gallery</button>
                    </form>
                </div>

                <div>
                    <h2>QR code</h2>
                    <div class="tepe-qrbox">
                        <img src="<?php echo esc_url( $qr_svg ); ?>" alt="QR" width="160" height="160">
                        <p class="description">Scans to the guest upload page.</p>
                        <a class="button" href="<?php echo esc_url( $qr_svg ); ?>" download>Download SVG</a>
                        <a class="button" href="<?php echo esc_url( $qr_png ); ?>" download>Download PNG</a>
                    </div>

                    <h2>Print orders (<?php echo count( $orders ); ?>)</h2>
                    <?php if ( TEPE_Prints::bridge_available() ) : ?>
                        <p class="description">These orders also appear in <a href="<?php echo esc_url( admin_url( 'admin.php?page=tweller-flow-2-prints' ) ); ?>">Tweller Bookings → Print Orders</a> (tagged <strong>Event</strong>), alongside every website order — manage fulfilment there.</p>
                    <?php endif; ?>
                    <?php if ( empty( $orders ) ) : ?><p class="description">No orders yet.</p><?php else : ?>
                    <table class="widefat striped tepe-orders">
                        <thead><tr><th>Ref</th><th>Customer</th><th>Total</th><th>Pay</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ( $orders as $o ) : ?>
                            <tr>
                                <td><?php echo esc_html( $o->order_ref ); ?></td>
                                <td><?php echo esc_html( $o->customer_name ); ?><br><small><?php echo esc_html( $o->customer_phone ); ?></small></td>
                                <td>TT$ <?php echo number_format( (float) $o->total, 2 ); ?></td>
                                <td><?php echo esc_html( strtoupper( $o->payment_method ) ); ?></td>
                                <td>
                                    <select class="tepe-order-status" data-id="<?php echo (int) $o->id; ?>">
                                        <?php foreach ( array( 'new', 'confirmed', 'printing', 'ready', 'completed', 'cancelled' ) as $st ) : ?>
                                            <option value="<?php echo esc_attr( $st ); ?>" <?php selected( $o->status, $st ); ?>><?php echo esc_html( ucfirst( $st ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>

                    <h2>Guests (<?php echo count( $guests ); ?>)</h2>
                    <?php if ( empty( $guests ) ) : ?><p class="description">No guests tracked yet.</p><?php else : ?>
                    <table class="widefat striped">
                        <thead><tr><th>Device</th><th>Uploads</th><th>Orders</th><th>Last seen</th></tr></thead>
                        <tbody>
                        <?php foreach ( $guests as $g ) : ?>
                            <tr>
                                <td><?php echo esc_html( $g->display_name ?: ( 'Guest #' . $g->id ) ); ?><br><small><?php echo esc_html( substr( $g->fingerprint_hash ?: $g->guest_uid, 0, 10 ) ); ?>…</small></td>
                                <td><?php echo (int) $g->upload_count; ?></td>
                                <td><?php echo (int) $g->order_count; ?></td>
                                <td><small><?php echo esc_html( $g->last_seen ); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>

                    <h2>Engagement</h2>
                    <p class="tepe-stats">
                        <span class="tepe-stat"><strong><?php echo (int) TEPE_Gallery::get_views( $id ); ?></strong> views</span>
                        <span class="tepe-stat"><strong><?php echo (int) TEPE_Gallery::count_uploads( $id, 'approved' ); ?></strong> photos</span>
                        <span class="tepe-stat"><strong><?php echo (int) count( $notes ); ?></strong> notes</span>
                    </p>

                    <h2>Photo Notes / Guestbook (<?php echo count( $notes ); ?>)</h2>
                    <?php if ( empty( $notes ) ) : ?><p class="description">No notes yet.</p><?php else : ?>
                    <div class="tepe-notes-admin">
                        <?php foreach ( $notes as $n ) : ?>
                            <div class="tepe-noteadmin" data-id="<?php echo (int) $n['id']; ?>">
                                <img src="<?php echo esc_url( $n['thumb_url'] ); ?>" alt="" loading="lazy">
                                <div>
                                    <p class="tepe-noteadmin__msg"><?php echo esc_html( $n['message'] ); ?></p>
                                    <p class="tepe-noteadmin__by">— <?php echo esc_html( $n['author'] ?: 'A guest' ); ?>
                                        <?php if ( $n['status'] === 'pending' ) : ?><span class="tepe-tag tepe-tag--gold">Pending</span><?php endif; ?>
                                    </p>
                                    <p>
                                        <?php if ( $n['status'] === 'pending' ) : ?><button class="button button-small tepe-note-approve" data-id="<?php echo (int) $n['id']; ?>">Approve</button><?php endif; ?>
                                        <button class="button button-small tepe-note-delete" data-id="<?php echo (int) $n['id']; ?>">Delete</button>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <h2>Photos (<?php echo count( $uploads ); ?>) <span class="description">— click a photo to set it as the cover</span></h2>
            <div class="tepe-admin-grid">
                <?php foreach ( $uploads as $u ) :
                    $base = TEPE_Gallery::url( $event );
                    $thumb = $base . '/thumbs/' . tepe_thumb_name( $u->filename );
                ?>
                    <div class="tepe-admin-tile tepe-status-<?php echo esc_attr( $u->status ); ?>" data-id="<?php echo (int) $u->id; ?>">
                        <img src="<?php echo esc_url( $thumb ); ?>" loading="lazy" alt="" onerror="this.src='<?php echo esc_url( $base . '/' . $u->filename ); ?>'">
                        <div class="tepe-admin-tile__bar">
                            <button class="button-link tepe-setcover" data-id="<?php echo (int) $u->id; ?>" title="Set as cover">★</button>
                            <?php if ( $u->status === 'pending' ) : ?>
                                <button class="button-link tepe-approve" data-id="<?php echo (int) $u->id; ?>" title="Approve">✓</button>
                            <?php endif; ?>
                            <button class="button-link tepe-delete" data-id="<?php echo (int) $u->id; ?>" title="Delete">🗑</button>
                        </div>
                        <?php if ( $u->status === 'pending' ) : ?><span class="tepe-admin-tile__flag">Pending</span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}
