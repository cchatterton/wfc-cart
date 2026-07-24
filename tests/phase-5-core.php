<?php
/**
 * Phase 5 Salesforce contract tests without a WordPress installation.
 */

require __DIR__ . '/phase-4-core.php';

if (!function_exists('sanitize_text_field')) {
	function sanitize_text_field($value) {
		return trim(strip_tags((string) $value));
	}
}

if (!function_exists('sanitize_email')) {
	function sanitize_email($value) {
		return filter_var((string) $value, FILTER_SANITIZE_EMAIL);
	}
}

if (!function_exists('esc_url_raw')) {
	function esc_url_raw($value) {
		return filter_var((string) $value, FILTER_SANITIZE_URL);
	}
}

if (!function_exists('wp_parse_url')) {
	function wp_parse_url($url, $component = -1) {
		return parse_url($url, $component);
	}
}

if (!function_exists('untrailingslashit')) {
	function untrailingslashit($value) {
		return rtrim((string) $value, '/\\');
	}
}

/**
 * Fail the Phase 5 contract test.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure.
 * @return void
 */
function wfcc_phase_5_assert($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "Phase 5 test failed: {$message}\n");
		exit(1);
	}
}

wfcc_phase_5_assert(
	'https://login.salesforce.com' === wfcc_sanitize_salesforce_login_url('https://login.salesforce.com/'),
	'the Salesforce login origin must be accepted'
);
wfcc_phase_5_assert(
	'https://example.my.salesforce.com' === wfcc_sanitize_salesforce_login_url('https://example.my.salesforce.com'),
	'a My Domain origin must be accepted'
);
wfcc_phase_5_assert(
	'' === wfcc_sanitize_salesforce_login_url('https://salesforce.com.example.test'),
	'a deceptive Salesforce hostname must be rejected'
);
wfcc_phase_5_assert(
	'' === wfcc_sanitize_salesforce_login_url('https://login.salesforce.com/unapproved'),
	'a login URL path must be rejected'
);
wfcc_phase_5_assert(
	'' === wfcc_sanitize_salesforce_login_url('http://login.salesforce.com'),
	'a non-HTTPS login URL must be rejected'
);
wfcc_phase_5_assert(
	'/services/apexrest/wfc-cart/v1/transactions' === wfcc_sanitize_salesforce_api_path('/services/apexrest/wfc-cart/v1/transactions'),
	'the fixed WFC Apex path must be accepted'
);
wfcc_phase_5_assert(
	'' === wfcc_sanitize_salesforce_api_path('/services/apexrest/wfc-cart/v1/transactions?target=other'),
	'an Apex path with query-controlled routing must be rejected'
);
wfcc_phase_5_assert(
	'' === wfcc_sanitize_salesforce_api_path('/services/apexrest/other/v1/transactions'),
	'an Apex path outside the WFC namespace must be rejected'
);

wfcc_phase_5_assert('1.3' === wfcc_sanitize_gf_entry_key('1.3'), 'a Gravity Forms sub-input must be accepted');
wfcc_phase_5_assert('' === wfcc_sanitize_gf_entry_key('email'), 'an arbitrary source key must be rejected');

$mapping = wfcc_sanitize_salesforce_field_map(
	'{
		"email":{"source":"field","field_id":"2","transform":"email"},
		"last_name":{"source":"field","field_id":"1.6","transform":"upper"},
		"Account.Secret__c":{"source":"constant","value":"blocked"},
		"metadata":{"appeal":{"source":"constant","value":"winter"}}
	}'
);
wfcc_phase_5_assert(!is_wp_error($mapping), 'a valid controlled map must be accepted');
wfcc_phase_5_assert(isset($mapping['email'], $mapping['last_name'], $mapping['metadata']['appeal']), 'fixed targets and metadata must remain');
wfcc_phase_5_assert(!isset($mapping['Account.Secret__c']), 'arbitrary Salesforce API field names must be dropped');

$conditional = wfcc_sanitize_salesforce_mapping_rule(
	array(
		'source'    => 'constant',
		'value'     => 'MONTHLY',
		'transform' => 'lower',
		'when'      => array('field_id' => '8', 'equals' => 'yes'),
	)
);
wfcc_phase_5_assert('monthly' === wfcc_resolve_salesforce_mapping_rule(array('8' => 'yes'), $conditional), 'matching conditional mapping must resolve');
wfcc_phase_5_assert('' === wfcc_resolve_salesforce_mapping_rule(array('8' => 'no'), $conditional), 'non-matching conditional mapping must be omitted');

$classification = wfcc_classify_salesforce_status(429);
wfcc_phase_5_assert($classification['retryable'] && 'availability' === $classification['category'], 'HTTP 429 must be retryable');
$classification = wfcc_classify_salesforce_status(403);
wfcc_phase_5_assert(!$classification['retryable'] && 'authorization' === $classification['category'], 'HTTP 403 must require intervention');

wfcc_phase_5_assert(67 === wfcc_salesforce_retry_delay(1, 7), 'first retry must use bounded deterministic backoff');
wfcc_phase_5_assert(86407 === wfcc_salesforce_retry_delay(99, 7), 'retry backoff must be capped');
wfcc_phase_5_assert(50.5 === wfcc_amount_from_minor_units(5050, 'AUD'), 'minor units must become a numeric decimal value');
wfcc_phase_5_assert(5000 === wfcc_amount_from_minor_units(5000, 'JPY'), 'zero-decimal currencies must remain whole');

$response = wfcc_validate_salesforce_response(
	array(
		'transactionKey' => 'wfcc_contract',
		'success'        => true,
		'records'        => array('transactionId' => 'a001234567890AB'),
	),
	'wfcc_contract'
);
wfcc_phase_5_assert(!is_wp_error($response), 'a matching fixed response must pass');
wfcc_phase_5_assert(
	is_wp_error(
		wfcc_validate_salesforce_response(
			array('transactionKey' => 'different', 'success' => true),
			'wfcc_contract'
		)
	),
	'a mismatched transaction key must fail'
);
wfcc_phase_5_assert(
	is_wp_error(
		wfcc_validate_salesforce_response(
			array(
				'transactionKey' => 'wfcc_contract',
				'success'        => true,
				'records'        => array('transactionId' => '../../secret'),
			),
			'wfcc_contract'
		)
	),
	'an invalid record reference must fail'
);

fwrite(STDOUT, "WFC Cart Phase 5 contract tests passed.\n");
