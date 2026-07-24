<?php
/**
 * WFC Cart administration navigation and dashboard.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('admin_menu', 'wfcc_register_admin_menu', 1);

/**
 * Register WFC Cart administration pages.
 *
 * @return void
 */
function wfcc_register_admin_menu() {
	add_menu_page(
		__('WFC Cart', 'wfc-cart'),
		__('WFC Cart', 'wfc-cart'),
		'wfcc_view_transactions',
		'wfcc',
		'wfcc_render_dashboard_page',
		'dashicons-cart',
		74
	);
	add_submenu_page('wfcc', __('Dashboard', 'wfc-cart'), __('Dashboard', 'wfc-cart'), 'wfcc_view_transactions', 'wfcc', 'wfcc_render_dashboard_page');
	add_submenu_page('wfcc', __('Transactions', 'wfc-cart'), __('Transactions', 'wfc-cart'), 'wfcc_view_transactions', 'edit.php?post_type=transaction');
	add_submenu_page('wfcc', __('Delivery Queue', 'wfc-cart'), __('Delivery Queue', 'wfc-cart'), 'wfcc_view_transactions', 'wfcc-delivery-queue', 'wfcc_render_delivery_queue_page');
	add_submenu_page('wfcc', __('Health', 'wfc-cart'), __('Health', 'wfc-cart'), 'wfcc_manage_settings', 'wfcc-health', 'wfcc_render_health_page');
	add_submenu_page('wfcc', __('Settings', 'wfc-cart'), __('Settings', 'wfc-cart'), 'wfcc_manage_settings', 'wfcc-settings', 'wfcc_render_settings_page');
}

/**
 * Render the WFC Cart dashboard.
 *
 * @return void
 */
function wfcc_render_dashboard_page() {
	if (!current_user_can('wfcc_view_transactions')) {
		wp_die(esc_html__('You are not allowed to view WFC Cart.', 'wfc-cart'));
	}

	$transaction_counts = wp_count_posts('transaction');
	$transaction_total  = $transaction_counts ? array_sum(array_map('intval', (array) $transaction_counts)) : 0;
	?>
	<div class="wrap wfcc-admin">
		<h1><?php echo esc_html__('WFC Cart', 'wfc-cart'); ?></h1>
		<div class="wfcc-admin__cards">
			<section class="wfcc-admin__card">
				<h2><?php echo esc_html__('Transactions', 'wfc-cart'); ?></h2>
				<p class="wfcc-admin__metric"><?php echo esc_html(number_format_i18n($transaction_total)); ?></p>
			</section>
			<section class="wfcc-admin__card">
				<h2><?php echo esc_html__('Delivery queue', 'wfc-cart'); ?></h2>
				<p class="wfcc-admin__metric"><?php echo esc_html(number_format_i18n(wfcc_count_pending_deliveries())); ?></p>
			</section>
			<section class="wfcc-admin__card">
				<h2><?php echo esc_html__('Version', 'wfc-cart'); ?></h2>
				<p class="wfcc-admin__metric"><?php echo esc_html(WFCC_VERSION); ?></p>
			</section>
		</div>
		<p><?php echo esc_html__('Configure and validate Stripe and Salesforce before enabling live WFC Cart checkout.', 'wfc-cart'); ?></p>
	</div>
	<?php
}
