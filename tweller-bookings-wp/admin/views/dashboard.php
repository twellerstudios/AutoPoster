<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf2-wrap">
    <div class="tf2-page-header">
        <h1>Dashboard</h1>
        <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-2-new' ); ?>" class="tf2-btn tf2-btn--primary tf2-btn--lg">+ New Session</a>
    </div>

    <!-- Stats -->
    <div class="tf2-grid tf2-grid--4 tf2-mb-6">
        <div class="tf2-stat tf2-stat--blue">
            <span class="tf2-stat__value"><?php echo $active_count; ?></span>
            <span class="tf2-stat__label">Active Sessions</span>
        </div>
        <div class="tf2-stat">
            <span class="tf2-stat__value"><?php echo $total_count; ?></span>
            <span class="tf2-stat__label">Total Sessions</span>
        </div>
        <div class="tf2-stat tf2-stat--green">
            <span class="tf2-stat__value">$<?php echo number_format( $revenue['month_revenue'], 0 ); ?></span>
            <span class="tf2-stat__label">This Month</span>
        </div>
        <div class="tf2-stat tf2-stat--amber">
            <span class="tf2-stat__value">$<?php echo number_format( $revenue['total_revenue'], 0 ); ?></span>
            <span class="tf2-stat__label">All Time</span>
        </div>
    </div>

    <div class="tf2-grid tf2-grid--sidebar">
        <!-- Pipeline Overview -->
        <div class="tf2-card">
            <h2>Pipeline</h2>
            <?php if ( empty( array_filter( $stage_counts ) ) ) : ?>
                <div class="tf2-empty">
                    <div class="tf2-empty__text">No sessions in progress</div>
                    <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-2-new' ); ?>" class="tf2-btn tf2-btn--primary">Create First Session</a>
                </div>
            <?php else : ?>
                <div class="tf2-pipeline-overview">
                    <?php foreach ( $stages as $key => $stage ) :
                        $count = $stage_counts[ $key ] ?? 0;
                        if ( $count === 0 ) continue;
                    ?>
                        <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-2-sessions&stage=' . $key ); ?>" class="tf2-pipeline-chip">
                            <span class="tf2-pipeline-chip__count"><?php echo $count; ?></span>
                            <span><?php echo esc_html( $stage['label'] ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Activity -->
        <div class="tf2-card">
            <h2>Recent</h2>
            <?php if ( empty( $recent ) ) : ?>
                <div class="tf2-empty">
                    <div class="tf2-empty__text">No sessions yet</div>
                </div>
            <?php else : ?>
                <?php foreach ( $recent as $s ) :
                    $stage_info = $stages[ $s->current_stage ] ?? array( 'label' => $s->current_stage );
                ?>
                    <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-2-session&id=' . $s->id ); ?>" style="display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid #F3F4F6; text-decoration:none; color:inherit;">
                        <div>
                            <div style="font-weight:600; font-size:14px; color:#111827;"><?php echo esc_html( $s->client_name ); ?></div>
                            <div style="font-size:12px; color:#9CA3AF;"><?php echo human_time_diff( strtotime( $s->updated_at ), current_time( 'timestamp' ) ); ?> ago</div>
                        </div>
                        <span class="tf2-badge tf2-badge--<?php echo esc_attr( $s->current_stage ); ?>"><?php echo esc_html( $stage_info['label'] ); ?></span>
                    </a>
                <?php endforeach; ?>
                <div class="tf2-mt-4">
                    <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-2-sessions' ); ?>" class="tf2-btn tf2-btn--secondary tf2-btn--sm">View All</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
