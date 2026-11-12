<?php
/**
 * Runs every scenario in one Playground boot.
 */

require __DIR__ . '/helpers.php';

echo 'ENV: PHP ' . PHP_VERSION . ', WordPress ' . get_bloginfo( 'version' ) . ', WooCommerce ' . WC_VERSION . ', plugin ' . WWFW_VERSION . "\n";

foreach ( array( '01-disable-alert.php', '02-health-reenable.php', '03-missed-orders.php', '04-queue-health.php', '06-settings-validation.php', '07-alert-format.php', '08-notice-scope.php', '05-uninstall.php' ) as $file ) {
	try {
		require __DIR__ . '/' . $file;
	} catch ( Throwable $e ) {
		t_assert( false, $file . ' threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	}
}

t_summary();
