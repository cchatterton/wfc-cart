<?php
/**
 * Idempotent WFC transaction records and payment-state transitions.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Generate a browser-safe transaction correlation key.
 *
 * @return string
 */
function wfcc_generate_transaction_key() {
	return 'wfcc_' . str_replace('-', '', wp_generate_uuid4());
}

/**
 * Create a protected pending transaction.
 *
 * @param string               $transaction_key Correlation key.
 * @param array<string, mixed> $package         Approved package.
 * @param int                  $amount          Minor-unit amount.
 * @param int                  $form_id         Gravity Forms form ID.
 * @return int|WP_Error
 */
function wfcc_create_transaction($transaction_key, $package, $amount, $form_id) {
	$transaction_key = wfcc_sanitize_transaction_key($transaction_key);
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'transaction',
			'post_status' => 'private',
			'post_title'  => sprintf(__('WFC transaction %s', 'wfc-cart'), $transaction_key),
		),
		true
	);
	if (is_wp_error($post_id)) {
		return $post_id;
	}

	$meta = array(
		'wfcc_transaction_key' => $transaction_key,
		'wfcc_package_id'      => isset($package['id']) ? sanitize_key($package['id']) : '',
		'wfcc_amount'          => absint($amount),
		'wfcc_currency'        => isset($package['currency']) ? strtoupper(sanitize_key($package['currency'])) : 'AUD',
		'wfcc_frequency'       => isset($package['frequency']) ? sanitize_key($package['frequency']) : 'one-off',
		'wfcc_recurring'       => !empty($package['recurring']) ? '1' : '0',
		'wfcc_form_id'         => absint($form_id),
		'wfcc_payment_state'   => 'pending',
		'wfcc_created_at'      => gmdate('c'),
	);
	foreach ($meta as $key => $value) {
		update_post_meta($post_id, $key, $value);
	}

	return (int) $post_id;
}

/**
 * Find a transaction by one exact meta value.
 *
 * @param string $meta_key   Allowed meta key.
 * @param string $meta_value Exact value.
 * @return int
 */
function wfcc_find_transaction($meta_key, $meta_value) {
	$allowed = array('wfcc_transaction_key', 'wfcc_stripe_intent_id', 'wfcc_stripe_setup_intent_id');
	if (!in_array($meta_key, $allowed, true) || '' === $meta_value) {
		return 0;
	}

	$ids = get_posts(
		array(
			'post_type'              => 'transaction',
			'post_status'            => array('private', 'publish', 'draft'),
			'fields'                 => 'ids',
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'suppress_filters'       => true,
			'update_post_meta_cache' => false,
			'meta_query'             => array(
				array(
					'key'     => $meta_key,
					'value'   => sanitize_text_field($meta_value),
					'compare' => '=',
				),
			),
		)
	);

	return empty($ids) ? 0 : absint($ids[0]);
}

/**
 * Attach safe Stripe object references to a transaction.
 *
 * @param int                  $transaction_id Transaction post ID.
 * @param array<string, mixed> $intent         Stripe object.
 * @return void
 */
function wfcc_store_intent_on_transaction($transaction_id, $intent) {
	if (empty($intent['id']) || empty($intent['object'])) {
		return;
	}

	$intent_id = sanitize_text_field($intent['id']);
	if ('setup_intent' === $intent['object']) {
		update_post_meta($transaction_id, 'wfcc_stripe_setup_intent_id', $intent_id);
	} else {
		update_post_meta($transaction_id, 'wfcc_stripe_intent_id', $intent_id);
	}

	update_post_meta($transaction_id, 'wfcc_stripe_object', sanitize_key($intent['object']));
	update_post_meta($transaction_id, 'wfcc_stripe_state', isset($intent['status']) ? sanitize_key($intent['status']) : 'requires_payment_method');
	if (!empty($intent['customer'])) {
		update_post_meta($transaction_id, 'wfcc_stripe_customer_id', sanitize_text_field($intent['customer']));
	}
}

/**
 * Apply an allow-listed payment state transition.
 *
 * @param int    $transaction_id Transaction post ID.
 * @param string $new_state      New WFC state.
 * @param string $stripe_state   Stripe state.
 * @return bool
 */
function wfcc_transition_transaction($transaction_id, $new_state, $stripe_state = '') {
	$transitions = array(
		'pending'               => array('pending', 'processing', 'requires_action', 'succeeded', 'setup_succeeded', 'failed', 'cancelled'),
		'processing'            => array('processing', 'requires_action', 'succeeded', 'failed', 'cancelled'),
		'requires_action'       => array('requires_action', 'processing', 'succeeded', 'setup_succeeded', 'failed', 'cancelled'),
		'failed'                => array('failed', 'processing', 'requires_action', 'succeeded', 'setup_succeeded', 'cancelled'),
		'succeeded'             => array('succeeded', 'partially_refunded', 'refunded', 'disputed'),
		'setup_succeeded'       => array('setup_succeeded'),
		'partially_refunded'    => array('partially_refunded', 'refunded', 'disputed'),
		'refunded'              => array('refunded', 'disputed'),
		'disputed'              => array('disputed', 'partially_refunded', 'refunded'),
		'cancelled'             => array('cancelled'),
	);
	$current = sanitize_key((string) get_post_meta($transaction_id, 'wfcc_payment_state', true));
	$current = '' === $current ? 'pending' : $current;
	$new_state = sanitize_key($new_state);

	if (!isset($transitions[$current]) || !in_array($new_state, $transitions[$current], true)) {
		return false;
	}

	update_post_meta($transaction_id, 'wfcc_payment_state', $new_state);
	update_post_meta($transaction_id, 'wfcc_payment_updated_at', gmdate('c'));
	if ('' !== $stripe_state) {
		update_post_meta($transaction_id, 'wfcc_stripe_state', sanitize_key($stripe_state));
	}

	return true;
}
