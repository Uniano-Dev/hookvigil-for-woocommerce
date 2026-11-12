<?php
/**
 * Settings storage, defaults and sanitising.
 *
 * @package Hookvigil
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the `wwfw_settings` option.
 */
class WWFW_Settings {

	const OPTION = 'wwfw_settings';

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'email_enabled'     => true,
			'email_recipients'  => (string) get_option( 'admin_email' ),
			'telegram_enabled'  => false,
			'telegram_token'    => '',
			'telegram_chat_id'  => '',
			'slack_enabled'     => false,
			'slack_webhook_url' => '',
			'backlog_minutes'   => 15,
			'reminder_hours'    => 24,
			'retention_days'    => 7,
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Messages from the last sanitize() call: [ code => [ 'type' => error|warning, 'message' => string ] ].
	 *
	 * @var array
	 */
	private static $notes = array();

	/**
	 * Sanitise raw input from the settings form. Secret fields left empty keep their stored value,
	 * because the form never echoes secrets back. Invalid input never replaces a working value; every
	 * rejected or incomplete field is reported on the settings screen.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input       = is_array( $input ) ? $input : array();
		$current     = self::all();
		$out         = array();
		self::$notes = array();

		$out['email_enabled']    = ! empty( $input['email_enabled'] );
		$out['telegram_enabled'] = ! empty( $input['telegram_enabled'] );
		$out['slack_enabled']    = ! empty( $input['slack_enabled'] );

		// Email recipients.
		$raw     = trim( (string) ( isset( $input['email_recipients'] ) ? $input['email_recipients'] : '' ) );
		$emails  = array();
		$invalid = array();
		foreach ( preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $candidate ) {
			$email = sanitize_email( $candidate );
			if ( $email && is_email( $email ) ) {
				$emails[] = $email;
			} else {
				$invalid[] = sanitize_text_field( $candidate );
			}
		}
		$out['email_recipients'] = implode( ', ', array_unique( $emails ) );
		if ( $invalid ) {
			self::note(
				'email_invalid',
				'error',
				/* translators: %s: comma-separated list of rejected addresses */
				sprintf( _n( 'This address was not saved because it is not a valid email address: %s', 'These addresses were not saved because they are not valid email addresses: %s', count( $invalid ), 'hookvigil-for-woocommerce' ), implode( ', ', $invalid ) )
			);
		}
		if ( '' === $out['email_recipients'] && ( '' !== $raw || $out['email_enabled'] ) ) {
			if ( '' !== $current['email_recipients'] ) {
				$out['email_recipients'] = $current['email_recipients'];
				self::note(
					'email_kept',
					'error',
					/* translators: %s: previously saved recipients */
					sprintf( __( 'Email alerts need at least one valid recipient, so the previous recipients were kept: %s', 'hookvigil-for-woocommerce' ), $current['email_recipients'] )
				);
			} elseif ( $out['email_enabled'] ) {
				self::note( 'email_none', 'warning', __( 'Email alerts are on, but there is no recipient. No email alerts will be sent until you add one.', 'hookvigil-for-woocommerce' ) );
			}
		}

		// Telegram.
		$token = isset( $input['telegram_token'] ) ? trim( sanitize_text_field( $input['telegram_token'] ) ) : '';
		if ( '' === $token ) {
			$out['telegram_token'] = $current['telegram_token'];
		} elseif ( preg_match( '/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $token ) ) {
			$out['telegram_token'] = $token;
		} else {
			$out['telegram_token'] = $current['telegram_token'];
			self::note( 'telegram_token', 'error', __( 'The Telegram bot token was not saved. It looks like 123456789:AAE… and is shown by @BotFather when you create the bot.', 'hookvigil-for-woocommerce' ) );
		}
		$chat = isset( $input['telegram_chat_id'] ) ? trim( sanitize_text_field( $input['telegram_chat_id'] ) ) : '';
		if ( '' === $chat || preg_match( '/^(-?\d{1,20}|@[A-Za-z][A-Za-z0-9_]{3,31})$/', $chat ) ) {
			$out['telegram_chat_id'] = $chat;
		} else {
			$out['telegram_chat_id'] = $current['telegram_chat_id'];
			self::note( 'telegram_chat', 'error', __( 'The Telegram chat ID was not saved. Use the numeric ID (for example 123456789 or -1001234567890) or a public channel name such as @shop_alerts.', 'hookvigil-for-woocommerce' ) );
		}
		if ( ! empty( $input['telegram_clear'] ) ) {
			$out['telegram_token'] = '';
		}
		if ( $out['telegram_enabled'] && ( '' === $out['telegram_token'] || '' === $out['telegram_chat_id'] ) ) {
			self::note( 'telegram_incomplete', 'warning', __( 'Telegram alerts are on, but they need both a bot token and a chat ID. Telegram alerts will not be sent until both are filled in.', 'hookvigil-for-woocommerce' ) );
		}

		// Slack.
		$raw_url = isset( $input['slack_webhook_url'] ) ? trim( sanitize_text_field( $input['slack_webhook_url'] ) ) : '';
		$slack   = ( '' === $raw_url ) ? '' : esc_url_raw( $raw_url, array( 'https' ) );
		if ( '' === $raw_url ) {
			$out['slack_webhook_url'] = $current['slack_webhook_url'];
		} elseif ( '' === $slack || 0 !== strpos( $slack, 'https://' ) ) {
			$out['slack_webhook_url'] = $current['slack_webhook_url'];
			self::note( 'slack_url', 'error', __( 'The Slack webhook URL was not saved. It must be an https:// address created under Incoming Webhooks in your Slack app.', 'hookvigil-for-woocommerce' ) );
		} else {
			$out['slack_webhook_url'] = $slack;
			if ( 'hooks.slack.com' !== wp_parse_url( $slack, PHP_URL_HOST ) ) {
				self::note( 'slack_host', 'warning', __( 'The Slack webhook URL was saved, but it is not a hooks.slack.com address. Make sure the receiver accepts Slack-formatted messages.', 'hookvigil-for-woocommerce' ) );
			}
		}
		if ( ! empty( $input['slack_clear'] ) ) {
			$out['slack_webhook_url'] = '';
		}
		if ( $out['slack_enabled'] && '' === $out['slack_webhook_url'] ) {
			self::note( 'slack_incomplete', 'warning', __( 'Slack alerts are on, but there is no webhook URL. Slack alerts will not be sent until you add one.', 'hookvigil-for-woocommerce' ) );
		}

		if ( ! $out['email_enabled'] && ! $out['telegram_enabled'] && ! $out['slack_enabled'] ) {
			self::note( 'no_channels', 'warning', __( 'All alert channels are off. Webhook problems will only be visible here in WordPress admin.', 'hookvigil-for-woocommerce' ) );
		}

		$out['backlog_minutes'] = self::clamp( isset( $input['backlog_minutes'] ) ? $input['backlog_minutes'] : 15, 5, 240 );
		$out['reminder_hours']  = self::clamp( isset( $input['reminder_hours'] ) ? $input['reminder_hours'] : 24, 1, 168 );
		$out['retention_days']  = self::clamp( isset( $input['retention_days'] ) ? $input['retention_days'] : 7, 1, 30 );

		self::report();
		return $out;
	}

	/**
	 * Messages produced by the last sanitize() call.
	 *
	 * @return array
	 */
	public static function notes() {
		return self::$notes;
	}

	/**
	 * Record a validation message.
	 *
	 * @param string $code    Message code.
	 * @param string $type    error|warning.
	 * @param string $message Message.
	 */
	private static function note( $code, $type, $message ) {
		self::$notes[ $code ] = array(
			'type'    => $type,
			'message' => $message,
		);
	}

	/**
	 * Pass the messages to the Settings API once per request (WordPress runs the sanitize callback
	 * twice when the option is saved for the first time).
	 */
	private static function report() {
		static $reported = false;
		if ( $reported || ! self::$notes || ! function_exists( 'add_settings_error' ) ) {
			return;
		}
		$reported   = true;
		$has_errors = in_array( 'error', wp_list_pluck( self::$notes, 'type' ), true );
		// With any message present core skips its own "Settings saved." notice.
		add_settings_error(
			self::OPTION,
			'wwfw_saved',
			$has_errors ? __( 'Other settings were saved.', 'hookvigil-for-woocommerce' ) : __( 'Settings saved.', 'hookvigil-for-woocommerce' ),
			'success'
		);
		foreach ( self::$notes as $code => $note ) {
			add_settings_error( self::OPTION, 'wwfw_' . $code, $note['message'], $note['type'] );
		}
	}

	/**
	 * Mask a secret for display, keeping the last 4 characters.
	 *
	 * @param string $secret Secret.
	 * @return string
	 */
	public static function mask( $secret ) {
		$secret = (string) $secret;
		if ( '' === $secret ) {
			return '';
		}
		return str_repeat( '•', 8 ) . substr( $secret, -4 );
	}

	/**
	 * Clamp an integer.
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 * @return int
	 */
	private static function clamp( $value, $min, $max ) {
		return max( $min, min( $max, absint( $value ) ) );
	}
}
