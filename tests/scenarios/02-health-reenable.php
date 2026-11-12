<?php
/**
 * US2: health overview and safe re-enable.
 */

t_section( 'US2 health overview and safe re-enable' );
t_enable_all_channels();

$order_id   = t_make_order();
$webhook_id = t_make_webhook( 'Fulfilment', 'order.created', 'failing-receiver.example.com' );
t_fail_until_disabled( $webhook_id, $order_id );
WWFW_Monitor::dispatch_alerts();

// Core behaviour (issue #70247): activating through the normal setter keeps the counter.
$core = wc_get_webhook( $webhook_id );
echo 'INFO: failure count kept by core after disable: ' . $core->get_failure_count() . "\n";

t_reset_capture();
t_assert( WWFW_Monitor::reenable( $webhook_id ), 're-enable returns true' );
$webhook = wc_get_webhook( $webhook_id );
t_assert( 'active' === $webhook->get_status() && 0 === (int) $webhook->get_failure_count(), 'webhook active with failure count 0' );

$closed = WWFW_Incidents::for_webhook( $webhook_id );
t_assert( $closed && $closed[0]['closed_at'] > 0, 'incident closed on re-enable' );
WWFW_Monitor::dispatch_alerts();
t_assert( 1 === count( $GLOBALS['wwfw_t']['mails'] ) && false !== strpos( $GLOBALS['wwfw_t']['mails'][0]['subject'], 'active again' ), 'recovery alert sent once' );

$webhook = t_deliver( $webhook_id, $order_id );
t_assert( 'active' === $webhook->get_status(), 'one more failure does not disable the webhook again' );

// Successful delivery and stats.
$ok_id = t_make_webhook( 'Analytics', 'order.created', 'ok-receiver.example.com' );
t_deliver( $ok_id, $order_id );
$stats = WWFW_Delivery_Log::stats( $ok_id );
t_assert( 1 === $stats['deliveries'] && 0 === $stats['failures'] && '' !== $stats['last_success'], 'successful delivery counted with last success time' );

// Health screen renders.
t_login_admin();
$_GET = array( 'page' => 'wwfw' );
$fail_id = t_make_webhook( 'Broken CRM', 'order.updated', 'failing-receiver.example.com' );
t_fail_until_disabled( $fail_id, $order_id );
ob_start();
WWFW_Admin::render();
$html = ob_get_clean();
t_assert( false !== strpos( $html, 'Analytics' ) && false !== strpos( $html, 'Broken CRM' ), 'health screen lists webhooks' );
t_assert( false !== strpos( $html, 'Re-enable and reset' ), 'health screen offers re-enable for a disabled webhook' );
t_assert( false !== strpos( $html, '_wpnonce' ), 're-enable link carries a nonce' );

$_GET = array(
	'page' => 'wwfw',
	'tab'  => 'settings',
);
ob_start();
WWFW_Admin::render();
$settings_html = ob_get_clean();
t_assert( false === strpos( $settings_html, 'SECRET-TOKEN' ) && false === strpos( $settings_html, 'SECRETPATH' ), 'settings screen never prints saved secrets' );
t_assert( false !== strpos( $settings_html, 'cdef' ), 'settings screen shows masked token tail' );

// Settings sanitising keeps secrets when the field is left empty.
$clean = WWFW_Settings::sanitize(
	array(
		'email_enabled'    => '1',
		'email_recipients' => 'a@example.com, not-an-email, b@example.com',
		'telegram_token'   => '',
		'backlog_minutes'  => '1',
		'retention_days'   => '99',
	)
);
t_assert( 'a@example.com, b@example.com' === $clean['email_recipients'], 'invalid email addresses dropped' );
t_assert( '123456:SECRET-TOKEN-abcdef' === $clean['telegram_token'], 'empty secret field keeps the saved token' );
t_assert( 5 === $clean['backlog_minutes'] && 30 === $clean['retention_days'], 'thresholds clamped to allowed ranges' );

// Capability gate.
wp_set_current_user( 0 );
t_assert( ! current_user_can( WWFW_Admin::CAPABILITY ), 'anonymous user lacks the required capability' );
t_login_admin();

// Pruning.
WWFW_Delivery_Log::insert(
	array(
		'webhook_id'  => $ok_id,
		'topic'       => 'order.created',
		'resource_id' => 1,
		'status_code' => '200',
		'success'     => 1,
		'duration_ms' => 5,
		'created_at'  => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
	)
);
global $wpdb;
$table  = WWFW_Delivery_Log::table();
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
WWFW_Delivery_Log::prune();
$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
t_assert( $after === $before - 1, 'prune removes only rows older than retention' );
