<?php
/**
 * WFC Cart capability registration.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Add WFC Cart capabilities to administrators.
 *
 * @return void
 */
function wfcc_add_capabilities() {
	$administrator = get_role('administrator');
	if (!$administrator) {
		return;
	}

	foreach (wfcc_get_capabilities() as $capability) {
		$administrator->add_cap($capability);
	}
}

/**
 * Return WFC Cart capabilities.
 *
 * @return string[]
 */
function wfcc_get_capabilities() {
	return array(
		'wfcc_manage_settings',
		'wfcc_view_transactions',
		'wfcc_retry_deliveries',
		'wfcc_view_sensitive_data',
		'wfcc_view_reports',
		'wfcc_export_transactions',
		'wfcc_import_operations',
		'wfcc_manage_batches',
		'wfcc_manage_receipts',
	);
}
