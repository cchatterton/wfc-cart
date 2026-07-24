<?php
/**
 * Phase 8 release-governance contracts without a WordPress installation.
 */

require __DIR__ . '/phase-7-core.php';

if (!function_exists('wp_json_encode')) {
	function wp_json_encode($value, $flags = 0) {
		return json_encode($value, $flags);
	}
}

if (!function_exists('sanitize_textarea_field')) {
	function sanitize_textarea_field($value) {
		return trim(strip_tags((string) $value));
	}
}

if (!function_exists('wp_generate_uuid4')) {
	function wp_generate_uuid4() {
		return '12345678-1234-4123-8123-123456789012';
	}
}

/**
 * Fail the Phase 8 contract test.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure.
 * @return void
 */
function wfcc_phase_8_assert($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "Phase 8 test failed: {$message}\n");
		exit(1);
	}
}

$readiness = array(
	'checked_at' => '2026-07-24T10:00:00Z',
	'version'    => WFCC_VERSION,
	'schema'     => WFCC_SCHEMA_VERSION,
	'status'     => 'ready',
	'pass'       => 14,
	'warning'    => 0,
	'blocking'   => 0,
	'checks'     => array('php' => 'pass'),
);

$governance = wfcc_evaluate_release_governance(array(), $readiness);
wfcc_phase_8_assert('pending' === $governance['status'], 'an empty valid journal must remain pending');
wfcc_phase_8_assert($governance['technical_ready'], 'a matching no-blocker technical audit must be accepted');
wfcc_phase_8_assert($governance['gates']['staging_validation']['can_approve'], 'staging must be the first approvable gate');
wfcc_phase_8_assert(!$governance['gates']['security_review']['can_approve'], 'security review must wait for staging');

$journal = array();
$journal[] = wfcc_create_release_governance_entry(
	$journal,
	array(
		'gate'               => 'staging_validation',
		'decision'           => 'approve',
		'reviewer'           => 'Staging Lead',
		'evidence_reference' => 'STAGE-2026-001',
		'recorded_by_id'     => 11,
		'recorded_by'        => 'Release Recorder',
		'recorded_at'        => '2026-07-24T10:01:00Z',
	)
);
$journal[] = wfcc_create_release_governance_entry(
	$journal,
	array(
		'gate'               => 'security_review',
		'decision'           => 'approve',
		'reviewer'           => 'Security Reviewer',
		'evidence_reference' => 'SEC-2026-001',
		'recorded_by_id'     => 11,
		'recorded_by'        => 'Release Recorder',
		'recorded_at'        => '2026-07-24T10:02:00Z',
	)
);
$governance = wfcc_evaluate_release_governance($journal, $readiness);
wfcc_phase_8_assert('blocked' === $governance['gates']['security_review']['status'], 'an independent approval without attestation must stay blocked');

$journal[] = wfcc_create_release_governance_entry(
	$journal,
	array(
		'gate'                 => 'security_review',
		'decision'             => 'approve',
		'reviewer'             => 'Security Reviewer',
		'evidence_reference'   => 'SEC-2026-002',
		'independent_attested' => true,
		'recorded_by_id'       => 11,
		'recorded_by'          => 'Release Recorder',
		'recorded_at'          => '2026-07-24T10:03:00Z',
	)
);
$journal[] = wfcc_create_release_governance_entry(
	$journal,
	array(
		'gate'                 => 'accessibility_review',
		'decision'             => 'approve',
		'reviewer'             => 'Accessibility Reviewer',
		'evidence_reference'   => 'A11Y-2026-001',
		'independent_attested' => true,
		'recorded_by_id'       => 11,
		'recorded_by'          => 'Release Recorder',
		'recorded_at'          => '2026-07-24T10:04:00Z',
	)
);
$journal[] = wfcc_create_release_governance_entry(
	$journal,
	array(
		'gate'               => 'production_pilot',
		'decision'           => 'approve',
		'reviewer'           => 'Pilot Owner',
		'evidence_reference' => 'PILOT-2026-001',
		'recorded_by_id'     => 11,
		'recorded_by'        => 'Release Recorder',
		'recorded_at'        => '2026-07-24T10:05:00Z',
	)
);
$journal[] = wfcc_create_release_governance_entry(
	$journal,
	array(
		'gate'               => 'release_approval',
		'decision'           => 'approve',
		'reviewer'           => 'Release Owner',
		'evidence_reference' => 'RELEASE-2026-001',
		'recorded_by_id'     => 11,
		'recorded_by'        => 'Release Recorder',
		'recorded_at'        => '2026-07-24T10:06:00Z',
	)
);

wfcc_phase_8_assert(wfcc_release_governance_journal_is_valid($journal), 'an append-only governance hash chain must validate');
$governance = wfcc_evaluate_release_governance($journal, $readiness);
wfcc_phase_8_assert('approved' === $governance['status'], 'all ordered gates must approve the current release');
wfcc_phase_8_assert(5 === $governance['approved'], 'every fixed release gate must be counted');

$stale_journal = array(
	wfcc_create_release_governance_entry(
		array(),
		array(
			'gate'               => 'staging_validation',
			'decision'           => 'approve',
			'reviewer'           => 'Earlier Tester',
			'evidence_reference' => 'STAGE-OLD',
			'version'            => '0.7.0',
			'schema'             => '7',
			'recorded_at'        => '2026-07-23T10:00:00Z',
		)
	),
);
$stale = wfcc_evaluate_release_governance($stale_journal, $readiness);
wfcc_phase_8_assert(1 === $stale['stale_entries'] && 0 === $stale['approved'], 'earlier-version approvals must not carry forward');

$tampered = $journal;
$tampered[1]['reviewer'] = 'Changed Reviewer';
wfcc_phase_8_assert(!wfcc_release_governance_journal_is_valid($tampered), 'a modified journal entry must break the hash chain');
wfcc_phase_8_assert('tampered' === wfcc_evaluate_release_governance($tampered, $readiness)['status'], 'journal corruption must block governance');
wfcc_phase_8_assert(!wfcc_release_evidence_text_is_safe('sk_live_not_allowed'), 'Stripe secret shapes must be rejected from evidence');
wfcc_phase_8_assert(!wfcc_release_evidence_fields_are_safe('SEC-2026-001', 'access_token=not-allowed'), 'secret-shaped decision notes must be rejected');
wfcc_phase_8_assert(wfcc_release_evidence_fields_are_safe('SEC-2026-001', 'Independent review completed.'), 'opaque references and safe notes must pass');

$bundle = wfcc_build_release_evidence_bundle($journal, $readiness, 'staging.example.test');
$bundle_checksum = $bundle['sha256'];
unset($bundle['sha256']);
wfcc_phase_8_assert(
	$bundle_checksum === hash('sha256', wp_json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
	'the exported evidence checksum must cover the complete bundle'
);

$GLOBALS['wfcc_test_options'] = array(
	'wfcc_schema_version' => '7',
	'wfcc_settings'       => array('currency' => 'AUD'),
);
$GLOBALS['wfcc_test_administrator'] = new WFCC_Test_Role();
wfcc_maybe_upgrade_schema();
wfcc_phase_8_assert('8' === get_option('wfcc_schema_version'), 'schema 7 must upgrade to schema 8');
wfcc_phase_8_assert(array() === get_option(WFCC_RELEASE_GOVERNANCE_OPTION), 'schema 8 must initialise an empty governance journal');
wfcc_phase_8_assert(
	isset($GLOBALS['wfcc_test_administrator']->capabilities['wfcc_approve_release']),
	'schema 8 must grant the least-privilege release capability'
);

$GLOBALS['wfcc_test_options'][WFCC_RELEASE_GOVERNANCE_OPTION] = array();
wfcc_phase_8_assert(
	wfcc_append_release_governance_entry(
		array(
			'gate'               => 'staging_validation',
			'decision'           => 'approve',
			'reviewer'           => 'Staging Lead',
			'evidence_reference' => 'STAGE-LOCK-001',
			'recorded_at'        => '2026-07-24T11:00:00Z',
		)
	),
	'the locked journal append must save a valid entry'
);
wfcc_phase_8_assert(
	wfcc_release_governance_journal_is_valid(get_option(WFCC_RELEASE_GOVERNANCE_OPTION)),
	'the stored journal must remain valid after the locked append'
);
wfcc_phase_8_assert(false === get_option('wfcc_release_governance_lock', false), 'the journal lock must be released after saving');

fwrite(STDOUT, "WFC Cart Phase 8 contract tests passed.\n");
