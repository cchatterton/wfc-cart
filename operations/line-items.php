<?php
/**
 * Fixed WFC transaction line-item contract.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('wfcc_checkout_completed', 'wfcc_create_checkout_line_item', 5, 3);

/**
 * Return supported line-item types.
 *
 * @return string[]
 */
function wfcc_line_item_types() {
	return array('donation', 'product', 'event', 'shipping', 'fee', 'adjustment');
}

/**
 * Sanitise an adapter-provided line item into the fixed WFC schema.
 *
 * @param mixed $candidate Candidate line item.
 * @return array<string, int|string>|WP_Error
 */
function wfcc_sanitize_transaction_line_item($candidate) {
	if (!is_array($candidate)) {
		return new WP_Error('wfcc_line_item_invalid', __('The transaction line item is invalid.', 'wfc-cart'));
	}

	$type = isset($candidate['type']) ? sanitize_key($candidate['type']) : '';
	if (!in_array($type, wfcc_line_item_types(), true)) {
		return new WP_Error('wfcc_line_item_type_invalid', __('The transaction line-item type is not supported.', 'wfc-cart'));
	}

	$currency = isset($candidate['currency']) ? strtoupper(sanitize_key($candidate['currency'])) : '';
	$label    = isset($candidate['label']) ? sanitize_text_field($candidate['label']) : '';
	$quantity = isset($candidate['quantity']) ? absint($candidate['quantity']) : 1;
	if (3 !== strlen($currency) || '' === $label || $quantity < 1 || $quantity > 9999) {
		return new WP_Error('wfcc_line_item_fields_invalid', __('The transaction line item has invalid required values.', 'wfc-cart'));
	}
	$source_key = isset($candidate['source_key'])
		? substr(sanitize_key($candidate['source_key']), 0, 100)
		: '';
	if ('' === $source_key) {
		return new WP_Error('wfcc_line_item_source_key_invalid', __('The transaction line item requires an idempotent source key.', 'wfc-cart'));
	}

	$unit_amount = isset($candidate['unit_amount']) ? intval($candidate['unit_amount']) : 0;
	$tax_amount  = isset($candidate['tax_amount']) ? intval($candidate['tax_amount']) : 0;
	$calculated  = ($unit_amount * $quantity) + $tax_amount;
	$total       = isset($candidate['total_amount']) ? intval($candidate['total_amount']) : $calculated;
	foreach (array($unit_amount, $tax_amount, $total) as $amount) {
		if (abs($amount) > 1000000000) {
			return new WP_Error('wfcc_line_item_amount_invalid', __('The transaction line-item amount is outside the supported range.', 'wfc-cart'));
		}
	}
	if ('adjustment' !== $type && ($unit_amount < 0 || $tax_amount < 0 || $total < 0)) {
		return new WP_Error('wfcc_line_item_amount_negative', __('Only adjustment line items may contain negative amounts.', 'wfc-cart'));
	}

	return array(
		'type'          => $type,
		'source_key'    => $source_key,
		'source_ref'    => isset($candidate['source_ref'])
			? substr(sanitize_text_field($candidate['source_ref']), 0, 100)
			: '',
		'label'         => substr($label, 0, 200),
		'quantity'      => $quantity,
		'unit_amount'   => $unit_amount,
		'tax_amount'    => $tax_amount,
		'total_amount'  => $total,
		'currency'      => $currency,
		'fund_code'     => isset($candidate['fund_code'])
			? substr(sanitize_key($candidate['fund_code']), 0, 50)
			: '',
	);
}

/**
 * Find one line item by transaction and idempotent source key.
 *
 * @param int    $transaction_id Transaction ID.
 * @param string $source_key     Source key.
 * @return int
 */
function wfcc_find_transaction_line_item($transaction_id, $source_key) {
	$existing = get_posts(
		array(
			'post_type'              => 'transactionlineitem',
			'post_status'            => array('private', 'publish', 'draft'),
			'fields'                 => 'ids',
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'suppress_filters'       => true,
			'update_post_meta_cache' => false,
			'meta_query'             => array(
				'relation' => 'AND',
				array(
					'key'     => 'wfcc_transaction_id',
					'value'   => absint($transaction_id),
					'compare' => '=',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => 'wfcc_source_key',
					'value'   => sanitize_key($source_key),
					'compare' => '=',
				),
			),
		)
	);

	return $existing ? absint($existing[0]) : 0;
}

/**
 * Acquire a stale-safe line-item creation lock.
 *
 * @param int    $transaction_id Transaction ID.
 * @param string $source_key     Source key.
 * @return string|false
 */
function wfcc_acquire_line_item_lock($transaction_id, $source_key) {
	$key      = 'wfcc_line_' . absint($transaction_id) . '_' . substr(hash('sha256', $source_key), 0, 20);
	$existing = get_option($key, false);
	if (is_array($existing) && !empty($existing['created']) && absint($existing['created']) < time() - 300) {
		delete_option($key);
	}

	return add_option($key, array('created' => time()), '', false) ? $key : false;
}

/**
 * Add one idempotent line item to a protected WFC transaction.
 *
 * Server-side adapters may call this function after resolving their own
 * authoritative product or event data.
 *
 * @param int                  $transaction_id Transaction ID.
 * @param array<string, mixed> $candidate      Fixed line-item candidate.
 * @return int|WP_Error
 */
function wfcc_add_transaction_line_item($transaction_id, $candidate) {
	$transaction_id = absint($transaction_id);
	if (!$transaction_id || 'transaction' !== get_post_type($transaction_id)) {
		return new WP_Error('wfcc_line_item_transaction_invalid', __('The WFC transaction is invalid.', 'wfc-cart'));
	}

	$item = wfcc_sanitize_transaction_line_item($candidate);
	if (is_wp_error($item)) {
		return $item;
	}

	$existing = wfcc_find_transaction_line_item($transaction_id, $item['source_key']);
	if ($existing) {
		return $existing;
	}

	$lock = wfcc_acquire_line_item_lock($transaction_id, $item['source_key']);
	if (!$lock) {
		return new WP_Error('wfcc_line_item_locked', __('This transaction line item is already being created.', 'wfc-cart'));
	}

	try {
		$existing = wfcc_find_transaction_line_item($transaction_id, $item['source_key']);
		if ($existing) {
			return $existing;
		}

		$line_item_id = wp_insert_post(
			array(
				'post_type'   => 'transactionlineitem',
				'post_status' => 'private',
				'post_parent' => $transaction_id,
				'post_title'  => $item['label'],
			),
			true
		);
		if (is_wp_error($line_item_id)) {
			return $line_item_id;
		}

		$meta = array(
			'wfcc_transaction_id' => $transaction_id,
			'wfcc_line_type'      => $item['type'],
			'wfcc_source_key'     => $item['source_key'],
			'wfcc_source_ref'     => $item['source_ref'],
			'wfcc_quantity'       => $item['quantity'],
			'wfcc_unit_amount'    => $item['unit_amount'],
			'wfcc_tax_amount'     => $item['tax_amount'],
			'wfcc_total_amount'   => $item['total_amount'],
			'wfcc_currency'       => $item['currency'],
			'wfcc_fund_code'      => $item['fund_code'],
			'wfcc_created_at'     => gmdate('c'),
		);
		foreach ($meta as $key => $value) {
			update_post_meta($line_item_id, $key, $value);
		}

		do_action('wfcc_transaction_line_item_created', $transaction_id, $line_item_id, $item);

		return absint($line_item_id);
	} finally {
		delete_option($lock);
	}
}

/**
 * Return bounded fixed-schema line items for downstream delivery.
 *
 * @param int $transaction_id Transaction ID.
 * @return array<int, array<string, int|string>>
 */
function wfcc_get_transaction_line_items($transaction_id) {
	$ids = get_posts(
		array(
			'post_type'              => 'transactionlineitem',
			'post_status'            => array('private', 'publish', 'draft'),
			'fields'                 => 'ids',
			'posts_per_page'         => 100,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'suppress_filters'       => true,
			'update_post_meta_cache' => true,
			'meta_query'             => array(
				array(
					'key'     => 'wfcc_transaction_id',
					'value'   => absint($transaction_id),
					'compare' => '=',
					'type'    => 'NUMERIC',
				),
			),
		)
	);

	$output = array();
	foreach ($ids as $line_item_id) {
		$output[] = array(
			'type'        => sanitize_key(get_post_meta($line_item_id, 'wfcc_line_type', true)),
			'sourceRef'   => sanitize_text_field(get_post_meta($line_item_id, 'wfcc_source_ref', true)),
			'label'       => sanitize_text_field(get_the_title($line_item_id)),
			'quantity'    => absint(get_post_meta($line_item_id, 'wfcc_quantity', true)),
			'unitAmount'  => intval(get_post_meta($line_item_id, 'wfcc_unit_amount', true)),
			'taxAmount'   => intval(get_post_meta($line_item_id, 'wfcc_tax_amount', true)),
			'totalAmount' => intval(get_post_meta($line_item_id, 'wfcc_total_amount', true)),
			'currency'    => strtoupper(sanitize_key(get_post_meta($line_item_id, 'wfcc_currency', true))),
			'fundCode'    => sanitize_key(get_post_meta($line_item_id, 'wfcc_fund_code', true)),
		);
	}

	return $output;
}

/**
 * Create the checkout's primary donation line item once.
 *
 * @param int                  $transaction_id Transaction ID.
 * @param array<string, mixed> $entry          Gravity Forms entry.
 * @param array<string, mixed> $form           Gravity Forms form.
 * @return void
 */
function wfcc_create_checkout_line_item($transaction_id, $entry, $form) {
	unset($entry, $form);

	$package_id = sanitize_key(get_post_meta($transaction_id, 'wfcc_package_id', true));
	$package    = wfcc_get_checkout_package($package_id);
	if (!$package) {
		return;
	}

	wfcc_add_transaction_line_item(
		$transaction_id,
		array(
			'type'         => 'donation',
			'source_key'   => 'checkout_donation',
			'source_ref'   => $package_id,
			'label'        => isset($package['label']) ? $package['label'] : __('Donation', 'wfc-cart'),
			'quantity'     => 1,
			'unit_amount'  => absint(get_post_meta($transaction_id, 'wfcc_amount', true)),
			'tax_amount'   => 0,
			'total_amount' => absint(get_post_meta($transaction_id, 'wfcc_amount', true)),
			'currency'     => get_post_meta($transaction_id, 'wfcc_currency', true),
			'fund_code'    => isset($package['fund']) ? $package['fund'] : '',
		)
	);
}
