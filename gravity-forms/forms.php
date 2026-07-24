<?php
/**
 * WFC Cart Gravity Forms designation.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_filter('gform_form_settings', 'wfcc_add_gravity_forms_settings', 10, 2);
add_filter('gform_pre_form_settings_save', 'wfcc_save_gravity_forms_settings');

/**
 * Add WFC Cart roles to the Gravity Forms form settings screen.
 *
 * @param array<string, mixed> $settings Existing settings sections.
 * @param array<string, mixed> $form     Gravity Forms form.
 * @return array<string, mixed>
 */
function wfcc_add_gravity_forms_settings($settings, $form) {
	$cart_enabled     = !empty($form['wfcc_cart_enabled']);
	$checkout_enabled = !empty($form['wfcc_checkout_enabled']);

	$settings[__('WFC Cart', 'wfc-cart')]['wfcc_form_roles'] = sprintf(
		'<tr><th scope="row">%1$s</th><td>'
		. '<label><input type="checkbox" name="wfcc_cart_enabled" value="1" %2$s> %3$s</label><br>'
		. '<label><input type="checkbox" name="wfcc_checkout_enabled" value="1" %4$s> %5$s</label>'
		. '<p class="description">%6$s</p>'
		. '</td></tr>',
		esc_html__('Form roles', 'wfc-cart'),
		checked($cart_enabled, true, false),
		esc_html__('Collect WFC Cart line items', 'wfc-cart'),
		checked($checkout_enabled, true, false),
		esc_html__('Use as a WFC Cart checkout form', 'wfc-cart'),
		esc_html__('Payment fields are supplied by WFC Cart and Stripe. Do not add a standard card field.', 'wfc-cart')
	);

	return $settings;
}

/**
 * Save only WFC-prefixed form-role settings.
 *
 * @param array<string, mixed> $form Gravity Forms form.
 * @return array<string, mixed>
 */
function wfcc_save_gravity_forms_settings($form) {
	$form['wfcc_cart_enabled']     = isset($_POST['wfcc_cart_enabled']) ? 1 : 0;
	$form['wfcc_checkout_enabled'] = isset($_POST['wfcc_checkout_enabled']) ? 1 : 0;

	return $form;
}

/**
 * Return whether a form has the WFC checkout role.
 *
 * @param array<string, mixed>|object $form Gravity Forms form.
 * @return bool
 */
function wfcc_is_checkout_form($form) {
	if (is_object($form)) {
		return !empty($form->wfcc_checkout_enabled);
	}

	return is_array($form) && !empty($form['wfcc_checkout_enabled']);
}

/**
 * Return whether a form collects WFC cart line items.
 *
 * @param array<string, mixed>|object $form Gravity Forms form.
 * @return bool
 */
function wfcc_is_cart_form($form) {
	if (is_object($form)) {
		return !empty($form->wfcc_cart_enabled);
	}

	return is_array($form) && !empty($form['wfcc_cart_enabled']);
}

