<?php
/**
 * Plugin lifecycle and scheduled processing setup.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('plugins_loaded', 'wfcc_maybe_upgrade_schema', 5);

/**
 * Apply safe WFC Cart option-schema upgrades.
 *
 * @return void
 */
function wfcc_maybe_upgrade_schema() {
	$installed_version = (string) get_option('wfcc_schema_version', '0');
	if (!version_compare($installed_version, WFCC_SCHEMA_VERSION, '<')) {
		return;
	}

	$settings = wfcc_get_settings();
	if (version_compare($installed_version, '3', '<') && isset($settings['mode'])) {
		unset($settings['mode']);
		update_option('wfcc_settings', $settings, false);
	}

	if (version_compare($installed_version, '3', '<')) {
		delete_option('wfcc_migration_state');
		$administrator = get_role('administrator');
		if ($administrator) {
			$administrator->remove_cap('wfcc_run_migrations');
		}
	}

	update_option('wfcc_schema_version', WFCC_SCHEMA_VERSION, false);
}

/**
 * Activate WFC Cart without modifying pre-existing site data.
 *
 * @return void
 */
function wfcc_activate() {
	wfcc_add_capabilities();

	if (false === get_option('wfcc_settings', false)) {
		add_option(
			'wfcc_settings',
			array(
				'currency'                => 'AUD',
				'approved_redirect_hosts' => array(),
				'checkout_packages'       => array(),
				'delivery_retry_limit'    => 8,
			),
			'',
			false
		);
	}

	if (false === get_option('wfcc_schema_version', false)) {
		add_option('wfcc_schema_version', WFCC_SCHEMA_VERSION, '', false);
	}

	if (!wp_next_scheduled('wfcc_process_delivery_queue')) {
		wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', 'wfcc_process_delivery_queue');
	}
	if (!wp_next_scheduled('wfcc_cleanup_idempotency')) {
		wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wfcc_cleanup_idempotency');
	}
}

/**
 * Deactivate WFC Cart while preserving all settings and records.
 *
 * @return void
 */
function wfcc_deactivate() {
	$timestamp = wp_next_scheduled('wfcc_process_delivery_queue');
	while ($timestamp) {
		wp_unschedule_event($timestamp, 'wfcc_process_delivery_queue');
		$timestamp = wp_next_scheduled('wfcc_process_delivery_queue');
	}

	$timestamp = wp_next_scheduled('wfcc_cleanup_idempotency');
	while ($timestamp) {
		wp_unschedule_event($timestamp, 'wfcc_cleanup_idempotency');
		$timestamp = wp_next_scheduled('wfcc_cleanup_idempotency');
	}
}
