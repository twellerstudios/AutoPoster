<?php
/**
 * Print Providers — the labs that produce your orders.
 *
 * Rendered by TwellerFlow2_Print_Fulfillment::page_providers(), which
 * supplies $providers and $settings. Posting back to the same page is
 * handled by that class's handle_admin_actions() (nonce tf2pf_save_providers).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$providers = isset( $providers ) && is_array( $providers ) ? $providers : array();
$settings  = isset( $settings ) && is_array( $settings ) ? $settings : array();

$default_id = '';
foreach ( $providers as $p ) {
    if ( ! empty( $p['default'] ) ) { $default_id = $p['id']; break; }
}

$accounts_url = admin_url( 'admin.php?page=tweller-flow-2-provider-accounts' );
$has_accounts = class_exists( 'TwellerFlow2_Print_Providers' );
?>
<div class="wrap tf2-wrap">
    <h1>Print Providers</h1>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Providers saved.</div>
    <?php endif; ?>

    <p class="tf2-description" style="max-width:760px;">
        These are the labs that print your orders. Add one here, then send any print order to
        them from the <a href="<?php echo esc_url( admin_url( 'admin.php?page=tweller-flow-2-prints' ) ); ?>">Print Store</a>
        screen — they receive a secure, expiring link to the print-ready files.
        <?php if ( $has_accounts ) : ?>
            To give a lab its own login and dashboard, use
            <a href="<?php echo esc_url( $accounts_url ); ?>">Provider Accounts</a>.
        <?php endif; ?>
    </p>

    <?php
    // Surface anything that would stop a job being produced.
    $notices = array();
    if ( class_exists( 'TwellerFlow2_Print_Fulfillment' ) ) {
        if ( ! TwellerFlow2_Print_Fulfillment::has_zip() ) {
            $notices[] = 'PHP is missing the <code>ZipArchive</code> extension, so print-ready ZIPs cannot be built on this server.';
        }
        if ( ! TwellerFlow2_Print_Fulfillment::has_image_editor() ) {
            $notices[] = 'No image editor (Imagick or GD) is available, so photos cannot be cropped to the ordered size.';
        }
    }
    foreach ( $notices as $n ) {
        echo '<div class="tf2-alert" style="background:#FEF3C7; color:#92400E;">' . wp_kses_post( $n ) . '</div>';
    }
    ?>

    <form method="post">
        <?php wp_nonce_field( 'tf2pf_save_providers' ); ?>
        <input type="hidden" name="tf2pf_save_providers" value="1">

        <div class="tf2-card tf2-mb-6">
            <h2 style="margin-top:0;">Labs</h2>

            <?php if ( empty( $providers ) ) : ?>
                <p class="tf2-description" style="margin-bottom:14px;">
                    No print providers yet. Add your first lab below — name is all that's required.
                </p>
            <?php endif; ?>

            <table class="widefat striped" id="tf2pv-table">
                <thead>
                    <tr>
                        <th style="width:22%;">Lab name</th>
                        <th style="width:16%;">Contact</th>
                        <th style="width:18%;">Email</th>
                        <th style="width:12%;">Phone</th>
                        <th style="width:9%;">Ships to client</th>
                        <th style="width:8%;">Active</th>
                        <th style="width:9%;">Default</th>
                        <th style="width:6%;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $rows = $providers;
                $rows[] = array( 'id' => 'new1', 'name' => '', 'active' => 1 ); // one blank row to add
                foreach ( $rows as $i => $p ) :
                    $pid = esc_attr( $p['id'] ?? ( 'new' . $i ) );
                    ?>
                    <tr>
                        <td>
                            <input type="hidden" name="pv[<?php echo $i; ?>][id]" value="<?php echo $pid; ?>">
                            <input type="text" name="pv[<?php echo $i; ?>][name]"
                                value="<?php echo esc_attr( $p['name'] ?? '' ); ?>"
                                placeholder="e.g. Island Photo Lab" style="width:100%;">
                            <?php if ( ! empty( $p['user_id'] ) ) :
                                $u = get_userdata( (int) $p['user_id'] ); ?>
                                <span class="tf2-badge" style="background:#D1FAE5; color:#065F46; margin-top:4px; display:inline-block;">
                                    Portal: <?php echo esc_html( $u ? $u->user_login : ( '#' . (int) $p['user_id'] ) ); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><input type="text" name="pv[<?php echo $i; ?>][contact_name]"
                            value="<?php echo esc_attr( $p['contact_name'] ?? '' ); ?>" style="width:100%;"></td>
                        <td><input type="email" name="pv[<?php echo $i; ?>][email]"
                            value="<?php echo esc_attr( $p['email'] ?? '' ); ?>" style="width:100%;"></td>
                        <td><input type="text" name="pv[<?php echo $i; ?>][phone]"
                            value="<?php echo esc_attr( $p['phone'] ?? '' ); ?>" style="width:100%;"></td>
                        <td style="text-align:center;">
                            <input type="checkbox" name="pv[<?php echo $i; ?>][does_delivery]" value="1"
                                <?php checked( ! empty( $p['does_delivery'] ) ); ?>>
                        </td>
                        <td style="text-align:center;">
                            <input type="checkbox" name="pv[<?php echo $i; ?>][active]" value="1"
                                <?php checked( ! isset( $p['active'] ) || ! empty( $p['active'] ) ); ?>>
                        </td>
                        <td style="text-align:center;">
                            <input type="radio" name="default_provider" value="<?php echo $pid; ?>"
                                <?php checked( $default_id, $p['id'] ?? '' ); ?>>
                        </td>
                        <td style="text-align:center;">
                            <?php if ( ! empty( $p['name'] ) ) : ?>
                                <button type="button" class="button-link tf2pv-clear"
                                    style="color:#B91C1C;" title="Clear this row, then Save to remove the lab">Remove</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="tf2-description" style="margin-top:10px;">
                Blank the lab name and save to remove it. <strong>Ships to client</strong> means that lab posts
                finished orders directly to your customer; leave it off when orders come back to you.
            </p>
        </div>

        <div class="tf2-card tf2-mb-6">
            <h2 style="margin-top:0;">What the lab receives</h2>
            <div class="tf2-field">
                <label class="tf2-field__label">Instructions sent with every job</label>
                <textarea name="pf_instructions" rows="5" style="max-width:760px; width:100%;"><?php
                    echo esc_textarea( $settings['provider_instructions'] ?? '' ); ?></textarea>
                <p class="tf2-description">Included in the hand-off email and on the job sheet.</p>
            </div>
            <div class="tf2-grid tf2-grid--2">
                <div class="tf2-field">
                    <label class="tf2-field__label">Default paper / finish</label>
                    <input type="text" name="pf_paper_finish"
                        value="<?php echo esc_attr( $settings['paper_finish'] ?? '' ); ?>" style="max-width:320px;">
                </div>
                <div class="tf2-field">
                    <label class="tf2-field__label">Download link expires after (days)</label>
                    <input type="number" min="1" max="90" name="pf_link_expiry_days"
                        value="<?php echo esc_attr( (int) ( $settings['link_expiry_days'] ?? 14 ) ); ?>" style="max-width:120px;">
                </div>
            </div>
            <div class="tf2-field">
                <label class="tf2-field__label">Return address</label>
                <textarea name="pf_return_address" rows="3" style="max-width:520px; width:100%;"><?php
                    echo esc_textarea( $settings['return_address'] ?? '' ); ?></textarea>
                <p class="tf2-description">Where finished orders come back to, printed on the job sheet.</p>
            </div>
        </div>

        <p><button type="submit" class="button button-primary">Save providers</button></p>
    </form>
</div>

<script>
(function () {
    // "Remove" just empties the name — the save handler drops nameless rows.
    var btns = document.querySelectorAll('.tf2pv-clear');
    for (var i = 0; i < btns.length; i++) {
        btns[i].addEventListener('click', function () {
            var row = this.closest('tr');
            if (!row) return;
            var name = row.querySelector('input[name*="[name]"]');
            if (!name) return;
            if (!window.confirm('Remove ' + (name.value || 'this lab') + ' when you save?')) return;
            name.value = '';
            row.style.opacity = '0.45';
        });
    }
})();
</script>
