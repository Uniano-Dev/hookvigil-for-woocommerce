<?php
/**
 * 0.2.0 US2 + US3: formatted alerts, site time, responsive Health screen.
 */

t_section( '0.2.0 formatted alerts and Health markup' );
t_enable_all_channels();

// Capture whether the plain-text alternative hook is active while wp_mail runs.
$GLOBALS['wwfw_alt_hook'] = array();
add_filter(
	'pre_wp_mail',
	static function ( $pre ) {
		$GLOBALS['wwfw_alt_hook'][] = (bool) has_action( 'phpmailer_init', array( 'WWFW_Notifier', 'set_alt_body' ) );
		return $pre;
	},
	5
);

$first      = t_make_order();
$webhook_id = t_make_webhook( 'ERP order import', 'order.created', 'failing-receiver.example.com' );
t_fail_until_disabled( $webhook_id, $first );
sleep( 1 );
t_make_order();
t_make_order();
t_reset_capture();
WWFW_Monitor::dispatch_alerts();

$mail = $GLOBALS['wwfw_t']['mails'] ? $GLOBALS['wwfw_t']['mails'][0] : array( 'message' => '', 'headers' => array() );
$http = $GLOBALS['wwfw_t']['http'];
t_assert( false !== strpos( implode( "\n", (array) $mail['headers'] ), 'text/html' ), 'email is sent as HTML' );
t_assert( false !== strpos( $mail['message'], '<!DOCTYPE html>' ) && false !== strpos( $mail['message'], 'HTTP 500 Internal Server Error' ), 'HTML email shows the readable last error' );
t_assert( false !== strpos( $mail['message'], 'Open webhook details' ) && false !== strpos( $mail['message'], 'webhook=' . $webhook_id ), 'button links to the webhook page' );
t_assert( false !== strpos( $mail['message'], '#02C076' ), 'email uses the brand accent' );
t_assert( array( true ) === array_slice( $GLOBALS['wwfw_alt_hook'], -1 ) && ! has_action( 'phpmailer_init', array( 'WWFW_Notifier', 'set_alt_body' ) ), 'plain-text alternative hook is active only during the send' );

$alert = WWFW_Alert::for_incident( WWFW_Incidents::find_open( 'webhook_disabled:' . $webhook_id ), 'reminder' );
t_assert( false !== strpos( $alert['text'], 'Orders not delivered so far: 2' ), 'alert counts orders missed so far' );
t_assert( false !== strpos( $alert['text'], 'Last error: HTTP 500 Internal Server Error' ), 'plain text carries the readable error' );

$telegram = null;
$slack    = null;
foreach ( $http as $call ) {
	if ( 'api.telegram.org' === $call['host'] ) {
		$telegram = $call['body'];
	} else {
		$slack = json_decode( $call['body'], true );
	}
}
t_assert( $telegram && 'HTML' === $telegram['parse_mode'] && false !== strpos( $telegram['text'], '<b>Webhook disabled</b>' ) && false !== strpos( $telegram['text'], '<a href="' ), 'Telegram gets HTML formatting with a link' );
t_assert( $slack && ! empty( $slack['blocks'] ) && false !== strpos( wp_json_encode( $slack['blocks'] ), '|Open webhook details>' ), 'Slack gets blocks with a mrkdwn link' );
$all = wp_json_encode( array( $mail, wp_list_pluck( $http, 'body' ) ) );
t_assert( false === strpos( $all, 'SECRET-TOKEN' ) && false === strpos( $all, 'SECRETPATH' ) && false === strpos( $all, 'test-secret' ), 'no secrets in formatted alerts' );

// A customised text wins on every channel.
$custom = static function () {
	return 'Custom wording from the shop';
};
add_filter( 'wwfw_alert_message', $custom );
$incident = WWFW_Incidents::find_open( 'webhook_disabled:' . $webhook_id );
WWFW_Incidents::update( $incident['key'], $incident['opened_at'], array( 'last_alert_at' => time() - 25 * HOUR_IN_SECONDS ) );
t_reset_capture();
WWFW_Monitor::dispatch_alerts();
remove_filter( 'wwfw_alert_message', $custom );
$mail = $GLOBALS['wwfw_t']['mails'] ? $GLOBALS['wwfw_t']['mails'][0] : array( 'message' => '', 'headers' => array() );
t_assert( 'Custom wording from the shop' === $mail['message'] && false === strpos( implode( "\n", (array) $mail['headers'] ), 'text/html' ), 'filtered text is sent as plain email' );
$custom_ok = true;
foreach ( $GLOBALS['wwfw_t']['http'] as $call ) {
	if ( 'api.telegram.org' === $call['host'] ) {
		$custom_ok = $custom_ok && ! isset( $call['body']['parse_mode'] ) && 'Custom wording from the shop' === $call['body']['text'];
	} else {
		$payload   = json_decode( $call['body'], true );
		$custom_ok = $custom_ok && empty( $payload['blocks'] ) && 'Custom wording from the shop' === $payload['text'];
	}
}
t_assert( $custom_ok && 2 === count( $GLOBALS['wwfw_t']['http'] ), 'filtered text is sent unformatted to Telegram and Slack' );

// Plain-text alternative setter.
$prop = new ReflectionProperty( 'WWFW_Notifier', 'alt_body' );
$prop->setAccessible( true );
$prop->setValue( null, 'plain alternative' );
$fake = new stdClass();
WWFW_Notifier::set_alt_body( $fake );
$prop->setValue( null, '' );
t_assert( isset( $fake->AltBody ) && 'plain alternative' === $fake->AltBody, 'plain-text alternative is attached to the mailer' );

// Errors and time.
t_assert( 'HTTP 503 Service Unavailable' === WWFW_Alert::describe_error( '503' ), 'HTTP status described' );
t_assert( 0 === strpos( WWFW_Alert::describe_error( 'http_request_failed' ), 'Connection failed' ), 'transport error described' );
update_option( 'timezone_string', 'Europe/Berlin' );
t_assert( false !== strpos( WWFW_Alert::format_time( time() ), '(Europe/Berlin)' ), 'site time zone name shown' );
update_option( 'timezone_string', '' );
update_option( 'gmt_offset', 2 );
t_assert( false !== strpos( WWFW_Alert::format_time( time() ), '(UTC+02:00)' ), 'UTC offset shown as UTC+hh:mm' );
update_option( 'gmt_offset', 0 );
t_assert( false !== strpos( WWFW_Alert::format_time( time() ), '(UTC)' ), 'UTC shown as UTC' );

$test = WWFW_Alert::test();
t_assert( false !== strpos( $test['subject'], 'test alert' ) && 'info' === $test['tone'], 'test alert is formatted' );

// Health and details markup.
t_login_admin();
$_GET = array( 'page' => 'wwfw' );
ob_start();
WWFW_Admin::render();
$html = ob_get_clean();
t_assert( false !== strpos( $html, 'wp-list-table' ) && false !== strpos( $html, 'column-primary' ) && false !== strpos( $html, 'toggle-row' ) && false !== strpos( $html, 'data-colname="Delivers to"' ), 'Health uses responsive list-table markup' );
t_assert( false !== strpos( $html, 'wwfw-status-disabled' ) && false !== strpos( $html, 'need attention' ), 'Health shows status pills and the summary' );
t_assert( false !== strpos( $html, 'wp-header-end' ), 'admin notices have an anchor below the header' );

$_GET = array(
	'page'    => 'wwfw',
	'webhook' => 999999,
);
ob_start();
WWFW_Admin::render();
$missing = ob_get_clean();
t_assert( false !== strpos( $missing, 'no longer exists' ), 'missing webhook has a designed empty state' );

global $wp_styles;
WWFW_Admin::enqueue( 'index.php' );
$elsewhere = wp_style_is( 'wwfw-admin', 'enqueued' );
WWFW_Admin::enqueue( 'woocommerce_page_wwfw' );
t_assert( ! $elsewhere && wp_style_is( 'wwfw-admin', 'enqueued' ), 'stylesheet loads only on the plugin page' );
$_GET = array();
