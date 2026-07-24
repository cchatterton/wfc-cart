<?php
/**
 * Shared WFC Cart settings and validation helpers.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Return all native WFC Cart settings.
 *
 * @return array<string, mixed>
 */
function wfcc_get_settings() {
	$settings = get_option('wfcc_settings', array());

	return is_array($settings) ? $settings : array();
}

/**
 * Return a native WFC Cart setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Default value.
 * @return mixed
 */
function wfcc_get_setting($key, $default = null) {
	$settings = wfcc_get_settings();

	return array_key_exists($key, $settings) ? $settings[$key] : $default;
}

/**
 * Resolve a secret without exposing its source to browser code.
 *
 * Precedence is wp-config.php constant, environment variable, then saved
 * WordPress setting. Empty values fall through to the next source.
 *
 * @param string $setting_key Setting array key.
 * @param string $constant    wp-config.php constant.
 * @param string $environment Environment variable.
 * @return string
 */
function wfcc_get_secret($setting_key, $constant, $environment) {
	if (defined($constant) && is_string(constant($constant)) && '' !== constant($constant)) {
		return constant($constant);
	}

	$environment_value = getenv($environment);
	if (is_string($environment_value) && '' !== $environment_value) {
		return $environment_value;
	}

	$value = wfcc_get_setting($setting_key, '');

	return is_string($value) ? $value : '';
}

/**
 * Return whether an arbitrary URL uses an approved thank-you destination.
 *
 * @param string $url URL to validate.
 * @return bool
 */
function wfcc_is_approved_redirect($url) {
	$url = esc_url_raw($url);
	if ('' === $url) {
		return false;
	}

	$target_host = wp_parse_url($url, PHP_URL_HOST);
	$site_host   = wp_parse_url(home_url('/'), PHP_URL_HOST);
	if ($target_host && $site_host && strtolower($target_host) === strtolower($site_host)) {
		return true;
	}

	$approved_hosts = wfcc_get_setting('approved_redirect_hosts', array());
	if (!is_array($approved_hosts)) {
		return false;
	}

	$approved_hosts = array_map('strtolower', array_map('sanitize_text_field', $approved_hosts));

	return $target_host && in_array(strtolower($target_host), $approved_hosts, true);
}

/**
 * Sanitise a stable transaction key.
 *
 * @param string $transaction_key Candidate key.
 * @return string
 */
function wfcc_sanitize_transaction_key($transaction_key) {
	$transaction_key = sanitize_key($transaction_key);

	return substr($transaction_key, 0, 100);
}

/**
 * Return a masked representation of a secret.
 *
 * @param string $secret Secret value.
 * @return string
 */
function wfcc_mask_secret($secret) {
	if ('' === $secret) {
		return '';
	}

	return str_repeat('•', 8) . substr($secret, -4);
}

