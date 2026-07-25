<?php
/**
 * Privacy-minimised operational CSV exports.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('admin_post_wfcc_export_transactions', 'wfcc_handle_transaction_export');

/**
 * Prevent spreadsheet formula execution in a CSV text cell.
 *
 * @param mixed $value Cell value.
 * @return string
 */
function wfcc_sanitize_csv_cell($value) {
	$value = is_scalar($value) ? (string) $value : '';
	$value = str_replace(array("\r", "\n", "\0"), ' ', $value);

	return preg_match('/^\s*[=+\-@]/', $value) ? "'" . $value : $value;
}

/**
 * Return the fixed privacy-minimised export headers.
 *
 * @return string[]
 */
function wfcc_transaction_export_headers() {
	return array(
		'transaction_key',
		'created_at',
		'payment_state',
		'crm_mode',
		'crm_state',
		'salesforce_state',
		'amount_minor',
		'currency',
		'package_id',
		'frequency',
		'receipt_number',
		'receipt_delivery_state',
		'batch_id',
		'fund_code',
		'external_reference',
	);
}

/**
 * Build one fixed export row without donor details or payment tokens.
 *
 * @param int $transaction_id Transaction ID.
 * @return string[]
 */
function wfcc_build_transaction_export_row($transaction_id) {
	$transaction_id = absint($transaction_id);

	return array_map(
		'wfcc_sanitize_csv_cell',
		array(
			wfcc_sanitize_transaction_key(get_post_meta($transaction_id, 'wfcc_transaction_key', true)),
			sanitize_text_field(get_post_meta($transaction_id, 'wfcc_created_at', true)),
			sanitize_key(get_post_meta($transaction_id, 'wfcc_payment_state', true)),
			wfcc_get_transaction_crm_mode($transaction_id),
			wfcc_get_transaction_crm_state($transaction_id),
			sanitize_key(get_post_meta($transaction_id, 'wfcc_salesforce_state', true)),
			(string) absint(get_post_meta($transaction_id, 'wfcc_amount', true)),
			strtoupper(sanitize_key(get_post_meta($transaction_id, 'wfcc_currency', true))),
			sanitize_key(get_post_meta($transaction_id, 'wfcc_package_id', true)),
			sanitize_key(get_post_meta($transaction_id, 'wfcc_frequency', true)),
			sanitize_text_field(get_post_meta($transaction_id, 'wfcc_receipt_number', true)),
			sanitize_key(get_post_meta($transaction_id, 'wfcc_receipt_delivery_state', true)),
			(string) absint(get_post_meta($transaction_id, 'wfcc_batch_id', true)),
			sanitize_key(get_post_meta($transaction_id, 'wfcc_operational_fund_code', true)),
			substr(sanitize_text_field(get_post_meta($transaction_id, 'wfcc_external_reference', true)), 0, 100),
		)
	);
}

/**
 * Stream a nonce-protected bounded CSV export.
 *
 * @return void
 */
function wfcc_handle_transaction_export() {
	if (!current_user_can('wfcc_export_transactions')) {
		wp_die(esc_html__('You are not allowed to export WFC transactions.', 'wfc-cart'));
	}
	check_admin_referer('wfcc_export_transactions');

	$range = wfcc_normalize_report_date_range(
		isset($_POST['date_from']) ? wp_unslash($_POST['date_from']) : '',
		isset($_POST['date_to']) ? wp_unslash($_POST['date_to']) : '',
		366
	);
	if (is_wp_error($range)) {
		wp_die(esc_html($range->get_error_message()));
	}

	$ids      = wfcc_get_transaction_ids_for_report($range, 5000);
	$filename = sprintf('wfc-transactions-%s-to-%s.csv', $range['from'], $range['to']);

	nocache_headers();
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	header('X-Content-Type-Options: nosniff');

	$output = fopen('php://output', 'w');
	if (false === $output) {
		wp_die(esc_html__('The transaction export could not be opened.', 'wfc-cart'));
	}
	fputcsv($output, wfcc_transaction_export_headers(), ',', '"', '');
	foreach ($ids as $transaction_id) {
		fputcsv($output, wfcc_build_transaction_export_row($transaction_id), ',', '"', '');
	}
	fclose($output);
	exit;
}
