<?php
/**
 * Plugin lifecycle and scheduled processing setup.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('plugins_loaded', 'wfcc_maybe_upgrade_schema', 5);
add_filter('cron_schedules', 'wfcc_add_cron_schedules');
add_action('wp_initialize_site', 'wfcc_initialize_new_site', 100, 2);

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

	if (version_compare($installed_version, '7', '<')) {
		$settings += array(
			'trusted_proxy_cidrs' => array(),
		);
		update_option('wfcc_settings', $settings, false);
	}

	update_option('wfcc_schema_version', WFCC_SCHEMA_VERSION, false);
	update_option(
		'wfcc_last_schema_upgrade',
		array(
			'from'       => $installed_version,
			'to'         => WFCC_SCHEMA_VERSION,
			'version'    => WFCC_VERSION,
			'upgraded_at' => gmdate('c'),
		),
		false
	);
}

/**
 * Run a callback once in each multisite blog using bounded queries.
 *
 * @param callable $callback Site callback.
 * @return void
 */
function wfcc_for_each_site($callback) {
	if (!is_multisite()) {
		call_user_func($callback);
		return;
	}

	$offset = 0;
	do {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $offset,
			)
		);
		foreach ($site_ids as $site_id) {
			switch_to_blog(absint($site_id));
			try {
				call_user_func($callback);
			} finally {
				restore_current_blog();
			}
		}
		$offset += count($site_ids);
	} while (100 === count($site_ids));
}

/**
 * Activate WFC Cart for the current site without modifying existing data.
 *
 * @return void
 */
function wfcc_activate_site() {
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
				'trusted_proxy_cidrs'    => array(),
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
 * Activate one site or every existing site during network activation.
 *
 * @param bool $network_wide Network activation.
 * @return void
 */
function wfcc_activate($network_wide = false) {
	if ($network_wide && is_multisite()) {
		wfcc_for_each_site('wfcc_activate_site');
		return;
	}

	wfcc_activate_site();
}

/**
 * Stop scheduled jobs for the current site while preserving all data.
 *
 * @return void
 */
function wfcc_deactivate_site() {
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

/**
 * Deactivate one site or every site after network deactivation.
 *
 * @param bool $network_wide Network deactivation.
 * @return void
 */
function wfcc_deactivate($network_wide = false) {
	if ($network_wide && is_multisite()) {
		wfcc_for_each_site('wfcc_deactivate_site');
		return;
	}

	wfcc_deactivate_site();
}

/**
 * Initialise a newly created site when WFC Cart is network active.
 *
 * @param WP_Site $new_site New site.
 * @param array   $args     Site creation arguments.
 * @return void
 */
function wfcc_initialize_new_site($new_site, $args = array()) {
	unset($args);
	if (!is_multisite() || !is_object($new_site) || empty($new_site->blog_id)) {
		return;
	}

	if (!function_exists('is_plugin_active_for_network')) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if (!is_plugin_active_for_network(plugin_basename(WFCC_PLUGIN_FILE))) {
		return;
	}

	switch_to_blog(absint($new_site->blog_id));
	try {
		wfcc_activate_site();
	} finally {
		restore_current_blog();
	}
}
