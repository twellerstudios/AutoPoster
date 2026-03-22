<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf-admin">
    <h1>Notification Log</h1>

    <div class="tf-card">
        <?php if ( empty( $notifications ) ) : ?>
            <p class="tf-empty">No notifications have been sent yet.</p>
        <?php else : ?>
            <table class="tf-table widefat">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Client</th>
                        <th>Subject</th>
                        <th>Recipient</th>
                        <th>Sent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $notifications as $notif ) : ?>
                        <tr>
                            <td>
                                <span class="tf-notif-status tf-notif-status--<?php echo esc_attr( $notif->status ); ?>">
                                    <?php echo $notif->status === 'sent' ? '✓ Sent' : '✗ Failed'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ( $notif->client_name ) : ?>
                                    <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-session&id=' . $notif->session_id ); ?>">
                                        <?php echo esc_html( $notif->client_name ); ?>
                                    </a>
                                    <br><small><code><?php echo esc_html( $notif->tracking_code ); ?></code></small>
                                <?php else : ?>
                                    <small>Session #<?php echo esc_html( $notif->session_id ); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $notif->subject ); ?></td>
                            <td><small><?php echo esc_html( $notif->recipient ); ?></small></td>
                            <td><small><?php echo date( 'M j, Y g:i A', strtotime( $notif->sent_at ) ); ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
