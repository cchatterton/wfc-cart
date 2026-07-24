<?php
/**
 * Stripe Customer, PaymentIntent, and SetupIntent orchestration.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Create a Stripe intent for one approved transaction.
 *
 * @param int                  $transaction_id Transaction post ID.
 * @param string               $transaction_key Correlation key.
 * @param array<string, mixed> $package         Approved package.
 * @param int                  $amount          Minor-unit amount.
 * @param string               $idempotency_key Server-scoped idempotency key.
 * @return array<string, mixed>|WP_Error
 */
function wfcc_create_stripe_intent($transaction_id, $transaction_key, $package, $amount, $idempotency_key) {
	$customer_id = '';
	if (!empty($package['recurring']) || 'setup' === $package['mode']) {
		$customer = wfcc_stripe_request(
			'POST',
			'customers',
			array(
				'description'                    => 'WFC Cart recurring donor',
				'metadata[wfcc_transaction_key]' => $transaction_key,
			),
			$idempotency_key . '-customer'
		);
		if (is_wp_error($customer)) {
			return $customer;
		}
		$customer_id = isset($customer['id']) ? sanitize_text_field($customer['id']) : '';
	}

	$metadata = array(
		'metadata[wfcc_transaction_key]' => $transaction_key,
		'metadata[wfcc_package_id]'      => isset($package['id']) ? $package['id'] : '',
	);

	if ('setup' === $package['mode']) {
		$params = array_merge(
			$metadata,
			array(
				'customer'               => $customer_id,
				'usage'                  => 'off_session',
				'payment_method_types[]' => 'card',
			)
		);
		$intent = wfcc_stripe_request('POST', 'setup_intents', $params, $idempotency_key . '-setup');
	} else {
		$params = array_merge(
			$metadata,
			array(
				'amount'                 => absint($amount),
				'currency'               => strtolower($package['currency']),
				'payment_method_types[]' => 'card',
				'description'            => isset($package['label']) ? $package['label'] : __('WFC Cart payment', 'wfc-cart'),
			)
		);
		if (!empty($package['recurring'])) {
			$params['customer']           = $customer_id;
			$params['setup_future_usage'] = 'off_session';
		}
		$intent = wfcc_stripe_request('POST', 'payment_intents', $params, $idempotency_key . '-payment');
	}

	if (is_wp_error($intent)) {
		return $intent;
	}

	wfcc_store_intent_on_transaction($transaction_id, $intent);

	return $intent;
}

/**
 * Retrieve and verify a transaction intent directly from Stripe.
 *
 * @param int $transaction_id Transaction post ID.
 * @return array<string, mixed>|WP_Error
 */
function wfcc_retrieve_transaction_intent($transaction_id) {
	$object = get_post_meta($transaction_id, 'wfcc_stripe_object', true);
	if ('setup_intent' === $object) {
		$id       = sanitize_text_field(get_post_meta($transaction_id, 'wfcc_stripe_setup_intent_id', true));
		$resource = 'setup_intents/' . $id;
	} else {
		$id       = sanitize_text_field(get_post_meta($transaction_id, 'wfcc_stripe_intent_id', true));
		$resource = 'payment_intents/' . $id;
	}
	if ('' === $id) {
		return new WP_Error('wfcc_missing_intent', __('No Stripe intent is attached to this transaction.', 'wfc-cart'));
	}

	return wfcc_stripe_request('GET', $resource);
}
