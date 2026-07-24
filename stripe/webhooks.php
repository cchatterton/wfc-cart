<?php
/**
 * Stripe webhook signature verification and state reconciliation.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Verify Stripe's timestamped HMAC signature over the exact raw body.
 *
 * @param string   $payload   Raw request body.
 * @param string   $header    Stripe-Signature header.
 * @param string   $secret    Endpoint signing secret.
 * @param int      $tolerance Maximum clock skew in seconds.
 * @param int|null $now       Testable current timestamp.
 * @return true|WP_Error
 */
function wfcc_verify_stripe_signature($payload, $header, $secret, $tolerance = 300, $now = null) {
	if ('' === $payload || '' === $header || '' === $secret) {
		return new WP_Error('wfcc_stripe_signature_missing', __('Stripe signature verification data is missing.', 'wfc-cart'));
	}

	$timestamp  = 0;
	$signatures = array();
	foreach (explode(',', $header) as $component) {
		$pair = array_map('trim', explode('=', $component, 2));
		if (2 !== count($pair)) {
			continue;
		}
		if ('t' === $pair[0]) {
			$timestamp = absint($pair[1]);
		} elseif ('v1' === $pair[0] && preg_match('/^[a-f0-9]{64}$/i', $pair[1])) {
			$signatures[] = strtolower($pair[1]);
		}
	}

	$now = null === $now ? time() : absint($now);
	if ($timestamp < 1 || empty($signatures) || abs($now - $timestamp) > absint($tolerance)) {
		return new WP_Error('wfcc_stripe_signature_expired', __('Stripe signature timestamp is invalid.', 'wfc-cart'));
	}

	$expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
	foreach ($signatures as $signature) {
		if (hash_equals($expected, $signature)) {
			return true;
		}
	}

	return new WP_Error('wfcc_stripe_signature_invalid', __('Stripe signature is invalid.', 'wfc-cart'));
}

/**
 * Allow-listed Stripe event types.
 *
 * @return array<string, string>
 */
function wfcc_stripe_event_states() {
	return array(
		'payment_intent.succeeded'       => 'succeeded',
		'payment_intent.processing'      => 'processing',
		'payment_intent.requires_action' => 'requires_action',
		'payment_intent.payment_failed'  => 'failed',
		'payment_intent.canceled'        => 'cancelled',
		'setup_intent.succeeded'         => 'setup_succeeded',
		'setup_intent.requires_action'   => 'requires_action',
		'setup_intent.setup_failed'      => 'failed',
		'setup_intent.canceled'          => 'cancelled',
		'charge.refunded'                => 'refunded',
		'charge.dispute.created'         => 'disputed',
	);
}

/**
 * Reconcile one verified Stripe event.
 *
 * @param array<string, mixed> $event Verified event.
 * @return true|WP_Error
 */
function wfcc_reconcile_stripe_event($event) {
	$states = wfcc_stripe_event_states();
	$type   = isset($event['type']) ? sanitize_text_field($event['type']) : '';
	if (!isset($states[$type])) {
		return true;
	}

	$object = isset($event['data']['object']) && is_array($event['data']['object'])
		? $event['data']['object']
		: array();
	$intent_id = '';
	if (0 === strpos($type, 'payment_intent.') || 0 === strpos($type, 'setup_intent.')) {
		$intent_id = isset($object['id']) ? sanitize_text_field($object['id']) : '';
	} elseif (!empty($object['payment_intent'])) {
		$intent_id = sanitize_text_field($object['payment_intent']);
	}

	$transaction_key = isset($object['metadata']['wfcc_transaction_key'])
		? wfcc_sanitize_transaction_key($object['metadata']['wfcc_transaction_key'])
		: '';
	$transaction_id  = $transaction_key ? wfcc_find_transaction('wfcc_transaction_key', $transaction_key) : 0;
	if (!$transaction_id && $intent_id) {
		$transaction_id = wfcc_find_transaction(
			0 === strpos($intent_id, 'seti_') ? 'wfcc_stripe_setup_intent_id' : 'wfcc_stripe_intent_id',
			$intent_id
		);
	}
	if (!$transaction_id) {
		return new WP_Error('wfcc_transaction_not_found', __('No WFC transaction matches this Stripe event.', 'wfc-cart'));
	}

	$new_state = $states[$type];
	if ('charge.refunded' === $type
		&& isset($object['amount'], $object['amount_refunded'])
		&& absint($object['amount_refunded']) < absint($object['amount'])) {
		$new_state = 'partially_refunded';
	}

	if (!wfcc_transition_transaction($transaction_id, $new_state, isset($object['status']) ? $object['status'] : '')) {
		return new WP_Error('wfcc_invalid_payment_transition', __('The Stripe event would create an invalid payment transition.', 'wfc-cart'));
	}

	if (!empty($object['payment_method'])) {
		update_post_meta($transaction_id, 'wfcc_stripe_payment_method_id', sanitize_text_field($object['payment_method']));
	}
	if (!empty($object['customer'])) {
		update_post_meta($transaction_id, 'wfcc_stripe_customer_id', sanitize_text_field($object['customer']));
	}
	update_post_meta($transaction_id, 'wfcc_last_stripe_event_id', sanitize_text_field($event['id']));

	/**
	 * Fires after a verified Stripe event changes a WFC transaction.
	 *
	 * @param int                  $transaction_id Transaction post ID.
	 * @param string               $type           Stripe event type.
	 * @param array<string, mixed> $object         Stripe object.
	 */
	do_action('wfcc_stripe_event_reconciled', $transaction_id, $type, $object);

	return true;
}
