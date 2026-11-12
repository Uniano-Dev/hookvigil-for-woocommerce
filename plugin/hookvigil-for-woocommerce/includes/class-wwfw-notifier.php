<?php
/**
 * Alert channels: email, Telegram, Slack.
 *
 * @package Hookvigil
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends plain-text alerts to the channels enabled in settings.
 */
class WWFW_Notifier {

	/**
	 * Plain-text alternative for the HTML email being sent.
	 *
	 * @var string
	 */
	private static $alt_body = '';

	/**
	 * Send a message to every enabled channel.
	 *
	 * @param string     $subject Short subject (email only).
	 * @param string     $text    Plain-text message.
	 * @param array|null $alert   Structured alert from WWFW_Alert; formatted per channel unless a filter customised the text.
	 * @return array Results keyed by channel: [ 'ok' => bool, 'message' => string ].
	 */
	public static function send( $subject, $text, $alert = null ) {
		$settings  = WWFW_Settings::all();
		$results   = array();
		$formatted = is_array( $alert ) && empty( $alert['custom'] );

		if ( $settings['email_enabled'] ) {
			$results['email'] = self::send_email( $settings['email_recipients'], $subject, $text, $formatted ? WWFW_Alert::email_html( $alert ) : '' );
		}
		if ( $settings['telegram_enabled'] ) {
			$results['telegram'] = $formatted
				? self::send_telegram( $settings['telegram_token'], $settings['telegram_chat_id'], WWFW_Alert::telegram_html( $alert ), true )
				: self::send_telegram( $settings['telegram_token'], $settings['telegram_chat_id'], $text );
		}
		if ( $settings['slack_enabled'] ) {
			$results['slack'] = self::send_slack( $settings['slack_webhook_url'], $formatted ? WWFW_Alert::slack_payload( $alert ) : array( 'text' => $text ) );
		}

		WWFW_Plugin::update_state(
			array(
				'last_alert_results' => array(
					'time'    => time(),
					'results' => $results,
				),
			)
		);

		return $results;
	}

	/**
	 * Email channel. With an HTML body the plain text goes along as the multipart alternative.
	 *
	 * @param string $recipients Comma-separated addresses.
	 * @param string $subject    Subject.
	 * @param string $text       Plain-text body.
	 * @param string $html       HTML body or ''.
	 * @return array
	 */
	private static function send_email( $recipients, $subject, $text, $html = '' ) {
		$to = array_filter( array_map( 'trim', explode( ',', (string) $recipients ) ) );
		if ( empty( $to ) ) {
			return self::result( false, __( 'No recipients configured.', 'hookvigil-for-woocommerce' ) );
		}
		if ( '' === $html ) {
			$sent = wp_mail( $to, $subject, $text );
		} else {
			self::$alt_body = $text;
			add_action( 'phpmailer_init', array( __CLASS__, 'set_alt_body' ) );
			$sent = wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
			remove_action( 'phpmailer_init', array( __CLASS__, 'set_alt_body' ) );
			self::$alt_body = '';
		}
		return $sent
			? self::result( true, __( 'Passed to the site mail system.','hookvigil-for-woocommerce' ) )
			: self::result( false, __( 'The site could not send the email. Check its mail setup; many hosts need an SMTP plugin.', 'hookvigil-for-woocommerce' ) );
	}

	/**
	 * Add the plain-text alternative to the outgoing HTML email (`phpmailer_init`, one message only).
	 *
	 * @param object $phpmailer PHPMailer instance.
	 */
	public static function set_alt_body( $phpmailer ) {
		if ( '' !== self::$alt_body ) {
			$phpmailer->AltBody = self::$alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
		}
	}

	/**
	 * Telegram channel.
	 *
	 * @param string $token   Bot token.
	 * @param string $chat_id Chat ID.
	 * @param string $text    Message.
	 * @param bool   $html    Whether $text uses Telegram HTML formatting.
	 * @return array
	 */
	private static function send_telegram( $token, $chat_id, $text, $html = false ) {
		if ( '' === $token || '' === $chat_id ) {
			return self::result( false, __( 'Bot token or chat ID missing.', 'hookvigil-for-woocommerce' ) );
		}
		$body = array(
			'chat_id'                  => $chat_id,
			'text'                     => $text,
			'disable_web_page_preview' => 'true',
		);
		if ( $html ) {
			$body['parse_mode'] = 'HTML';
		}
		$response = wp_remote_post(
			// The token is limited to [0-9A-Za-z:_-] by WWFW_Settings::sanitize().
			'https://api.telegram.org/bot' . $token . '/sendMessage',
			array(
				'timeout' => 10,
				'body'    => $body,
			)
		);
		return self::http_result( $response, 'Telegram' );
	}

	/**
	 * Slack channel.
	 *
	 * @param string $url     Incoming webhook URL.
	 * @param array  $payload Message payload (`text`, optional `blocks`).
	 * @return array
	 */
	private static function send_slack( $url, array $payload ) {
		if ( '' === $url ) {
			return self::result( false, __( 'Incoming webhook URL missing.', 'hookvigil-for-woocommerce' ) );
		}
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
		return self::http_result( $response, 'Slack' );
	}

	/**
	 * Convert an HTTP response to a result without exposing URLs or tokens.
	 *
	 * @param array|WP_Error $response Response.
	 * @param string         $service  Service name (not translated).
	 * @return array
	 */
	private static function http_result( $response, $service ) {
		if ( is_wp_error( $response ) ) {
			/* translators: 1: service name, 2: error code */
			return self::result( false, sprintf( __( 'Could not reach %1$s (%2$s).', 'hookvigil-for-woocommerce' ), $service, $response->get_error_code() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			/* translators: %s: service name */
			return self::result( true, sprintf( __( 'Accepted by %s.', 'hookvigil-for-woocommerce' ), $service ) );
		}
		/* translators: 1: service name, 2: HTTP status code */
		return self::result( false, sprintf( __( '%1$s refused the message (HTTP %2$d). Check the token, chat ID or webhook URL.', 'hookvigil-for-woocommerce' ), $service, $code ) );
	}

	/**
	 * Result tuple.
	 *
	 * @param bool   $ok      Success.
	 * @param string $message Message.
	 * @return array
	 */
	private static function result( $ok, $message ) {
		return array(
			'ok'      => (bool) $ok,
			'message' => (string) $message,
		);
	}
}
