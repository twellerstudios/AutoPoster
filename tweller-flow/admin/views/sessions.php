<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap tf-admin">
    <h1>Sessions
        <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-new' ); ?>" class="page-title-action">Add New</a>
    </h1>

    <?php if ( isset( $_GET['deleted'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Session deleted.</p></div>
    <?php endif; ?>

    <div class="tf-filters">
        <form method="get" class="tf-filters__form">
            <input type="hidden" name="page" value="tweller-flow-sessions">
            <div class="tf-filters__row">
                <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search by name, email, or code..." class="tf-filters__search">
                <select name="stage" class="tf-filters__select">
                    <option value="">All Stages</option>
                    <?php foreach ( $stages as $key => $stage_info ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $stage, $key ); ?>><?php echo esc_html( $stage_info['label'] ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button">Filter</button>
                <?php if ( $search || $stage ) : ?>
                    <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-sessions' ); ?>" class="button">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="tf-card">
        <?php if ( empty( $sessions ) ) : ?>
            <p class="tf-empty">No sessions found. <?php if ( $search || $stage ) : ?>Try adjusting your filters.<?php else : ?><a href="<?php echo admin_url( 'admin.php?page=tweller-flow-new' ); ?>">Create your first session</a>.<?php endif; ?></p>
        <?php else : ?>
            <table class="tf-table tf-table--sessions widefat">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Client</th>
                        <th>Package</th>
                        <th>Session Date</th>
                        <th>Stage</th>
                        <th>Payment</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $sessions as $s ) :
                        $stage_info = $stages[ $s->current_stage ] ?? array( 'label' => $s->current_stage );
                    ?>
                        <tr>
                            <td><code><?php echo esc_html( $s->tracking_code ); ?></code></td>
                            <td>
                                <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-session&id=' . $s->id ); ?>">
                                    <strong><?php echo esc_html( $s->client_name ); ?></strong>
                                </a>
                                <?php if ( $s->client_email ) : ?>
                                    <br><small><?php echo esc_html( $s->client_email ); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( ucfirst( $s->package_type ) ); ?></td>
                            <td><?php echo $s->session_date ? date( 'M j, Y', strtotime( $s->session_date ) ) : '—'; ?></td>
                            <td><span class="tf-badge tf-badge--<?php echo esc_attr( $s->current_stage ); ?>"><?php echo esc_html( $stage_info['label'] ); ?></span></td>
                            <td>
                                <span class="tf-payment tf-payment--<?php echo esc_attr( $s->payment_status ); ?>">
                                    <?php echo esc_html( ucfirst( $s->payment_status ) ); ?>
                                </span>
                                <br><small>$<?php echo number_format( $s->total_amount, 0 ); ?></small>
                            </td>
                            <td><small><?php echo human_time_diff( strtotime( $s->updated_at ), current_time( 'timestamp' ) ); ?> ago</small></td>
                            <td>
                                <a href="<?php echo admin_url( 'admin.php?page=tweller-flow-session&id=' . $s->id ); ?>" class="button button-small">View</a>
                                <?php if ( $s->current_stage !== 'delivered' ) : ?>
                                    <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=tweller-flow-sessions&action=advance&session_id=' . $s->id ), 'tweller_flow_advance_' . $s->id ); ?>" class="button button-small button-primary" title="Advance Stage">▶</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $total_pages = ceil( $total / $per_page );
            if ( $total_pages > 1 ) :
            ?>
                <div class="tf-pagination">
                    <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                        <a href="<?php echo add_query_arg( 'paged', $i ); ?>" class="button <?php echo $i === $paged ? 'button-primary' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
