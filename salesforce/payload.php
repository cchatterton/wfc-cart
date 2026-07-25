<?php
/**
 * Fixed versioned Salesforce transaction payload.
 */

if (!defined('ABSPATH')) {
	exit;
}

define('WFCC_SALESFORCE_PAYLOAD_VERSION', '1.1');

/**
 * Hash only the non-donor delivery contract used for operational comparison.
 *
 * @param array<string, mixed> $payload Complete in-memory Salesforce payload.
 * @return string
 */
function wfcc_salesforce_payload_fingerprint($payload) {
	$gift = is_array($payload['gift'] ?? null) ? $payload['gift'] : array();
	$line_items = array();
	foreach (is_array($payload['lineItems'] ?? null) ? $payload['lineItems'] : array() as $line_item) {
		if (!is_array($line_item)) {
			continue;
		}
		$line_items[] = array(
			'type'        => $line_item['type'] ?? '',
			'quantity'    => $line_item['quantity'] ?? 0,
			'unitAmount'  => $line_item['unitAmount'] ?? 0,
			'taxAmount'   => $line_item['taxAmount'] ?? 0,
			'totalAmount' => $line_item['totalAmount'] ?? 0,
			'currency'    => $line_item['currency'] ?? '',
			'fundCode'    => $line_item['fundCode'] ?? '',
		);
	}
	$fingerprint = array(
		'schemaVersion'   => $payload['schemaVersion'] ?? '',
		'operation'       => $payload['operation'] ?? '',
		'transactionKey'  => $payload['transactionKey'] ?? '',
		'transactionType' => $payload['transactionType'] ?? '',
		'gift'            => array(
			'amount'       => $gift['amount'] ?? 0,
			'currency'     => $gift['currency'] ?? '',
			'frequency'    => $gift['frequency'] ?? '',
			'campaignCode' => $gift['campaignCode'] ?? '',
			'fundCode'     => $gift['fundCode'] ?? '',
			'giftType'     => $gift['giftType'] ?? '',
		),
		'lineItems'       => $line_items,
		'stripe'          => $payload['stripe'] ?? array(),
		'recurring'       => array(
			'enabled'           => $payload['recurring']['enabled'] ?? false,
			'consentRecordedAt' => $payload['recurring']['consentRecordedAt'] ?? '',
		),
		'occurredAt'      => $payload['occurredAt'] ?? '',
	);

	return hash('sha256', wp_json_encode($fingerprint));
}

/**
 * Build the fixed payload without persisting duplicate donor data.
 *
 * @param int $transaction_id Transaction post ID.
 * @return array<string, mixed>|WP_Error
 */
function wfcc_build_salesforce_payload($transaction_id) {
	$entry_id = absint(get_post_meta($transaction_id, 'wfcc_gravity_forms_entry_id', true));
	if (!$entry_id || !class_exists('GFAPI')) {
		return wfcc_salesforce_error(
			'wfcc_salesforce_entry_unavailable',
			__('The Gravity Forms entry is unavailable for Salesforce delivery.', 'wfc-cart'),
			'payload'
		);
	}

	$entry = GFAPI::get_entry($entry_id);
	if (is_wp_error($entry) || !is_array($entry)) {
		return wfcc_salesforce_error(
			'wfcc_salesforce_entry_invalid',
			__('The Gravity Forms entry could not be read for Salesforce delivery.', 'wfc-cart'),
			'payload'
		);
	}

	$transaction_key = wfcc_sanitize_transaction_key(get_post_meta($transaction_id, 'wfcc_transaction_key', true));
	$package_id      = sanitize_key(get_post_meta($transaction_id, 'wfcc_package_id', true));
	$package         = wfcc_get_checkout_package($package_id);
	if ('' === $transaction_key || !$package) {
		return wfcc_salesforce_error(
			'wfcc_salesforce_transaction_invalid',
			__('The WFC transaction cannot be mapped for Salesforce.', 'wfc-cart'),
			'payload'
		);
	}

	$mapped   = wfcc_resolve_salesforce_mapping($entry);
	$required = wfcc_get_setting('salesforce_required_fields', array('email', 'last_name'));
	$required = is_array($required) ? $required : array('email', 'last_name');
	foreach ($required as $field) {
		if (!array_key_exists($field, $mapped) || '' === $mapped[$field] || null === $mapped[$field]) {
			return wfcc_salesforce_error(
				'wfcc_salesforce_required_field_missing',
				__('A required Salesforce payload value is missing.', 'wfc-cart'),
				'payload'
			);
		}
	}

	$amount_minor = absint(get_post_meta($transaction_id, 'wfcc_amount', true));
	$currency     = strtoupper(sanitize_key(get_post_meta($transaction_id, 'wfcc_currency', true)));
	$payment_state = sanitize_key(get_post_meta($transaction_id, 'wfcc_payment_state', true));
	$initial_payment_state = sanitize_key(get_post_meta($transaction_id, 'wfcc_initial_payment_state', true));
	$initial_payment_state = $initial_payment_state ?: $payment_state;
	$metadata      = array_merge(
		array(
			'wfcPackageId'       => $package_id,
			'gravityFormsEntryId' => (string) $entry_id,
		),
		$mapped['metadata']
	);

	return array(
		'schemaVersion'   => WFCC_SALESFORCE_PAYLOAD_VERSION,
		'operation'       => sanitize_key(get_post_meta($transaction_id, 'wfcc_salesforce_operation', true)) ?: 'upsert',
		'transactionKey'  => $transaction_key,
		'transactionType' => !empty($package['gift_type']) ? sanitize_key($package['gift_type']) : 'donation',
		'donor'           => array(
			'firstName' => $mapped['first_name'],
			'lastName'  => $mapped['last_name'],
			'email'     => $mapped['email'],
			'phone'     => $mapped['phone'],
			'address'   => array(
				'line1'    => $mapped['address_line1'],
				'line2'    => $mapped['address_line2'],
				'city'     => $mapped['city'],
				'state'    => $mapped['state'],
				'postcode' => $mapped['postcode'],
				'country'  => $mapped['country'],
			),
		),
		'gift'            => array(
			'amount'          => wfcc_amount_from_minor_units($amount_minor, $currency),
			'currency'        => $currency,
			'frequency'       => sanitize_key(get_post_meta($transaction_id, 'wfcc_frequency', true)),
			'campaignCode'    => isset($package['campaign']) ? sanitize_text_field($package['campaign']) : '',
			'fundCode'        => isset($package['fund']) ? sanitize_text_field($package['fund']) : '',
			'giftType'        => isset($package['gift_type']) ? sanitize_text_field($package['gift_type']) : 'donation',
			'recurrenceStart' => $mapped['recurrence_start'],
		),
		'lineItems'       => wfcc_get_transaction_line_items($transaction_id),
		'stripe'          => array(
			'customerId'              => sanitize_text_field(get_post_meta($transaction_id, 'wfcc_stripe_customer_id', true)),
			'paymentMethodId'         => sanitize_text_field(get_post_meta($transaction_id, 'wfcc_stripe_payment_method_id', true)),
			'initialPaymentIntentId'   => sanitize_text_field(get_post_meta($transaction_id, 'wfcc_stripe_intent_id', true)),
			'setupIntentId'            => sanitize_text_field(get_post_meta($transaction_id, 'wfcc_stripe_setup_intent_id', true)),
			'initialPaymentStatus'     => $initial_payment_state,
			'currentPaymentStatus'     => $payment_state,
		),
		'recurring'       => array(
			'enabled'           => '1' === get_post_meta($transaction_id, 'wfcc_recurring', true),
			'consentRecordedAt' => sanitize_text_field(get_post_meta($transaction_id, 'wfcc_consent_recorded_at', true)),
			'consentEvidence'   => $mapped['consent_evidence'],
		),
		'attribution'     => array(
			'source'   => $mapped['source'],
			'medium'   => $mapped['medium'],
			'campaign' => $mapped['attribution_campaign'],
		),
		'metadata'        => $metadata,
		'occurredAt'      => sanitize_text_field(
			get_post_meta($transaction_id, 'wfcc_payment_updated_at', true)
				?: get_post_meta($transaction_id, 'wfcc_created_at', true)
				?: gmdate('c')
		),
	);
}
