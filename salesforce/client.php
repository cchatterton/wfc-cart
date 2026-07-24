<?php
/**
 * Fixed Salesforce Apex REST client.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Sanitise the fixed WFC Apex REST path.
 *
 * @param mixed $path Candidate path.
 * @return string
 */
function wfcc_sanitize_salesforce_api_path($path) {
	$path = '/' . ltrim(sanitize_text_field((string) $path), '/');

	return preg_match('#^/services/apexrest/wfc-cart/[A-Za-z0-9/_-]+$#', $path) ? $path : '';
}

/**
 * Return the fixed Apex REST path.
 *
 * @return string
 */
function wfcc_get_salesforce_api_path() {
	if (defined('WFCC_SALESFORCE_API_PATH') && is_string(WFCC_SALESFORCE_API_PATH)) {
		$path = WFCC_SALESFORCE_API_PATH;
	} else {
		$environment = getenv('WFCC_SALESFORCE_API_PATH');
		$path = is_string($environment) && '' !== $environment
			? $environment
			: wfcc_get_setting('salesforce_api_path', '/services/apexrest/wfc-cart/v1/transactions');
	}

	return wfcc_sanitize_salesforce_api_path($path);
}

/**
 * Validate the fixed Apex response schema and Salesforce record IDs.
 *
 * @param mixed  $body            Decoded response.
 * @param string $transaction_key Expected transaction key.
 * @param bool   $allow_duplicate Whether a duplicate response is expected.
 * @return array<string, mixed>|WP_Error
 */
function wfcc_validate_salesforce_response($body, $transaction_key, $allow_duplicate = false) {
	if (!is_array($body)
		|| empty($body['transactionKey'])
		|| !hash_equals($transaction_key, (string) $body['transactionKey'])) {
		return wfcc_salesforce_error(
			'wfcc_salesforce_response_schema',
			__('Salesforce returned an invalid transaction response.', 'wfc-cart'),
			'response'
		);
	}

	$success   = true === ($body['success'] ?? false);
	$duplicate = true === ($body['duplicate'] ?? false);
	if (!$success && !($allow_duplicate && $duplicate)) {
		return wfcc_salesforce_error(
			'wfcc_salesforce_response_failed',
			__('Salesforce did not accept the transaction.', 'wfc-cart'),
			'validation'
		);
	}

	$records = isset($body['records']) && is_array($body['records']) ? $body['records'] : array();
	$allowed = array('transactionId', 'contactId', 'recurringGiftId');
	$output_records = array();
	foreach ($allowed as $key) {
		if (empty($records[$key])) {
			continue;
		}
		$value = sanitize_text_field($records[$key]);
		if (!preg_match('/^[A-Za-z0-9]{15}(?:[A-Za-z0-9]{3})?$/', $value)) {
			return wfcc_salesforce_error(
				'wfcc_salesforce_record_id_invalid',
				__('Salesforce returned an invalid record reference.', 'wfc-cart'),
				'response'
			);
		}
		$output_records[$key] = $value;
	}

	return array(
		'success'              => true,
		'duplicate'            => $duplicate,
		'records'              => $output_records,
		'reconciliationStatus' => isset($body['reconciliationStatus'])
			? sanitize_key($body['reconciliationStatus'])
			: 'synced',
	);
}

/**
 * Classify an unsuccessful Salesforce HTTP response.
 *
 * @param int $status HTTP status.
 * @return array{category:string,retryable:bool}
 */
function wfcc_classify_salesforce_status($status) {
	$status = absint($status);
	if (in_array($status, array(408, 429, 500, 502, 503, 504), true)) {
		return array('category' => 'availability', 'retryable' => true);
	}
	if (401 === $status) {
		return array('category' => 'authentication', 'retryable' => true);
	}
	if (403 === $status) {
		return array('category' => 'authorization', 'retryable' => false);
	}
	if (in_array($status, array(400, 404, 409, 410, 412, 422), true)) {
		return array('category' => 'validation', 'retryable' => false);
	}

	return array('category' => 'response', 'retryable' => false);
}

/**
 * Deliver one fixed transaction payload to Salesforce.
 *
 * @param array<string, mixed> $payload    Fixed payload.
 * @param bool                 $auth_retry Whether a 401 can refresh once.
 * @return array<string, mixed>|WP_Error
 */
function wfcc_salesforce_deliver_payload($payload, $auth_retry = true) {
	$transaction_key = isset($payload['transactionKey'])
		? wfcc_sanitize_transaction_key($payload['transactionKey'])
		: '';
	$path = wfcc_get_salesforce_api_path();
	if ('' === $transaction_key || '' === $path) {
		return wfcc_salesforce_error(
			'wfcc_salesforce_request_invalid',
			__('The Salesforce transaction request is invalid.', 'wfc-cart'),
			'configuration'
		);
	}

	$token = wfcc_salesforce_authenticate();
	if (is_wp_error($token)) {
		return $token;
	}

	$json = wp_json_encode($payload);
	if (!is_string($json)) {
		return wfcc_salesforce_error(
			'wfcc_salesforce_json_failed',
			__('The Salesforce transaction payload could not be encoded.', 'wfc-cart'),
			'payload'
		);
	}

	$response = wp_remote_post(
		$token['instance_url'] . $path,
		array(
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => array(
				'Accept'                => 'application/json',
				'Authorization'         => 'Bearer ' . $token['access_token'],
				'Content-Type'          => 'application/json',
				'Idempotency-Key'       => $transaction_key,
				'X-WFC-Transaction-Key' => $transaction_key,
			),
			'body'        => $json,
			'user-agent'  => 'WFC-Cart/' . WFCC_VERSION . '; ' . home_url('/'),
		)
	);
	if (is_wp_error($response)) {
		return wfcc_salesforce_error(
			'wfcc_salesforce_transport',
			__('Salesforce could not be reached.', 'wfc-cart'),
			'transport',
			true
		);
	}

	$status = wp_remote_retrieve_response_code($response);
	$body   = json_decode(wp_remote_retrieve_body($response), true);
	if (401 === $status && $auth_retry) {
		$refreshed = wfcc_salesforce_authenticate(true);
		if (is_wp_error($refreshed)) {
			return $refreshed;
		}

		return wfcc_salesforce_deliver_payload($payload, false);
	}

	if (($status >= 200 && $status < 300) || 409 === $status) {
		return wfcc_validate_salesforce_response($body, $transaction_key, 409 === $status);
	}

	$classification = wfcc_classify_salesforce_status($status);

	return wfcc_salesforce_error(
		'wfcc_salesforce_http_error',
		__('Salesforce rejected the transaction request.', 'wfc-cart'),
		$classification['category'],
		$classification['retryable'],
		$status
	);
}
