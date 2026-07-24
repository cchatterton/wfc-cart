<?php
/**
 * Persistent Salesforce delivery outbox and controlled retries.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('wfcc_checkout_completed', 'wfcc_queue_completed_checkout_for_salesforce', 10, 3);
add_action('wfcc_process_delivery_queue', 'wfcc_process_salesforce_delivery_queue');
add_action('admin_post_wfcc_retry_delivery', 'wfcc_handle_manual_delivery_retry');

/**
 * Queue a completed checkout.
 *
 * @param int                  $transaction_id Transaction post ID.
 * @param array<string, mixed> $entry          Gravity Forms entry.
 * @param array<string, mixed> $form           Gravity Forms form.
 * @return void
 */
function wfcc_queue_completed_checkout_for_salesforce($transaction_id, $entry, $form) {
	unset($entry, $form);
	wfcc_enqueue_salesforce_delivery($transaction_id, 'upsert');
}

/**
 * Create or restart the outbox state for one transaction.
 *
 * @param int    $transaction_id Transaction post ID.
 * @param string $operation      upsert or reconcile.
 * @return bool
 */
function wfcc_enqueue_salesforce_delivery($transaction_id, $operation = 'upsert') {
	$payment_state = sanitize_key(get_post_meta($transaction_id, 'wfcc_payment_state', true));
	if (!in_array($payment_state, array('succeeded', 'setup_succeeded', 'partially_refunded', 'refunded', 'disputed', 'cancelled'), true)) {
		return false;
	}
	if (!get_post_meta($transaction_id, 'wfcc_gravity_forms_entry_id', true)) {
		return false;
	}

	$current = sanitize_key(get_post_meta($transaction_id, 'wfcc_salesforce_state', true));
	if ('upsert' === $operation && in_array($current, array('salesforce_pending', 'salesforce_delivering', 'salesforce_delivered'), true)) {
		return true;
	}

	update_post_meta($transaction_id, 'wfcc_salesforce_state', 'salesforce_pending');
	update_post_meta($transaction_id, 'wfcc_salesforce_operation', 'reconcile' === $operation ? 'reconcile' : 'upsert');
	update_post_meta($transaction_id, 'wfcc_salesforce_payload_version', WFCC_SALESFORCE_PAYLOAD_VERSION);
	update_post_meta($transaction_id, 'wfcc_salesforce_delivery_attempts', 0);
	update_post_meta($transaction_id, 'wfcc_salesforce_next_attempt', gmdate('Y-m-d H:i:s'));
	update_post_meta($transaction_id, 'wfcc_salesforce_reconciliation_state', 'pending');
	if (!get_post_meta($transaction_id, 'wfcc_initial_payment_state', true)) {
		update_post_meta($transaction_id, 'wfcc_initial_payment_state', $payment_state);
	}
	delete_post_meta($transaction_id, 'wfcc_salesforce_last_error_category');
	delete_post_meta($transaction_id, 'wfcc_salesforce_last_error_code');
	delete_post_meta($transaction_id, 'wfcc_salesforce_last_http_status');

	return true;
}

/**
 * Return exponential delivery backoff with deterministic small jitter.
 *
 * @param int $attempt        One-based attempt number.
 * @param int $transaction_id Transaction post ID.
 * @return int
 */
function wfcc_salesforce_retry_delay($attempt, $transaction_id = 0) {
	$delays = array(60, 300, 900, 3600, 21600, 43200, 86400);
	$index  = min(max(1, absint($attempt)), count($delays)) - 1;
	$jitter = absint($transaction_id) % 31;

	return $delays[$index] + $jitter;
}

/**
 * Acquire a stale-safe delivery lock.
 *
 * @param int $transaction_id Transaction ID.
 * @return string|false
 */
function wfcc_acquire_delivery_lock($transaction_id) {
	$key      = 'wfcc_delivery_' . absint($transaction_id);
	$existing = get_option($key, false);
	if (is_array($existing) && !empty($existing['created']) && absint($existing['created']) < time() - 600) {
		delete_option($key);
	}

	return add_option($key, array('created' => time()), '', false) ? $key : false;
}

/**
 * Store only safe operational error metadata.
 *
 * @param int      $transaction_id Transaction ID.
 * @param WP_Error $error          Delivery error.
 * @return array{retryable:bool,category:string,status:int}
 */
function wfcc_store_salesforce_delivery_error($transaction_id, $error) {
	$data       = $error->get_error_data();
	$data       = is_array($data) ? $data : array();
	$category   = sanitize_key($data['category'] ?? 'unknown');
	$status     = absint($data['status'] ?? 0);
	$retryable  = !empty($data['retryable']);

	update_post_meta($transaction_id, 'wfcc_salesforce_last_error_category', $category);
	update_post_meta($transaction_id, 'wfcc_salesforce_last_error_code', sanitize_key($error->get_error_code()));
	update_post_meta($transaction_id, 'wfcc_salesforce_last_http_status', $status);

	return array(
		'retryable' => $retryable,
		'category'  => $category,
		'status'    => $status,
	);
}

/**
 * Deliver one outbox record.
 *
 * @param int $transaction_id Transaction post ID.
 * @return bool
 */
function wfcc_deliver_salesforce_transaction($transaction_id) {
	$lock = wfcc_acquire_delivery_lock($transaction_id);
	if (!$lock) {
		return false;
	}

	try {
		$attempt = absint(get_post_meta($transaction_id, 'wfcc_salesforce_delivery_attempts', true)) + 1;
		update_post_meta($transaction_id, 'wfcc_salesforce_state', 'salesforce_delivering');
		update_post_meta($transaction_id, 'wfcc_salesforce_delivery_attempts', $attempt);
		update_post_meta($transaction_id, 'wfcc_salesforce_last_attempt', gmdate('c'));

		$payload = wfcc_build_salesforce_payload($transaction_id);
		if (is_wp_error($payload)) {
			$result = $payload;
		} else {
			update_post_meta(
				$transaction_id,
				'wfcc_salesforce_payload_version',
				sanitize_text_field($payload['schemaVersion'] ?? WFCC_SALESFORCE_PAYLOAD_VERSION)
			);
			update_post_meta($transaction_id, 'wfcc_salesforce_payload_hash', hash('sha256', wp_json_encode($payload)));
			$result = wfcc_salesforce_deliver_payload($payload);
		}

		if (!is_wp_error($result)) {
			update_post_meta($transaction_id, 'wfcc_salesforce_state', 'salesforce_delivered');
			update_post_meta($transaction_id, 'wfcc_salesforce_delivered_at', gmdate('c'));
			update_post_meta($transaction_id, 'wfcc_salesforce_reconciliation_state', $result['reconciliationStatus']);
			delete_post_meta($transaction_id, 'wfcc_salesforce_next_attempt');
			delete_post_meta($transaction_id, 'wfcc_salesforce_last_error_category');
			delete_post_meta($transaction_id, 'wfcc_salesforce_last_error_code');
			delete_post_meta($transaction_id, 'wfcc_salesforce_last_http_status');

			$record_keys = array(
				'transactionId'   => 'wfcc_salesforce_transaction_id',
				'contactId'       => 'wfcc_salesforce_contact_id',
				'recurringGiftId' => 'wfcc_salesforce_recurring_gift_id',
			);
			foreach ($record_keys as $response_key => $meta_key) {
				if (!empty($result['records'][$response_key])) {
					update_post_meta($transaction_id, $meta_key, $result['records'][$response_key]);
				}
			}

			do_action('wfcc_salesforce_delivery_succeeded', $transaction_id, $result);
			return true;
		}

		$error_data = wfcc_store_salesforce_delivery_error($transaction_id, $result);
		$limit      = min(20, max(1, absint(wfcc_get_setting('delivery_retry_limit', 8))));
		if ($error_data['retryable'] && $attempt < $limit) {
			update_post_meta($transaction_id, 'wfcc_salesforce_state', 'salesforce_failed');
			update_post_meta(
				$transaction_id,
				'wfcc_salesforce_next_attempt',
				gmdate('Y-m-d H:i:s', time() + wfcc_salesforce_retry_delay($attempt, $transaction_id))
			);
		} else {
			update_post_meta($transaction_id, 'wfcc_salesforce_state', 'manual_review');
			update_post_meta($transaction_id, 'wfcc_salesforce_reconciliation_state', 'attention_required');
			delete_post_meta($transaction_id, 'wfcc_salesforce_next_attempt');
		}
		do_action('wfcc_salesforce_delivery_failed', $transaction_id, $error_data);

		return false;
	} finally {
		delete_option($lock);
	}
}

/**
 * Process a bounded batch of due outbox records.
 *
 * @return void
 */
function wfcc_process_salesforce_delivery_queue() {
	$batch_size = min(50, max(1, absint(apply_filters('wfcc_salesforce_delivery_batch_size', 10))));
	$ids = get_posts(
		array(
			'post_type'              => 'transaction',
			'post_status'            => array('private', 'publish', 'draft'),
			'fields'                 => 'ids',
			'posts_per_page'         => $batch_size,
			'orderby'                => 'modified',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'suppress_filters'       => true,
			'update_post_meta_cache' => false,
			'meta_query'             => array(
				'relation' => 'AND',
				array(
					'key'     => 'wfcc_salesforce_state',
					'value'   => array('salesforce_pending', 'salesforce_failed'),
					'compare' => 'IN',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => 'wfcc_salesforce_next_attempt',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => 'wfcc_salesforce_next_attempt',
						'value'   => gmdate('Y-m-d H:i:s'),
						'compare' => '<=',
						'type'    => 'DATETIME',
					),
				),
			),
		)
	);

	foreach ($ids as $transaction_id) {
		wfcc_deliver_salesforce_transaction(absint($transaction_id));
	}
}

/**
 * Retry one transaction from the administration queue.
 *
 * @return void
 */
function wfcc_handle_manual_delivery_retry() {
	$transaction_id = isset($_POST['transaction_id']) ? absint($_POST['transaction_id']) : 0;
	if (!$transaction_id || !current_user_can('wfcc_retry_deliveries')) {
		wp_die(esc_html__('You are not allowed to retry this delivery.', 'wfc-cart'));
	}
	check_admin_referer('wfcc_retry_delivery_' . $transaction_id);

	$state = sanitize_key(get_post_meta($transaction_id, 'wfcc_salesforce_state', true));
	if (in_array($state, array('salesforce_failed', 'manual_review'), true)) {
		update_post_meta($transaction_id, 'wfcc_salesforce_state', 'salesforce_pending');
		update_post_meta($transaction_id, 'wfcc_salesforce_delivery_attempts', 0);
		update_post_meta($transaction_id, 'wfcc_salesforce_next_attempt', gmdate('Y-m-d H:i:s'));
		update_post_meta($transaction_id, 'wfcc_salesforce_reconciliation_state', 'pending');
		delete_post_meta($transaction_id, 'wfcc_salesforce_last_error_category');
		delete_post_meta($transaction_id, 'wfcc_salesforce_last_error_code');
		delete_post_meta($transaction_id, 'wfcc_salesforce_last_http_status');
	}

	wp_safe_redirect(add_query_arg(array('page' => 'wfcc-delivery-queue', 'wfcc_retried' => $transaction_id), admin_url('admin.php')));
	exit;
}
