<?php
/**
 * 0.3.0: the disabled-webhook notice stays on WooCommerce screens and can be hidden for 24 hours (Guideline 11).
 */

t_section( '0.3.0 admin notice scope and snooze' );
t_login_admin();
delete_user_meta( get_current_user_id(), 'wwfw_notice_snoozed' );

$order_id = t_make_order();
$hook_id  = t_make_webhook( 'Notice ERP', 'order.created', 'failing-receiver.example.com' );
t_fail_until_disabled( $hook_id, $order_id );
WWFW_Monitor::run_checks();

/**
 * Render admin notices for a screen id.
 */
function t_notices_on( $screen_id ) {
	set_current_screen( $screen_id );
	ob_start();
	WWFW_Admin::notices();
	return ob_get_clean();
}

t_assert( false !== strpos( t_notices_on( 'woocommerce_page_wc-settings' ), 'webhook' ), 'notice shown on WooCommerce screens' );
t_assert( '' === trim( t_notices_on( 'dashboard' ) ), 'notice not shown on the Dashboard' );
t_assert( '' === trim( t_notices_on( 'plugins' ) ), 'notice not shown on the Plugins screen' );
t_assert( false !== strpos( t_notices_on( 'woocommerce_page_wc-settings' ), 'Hide for 24 hours' ), 'notice offers "Hide for 24 hours"' );
t_assert( false !== strpos( t_notices_on( 'woocommerce_page_wc-settings' ), 'wwfw_snooze_notice' ), 'hide link goes to the nonce-protected handler' );

WWFW_Admin::snooze_notice();
t_assert( '' === trim( t_notices_on( 'woocommerce_page_wc-settings' ) ), 'hidden after snooze' );

$meta = get_user_meta( get_current_user_id(), 'wwfw_notice_snoozed', true );
$meta['until'] = time() - 1;
update_user_meta( get_current_user_id(), 'wwfw_notice_snoozed', $meta );
t_assert( false !== strpos( t_notices_on( 'woocommerce_page_wc-settings' ), 'Hide for 24 hours' ), 'shown again after 24 hours' );

WWFW_Admin::snooze_notice();
$other = t_make_webhook( 'Notice CRM', 'order.updated', 'failing-receiver.example.com' );
t_fail_until_disabled( $other, $order_id );
WWFW_Monitor::run_checks();
t_assert( false !== strpos( t_notices_on( 'woocommerce_page_wc-settings' ), 'Hide for 24 hours' ), 'a newly disabled webhook shows the notice again' );

wp_set_current_user( 0 );
t_assert( '' === trim( t_notices_on( 'woocommerce_page_wc-settings' ) ), 'no notice without the capability' );
t_login_admin();
set_current_screen( 'front' );
