<?php
// Demo store for manual review and wordpress.org screenshots. Reserved .example domains only.
require_once '/wordpress/wp-load.php';

$hosts = array(
	'erp.harbor-supply.example' => array( 503, 'Service Unavailable' ),
	'crm.harbor-supply.example' => array( 200, 'OK' ),
	'3pl.harbor-supply.example' => array( 200, 'OK' ),
);
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( $hosts ) {
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( isset( $hosts[ $host ] ) ) {
		return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => $hosts[ $host ][0], 'message' => $hosts[ $host ][1] ), 'cookies' => array(), 'filename' => null );
	}
	return $pre;
}, 10, 3 );

// Keep alert emails as files instead of sending them (screenshot 4 shows the real HTML).
add_filter( 'pre_wp_mail', static function ( $pre, $atts ) {
	if ( is_dir( '/demo/out' ) || mkdir( '/demo/out' ) ) {
		$base = '/demo/out/alert-' . microtime( true );
		$html = false !== strpos( implode( "\n", (array) $atts['headers'] ), 'text/html' );
		file_put_contents( $base . ( $html ? '.html' : '.txt' ), $atts['message'] );
		file_put_contents( $base . '.meta.txt', 'To: ' . implode( ', ', (array) $atts['to'] ) . "\nSubject: " . $atts['subject'] . "\n" );
	}
	return true;
}, 10, 2 );

update_option( 'blogname', 'Harbor Supply Co.' );
update_option( 'timezone_string', 'Europe/Berlin' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'wwfw_settings', array( 'email_enabled' => true, 'email_recipients' => 'ops@harbor-supply.example' ) );
wp_set_current_user( 1 );

function demo_webhook( $name, $topic, $url ) {
	$w = new WC_Webhook();
	$w->set_name( $name );
	$w->set_user_id( 1 );
	$w->set_topic( $topic );
	$w->set_delivery_url( $url );
	$w->set_status( 'active' );
	$w->save();
	return $w->get_id();
}
function demo_order( $first, $last, $total ) {
	$o = wc_create_order();
	$o->set_billing_first_name( $first );
	$o->set_billing_last_name( $last );
	$o->set_billing_email( strtolower( $first ) . '@customer.example' );
	$o->set_total( $total );
	$o->set_status( 'processing' );
	$o->save();
	return $o->get_id();
}

$order = demo_order( 'Maya', 'Fischer', 84.50 );
$erp   = demo_webhook( 'ERP order import', 'order.created', 'https://erp.harbor-supply.example/api/orders' );
$crm   = demo_webhook( 'CRM deals sync', 'order.updated', 'https://crm.harbor-supply.example/woo/deals' );
$tpl   = demo_webhook( 'Fulfilment export', 'order.updated', 'https://3pl.harbor-supply.example/inbound' );
for ( $i = 0; $i < 12; $i++ ) { wc_get_webhook( $crm )->deliver( $order ); }
for ( $i = 0; $i < 9; $i++ ) { wc_get_webhook( $tpl )->deliver( $order ); }
for ( $i = 0; $i < 7; $i++ ) { wc_get_webhook( $erp )->deliver( $order ); }
foreach ( array( array( 'Liam', 'Novak', 129.00 ), array( 'Sofia', 'Marin', 46.90 ), array( 'Noah', 'Berg', 212.40 ), array( 'Emma', 'Weiss', 58.00 ) ) as $c ) {
	demo_order( $c[0], $c[1], $c[2] );
}
WWFW_Monitor::run_checks();

// Telegram configured after the alert went out, so the screen shows a saved (masked) token without a fake send result.
update_option(
	'wwfw_settings',
	array(
		'email_enabled'    => true,
		'email_recipients' => 'ops@harbor-supply.example',
		'telegram_enabled' => true,
		'telegram_token'   => '7012345678:AAHdemoTokenNotRealDemoTokenx9Qk',
		'telegram_chat_id' => '-1001987654321',
	)
);
echo "seeded\n";
