<?php
/**
 * Checks and alert dispatch.
 *
 * @package Hookvigil
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects disabled webhooks and stuck deliveries, and dispatches alerts.
 */
class WWFW_Monitor {

	const DELIVERY_HOOK = 'woocommerce_deliver_webhook_async';
	const QUEUE_KEY     = 'queue_backlog';

	/**
	 * Callback for `woocommerce_webhook_disabled_due_delivery_failures`.
	 * Runs inside the delivery (possibly a shopper request), so it only records and enqueues.
	 *
	 * @param int $webhook_id Webhook ID.
	 */
	public static function on_disabled( $webhook_id ) {
		try {
			$webhook = wc_get_webhook( $webhook_id );
			self::open_webhook_incident( $webhook, $webhook_id, false );
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'wwfw_dispatch_alerts', array(), 'wwfw' );
			}
		} catch ( Throwable $e ) {
			WWFW_Plugin::log_error( 'on_disabled', $e );
		}
	}

	/**
	 * Full periodic check.
	 */
	public static function run_checks() {
		try {
			self::check_webhooks();
			self::check_queue();
			WWFW_Plugin::update_state( array( 'last_check_at' => time() ) );
			self::dispatch_alerts();
		} catch ( Throwable $e ) {
			WWFW_Plugin::log_error( 'run_checks', $e );
		}
	}

	/**
	 * Open incidents for disabled webhooks, close incidents for webhooks that are active again or gone.
	 */
	public static function check_webhooks() {
		$disabled = array();
		$existing = array();

		$data_store = WC_Data_Store::load( 'webhook' );
		foreach ( $data_store->search_webhooks( array( 'limit' => -1 ) ) as $id ) {
			$webhook = wc_get_webhook( $id );
			if ( ! $webhook ) {
				continue;
			}
			$existing[ (int) $id ] = $webhook->get_status();
			if ( 'disabled' === $webhook->get_status() ) {
				$disabled[ (int) $id ] = $webhook;
			}
		}

		foreach ( $disabled as $id => $webhook ) {
			self::open_webhook_incident( $webhook, $id, true );
		}

		foreach ( WWFW_Incidents::open_incidents() as $incident ) {
			if ( 'webhook_disabled' !== $incident['type'] ) {
				continue;
			}
			$id = (int) $incident['webhook_id'];
			if ( ! isset( $existing[ $id ] ) ) {
				// Webhook deleted: close without a recovery notice.
				WWFW_Incidents::close( $incident['key'] );
				WWFW_Incidents::update( $incident['key'], $incident['opened_at'], array( 'alert_pending' => '' ) );
			} elseif ( 'disabled' !== $existing[ $id ] ) {
				WWFW_Incidents::close( $incident['key'] );
			}
		}
	}

	/**
	 * Look for overdue and failed webhook delivery actions.
	 *
	 * @return array{overdue:int,oldest_minutes:int,failed_24h:int,applicable:bool}
	 */
	public static function queue_status() {
		$status = array(
			'overdue'        => 0,
			'oldest_minutes' => 0,
			'failed_24h'     => 0,
			'applicable'     => function_exists( 'as_get_scheduled_actions' ),
		);
		if ( ! $status['applicable'] ) {
			return $status;
		}

		$threshold = time() - (int) WWFW_Settings::get( 'backlog_minutes' ) * MINUTE_IN_SECONDS;
		$overdue   = as_get_scheduled_actions(
			array(
				'hook'     => self::DELIVERY_HOOK,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'date'     => gmdate( 'Y-m-d H:i:s', $threshold ),
				'date_compare' => '<=',
				'per_page' => 500,
				'orderby'  => 'date',
				'order'    => 'ASC',
			),
			'ids'
		);
		$status['overdue'] = count( $overdue );
		if ( $status['overdue'] > 0 ) {
			$oldest = ActionScheduler::store()->get_date( reset( $overdue ) );
			if ( $oldest ) {
				$status['oldest_minutes'] = (int) floor( ( time() - $oldest->getTimestamp() ) / MINUTE_IN_SECONDS );
			}
		}

		$failed               = as_get_scheduled_actions(
			array(
				'hook'         => self::DELIVERY_HOOK,
				'status'       => ActionScheduler_Store::STATUS_FAILED,
				'date'         => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'date_compare' => '>=',
				'per_page'     => 500,
			),
			'ids'
		);
		$status['failed_24h'] = count( $failed );

		return $status;
	}

	/**
	 * Open or close the queue backlog incident.
	 */
	public static function check_queue() {
		$status = self::queue_status();
		if ( ! $status['applicable'] ) {
			return;
		}
		if ( $status['overdue'] > 0 ) {
			WWFW_Incidents::open(
				array(
					'key'     => self::QUEUE_KEY,
					'type'    => 'queue_backlog',
					'details' => sprintf( '%d|%d', $status['overdue'], $status['oldest_minutes'] ),
				)
			);
			$open = WWFW_Incidents::find_open( self::QUEUE_KEY );
			if ( $open ) {
				WWFW_Incidents::update( self::QUEUE_KEY, $open['opened_at'], array( 'details' => sprintf( '%d|%d', $status['overdue'], $status['oldest_minutes'] ) ) );
			}
		} else {
			WWFW_Incidents::close( self::QUEUE_KEY );
		}
	}

	/**
	 * Send pending alerts: new incidents, reminders, recoveries.
	 */
	public static function dispatch_alerts() {
		$reminder = (int) WWFW_Settings::get( 'reminder_hours' ) * HOUR_IN_SECONDS;
		$now      = time();

		foreach ( WWFW_Incidents::all() as $incident ) {
			$event = '';
			if ( 'open' === $incident['alert_pending'] && empty( $incident['closed_at'] ) ) {
				$event = 'open';
			} elseif ( 'recovered' === $incident['alert_pending'] ) {
				$event = 'recovered';
			} elseif ( empty( $incident['closed_at'] ) && $incident['last_alert_at'] && ( $now - $incident['last_alert_at'] ) >= $reminder ) {
				$event = 'reminder';
			}
			if ( '' === $event ) {
				continue;
			}

			// Mark first so a slow or failing channel never causes repeated sends.
			WWFW_Incidents::update(
				$incident['key'],
				$incident['opened_at'],
				array(
					'alert_pending' => '',
					'last_alert_at' => $now,
				)
			);

			$alert   = WWFW_Alert::for_incident( $incident, $event );
			$results = WWFW_Notifier::send( $alert['subject'], $alert['text'], $alert );

			/**
			 * Fires after alerts for an incident were sent.
			 *
			 * @param array  $incident Incident.
			 * @param string $event    open|reminder|recovered.
			 * @param array  $results  Per-channel results.
			 */
			do_action( 'wwfw_alert_sent', $incident, $event, $results );
		}
	}

	/**
	 * Subject and plain text for an incident event (kept for compatibility; see WWFW_Alert).
	 *
	 * @param array  $incident Incident.
	 * @param string $event    open|reminder|recovered.
	 * @return string[] [ subject, text ].
	 */
	public static function message( array $incident, $event ) {
		$alert = WWFW_Alert::for_incident( $incident, $event );
		return array( $alert['subject'], $alert['text'] );
	}

	/**
	 * Re-enable a webhook and reset its failure counter (works around WooCommerce issue #70247).
	 *
	 * @param int $webhook_id Webhook ID.
	 * @return bool
	 */
	public static function reenable( $webhook_id ) {
		$webhook = wc_get_webhook( $webhook_id );
		if ( ! $webhook ) {
			return false;
		}
		$webhook->set_failure_count( 0 );
		$webhook->set_status( 'active' );
		$webhook->save();
		WWFW_Incidents::close( 'webhook_disabled:' . (int) $webhook_id );
		return true;
	}

	/**
	 * Open an incident for a disabled webhook.
	 *
	 * @param WC_Webhook|false $webhook    Webhook object.
	 * @param int              $webhook_id Webhook ID.
	 * @param bool             $from_scan  True when found by the periodic scan.
	 */
	private static function open_webhook_incident( $webhook, $webhook_id, $from_scan ) {
		$opened_at = time();
		if ( $from_scan && $webhook && $webhook->get_date_modified() ) {
			$opened_at = $webhook->get_date_modified()->getTimestamp();
		}
		WWFW_Incidents::open(
			array(
				'key'           => 'webhook_disabled:' . (int) $webhook_id,
				'type'          => 'webhook_disabled',
				'webhook_id'    => (int) $webhook_id,
				'topic'         => $webhook ? $webhook->get_topic() : '',
				'name'          => $webhook ? $webhook->get_name() : '',
				'opened_at'     => $opened_at,
				'opened_approx' => $from_scan,
			)
		);
	}
}
