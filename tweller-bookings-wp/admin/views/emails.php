<?php
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! class_exists( 'TwellerFlow2_Email_Templates' ) ) {
    echo '<div class="wrap"><h1>Emails</h1><p>Email templates are unavailable.</p></div>';
    return;
}

$copy     = TwellerFlow2_Email_Templates::copy_settings();
$registry = TwellerFlow2_Email_Templates::registry();

// Group templates for display.
$groups = array();
foreach ( $registry as $key => $entry ) {
    $groups[ $entry['group'] ][ $key ] = $entry;
}
?>
<div class="wrap tf2-wrap">
    <h1>Emails</h1>
    <p class="tf2-description" style="max-width:760px;">Edit the emails your clients receive, and choose which studio addresses get a copy of everything. Nothing here affects an email until you save a change to it — until then, each email sends exactly as it always has.</p>

    <?php if ( isset( $_GET['copy_saved'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Studio copy settings saved.</div>
    <?php endif; ?>
    <?php if ( isset( $_GET['tpl_saved'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Template saved. Clients will now receive your version.</div>
    <?php elseif ( isset( $_GET['tpl_reset'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Template reset to the built-in default.</div>
    <?php elseif ( isset( $_GET['tpl_tested'] ) ) : ?>
        <div class="tf2-alert tf2-alert--success">Test email sent to you. Check your inbox (and the studio copies).</div>
    <?php elseif ( isset( $_GET['tpl_test_failed'] ) ) : ?>
        <div class="tf2-alert tf2-alert--error">Couldn't send the test — check your SMTP settings under Settings.</div>
    <?php elseif ( isset( $_GET['tpl_error'] ) ) : ?>
        <div class="tf2-alert tf2-alert--error">That template could not be saved.</div>
    <?php endif; ?>

    <!-- Studio copy -->
    <div class="tf2-card tf2-mb-6">
        <h2 style="margin:0 0 6px;">Copy the studio on every email</h2>
        <p class="tf2-description" style="margin-top:0;">Every email the plugin sends — booking, gallery delivery, prints, everything — copies these addresses. An address that is already a direct recipient isn't copied twice.</p>
        <form method="post" action="">
            <?php wp_nonce_field( 'tweller_flow_2_emails' ); ?>
            <label style="display:flex; align-items:center; gap:8px; margin:12px 0;">
                <input type="checkbox" name="email_copy_enabled" value="1" <?php checked( $copy['enabled'] ); ?>>
                <span>Send studio copies (turn off to email only the client/recipient)</span>
            </label>
            <div class="tf2-field" style="max-width:520px;">
                <label class="tf2-field__label">Copy to (Cc — visible to the recipient)</label>
                <input type="text" name="email_copy_cc" value="<?php echo esc_attr( $copy['cc'] ); ?>" class="regular-text" style="width:100%;" placeholder="hello@twellerstudios.com">
                <div class="tf2-field__hint">Comma-separate multiple addresses.</div>
            </div>
            <div class="tf2-field" style="max-width:520px; margin-top:12px;">
                <label class="tf2-field__label">Blind copy to (Bcc — hidden from the recipient)</label>
                <input type="text" name="email_copy_bcc" value="<?php echo esc_attr( $copy['bcc'] ); ?>" class="regular-text" style="width:100%;" placeholder="stephen.twellerstudios@gmail.com">
            </div>
            <p style="margin-top:14px;">
                <button type="submit" name="tweller_flow_2_save_email_copy" value="1" class="button button-primary">Save copy settings</button>
            </p>
        </form>
    </div>

    <!-- Templates -->
    <?php foreach ( $groups as $group_name => $entries ) : ?>
        <h2 style="margin:26px 0 10px; font-size:15px; color:#374151;"><?php echo esc_html( $group_name ); ?></h2>

        <?php foreach ( $entries as $key => $entry ) :
            $edit         = TwellerFlow2_Email_Templates::editable( $key );
            $is_custom    = TwellerFlow2_Email_Templates::is_customised( $key );
        ?>
            <div class="tf2-card tf2-mb-6" id="tpl-<?php echo esc_attr( $key ); ?>">
                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                    <h3 style="margin:0; font-size:15px;"><?php echo esc_html( $entry['label'] ); ?></h3>
                    <?php if ( $is_custom ) : ?>
                        <span class="tf2-badge" style="background:#FEF3C7; color:#92400E;">Customised</span>
                    <?php else : ?>
                        <span class="tf2-badge" style="background:#E5E7EB; color:#374151;">Default</span>
                    <?php endif; ?>
                </div>
                <p class="tf2-description" style="margin:4px 0 12px;"><?php echo esc_html( $entry['desc'] ); ?></p>

                <form method="post" action="">
                    <?php wp_nonce_field( 'tweller_flow_2_emails' ); ?>
                    <input type="hidden" name="email_key" value="<?php echo esc_attr( $key ); ?>">

                    <div class="tf2-field">
                        <label class="tf2-field__label">Subject</label>
                        <input type="text" name="email_subject" value="<?php echo esc_attr( $edit['subject'] ); ?>" style="width:100%;">
                    </div>

                    <div class="tf2-field" style="margin-top:12px;">
                        <label class="tf2-field__label">Body</label>
                        <textarea name="email_body" rows="14" style="width:100%; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12.5px; line-height:1.5;"><?php echo esc_textarea( $edit['body'] ); ?></textarea>
                        <div class="tf2-field__hint">Basic HTML is allowed. The black &amp; gold frame, header and footer are added automatically — just write the message.</div>
                    </div>

                    <div style="margin:10px 0 14px;">
                        <span style="font-size:12px; color:#6B7280;">Merge tags you can use:</span><br>
                        <?php foreach ( $entry['tokens'] as $tok ) : ?>
                            <code style="display:inline-block; background:#F3F4F6; border:1px solid #E5E7EB; border-radius:4px; padding:1px 6px; margin:3px 4px 0 0; font-size:12px;">{{<?php echo esc_html( $tok ); ?>}}</code>
                        <?php endforeach; ?>
                    </div>

                    <p style="margin:0;">
                        <button type="submit" name="tweller_flow_2_save_email_template" value="1" class="button button-primary">Save</button>
                        <button type="submit" name="tweller_flow_2_test_email" value="1" class="button">Send test to me</button>
                        <?php if ( $is_custom ) : ?>
                            <button type="submit" name="tweller_flow_2_reset_email_template" value="1" class="button button-link-delete" style="color:#B91C1C;" onclick="return confirm('Reset this email to the built-in default? Your custom wording will be discarded.');">Reset to default</button>
                        <?php endif; ?>
                        <span style="font-size:12px; color:#9CA3AF; margin-left:6px;">The test sends the saved version — save first if you've just edited.</span>
                    </p>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>
