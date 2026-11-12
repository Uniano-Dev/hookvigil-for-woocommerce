<?php
/**
 * US3: orders missed while a webhook was disabled.
 */

t_section( 'US3 orders missed during an outage' );

$before_order = t_make_order();
$webhook_id   = t_make_webhook( 'ERP new orders', 'order.created', 'failing-receiver.example.com' );
sleep( 1 );
t_fail_until_disabled( $webhook_id, $before_order );
$incident = WWFW_Incidents::find_open( 'webhook_disabled:' . $webhook_id );
t_assert( null !== $incident, 'outage recorded' );

$missed = array( t_make_order(), t_make_order(), t_make_order() );
$still  = WWFW_Missed_Orders::order_ids( WWFW_Incidents::find_open( 'webhook_disabled:' . $webhook_id ) );
t_assert( $still === $missed, 'open outage lists the orders created so far' );

sleep( 1 );
WWFW_Monitor::reenable( $webhook_id );
sleep( 1 );
$after_order = t_make_order();

$history = WWFW_Incidents::for_webhook( $webhook_id );
$ids     = WWFW_Missed_Orders::order_ids( $history[0] );
t_assert( $ids === $missed, 'closed outage lists exactly the 3 orders created while disabled' );
t_assert( ! in_array( $before_order, $ids, true ) && ! in_array( $after_order, $ids, true ), 'orders before and after the outage are excluded' );

ob_start();
@WWFW_Missed_Orders::output_csv( $history[0] ); // phpcs:ignore -- headers cannot be sent in CLI.
$csv   = ob_get_clean();
$lines = array_values( array_filter( explode( "\n", trim( $csv ) ), static function ( $line ) {
	return 0 === strpos( $line, 'order_id' ) || preg_match( '/^\d+,/', $line );
} ) );
t_assert( 4 === count( $lines ) && 0 === strpos( $lines[0], 'order_id' ), 'CSV has a header and 3 order rows' );

$other = t_make_webhook( 'Products feed', 'product.updated', 'failing-receiver.example.com' );
t_assert( array() === WWFW_Missed_Orders::order_ids( array( 'topic' => 'product.updated', 'opened_at' => time() - 60, 'closed_at' => 0, 'webhook_id' => $other ) ), 'non-order topics return no order list' );
