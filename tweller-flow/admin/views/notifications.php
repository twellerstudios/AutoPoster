<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf-wrap">
    <h1>Notification Log</h1>

    <div class="tf-card tf-card--flush">
        <?php if ( empty( $notifications ) ) : ?>
            <div class="tf-empty">
                <div class="tf-empty__text">No notifications sent yet.</div>
            </div>
        <?php else : ?>
            <table class="tf-table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Client</th>
                        <th>Subject</th>
                        <th>Sent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $notifications as $n ) : ?>
                        <tr>
                            <td><span class="tf-notif-status--<?php echo esc_attr( $n->status ); ?>"><?php echo ucfirst( $n->status ); ?></span></td>
                            <td>
                                <?php if ( ! empty( $n->client_name ) ) : ?>
                                    <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-session&id=' . $n->session_id ); ?>">
                                        <span class="tf-client-name"><?php echo esc_html( $n->client_name ); ?></span>
                                    </a>
                                    <div class="tf-muted tf-text-sm"><?php echo esc_html( $n->recipient ); ?></div>
                                <?php else : ?>
                                    <?php echo esc_html( $n->recipient ); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $n->subject ); ?></td>
                            <td class="tf-muted"><?php echo date( 'M j, Y — g:i A', strtotime( $n->sent_at ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
