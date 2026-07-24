<?php
/**
 * Dependency detection and administrator notices.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('admin_notices', 'wfcc_dependency_notices');

/**
 * Show actionable dependency notices to administrators.
 *
 * @return void
 */
function wfcc_dependency_notices() {
	if (!current_user_can('activate_plugins')) {
		return;
	}

	if (!class_exists('GFForms')) {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__('WFC Cart requires Gravity Forms for donation and checkout forms. Record and migration tools remain available.', 'wfc-cart');
		echo '</p></div>';
	}

	if (!wfcc_get_setting('stripe_publishable_key') || !wfcc_get_secret('stripe_secret_key', 'WFCC_STRIPE_SECRET_KEY', 'WFCC_STRIPE_SECRET_KEY')) {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__('WFC Cart payments are unavailable until Stripe is configured. Non-tokenised card fields remain disabled.', 'wfc-cart');
		echo '</p></div>';
	}
}
