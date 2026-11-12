<?php
/**
 * Incident store.
 *
 * @package Hookvigil
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps open and recently closed incidents in the `wwfw_incidents` option.
 */
class WWFW_Incidents {

	const OPTION      = 'wwfw_incidents';
	const MAX_ENTRIES = 200;
	const KEEP_CLOSED = 30 * DAY_IN_SECONDS;

	/**
	 * All incidents, newest first.
	 *
	 * @return array[]
	 */
	public static function all() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * Open incident for a key, or null.
	 *
	 * @param string $key Incident key.
	 * @return array|null
	 */
	public static function find_open( $key ) {
		foreach ( self::all() as $incident ) {
			if ( $incident['key'] === $key && empty( $incident['closed_at'] ) ) {
				return $incident;
			}
		}
		return null;
	}

	/**
	 * Open incidents.
	 *
	 * @return array[]
	 */
	public static function open_incidents() {
		return array_values(
			array_filter(
				self::all(),
				static function ( $incident ) {
					return empty( $incident['closed_at'] );
				}
			)
		);
	}

	/**
	 * Incidents of one webhook (open and closed), newest first.
	 *
	 * @param int $webhook_id Webhook ID.
	 * @return array[]
	 */
	public static function for_webhook( $webhook_id ) {
		return array_values(
			array_filter(
				self::all(),
				static function ( $incident ) use ( $webhook_id ) {
					return 'webhook_disabled' === $incident['type'] && (int) $incident['webhook_id'] === (int) $webhook_id;
				}
			)
		);
	}

	/**
	 * Find one incident by key and opened_at.
	 *
	 * @param string $key       Key.
	 * @param int    $opened_at Opened timestamp.
	 * @return array|null
	 */
	public static function find( $key, $opened_at ) {
		foreach ( self::all() as $incident ) {
			if ( $incident['key'] === $key && (int) $incident['opened_at'] === (int) $opened_at ) {
				return $incident;
			}
		}
		return null;
	}

	/**
	 * Open an incident unless one is already open for the key.
	 *
	 * @param array $data Incident fields: key, type, plus optional webhook_id, topic, name, details, opened_approx, opened_at.
	 * @return bool True when a new incident was opened.
	 */
	public static function open( array $data ) {
		if ( self::find_open( $data['key'] ) ) {
			return false;
		}

		$incident = array_merge(
			array(
				'key'           => '',
				'type'          => '',
				'webhook_id'    => 0,
				'topic'         => '',
				'name'          => '',
				'opened_at'     => time(),
				'opened_approx' => false,
				'last_alert_at' => 0,
				'alert_pending' => 'open',
				'closed_at'     => 0,
				'details'       => '',
			),
			$data
		);

		$list = self::all();
		array_unshift( $list, $incident );
		self::save( $list );
		return true;
	}

	/**
	 * Close the open incident for a key.
	 *
	 * @param string $key Key.
	 * @return bool True when an incident was closed.
	 */
	public static function close( $key ) {
		$list    = self::all();
		$changed = false;
		foreach ( $list as $i => $incident ) {
			if ( $incident['key'] === $key && empty( $incident['closed_at'] ) ) {
				$list[ $i ]['closed_at'] = time();
				// A recovery notice only makes sense if someone was told about the problem.
				$list[ $i ]['alert_pending'] = $incident['last_alert_at'] ? 'recovered' : '';
				$changed                     = true;
			}
		}
		if ( $changed ) {
			self::save( $list );
		}
		return $changed;
	}

	/**
	 * Update fields of the open (or most recent) incident for a key.
	 *
	 * @param string $key       Key.
	 * @param int    $opened_at Opened timestamp identifying the incident.
	 * @param array  $fields    Fields to set.
	 */
	public static function update( $key, $opened_at, array $fields ) {
		$list = self::all();
		foreach ( $list as $i => $incident ) {
			if ( $incident['key'] === $key && (int) $incident['opened_at'] === (int) $opened_at ) {
				$list[ $i ] = array_merge( $incident, $fields );
				break;
			}
		}
		self::save( $list );
	}

	/**
	 * Persist, dropping old closed incidents and capping the list.
	 *
	 * @param array[] $list Incidents.
	 */
	private static function save( array $list ) {
		$cutoff = time() - self::KEEP_CLOSED;
		$list   = array_values(
			array_filter(
				$list,
				static function ( $incident ) use ( $cutoff ) {
					return empty( $incident['closed_at'] ) || $incident['closed_at'] >= $cutoff;
				}
			)
		);
		update_option( self::OPTION, array_slice( $list, 0, self::MAX_ENTRIES ), false );
	}
}
