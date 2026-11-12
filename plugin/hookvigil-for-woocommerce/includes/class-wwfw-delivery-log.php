<?php
/**
 * Delivery metadata log.
 *
 * @package Hookvigil
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores one metadata row per webhook delivery. Bodies are never stored.
 */
class WWFW_Delivery_Log {

	const DB_VERSION = '1';

	/**
	 * Table name with prefix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wwfw_deliveries';
	}

	/**
	 * Create or upgrade the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			webhook_id bigint(20) unsigned NOT NULL DEFAULT 0,
			topic varchar(100) NOT NULL DEFAULT '',
			resource_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status_code varchar(60) NOT NULL DEFAULT '',
			success tinyint(1) NOT NULL DEFAULT 0,
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY webhook_time (webhook_id,created_at),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );
		WWFW_Plugin::update_state( array( 'db_version' => self::DB_VERSION ) );
	}

	/**
	 * Callback for `woocommerce_webhook_delivery`.
	 *
	 * @param array          $http_args  Request args (unused, may contain the payload).
	 * @param array|WP_Error $response   Response or error.
	 * @param float          $duration   Duration in seconds.
	 * @param mixed          $arg        Resource argument (usually an ID).
	 * @param int            $webhook_id Webhook ID.
	 */
	public static function capture( $http_args, $response, $duration, $arg, $webhook_id ) {
		try {
			if ( is_wp_error( $response ) ) {
				$code    = $response->get_error_code();
				$success = false;
			} else {
				$http    = (int) wp_remote_retrieve_response_code( $response );
				$code    = (string) $http;
				$success = ( $http >= 200 && $http < 303 );
			}

			$topic   = '';
			$webhook = wc_get_webhook( $webhook_id );
			if ( $webhook ) {
				$topic = $webhook->get_topic();
			}

			self::insert(
				array(
					'webhook_id'  => absint( $webhook_id ),
					'topic'       => $topic,
					'resource_id' => is_numeric( $arg ) ? absint( $arg ) : 0,
					'status_code' => substr( (string) $code, 0, 60 ),
					'success'     => $success ? 1 : 0,
					'duration_ms' => (int) round( (float) $duration * 1000 ),
				)
			);
		} catch ( Throwable $e ) {
			WWFW_Plugin::log_error( 'capture', $e );
		}
	}

	/**
	 * Insert a row.
	 *
	 * @param array $row Row without id/created_at.
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$row['created_at'] = isset( $row['created_at'] ) ? $row['created_at'] : gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$wpdb->insert(
			self::table(),
			$row,
			array( '%d', '%s', '%d', '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * Per-webhook statistics for the last 24 hours plus last success and last error.
	 *
	 * @param int $webhook_id Webhook ID.
	 * @return array{deliveries:int,failures:int,last_success:string,last_error:string,last_error_at:string}
	 */
	public static function stats( $webhook_id ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, live data.
		$counts = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS deliveries, COALESCE(SUM(success = 0), 0) AS failures FROM %i WHERE webhook_id = %d AND created_at >= %s",
				$table,
				$webhook_id,
				$since
			)
		);
		$last_success = $wpdb->get_var(
			$wpdb->prepare( "SELECT MAX(created_at) FROM %i WHERE webhook_id = %d AND success = 1", $table, $webhook_id )
		);
		$last_error = $wpdb->get_row(
			$wpdb->prepare( "SELECT status_code, created_at FROM %i WHERE webhook_id = %d AND success = 0 ORDER BY created_at DESC, id DESC LIMIT 1", $table, $webhook_id )
		);
		// phpcs:enable

		return array(
			'deliveries'    => $counts ? (int) $counts->deliveries : 0,
			'failures'      => $counts ? (int) $counts->failures : 0,
			'last_success'  => $last_success ? (string) $last_success : '',
			'last_error'    => $last_error ? (string) $last_error->status_code : '',
			'last_error_at' => $last_error ? (string) $last_error->created_at : '',
		);
	}

	/**
	 * Delete rows older than the retention setting, in batches.
	 */
	public static function prune() {
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) WWFW_Settings::get( 'retention_days' ) * DAY_IN_SECONDS );

		for ( $i = 0; $i < 20; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE created_at < %s LIMIT 5000", $table, $cutoff ) );
			if ( ! $deleted || $deleted < 5000 ) {
				break;
			}
		}
	}

	/**
	 * Drop the table (uninstall).
	 */
	public static function drop() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall cleanup of own table.
		$wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $table ) );
	}
}
