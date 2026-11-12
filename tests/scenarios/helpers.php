<?php
/**
 * Test helpers for Playground scenario runs. Not shipped with the plugin.
 */

require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

$GLOBALS['wwfw_t'] = array(
	'pass'   => 0,
	'fail'   => 0,
	'mails'  => array(),
	'http'   => array(),
	'alerts' => array(),
);

/**
 * Record an assertion.
 */
function t_assert( $cond, $msg ) {
	if ( $cond ) {
		++$GLOBALS['wwfw_t']['pass'];
		echo "PASS: {$msg}\n";
	} else {
		++$GLOBALS['wwfw_t']['fail'];
		echo "FAIL: {$msg}\n";
	}
}

function t_section( $name ) {
	echo "\n== {$name} ==\n";
}

function t_reset_capture() {
	$GLOBALS['wwfw_t']['mails']  = array();
	$GLOBALS['wwfw_t']['http']   = array();
	$GLOBALS['wwfw_t']['alerts'] = array();
}

// Capture email instead of sending.
add_filter(
	'pre_wp_mail',
	static function ( $null, $atts ) {
		$GLOBALS['wwfw_t']['mails'][] = $atts;
		return true;
	},
	10,
	2
);

// Fake HTTP: receiver hosts answer by name, alert services answer 200.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$code = null;
		if ( 'failing-receiver.example.com' === $host ) {
			$code = 500;
		} elseif ( 'ok-receiver.example.com' === $host ) {
			$code = 200;
		} elseif ( 'api.telegram.org' === $host || 'hooks.slack.com' === $host ) {
			$GLOBALS['wwfw_t']['http'][] = array(
				'host' => $host,
				'url'  => $url,
				'body' => $args['body'],
			);
			$code = 200;
		}
		if ( null === $code ) {
			return $pre;
		}
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array(
				'code'    => $code,
				'message' => 'test',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

add_action(
	'wwfw_alert_sent',
	static function ( $incident, $event, $results ) {
		$GLOBALS['wwfw_t']['alerts'][] = array(
			'key'     => $incident['key'],
			'event'   => $event,
			'results' => $results,
		);
	},
	10,
	3
);

/**
 * Admin user as current user.
 */
function t_login_admin() {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);
	wp_set_current_user( $admins[0]->ID );
	return $admins[0]->ID;
}

/**
 * Create an active webhook.
 */
function t_make_webhook( $name, $topic, $host ) {
	$webhook = new WC_Webhook();
	$webhook->set_name( $name );
	$webhook->set_user_id( t_login_admin() );
	$webhook->set_topic( $topic );
	$webhook->set_delivery_url( 'https://' . $host . '/hook' );
	$webhook->set_secret( 'test-secret' );
	$webhook->set_status( 'active' );
	$webhook->save();
	return $webhook->get_id();
}

/**
 * Create a simple order.
 */
function t_make_order() {
	$order = wc_create_order();
	$order->set_billing_email( 'buyer@example.com' );
	$order->set_total( 10 );
	$order->save();
	return $order->get_id();
}

/**
 * Deliver once (synchronously through core) and return the fresh webhook object.
 */
function t_deliver( $webhook_id, $arg ) {
	$webhook = wc_get_webhook( $webhook_id );
	$webhook->deliver( $arg );
	return wc_get_webhook( $webhook_id );
}

/**
 * Deliver until WooCommerce disables the webhook. Returns number of attempts.
 */
function t_fail_until_disabled( $webhook_id, $arg, $max = 15 ) {
	for ( $i = 1; $i <= $max; $i++ ) {
		$webhook = t_deliver( $webhook_id, $arg );
		if ( 'disabled' === $webhook->get_status() ) {
			return $i;
		}
	}
	return -1;
}

function t_enable_all_channels() {
	update_option(
		WWFW_Settings::OPTION,
		array(
			'email_enabled'     => true,
			'email_recipients'  => 'owner@example.com',
			'telegram_enabled'  => true,
			'telegram_token'    => '123456:SECRET-TOKEN-abcdef',
			'telegram_chat_id'  => '42',
			'slack_enabled'     => true,
			'slack_webhook_url' => 'https://hooks.slack.com/services/T000/B000/SECRETPATH',
		)
	);
}

function t_summary() {
	$t = $GLOBALS['wwfw_t'];
	echo "\nRESULT: {$t['pass']} passed, {$t['fail']} failed\n";
}
