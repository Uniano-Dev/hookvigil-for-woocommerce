<?php
/**
 * Plugin wiring.
 *
 * @package Hookvigil
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks, schedules and shared state.
 */
class WWFW_Plugin {

	const STATE_OPTION = 'wwfw_state';
	const GROUP        = 'wwfw';

	/**
	 * Bootstrap after plugins are loaded.
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woocommerce_notice' ) );
			return;
		}

		if ( WWFW_Delivery_Log::DB_VERSION !== self::state( 'db_version' ) ) {
			WWFW_Delivery_Log::install();
		}

		add_action( 'woocommerce_webhook_delivery', array( 'WWFW_Delivery_Log', 'capture' ), 10, 5 );
		add_action( 'woocommerce_webhook_disabled_due_delivery_failures', array( 'WWFW_Monitor', 'on_disabled' ) );

		add_action( 'wwfw_run_checks', array( 'WWFW_Monitor', 'run_checks' ) );
		add_action( 'wwfw_dispatch_alerts', array( 'WWFW_Monitor', 'dispatch_alerts' ) );
		add_action( 'wwfw_prune', array( 'WWFW_Delivery_Log', 'prune' ) );

		// Only admin requests verify the schedules, so storefront requests stay query-free.
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );

		if ( is_admin() ) {
			WWFW_Admin::init();
		}
	}

	/**
	 * Activation: create table. Schedules are created on the next load once Action Scheduler is ready.
	 */
	public static function activate() {
		if ( class_exists( 'WooCommerce' ) ) {
			WWFW_Delivery_Log::install();
		}
	}

	/**
	 * Deactivation: remove scheduled actions.
	 */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}

	/**
	 * Ensure recurring actions exist.
	 */
	public static function schedule() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		/**
		 * Filters the number of seconds between checks.
		 *
		 * @param int $seconds Default 300.
		 */
		$interval = max( 60, (int) apply_filters( 'wwfw_check_interval', 5 * MINUTE_IN_SECONDS ) );

		if ( ! as_has_scheduled_action( 'wwfw_run_checks', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 60, $interval, 'wwfw_run_checks', array(), self::GROUP );
		}
		if ( ! as_has_scheduled_action( 'wwfw_prune', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'wwfw_prune', array(), self::GROUP );
		}
	}

	/**
	 * Read a state value.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function state( $key ) {
		$state = get_option( self::STATE_OPTION, array() );
		return ( is_array( $state ) && isset( $state[ $key ] ) ) ? $state[ $key ] : null;
	}

	/**
	 * Merge values into state.
	 *
	 * @param array $values Values.
	 */
	public static function update_state( array $values ) {
		$state = get_option( self::STATE_OPTION, array() );
		$state = array_merge( is_array( $state ) ? $state : array(), $values );
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Log an internal error to the WooCommerce log without interrupting the request.
	 *
	 * @param string    $where Context.
	 * @param Throwable $e     Error.
	 */
	public static function log_error( $where, $e ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $where . ': ' . $e->getMessage(), array( 'source' => 'hookvigil' ) );
		}
	}

	/**
	 * Admin notice when WooCommerce is not active.
	 */
	public static function missing_woocommerce_notice() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Hookvigil for WooCommerce needs WooCommerce to be installed and active.', 'hookvigil-for-woocommerce' ) . '</p></div>';
	}
}
