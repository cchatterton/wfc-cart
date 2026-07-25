<?php
/**
 * WFC Cart health screen.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Return health checks without exposing secret values.
 *
 * @return array<string, array{label:string,status:string,detail:string}>
 */
function wfcc_get_health_checks() {
	$salesforce_enabled = wfcc_uses_salesforce_crm();
	$salesforce_configured = '' !== wfcc_get_salesforce_login_url()
		&& '' !== wfcc_get_salesforce_client_id()
		&& '' !== wfcc_get_salesforce_client_secret()
		&& '' !== wfcc_get_salesforce_api_path();
	$salesforce_diagnostic = wfcc_get_salesforce_connection_diagnostic();
	$salesforce_detail = !$salesforce_enabled
		? __('Not required in WordPress CRM mode', 'wfc-cart')
		: ($salesforce_configured
			? __('OAuth client and fixed Apex endpoint configured', 'wfc-cart')
			: __('OAuth client or fixed Apex endpoint missing', 'wfc-cart'));
	if ($salesforce_enabled && !empty($salesforce_diagnostic['checked_at'])) {
		$salesforce_detail .= sprintf(
			__('; last connection test %1$s at %2$s', 'wfc-cart'),
			'ok' === ($salesforce_diagnostic['status'] ?? '') ? __('passed', 'wfc-cart') : __('failed', 'wfc-cart'),
			(string) $salesforce_diagnostic['checked_at']
		);
	}
	$receipt_email_enabled = (bool) wfcc_get_setting('receipt_email_enabled', false);
	$receipt_field_id      = wfcc_sanitize_gf_entry_key(wfcc_get_setting('receipt_email_field_id', ''));
	$receipt_status        = !$receipt_email_enabled || '' !== $receipt_field_id ? 'ok' : 'warning';
	$receipt_detail        = $receipt_email_enabled
		? ('' !== $receipt_field_id
			? __('Automatic email enabled with a configured Gravity Forms field', 'wfc-cart')
			: __('Automatic email is enabled but its Gravity Forms field is missing', 'wfc-cart'))
		: __('Receipt records enabled; automatic email disabled', 'wfc-cart');
	$queue_next = wp_next_scheduled('wfcc_process_delivery_queue');
	$cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
	$cron_status = $salesforce_enabled
		? ($queue_next && !$cron_disabled ? 'ok' : 'warning')
		: (!$queue_next ? 'ok' : 'warning');
	if (!$salesforce_enabled) {
		$cron_detail = $queue_next
			? __('A Salesforce queue schedule remains active even though WordPress CRM mode is selected.', 'wfc-cart')
			: __('Salesforce delivery scheduling is correctly disabled in WordPress CRM mode.', 'wfc-cart');
	} else {
		$cron_detail = $cron_disabled
			? __('WP-Cron is disabled; an external runner must invoke wp-cron.php.', 'wfc-cart')
			: ($queue_next
				? sprintf(__('Delivery queue scheduled for %s UTC', 'wfc-cart'), gmdate('c', $queue_next))
				: __('Delivery queue schedule is missing; save the CRM mode or reactivate the plugin to restore it.', 'wfc-cart'));
	}
	$wordpress_recurring = !$salesforce_enabled && wfcc_wordpress_crm_has_recurring_packages();

	return array(
		'crm_mode' => array(
			'label'  => __('CRM data location', 'wfc-cart'),
			'status' => 'ok',
			'detail' => wfcc_get_crm_mode_label(),
		),
		'gravity_forms' => array(
			'label'  => __('Gravity Forms', 'wfc-cart'),
			'status' => class_exists('GFForms') ? 'ok' : 'warning',
			'detail' => class_exists('GFForms') ? __('Available', 'wfc-cart') : __('Not detected', 'wfc-cart'),
		),
		'stripe' => array(
			'label'  => __('Stripe', 'wfc-cart'),
			'status' => wfcc_get_stripe_publishable_key() && wfcc_get_secret('stripe_secret_key', 'WFCC_STRIPE_SECRET_KEY', 'WFCC_STRIPE_SECRET_KEY') ? 'ok' : 'warning',
			'detail' => wfcc_get_stripe_publishable_key() && wfcc_get_secret('stripe_secret_key', 'WFCC_STRIPE_SECRET_KEY', 'WFCC_STRIPE_SECRET_KEY')
				? __('Publishable and secret keys configured', 'wfc-cart')
				: __('Publishable or secret key missing', 'wfc-cart'),
		),
		'salesforce' => array(
			'label'  => __('Salesforce', 'wfc-cart'),
			'status' => !$salesforce_enabled || $salesforce_configured ? 'ok' : 'warning',
			'detail' => $salesforce_detail,
		),
		'recurring_ownership' => array(
			'label'  => __('Recurring-payment ownership', 'wfc-cart'),
			'status' => $wordpress_recurring ? 'warning' : 'ok',
			'detail' => $wordpress_recurring
				? __('Recurring or SetupIntent packages are unavailable until Salesforce CRM mode is enabled.', 'wfc-cart')
				: ($salesforce_enabled
					? __('Salesforce owns subsequent recurring-payment orchestration.', 'wfc-cart')
					: __('WordPress CRM mode is configured for one-off packages only.', 'wfc-cart')),
		),
		'webhook' => array(
			'label'  => __('Stripe webhook signature', 'wfc-cart'),
			'status' => wfcc_get_secret('stripe_webhook_secret', 'WFCC_STRIPE_WEBHOOK_SECRET', 'WFCC_STRIPE_WEBHOOK_SECRET') ? 'ok' : 'warning',
			'detail' => wfcc_get_secret('stripe_webhook_secret', 'WFCC_STRIPE_WEBHOOK_SECRET', 'WFCC_STRIPE_WEBHOOK_SECRET') ? __('Signing secret configured', 'wfc-cart') : __('Not configured', 'wfc-cart'),
		),
		'checkout_packages' => array(
			'label'  => __('Checkout packages', 'wfc-cart'),
			'status' => wfcc_get_checkout_packages() ? 'ok' : 'warning',
			'detail' => wfcc_get_checkout_packages()
				? sprintf(__('%d configured', 'wfc-cart'), count(wfcc_get_checkout_packages()))
				: __('None configured', 'wfc-cart'),
		),
		'receipts' => array(
			'label'  => __('Receipts', 'wfc-cart'),
			'status' => $receipt_status,
			'detail' => $receipt_detail,
		),
		'cron' => array(
			'label'  => __('Scheduled processing', 'wfc-cart'),
			'status' => $cron_status,
			'detail' => $cron_detail,
		),
	);
}

/**
 * Render health checks.
 *
 * @return void
 */
function wfcc_render_health_page() {
	if (!current_user_can('wfcc_manage_settings')) {
		wp_die(esc_html__('You are not allowed to view WFC Cart health.', 'wfc-cart'));
	}
	?>
	<div class="wrap wfcc-admin">
		<h1><?php echo esc_html__('WFC Cart Health', 'wfc-cart'); ?></h1>
		<table class="widefat striped wfcc-admin__table">
			<caption class="screen-reader-text"><?php echo esc_html__('WFC Cart service health checks', 'wfc-cart'); ?></caption>
			<thead><tr><th><?php echo esc_html__('Service', 'wfc-cart'); ?></th><th><?php echo esc_html__('Status', 'wfc-cart'); ?></th><th><?php echo esc_html__('Detail', 'wfc-cart'); ?></th></tr></thead>
			<tbody>
				<?php foreach (wfcc_get_health_checks() as $check) : ?>
					<tr>
						<th scope="row"><?php echo esc_html($check['label']); ?></th>
						<td><span class="wfcc-status wfcc-status--<?php echo esc_attr($check['status']); ?>"><?php echo esc_html($check['status']); ?></span></td>
						<td><?php echo esc_html($check['detail']); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
