<?php
/**
 * Minimal Stripe API client using the WordPress HTTP API.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Return the configured publishable key.
 *
 * @return string
 */
function wfcc_get_stripe_publishable_key() {
	return wfcc_get_secret('stripe_publishable_key', 'WFCC_STRIPE_PUBLISHABLE_KEY', 'WFCC_STRIPE_PUBLISHABLE_KEY');
}

/**
 * Send a request to one fixed Stripe API v1 resource.
 *
 * @param string                    $method          HTTP method.
 * @param string                    $resource        Allow-listed relative resource.
 * @param array<string, mixed>      $parameters      Form parameters.
 * @param string                    $idempotency_key Optional idempotency key.
 * @return array<string, mixed>|WP_Error
 */
function wfcc_stripe_request($method, $resource, $parameters = array(), $idempotency_key = '') {
	$secret = wfcc_get_secret('stripe_secret_key', 'WFCC_STRIPE_SECRET_KEY', 'WFCC_STRIPE_SECRET_KEY');
	if ('' === $secret) {
		return new WP_Error('wfcc_stripe_not_configured', __('Stripe is not configured.', 'wfc-cart'));
	}

	$method   = strtoupper($method);
	$resource = ltrim($resource, '/');
	if (!preg_match('#^(payment_intents|setup_intents|customers)(/[A-Za-z0-9_]+)?$#', $resource)) {
		return new WP_Error('wfcc_stripe_resource_rejected', __('The Stripe resource is not allowed.', 'wfc-cart'));
	}

	$headers = array(
		'Authorization' => 'Bearer ' . $secret,
		'Content-Type'  => 'application/x-www-form-urlencoded',
	);
	if ('POST' === $method && '' !== $idempotency_key) {
		$headers['Idempotency-Key'] = substr(sanitize_text_field($idempotency_key), 0, 255);
	}

	$args = array(
		'method'      => $method,
		'timeout'     => 20,
		'redirection' => 0,
		'headers'     => $headers,
		'user-agent'  => 'WFC-Cart/' . WFCC_VERSION . '; ' . home_url('/'),
	);
	if ('POST' === $method) {
		$args['body'] = $parameters;
	}

	$response = wp_remote_request('https://api.stripe.com/v1/' . $resource, $args);
	if (is_wp_error($response)) {
		return new WP_Error('wfcc_stripe_transport_error', __('Stripe could not be reached.', 'wfc-cart'));
	}

	$status = wp_remote_retrieve_response_code($response);
	$body   = json_decode(wp_remote_retrieve_body($response), true);
	if (!is_array($body)) {
		return new WP_Error('wfcc_stripe_invalid_response', __('Stripe returned an invalid response.', 'wfc-cart'));
	}
	if ($status < 200 || $status >= 300) {
		return new WP_Error('wfcc_stripe_api_error', __('Stripe could not prepare this payment.', 'wfc-cart'), array('status' => $status));
	}

	return $body;
}
