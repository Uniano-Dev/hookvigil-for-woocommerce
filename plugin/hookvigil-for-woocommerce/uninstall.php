<?php
/**
 * Uninstall: remove every option, table and scheduled action the plugin created.
 *
 * @package Hookvigil
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'wwfw_settings' );
delete_option( 'wwfw_incidents' );
delete_option( 'wwfw_state' );
delete_transient( 'wwfw_fallback_lock' );
delete_metadata( 'user', 0, 'wwfw_notice_snoozed', '', true );

$wwfw_table = $wpdb->prefix . 'wwfw_deliveries';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own table.
$wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $wwfw_table ) );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'wwfw' );
}
