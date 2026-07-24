<?php
/**
 * Phase 6 operational contract tests without a WordPress installation.
 */

require __DIR__ . '/phase-5-core.php';

/**
 * Fail the Phase 6 contract test.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure.
 * @return void
 */
function wfcc_phase_6_assert($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "Phase 6 test failed: {$message}\n");
		exit(1);
	}
}

$range = wfcc_normalize_report_date_range('2026-07-01', '2026-07-31');
wfcc_phase_6_assert(!is_wp_error($range), 'a valid reporting range must pass');
wfcc_phase_6_assert(
	is_wp_error(wfcc_normalize_report_date_range('2026-07-31', '2026-07-01')),
	'a reversed reporting range must fail'
);
wfcc_phase_6_assert(
	is_wp_error(wfcc_normalize_report_date_range('2025-01-01', '2026-07-01')),
	'a reporting range beyond the bound must fail'
);

$line_item = wfcc_sanitize_transaction_line_item(
	array(
		'type'         => 'event',
		'source_key'   => 'booking_101',
		'source_ref'   => 'booking-101',
		'label'        => 'Community dinner',
		'quantity'     => 2,
		'unit_amount'  => 2500,
		'tax_amount'   => 500,
		'currency'     => 'aud',
		'fund_code'    => 'events_2026',
	)
);
wfcc_phase_6_assert(!is_wp_error($line_item), 'a fixed event line item must pass');
wfcc_phase_6_assert(5500 === $line_item['total_amount'], 'line-item totals must be server-calculated when omitted');
wfcc_phase_6_assert('AUD' === $line_item['currency'], 'line-item currency must be normalised');
wfcc_phase_6_assert(
	is_wp_error(wfcc_sanitize_transaction_line_item(array('type' => 'arbitrary_object'))),
	'an unapproved line-item type must fail'
);
wfcc_phase_6_assert(
	is_wp_error(
		wfcc_sanitize_transaction_line_item(
			array(
				'type'        => 'donation',
				'source_key'  => 'invalid_negative',
				'label'       => 'Invalid negative donation',
				'quantity'    => 1,
				'unit_amount' => -100,
				'currency'    => 'AUD',
			)
		)
	),
	'negative amounts must be restricted to adjustment line items'
);
wfcc_phase_6_assert('1.1' === WFCC_SALESFORCE_PAYLOAD_VERSION, 'Phase 6 must expose the line-item payload contract version');

$GLOBALS['wfcc_test_meta'][201] = array(
	'wfcc_payment_state'          => 'succeeded',
	'wfcc_salesforce_state'       => 'salesforce_delivered',
	'wfcc_amount'                 => 5000,
	'wfcc_currency'               => 'AUD',
	'wfcc_receipt_number'         => 'WFC-2026-000000201',
	'wfcc_batch_id'               => 301,
	'wfcc_transaction_key'        => 'wfcc_report_201',
	'wfcc_created_at'             => '2026-07-24T01:00:00Z',
	'wfcc_package_id'             => 'general',
	'wfcc_frequency'              => 'one-off',
	'wfcc_receipt_delivery_state' => 'sent',
	'wfcc_operational_fund_code'  => 'general',
	'wfcc_external_reference'     => '=HYPERLINK("bad")',
);
$GLOBALS['wfcc_test_meta'][202] = array(
	'wfcc_payment_state'    => 'refunded',
	'wfcc_salesforce_state' => 'salesforce_pending',
	'wfcc_amount'           => 2500,
	'wfcc_currency'         => 'AUD',
);
$report = wfcc_build_operational_report(array(201, 202));
wfcc_phase_6_assert(2 === $report['transaction_count'], 'the report must count transactions');
wfcc_phase_6_assert(7500 === $report['currency_totals']['AUD'], 'the report must aggregate minor units by currency');
wfcc_phase_6_assert(1 === $report['receipt_count'] && 1 === $report['batched_count'], 'the report must count receipts and batches');

wfcc_phase_6_assert(wfcc_transaction_is_batch_eligible('succeeded'), 'a successful payment must be batch eligible');
wfcc_phase_6_assert(!wfcc_transaction_is_batch_eligible('processing'), 'an incomplete payment must not be batch eligible');
$totals = wfcc_calculate_batch_totals(array(201, 202));
wfcc_phase_6_assert(7500 === $totals['AUD'], 'batch totals must use original transaction minor units');

wfcc_phase_6_assert(
	'WFC-2026-000000201' === wfcc_generate_receipt_number(201, '2026-07-24T01:00:00Z', 'WFC'),
	'receipt numbers must be deterministic and transaction unique'
);
wfcc_phase_6_assert(
	"'=2+2" === wfcc_sanitize_csv_cell('=2+2'),
	'CSV formula cells must be neutralised'
);
wfcc_phase_6_assert(
	"'  @SUM(1,2)" === wfcc_sanitize_csv_cell('  @SUM(1,2)'),
	'CSV formula cells with leading whitespace must be neutralised'
);
$headers = wfcc_transaction_export_headers();
wfcc_phase_6_assert(!in_array('email', $headers, true), 'exports must not include donor email');
wfcc_phase_6_assert(!in_array('payment_method_id', $headers, true), 'exports must not include reusable payment identifiers');
$export_row = wfcc_build_transaction_export_row(201);
$reference_index = array_search('external_reference', $headers, true);
wfcc_phase_6_assert(
	0 === strpos($export_row[$reference_index], "'="),
	'imported references must remain formula-safe when exported'
);

$import = wfcc_parse_operational_import_csv(
	"transaction_key,fund_code,reference\n"
	. "wfcc_contract_1,general,REF-100\n"
	. "wfcc_contract_2,events,REF-101\n"
);
wfcc_phase_6_assert(!is_wp_error($import) && 2 === count($import), 'a valid operational metadata CSV must parse');
wfcc_phase_6_assert(
	is_wp_error(
		wfcc_parse_operational_import_csv(
			"transaction_key,amount,payment_state\nwfcc_contract_1,999999,succeeded\n"
		)
	),
	'the import must reject financial fields'
);
wfcc_phase_6_assert(
	is_wp_error(
		wfcc_parse_operational_import_csv(
			"transaction_key,fund_code,reference\n"
			. "wfcc_duplicate,general,ONE\n"
			. "wfcc_duplicate,events,TWO\n"
		)
	),
	'the import must reject duplicate transaction rows'
);

fwrite(STDOUT, "WFC Cart Phase 6 contract tests passed.\n");
