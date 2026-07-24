<?php
/**
 * Immutable operational transaction batches.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('admin_post_wfcc_create_transaction_batch', 'wfcc_handle_create_transaction_batch');

/**
 * Return whether a transaction is eligible for operational batching.
 *
 * @param string $payment_state Payment state.
 * @return bool
 */
function wfcc_transaction_is_batch_eligible($payment_state) {
	return in_array(
		sanitize_key($payment_state),
		array('succeeded', 'partially_refunded', 'refunded', 'disputed'),
		true
	);
}

/**
 * Aggregate original transaction totals by currency.
 *
 * @param int[] $transaction_ids Transaction IDs.
 * @return array<string, int>
 */
function wfcc_calculate_batch_totals($transaction_ids) {
	$totals = array();
	foreach ($transaction_ids as $transaction_id) {
		$currency = strtoupper(sanitize_key(get_post_meta(absint($transaction_id), 'wfcc_currency', true)));
		if (3 !== strlen($currency)) {
			continue;
		}
		$totals[$currency] = ($totals[$currency] ?? 0)
			+ absint(get_post_meta(absint($transaction_id), 'wfcc_amount', true));
	}
	ksort($totals);

	return $totals;
}

/**
 * Acquire a stale-safe global batch-build lock.
 *
 * @return bool
 */
function wfcc_acquire_batch_lock() {
	$key      = 'wfcc_batch_build_lock';
	$existing = get_option($key, false);
	if (is_array($existing) && !empty($existing['created']) && absint($existing['created']) < time() - 600) {
		delete_option($key);
	}

	return add_option($key, array('created' => time()), '', false);
}

/**
 * Build and seal one bounded transaction batch.
 *
 * @param array{from:string,to:string} $range Inclusive date range.
 * @param int                          $limit Maximum transactions.
 * @return int|WP_Error
 */
function wfcc_create_transaction_batch($range, $limit = 500) {
	if (!wfcc_acquire_batch_lock()) {
		return new WP_Error('wfcc_batch_locked', __('Another transaction batch is being created.', 'wfc-cart'));
	}

	try {
		$ids = wfcc_get_transaction_ids_for_report(
			$range,
			min(500, max(1, absint($limit))),
			array(
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => 'wfcc_batch_id',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => 'wfcc_payment_state',
						'value'   => array('succeeded', 'partially_refunded', 'refunded', 'disputed'),
						'compare' => 'IN',
					),
				),
			)
		);
		$ids = array_values(
			array_filter(
				$ids,
				function ($transaction_id) {
					return wfcc_transaction_is_batch_eligible(
						get_post_meta($transaction_id, 'wfcc_payment_state', true)
					);
				}
			)
		);
		if (!$ids) {
			return new WP_Error('wfcc_batch_empty', __('No eligible unbatched transactions were found.', 'wfc-cart'));
		}

		$batch_id = wp_insert_post(
			array(
				'post_type'   => 'transactionbatch',
				'post_status' => 'private',
				'post_title'  => sprintf(__('WFC batch %s', 'wfc-cart'), gmdate('Ymd-His')),
			),
			true
		);
		if (is_wp_error($batch_id)) {
			return $batch_id;
		}

		$totals = wfcc_calculate_batch_totals($ids);
		foreach ($ids as $transaction_id) {
			update_post_meta($transaction_id, 'wfcc_batch_id', absint($batch_id));
			update_post_meta($transaction_id, 'wfcc_batched_at', gmdate('c'));
		}

		$meta = array(
			'wfcc_batch_status'      => 'sealed',
			'wfcc_batch_count'       => count($ids),
			'wfcc_batch_totals'      => wp_json_encode($totals),
			'wfcc_batch_period_from' => $range['from'],
			'wfcc_batch_period_to'   => $range['to'],
			'wfcc_batch_created_at'  => gmdate('c'),
			'wfcc_batch_created_by'  => absint(get_current_user_id()),
		);
		foreach ($meta as $key => $value) {
			update_post_meta($batch_id, $key, $value);
		}
		do_action('wfcc_transaction_batch_created', absint($batch_id), $ids, $totals);

		return absint($batch_id);
	} finally {
		delete_option('wfcc_batch_build_lock');
	}
}

/**
 * Handle a capability- and nonce-protected batch creation.
 *
 * @return void
 */
function wfcc_handle_create_transaction_batch() {
	if (!current_user_can('wfcc_manage_batches')) {
		wp_die(esc_html__('You are not allowed to create transaction batches.', 'wfc-cart'));
	}
	check_admin_referer('wfcc_create_transaction_batch');

	$range  = wfcc_normalize_report_date_range(
		isset($_POST['date_from']) ? wp_unslash($_POST['date_from']) : '',
		isset($_POST['date_to']) ? wp_unslash($_POST['date_to']) : '',
		366
	);
	$result = is_wp_error($range) ? $range : wfcc_create_transaction_batch($range);

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'              => 'wfcc-batches',
				'wfcc_batch_result' => is_wp_error($result) ? 'failed' : 'created',
				'wfcc_batch_id'     => is_wp_error($result) ? 0 : absint($result),
				'wfcc_batch_code'   => is_wp_error($result) ? sanitize_key($result->get_error_code()) : '',
			),
			admin_url('admin.php')
		)
	);
	exit;
}
