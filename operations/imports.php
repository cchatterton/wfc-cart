<?php
/**
 * Validation-first operational metadata imports.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('admin_post_wfcc_import_operational_metadata', 'wfcc_handle_operational_import');

/**
 * Parse a bounded CSV document into safe metadata updates.
 *
 * This format cannot create transactions or alter payment amounts or states.
 *
 * @param mixed $csv CSV text.
 * @return array<int, array{transaction_key:string,fund_code:string,reference:string}>|WP_Error
 */
function wfcc_parse_operational_import_csv($csv) {
	if (!is_string($csv) || '' === trim($csv) || strlen($csv) > 262144) {
		return new WP_Error('wfcc_import_size_invalid', __('The operational CSV is empty or too large.', 'wfc-cart'));
	}

	$stream = fopen('php://temp', 'w+');
	if (false === $stream) {
		return new WP_Error('wfcc_import_stream_failed', __('The operational CSV could not be read.', 'wfc-cart'));
	}
	fwrite($stream, $csv);
	rewind($stream);

	$header = fgetcsv($stream, 0, ',', '"', '');
	$header = is_array($header) ? array_map('sanitize_key', $header) : array();
	if (array('transaction_key', 'fund_code', 'reference') !== $header) {
		fclose($stream);
		return new WP_Error(
			'wfcc_import_header_invalid',
			__('CSV headers must be transaction_key,fund_code,reference in that order.', 'wfc-cart')
		);
	}

	$rows = array();
	$seen = array();
	while (false !== ($values = fgetcsv($stream, 0, ',', '"', ''))) {
		if (array(null) === $values || array('') === $values) {
			continue;
		}
		if (3 !== count($values) || count($rows) >= 500) {
			fclose($stream);
			return new WP_Error('wfcc_import_rows_invalid', __('The operational CSV has invalid rows or exceeds 500 rows.', 'wfc-cart'));
		}

		$key       = wfcc_sanitize_transaction_key(trim((string) $values[0]));
		$fund_code = substr(sanitize_key($values[1]), 0, 50);
		$reference = wfcc_sanitize_operational_reference($values[2]);
		if ('' !== trim((string) $values[2]) && '' === $reference) {
			fclose($stream);
			return new WP_Error(
				'wfcc_import_reference_invalid',
				__('Operational references must be opaque identifiers and must not contain donor details.', 'wfc-cart')
			);
		}
		if ('' === $key || ('' === $fund_code && '' === $reference) || isset($seen[$key])) {
			fclose($stream);
			return new WP_Error('wfcc_import_row_invalid', __('The operational CSV contains an invalid or duplicate transaction row.', 'wfc-cart'));
		}

		$seen[$key] = true;
		$rows[] = array(
			'transaction_key' => $key,
			'fund_code'       => $fund_code,
			'reference'       => $reference,
		);
	}
	fclose($stream);

	if (!$rows) {
		return new WP_Error('wfcc_import_empty', __('The operational CSV contains no data rows.', 'wfc-cart'));
	}

	return $rows;
}

/**
 * Validate all targets before applying an idempotent metadata import.
 *
 * @param array<int, array{transaction_key:string,fund_code:string,reference:string}> $rows Rows.
 * @return int|WP_Error
 */
function wfcc_apply_operational_import($rows) {
	if (!is_array($rows) || !$rows) {
		return new WP_Error('wfcc_import_empty', __('There are no operational rows to import.', 'wfc-cart'));
	}

	$resolved = array();
	foreach ($rows as $row) {
		$transaction_id = wfcc_find_transaction('wfcc_transaction_key', $row['transaction_key']);
		if (!$transaction_id) {
			return new WP_Error(
				'wfcc_import_transaction_missing',
				__('At least one imported transaction key was not found. No rows were changed.', 'wfc-cart')
			);
		}
		$resolved[] = array($transaction_id, $row);
	}

	foreach ($resolved as $resolved_row) {
		list($transaction_id, $row) = $resolved_row;
		if ('' !== $row['fund_code']) {
			update_post_meta($transaction_id, 'wfcc_operational_fund_code', $row['fund_code']);
		}
		if ('' !== $row['reference']) {
			update_post_meta($transaction_id, 'wfcc_external_reference', $row['reference']);
		}
		update_post_meta($transaction_id, 'wfcc_operational_imported_at', gmdate('c'));
		update_post_meta($transaction_id, 'wfcc_operational_imported_by', absint(get_current_user_id()));
	}

	return count($resolved);
}

/**
 * Handle a nonce-protected operational CSV import.
 *
 * @return void
 */
function wfcc_handle_operational_import() {
	if (!current_user_can('wfcc_import_operations')) {
		wp_die(esc_html__('You are not allowed to import operational metadata.', 'wfc-cart'));
	}
	check_admin_referer('wfcc_import_operational_metadata');

	$csv    = isset($_POST['operational_csv']) ? wp_unslash($_POST['operational_csv']) : '';
	$rows   = wfcc_parse_operational_import_csv($csv);
	$result = is_wp_error($rows) ? $rows : wfcc_apply_operational_import($rows);
	$status = is_wp_error($result) ? 'failed' : 'imported';
	$count  = is_wp_error($result) ? 0 : absint($result);
	$code   = is_wp_error($result) ? sanitize_key($result->get_error_code()) : '';

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'               => 'wfcc-imports',
				'wfcc_import_result' => $status,
				'wfcc_import_count'  => $count,
				'wfcc_import_code'   => $code,
			),
			admin_url('admin.php')
		)
	);
	exit;
}
