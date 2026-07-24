<?php
/**
 * Bounded operational reporting helpers.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Parse and bound an inclusive reporting date range.
 *
 * @param mixed $from     Start date.
 * @param mixed $to       End date.
 * @param int   $max_days Maximum inclusive span.
 * @return array{from:string,to:string}|WP_Error
 */
function wfcc_normalize_report_date_range($from, $to, $max_days = 366) {
	$from = trim((string) $from);
	$to   = trim((string) $to);
	$start = DateTimeImmutable::createFromFormat('!Y-m-d', $from, new DateTimeZone('UTC'));
	$start_errors = DateTimeImmutable::getLastErrors();
	$start_valid  = false === $start_errors || (0 === $start_errors['warning_count'] && 0 === $start_errors['error_count']);

	$end   = DateTimeImmutable::createFromFormat('!Y-m-d', $to, new DateTimeZone('UTC'));
	$end_errors = DateTimeImmutable::getLastErrors();
	$end_valid  = false === $end_errors || (0 === $end_errors['warning_count'] && 0 === $end_errors['error_count']);
	if (!$start || !$end || !$start_valid || !$end_valid || $end < $start) {
		return new WP_Error('wfcc_report_dates_invalid', __('The reporting date range is invalid.', 'wfc-cart'));
	}

	$days = absint($end->diff($start)->format('%a')) + 1;
	if ($days > min(3660, max(1, absint($max_days)))) {
		return new WP_Error('wfcc_report_range_too_large', __('The reporting date range is too large.', 'wfc-cart'));
	}

	return array(
		'from' => $start->format('Y-m-d'),
		'to'   => $end->format('Y-m-d'),
	);
}

/**
 * Return bounded transaction IDs for a date range.
 *
 * @param array{from:string,to:string} $range Date range.
 * @param int                          $limit Maximum records.
 * @param array<string, mixed>         $extra Additional query arguments.
 * @return int[]
 */
function wfcc_get_transaction_ids_for_report($range, $limit = 5000, $extra = array()) {
	$args = array(
		'post_type'              => 'transaction',
		'post_status'            => array('private', 'publish', 'draft'),
		'fields'                 => 'ids',
		'posts_per_page'         => min(5000, max(1, absint($limit))),
		'orderby'                => 'date',
		'order'                  => 'ASC',
		'no_found_rows'          => true,
		'suppress_filters'       => true,
		'update_post_meta_cache' => true,
		'date_query'             => array(
			array(
				'after'     => $range['from'] . ' 00:00:00',
				'before'    => $range['to'] . ' 23:59:59',
				'inclusive' => true,
			),
		),
	);

	$ids = get_posts(array_merge($args, is_array($extra) ? $extra : array()));

	return array_values(array_map('absint', is_array($ids) ? $ids : array()));
}

/**
 * Build privacy-minimised operational aggregates from transaction IDs.
 *
 * @param int[] $transaction_ids Transaction IDs.
 * @return array<string, mixed>
 */
function wfcc_build_operational_report($transaction_ids) {
	$report = array(
		'transaction_count' => 0,
		'payment_states'    => array(),
		'salesforce_states' => array(),
		'currency_totals'   => array(),
		'receipt_count'     => 0,
		'batched_count'     => 0,
	);

	foreach ($transaction_ids as $transaction_id) {
		$transaction_id = absint($transaction_id);
		if (!$transaction_id) {
			continue;
		}

		++$report['transaction_count'];
		$payment_state = sanitize_key(get_post_meta($transaction_id, 'wfcc_payment_state', true)) ?: 'unknown';
		$salesforce_state = sanitize_key(get_post_meta($transaction_id, 'wfcc_salesforce_state', true)) ?: 'not_queued';
		$currency = strtoupper(sanitize_key(get_post_meta($transaction_id, 'wfcc_currency', true)));
		$amount   = absint(get_post_meta($transaction_id, 'wfcc_amount', true));

		$report['payment_states'][$payment_state] = ($report['payment_states'][$payment_state] ?? 0) + 1;
		$report['salesforce_states'][$salesforce_state] = ($report['salesforce_states'][$salesforce_state] ?? 0) + 1;
		if (3 === strlen($currency)) {
			$report['currency_totals'][$currency] = ($report['currency_totals'][$currency] ?? 0) + $amount;
		}
		if (get_post_meta($transaction_id, 'wfcc_receipt_number', true)) {
			++$report['receipt_count'];
		}
		if (get_post_meta($transaction_id, 'wfcc_batch_id', true)) {
			++$report['batched_count'];
		}
	}

	ksort($report['payment_states']);
	ksort($report['salesforce_states']);
	ksort($report['currency_totals']);

	return $report;
}

/**
 * Format a minor-unit report amount.
 *
 * @param int    $amount   Minor-unit amount.
 * @param string $currency ISO currency.
 * @return string
 */
function wfcc_format_report_amount($amount, $currency) {
	$value = wfcc_amount_from_minor_units(absint($amount), $currency);

	return strtoupper($currency) . ' ' . number_format((float) $value, wfcc_currency_exponent($currency), '.', ',');
}
