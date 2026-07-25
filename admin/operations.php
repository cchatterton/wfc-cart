<?php
/**
 * WFC-native reports, exports, imports, batches, and receipts administration.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Resolve a safe default and requested admin date range.
 *
 * @param int $default_days Default number of days.
 * @return array{from:string,to:string}
 */
function wfcc_get_admin_report_range($default_days = 30) {
	$to_default   = gmdate('Y-m-d');
	$from_default = gmdate('Y-m-d', time() - ((max(1, absint($default_days)) - 1) * DAY_IN_SECONDS));
	$from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : $from_default;
	$to   = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : $to_default;
	$range = wfcc_normalize_report_date_range($from, $to, 366);

	return is_wp_error($range)
		? array('from' => $from_default, 'to' => $to_default)
		: $range;
}

/**
 * Render reusable date range inputs.
 *
 * @param array{from:string,to:string} $range Range.
 * @return void
 */
function wfcc_render_date_range_inputs($range) {
	echo '<label>' . esc_html__('From', 'wfc-cart') . ' <input type="date" name="date_from" value="' . esc_attr($range['from']) . '" required></label> ';
	echo '<label>' . esc_html__('To', 'wfc-cart') . ' <input type="date" name="date_to" value="' . esc_attr($range['to']) . '" required></label> ';
}

/**
 * Render one query-string operation result without exposing submitted data.
 *
 * @param string $result_key Result query key.
 * @param string $success    Success value.
 * @param string $message    Success message.
 * @param string $code_key   Optional safe error-code query key.
 * @return void
 */
function wfcc_render_operation_notice($result_key, $success, $message, $code_key = '') {
	$result = isset($_GET[$result_key]) ? sanitize_key(wp_unslash($_GET[$result_key])) : '';
	if ('' === $result) {
		return;
	}

	if ($success === $result) {
		echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
		return;
	}

	$code = $code_key && isset($_GET[$code_key]) ? sanitize_key(wp_unslash($_GET[$code_key])) : 'operation_failed';
	echo '<div class="notice notice-error"><p>'
		. esc_html(sprintf(__('The operation failed (%s). No sensitive response data was stored.', 'wfc-cart'), $code))
		. '</p></div>';
}

/**
 * Render operational reports.
 *
 * @return void
 */
function wfcc_render_reports_page() {
	if (!current_user_can('wfcc_view_reports')) {
		wp_die(esc_html__('You are not allowed to view WFC reports.', 'wfc-cart'));
	}

	$range  = wfcc_get_admin_report_range();
	$ids    = wfcc_get_transaction_ids_for_report($range, 5000);
	$report = wfcc_build_operational_report($ids);
	?>
	<div class="wrap wfcc-admin">
		<h1><?php echo esc_html__('WFC Cart Reports', 'wfc-cart'); ?></h1>
		<form method="get">
			<input type="hidden" name="page" value="wfcc-reports">
			<?php wfcc_render_date_range_inputs($range); ?>
			<button class="button" type="submit"><?php echo esc_html__('Apply', 'wfc-cart'); ?></button>
		</form>
		<div class="wfcc-admin__cards">
			<section class="wfcc-admin__card"><h2><?php echo esc_html__('Transactions', 'wfc-cart'); ?></h2><p class="wfcc-admin__metric"><?php echo esc_html(number_format_i18n($report['transaction_count'])); ?></p></section>
			<section class="wfcc-admin__card"><h2><?php echo esc_html__('Receipts', 'wfc-cart'); ?></h2><p class="wfcc-admin__metric"><?php echo esc_html(number_format_i18n($report['receipt_count'])); ?></p></section>
			<section class="wfcc-admin__card"><h2><?php echo esc_html__('Batched', 'wfc-cart'); ?></h2><p class="wfcc-admin__metric"><?php echo esc_html(number_format_i18n($report['batched_count'])); ?></p></section>
		</div>
		<h2><?php echo esc_html__('Original transaction totals', 'wfc-cart'); ?></h2>
		<table class="widefat striped wfcc-admin__table"><caption class="screen-reader-text"><?php echo esc_html__('Original transaction totals by currency', 'wfc-cart'); ?></caption><thead><tr><th><?php echo esc_html__('Currency', 'wfc-cart'); ?></th><th><?php echo esc_html__('Total', 'wfc-cart'); ?></th></tr></thead><tbody>
		<?php if (!$report['currency_totals']) : ?>
			<tr><td colspan="2"><?php echo esc_html__('No transactions in this period.', 'wfc-cart'); ?></td></tr>
		<?php else : ?>
			<?php foreach ($report['currency_totals'] as $currency => $amount) : ?>
				<tr><th scope="row"><?php echo esc_html($currency); ?></th><td><?php echo esc_html(wfcc_format_report_amount($amount, $currency)); ?></td></tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody></table>
		<div class="wfcc-admin__cards">
			<section class="wfcc-admin__card">
				<h2><?php echo esc_html__('Payment states', 'wfc-cart'); ?></h2>
				<?php wfcc_render_report_counts($report['payment_states']); ?>
			</section>
			<section class="wfcc-admin__card">
				<h2><?php echo esc_html__('CRM states', 'wfc-cart'); ?></h2>
				<?php wfcc_render_report_counts($report['crm_states']); ?>
			</section>
		</div>
		<p class="description"><?php echo esc_html__('Reports are bounded to 5,000 transactions and contain no donor details.', 'wfc-cart'); ?></p>
	</div>
	<?php
}

/**
 * Render a state/count definition list.
 *
 * @param array<string, int> $counts Counts.
 * @return void
 */
function wfcc_render_report_counts($counts) {
	echo '<dl>';
	if (!$counts) {
		echo '<dt>' . esc_html__('No data', 'wfc-cart') . '</dt><dd>0</dd>';
	} else {
		foreach ($counts as $state => $count) {
			echo '<dt>' . esc_html($state) . '</dt><dd>' . esc_html(number_format_i18n($count)) . '</dd>';
		}
	}
	echo '</dl>';
}

/**
 * Render the bounded export screen.
 *
 * @return void
 */
function wfcc_render_exports_page() {
	if (!current_user_can('wfcc_export_transactions')) {
		wp_die(esc_html__('You are not allowed to export WFC transactions.', 'wfc-cart'));
	}
	$range = wfcc_get_admin_report_range();
	?>
	<div class="wrap wfcc-admin">
		<h1><?php echo esc_html__('WFC Cart Exports', 'wfc-cart'); ?></h1>
		<p><?php echo esc_html__('Download up to 5,000 protected transaction summaries. Donor details and reusable payment identifiers are excluded.', 'wfc-cart'); ?></p>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="wfcc_export_transactions">
			<?php wp_nonce_field('wfcc_export_transactions'); ?>
			<?php wfcc_render_date_range_inputs($range); ?>
			<button class="button button-primary" type="submit"><?php echo esc_html__('Download CSV', 'wfc-cart'); ?></button>
		</form>
	</div>
	<?php
}

/**
 * Render the validation-first metadata import screen.
 *
 * @return void
 */
function wfcc_render_imports_page() {
	if (!current_user_can('wfcc_import_operations')) {
		wp_die(esc_html__('You are not allowed to import operational metadata.', 'wfc-cart'));
	}
	?>
	<div class="wrap wfcc-admin">
		<h1><?php echo esc_html__('WFC Cart Imports', 'wfc-cart'); ?></h1>
		<?php
		$count = isset($_GET['wfcc_import_count']) ? absint($_GET['wfcc_import_count']) : 0;
		wfcc_render_operation_notice(
			'wfcc_import_result',
			'imported',
			sprintf(__('%d transaction rows were updated.', 'wfc-cart'), $count),
			'wfcc_import_code'
		);
		?>
		<p><?php echo esc_html__('Attach a fund code or external operational reference to existing transactions. This import cannot create transactions or change payment amounts or states.', 'wfc-cart'); ?></p>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="wfcc_import_operational_metadata">
			<?php wp_nonce_field('wfcc_import_operational_metadata'); ?>
			<label for="wfcc-operational-csv"><strong><?php echo esc_html__('CSV data', 'wfc-cart'); ?></strong></label>
			<textarea id="wfcc-operational-csv" class="large-text code" rows="16" name="operational_csv" required>transaction_key,fund_code,reference</textarea>
			<p class="description"><?php echo esc_html__('Maximum 500 rows and 256 KB. Every transaction key is validated before any row is changed.', 'wfc-cart'); ?></p>
			<button class="button button-primary" type="submit"><?php echo esc_html__('Validate and import', 'wfc-cart'); ?></button>
		</form>
	</div>
	<?php
}

/**
 * Render immutable batch administration.
 *
 * @return void
 */
function wfcc_render_batches_page() {
	if (!current_user_can('wfcc_manage_batches')) {
		wp_die(esc_html__('You are not allowed to manage transaction batches.', 'wfc-cart'));
	}
	$range   = wfcc_get_admin_report_range(7);
	$batches = get_posts(
		array(
			'post_type'      => 'transactionbatch',
			'post_status'    => array('private', 'publish', 'draft'),
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
	?>
	<div class="wrap wfcc-admin">
		<h1><?php echo esc_html__('WFC Cart Batch Management', 'wfc-cart'); ?></h1>
		<?php
		$batch_id = isset($_GET['wfcc_batch_id']) ? absint($_GET['wfcc_batch_id']) : 0;
		wfcc_render_operation_notice(
			'wfcc_batch_result',
			'created',
			sprintf(__('Batch #%d was created and sealed.', 'wfc-cart'), $batch_id),
			'wfcc_batch_code'
		);
		?>
		<p><?php echo esc_html__('Create an immutable batch from up to 500 successful, unbatched transactions in the selected period.', 'wfc-cart'); ?></p>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="wfcc_create_transaction_batch">
			<?php wp_nonce_field('wfcc_create_transaction_batch'); ?>
			<?php wfcc_render_date_range_inputs($range); ?>
			<button class="button button-primary" type="submit"><?php echo esc_html__('Create and seal batch', 'wfc-cart'); ?></button>
		</form>
		<h2><?php echo esc_html__('Recent batches', 'wfc-cart'); ?></h2>
		<table class="widefat striped wfcc-admin__table"><caption class="screen-reader-text"><?php echo esc_html__('Recent sealed transaction batches', 'wfc-cart'); ?></caption><thead><tr><th><?php echo esc_html__('Batch', 'wfc-cart'); ?></th><th><?php echo esc_html__('Period', 'wfc-cart'); ?></th><th><?php echo esc_html__('Transactions', 'wfc-cart'); ?></th><th><?php echo esc_html__('Original totals', 'wfc-cart'); ?></th><th><?php echo esc_html__('Status', 'wfc-cart'); ?></th></tr></thead><tbody>
		<?php if (!$batches) : ?>
			<tr><td colspan="5"><?php echo esc_html__('No batches created.', 'wfc-cart'); ?></td></tr>
		<?php else : ?>
			<?php foreach ($batches as $batch) : ?>
				<?php
				$totals = json_decode((string) get_post_meta($batch->ID, 'wfcc_batch_totals', true), true);
				$total_labels = array();
				foreach (is_array($totals) ? $totals : array() as $currency => $amount) {
					$total_labels[] = wfcc_format_report_amount($amount, $currency);
				}
				?>
				<tr>
					<th scope="row"><?php echo esc_html($batch->post_title); ?></th>
					<td><?php echo esc_html(get_post_meta($batch->ID, 'wfcc_batch_period_from', true) . ' – ' . get_post_meta($batch->ID, 'wfcc_batch_period_to', true)); ?></td>
					<td><?php echo esc_html(number_format_i18n(absint(get_post_meta($batch->ID, 'wfcc_batch_count', true)))); ?></td>
					<td><?php echo esc_html(implode(', ', $total_labels)); ?></td>
					<td><?php echo esc_html(get_post_meta($batch->ID, 'wfcc_batch_status', true)); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody></table>
	</div>
	<?php
}

/**
 * Render receipt operations without exposing donor addresses.
 *
 * @return void
 */
function wfcc_render_receipts_page() {
	if (!current_user_can('wfcc_manage_receipts')) {
		wp_die(esc_html__('You are not allowed to manage receipts.', 'wfc-cart'));
	}
	$transactions = get_posts(
		array(
			'post_type'      => 'transaction',
			'post_status'    => array('private', 'publish', 'draft'),
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => 'wfcc_receipt_number',
					'compare' => 'EXISTS',
				),
			),
		)
	);
	?>
	<div class="wrap wfcc-admin">
		<h1><?php echo esc_html__('WFC Cart Receipts', 'wfc-cart'); ?></h1>
		<?php
		wfcc_render_operation_notice(
			'wfcc_receipt_result',
			'sent',
			__('The receipt was sent.', 'wfc-cart')
		);
		?>
		<table class="widefat striped wfcc-admin__table"><caption class="screen-reader-text"><?php echo esc_html__('Issued WFC transaction receipts', 'wfc-cart'); ?></caption><thead><tr><th><?php echo esc_html__('Receipt', 'wfc-cart'); ?></th><th><?php echo esc_html__('Transaction', 'wfc-cart'); ?></th><th><?php echo esc_html__('Amount', 'wfc-cart'); ?></th><th><?php echo esc_html__('Issued', 'wfc-cart'); ?></th><th><?php echo esc_html__('Delivery', 'wfc-cart'); ?></th><th><?php echo esc_html__('Action', 'wfc-cart'); ?></th></tr></thead><tbody>
		<?php if (!$transactions) : ?>
			<tr><td colspan="6"><?php echo esc_html__('No receipts issued.', 'wfc-cart'); ?></td></tr>
		<?php else : ?>
			<?php foreach ($transactions as $transaction) : ?>
				<?php $currency = get_post_meta($transaction->ID, 'wfcc_currency', true); ?>
				<tr>
					<th scope="row"><?php echo esc_html(get_post_meta($transaction->ID, 'wfcc_receipt_number', true)); ?></th>
					<td><a href="<?php echo esc_url(get_edit_post_link($transaction->ID)); ?>"><?php echo esc_html($transaction->post_title ?: '#' . $transaction->ID); ?></a></td>
					<td><?php echo esc_html(wfcc_format_report_amount(get_post_meta($transaction->ID, 'wfcc_amount', true), $currency)); ?></td>
					<td><?php echo esc_html(get_post_meta($transaction->ID, 'wfcc_receipt_issued_at', true)); ?></td>
					<td><?php echo esc_html(get_post_meta($transaction->ID, 'wfcc_receipt_delivery_state', true)); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
							<input type="hidden" name="action" value="wfcc_resend_receipt">
							<input type="hidden" name="transaction_id" value="<?php echo esc_attr($transaction->ID); ?>">
							<?php wp_nonce_field('wfcc_resend_receipt_' . $transaction->ID); ?>
							<button class="button button-small" type="submit"><?php echo esc_html__('Send receipt', 'wfc-cart'); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody></table>
	</div>
	<?php
}
