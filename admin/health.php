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
	return array(
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
			'status' => wfcc_get_secret('salesforce_client_secret', 'WFCC_SALESFORCE_CLIENT_SECRET', 'WFCC_SALESFORCE_CLIENT_SECRET') ? 'ok' : 'warning',
			'detail' => wfcc_get_secret('salesforce_client_secret', 'WFCC_SALESFORCE_CLIENT_SECRET', 'WFCC_SALESFORCE_CLIENT_SECRET') ? __('Client secret configured', 'wfc-cart') : __('Not configured', 'wfc-cart'),
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
