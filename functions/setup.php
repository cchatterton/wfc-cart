<?php
/**
 * Plugin lifecycle and scheduled processing setup.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('plugins_loaded', 'wfcc_maybe_upgrade_schema', 5);
add_filter('cron_schedules', 'wfcc_add_cron_schedules');

/**
 * Add the bounded delivery queue interval.
 *
 * @param array<string, array<string, int|string>> $schedules Schedules.
 * @return array<string, array<string, int|string>>
 */
function wfcc_add_cron_schedules($schedules) {
	$schedules['wfcc_five_minutes'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __('Every five minutes (WFC Cart)', 'wfc-cart'),
	);

	return $schedules;
}

/**
 * Ensure the Salesforce delivery queue uses its current recurrence.
 *
 * @param bool $replace Whether to replace any existing schedule.
 * @return void
 */
function wfcc_schedule_delivery_queue($replace = false) {
	if ($replace) {
		$timestamp = wp_next_scheduled('wfcc_process_delivery_queue');
		while ($timestamp) {
			wp_unschedule_event($timestamp, 'wfcc_process_delivery_queue');
			$timestamp = wp_next_scheduled('wfcc_process_delivery_queue');
		}
	}

	if (!wp_next_scheduled('wfcc_process_delivery_queue')) {
		wp_schedule_event(time() + MINUTE_IN_SECONDS, 'wfcc_five_minutes', 'wfcc_process_delivery_queue');
	}
}

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

	if (version_compare($installed_version, '5', '<')) {
		$settings += array(
			'salesforce_login_url'       => 'https://login.salesforce.com',
			'salesforce_api_path'        => '/services/apexrest/wfc-cart/v1/transactions',
			'salesforce_field_map'       => array(),
			'salesforce_required_fields' => array('email', 'last_name'),
			'delivery_retry_limit'       => 8,
		);
		update_option('wfcc_settings', $settings, false);
		wfcc_schedule_delivery_queue(true);
	}

	if (version_compare($installed_version, '6', '<')) {
		$settings += array(
			'receipt_generation_enabled' => true,
			'receipt_email_enabled'      => false,
			'receipt_number_prefix'      => 'WFC',
			'receipt_email_field_id'     => '',
			'receipt_email_subject'      => __('Your contribution receipt {receipt_number}', 'wfc-cart'),
		);
		update_option('wfcc_settings', $settings, false);
		wfcc_add_capabilities();
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
				'salesforce_login_url'    => 'https://login.salesforce.com',
				'salesforce_api_path'     => '/services/apexrest/wfc-cart/v1/transactions',
				'salesforce_field_map'    => array(),
				'salesforce_required_fields' => array('email', 'last_name'),
				'delivery_retry_limit'    => 8,
				'receipt_generation_enabled' => true,
				'receipt_email_enabled'  => false,
				'receipt_number_prefix'  => 'WFC',
				'receipt_email_field_id' => '',
				'receipt_email_subject'  => __('Your contribution receipt {receipt_number}', 'wfc-cart'),
			),
			'',
			false
		);
	}

	if (false === get_option('wfcc_schema_version', false)) {
		add_option('wfcc_schema_version', WFCC_SCHEMA_VERSION, '', false);
	}

	wfcc_schedule_delivery_queue();
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
