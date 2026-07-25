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
	if (wfcc_uses_salesforce_crm()) {
		add_submenu_page('wfcc', __('Delivery Queue', 'wfc-cart'), __('Delivery Queue', 'wfc-cart'), 'wfcc_view_transactions', 'wfcc-delivery-queue', 'wfcc_render_delivery_queue_page');
	}
	add_submenu_page('wfcc', __('Reports', 'wfc-cart'), __('Reports', 'wfc-cart'), 'wfcc_view_reports', 'wfcc-reports', 'wfcc_render_reports_page');
	add_submenu_page('wfcc', __('Exports', 'wfc-cart'), __('Exports', 'wfc-cart'), 'wfcc_export_transactions', 'wfcc-exports', 'wfcc_render_exports_page');
	add_submenu_page('wfcc', __('Imports', 'wfc-cart'), __('Imports', 'wfc-cart'), 'wfcc_import_operations', 'wfcc-imports', 'wfcc_render_imports_page');
	add_submenu_page('wfcc', __('Batch Management', 'wfc-cart'), __('Batch Management', 'wfc-cart'), 'wfcc_manage_batches', 'wfcc-batches', 'wfcc_render_batches_page');
	add_submenu_page('wfcc', __('Receipts', 'wfc-cart'), __('Receipts', 'wfc-cart'), 'wfcc_manage_receipts', 'wfcc-receipts', 'wfcc_render_receipts_page');
	add_submenu_page('wfcc', __('Health', 'wfc-cart'), __('Health', 'wfc-cart'), 'wfcc_manage_settings', 'wfcc-health', 'wfcc_render_health_page');
	add_submenu_page('wfcc', __('Production Readiness', 'wfc-cart'), __('Production Readiness', 'wfc-cart'), 'wfcc_manage_settings', 'wfcc-readiness', 'wfcc_render_readiness_page');
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
	$range = array(
		'from' => gmdate('Y-m-d', time() - (29 * DAY_IN_SECONDS)),
		'to'   => gmdate('Y-m-d'),
	);
	$report = wfcc_build_operational_report(wfcc_get_transaction_ids_for_report($range, 5000));
	$succeeded = 0;
	foreach (array('succeeded', 'partially_refunded', 'refunded', 'disputed') as $successful_state) {
		$succeeded += absint($report['payment_states'][$successful_state] ?? 0);
	}
	$success_rate = $report['transaction_count'] > 0
		? round(($succeeded / $report['transaction_count']) * 100, 1)
		: 0;
	$readiness = wfcc_summarize_readiness(
		wfcc_evaluate_readiness_context(wfcc_get_readiness_context())
	);
	$release = wfcc_evaluate_release_governance(
		get_option(WFCC_RELEASE_GOVERNANCE_OPTION, array()),
		get_option('wfcc_readiness_last_run', array())
	);
	?>
	<div class="wrap wfcc-admin">
		<h1><?php echo esc_html__('WFC Cart', 'wfc-cart'); ?></h1>
		<div class="wfcc-admin__cards">
			<section class="wfcc-admin__card">
				<h2><?php echo esc_html__('Transactions', 'wfc-cart'); ?></h2>
				<p class="wfcc-admin__metric"><?php echo esc_html(number_format_i18n($transaction_total)); ?></p>
			</section>
			<?php if (wfcc_uses_salesforce_crm()) : ?>
				<section class="wfcc-admin__card">
					<h2><?php echo esc_html__('Delivery queue', 'wfc-cart'); ?></h2>
					<p class="wfcc-admin__metric"><?php echo esc_html(number_format_i18n(wfcc_count_pending_deliveries())); ?></p>
				</section>
			<?php else : ?>
				<section class="wfcc-admin__card">
					<h2><?php echo esc_html__('CRM data location', 'wfc-cart'); ?></h2>
					<p class="wfcc-admin__metric"><?php echo esc_html__('Gravity Forms', 'wfc-cart'); ?></p>
				</section>
			<?php endif; ?>
			<section class="wfcc-admin__card">
				<h2><?php echo esc_html__('30-day payment success', 'wfc-cart'); ?></h2>
				<p class="wfcc-admin__metric"><?php echo esc_html(number_format_i18n($success_rate, 1) . '%'); ?></p>
			</section>
			<section class="wfcc-admin__card">
				<h2><?php echo esc_html__('30-day receipts', 'wfc-cart'); ?></h2>
				<p class="wfcc-admin__metric"><?php echo esc_html(number_format_i18n($report['receipt_count'])); ?></p>
			</section>
			<section class="wfcc-admin__card">
				<h2><?php echo esc_html__('Production readiness', 'wfc-cart'); ?></h2>
				<p class="wfcc-admin__metric"><?php echo esc_html($readiness['status']); ?></p>
				<?php if (current_user_can('wfcc_manage_settings')) : ?>
					<p><a href="<?php echo esc_url(admin_url('admin.php?page=wfcc-readiness')); ?>"><?php echo esc_html__('View audit', 'wfc-cart'); ?></a></p>
				<?php endif; ?>
			</section>
			<section class="wfcc-admin__card">
				<h2><?php echo esc_html__('Version', 'wfc-cart'); ?></h2>
				<p class="wfcc-admin__metric"><?php echo esc_html(WFCC_VERSION); ?></p>
			</section>
			<section class="wfcc-admin__card">
				<h2><?php echo esc_html__('Release approval', 'wfc-cart'); ?></h2>
				<p class="wfcc-admin__metric"><?php echo esc_html($release['status']); ?></p>
				<p><?php echo esc_html(sprintf(__('%1$d of %2$d gates approved', 'wfc-cart'), $release['approved'], $release['total'])); ?></p>
			</section>
		</div>
		<p>
			<?php
			echo esc_html(
				wfcc_uses_salesforce_crm()
					? __('Configure and validate Stripe and Salesforce before enabling live WFC Cart checkout.', 'wfc-cart')
					: __('Configure and validate Stripe and Gravity Forms before enabling live WFC Cart checkout. Donor PII remains in the single Gravity Forms cart entry.', 'wfc-cart')
			);
			?>
		</p>
	</div>
	<?php
}
