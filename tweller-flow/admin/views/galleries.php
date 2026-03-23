<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf-wrap">
    <h1>Galleries</h1>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="tf-alert tf-alert--success">Gallery settings saved.</div>
    <?php elseif ( isset( $_GET['deleted'] ) ) : ?>
        <div class="tf-alert tf-alert--success">Gallery deleted.</div>
    <?php endif; ?>

    <?php if ( empty( $sessions_with_galleries ) ) : ?>
        <div class="tf-card">
            <p style="text-align:center; color:#6B7280; padding:40px 0;">
                No galleries yet. Export photos from Lightroom or let the watcher auto-upload from your exports folder.
            </p>
        </div>
    <?php else : ?>

        <?php foreach ( $sessions_with_galleries as $session ) :
            $gallery_info = TwellerFlow_Gallery::get_gallery_info( $session->id, $session->tracking_code );
            $gallery_url  = TwellerFlow_Gallery::get_gallery_url( $session->tracking_code );
            $client_link  = $tracker_url ? $tracker_url . ( strpos( $tracker_url, '?' ) !== false ? '&' : '?' ) . 'code=' . $session->tracking_code . '#gallery' : '';
        ?>
            <div class="tf-card tf-mb-6">
                <!-- Gallery Header -->
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
                    <div>
                        <h2 style="margin:0 0 4px 0; font-size:18px;">
                            <?php echo esc_html( $session->client_name ); ?>
                            <span style="font-weight:400; color:#6B7280; font-size:14px; margin-left:8px;"><?php echo esc_html( $session->tracking_code ); ?></span>
                        </h2>
                        <div style="font-size:13px; color:#9CA3AF; display:flex; gap:16px; flex-wrap:wrap;">
                            <?php if ( $session->session_date ) : ?>
                                <span>Session: <?php echo date( 'M j, Y', strtotime( $session->session_date ) ); ?></span>
                            <?php endif; ?>
                            <span>Stage: <strong style="color:#374151;"><?php echo ucfirst( $session->current_stage ); ?></strong></span>
                            <span><?php echo $gallery_info['photo_count']; ?> photos<?php echo $gallery_info['total_size_mb'] ? ' (' . $gallery_info['total_size_mb'] . ' MB)' : ''; ?></span>
                        </div>
                    </div>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <?php if ( $client_link && $gallery_info['photo_count'] > 0 ) : ?>
                            <a href="<?php echo esc_url( $client_link ); ?>" target="_blank" class="tf-btn tf-btn--secondary tf-btn--sm" style="font-size:12px;">Client View</a>
                        <?php endif; ?>
                        <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-session&id=' . $session->id ); ?>" class="tf-btn tf-btn--ghost tf-btn--sm" style="font-size:12px;">Session Details</a>
                    </div>
                </div>

                <!-- Photo Grid -->
                <?php if ( $gallery_info['photo_count'] > 0 ) : ?>
                    <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(100px,1fr)); gap:8px; margin-bottom:16px;">
                        <?php foreach ( array_slice( $gallery_info['photos'], 0, 20 ) as $photo ) : ?>
                            <div style="position:relative; border-radius:8px; overflow:hidden; aspect-ratio:1; background:#F3F4F6;">
                                <img src="<?php echo esc_url( $gallery_url . '/thumbs/' . $photo->filename ); ?>" alt="<?php echo esc_attr( $photo->filename ); ?>" style="width:100%; height:100%; object-fit:cover;">
                            </div>
                        <?php endforeach; ?>
                        <?php if ( $gallery_info['photo_count'] > 20 ) : ?>
                            <div style="border-radius:8px; aspect-ratio:1; background:#F3F4F6; display:flex; align-items:center; justify-content:center; color:#6B7280; font-size:14px; font-weight:600;">
                                +<?php echo $gallery_info['photo_count'] - 20; ?> more
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Gallery Controls -->
                <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; padding-top:16px; border-top:1px solid #F3F4F6;">
                    <!-- Password -->
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:13px; color:#6B7280;">
                            <?php echo $gallery_info['has_password'] ? '&#128274; Protected' : '&#128275; No password'; ?>
                        </span>
                        <form method="post" style="display:inline-flex; gap:4px; align-items:center;">
                            <?php wp_nonce_field( 'tweller_flow_gallery_password' ); ?>
                            <input type="hidden" name="tweller_flow_gallery_password" value="1">
                            <input type="hidden" name="session_id" value="<?php echo $session->id; ?>">
                            <input type="hidden" name="redirect_to" value="galleries">
                            <input type="text" name="gallery_pw" placeholder="<?php echo $gallery_info['has_password'] ? 'Change password' : 'Set password'; ?>" style="padding:5px 8px; font-size:12px; border:1px solid #D1D5DB; border-radius:6px; width:120px; font-family:inherit;">
                            <button type="submit" class="tf-btn tf-btn--secondary tf-btn--sm" style="font-size:11px; padding:5px 10px;">Set</button>
                        </form>
                        <?php if ( $gallery_info['has_password'] ) : ?>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'tweller_flow_gallery_password' ); ?>
                                <input type="hidden" name="tweller_flow_gallery_password" value="1">
                                <input type="hidden" name="session_id" value="<?php echo $session->id; ?>">
                                <input type="hidden" name="gallery_pw" value="">
                                <input type="hidden" name="redirect_to" value="galleries">
                                <button type="submit" class="tf-btn tf-btn--ghost tf-btn--sm" style="font-size:11px; padding:5px 10px; color:#DC2626;" onclick="return confirm('Remove password protection?');">Remove</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div style="margin-left:auto;">
                        <?php if ( $gallery_info['photo_count'] > 0 ) : ?>
                            <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=tweller-flow-galleries&action=delete_gallery&session_id=' . $session->id ), 'tweller_flow_delete_gallery_' . $session->id ); ?>"
                               class="tf-btn tf-btn--danger tf-btn--sm" style="font-size:11px; padding:5px 10px;"
                               onclick="return confirm('Delete all <?php echo $gallery_info['photo_count']; ?> photos from this gallery? This cannot be undone.');">
                                Delete Gallery
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>
</div>
