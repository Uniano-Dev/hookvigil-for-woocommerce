<?php
/**
 * Uninstall removes all plugin data.
 */

t_section( 'Uninstall cleanup' );
global $wpdb;

WWFW_Plugin::schedule();
t_assert( as_has_scheduled_action( 'wwfw_run_checks', array(), 'wwfw' ), 'recurring check scheduled' );
t_assert( as_has_scheduled_action( 'wwfw_prune', array(), 'wwfw' ), 'daily prune scheduled' );

$table = WWFW_Delivery_Log::table();
t_assert( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'delivery table exists before uninstall' );

update_user_meta( 1, 'wwfw_notice_snoozed', array( 'until' => time() + 60, 'signature' => 'x' ) );

define( 'WP_UNINSTALL_PLUGIN', 'hookvigil-for-woocommerce/hookvigil-for-woocommerce.php' );
include WP_PLUGIN_DIR . '/hookvigil-for-woocommerce/uninstall.php';

$wpdb->flush();
t_assert( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'delivery table dropped' );
t_assert( false === get_option( 'wwfw_settings' ) && false === get_option( 'wwfw_incidents' ) && false === get_option( 'wwfw_state' ), 'options removed' );
t_assert( ! as_has_scheduled_action( 'wwfw_run_checks', array(), 'wwfw' ) && ! as_has_scheduled_action( 'wwfw_prune', array(), 'wwfw' ), 'scheduled actions removed' );
t_assert( '' === get_user_meta( 1, 'wwfw_notice_snoozed', true ), 'notice snooze user meta removed' );
