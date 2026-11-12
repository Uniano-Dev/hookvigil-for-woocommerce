<?php
/**
 * Admin screens and actions.
 *
 * @package Hookvigil
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → Hookvigil.
 */
class WWFW_Admin {

	const PAGE       = 'wwfw';
	const CAPABILITY = 'manage_woocommerce';
	const GROUP      = 'wwfw_settings_group';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'fallback_checks' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_wwfw_reenable', array( __CLASS__, 'handle_reenable' ) );
		add_action( 'admin_post_wwfw_test_alert', array( __CLASS__, 'handle_test_alert' ) );
		add_action( 'admin_post_wwfw_missed_csv', array( __CLASS__, 'handle_missed_csv' ) );
		add_action( 'admin_post_wwfw_snooze_notice', array( __CLASS__, 'handle_snooze_notice' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( __CLASS__, 'capability' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WWFW_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Capability for saving settings.
	 *
	 * @return string
	 */
	public static function capability() {
		return self::CAPABILITY;
	}

	/**
	 * Submenu under WooCommerce.
	 */
	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Hookvigil', 'hookvigil-for-woocommerce' ),
			__( 'Hookvigil', 'hookvigil-for-woocommerce' ),
			self::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Stylesheet for the plugin's own page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'wwfw-admin', plugins_url( 'assets/admin.css', WWFW_FILE ), array(), WWFW_VERSION );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( array( 'tab' => 'settings' ) ) ) . '">' . esc_html__( 'Settings', 'hookvigil-for-woocommerce' ) . '</a>' );
		return $links;
	}

	/**
	 * Register the settings option.
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			WWFW_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'WWFW_Settings', 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Run checks from an admin request when the background queue has not run them for 10 minutes.
	 */
	public static function fallback_checks() {
		if ( ! current_user_can( self::CAPABILITY ) || wp_doing_ajax() ) {
			return;
		}
		$last = (int) WWFW_Plugin::state( 'last_check_at' );
		if ( $last > time() - 10 * MINUTE_IN_SECONDS || get_transient( 'wwfw_fallback_lock' ) ) {
			return;
		}
		set_transient( 'wwfw_fallback_lock', 1, 5 * MINUTE_IN_SECONDS );
		WWFW_Monitor::run_checks();
	}

	/**
	 * Admin notices: disabled webhooks (WooCommerce screens only, can be hidden for 24 hours) and stale checks.
	 */
	public static function notices() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$screen = get_current_screen();
		$ours   = $screen && false !== strpos( (string) $screen->id, self::PAGE );
		// Not on the Dashboard or the Plugins screen: only where store owners manage WooCommerce.
		$where = $screen && ( $ours || false !== strpos( (string) $screen->id, 'woocommerce' ) || 'edit-shop_order' === $screen->id );
		if ( ! $where ) {
			return;
		}

		$disabled = self::disabled_signature();
		if ( $disabled['count'] && ! $ours && ! self::notice_snoozed( $disabled['signature'] ) ) {
			$snooze = wp_nonce_url( add_query_arg( 'action', 'wwfw_snooze_notice', admin_url( 'admin-post.php' ) ), 'wwfw_snooze_notice' );
			printf(
				'<div class="notice notice-error"><p>%s <a href="%s">%s</a> &middot; <a href="%s">%s</a></p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of webhooks */
						_n( '%d WooCommerce webhook is disabled. Orders are not reaching the connected system.', '%d WooCommerce webhooks are disabled. Orders are not reaching the connected systems.', $disabled['count'], 'hookvigil-for-woocommerce' ),
						$disabled['count']
					)
				),
				esc_url( self::url() ),
				esc_html__( 'Review', 'hookvigil-for-woocommerce' ),
				esc_url( $snooze ),
				esc_html__( 'Hide for 24 hours', 'hookvigil-for-woocommerce' )
			);
		}

		$last = (int) WWFW_Plugin::state( 'last_check_at' );
		if ( $ours && $last && $last < time() - 30 * MINUTE_IN_SECONDS ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: human time difference */
						__( 'Background checks last ran %s ago. Scheduled tasks (WP-Cron / Action Scheduler) may not be running on this site, which also delays webhook deliveries.', 'hookvigil-for-woocommerce' ),
						human_time_diff( $last )
					)
				)
			);
		}
	}

	/**
	 * Number of disabled webhooks and a signature of the current set (changes when another webhook is disabled).
	 *
	 * @return array{count:int,signature:string}
	 */
	private static function disabled_signature() {
		$keys = array();
		foreach ( WWFW_Incidents::open_incidents() as $incident ) {
			if ( 'webhook_disabled' === $incident['type'] ) {
				$keys[] = $incident['key'] . '@' . (int) $incident['opened_at'];
			}
		}
		sort( $keys );
		return array(
			'count'     => count( $keys ),
			'signature' => md5( implode( '|', $keys ) ),
		);
	}

	/**
	 * Whether the current user hid the notice for this set of disabled webhooks less than 24 hours ago.
	 *
	 * @param string $signature Current signature.
	 * @return bool
	 */
	public static function notice_snoozed( $signature ) {
		$snooze = get_user_meta( get_current_user_id(), 'wwfw_notice_snoozed', true );
		return is_array( $snooze )
			&& isset( $snooze['until'], $snooze['signature'] )
			&& (int) $snooze['until'] > time()
			&& $snooze['signature'] === $signature;
	}

	/**
	 * Hide the disabled-webhook notice for 24 hours for the current user (admin-post).
	 */
	public static function handle_snooze_notice() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'hookvigil-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'wwfw_snooze_notice' );
		self::snooze_notice();
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=wc-admin' ) );
		exit;
	}

	/**
	 * Store the 24-hour snooze for the current set of disabled webhooks.
	 */
	public static function snooze_notice() {
		$disabled = self::disabled_signature();
		update_user_meta(
			get_current_user_id(),
			'wwfw_notice_snoozed',
			array(
				'until'     => time() + DAY_IN_SECONDS,
				'signature' => $disabled['signature'],
			)
		);
	}

	/**
	 * Page router.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'hookvigil-for-woocommerce' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$tab        = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'health';
		$webhook_id = isset( $_GET['webhook'] ) ? absint( $_GET['webhook'] ) : 0;
		$notice     = isset( $_GET['wwfw_notice'] ) ? sanitize_key( wp_unslash( $_GET['wwfw_notice'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap wwfw-wrap"><div class="wwfw-header">';
		echo '<img class="wwfw-logo" src="' . esc_url( plugins_url( 'assets/icon.svg', WWFW_FILE ) ) . '" width="40" height="40" alt="">';
		echo '<h1>' . esc_html__( 'Hookvigil', 'hookvigil-for-woocommerce' ) . '</h1></div>';
		echo '<hr class="wp-header-end">';
		self::render_action_notice( $notice );

		echo '<nav class="nav-tab-wrapper wwfw-tabs">';
		foreach ( array(
			'health'   => __( 'Health', 'hookvigil-for-woocommerce' ),
			'settings' => __( 'Alerts & settings', 'hookvigil-for-woocommerce' ),
		) as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::url( array( 'tab' => $key ) ) ),
				( $key === $tab ) ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		if ( 'settings' === $tab ) {
			self::render_settings();
		} elseif ( $webhook_id ) {
			self::render_webhook( $webhook_id );
		} else {
			self::render_health();
		}
		echo '</div>';
	}

	/**
	 * Health tab.
	 */
	private static function render_health() {
		$ids      = WC_Data_Store::load( 'webhook' )->search_webhooks( array( 'limit' => -1 ) );
		$webhooks = array();
		foreach ( $ids as $id ) {
			$webhook = wc_get_webhook( $id );
			if ( $webhook ) {
				$webhooks[ (int) $id ] = $webhook;
			}
		}
		$disabled = 0;
		foreach ( $webhooks as $webhook ) {
			if ( 'disabled' === $webhook->get_status() ) {
				++$disabled;
			}
		}
		$queue = WWFW_Monitor::queue_status();
		$last  = (int) WWFW_Plugin::state( 'last_check_at' );

		// Summary strip.
		if ( empty( $webhooks ) ) {
			$tone     = 'info';
			$headline = __( 'No webhooks to watch yet', 'hookvigil-for-woocommerce' );
		} elseif ( $disabled ) {
			$tone     = 'danger';
			$headline = sprintf(
				/* translators: 1: number of disabled webhooks, 2: total number of webhooks */
				_n( '%1$d of %2$d webhooks needs attention', '%1$d of %2$d webhooks need attention', $disabled, 'hookvigil-for-woocommerce' ),
				$disabled,
				count( $webhooks )
			);
		} else {
			$tone     = 'ok';
			$headline = sprintf(
				/* translators: %d: number of webhooks */
				_n( '%d webhook is active', 'All %d webhooks are active', count( $webhooks ), 'hookvigil-for-woocommerce' ),
				count( $webhooks )
			);
		}
		if ( ! $queue['applicable'] ) {
			$queue_text = __( 'Delivery queue: not applicable (Action Scheduler is not available).', 'hookvigil-for-woocommerce' );
		} elseif ( $queue['overdue'] ) {
			$tone       = ( 'danger' === $tone ) ? $tone : 'warn';
			$queue_text = sprintf(
				/* translators: 1: number of deliveries, 2: minutes */
				__( 'Delivery queue: %1$d deliveries overdue, the oldest has waited %2$d minutes.', 'hookvigil-for-woocommerce' ),
				$queue['overdue'],
				$queue['oldest_minutes']
			);
		} else {
			$queue_text = sprintf(
				/* translators: %d: number of failed actions */
				__( 'Delivery queue: no overdue deliveries, %d failed jobs in the last 24 h.', 'hookvigil-for-woocommerce' ),
				$queue['failed_24h']
			);
		}
		$check_text = $last
			/* translators: %s: human time difference */
			? sprintf( __( 'Last check %s ago', 'hookvigil-for-woocommerce' ), human_time_diff( $last ) )
			: __( 'The first check has not run yet', 'hookvigil-for-woocommerce' );

		echo '<div class="wwfw-summary wwfw-tone-' . esc_attr( $tone ) . '">';
		echo '<span class="wwfw-dot" aria-hidden="true"></span><div>';
		echo '<p class="wwfw-summary-title">' . esc_html( $headline ) . '</p>';
		echo '<p class="wwfw-summary-meta">' . esc_html( $check_text ) . ' · ' . esc_html( $queue_text ) . '</p>';
		echo '</div></div>';

		if ( empty( $webhooks ) ) {
			echo '<div class="wwfw-empty"><p><strong>' . esc_html__( 'This store has no webhooks yet.', 'hookvigil-for-woocommerce' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Connectors to ERPs, CRMs and automation tools usually create them. Hookvigil starts watching each new webhook automatically.', 'hookvigil-for-woocommerce' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=webhooks' ) ) . '">' . esc_html__( 'Open WooCommerce webhooks', 'hookvigil-for-woocommerce' ) . '</a></p></div>';
			return;
		}

		$columns = array(
			'status'  => __( 'Status', 'hookvigil-for-woocommerce' ),
			'host'    => __( 'Delivers to', 'hookvigil-for-woocommerce' ),
			'success' => __( 'Last success', 'hookvigil-for-woocommerce' ),
			'streak'  => __( 'Failure streak', 'hookvigil-for-woocommerce' ),
			'volume'  => __( 'Sent / failed, 24 h', 'hookvigil-for-woocommerce' ),
			'error'   => __( 'Last error', 'hookvigil-for-woocommerce' ),
			'actions' => __( 'Actions', 'hookvigil-for-woocommerce' ),
		);

		// Core list-table markup: on narrow screens core CSS keeps the primary column and common.js expands rows.
		echo '<table class="wp-list-table widefat striped table-view-list wwfw-webhooks"><thead><tr>';
		echo '<th scope="col" class="column-primary">' . esc_html__( 'Webhook', 'hookvigil-for-woocommerce' ) . '</th>';
		foreach ( $columns as $key => $heading ) {
			$label = ( 'actions' === $key ) ? '<span class="screen-reader-text">' . esc_html( $heading ) . '</span>' : esc_html( $heading );
			echo '<th scope="col" class="column-' . esc_attr( $key ) . '">' . $label . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		echo '</tr></thead><tbody>';

		foreach ( $webhooks as $id => $webhook ) {
			$stats  = WWFW_Delivery_Log::stats( $id );
			$status = $webhook->get_status();
			$cells  = array(
				'status'  => self::status_badge( $status ),
				'host'    => esc_html( (string) wp_parse_url( $webhook->get_delivery_url(), PHP_URL_HOST ) ),
				'success' => esc_html( self::ago( $stats['last_success'] ) ),
				'streak'  => esc_html( number_format_i18n( (int) $webhook->get_failure_count() ) ),
				'volume'  => esc_html( number_format_i18n( $stats['deliveries'] ) . ' / ' . number_format_i18n( $stats['failures'] ) ),
				'error'   => ( '' !== $stats['last_error'] )
					? esc_html( WWFW_Alert::describe_error( $stats['last_error'] ) ) . '<br><span class="wwfw-muted">' . esc_html( self::ago( $stats['last_error_at'] ) ) . '</span>'
					: '<span class="wwfw-muted">—</span>',
				'actions' => ( 'disabled' === $status ) ? self::reenable_button( $id ) : '',
			);
			echo '<tr class="wwfw-row-' . esc_attr( $status ) . '">';
			echo '<td class="column-primary">';
			echo '<a class="row-title" href="' . esc_url( self::url( array( 'webhook' => $id ) ) ) . '">' . esc_html( $webhook->get_name() ) . '</a>';
			echo '<code class="wwfw-topic">' . esc_html( $webhook->get_topic() ) . '</code>';
			echo '<span class="wwfw-mobile-status">' . self::status_badge( $status ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in status_badge().
			echo '<button type="button" class="toggle-row"><span class="screen-reader-text">' . esc_html__( 'Show more details', 'hookvigil-for-woocommerce' ) . '</span></button></td>';
			foreach ( $columns as $key => $heading ) {
				echo '<td class="column-' . esc_attr( $key ) . '" data-colname="' . esc_attr( $heading ) . '">' . $cells[ $key ] . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every cell escaped above.
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description wwfw-footnote">' . esc_html__( 'Delivery figures cover deliveries recorded since this plugin was activated. Request and response bodies are never stored.', 'hookvigil-for-woocommerce' ) . '</p>';
	}

	/**
	 * Webhook details with outage history and missed orders.
	 *
	 * @param int $webhook_id Webhook ID.
	 */
	private static function render_webhook( $webhook_id ) {
		$webhook = wc_get_webhook( $webhook_id );
		echo '<p class="wwfw-back"><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'All webhooks', 'hookvigil-for-woocommerce' ) . '</a></p>';
		if ( ! $webhook ) {
			echo '<div class="wwfw-empty"><p><strong>' . esc_html__( 'This webhook no longer exists.', 'hookvigil-for-woocommerce' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'It may have been deleted in WooCommerce or by the connector that created it.', 'hookvigil-for-woocommerce' ) . '</p></div>';
			return;
		}

		echo '<div class="wwfw-title"><h2>' . esc_html( $webhook->get_name() ) . '</h2>' . self::status_badge( $webhook->get_status() ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in status_badge().
		echo '<p class="wwfw-route"><code>' . esc_html( $webhook->get_topic() ) . '</code> → ' . esc_html( (string) wp_parse_url( $webhook->get_delivery_url(), PHP_URL_HOST ) ) . '</p>';
		if ( 'disabled' === $webhook->get_status() ) {
			echo '<p>' . self::reenable_button( $webhook_id ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in reenable_button().
		}

		echo '<h3>' . esc_html__( 'Outages and missed orders', 'hookvigil-for-woocommerce' ) . '</h3>';
		$incidents = WWFW_Incidents::for_webhook( $webhook_id );
		if ( empty( $incidents ) ) {
			echo '<div class="wwfw-empty wwfw-tone-ok"><p><strong>' . esc_html__( 'No outages in the last 30 days.', 'hookvigil-for-woocommerce' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'If WooCommerce disables this webhook, the outage and the orders it missed appear here.', 'hookvigil-for-woocommerce' ) . '</p></div>';
			return;
		}

		foreach ( $incidents as $incident ) {
			$from = WWFW_Alert::format_time( (int) $incident['opened_at'] ) . ( $incident['opened_approx'] ? ' ' . __( '(approx.)', 'hookvigil-for-woocommerce' ) : '' );
			if ( $incident['closed_at'] ) {
				/* translators: 1: start time, 2: end time */
				$period = sprintf( __( 'Disabled from %1$s to %2$s', 'hookvigil-for-woocommerce' ), $from, WWFW_Alert::format_time( (int) $incident['closed_at'] ) );
			} else {
				/* translators: %s: start time */
				$period = sprintf( __( 'Disabled since %s, still disabled', 'hookvigil-for-woocommerce' ), $from );
			}
			echo '<div class="wwfw-outage wwfw-tone-' . ( $incident['closed_at'] ? 'ok' : 'danger' ) . '">';
			echo '<p class="wwfw-outage-period"><strong>' . esc_html( $period ) . '</strong></p>';

			if ( ! WWFW_Missed_Orders::supports( $incident['topic'] ) ) {
				echo '<p>' . esc_html__( 'Missed items cannot be listed for this topic. Every event in the window above was skipped.', 'hookvigil-for-woocommerce' ) . '</p></div>';
				continue;
			}

			$order_ids = WWFW_Missed_Orders::order_ids( $incident );
			if ( empty( $order_ids ) ) {
				echo '<p>' . esc_html__( 'No orders were affected.', 'hookvigil-for-woocommerce' ) . '</p></div>';
				continue;
			}

			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of orders */
					_n( '%d order was not delivered to this webhook:', '%d orders were not delivered to this webhook:', count( $order_ids ), 'hookvigil-for-woocommerce' ),
					count( $order_ids )
				)
			) . '</p><p>';
			$links = array();
			foreach ( array_slice( $order_ids, 0, 50 ) as $order_id ) {
				$order   = wc_get_order( $order_id );
				$links[] = $order
					? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>'
					: '#' . esc_html( (string) $order_id );
			}
			echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link escaped above.
			if ( count( $order_ids ) > 50 ) {
				echo ' …';
			}
			$csv = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => 'wwfw_missed_csv',
						'incident' => $incident['key'],
						'opened'   => (int) $incident['opened_at'],
					),
					admin_url( 'admin-post.php' )
				),
				'wwfw_missed_csv'
			);
			echo '</p><p><a class="button" href="' . esc_url( $csv ) . '">' . esc_html__( 'Download CSV', 'hookvigil-for-woocommerce' ) . '</a></p></div>';
		}
	}

	/**
	 * Settings tab.
	 */
	private static function render_settings() {
		$s = WWFW_Settings::all();
		// Custom admin pages do not print the "Settings saved" notice on their own.
		settings_errors();
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		$name = WWFW_Settings::OPTION;
		?>
		<h2><?php esc_html_e( 'Email', 'hookvigil-for-woocommerce' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Send email alerts', 'hookvigil-for-woocommerce' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[email_enabled]" value="1" <?php checked( $s['email_enabled'] ); ?>> <?php esc_html_e( 'Enabled', 'hookvigil-for-woocommerce' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="wwfw-email"><?php esc_html_e( 'Recipients', 'hookvigil-for-woocommerce' ); ?></label></th>
				<td><input id="wwfw-email" class="regular-text" type="text" name="<?php echo esc_attr( $name ); ?>[email_recipients]" value="<?php echo esc_attr( $s['email_recipients'] ); ?>">
				<p class="description"><?php esc_html_e( 'Separate several addresses with commas.', 'hookvigil-for-woocommerce' ); ?></p></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Telegram', 'hookvigil-for-woocommerce' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Send Telegram alerts', 'hookvigil-for-woocommerce' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[telegram_enabled]" value="1" <?php checked( $s['telegram_enabled'] ); ?>> <?php esc_html_e( 'Enabled', 'hookvigil-for-woocommerce' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="wwfw-tg-token"><?php esc_html_e( 'Bot token', 'hookvigil-for-woocommerce' ); ?></label></th>
				<td><input id="wwfw-tg-token" class="regular-text" type="password" autocomplete="off" name="<?php echo esc_attr( $name ); ?>[telegram_token]" value="" placeholder="<?php echo esc_attr( WWFW_Settings::mask( $s['telegram_token'] ) ); ?>">
				<?php if ( '' !== $s['telegram_token'] ) : ?>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[telegram_clear]" value="1"> <?php esc_html_e( 'Remove saved token', 'hookvigil-for-woocommerce' ); ?></label>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Create a bot with @BotFather. Leave empty to keep the saved token.', 'hookvigil-for-woocommerce' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="wwfw-tg-chat"><?php esc_html_e( 'Chat ID', 'hookvigil-for-woocommerce' ); ?></label></th>
				<td><input id="wwfw-tg-chat" class="regular-text" type="text" name="<?php echo esc_attr( $name ); ?>[telegram_chat_id]" value="<?php echo esc_attr( $s['telegram_chat_id'] ); ?>"></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Slack', 'hookvigil-for-woocommerce' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Send Slack alerts', 'hookvigil-for-woocommerce' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[slack_enabled]" value="1" <?php checked( $s['slack_enabled'] ); ?>> <?php esc_html_e( 'Enabled', 'hookvigil-for-woocommerce' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="wwfw-slack"><?php esc_html_e( 'Incoming webhook URL', 'hookvigil-for-woocommerce' ); ?></label></th>
				<td><input id="wwfw-slack" class="regular-text" type="password" autocomplete="off" name="<?php echo esc_attr( $name ); ?>[slack_webhook_url]" value="" placeholder="<?php echo esc_attr( WWFW_Settings::mask( $s['slack_webhook_url'] ) ); ?>">
				<?php if ( '' !== $s['slack_webhook_url'] ) : ?>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[slack_clear]" value="1"> <?php esc_html_e( 'Remove saved URL', 'hookvigil-for-woocommerce' ); ?></label>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Leave empty to keep the saved URL.', 'hookvigil-for-woocommerce' ); ?></p></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Thresholds', 'hookvigil-for-woocommerce' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wwfw-backlog"><?php esc_html_e( 'Overdue delivery alert after (minutes)', 'hookvigil-for-woocommerce' ); ?></label></th>
				<td><input id="wwfw-backlog" type="number" min="5" max="240" name="<?php echo esc_attr( $name ); ?>[backlog_minutes]" value="<?php echo esc_attr( (string) $s['backlog_minutes'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wwfw-reminder"><?php esc_html_e( 'Remind every (hours) while a problem is open', 'hookvigil-for-woocommerce' ); ?></label></th>
				<td><input id="wwfw-reminder" type="number" min="1" max="168" name="<?php echo esc_attr( $name ); ?>[reminder_hours]" value="<?php echo esc_attr( (string) $s['reminder_hours'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wwfw-retention"><?php esc_html_e( 'Keep delivery history (days)', 'hookvigil-for-woocommerce' ); ?></label></th>
				<td><input id="wwfw-retention" type="number" min="1" max="30" name="<?php echo esc_attr( $name ); ?>[retention_days]" value="<?php echo esc_attr( (string) $s['retention_days'] ); ?>"></td>
			</tr>
		</table>
		<?php
		submit_button();
		echo '</form>';

		echo '<h2>' . esc_html__( 'Test', 'hookvigil-for-woocommerce' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wwfw_test_alert">';
		wp_nonce_field( 'wwfw_test_alert' );
		submit_button( __( 'Send test alert', 'hookvigil-for-woocommerce' ), 'secondary', 'submit', false );
		echo '</form>';

		$last = WWFW_Plugin::state( 'last_alert_results' );
		if ( is_array( $last ) && ! empty( $last['results'] ) ) {
			/* translators: %s: human time difference */
			echo '<h3>' . esc_html( sprintf( __( 'Last alert (%s ago)', 'hookvigil-for-woocommerce' ), human_time_diff( (int) $last['time'] ) ) ) . '</h3><ul class="wwfw-results">';
			foreach ( $last['results'] as $channel => $result ) {
				printf(
					'<li><strong>%s</strong> <span class="wwfw-status wwfw-status-%s"><span class="wwfw-dot" aria-hidden="true"></span>%s</span> <span class="wwfw-muted">%s</span></li>',
					esc_html( ucfirst( $channel ) ),
					$result['ok'] ? 'active' : 'disabled',
					$result['ok'] ? esc_html__( 'Sent', 'hookvigil-for-woocommerce' ) : esc_html__( 'Failed', 'hookvigil-for-woocommerce' ),
					esc_html( $result['message'] )
				);
			}
			echo '</ul>';
		}
	}

	/**
	 * Re-enable a webhook (admin-post).
	 */
	public static function handle_reenable() {
		$webhook_id = isset( $_GET['webhook_id'] ) ? absint( $_GET['webhook_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the ID is part of the nonce action checked below.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'hookvigil-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'wwfw_reenable_' . $webhook_id );
		$ok = WWFW_Monitor::reenable( $webhook_id );
		wp_safe_redirect( self::url( array( 'wwfw_notice' => $ok ? 'reenabled' : 'reenable_failed' ) ) );
		exit;
	}

	/**
	 * Send a test alert (admin-post).
	 */
	public static function handle_test_alert() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'hookvigil-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'wwfw_test_alert' );
		$alert = WWFW_Alert::test();
		WWFW_Notifier::send( $alert['subject'], $alert['text'], $alert );
		wp_safe_redirect( self::url( array( 'tab' => 'settings' ) ) );
		exit;
	}

	/**
	 * Download missed orders as CSV (admin-post).
	 */
	public static function handle_missed_csv() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'hookvigil-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'wwfw_missed_csv' );
		$key      = isset( $_GET['incident'] ) ? sanitize_text_field( wp_unslash( $_GET['incident'] ) ) : '';
		$opened   = isset( $_GET['opened'] ) ? absint( $_GET['opened'] ) : 0;
		$incident = WWFW_Incidents::find( $key, $opened );
		if ( ! $incident ) {
			wp_die( esc_html__( 'Outage not found.', 'hookvigil-for-woocommerce' ), 404 );
		}
		WWFW_Missed_Orders::output_csv( $incident );
		exit;
	}

	/**
	 * Result notice after an admin action.
	 *
	 * @param string $notice Notice key.
	 */
	private static function render_action_notice( $notice ) {
		$messages = array(
			'reenabled'       => array( 'success', __( 'Webhook re-enabled and its failure counter reset.', 'hookvigil-for-woocommerce' ) ),
			'reenable_failed' => array( 'error', __( 'The webhook could not be re-enabled.', 'hookvigil-for-woocommerce' ) ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
		}
	}

	/**
	 * "Re-enable and reset" button HTML (escaped).
	 *
	 * @param int $webhook_id Webhook ID.
	 * @return string
	 */
	private static function reenable_button( $webhook_id ) {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'wwfw_reenable',
					'webhook_id' => $webhook_id,
				),
				admin_url( 'admin-post.php' )
			),
			'wwfw_reenable_' . $webhook_id
		);
		return '<a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Re-enable and reset', 'hookvigil-for-woocommerce' ) . '</a>';
	}

	/**
	 * Status badge HTML (escaped).
	 *
	 * @param string $status Webhook status.
	 * @return string
	 */
	private static function status_badge( $status ) {
		$labels = array(
			'active'   => __( 'Active', 'hookvigil-for-woocommerce' ),
			'paused'   => __( 'Paused', 'hookvigil-for-woocommerce' ),
			'disabled' => __( 'Disabled', 'hookvigil-for-woocommerce' ),
		);
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( (string) $status );
		return '<span class="wwfw-status wwfw-status-' . esc_attr( $status ) . '"><span class="wwfw-dot" aria-hidden="true"></span>' . esc_html( $label ) . '</span>';
	}

	/**
	 * "x ago" for a GMT datetime string.
	 *
	 * @param string $gmt_datetime Datetime (GMT) or empty.
	 * @return string
	 */
	private static function ago( $gmt_datetime ) {
		if ( '' === $gmt_datetime ) {
			return '—';
		}
		$ts = strtotime( $gmt_datetime . ' UTC' );
		/* translators: %s: human time difference */
		return $ts ? sprintf( __( '%s ago', 'hookvigil-for-woocommerce' ), human_time_diff( $ts ) ) : '—';
	}

	/**
	 * Admin page URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}
}
