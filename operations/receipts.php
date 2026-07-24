<?php
/**
 * Privacy-minimised transaction receipt generation and delivery.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('wfcc_checkout_completed', 'wfcc_issue_checkout_receipt', 20, 3);
add_action('admin_post_wfcc_resend_receipt', 'wfcc_handle_receipt_resend');

/**
 * Return whether a payment state supports a financial receipt.
 *
 * @param string $state Payment state.
 * @return bool
 */
function wfcc_payment_state_is_receiptable($state) {
	return in_array(
		sanitize_key($state),
		array('succeeded', 'partially_refunded', 'refunded', 'disputed'),
		true
	);
}

/**
 * Generate a deterministic, transaction-unique receipt number.
 *
 * @param int    $transaction_id Transaction ID.
 * @param string $occurred_at    ISO timestamp.
 * @param string $prefix         Receipt prefix.
 * @return string
 */
function wfcc_generate_receipt_number($transaction_id, $occurred_at = '', $prefix = 'WFC') {
	$prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9-]/', '', (string) $prefix), 0, 12));
	$prefix = $prefix ?: 'WFC';
	$time   = strtotime((string) $occurred_at);
	$year   = false === $time ? gmdate('Y') : gmdate('Y', $time);

	return sprintf('%s-%s-%09d', $prefix, $year, absint($transaction_id));
}

/**
 * Create a receipt record on the protected transaction once.
 *
 * @param int $transaction_id Transaction ID.
 * @return string|WP_Error
 */
function wfcc_create_transaction_receipt($transaction_id) {
	$transaction_id = absint($transaction_id);
	if (!$transaction_id || 'transaction' !== get_post_type($transaction_id)) {
		return new WP_Error('wfcc_receipt_transaction_invalid', __('The WFC transaction is invalid.', 'wfc-cart'));
	}

	$state = get_post_meta($transaction_id, 'wfcc_payment_state', true);
	if (!wfcc_payment_state_is_receiptable($state)) {
		return new WP_Error('wfcc_receipt_payment_incomplete', __('The transaction is not eligible for a receipt.', 'wfc-cart'));
	}

	$existing = sanitize_text_field(get_post_meta($transaction_id, 'wfcc_receipt_number', true));
	if ('' !== $existing) {
		return $existing;
	}

	$prefix = wfcc_get_setting('receipt_number_prefix', 'WFC');
	$number = wfcc_generate_receipt_number(
		$transaction_id,
		get_post_meta($transaction_id, 'wfcc_payment_updated_at', true),
		$prefix
	);

	update_post_meta($transaction_id, 'wfcc_receipt_number', $number);
	update_post_meta($transaction_id, 'wfcc_receipt_issued_at', gmdate('c'));
	update_post_meta($transaction_id, 'wfcc_receipt_delivery_state', 'not_sent');

	do_action('wfcc_receipt_created', $transaction_id, $number);

	return $number;
}

/**
 * Resolve the configured receipt email without persisting a duplicate.
 *
 * @param int                          $transaction_id Transaction ID.
 * @param array<string, mixed>|null    $entry          Optional Gravity Forms entry.
 * @return string|WP_Error
 */
function wfcc_get_receipt_email($transaction_id, $entry = null) {
	$field_id = wfcc_sanitize_gf_entry_key(wfcc_get_setting('receipt_email_field_id', ''));
	if ('' === $field_id) {
		return new WP_Error('wfcc_receipt_email_field_missing', __('The receipt email field is not configured.', 'wfc-cart'));
	}

	if (!is_array($entry)) {
		$entry_id = absint(get_post_meta($transaction_id, 'wfcc_gravity_forms_entry_id', true));
		if (!$entry_id || !class_exists('GFAPI')) {
			return new WP_Error('wfcc_receipt_entry_unavailable', __('The receipt entry is unavailable.', 'wfc-cart'));
		}
		$entry = GFAPI::get_entry($entry_id);
	}

	if (is_wp_error($entry) || !is_array($entry)) {
		return new WP_Error('wfcc_receipt_entry_invalid', __('The receipt entry could not be read.', 'wfc-cart'));
	}

	$email = sanitize_email($entry[$field_id] ?? '');
	if ('' === $email || !is_email($email)) {
		return new WP_Error('wfcc_receipt_email_invalid', __('The receipt email address is invalid.', 'wfc-cart'));
	}

	return $email;
}

/**
 * Build the plain-text receipt body.
 *
 * @param int    $transaction_id Transaction ID.
 * @param string $receipt_number Receipt number.
 * @return string
 */
function wfcc_build_receipt_body($transaction_id, $receipt_number) {
	$currency = strtoupper(sanitize_key(get_post_meta($transaction_id, 'wfcc_currency', true)));
	$amount   = absint(get_post_meta($transaction_id, 'wfcc_amount', true));
	$issued   = sanitize_text_field(get_post_meta($transaction_id, 'wfcc_receipt_issued_at', true));
	$key      = wfcc_sanitize_transaction_key(get_post_meta($transaction_id, 'wfcc_transaction_key', true));

	$lines = array(
		sprintf(__('Thank you for your contribution to %s.', 'wfc-cart'), get_bloginfo('name')),
		'',
		sprintf(__('Receipt number: %s', 'wfc-cart'), $receipt_number),
		sprintf(__('Receipt date: %s', 'wfc-cart'), $issued),
		sprintf(__('Amount: %s', 'wfc-cart'), wfcc_format_report_amount($amount, $currency)),
		sprintf(__('Transaction reference: %s', 'wfc-cart'), $key),
		'',
		__('Please retain this email for your records.', 'wfc-cart'),
	);

	/**
	 * Filter the plain-text receipt body.
	 *
	 * @param string[] $lines          Receipt lines.
	 * @param int      $transaction_id Transaction ID.
	 */
	$lines = apply_filters('wfcc_receipt_body_lines', $lines, $transaction_id);

	return implode("\n", is_array($lines) ? array_map('strval', $lines) : array());
}

/**
 * Acquire a stale-safe receipt-send lock.
 *
 * @param int $transaction_id Transaction ID.
 * @return string|false
 */
function wfcc_acquire_receipt_lock($transaction_id) {
	$key      = 'wfcc_receipt_' . absint($transaction_id);
	$existing = get_option($key, false);
	if (is_array($existing) && !empty($existing['created']) && absint($existing['created']) < time() - 300) {
		delete_option($key);
	}

	return add_option($key, array('created' => time()), '', false) ? $key : false;
}

/**
 * Send or resend a receipt under a per-transaction lock.
 *
 * @param int                       $transaction_id Transaction ID.
 * @param array<string, mixed>|null $entry          Optional entry.
 * @return true|WP_Error
 */
function wfcc_send_transaction_receipt($transaction_id, $entry = null) {
	$lock = wfcc_acquire_receipt_lock($transaction_id);
	if (!$lock) {
		return new WP_Error('wfcc_receipt_locked', __('This receipt is already being sent.', 'wfc-cart'));
	}

	try {
		return wfcc_send_transaction_receipt_unlocked($transaction_id, $entry);
	} finally {
		delete_option($lock);
	}
}

/**
 * Perform one locked receipt send.
 *
 * @param int                       $transaction_id Transaction ID.
 * @param array<string, mixed>|null $entry          Optional entry.
 * @return true|WP_Error
 */
function wfcc_send_transaction_receipt_unlocked($transaction_id, $entry = null) {
	$receipt_number = wfcc_create_transaction_receipt($transaction_id);
	if (is_wp_error($receipt_number)) {
		return $receipt_number;
	}

	$email = wfcc_get_receipt_email($transaction_id, $entry);
	if (is_wp_error($email)) {
		update_post_meta($transaction_id, 'wfcc_receipt_delivery_state', 'attention_required');
		update_post_meta($transaction_id, 'wfcc_receipt_last_error', sanitize_key($email->get_error_code()));
		return $email;
	}

	$subject = sanitize_text_field(wfcc_get_setting('receipt_email_subject', __('Your contribution receipt', 'wfc-cart')));
	$subject = str_replace('{receipt_number}', $receipt_number, $subject);
	$sent    = wp_mail(
		$email,
		$subject,
		wfcc_build_receipt_body($transaction_id, $receipt_number),
		array('Content-Type: text/plain; charset=UTF-8')
	);

	update_post_meta($transaction_id, 'wfcc_receipt_last_attempt', gmdate('c'));
	update_post_meta(
		$transaction_id,
		'wfcc_receipt_delivery_attempts',
		absint(get_post_meta($transaction_id, 'wfcc_receipt_delivery_attempts', true)) + 1
	);
	update_post_meta($transaction_id, 'wfcc_receipt_last_actor', absint(get_current_user_id()));
	if (!$sent) {
		update_post_meta($transaction_id, 'wfcc_receipt_delivery_state', 'attention_required');
		update_post_meta($transaction_id, 'wfcc_receipt_last_error', 'mail_failed');
		return new WP_Error('wfcc_receipt_mail_failed', __('WordPress could not send the receipt.', 'wfc-cart'));
	}

	update_post_meta($transaction_id, 'wfcc_receipt_delivery_state', 'sent');
	update_post_meta($transaction_id, 'wfcc_receipt_sent_at', gmdate('c'));
	delete_post_meta($transaction_id, 'wfcc_receipt_last_error');
	do_action('wfcc_receipt_sent', $transaction_id, $receipt_number);

	return true;
}

/**
 * Generate and optionally email a receipt after checkout.
 *
 * @param int                  $transaction_id Transaction ID.
 * @param array<string, mixed> $entry          Gravity Forms entry.
 * @param array<string, mixed> $form           Gravity Forms form.
 * @return void
 */
function wfcc_issue_checkout_receipt($transaction_id, $entry, $form) {
	unset($form);

	if (!wfcc_get_setting('receipt_generation_enabled', true)) {
		return;
	}

	$receipt = wfcc_create_transaction_receipt($transaction_id);
	$delivery_state = sanitize_key(get_post_meta($transaction_id, 'wfcc_receipt_delivery_state', true));
	if (!is_wp_error($receipt)
		&& 'sent' !== $delivery_state
		&& wfcc_get_setting('receipt_email_enabled', false)) {
		wfcc_send_transaction_receipt($transaction_id, $entry);
	}
}

/**
 * Handle a capability- and nonce-protected receipt resend.
 *
 * @return void
 */
function wfcc_handle_receipt_resend() {
	$transaction_id = isset($_POST['transaction_id']) ? absint($_POST['transaction_id']) : 0;
	if (!$transaction_id || !current_user_can('wfcc_manage_receipts')) {
		wp_die(esc_html__('You are not allowed to resend this receipt.', 'wfc-cart'));
	}
	check_admin_referer('wfcc_resend_receipt_' . $transaction_id);

	$result = wfcc_send_transaction_receipt($transaction_id);
	$status = is_wp_error($result) ? 'failed' : 'sent';
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'                => 'wfcc-receipts',
				'wfcc_receipt_result' => $status,
			),
			admin_url('admin.php')
		)
	);
	exit;
}
