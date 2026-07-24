<?php
/**
 * Bounded storage for intent and webhook idempotency records.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('wfcc_cleanup_idempotency', 'wfcc_cleanup_idempotency_records');

/**
 * Remove expired non-autoloaded idempotency options in bounded batches.
 *
 * Intent replays are retained for 48 hours. Stripe event IDs are retained for
 * 30 days, beyond the normal automatic webhook retry period.
 *
 * @return void
 */
function wfcc_cleanup_idempotency_records() {
	global $wpdb;

	$intent_like = $wpdb->esc_like('wfcc_intent_') . '%';
	$event_like  = $wpdb->esc_like('wfcc_evt_') . '%';
	$query       = $wpdb->prepare(
		"SELECT option_name, option_value FROM {$wpdb->options}
		WHERE option_name LIKE %s OR option_name LIKE %s
		LIMIT %d",
		$intent_like,
		$event_like,
		1000
	);
	$records = $wpdb->get_results($query, ARRAY_A);
	if (!is_array($records)) {
		return;
	}

	$now = time();
	foreach ($records as $record) {
		$name  = isset($record['option_name']) ? (string) $record['option_name'] : '';
		$value = isset($record['option_value']) ? maybe_unserialize($record['option_value']) : array();
		if (!is_array($value) || empty($value['created'])) {
			continue;
		}

		$maximum_age = 0 === strpos($name, 'wfcc_evt_') ? 30 * DAY_IN_SECONDS : 2 * DAY_IN_SECONDS;
		if (absint($value['created']) < $now - $maximum_age) {
			delete_option($name);
		}
	}
}
