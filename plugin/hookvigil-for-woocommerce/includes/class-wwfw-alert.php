<?php
/**
 * Alert content and per-channel formatting.
 *
 * @package Hookvigil
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds one structured alert and renders it as plain text, HTML email, Telegram HTML or Slack blocks.
 *
 * An alert is an array: subject, title, tone (danger|warn|ok|info), intro, rows (list of [label, value]),
 * note, button ([label, url]), text (plain text after the `wwfw_alert_message` filter) and custom
 * (true when a filter changed the text; channels then send that text only).
 */
class WWFW_Alert {

	/**
	 * Alert for an incident event.
	 *
	 * @param array  $incident Incident.
	 * @param string $event    open|reminder|recovered.
	 * @return array
	 */
	public static function for_incident( array $incident, $event ) {
		$store = self::store_name();

		if ( 'queue_backlog' === $incident['type'] ) {
			$parts   = explode( '|', (string) $incident['details'] );
			$count   = isset( $parts[0] ) ? (int) $parts[0] : 0;
			$minutes = isset( $parts[1] ) ? (int) $parts[1] : 0;
			$health  = WWFW_Admin::url();
			if ( 'recovered' === $event ) {
				$alert = array(
					/* translators: %s: store name */
					'subject' => sprintf( __( '[%s] Webhook deliveries are flowing again', 'hookvigil-for-woocommerce' ), $store ),
					'title'   => __( 'Deliveries are flowing again', 'hookvigil-for-woocommerce' ),
					'tone'    => 'ok',
					'intro'   => __( 'The background queue is processing webhook deliveries again.', 'hookvigil-for-woocommerce' ),
				);
			} else {
				$alert = array(
					/* translators: 1: store name, 2: number of deliveries, 3: minutes */
					'subject' => sprintf( __( '[%1$s] %2$d webhook deliveries are overdue (oldest %3$d min)', 'hookvigil-for-woocommerce' ), $store, $count, $minutes ),
					'title'   => __( 'Webhook deliveries are overdue', 'hookvigil-for-woocommerce' ),
					'tone'    => 'warn',
					'intro'   => __( 'The background queue is not processing webhook deliveries. This often means WP-Cron is not running.', 'hookvigil-for-woocommerce' ),
					'rows'    => array(
						array( __( 'Overdue deliveries', 'hookvigil-for-woocommerce' ), number_format_i18n( $count ) ),
						/* translators: %d: minutes */
						array( __( 'Oldest waiting', 'hookvigil-for-woocommerce' ), sprintf( _n( '%d minute', '%d minutes', $minutes, 'hookvigil-for-woocommerce' ), $minutes ) ),
					),
				);
			}
			$alert['button'] = array( __( 'Open Hookvigil', 'hookvigil-for-woocommerce' ), $health );
			return self::finish( $alert, $incident, $event );
		}

		$webhook_id = (int) $incident['webhook_id'];
		$name       = '' !== $incident['name'] ? $incident['name'] : '#' . $webhook_id;
		$host       = '';
		$hook       = wc_get_webhook( $webhook_id );
		if ( $hook ) {
			$host = (string) wp_parse_url( $hook->get_delivery_url(), PHP_URL_HOST );
		}
		$details = WWFW_Admin::url( array( 'webhook' => $webhook_id ) );
		$button  = array( __( 'Open webhook details', 'hookvigil-for-woocommerce' ), $details );
		$since   = self::format_time( (int) $incident['opened_at'] ) . ( $incident['opened_approx'] ? ' ' . __( '(approx.)', 'hookvigil-for-woocommerce' ) : '' );
		$missed  = self::missed_row( $incident );

		if ( 'recovered' === $event ) {
			$rows = array(
				array( __( 'Webhook', 'hookvigil-for-woocommerce' ), $name ),
				array( __( 'Delivers to', 'hookvigil-for-woocommerce' ), $host ),
				/* translators: 1: start time, 2: end time */
				array( __( 'Outage', 'hookvigil-for-woocommerce' ), sprintf( __( '%1$s to %2$s', 'hookvigil-for-woocommerce' ), $since, self::format_time( (int) $incident['closed_at'] ) ) ),
			);
			if ( $missed ) {
				$rows[] = array( __( 'Orders not delivered during the outage', 'hookvigil-for-woocommerce' ), $missed );
			}
			$alert = array(
				/* translators: 1: store name, 2: webhook name */
				'subject' => sprintf( __( '[%1$s] Webhook "%2$s" is active again', 'hookvigil-for-woocommerce' ), $store, $name ),
				'title'   => __( 'Webhook active again', 'hookvigil-for-woocommerce' ),
				'tone'    => 'ok',
				/* translators: %s: webhook name */
				'intro'   => sprintf( __( 'The webhook "%s" is active again. New events are delivered from now on.', 'hookvigil-for-woocommerce' ), $name ),
				'rows'    => $rows,
				'note'    => $missed ? __( 'Events from the outage were not sent again. The webhook page lists the affected orders and offers a CSV.', 'hookvigil-for-woocommerce' ) : '',
				'button'  => $button,
			);
			return self::finish( $alert, $incident, $event );
		}

		$stats  = WWFW_Delivery_Log::stats( $webhook_id );
		$prefix = ( 'reminder' === $event ) ? __( 'Still disabled', 'hookvigil-for-woocommerce' ) : __( 'Webhook disabled', 'hookvigil-for-woocommerce' );
		$rows   = array(
			array( __( 'Webhook', 'hookvigil-for-woocommerce' ), $name ),
			array( __( 'Topic', 'hookvigil-for-woocommerce' ), $incident['topic'] ),
			array( __( 'Delivers to', 'hookvigil-for-woocommerce' ), $host ),
		);
		if ( '' !== $stats['last_error'] ) {
			$rows[] = array( __( 'Last error', 'hookvigil-for-woocommerce' ), self::describe_error( $stats['last_error'] ) );
		}
		$rows[] = array( __( 'Disabled at', 'hookvigil-for-woocommerce' ), $since );
		if ( $missed ) {
			$rows[] = array( __( 'Orders not delivered so far', 'hookvigil-for-woocommerce' ), $missed );
		}
		$alert = array(
			/* translators: 1: store name, 2: "Webhook disabled" or "Still disabled", 3: webhook name, 4: topic, 5: host */
			'subject' => sprintf( __( '[%1$s] %2$s: "%3$s" (%4$s) → %5$s', 'hookvigil-for-woocommerce' ), $store, $prefix, $name, $incident['topic'], $host ),
			'title'   => ( 'reminder' === $event ) ? __( 'Webhook still disabled', 'hookvigil-for-woocommerce' ) : __( 'Webhook disabled', 'hookvigil-for-woocommerce' ),
			'tone'    => 'danger',
			/* translators: %s: webhook name */
			'intro'   => sprintf( __( 'The webhook "%s" is disabled, so WooCommerce has stopped sending it events.', 'hookvigil-for-woocommerce' ), $name ),
			'rows'    => $rows,
			'note'    => __( 'WooCommerce does not retry: events are skipped until the webhook is re-enabled. Fix the receiving system first, then use "Re-enable and reset".', 'hookvigil-for-woocommerce' ),
			'button'  => $button,
		);
		return self::finish( $alert, $incident, $event );
	}

	/**
	 * Test alert.
	 *
	 * @return array
	 */
	public static function test() {
		$store = self::store_name();
		return self::finish(
			array(
				/* translators: %s: store name */
				'subject' => sprintf( __( '[%s] Hookvigil test alert', 'hookvigil-for-woocommerce' ), $store ),
				'title'   => __( 'Test alert', 'hookvigil-for-woocommerce' ),
				'tone'    => 'info',
				/* translators: %s: store name */
				'intro'   => sprintf( __( 'If you can read this, alerts from %s reach you.', 'hookvigil-for-woocommerce' ), $store ),
				'rows'    => array(
					array( __( 'Sent at', 'hookvigil-for-woocommerce' ), self::format_time( time() ) ),
				),
				'button'  => array( __( 'Open alert settings', 'hookvigil-for-woocommerce' ), WWFW_Admin::url( array( 'tab' => 'settings' ) ) ),
			),
			null,
			'test'
		);
	}

	/**
	 * Human-readable last error: "HTTP 503 Service Unavailable" or "Connection failed (code)".
	 *
	 * @param string $code HTTP status or WP_Error code as stored in the delivery log.
	 * @return string
	 */
	public static function describe_error( $code ) {
		$code = (string) $code;
		if ( '' === $code ) {
			return '';
		}
		if ( ctype_digit( $code ) ) {
			return trim( 'HTTP ' . $code . ' ' . get_status_header_desc( (int) $code ) );
		}
		/* translators: %s: error code such as http_request_failed */
		return sprintf( __( 'Connection failed (%s)', 'hookvigil-for-woocommerce' ), $code );
	}

	/**
	 * Date and time in the site's format and time zone, with the zone name.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function format_time( $timestamp ) {
		$date   = (string) get_option( 'date_format' );
		$time   = (string) get_option( 'time_format' );
		$format = ( '' !== $date ? $date : 'Y-m-d' ) . ' ' . ( '' !== $time ? $time : 'H:i' );
		$zone   = wp_timezone_string();
		if ( '+00:00' === $zone || 'UTC' === $zone ) {
			$zone = 'UTC';
		} elseif ( preg_match( '/^[+-]\d{2}:\d{2}$/', $zone ) ) {
			$zone = 'UTC' . $zone;
		}
		return wp_date( $format, (int) $timestamp ) . ' (' . $zone . ')';
	}

	/**
	 * HTML email body. Inline styles only; works in common mail clients.
	 *
	 * @param array $alert Alert.
	 * @return string
	 */
	public static function email_html( array $alert ) {
		$tones = array(
			'danger' => array( '#F87171', '#FDECEC' ),
			'warn'   => array( '#F2B554', '#FDF3E1' ),
			'ok'     => array( '#02C076', '#E6F6EF' ),
			'info'   => array( '#38BDF8', '#E7F5FC' ),
		);
		$tone  = isset( $tones[ $alert['tone'] ] ) ? $tones[ $alert['tone'] ] : $tones['info'];
		$font  = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
		$store = self::store_name();

		$rows = '';
		foreach ( $alert['rows'] as $row ) {
			$rows .= '<tr><td style="padding:9px 0;border-top:1px solid #E6EAF0;color:#5B6573;font-size:13px;line-height:20px;width:42%;vertical-align:top;">' . esc_html( $row[0] ) . '</td>'
				. '<td style="padding:9px 0;border-top:1px solid #E6EAF0;color:#14181D;font-size:14px;line-height:20px;vertical-align:top;">' . esc_html( $row[1] ) . '</td></tr>';
		}

		$html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $alert['subject'] ) . '</title></head>';
		$html .= '<body style="margin:0;padding:0;background:#F5F7FA;">';
		$html .= '<div style="display:none;max-height:0;overflow:hidden;">' . esc_html( $alert['intro'] ) . '</div>';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F7FA;font-family:' . esc_attr( $font ) . ';"><tr><td align="center" style="padding:32px 16px;">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">';

		// Header: product name with the green signal dot, store on the right.
		$html .= '<tr><td style="padding:0 4px 14px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
			. '<td style="font-size:14px;font-weight:600;color:#14181D;"><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#02C076;margin-right:8px;vertical-align:middle;"></span>' . esc_html__( 'Hookvigil', 'hookvigil-for-woocommerce' ) . '</td>'
			. '<td align="right" style="font-size:13px;color:#5B6573;">' . esc_html( $store ) . '</td>'
			. '</tr></table></td></tr>';

		// Card.
		$html .= '<tr><td style="background:#FFFFFF;border:1px solid #E3E7EE;border-radius:12px;padding:28px 28px 24px;">';
		$html .= '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:' . esc_attr( $tone[1] ) . ';color:#14181D;font-size:12px;font-weight:600;">'
			. '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . esc_attr( $tone[0] ) . ';margin-right:6px;vertical-align:middle;"></span>' . esc_html( $alert['title'] ) . '</span>';
		$html .= '<h1 style="margin:16px 0 8px;font-size:20px;line-height:1.35;color:#14181D;font-weight:700;">' . esc_html( $alert['intro'] ) . '</h1>';
		if ( '' !== $rows ) {
			$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;">' . $rows . '</table>';
		}
		if ( '' !== $alert['note'] ) {
			$html .= '<p style="margin:20px 0 0;padding:12px 14px;background:#F5F7FA;border-radius:8px;color:#14181D;font-size:13px;line-height:1.5;">' . esc_html( $alert['note'] ) . '</p>';
		}
		if ( $alert['button'] ) {
			$html .= '<p style="margin:24px 0 0;"><a href="' . esc_url( $alert['button'][1] ) . '" style="display:inline-block;padding:11px 20px;background:#02C076;color:#0B0E11;text-decoration:none;font-weight:600;font-size:14px;border-radius:8px;">' . esc_html( $alert['button'][0] ) . '</a></p>';
		}
		$html .= '</td></tr>';

		// Footer.
		$html .= '<tr><td style="padding:16px 4px 0;font-size:12px;line-height:1.5;color:#5B6573;">'
			. esc_html__( 'Sent by Hookvigil for WooCommerce, by Uniano.', 'hookvigil-for-woocommerce' ) . ' '
			. '<a href="' . esc_url( WWFW_Admin::url( array( 'tab' => 'settings' ) ) ) . '" style="color:#007A55;">' . esc_html__( 'Alert settings', 'hookvigil-for-woocommerce' ) . '</a></td></tr>';

		$html .= '</table></td></tr></table></body></html>';
		return $html;
	}

	/**
	 * Telegram message with parse_mode=HTML.
	 *
	 * @param array $alert Alert.
	 * @return string
	 */
	public static function telegram_html( array $alert ) {
		$lines = array( self::marker( $alert['tone'] ) . ' <b>' . self::tg( $alert['title'] ) . '</b> · ' . self::tg( self::store_name() ), self::tg( $alert['intro'] ), '' );
		foreach ( $alert['rows'] as $row ) {
			$lines[] = '<b>' . self::tg( $row[0] ) . ':</b> ' . self::tg( $row[1] );
		}
		if ( '' !== $alert['note'] ) {
			$lines[] = '';
			$lines[] = '<i>' . self::tg( $alert['note'] ) . '</i>';
		}
		if ( $alert['button'] ) {
			$lines[] = '';
			$lines[] = '<a href="' . self::tg( $alert['button'][1] ) . '">' . self::tg( $alert['button'][0] ) . '</a>';
		}
		return implode( "\n", $lines );
	}

	/**
	 * Slack incoming-webhook payload (mrkdwn blocks with a plain-text fallback).
	 *
	 * @param array $alert Alert.
	 * @return array
	 */
	public static function slack_payload( array $alert ) {
		$icons  = array(
			'danger' => ':red_circle:',
			'warn'   => ':large_orange_circle:',
			'ok'     => ':large_green_circle:',
			'info'   => ':large_blue_circle:',
		);
		$icon   = isset( $icons[ $alert['tone'] ] ) ? $icons[ $alert['tone'] ] : $icons['info'];
		$blocks = array(
			array(
				'type' => 'section',
				'text' => array(
					'type' => 'mrkdwn',
					'text' => $icon . ' *' . self::sl( $alert['title'] ) . '* · ' . self::sl( self::store_name() ) . "\n" . self::sl( $alert['intro'] ),
				),
			),
		);
		$fields = array();
		foreach ( array_slice( $alert['rows'], 0, 10 ) as $row ) {
			$fields[] = array(
				'type' => 'mrkdwn',
				'text' => '*' . self::sl( $row[0] ) . "*\n" . self::sl( $row[1] ),
			);
		}
		if ( $fields ) {
			$blocks[] = array(
				'type'   => 'section',
				'fields' => $fields,
			);
		}
		$footer = array();
		if ( '' !== $alert['note'] ) {
			$footer[] = self::sl( $alert['note'] );
		}
		if ( $alert['button'] ) {
			$footer[] = '<' . esc_url_raw( $alert['button'][1] ) . '|' . self::sl( $alert['button'][0] ) . '>';
		}
		if ( $footer ) {
			$blocks[] = array(
				'type' => 'section',
				'text' => array(
					'type' => 'mrkdwn',
					'text' => implode( "\n", $footer ),
				),
			);
		}
		$blocks[] = array(
			'type'     => 'context',
			'elements' => array(
				array(
					'type' => 'mrkdwn',
					'text' => self::sl( __( 'Hookvigil for WooCommerce', 'hookvigil-for-woocommerce' ) ),
				),
			),
		);
		return array(
			'text'   => $alert['subject'],
			'blocks' => $blocks,
		);
	}

	/**
	 * Fill defaults, build the plain text and apply the `wwfw_alert_message` filter.
	 *
	 * @param array      $alert    Partial alert.
	 * @param array|null $incident Incident (null for the test alert).
	 * @param string     $event    Event.
	 * @return array
	 */
	private static function finish( array $alert, $incident, $event ) {
		$alert = array_merge(
			array(
				'subject' => '',
				'title'   => '',
				'tone'    => 'info',
				'intro'   => '',
				'rows'    => array(),
				'note'    => '',
				'button'  => array(),
			),
			$alert
		);

		$lines = array( $alert['subject'], $alert['intro'] );
		foreach ( $alert['rows'] as $row ) {
			$lines[] = $row[0] . ': ' . $row[1];
		}
		if ( '' !== $alert['note'] ) {
			$lines[] = $alert['note'];
		}
		if ( $alert['button'] ) {
			$lines[] = $alert['button'][0] . ': ' . $alert['button'][1];
		}
		$text = implode( "\n", $lines );

		if ( null !== $incident ) {
			/**
			 * Filters the alert text. When a callback changes it, every channel sends the filtered
			 * plain text instead of its formatted version.
			 *
			 * @param string $text     Message text.
			 * @param array  $incident Incident.
			 * @param string $event    open|reminder|recovered.
			 */
			$filtered = (string) apply_filters( 'wwfw_alert_message', $text, $incident, $event );
		} else {
			$filtered = $text;
		}
		$alert['text']   = $filtered;
		$alert['custom'] = ( $filtered !== $text );
		return $alert;
	}

	/**
	 * "N" or "1,000+" orders missed for order topics; '' for other topics.
	 *
	 * @param array $incident Incident.
	 * @return string
	 */
	private static function missed_row( array $incident ) {
		if ( ! WWFW_Missed_Orders::supports( $incident['topic'] ) ) {
			return '';
		}
		$count = count( WWFW_Missed_Orders::order_ids( $incident ) );
		return number_format_i18n( $count ) . ( $count >= WWFW_Missed_Orders::LIMIT ? '+' : '' );
	}

	/**
	 * Status marker for Telegram.
	 *
	 * @param string $tone Tone.
	 * @return string
	 */
	private static function marker( $tone ) {
		$markers = array(
			'danger' => '🔴',
			'warn'   => '🟠',
			'ok'     => '🟢',
			'info'   => '🔵',
		);
		return isset( $markers[ $tone ] ) ? $markers[ $tone ] : $markers['info'];
	}

	/**
	 * Escape for Telegram HTML.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function tg( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Escape for Slack mrkdwn.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function sl( $text ) {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), (string) $text );
	}

	/**
	 * Store name without HTML entities.
	 *
	 * @return string
	 */
	private static function store_name() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
