<?php
/**
 * Stripe-to-Salesforce reconciliation queueing.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('wfcc_stripe_event_reconciled', 'wfcc_queue_stripe_state_for_salesforce', 10, 3);

/**
 * Queue initial delivery or a state reconciliation after verified Stripe events.
 *
 * @param int                  $transaction_id Transaction post ID.
 * @param string               $event_type     Stripe event type.
 * @param array<string, mixed> $object         Stripe object.
 * @return void
 */
function wfcc_queue_stripe_state_for_salesforce($transaction_id, $event_type, $object) {
	unset($object);

	if (in_array($event_type, array('payment_intent.succeeded', 'setup_intent.succeeded'), true)) {
		wfcc_enqueue_salesforce_delivery($transaction_id, 'upsert');
		return;
	}

	$reconciliation_events = array(
		'payment_intent.canceled',
		'charge.refunded',
		'charge.dispute.created',
	);
	if (in_array($event_type, $reconciliation_events, true)
		&& 'salesforce_delivered' === get_post_meta($transaction_id, 'wfcc_salesforce_state', true)) {
		wfcc_enqueue_salesforce_delivery($transaction_id, 'reconcile');
	}
}
