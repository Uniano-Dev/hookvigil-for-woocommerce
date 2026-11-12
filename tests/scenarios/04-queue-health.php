<?php
/**
 * US4: delivery queue health.
 */

t_section( 'US4 delivery queue health' );
t_reset_capture();

$hook = WWFW_Monitor::DELIVERY_HOOK;
as_schedule_single_action( time() - 30 * MINUTE_IN_SECONDS, $hook, array( 'webhook_id' => 9001, 'arg' => 1 ), 'woocommerce-webhooks' );
as_schedule_single_action( time() - 20 * MINUTE_IN_SECONDS, $hook, array( 'webhook_id' => 9002, 'arg' => 2 ), 'woocommerce-webhooks' );
as_schedule_single_action( time() + 10 * MINUTE_IN_SECONDS, $hook, array( 'webhook_id' => 9003, 'arg' => 3 ), 'woocommerce-webhooks' );

$failed_id = as_schedule_single_action( time() - 60, $hook, array( 'webhook_id' => 9004, 'arg' => 4 ), 'woocommerce-webhooks' );
ActionScheduler::store()->mark_failure( $failed_id );

$status = WWFW_Monitor::queue_status();
t_assert( 2 === $status['overdue'], 'two overdue deliveries counted, future one ignored' );
t_assert( $status['oldest_minutes'] >= 29 && $status['oldest_minutes'] <= 31, 'oldest overdue age about 30 minutes' );
t_assert( 1 === $status['failed_24h'], 'failed delivery job counted' );

WWFW_Monitor::run_checks();
$incident = WWFW_Incidents::find_open( WWFW_Monitor::QUEUE_KEY );
t_assert( null !== $incident, 'backlog incident opened' );
// Earlier scenarios leave other open incidents whose alerts go out in the same run; count only backlog mails.
$mails = array_values( array_filter( $GLOBALS['wwfw_t']['mails'], static function ( $mail ) {
	return false !== strpos( $mail['subject'], 'overdue' );
} ) );
t_assert( 1 === count( $mails ), 'backlog alert sent once' );

t_reset_capture();
WWFW_Monitor::run_checks();
t_assert( 0 === count( $GLOBALS['wwfw_t']['mails'] ), 'no duplicate backlog alert' );

as_unschedule_all_actions( $hook );
t_reset_capture();
WWFW_Monitor::run_checks();
t_assert( null === WWFW_Incidents::find_open( WWFW_Monitor::QUEUE_KEY ), 'backlog incident closed when the queue clears' );
$mails = $GLOBALS['wwfw_t']['mails'];
t_assert( 1 === count( $mails ) && false !== strpos( $mails[0]['subject'], 'flowing again' ), 'recovery alert sent once' );
