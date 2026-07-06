<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf2-wrap">
    <div class="tf2-page-header">
        <h1>Sessions</h1>
        <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-2-new' ); ?>" class="tf2-btn tf2-btn--primary">+ New Session</a>
    </div>

    <!-- Filters -->
    <form method="get" class="tf2-filters">
        <input type="hidden" name="page" value="tweller-flow-2-sessions">
        <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search clients..." class="tf2-filters__search">
        <select name="stage" class="tf2-filters__select" onchange="this.form.submit()">
            <option value="">All Stages</option>
            <?php foreach ( $stages as $key => $s ) : ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $stage, $key ); ?>><?php echo esc_html( $s['label'] ); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="tf2-btn tf2-btn--secondary">Search</button>
        <?php if ( $search || $stage ) : ?>
            <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-2-sessions' ); ?>" class="tf2-btn tf2-btn--ghost">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ( isset( $_GET['deleted'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Session deleted.</div>
    <?php endif; ?>

    <!-- Sessions Table -->
    <div class="tf2-card tf2-card--flush">
        <?php if ( empty( $sessions ) ) : ?>
            <div class="tf2-empty">
                <div class="tf2-empty__text"><?php echo $search || $stage ? 'No sessions match your filters.' : 'No sessions yet.'; ?></div>
                <?php if ( ! $search && ! $stage ) : ?>
                    <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-2-new' ); ?>" class="tf2-btn tf2-btn--primary">Create First Session</a>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <table class="tf2-table">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Package</th>
                        <th>Date</th>
                        <th>Stage</th>
                        <th>Payment</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $packages_opt = get_option( 'tweller_flow_2_packages', array() );
                    foreach ( $sessions as $s ) :
                        $stage_info = $stages[ $s->current_stage ] ?? array( 'label' => $s->current_stage );
                        $pkg = $packages_opt[ $s->package_type ] ?? array();
                        $pkg_name = $pkg['name'] ?? ucfirst( $s->package_type );
                    ?>
                        <tr>
                            <td>
                                <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-2-session&id=' . $s->id ); ?>">
                                    <span class="tf2-client-name"><?php echo esc_html( $s->client_name ); ?></span>
                                </a>
                                <div class="tf2-muted tf2-text-sm"><?php echo esc_html( $s->tracking_code ); ?></div>
                            </td>
                            <td><?php echo esc_html( $pkg_name ); ?></td>
                            <td>
                                <?php if ( $s->session_date ) : ?>
                                    <?php echo date( 'M j, Y', strtotime( $s->session_date ) ); ?>
                                    <?php if ( $s->session_time ) : ?>
                                        <div class="tf2-muted tf2-text-sm"><?php echo date( 'g:i A', strtotime( $s->session_time ) ); ?></div>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="tf2-muted">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="tf2-badge tf2-badge--<?php echo esc_attr( $s->current_stage ); ?>"><?php echo esc_html( $stage_info['label'] ); ?></span>
                            </td>
                            <td>
                                <span class="tf2-badge tf2-badge--<?php echo esc_attr( $s->payment_status ); ?>"><?php echo esc_html( ucfirst( $s->payment_status ) ); ?></span>
                            </td>
                            <td class="tf2-text-right">
                                <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-2-session&id=' . $s->id ); ?>" class="tf2-btn tf2-btn--ghost tf2-btn--sm">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $total_pages = ceil( $total / $per_page );
            if ( $total_pages > 1 ) : ?>
                <div class="tf2-pagination" style="padding: 16px;">
                    <?php for ( $i = 1; $i <= $total_pages; $i++ ) :
                        $url = add_query_arg( array( 'paged' => $i, 's' => $search, 'stage' => $stage ), admin_url( 'admin.php?page=tweller-flow-2-sessions' ) );
                    ?>
                        <?php if ( $i === $paged ) : ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else : ?>
                            <a href="<?php echo esc_url( $url ); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
