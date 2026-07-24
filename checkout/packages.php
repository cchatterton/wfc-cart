<?php
/**
 * Server-owned checkout package definitions.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Return configured checkout packages after applying the supported extension filter.
 *
 * Amounts are stored as Stripe minor units (for example, 5000 is AUD 50.00).
 *
 * @return array<string, array<string, mixed>>
 */
function wfcc_get_checkout_packages() {
	$packages = wfcc_get_setting('checkout_packages', array());
	$packages = is_array($packages) ? $packages : array();

	/**
	 * Filter server-owned checkout packages.
	 *
	 * @param array<string, array<string, mixed>> $packages Packages keyed by opaque ID.
	 */
	$packages = apply_filters('wfcc_checkout_packages', $packages);

	return is_array($packages) ? $packages : array();
}

/**
 * Resolve an enabled package by opaque identifier.
 *
 * @param string $package_id Package identifier.
 * @return array<string, mixed>|null
 */
function wfcc_get_checkout_package($package_id) {
	$package_id = sanitize_key($package_id);
	$packages   = wfcc_get_checkout_packages();

	if ('' === $package_id || empty($packages[$package_id]) || !is_array($packages[$package_id])) {
		return null;
	}

	$package = $packages[$package_id];
	if (isset($package['enabled']) && !$package['enabled']) {
		return null;
	}

	$package['id'] = $package_id;

	return $package;
}

/**
 * Convert a donor-entered major-unit amount to Stripe minor units.
 *
 * @param mixed  $amount   Donor-entered amount.
 * @param string $currency ISO currency.
 * @return int
 */
function wfcc_amount_to_minor_units($amount, $currency) {
	if (!is_scalar($amount)) {
		return 0;
	}

	$normalised = preg_replace('/[^0-9.,-]/', '', (string) $amount);
	$normalised = str_replace(',', '', (string) $normalised);
	if (!is_numeric($normalised) || (float) $normalised <= 0) {
		return 0;
	}

	$zero_decimal = array(
		'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
		'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
	);
	$exponent = in_array(strtoupper($currency), $zero_decimal, true) ? 0 : 2;

	return (int) round((float) $normalised * (10 ** $exponent));
}

/**
 * Resolve and validate an amount against a package allow-list and custom bounds.
 *
 * @param array<string, mixed> $package Package.
 * @param mixed                $amount  Optional donor-entered major-unit amount.
 * @return int
 */
function wfcc_resolve_package_amount($package, $amount = null) {
	$default = isset($package['amount']) ? absint($package['amount']) : 0;
	if (null === $amount || '' === trim((string) $amount)) {
		return $default;
	}

	$currency = isset($package['currency']) ? (string) $package['currency'] : 'AUD';
	$minor    = wfcc_amount_to_minor_units($amount, $currency);
	if ($minor < 1) {
		return 0;
	}

	$allowed = isset($package['allowed_amounts']) && is_array($package['allowed_amounts'])
		? array_map('absint', $package['allowed_amounts'])
		: array();
	if ($minor === $default || in_array($minor, $allowed, true)) {
		return $minor;
	}

	if (!empty($package['allow_custom_amount'])) {
		$minimum = isset($package['minimum_amount']) ? absint($package['minimum_amount']) : 100;
		$maximum = isset($package['maximum_amount']) ? absint($package['maximum_amount']) : 10000000;
		if ($minor >= $minimum && $minor <= $maximum) {
			return $minor;
		}
	}

	return 0;
}

/**
 * Sanitise a checkout-package JSON document into a fixed schema.
 *
 * @param mixed $json JSON string.
 * @return array<string, array<string, mixed>>|WP_Error
 */
function wfcc_sanitize_checkout_packages($json) {
	if (!is_string($json) || '' === trim($json)) {
		return array();
	}

	$decoded = json_decode($json, true);
	if (!is_array($decoded)) {
		return new WP_Error('wfcc_invalid_package_json', __('Checkout packages must be valid JSON.', 'wfc-cart'));
	}

	$output = array();
	foreach ($decoded as $candidate_id => $candidate) {
		$id = sanitize_key($candidate_id);
		if ('' === $id || !is_array($candidate)) {
			continue;
		}

		$currency = isset($candidate['currency']) ? strtoupper(sanitize_key($candidate['currency'])) : 'AUD';
		$mode     = isset($candidate['mode']) && 'setup' === $candidate['mode'] ? 'setup' : 'payment';
		$frequency = isset($candidate['frequency']) ? sanitize_key($candidate['frequency']) : 'one-off';

		$package = array(
			'enabled'             => !isset($candidate['enabled']) || (bool) $candidate['enabled'],
			'label'               => isset($candidate['label']) ? sanitize_text_field($candidate['label']) : $id,
			'mode'                => $mode,
			'amount'              => isset($candidate['amount']) ? absint($candidate['amount']) : 0,
			'allowed_amounts'     => isset($candidate['allowed_amounts']) && is_array($candidate['allowed_amounts'])
				? array_values(array_unique(array_filter(array_map('absint', $candidate['allowed_amounts']))))
				: array(),
			'allow_custom_amount' => !empty($candidate['allow_custom_amount']),
			'minimum_amount'      => isset($candidate['minimum_amount']) ? absint($candidate['minimum_amount']) : 100,
			'maximum_amount'      => isset($candidate['maximum_amount']) ? absint($candidate['maximum_amount']) : 10000000,
			'amount_field_id'     => isset($candidate['amount_field_id']) ? absint($candidate['amount_field_id']) : 0,
			'consent_field_id'    => isset($candidate['consent_field_id']) ? absint($candidate['consent_field_id']) : 0,
			'currency'            => 3 === strlen($currency) ? $currency : 'AUD',
			'frequency'           => $frequency,
			'recurring'           => !empty($candidate['recurring']),
			'campaign'            => isset($candidate['campaign']) ? sanitize_text_field($candidate['campaign']) : '',
			'fund'                => isset($candidate['fund']) ? sanitize_text_field($candidate['fund']) : '',
			'gift_type'           => isset($candidate['gift_type']) ? sanitize_text_field($candidate['gift_type']) : '',
			'thank_you_url'       => isset($candidate['thank_you_url']) ? esc_url_raw($candidate['thank_you_url']) : '',
		);

		if ('payment' === $mode && $package['amount'] < 1) {
			continue;
		}
		if (($package['recurring'] || 'setup' === $mode) && $package['consent_field_id'] < 1) {
			continue;
		}
		if ($package['maximum_amount'] < $package['minimum_amount']) {
			$package['maximum_amount'] = $package['minimum_amount'];
		}
		if ('' !== $package['thank_you_url'] && !wfcc_is_approved_redirect($package['thank_you_url'])) {
			$package['thank_you_url'] = '';
		}

		$output[$id] = $package;
	}

	return $output;
}
