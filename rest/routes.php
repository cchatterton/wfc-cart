<?php
/**
 * Public WFC checkout and Stripe webhook REST routes.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('rest_api_init', 'wfcc_register_rest_routes');

/**
 * Register explicitly public, internally authenticated routes.
 *
 * @return void
 */
function wfcc_register_rest_routes() {
	register_rest_route(
		'wfc-cart/v1',
		'/checkout/intents',
		array(
			'methods'             => 'POST',
			'callback'            => 'wfcc_rest_create_intent',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'wfc-cart/v1',
		'/stripe/webhook',
		array(
			'methods'             => 'POST',
			'callback'            => 'wfcc_rest_stripe_webhook',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Build a privacy-safe request fingerprint.
 *
 * @return string
 */
function wfcc_request_fingerprint() {
	$address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
	$address = apply_filters('wfcc_rate_limit_identifier', $address);

	return hash_hmac('sha256', (string) $address, wp_salt('auth'));
}

/**
 * Enforce a small per-address intent-creation window.
 *
 * @return true|WP_Error
 */
function wfcc_check_intent_rate_limit() {
	$key   = 'wfcc_rl_' . substr(wfcc_request_fingerprint(), 0, 40);
	$count = absint(get_transient($key));
	if ($count >= 10) {
		return new WP_Error('wfcc_rate_limited', __('Too many payment attempts. Please wait and try again.', 'wfc-cart'), array('status' => 429));
	}

	set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);

	return true;
}

/**
 * Create or replay an intent for an approved checkout package.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function wfcc_rest_create_intent($request) {
	$limited = wfcc_check_intent_rate_limit();
	if (is_wp_error($limited)) {
		return $limited;
	}

	$package_id = sanitize_key((string) $request->get_param('package'));
	$package    = wfcc_get_checkout_package($package_id);
	$form_id    = absint($request->get_param('form_id'));
	if (!$package || $form_id < 1) {
		return new WP_Error('wfcc_invalid_checkout', __('The checkout package or form is invalid.', 'wfc-cart'), array('status' => 400));
	}
	if (!wfcc_package_is_allowed_for_form($package_id, $form_id)) {
		return new WP_Error('wfcc_package_form_mismatch', __('This package is not approved for the checkout form.', 'wfc-cart'), array('status' => 400));
	}

	$amount = 'setup' === $package['mode'] ? 0 : wfcc_resolve_package_amount($package, $request->get_param('amount'));
	if ('payment' === $package['mode'] && $amount < 1) {
		return new WP_Error('wfcc_invalid_amount', __('The donation amount is not approved for this package.', 'wfc-cart'), array('status' => 400));
	}

	$client_key = sanitize_text_field((string) $request->get_param('idempotency_key'));
	if (!preg_match('/^[A-Za-z0-9_-]{16,100}$/', $client_key)) {
		return new WP_Error('wfcc_invalid_idempotency_key', __('The checkout request key is invalid.', 'wfc-cart'), array('status' => 400));
	}

	$scope      = hash_hmac('sha256', $form_id . '|' . $package_id . '|' . $amount . '|' . $client_key, wp_salt('auth'));
	$option_key = 'wfcc_intent_' . substr($scope, 0, 40);
	$existing   = get_option($option_key, false);
	if (is_array($existing) && !empty($existing['transaction_id'])) {
		return wfcc_rest_intent_response(absint($existing['transaction_id']));
	}
	if (is_array($existing) && !empty($existing['created']) && absint($existing['created']) < time() - 120) {
		delete_option($option_key);
	}

	$lock = array('created' => time(), 'transaction_id' => 0);
	if (!add_option($option_key, $lock, '', false)) {
		$existing = get_option($option_key, false);
		if (is_array($existing) && !empty($existing['transaction_id'])) {
			return wfcc_rest_intent_response(absint($existing['transaction_id']));
		}

		return new WP_Error('wfcc_intent_in_progress', __('This payment attempt is already being prepared.', 'wfc-cart'), array('status' => 409));
	}

	$transaction_key = wfcc_generate_transaction_key();
	$transaction_id  = wfcc_create_transaction($transaction_key, $package, $amount, $form_id);
	if (is_wp_error($transaction_id)) {
		delete_option($option_key);
		return $transaction_id;
	}

	$intent = wfcc_create_stripe_intent($transaction_id, $transaction_key, $package, $amount, 'wfcc-' . $scope);
	if (is_wp_error($intent)) {
		wfcc_transition_transaction($transaction_id, 'failed');
		delete_option($option_key);
		return $intent;
	}

	update_option($option_key, array('created' => time(), 'transaction_id' => $transaction_id), false);

	return wfcc_rest_intent_response($transaction_id, $intent);
}

/**
 * Return only the browser-required intent data.
 *
 * @param int                       $transaction_id Transaction ID.
 * @param array<string, mixed>|null $intent         Optional fresh Stripe response.
 * @return WP_REST_Response|WP_Error
 */
function wfcc_rest_intent_response($transaction_id, $intent = null) {
	if (null === $intent) {
		$intent = wfcc_retrieve_transaction_intent($transaction_id);
	}
	if (is_wp_error($intent)) {
		return $intent;
	}
	if (empty($intent['client_secret'])) {
		return new WP_Error('wfcc_missing_client_secret', __('Stripe did not return a checkout secret.', 'wfc-cart'), array('status' => 502));
	}

	return rest_ensure_response(
		array(
			'client_secret'  => $intent['client_secret'],
			'intent_type'    => 'setup_intent' === $intent['object'] ? 'setup' : 'payment',
			'transaction_key' => get_post_meta($transaction_id, 'wfcc_transaction_key', true),
		)
	);
}

/**
 * Verify and reconcile a Stripe webhook.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function wfcc_rest_stripe_webhook($request) {
	$payload   = $request->get_body();
	$signature = $request->get_header('stripe-signature');
	$secret    = wfcc_get_secret('stripe_webhook_secret', 'WFCC_STRIPE_WEBHOOK_SECRET', 'WFCC_STRIPE_WEBHOOK_SECRET');
	$verified  = wfcc_verify_stripe_signature($payload, $signature, $secret);
	if (is_wp_error($verified)) {
		return new WP_Error($verified->get_error_code(), $verified->get_error_message(), array('status' => 400));
	}

	$event = json_decode($payload, true);
	if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
		return new WP_Error('wfcc_invalid_stripe_event', __('The Stripe event is invalid.', 'wfc-cart'), array('status' => 400));
	}

	$event_id = sanitize_text_field($event['id']);
	if (!preg_match('/^evt_[A-Za-z0-9]+$/', $event_id)) {
		return new WP_Error('wfcc_invalid_stripe_event_id', __('The Stripe event ID is invalid.', 'wfc-cart'), array('status' => 400));
	}

	$states = wfcc_stripe_event_states();
	$event_type = sanitize_text_field($event['type']);
	if (!preg_match('/^[a-z0-9_.]+$/', $event_type) || !isset($states[$event_type])) {
		return rest_ensure_response(array('received' => true, 'handled' => false));
	}

	$dedupe_key = 'wfcc_evt_' . substr(hash_hmac('sha256', $event_id, wp_salt('auth')), 0, 40);
	$existing_event = get_option($dedupe_key, false);
	if (is_array($existing_event)
		&& 'processing' === ($existing_event['state'] ?? '')
		&& !empty($existing_event['created'])
		&& absint($existing_event['created']) < time() - 300) {
		delete_option($dedupe_key);
	}
	if (!add_option($dedupe_key, array('state' => 'processing', 'created' => time()), '', false)) {
		return rest_ensure_response(array('received' => true, 'duplicate' => true));
	}

	$result = wfcc_reconcile_stripe_event($event);
	if (is_wp_error($result)) {
		delete_option($dedupe_key);
		return new WP_Error($result->get_error_code(), $result->get_error_message(), array('status' => 409));
	}

	update_option($dedupe_key, array('state' => 'complete', 'created' => time()), false);

	return rest_ensure_response(array('received' => true, 'handled' => true));
}
