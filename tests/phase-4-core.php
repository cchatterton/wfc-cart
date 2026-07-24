<?php
/**
 * Phase 4 pure-function contract tests without a WordPress installation.
 */

require __DIR__ . '/bootstrap-smoke.php';

if (!class_exists('WP_Error')) {
	class WP_Error {
		private $code;
		private $message;

		public function __construct($code = '', $message = '') {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

if (!function_exists('is_wp_error')) {
	function is_wp_error($value) {
		return $value instanceof WP_Error;
	}
}

if (!function_exists('absint')) {
	function absint($value) {
		return abs((int) $value);
	}
}

if (!function_exists('sanitize_key')) {
	function sanitize_key($value) {
		return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value));
	}
}

if (!function_exists('get_post_meta')) {
	function get_post_meta($post_id, $key) {
		return $GLOBALS['wfcc_test_meta'][$post_id][$key] ?? '';
	}
}

if (!function_exists('update_post_meta')) {
	function update_post_meta($post_id, $key, $value) {
		$GLOBALS['wfcc_test_meta'][$post_id][$key] = $value;
		return true;
	}
}

/**
 * Fail the contract test.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure.
 * @return void
 */
function wfcc_test_assert($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "Phase 4 test failed: {$message}\n");
		exit(1);
	}
}

$payload   = '{"id":"evt_contract","type":"payment_intent.succeeded"}';
$timestamp = 1721779200;
$secret    = 'whsec_contract_test';
$signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
$header    = 't=' . $timestamp . ',v1=' . $signature;

wfcc_test_assert(
	true === wfcc_verify_stripe_signature($payload, $header, $secret, 300, $timestamp + 30),
	'a valid webhook signature must pass'
);
wfcc_test_assert(
	is_wp_error(wfcc_verify_stripe_signature($payload, $header, $secret, 300, $timestamp + 301)),
	'an expired webhook signature must fail'
);
wfcc_test_assert(
	is_wp_error(wfcc_verify_stripe_signature($payload . 'x', $header, $secret, 300, $timestamp)),
	'a modified webhook body must fail'
);

wfcc_test_assert(5050 === wfcc_amount_to_minor_units('$50.50', 'AUD'), 'AUD must use two minor-unit digits');
wfcc_test_assert(5000 === wfcc_amount_to_minor_units('5,000', 'JPY'), 'JPY must remain zero-decimal');

$package = array(
	'amount'              => 5000,
	'currency'            => 'AUD',
	'allowed_amounts'     => array(2500, 5000),
	'allow_custom_amount' => true,
	'minimum_amount'      => 1000,
	'maximum_amount'      => 10000,
);
wfcc_test_assert(5000 === wfcc_resolve_package_amount($package), 'the package default amount must resolve');
wfcc_test_assert(2500 === wfcc_resolve_package_amount($package, '25.00'), 'an allowed amount must resolve');
wfcc_test_assert(7550 === wfcc_resolve_package_amount($package, '75.50'), 'a bounded custom amount must resolve');
wfcc_test_assert(0 === wfcc_resolve_package_amount($package, '9.99'), 'an amount below the package minimum must fail');
wfcc_test_assert(isset(wfcc_stripe_event_states()['payment_intent.succeeded']), 'payment success must be allow-listed');
wfcc_test_assert(!isset(wfcc_stripe_event_states()['customer.created']), 'unrelated events must not be allow-listed');

$GLOBALS['wfcc_test_meta'][101]['wfcc_payment_state'] = 'pending';
wfcc_test_assert(wfcc_transition_transaction(101, 'succeeded', 'succeeded'), 'pending must transition to succeeded');
wfcc_test_assert(!wfcc_transition_transaction(101, 'failed', 'failed'), 'succeeded must not regress to failed');
wfcc_test_assert(wfcc_transition_transaction(101, 'partially_refunded'), 'succeeded must transition to partially refunded');
wfcc_test_assert(wfcc_transition_transaction(101, 'refunded'), 'a partial refund must transition to fully refunded');

fwrite(STDOUT, "WFC Cart Phase 4 contract tests passed.\n");
