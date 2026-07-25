<?php
/**
 * Salesforce External Client App authentication.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('admin_post_wfcc_test_salesforce_connection', 'wfcc_handle_salesforce_connection_test');

/**
 * Return whether a URL is an approved Salesforce HTTPS origin.
 *
 * @param string $url Salesforce URL.
 * @return bool
 */
function wfcc_is_salesforce_url($url) {
	$url    = esc_url_raw($url);
	$scheme = wp_parse_url($url, PHP_URL_SCHEME);
	$host   = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
	$path   = (string) wp_parse_url($url, PHP_URL_PATH);
	$port   = wp_parse_url($url, PHP_URL_PORT);

	return 'https' === $scheme
		&& (bool) preg_match('/(^|\.)salesforce\.com$/', $host)
		&& ('' === $path || '/' === $path)
		&& (null === $port || 443 === $port)
		&& empty(wp_parse_url($url, PHP_URL_USER))
		&& empty(wp_parse_url($url, PHP_URL_PASS))
		&& empty(wp_parse_url($url, PHP_URL_QUERY))
		&& empty(wp_parse_url($url, PHP_URL_FRAGMENT));
}

/**
 * Sanitise a Salesforce login origin.
 *
 * @param mixed $url Candidate URL.
 * @return string
 */
function wfcc_sanitize_salesforce_login_url($url) {
	$url = untrailingslashit(esc_url_raw((string) $url));

	return wfcc_is_salesforce_url($url) ? $url : '';
}

/**
 * Return the configured Salesforce login origin.
 *
 * @return string
 */
function wfcc_get_salesforce_login_url() {
	if (defined('WFCC_SALESFORCE_LOGIN_URL') && is_string(WFCC_SALESFORCE_LOGIN_URL)) {
		$url = wfcc_sanitize_salesforce_login_url(WFCC_SALESFORCE_LOGIN_URL);
		if ('' !== $url) {
			return $url;
		}
	}

	$environment = getenv('WFCC_SALESFORCE_LOGIN_URL');
	if (is_string($environment) && '' !== $environment) {
		$url = wfcc_sanitize_salesforce_login_url($environment);
		if ('' !== $url) {
			return $url;
		}
	}

	return wfcc_sanitize_salesforce_login_url(wfcc_get_setting('salesforce_login_url', ''));
}

/**
 * Return the Salesforce External Client App ID.
 *
 * @return string
 */
function wfcc_get_salesforce_client_id() {
	return wfcc_get_secret('salesforce_client_id', 'WFCC_SALESFORCE_CLIENT_ID', 'WFCC_SALESFORCE_CLIENT_ID');
}

/**
 * Return the Salesforce External Client App secret.
 *
 * @return string
 */
function wfcc_get_salesforce_client_secret() {
	return wfcc_get_secret('salesforce_client_secret', 'WFCC_SALESFORCE_CLIENT_SECRET', 'WFCC_SALESFORCE_CLIENT_SECRET');
}

/**
 * Hold the access token only for the current PHP request.
 *
 * @param string                    $action get, set, or clear.
 * @param array<string, string>|null $value Token and instance data.
 * @return array<string, string>|null
 */
function wfcc_salesforce_runtime_token($action = 'get', $value = null) {
	static $runtime_token = null;

	if ('clear' === $action) {
		$runtime_token = null;
	} elseif ('set' === $action) {
		$runtime_token = $value;
	}

	return $runtime_token;
}

/**
 * Create a sanitised Salesforce integration error.
 *
 * @param string $code      Internal code.
 * @param string $message   User-safe message.
 * @param string $category  Operational category.
 * @param bool   $retryable Whether queue retry is safe.
 * @param int    $status    HTTP status.
 * @return WP_Error
 */
function wfcc_salesforce_error($code, $message, $category, $retryable = false, $status = 0) {
	return new WP_Error(
		$code,
		$message,
		array(
			'category'  => sanitize_key($category),
			'retryable' => (bool) $retryable,
			'status'    => absint($status),
		)
	);
}

/**
 * Exchange client credentials for a memory-only access token.
 *
 * @param bool $force Force a new token.
 * @return array<string, string>|WP_Error
 */
function wfcc_salesforce_authenticate($force = false) {
	if (!wfcc_uses_salesforce_crm()) {
		return wfcc_salesforce_error(
			'wfcc_salesforce_disabled',
			__('Salesforce is disabled by the current CRM data-location setting.', 'wfc-cart'),
			'configuration'
		);
	}

	if ($force) {
		wfcc_salesforce_runtime_token('clear');
	}

	$cached = wfcc_salesforce_runtime_token();
	if (is_array($cached) && !empty($cached['access_token']) && !empty($cached['instance_url'])) {
		return $cached;
	}

	$login_url    = wfcc_get_salesforce_login_url();
	$client_id    = wfcc_get_salesforce_client_id();
	$client_secret = wfcc_get_salesforce_client_secret();
	if ('' === $login_url || '' === $client_id || '' === $client_secret) {
		return wfcc_salesforce_error(
			'wfcc_salesforce_not_configured',
			__('Salesforce authentication is not fully configured.', 'wfc-cart'),
			'configuration'
		);
	}

	$response = wp_remote_post(
		$login_url . '/services/oauth2/token',
		array(
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'        => array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
			),
			'user-agent'  => 'WFC-Cart/' . WFCC_VERSION . '; ' . home_url('/'),
		)
	);
	if (is_wp_error($response)) {
		return wfcc_salesforce_error(
			'wfcc_salesforce_auth_transport',
			__('Salesforce authentication could not be reached.', 'wfc-cart'),
			'transport',
			true
		);
	}

	$status = wp_remote_retrieve_response_code($response);
	$body   = json_decode(wp_remote_retrieve_body($response), true);
	if ($status < 200 || $status >= 300 || !is_array($body)) {
		$retryable = in_array($status, array(408, 429, 500, 502, 503, 504), true);

		return wfcc_salesforce_error(
			'wfcc_salesforce_auth_rejected',
			__('Salesforce rejected the integration credentials.', 'wfc-cart'),
			$retryable ? 'availability' : 'authentication',
			$retryable,
			$status
		);
	}

	$access_token = isset($body['access_token']) && is_string($body['access_token']) ? $body['access_token'] : '';
	$instance_url = isset($body['instance_url']) ? wfcc_sanitize_salesforce_login_url($body['instance_url']) : '';
	if ('' === $access_token || '' === $instance_url) {
		return wfcc_salesforce_error(
			'wfcc_salesforce_auth_schema',
			__('Salesforce returned an invalid authentication response.', 'wfc-cart'),
			'response'
		);
	}

	$token = array(
		'access_token' => $access_token,
		'instance_url' => $instance_url,
	);
	wfcc_salesforce_runtime_token('set', $token);

	return $token;
}

/**
 * Return the last connection diagnostic without any credentials.
 *
 * @return array<string, mixed>
 */
function wfcc_get_salesforce_connection_diagnostic() {
	$diagnostic = get_option('wfcc_salesforce_connection_diagnostic', array());

	return is_array($diagnostic) ? $diagnostic : array();
}

/**
 * Test authentication from a nonce-protected administration action.
 *
 * @return void
 */
function wfcc_handle_salesforce_connection_test() {
	if (!current_user_can('wfcc_manage_settings')) {
		wp_die(esc_html__('You are not allowed to test Salesforce.', 'wfc-cart'));
	}
	check_admin_referer('wfcc_test_salesforce_connection');
	if (!wfcc_uses_salesforce_crm()) {
		wp_die(esc_html__('Salesforce is disabled by the current CRM data-location setting.', 'wfc-cart'));
	}

	$result = wfcc_salesforce_authenticate(true);
	$error_data = is_wp_error($result) ? $result->get_error_data() : array();
	$error_data = is_array($error_data) ? $error_data : array();
	$diagnostic = array(
		'checked_at' => gmdate('c'),
		'status'     => is_wp_error($result) ? 'failed' : 'ok',
		'category'   => is_wp_error($result) ? sanitize_key((string) ($error_data['category'] ?? 'unknown')) : '',
	);
	update_option('wfcc_salesforce_connection_diagnostic', $diagnostic, false);

	$url = add_query_arg(
		array(
			'page'                  => 'wfcc-settings',
			'tab'                   => 'salesforce',
			'wfcc_connection_test'  => $diagnostic['status'],
		),
		admin_url('admin.php')
	);
	wp_safe_redirect($url);
	exit;
}
