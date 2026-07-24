<?php
/**
 * WFC Cart asset registration.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('wp_enqueue_scripts', 'wfcc_register_frontend_assets');
add_action('admin_enqueue_scripts', 'wfcc_register_admin_assets');

/**
 * Register frontend assets for WFC-native checkout components.
 *
 * @return void
 */
function wfcc_register_frontend_assets() {
	wp_register_script(
		'wfcc-stripe',
		'https://js.stripe.com/v3/',
		array(),
		null,
		true
	);
	wp_register_style(
		'wfcc-checkout',
		WFCC_PLUGIN_URL . 'styles/wfc-cart.css',
		array(),
		WFCC_VERSION
	);
	wp_register_script(
		'wfcc-checkout',
		WFCC_PLUGIN_URL . 'scripts/wfc-cart-checkout.js',
		array('wfcc-stripe'),
		WFCC_VERSION,
		true
	);
}

/**
 * Enqueue administration styles only on WFC Cart screens.
 *
 * @param string $hook_suffix Current admin hook.
 * @return void
 */
function wfcc_register_admin_assets($hook_suffix) {
	if (false === strpos((string) $hook_suffix, 'wfcc')) {
		return;
	}

	wp_enqueue_style(
		'wfcc-admin',
		WFCC_PLUGIN_URL . 'styles/wfc-cart-admin.css',
		array(),
		WFCC_VERSION
	);
}
