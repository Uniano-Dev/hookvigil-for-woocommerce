<?php
/**
 * Orders affected by a webhook outage.
 *
 * @package Hookvigil
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns an outage window into a list of order IDs.
 */
class WWFW_Missed_Orders {

	const LIMIT = 1000;

	/**
	 * Whether a topic is an order topic this class can resolve.
	 *
	 * @param string $topic Webhook topic.
	 * @return bool
	 */
	public static function supports( $topic ) {
		return in_array( $topic, array( 'order.created', 'order.updated' ), true );
	}

	/**
	 * Order IDs created (order.created) or modified (order.updated) during the incident window.
	 *
	 * @param array $incident Incident.
	 * @return int[]
	 */
	public static function order_ids( array $incident ) {
		if ( ! self::supports( $incident['topic'] ) ) {
			return array();
		}

		$start = (int) $incident['opened_at'];
		$end   = empty( $incident['closed_at'] ) ? time() : (int) $incident['closed_at'];
		$field = ( 'order.created' === $incident['topic'] ) ? 'date_created' : 'date_modified';

		$ids = wc_get_orders(
			array(
				$field    => $start . '...' . $end,
				'limit'   => self::LIMIT,
				'orderby' => 'date',
				'order'   => 'ASC',
				'return'  => 'ids',
				'type'    => 'shop_order',
				'status'  => array_keys( wc_get_order_statuses() ),
			)
		);

		return array_map( 'absint', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Stream a CSV of affected orders to the browser.
	 *
	 * @param array $incident Incident.
	 */
	public static function output_csv( array $incident ) {
		$filename = sprintf( 'missed-orders-webhook-%d-%s.csv', (int) $incident['webhook_id'], gmdate( 'Ymd-His', (int) $incident['opened_at'] ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming download.
		fputcsv( $out, array( 'order_id', 'order_number', 'status', 'date_created_gmt', 'date_modified_gmt', 'total', 'currency', 'date_created_site', 'date_modified_site' ) );
		foreach ( self::order_ids( $incident ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$created  = $order->get_date_created();
			$modified = $order->get_date_modified();
			fputcsv(
				$out,
				array(
					$order_id,
					$order->get_order_number(),
					$order->get_status(),
					$created ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : '',
					$modified ? gmdate( 'Y-m-d H:i:s', $modified->getTimestamp() ) : '',
					$order->get_total(),
					$order->get_currency(),
					$created ? wp_date( 'Y-m-d H:i:s', $created->getTimestamp() ) : '',
					$modified ? wp_date( 'Y-m-d H:i:s', $modified->getTimestamp() ) : '',
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming download.
	}
}
