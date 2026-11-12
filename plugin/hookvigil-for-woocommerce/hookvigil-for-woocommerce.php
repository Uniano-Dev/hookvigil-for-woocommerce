<?php
/**
 * Plugin Name:          Hookvigil for WooCommerce
 * Description:          Alerts you by email, Telegram or Slack when WooCommerce disables a webhook or webhook deliveries get stuck, and shows which orders were missed.
 * Version:              0.3.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               uniano
 * Author URI:           https://profiles.wordpress.org/uniano/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          hookvigil-for-woocommerce
 * WC requires at least: 9.0
 * WC tested up to:      11.2
 *
 * @package Hookvigil
 */

defined( 'ABSPATH' ) || exit;

define( 'WWFW_VERSION', '0.3.0' );
define( 'WWFW_FILE', __FILE__ );
define( 'WWFW_DIR', plugin_dir_path( __FILE__ ) );

require_once WWFW_DIR . 'includes/class-wwfw-settings.php';
require_once WWFW_DIR . 'includes/class-wwfw-delivery-log.php';
require_once WWFW_DIR . 'includes/class-wwfw-incidents.php';
require_once WWFW_DIR . 'includes/class-wwfw-alert.php';
require_once WWFW_DIR . 'includes/class-wwfw-notifier.php';
require_once WWFW_DIR . 'includes/class-wwfw-missed-orders.php';
require_once WWFW_DIR . 'includes/class-wwfw-monitor.php';
require_once WWFW_DIR . 'includes/class-wwfw-plugin.php';
require_once WWFW_DIR . 'includes/admin/class-wwfw-admin.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WWFW_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WWFW_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( 'WWFW_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WWFW_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'WWFW_Plugin', 'init' ) );
