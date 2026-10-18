<?php
/**
 * Removes the plugin's setting. Fan club sign-ups are kept (export them first
 * if you no longer need them, then delete them under BEAZER Demo).
 *
 * @package beazer-demo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'beazer_demo_notify' );
