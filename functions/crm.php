<?php
/**
 * CRM mode selection and donor-data retention boundaries.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('wfcc_checkout_completed', 'wfcc_record_completed_checkout_crm_state', 1, 3);
add_action('update_option_wfcc_settings', 'wfcc_handle_crm_settings_change', 10, 3);
add_filter('add_post_metadata', 'wfcc_prevent_operational_pii_metadata', 10, 5);
add_filter('update_post_metadata', 'wfcc_prevent_operational_pii_metadata', 10, 5);

/**
 * Return an allow-listed CRM mode.
 *
 * @param mixed  $value   Candidate mode.
 * @param string $default Fallback mode.
 * @return string
 */
function wfcc_sanitize_crm_mode($value, $default = 'wordpress') {
	$value   = sanitize_key((string) $value);
	$default = 'salesforce' === $default ? 'salesforce' : 'wordpress';

	return in_array($value, array('salesforce', 'wordpress'), true) ? $value : $default;
}

/**
 * Resolve the effective CRM mode.
 *
 * A wp-config.php constant may enforce the mode for managed environments.
 *
 * @return string
 */
function wfcc_get_crm_mode() {
	if (defined('WFCC_CRM_MODE')) {
		return wfcc_sanitize_crm_mode(constant('WFCC_CRM_MODE'));
	}

	return wfcc_sanitize_crm_mode(wfcc_get_setting('crm_mode', 'wordpress'));
}

/**
 * Return whether Salesforce owns CRM delivery.
 *
 * @return bool
 */
function wfcc_uses_salesforce_crm() {
	return 'salesforce' === wfcc_get_crm_mode();
}

/**
 * Return the human-readable effective CRM mode.
 *
 * @return string
 */
function wfcc_get_crm_mode_label() {
	return wfcc_uses_salesforce_crm()
		? __('Salesforce CRM', 'wfc-cart')
		: __('WordPress (Gravity Forms entry)', 'wfc-cart');
}

/**
 * Resolve the CRM mode recorded for a transaction.
 *
 * @param int $transaction_id Transaction ID.
 * @return string
 */
function wfcc_get_transaction_crm_mode($transaction_id) {
	$stored = sanitize_key((string) get_post_meta(absint($transaction_id), 'wfcc_crm_mode', true));
	if (in_array($stored, array('salesforce', 'wordpress'), true)) {
		return $stored;
	}

	return '' !== get_post_meta(absint($transaction_id), 'wfcc_salesforce_state', true)
		? 'salesforce'
		: wfcc_get_crm_mode();
}

/**
 * Resolve a privacy-safe generic CRM state for reporting.
 *
 * @param int $transaction_id Transaction ID.
 * @return string
 */
function wfcc_get_transaction_crm_state($transaction_id) {
	$state = sanitize_key((string) get_post_meta(absint($transaction_id), 'wfcc_crm_state', true));
	if ('' !== $state) {
		return $state;
	}

	if ('salesforce' === wfcc_get_transaction_crm_mode($transaction_id)) {
		return sanitize_key((string) get_post_meta(absint($transaction_id), 'wfcc_salesforce_state', true)) ?: 'not_queued';
	}

	return get_post_meta(absint($transaction_id), 'wfcc_gravity_forms_entry_id', true)
		? 'gravity_forms_entry_retained'
		: 'awaiting_gravity_forms_entry';
}

/**
 * Infer the safe mode for an existing installation during schema upgrade.
 *
 * Existing complete Salesforce configurations remain enabled. Sites without
 * credentials move to the explicit WordPress-only mode instead of producing
 * failed delivery records.
 *
 * @return string
 */
function wfcc_infer_existing_crm_mode() {
	if (
		function_exists('wfcc_get_salesforce_client_id')
		&& function_exists('wfcc_get_salesforce_client_secret')
		&& '' !== wfcc_get_salesforce_client_id()
		&& '' !== wfcc_get_salesforce_client_secret()
	) {
		return 'salesforce';
	}

	return 'wordpress';
}

/**
 * Return whether a package needs an external recurring-payment owner.
 *
 * @param array<string, mixed> $package Checkout package.
 * @return bool
 */
function wfcc_package_requires_external_recurring_owner($package) {
	return is_array($package)
		&& (!empty($package['recurring']) || 'setup' === ($package['mode'] ?? 'payment'));
}

/**
 * Return whether WordPress mode contains an unsafe recurring package.
 *
 * @param array<string, array<string, mixed>>|null $packages Optional packages.
 * @return bool
 */
function wfcc_wordpress_crm_has_recurring_packages($packages = null) {
	if (null === $packages) {
		$packages = function_exists('wfcc_get_checkout_packages') ? wfcc_get_checkout_packages() : array();
	}

	foreach (is_array($packages) ? $packages : array() as $package) {
		if (
			is_array($package)
			&& (!isset($package['enabled']) || $package['enabled'])
			&& wfcc_package_requires_external_recurring_owner($package)
		) {
			return true;
		}
	}

	return false;
}

/**
 * Record which CRM boundary owns the completed checkout.
 *
 * Donor field values remain in the Gravity Forms entry supplied to this hook.
 * Only the entry ID and operational state are retained on the transaction.
 *
 * @param int                  $transaction_id Transaction ID.
 * @param array<string, mixed> $entry          Gravity Forms entry.
 * @param array<string, mixed> $form           Gravity Forms form.
 * @return void
 */
function wfcc_record_completed_checkout_crm_state($transaction_id, $entry, $form) {
	unset($entry, $form);

	$mode = wfcc_get_crm_mode();
	update_post_meta($transaction_id, 'wfcc_crm_mode', $mode);
	update_post_meta(
		$transaction_id,
		'wfcc_crm_state',
		'salesforce' === $mode ? 'delivery_pending' : 'gravity_forms_entry_retained'
	);
}

/**
 * Synchronise Salesforce scheduling after the effective mode changes.
 *
 * @param mixed  $old_value Previous settings.
 * @param mixed  $new_value New settings.
 * @param string $option    Option name.
 * @return void
 */
function wfcc_handle_crm_settings_change($old_value, $new_value, $option) {
	unset($option);
	$old_mode = wfcc_sanitize_crm_mode(is_array($old_value) ? ($old_value['crm_mode'] ?? 'wordpress') : 'wordpress');
	$new_mode = wfcc_sanitize_crm_mode(is_array($new_value) ? ($new_value['crm_mode'] ?? 'wordpress') : 'wordpress');
	if ($old_mode === $new_mode || !function_exists('wfcc_schedule_delivery_queue')) {
		return;
	}

	wfcc_remove_known_operational_pii_copies();
	wfcc_schedule_delivery_queue(true);
}

/**
 * Identify metadata keys that are intended to contain donor PII.
 *
 * @param mixed $meta_key Metadata key.
 * @return bool
 */
function wfcc_operational_meta_key_contains_pii($meta_key) {
	$key = sanitize_key((string) $meta_key);
	if ('' === $key) {
		return false;
	}

	return (bool) preg_match(
		'/(^|_)(first_name|last_name|full_name|donor_name|email|recipient|phone|mobile|address|address_line1|address_line2|street|suburb|city|postcode|postal_code|contact_id|billing|shipping_name|shipping_address)(_|$)/',
		$key
	);
}

/**
 * Return known historical WFC donor metadata keys.
 *
 * @return string[]
 */
function wfcc_known_operational_pii_meta_keys() {
	return array(
		'wfcc_salesforce_contact_id',
		'wfcc_first_name',
		'wfcc_last_name',
		'wfcc_full_name',
		'wfcc_donor_name',
		'wfcc_email',
		'wfcc_recipient_email',
		'wfcc_phone',
		'wfcc_mobile',
		'wfcc_address',
		'wfcc_billing_address',
		'wfcc_shipping_address',
	);
}

/**
 * Remove known donor-field copies from WFC operational metadata.
 *
 * The authoritative Gravity Forms entries and all financial/operational
 * transaction data remain untouched.
 *
 * @return void
 */
function wfcc_remove_known_operational_pii_copies() {
	foreach (wfcc_known_operational_pii_meta_keys() as $meta_key) {
		delete_post_meta_by_key($meta_key);
	}
}

/**
 * Reject donor-PII metadata on WFC operational post types.
 *
 * @param mixed  $check      Existing short-circuit value.
 * @param int    $object_id  Post ID.
 * @param string $meta_key   Metadata key.
 * @param mixed  $meta_value Metadata value.
 * @param bool   $unique     Whether an add operation is unique.
 * @return mixed
 */
function wfcc_prevent_operational_pii_metadata($check, $object_id, $meta_key, $meta_value, $unique = false) {
	unset($meta_value, $unique);
	if (null !== $check || !wfcc_operational_meta_key_contains_pii($meta_key)) {
		return $check;
	}

	$post_type = get_post_type(absint($object_id));
	if (!in_array($post_type, array('transaction', 'transactionlineitem', 'transactionbatch', 'fundcode'), true)) {
		return $check;
	}

	return false;
}

/**
 * Sanitize a non-PII operational reference.
 *
 * References are deliberately restricted to opaque machine identifiers. Free
 * text, email addresses, and phone-like values belong in the Gravity Forms
 * entry and are rejected here.
 *
 * @param mixed $value Candidate value.
 * @return string
 */
function wfcc_sanitize_operational_reference($value) {
	$value = trim((string) $value);
	if ('' === $value || strlen($value) > 100) {
		return '';
	}
	if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,99}$/', $value)) {
		return '';
	}
	if (preg_match('/^\+?[0-9][0-9._\/-]{7,}$/', $value)) {
		return '';
	}

	return $value;
}
