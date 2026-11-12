<?php
/**
 * US1: alert when a webhook is disabled.
 */

t_section( 'US1 alert when a webhook is disabled' );
t_enable_all_channels();
t_reset_capture();

$order_id   = t_make_order();
$webhook_id = t_make_webhook( 'ERP orders', 'order.created', 'failing-receiver.example.com' );
$attempts   = t_fail_until_disabled( $webhook_id, $order_id );
echo "INFO: WooCommerce disabled the webhook after {$attempts} consecutive failures\n";
t_assert( $attempts > 0, 'failing webhook gets disabled by WooCommerce' );

$incident = WWFW_Incidents::find_open( 'webhook_disabled:' . $webhook_id );
t_assert( null !== $incident, 'incident opened by the disable hook' );
t_assert( $incident && false === $incident['opened_approx'], 'incident time is exact (not from scan)' );
t_assert( as_has_scheduled_action( 'wwfw_dispatch_alerts', array(), 'wwfw' ), 'async alert dispatch enqueued, not sent inline' );
t_assert( 0 === count( $GLOBALS['wwfw_t']['mails'] ), 'no email sent inside the delivery itself' );

WWFW_Monitor::dispatch_alerts();
$mails = $GLOBALS['wwfw_t']['mails'];
$http  = $GLOBALS['wwfw_t']['http'];
t_assert( 1 === count( $mails ), 'one email alert sent' );
t_assert( 2 === count( $http ), 'one Telegram and one Slack alert sent' );
t_assert( $mails && false !== strpos( $mails[0]['subject'], 'ERP orders' ), 'email subject names the webhook' );
t_assert( $mails && false !== strpos( $mails[0]['message'], '500' ), 'email shows the last error code' );
t_assert( $mails && false !== strpos( $mails[0]['message'], 'failing-receiver.example.com' ), 'email shows the receiver host' );

$all_text = wp_json_encode( array( $mails, wp_list_pluck( $http, 'body' ) ) );
t_assert( false === strpos( $all_text, 'SECRET-TOKEN' ) && false === strpos( $all_text, 'SECRETPATH' ) && false === strpos( $all_text, 'test-secret' ), 'no secrets inside alert texts' );

t_reset_capture();
WWFW_Monitor::run_checks();
WWFW_Monitor::dispatch_alerts();
t_assert( 0 === count( $GLOBALS['wwfw_t']['mails'] ) && 0 === count( $GLOBALS['wwfw_t']['http'] ), 'no duplicate alert on the next check' );

$incident = WWFW_Incidents::find_open( 'webhook_disabled:' . $webhook_id );
WWFW_Incidents::update( $incident['key'], $incident['opened_at'], array( 'last_alert_at' => time() - 25 * HOUR_IN_SECONDS ) );
t_reset_capture();
WWFW_Monitor::dispatch_alerts();
t_assert( 1 === count( $GLOBALS['wwfw_t']['mails'] ) && false !== strpos( $GLOBALS['wwfw_t']['mails'][0]['subject'], 'Still disabled' ), 'reminder sent after 24 h' );

$stats = WWFW_Delivery_Log::stats( $webhook_id );
t_assert( $stats['failures'] === $attempts && '500' === $stats['last_error'], 'delivery log recorded every failed attempt' );

// Webhook disabled before the plugin could see it.
$old_id = t_make_webhook( 'Old CRM sync', 'customer.created', 'failing-receiver.example.com' );
$old    = wc_get_webhook( $old_id );
$old->set_status( 'disabled' );
$old->save();
t_reset_capture();
WWFW_Monitor::run_checks();
$old_incident = WWFW_Incidents::find_open( 'webhook_disabled:' . $old_id );
t_assert( $old_incident && true === $old_incident['opened_approx'], 'scan finds a webhook disabled earlier (approximate time)' );
t_assert( 1 === count( $GLOBALS['wwfw_t']['mails'] ), 'scan-found webhook alerted once' );

// Paused webhooks are not failures.
$paused_id = t_make_webhook( 'Paused', 'product.updated', 'ok-receiver.example.com' );
$paused    = wc_get_webhook( $paused_id );
$paused->set_status( 'paused' );
$paused->save();
WWFW_Monitor::run_checks();
t_assert( null === WWFW_Incidents::find_open( 'webhook_disabled:' . $paused_id ), 'paused webhook does not open an incident' );

// Test alert.
t_reset_capture();
$results = WWFW_Notifier::send( 'Test', 'Test body' );
t_assert( ! empty( $results['email']['ok'] ) && ! empty( $results['telegram']['ok'] ) && ! empty( $results['slack']['ok'] ), 'test alert reports success per channel' );
