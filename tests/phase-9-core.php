<?php
/**
 * Phase 9 CRM-mode and PII-retention contracts without WordPress.
 */

require __DIR__ . '/phase-8-core.php';

/**
 * Fail the Phase 9 contract test.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure.
 * @return void
 */
function wfcc_phase_9_assert($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "Phase 9 test failed: {$message}\n");
		exit(1);
	}
}

wfcc_phase_9_assert('wordpress' === wfcc_sanitize_crm_mode('wordpress'), 'WordPress CRM mode must be accepted');
wfcc_phase_9_assert('salesforce' === wfcc_sanitize_crm_mode('salesforce'), 'Salesforce CRM mode must be accepted');
wfcc_phase_9_assert('wordpress' === wfcc_sanitize_crm_mode('other'), 'unknown CRM modes must fail to WordPress');

foreach (
	array(
		'wfcc_email',
		'wfcc_donor_name',
		'wfcc_billing_address',
		'wfcc_salesforce_contact_id',
	) as $pii_key
) {
	wfcc_phase_9_assert(wfcc_operational_meta_key_contains_pii($pii_key), "{$pii_key} must be blocked from operational metadata");
}
foreach (
	array(
		'wfcc_transaction_key',
		'wfcc_gravity_forms_entry_id',
		'wfcc_stripe_intent_id',
		'wfcc_amount',
	) as $operational_key
) {
	wfcc_phase_9_assert(!wfcc_operational_meta_key_contains_pii($operational_key), "{$operational_key} must remain available");
}

$GLOBALS['wfcc_test_post_types_by_id'][901] = 'transaction';
wfcc_phase_9_assert(
	false === wfcc_prevent_operational_pii_metadata(null, 901, 'wfcc_email', 'person@example.test'),
	'donor email metadata must be rejected on a transaction'
);
wfcc_phase_9_assert(
	null === wfcc_prevent_operational_pii_metadata(null, 901, 'wfcc_amount', 5000),
	'non-PII operational metadata must remain writable'
);

wfcc_phase_9_assert('REF-100/ABC' === wfcc_sanitize_operational_reference('REF-100/ABC'), 'opaque references must pass');
wfcc_phase_9_assert('' === wfcc_sanitize_operational_reference('person@example.test'), 'email references must fail');
wfcc_phase_9_assert('' === wfcc_sanitize_operational_reference('+61412345678'), 'phone references must fail');
wfcc_phase_9_assert('' === wfcc_sanitize_operational_reference('Donor free text'), 'free-text references must fail');

$wordpress_context = array(
	'php_version'                  => '8.3.0',
	'wp_version'                   => '6.8',
	'https'                        => true,
	'schema_version'               => WFCC_SCHEMA_VERSION,
	'gravity_forms'                => true,
	'stripe'                       => true,
	'webhook'                      => true,
	'crm_mode'                     => 'wordpress',
	'salesforce'                   => false,
	'packages'                     => true,
	'wordpress_recurring_packages' => false,
	'queue_scheduled'              => false,
	'cron_disabled'                => false,
	'receipt_email_enabled'        => false,
	'receipt_email_field'          => false,
	'forwarded_header_present'     => false,
	'trusted_proxies'              => false,
	'rest_hardened'                => true,
	'multisite'                    => false,
);
$wordpress_summary = wfcc_summarize_readiness(wfcc_evaluate_readiness_context($wordpress_context));
wfcc_phase_9_assert('ready' === $wordpress_summary['status'], 'one-off WordPress CRM mode must be production-ready without Salesforce');

$wordpress_context['wordpress_recurring_packages'] = true;
$wordpress_recurring = wfcc_evaluate_readiness_context($wordpress_context);
wfcc_phase_9_assert('blocking' === $wordpress_recurring['recurring_ownership']['status'], 'WordPress recurring packages must block readiness');

$salesforce_context = $wordpress_context;
$salesforce_context['crm_mode'] = 'salesforce';
$salesforce_context['wordpress_recurring_packages'] = false;
$salesforce_context['queue_scheduled'] = true;
$salesforce_context['salesforce'] = false;
$salesforce_checks = wfcc_evaluate_readiness_context($salesforce_context);
wfcc_phase_9_assert('blocking' === $salesforce_checks['salesforce']['status'], 'Salesforce mode must require its credentials');

$GLOBALS['wfcc_test_options']['wfcc_settings'] = array(
	'crm_mode' => 'wordpress',
	'checkout_packages' => array(
		'one-off' => array(
			'enabled'   => true,
			'mode'      => 'payment',
			'recurring' => false,
		),
		'monthly' => array(
			'enabled'   => true,
			'mode'      => 'payment',
			'recurring' => true,
		),
	),
);
wfcc_phase_9_assert(is_array(wfcc_get_checkout_package('one-off')), 'one-off packages must remain available in WordPress mode');
wfcc_phase_9_assert(null === wfcc_get_checkout_package('monthly'), 'recurring packages must be unavailable in WordPress mode');

$GLOBALS['wfcc_test_meta'][902] = array(
	'wfcc_payment_state'            => 'succeeded',
	'wfcc_gravity_forms_entry_id'   => 44,
	'wfcc_salesforce_state'         => '',
);
wfcc_phase_9_assert(false === wfcc_enqueue_salesforce_delivery(902), 'WordPress mode must not enqueue Salesforce delivery');
wfcc_record_completed_checkout_crm_state(902, array('id' => 44, '2' => 'person@example.test'), array('id' => 3));
wfcc_phase_9_assert('wordpress' === $GLOBALS['wfcc_test_meta'][902]['wfcc_crm_mode'], 'completed checkout must record WordPress mode');
wfcc_phase_9_assert(
	'gravity_forms_entry_retained' === $GLOBALS['wfcc_test_meta'][902]['wfcc_crm_state'],
	'completed checkout must record the single-entry retention state'
);
wfcc_phase_9_assert(!isset($GLOBALS['wfcc_test_meta'][902]['wfcc_email']), 'checkout completion must not copy donor email');

$payload = array(
	'schemaVersion'   => '1.1',
	'operation'       => 'upsert',
	'transactionKey'  => 'wfcc_fingerprint',
	'transactionType' => 'donation',
	'donor'           => array('email' => 'first@example.test'),
	'gift'            => array('amount' => 50, 'currency' => 'AUD', 'recurrenceStart' => '2026-08-01'),
	'lineItems'       => array(
		array(
			'type'        => 'event',
			'sourceRef'   => 'person-specific-booking',
			'label'       => 'Donor supplied dedication',
			'quantity'    => 1,
			'unitAmount'  => 5000,
			'totalAmount' => 5000,
			'currency'    => 'AUD',
			'fundCode'    => 'general',
		),
	),
	'stripe'          => array('initialPaymentIntentId' => 'pi_test'),
	'recurring'       => array('enabled' => false, 'consentRecordedAt' => ''),
	'metadata'        => array('freeText' => 'donor supplied'),
	'occurredAt'      => '2026-07-25T00:00:00Z',
);
$fingerprint = wfcc_salesforce_payload_fingerprint($payload);
$payload['donor']['email'] = 'second@example.test';
$payload['metadata']['freeText'] = 'different donor data';
$payload['gift']['recurrenceStart'] = '2026-09-01';
$payload['lineItems'][0]['sourceRef'] = 'another-booking';
$payload['lineItems'][0]['label'] = 'Different donor dedication';
wfcc_phase_9_assert(
	$fingerprint === wfcc_salesforce_payload_fingerprint($payload),
	'the stored delivery fingerprint must not derive from donor fields or mapped metadata'
);
$payload['gift']['amount'] = 75;
wfcc_phase_9_assert(
	$fingerprint !== wfcc_salesforce_payload_fingerprint($payload),
	'the fingerprint must still detect an operational gift change'
);

$GLOBALS['wfcc_test_options'] = array(
	'wfcc_schema_version' => '8',
	'wfcc_settings'       => array('currency' => 'AUD'),
);
$GLOBALS['wfcc_test_meta'][903] = array(
	'wfcc_salesforce_contact_id' => '0031234567890AB',
	'wfcc_email'                 => 'legacy@example.test',
	'wfcc_transaction_key'       => 'wfcc_preserved',
);
$GLOBALS['wfcc_test_schedules'] = array(
	'wfcc_process_delivery_queue' => array(time() + 60),
);
wfcc_maybe_upgrade_schema();
wfcc_phase_9_assert('wordpress' === get_option('wfcc_settings')['crm_mode'], 'unconfigured sites must upgrade to WordPress CRM mode');
wfcc_phase_9_assert(false === wp_next_scheduled('wfcc_process_delivery_queue'), 'WordPress-mode upgrade must remove Salesforce scheduling');
wfcc_phase_9_assert(!isset($GLOBALS['wfcc_test_meta'][903]['wfcc_salesforce_contact_id']), 'schema 9 must remove legacy Salesforce Contact IDs');
wfcc_phase_9_assert(!isset($GLOBALS['wfcc_test_meta'][903]['wfcc_email']), 'schema 9 must remove known donor-field metadata copies');
wfcc_phase_9_assert('wfcc_preserved' === $GLOBALS['wfcc_test_meta'][903]['wfcc_transaction_key'], 'schema 9 must preserve operational metadata');

$GLOBALS['wfcc_test_options'] = array(
	'wfcc_schema_version' => '8',
	'wfcc_settings'       => array(
		'salesforce_client_id'     => 'client-id',
		'salesforce_client_secret' => 'client-secret',
	),
);
$GLOBALS['wfcc_test_schedules'] = array();
wfcc_maybe_upgrade_schema();
wfcc_phase_9_assert('salesforce' === get_option('wfcc_settings')['crm_mode'], 'configured Salesforce sites must preserve delivery on upgrade');
wfcc_phase_9_assert((bool) wp_next_scheduled('wfcc_process_delivery_queue'), 'Salesforce-mode upgrade must schedule delivery');

$headers = wfcc_transaction_export_headers();
wfcc_phase_9_assert(in_array('crm_mode', $headers, true), 'exports must identify the CRM mode');
wfcc_phase_9_assert(in_array('crm_state', $headers, true), 'exports must identify the generic CRM state');
wfcc_phase_9_assert(!in_array('email', $headers, true), 'exports must continue excluding donor email');

fwrite(STDOUT, "WFC Cart Phase 9 contract tests passed.\n");
