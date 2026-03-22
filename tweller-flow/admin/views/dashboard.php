<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf-admin">
    <h1>Tweller Flow Dashboard</h1>

    <div class="tf-stats-grid">
        <div class="tf-stat-card tf-stat-card--primary">
            <div class="tf-stat-card__number"><?php echo $active_count; ?></div>
            <div class="tf-stat-card__label">Active Sessions</div>
        </div>
        <div class="tf-stat-card">
            <div class="tf-stat-card__number"><?php echo $total_count; ?></div>
            <div class="tf-stat-card__label">Total Sessions</div>
        </div>
        <div class="tf-stat-card tf-stat-card--green">
            <div class="tf-stat-card__number">$<?php echo number_format( $revenue['month_revenue'], 0 ); ?></div>
            <div class="tf-stat-card__label">This Month</div>
        </div>
        <div class="tf-stat-card">
            <div class="tf-stat-card__number">$<?php echo number_format( $revenue['total_revenue'], 0 ); ?></div>
            <div class="tf-stat-card__label">Total Revenue</div>
        </div>
    </div>

    <div class="tf-dashboard-grid">
        <div class="tf-card">
            <h2>Pipeline Overview</h2>
            <div class="tf-pipeline">
                <?php foreach ( $stages as $key => $stage ) :
                    $count = $stage_counts[ $key ] ?? 0;
                    if ( $count === 0 ) continue;
                ?>
                    <div class="tf-pipeline__item">
                        <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-sessions&stage=' . $key ); ?>">
                            <span class="tf-pipeline__count"><?php echo $count; ?></span>
                            <span class="tf-pipeline__label"><?php echo esc_html( $stage['label'] ); ?></span>
                        </a>
                    </div>
                <?php endforeach; ?>
                <?php if ( empty( array_filter( $stage_counts ) ) ) : ?>
                    <p class="tf-empty">No active sessions. <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-new' ); ?>">Create your first session</a>.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="tf-card">
            <h2>Recent Activity</h2>
            <?php if ( empty( $recent ) ) : ?>
                <p class="tf-empty">No sessions yet.</p>
            <?php else : ?>
                <table class="tf-table">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Stage</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $recent as $s ) :
                            $stage_info = $stages[ $s->current_stage ] ?? array( 'label' => $s->current_stage );
                        ?>
                            <tr>
                                <td>
                                    <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-session&id=' . $s->id ); ?>">
                                        <strong><?php echo esc_html( $s->client_name ); ?></strong>
                                    </a>
                                    <br><small class="tf-muted"><?php echo esc_html( $s->tracking_code ); ?></small>
                                </td>
                                <td><span class="tf-badge tf-badge--<?php echo esc_attr( $s->current_stage ); ?>"><?php echo esc_html( $stage_info['label'] ); ?></span></td>
                                <td><small><?php echo human_time_diff( strtotime( $s->updated_at ), current_time( 'timestamp' ) ); ?> ago</small></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <p><a href="<?php echo admin_url( 'admin.php?page=tweller-flow-sessions' ); ?>" class="button">View All Sessions</a></p>
        </div>
    </div>

    <div class="tf-card">
        <h2>Quick Actions</h2>
        <div class="tf-quick-actions">
            <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-new' ); ?>" class="button button-primary button-hero">+ New Session</a>
            <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-settings' ); ?>" class="button button-hero">Settings</a>
        </div>
    </div>
</div>
