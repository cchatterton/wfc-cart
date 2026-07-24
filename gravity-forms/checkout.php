<?php
/**
 * Gravity Forms and Stripe Payment Element checkout integration.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('gform_enqueue_scripts', 'wfcc_enqueue_checkout_assets', 10, 2);
add_filter('gform_submit_button', 'wfcc_render_payment_element', 10, 2);
add_filter('gform_validation', 'wfcc_validate_checkout_submission');
add_action('gform_after_submission', 'wfcc_attach_entry_to_transaction', 10, 2);
add_filter('gform_confirmation', 'wfcc_checkout_confirmation', 10, 4);

/**
 * Return the package IDs approved for a Gravity Forms checkout form.
 *
 * @param int $form_id Form ID.
 * @return array<int, string>
 */
function wfcc_allowed_packages_for_form($form_id) {
	if (!class_exists('GFAPI')) {
		return array();
	}

	$form = GFAPI::get_form(absint($form_id));
	if (!$form || !wfcc_is_checkout_form($form)) {
		return array();
	}

	$packages = array();
	if (!empty($form['wfcc_default_package'])) {
		$packages[] = sanitize_key($form['wfcc_default_package']);
	}

	/**
	 * Filter package IDs approved for a checkout form.
	 *
	 * @param array<int, string> $packages Package IDs.
	 * @param int                $form_id  Form ID.
	 */
	$packages = apply_filters('wfcc_allowed_packages_for_form', $packages, absint($form_id));

	return array_values(array_unique(array_filter(array_map('sanitize_key', is_array($packages) ? $packages : array()))));
}

/**
 * Return whether a package is approved for a form.
 *
 * @param string $package_id Package ID.
 * @param int    $form_id    Form ID.
 * @return bool
 */
function wfcc_package_is_allowed_for_form($package_id, $form_id) {
	return in_array(sanitize_key($package_id), wfcc_allowed_packages_for_form($form_id), true);
}

/**
 * Resolve the current request's approved package for a form.
 *
 * @param array<string, mixed> $form Form.
 * @return array<string, mixed>|null
 */
function wfcc_checkout_package_for_form($form) {
	$form_id = isset($form['id']) ? absint($form['id']) : 0;
	$default = isset($form['wfcc_default_package']) ? sanitize_key($form['wfcc_default_package']) : '';
	$requested = isset($_GET['package']) ? sanitize_key(wp_unslash($_GET['package'])) : $default;
	if (!wfcc_package_is_allowed_for_form($requested, $form_id)) {
		$requested = $default;
	}

	return wfcc_get_checkout_package($requested);
}

/**
 * Enqueue Stripe-hosted fields and the WFC controller only for checkout forms.
 *
 * @param array<string, mixed> $form Form.
 * @param bool                 $ajax AJAX form.
 * @return void
 */
function wfcc_enqueue_checkout_assets($form, $ajax) {
	unset($ajax);
	if (!wfcc_is_checkout_form($form) || !wfcc_checkout_package_for_form($form)) {
		return;
	}

	wp_enqueue_style('wfcc-checkout');
	wp_enqueue_script('wfcc-stripe');
	wp_enqueue_script('wfcc-checkout');
}

/**
 * Add the Payment Element immediately before the Gravity Forms submit button.
 *
 * @param string               $button Existing submit button.
 * @param array<string, mixed> $form   Form.
 * @return string
 */
function wfcc_render_payment_element($button, $form) {
	if (!wfcc_is_checkout_form($form)) {
		return $button;
	}

	$package = wfcc_checkout_package_for_form($form);
	if (!$package) {
		return '<div class="wfcc-checkout__error" role="alert">'
			. esc_html__('This checkout does not have an enabled payment package.', 'wfc-cart')
			. '</div>' . $button;
	}

	$form_id = absint($form['id']);
	$markup  = sprintf(
		'<section class="wfcc-checkout" data-wfcc-checkout data-wfcc-form-id="%1$d" data-wfcc-package="%2$s" data-wfcc-amount-field="%3$d" data-wfcc-consent-field="%4$d" data-wfcc-publishable-key="%5$s" data-wfcc-intent-url="%6$s" aria-busy="true">'
		. '<div class="wfcc-checkout__section wfcc-checkout__payment">'
		. '<div id="wfcc-payment-element-%1$d" data-wfcc-payment-element></div>'
		. '<p class="wfcc-checkout__error" data-wfcc-error role="alert" hidden></p>'
		. '<p data-wfcc-status role="status" aria-live="polite">%7$s</p>'
		. '</div>'
		. '<input type="hidden" name="wfcc_transaction_key" value="">'
		. '<input type="hidden" name="wfcc_intent_id" value="">'
		. '<div class="wfcc-checkout__submit">%8$s</div>'
		. '</section>',
		$form_id,
		esc_attr($package['id']),
		isset($package['amount_field_id']) ? absint($package['amount_field_id']) : 0,
		isset($package['consent_field_id']) ? absint($package['consent_field_id']) : 0,
		esc_attr(wfcc_get_stripe_publishable_key()),
		esc_url(rest_url('wfc-cart/v1/checkout/intents')),
		esc_html__('Preparing secure payment fields…', 'wfc-cart'),
		$button
	);

	return $markup;
}

/**
 * Fail a Gravity Forms validation result with an accessible message.
 *
 * @param array<string, mixed> $result  Validation result.
 * @param string               $message Failure message.
 * @return array<string, mixed>
 */
function wfcc_checkout_validation_error($result, $message) {
	$result['is_valid'] = false;
	$result['form']['validation_message'] = '<div class="validation_error" role="alert">' . esc_html($message) . '</div>';

	return $result;
}

/**
 * Return whether any submitted input belonging to a Gravity Forms field has a value.
 *
 * @param int $field_id Field ID.
 * @return bool
 */
function wfcc_posted_gf_field_has_value($field_id) {
	$exact  = 'input_' . absint($field_id);
	$prefix = $exact . '_';
	foreach ($_POST as $key => $value) {
		if ($key !== $exact && 0 !== strpos((string) $key, $prefix)) {
			continue;
		}
		$value = wp_unslash($value);
		$value = is_array($value) ? implode('', array_map('strval', $value)) : (string) $value;
		if ('' !== trim($value)) {
			return true;
		}
	}

	return false;
}

/**
 * Verify the completed intent with Stripe before Gravity Forms accepts checkout.
 *
 * @param array<string, mixed> $result Gravity Forms validation result.
 * @return array<string, mixed>
 */
function wfcc_validate_checkout_submission($result) {
	$form = isset($result['form']) ? $result['form'] : array();
	if (!wfcc_is_checkout_form($form)) {
		return $result;
	}

	$transaction_key = isset($_POST['wfcc_transaction_key'])
		? wfcc_sanitize_transaction_key(wp_unslash($_POST['wfcc_transaction_key']))
		: '';
	$posted_intent = isset($_POST['wfcc_intent_id'])
		? sanitize_text_field(wp_unslash($_POST['wfcc_intent_id']))
		: '';
	$transaction_id = wfcc_find_transaction('wfcc_transaction_key', $transaction_key);
	if (!$transaction_id || '' === $posted_intent) {
		return wfcc_checkout_validation_error($result, __('Complete the secure payment section before submitting.', 'wfc-cart'));
	}

	$form_id = absint($form['id']);
	if ($form_id !== absint(get_post_meta($transaction_id, 'wfcc_form_id', true))) {
		return wfcc_checkout_validation_error($result, __('The payment does not belong to this checkout form.', 'wfc-cart'));
	}

	$package_id = sanitize_key(get_post_meta($transaction_id, 'wfcc_package_id', true));
	$package    = wfcc_get_checkout_package($package_id);
	if (!$package || !wfcc_package_is_allowed_for_form($package_id, $form_id)) {
		return wfcc_checkout_validation_error($result, __('The payment package is no longer approved.', 'wfc-cart'));
	}

	if (!empty($package['recurring']) || 'setup' === $package['mode']) {
		$consent_field = isset($package['consent_field_id']) ? absint($package['consent_field_id']) : 0;
		if (!$consent_field || !wfcc_posted_gf_field_has_value($consent_field)) {
			return wfcc_checkout_validation_error($result, __('Consent is required for recurring off-session payments.', 'wfc-cart'));
		}
		update_post_meta($transaction_id, 'wfcc_consent_recorded_at', gmdate('c'));
		update_post_meta($transaction_id, 'wfcc_consent_field_id', $consent_field);
	}

	$intent = wfcc_retrieve_transaction_intent($transaction_id);
	if (is_wp_error($intent) || empty($intent['id']) || !hash_equals((string) $intent['id'], $posted_intent)) {
		return wfcc_checkout_validation_error($result, __('The payment could not be verified. Please try again.', 'wfc-cart'));
	}

	$expected_amount   = absint(get_post_meta($transaction_id, 'wfcc_amount', true));
	$expected_currency = strtolower(get_post_meta($transaction_id, 'wfcc_currency', true));
	if ('payment_intent' === $intent['object']) {
		if ('succeeded' !== $intent['status']
			|| $expected_amount !== absint($intent['amount'])
			|| $expected_currency !== strtolower($intent['currency'])) {
			return wfcc_checkout_validation_error($result, __('The payment has not completed for the approved amount.', 'wfc-cart'));
		}
		wfcc_transition_transaction($transaction_id, 'succeeded', $intent['status']);
	} elseif ('setup_intent' === $intent['object']) {
		if ('succeeded' !== $intent['status']) {
			return wfcc_checkout_validation_error($result, __('The payment method setup has not completed.', 'wfc-cart'));
		}
		wfcc_transition_transaction($transaction_id, 'setup_succeeded', $intent['status']);
	} else {
		return wfcc_checkout_validation_error($result, __('Stripe returned an unsupported payment object.', 'wfc-cart'));
	}

	if (!empty($intent['payment_method'])) {
		update_post_meta($transaction_id, 'wfcc_stripe_payment_method_id', sanitize_text_field($intent['payment_method']));
	}
	if (!empty($intent['customer'])) {
		update_post_meta($transaction_id, 'wfcc_stripe_customer_id', sanitize_text_field($intent['customer']));
	}

	return $result;
}

/**
 * Link the accepted Gravity Forms entry to its protected transaction.
 *
 * @param array<string, mixed> $entry Entry.
 * @param array<string, mixed> $form  Form.
 * @return void
 */
function wfcc_attach_entry_to_transaction($entry, $form) {
	if (!wfcc_is_checkout_form($form)) {
		return;
	}

	$transaction_key = isset($_POST['wfcc_transaction_key'])
		? wfcc_sanitize_transaction_key(wp_unslash($_POST['wfcc_transaction_key']))
		: '';
	$transaction_id = wfcc_find_transaction('wfcc_transaction_key', $transaction_key);
	if (!$transaction_id) {
		return;
	}

	update_post_meta($transaction_id, 'wfcc_gravity_forms_entry_id', isset($entry['id']) ? absint($entry['id']) : 0);
	do_action('wfcc_checkout_completed', $transaction_id, $entry, $form);
}

/**
 * Use only the package's server-approved thank-you URL.
 *
 * @param mixed                $confirmation Existing confirmation.
 * @param array<string, mixed> $form         Form.
 * @param array<string, mixed> $entry        Entry.
 * @param bool                 $ajax         AJAX submission.
 * @return mixed
 */
function wfcc_checkout_confirmation($confirmation, $form, $entry, $ajax) {
	unset($entry, $ajax);
	if (!wfcc_is_checkout_form($form)) {
		return $confirmation;
	}

	$transaction_key = isset($_POST['wfcc_transaction_key'])
		? wfcc_sanitize_transaction_key(wp_unslash($_POST['wfcc_transaction_key']))
		: '';
	$transaction_id = wfcc_find_transaction('wfcc_transaction_key', $transaction_key);
	$package_id     = $transaction_id ? get_post_meta($transaction_id, 'wfcc_package_id', true) : '';
	$package        = wfcc_get_checkout_package($package_id);
	if ($package && !empty($package['thank_you_url']) && wfcc_is_approved_redirect($package['thank_you_url'])) {
		return array('redirect' => esc_url_raw($package['thank_you_url']));
	}

	return $confirmation;
}
