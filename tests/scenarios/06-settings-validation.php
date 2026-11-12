<?php
/**
 * 0.2.0 US1: settings never fail silently.
 */

t_section( '0.2.0 settings validation' );

$base = array(
	'email_enabled'     => true,
	'email_recipients'  => 'keep@harbor-supply.example',
	'telegram_enabled'  => false,
	'telegram_token'    => '',
	'telegram_chat_id'  => '',
	'slack_enabled'     => false,
	'slack_webhook_url' => '',
);
update_option( WWFW_Settings::OPTION, $base );

$out   = WWFW_Settings::sanitize( array( 'email_enabled' => '1', 'email_recipients' => 'a@harbor-supply.example, not-an-email; ops@example' ) );
$notes = WWFW_Settings::notes();
t_assert( 'a@harbor-supply.example' === $out['email_recipients'], 'valid recipient saved, invalid ones dropped' );
t_assert( isset( $notes['email_invalid'] ) && 'error' === $notes['email_invalid']['type'] && false !== strpos( $notes['email_invalid']['message'], 'not-an-email' ) && false !== strpos( $notes['email_invalid']['message'], 'ops@example' ), 'error names every rejected address' );

$out   = WWFW_Settings::sanitize( array( 'email_enabled' => '1', 'email_recipients' => 'oops' ) );
$notes = WWFW_Settings::notes();
t_assert( 'keep@harbor-supply.example' === $out['email_recipients'] && isset( $notes['email_kept'] ), 'no valid recipient: previous recipients kept and reported' );

$out   = WWFW_Settings::sanitize( array( 'email_enabled' => '1', 'email_recipients' => '' ) );
t_assert( 'keep@harbor-supply.example' === $out['email_recipients'], 'emptying recipients while email is on keeps the previous ones' );

$out   = WWFW_Settings::sanitize( array( 'email_recipients' => '' ) );
t_assert( '' === $out['email_recipients'] && ! isset( WWFW_Settings::notes()['email_kept'] ), 'email off: recipients can be cleared' );

// Telegram.
$out   = WWFW_Settings::sanitize( array( 'email_enabled' => '1', 'email_recipients' => 'a@harbor-supply.example', 'telegram_enabled' => '1' ) );
$notes = WWFW_Settings::notes();
t_assert( isset( $notes['telegram_incomplete'] ) && 'warning' === $notes['telegram_incomplete']['type'], 'Telegram on without token and chat ID: warning' );

update_option( WWFW_Settings::OPTION, array_merge( $base, array( 'telegram_token' => '7012345678:AAHsavedtokensavedtokensaved01', 'telegram_chat_id' => '-1001234567890' ) ) );
$out   = WWFW_Settings::sanitize( array( 'email_enabled' => '1', 'email_recipients' => 'a@harbor-supply.example', 'telegram_enabled' => '1', 'telegram_token' => 'garbage token', 'telegram_chat_id' => 'my chat' ) );
$notes = WWFW_Settings::notes();
t_assert( '7012345678:AAHsavedtokensavedtokensaved01' === $out['telegram_token'] && isset( $notes['telegram_token'] ), 'malformed token rejected, saved token kept' );
t_assert( '-1001234567890' === $out['telegram_chat_id'] && isset( $notes['telegram_chat'] ), 'malformed chat ID rejected, saved chat ID kept' );
t_assert( ! isset( $notes['telegram_incomplete'] ), 'kept values count as configured' );

$out = WWFW_Settings::sanitize( array( 'telegram_enabled' => '1', 'telegram_token' => '7012345678:AAHnewtokennewtokennewtoken02', 'telegram_chat_id' => '@shop_alerts' ) );
t_assert( '7012345678:AAHnewtokennewtokennewtoken02' === $out['telegram_token'] && '@shop_alerts' === $out['telegram_chat_id'] && ! isset( WWFW_Settings::notes()['telegram_token'] ), 'valid token and @channel saved' );

// Slack.
update_option( WWFW_Settings::OPTION, array_merge( $base, array( 'slack_webhook_url' => 'https://hooks.slack.com/services/T0/B0/saved' ) ) );
$out   = WWFW_Settings::sanitize( array( 'slack_enabled' => '1', 'slack_webhook_url' => 'http://hooks.slack.com/services/T0/B0/plain' ) );
$notes = WWFW_Settings::notes();
t_assert( 'https://hooks.slack.com/services/T0/B0/saved' === $out['slack_webhook_url'] && isset( $notes['slack_url'] ), 'non-https Slack URL rejected, saved URL kept' );

$out   = WWFW_Settings::sanitize( array( 'slack_enabled' => '1', 'slack_webhook_url' => 'https://chat.harbor-supply.example/hooks/abc' ) );
$notes = WWFW_Settings::notes();
t_assert( 'https://chat.harbor-supply.example/hooks/abc' === $out['slack_webhook_url'] && isset( $notes['slack_host'] ) && 'warning' === $notes['slack_host']['type'], 'https URL off hooks.slack.com saved with a warning' );

$out   = WWFW_Settings::sanitize( array( 'slack_enabled' => '1', 'slack_clear' => '1' ) );
t_assert( '' === $out['slack_webhook_url'] && isset( WWFW_Settings::notes()['slack_incomplete'] ), 'Slack on without URL: warning' );

// No channel.
WWFW_Settings::sanitize( array( 'email_recipients' => 'a@harbor-supply.example' ) );
t_assert( isset( WWFW_Settings::notes()['no_channels'] ), 'all channels off: warning' );

// Clean input: no messages.
WWFW_Settings::sanitize( array( 'email_enabled' => '1', 'email_recipients' => 'a@harbor-supply.example' ) );
t_assert( array() === WWFW_Settings::notes(), 'valid settings produce no messages' );

// Settings API: reported once per request, with a "saved" message first.
$errors = get_settings_errors( WWFW_Settings::OPTION );
$codes  = wp_list_pluck( $errors, 'code' );
t_assert( in_array( 'wwfw_saved', $codes, true ), 'Settings API gets a saved message' );
t_assert( count( array_keys( $codes, 'wwfw_saved', true ) ) === 1, 'messages are reported only once per request' );

t_enable_all_channels();
