<?php
/**
 * Version-bound production release governance and evidence export.
 */

if (!defined('ABSPATH')) {
	exit;
}

define('WFCC_RELEASE_GOVERNANCE_OPTION', 'wfcc_release_governance_journal');

add_action('admin_post_wfcc_record_release_gate', 'wfcc_handle_release_gate_action');
add_action('admin_post_wfcc_export_release_evidence', 'wfcc_handle_release_evidence_export');

/**
 * Return the ordered production release gates.
 *
 * @return array<string, array{label:string,description:string,requires:string[],independent:bool}>
 */
function wfcc_release_gate_definitions() {
	return array(
		'staging_validation' => array(
			'label'       => __('External staging validation', 'wfc-cart'),
			'description' => __('The production validation matrix has been completed in a representative external staging environment.', 'wfc-cart'),
			'requires'    => array(),
			'independent' => false,
		),
		'security_review' => array(
			'label'       => __('Independent security review', 'wfc-cart'),
			'description' => __('An independent reviewer has assessed checkout, integration, access-control, and data-handling boundaries.', 'wfc-cart'),
			'requires'    => array('staging_validation'),
			'independent' => true,
		),
		'accessibility_review' => array(
			'label'       => __('Independent accessibility review', 'wfc-cart'),
			'description' => __('An independent reviewer has completed keyboard, focus, announcement, zoom, contrast, and assistive-technology checks.', 'wfc-cart'),
			'requires'    => array('staging_validation'),
			'independent' => true,
		),
		'production_pilot' => array(
			'label'       => __('Production pilot approval', 'wfc-cart'),
			'description' => __('A bounded production pilot has met the documented operational, rollback, and support criteria.', 'wfc-cart'),
			'requires'    => array('security_review', 'accessibility_review'),
			'independent' => false,
		),
		'release_approval' => array(
			'label'       => __('Final production release approval', 'wfc-cart'),
			'description' => __('The authorised release owner accepts the evidence bundle and approves the current version for general production use.', 'wfc-cart'),
			'requires'    => array('production_pilot'),
			'independent' => false,
		),
	);
}

/**
 * Return the fixed hash payload for one journal entry.
 *
 * @param array<string, mixed> $entry Journal entry.
 * @return string
 */
function wfcc_release_governance_entry_payload($entry) {
	$payload = array(
		'sequence'             => absint($entry['sequence'] ?? 0),
		'gate'                 => sanitize_key($entry['gate'] ?? ''),
		'decision'             => sanitize_key($entry['decision'] ?? ''),
		'reviewer'             => sanitize_text_field($entry['reviewer'] ?? ''),
		'evidence_reference'   => sanitize_text_field($entry['evidence_reference'] ?? ''),
		'note'                 => sanitize_textarea_field($entry['note'] ?? ''),
		'independent_attested' => !empty($entry['independent_attested']),
		'recorded_by_id'       => absint($entry['recorded_by_id'] ?? 0),
		'recorded_by'          => sanitize_text_field($entry['recorded_by'] ?? ''),
		'recorded_at'          => sanitize_text_field($entry['recorded_at'] ?? ''),
		'version'              => sanitize_text_field($entry['version'] ?? ''),
		'schema'               => sanitize_text_field($entry['schema'] ?? ''),
		'previous_hash'        => sanitize_text_field($entry['previous_hash'] ?? ''),
	);

	return (string) wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Create the next tamper-evident release governance entry.
 *
 * @param array<int, array<string, mixed>> $journal Existing journal.
 * @param array<string, mixed>             $data    Sanitised entry data.
 * @return array<string, mixed>
 */
function wfcc_create_release_governance_entry($journal, $data) {
	$last = $journal ? end($journal) : array();
	$entry = array(
		'sequence'             => count($journal) + 1,
		'gate'                 => sanitize_key($data['gate'] ?? ''),
		'decision'             => sanitize_key($data['decision'] ?? ''),
		'reviewer'             => substr(sanitize_text_field($data['reviewer'] ?? ''), 0, 160),
		'evidence_reference'   => substr(sanitize_text_field($data['evidence_reference'] ?? ''), 0, 240),
		'note'                 => substr(sanitize_textarea_field($data['note'] ?? ''), 0, 1000),
		'independent_attested' => !empty($data['independent_attested']),
		'recorded_by_id'       => absint($data['recorded_by_id'] ?? 0),
		'recorded_by'          => substr(sanitize_text_field($data['recorded_by'] ?? ''), 0, 160),
		'recorded_at'          => sanitize_text_field($data['recorded_at'] ?? gmdate('c')),
		'version'              => sanitize_text_field($data['version'] ?? WFCC_VERSION),
		'schema'               => sanitize_text_field($data['schema'] ?? WFCC_SCHEMA_VERSION),
		'previous_hash'        => is_array($last) ? sanitize_text_field($last['hash'] ?? '') : '',
	);
	$entry['hash'] = hash('sha256', wfcc_release_governance_entry_payload($entry));

	return $entry;
}

/**
 * Verify sequence and hash-chain integrity.
 *
 * @param mixed $journal Candidate journal.
 * @return bool
 */
function wfcc_release_governance_journal_is_valid($journal) {
	if (!is_array($journal)) {
		return false;
	}

	$definitions   = wfcc_release_gate_definitions();
	$previous_hash = '';
	foreach (array_values($journal) as $index => $entry) {
		if (!is_array($entry)) {
			return false;
		}
		if (
			!isset($definitions[sanitize_key($entry['gate'] ?? '')])
			|| !in_array(sanitize_key($entry['decision'] ?? ''), array('approve', 'reject', 'revoke'), true)
		) {
			return false;
		}
		if (($index + 1) !== absint($entry['sequence'] ?? 0)) {
			return false;
		}
		if ($previous_hash !== (string) ($entry['previous_hash'] ?? '')) {
			return false;
		}
		$expected_hash = hash('sha256', wfcc_release_governance_entry_payload($entry));
		if (!hash_equals($expected_hash, (string) ($entry['hash'] ?? ''))) {
			return false;
		}
		$previous_hash = $expected_hash;
	}

	return true;
}

/**
 * Return whether a saved technical audit can support current release approval.
 *
 * @param mixed $readiness Saved readiness snapshot.
 * @return bool
 */
function wfcc_release_readiness_snapshot_is_current($readiness) {
	return is_array($readiness)
		&& !empty($readiness['checked_at'])
		&& WFCC_VERSION === (string) ($readiness['version'] ?? '')
		&& WFCC_SCHEMA_VERSION === (string) ($readiness['schema'] ?? '')
		&& 0 === absint($readiness['blocking'] ?? 1)
		&& in_array((string) ($readiness['status'] ?? ''), array('ready', 'warning'), true);
}

/**
 * Evaluate current-version release governance.
 *
 * @param mixed $journal   Governance journal.
 * @param mixed $readiness Saved technical audit.
 * @return array<string, mixed>
 */
function wfcc_evaluate_release_governance($journal, $readiness) {
	$definitions   = wfcc_release_gate_definitions();
	$journal_valid = wfcc_release_governance_journal_is_valid($journal);
	$current       = array();
	$stale_count   = 0;

	if ($journal_valid) {
		foreach ($journal as $entry) {
			if (
				WFCC_VERSION !== (string) ($entry['version'] ?? '')
				|| WFCC_SCHEMA_VERSION !== (string) ($entry['schema'] ?? '')
			) {
				++$stale_count;
				continue;
			}
			$gate = sanitize_key($entry['gate'] ?? '');
			if (isset($definitions[$gate])) {
				$current[$gate] = $entry;
			}
		}
	}

	$technical_ready = wfcc_release_readiness_snapshot_is_current($readiness);
	$gates           = array();
	$approved        = 0;
	$rejected        = 0;
	$blocked         = 0;

	foreach ($definitions as $gate_key => $definition) {
		$entry          = $current[$gate_key] ?? array();
		$decision       = sanitize_key($entry['decision'] ?? '');
		$prerequisites  = $definition['requires'];
		$prerequisites_ready = true;
		foreach ($prerequisites as $required_gate) {
			if ('approved' !== ($gates[$required_gate]['status'] ?? 'pending')) {
				$prerequisites_ready = false;
				break;
			}
		}
		if ('staging_validation' === $gate_key || 'release_approval' === $gate_key) {
			$prerequisites_ready = $prerequisites_ready && $technical_ready;
		}

		$status = 'pending';
		if ('reject' === $decision) {
			$status = 'rejected';
			++$rejected;
		} elseif ('approve' === $decision) {
			$independent_ready = !$definition['independent'] || !empty($entry['independent_attested']);
			if ($prerequisites_ready && $independent_ready) {
				$status = 'approved';
				++$approved;
			} else {
				$status = 'blocked';
				++$blocked;
			}
		}

		$gates[$gate_key] = array(
			'label'         => $definition['label'],
			'description'   => $definition['description'],
			'independent'   => $definition['independent'],
			'status'        => $status,
			'can_approve'   => $journal_valid && $prerequisites_ready,
			'entry'         => $entry,
		);
	}

	$gate_count = count($definitions);
	$status = !$journal_valid
		? 'tampered'
		: ($approved === $gate_count ? 'approved' : (($rejected + $blocked) > 0 ? 'blocked' : 'pending'));

	return array(
		'status'          => $status,
		'approved'        => $approved,
		'total'           => $gate_count,
		'rejected'        => $rejected,
		'blocked'         => $blocked,
		'technical_ready' => $technical_ready,
		'journal_valid'   => $journal_valid,
		'stale_entries'   => $stale_count,
		'gates'           => $gates,
	);
}

/**
 * Append a validated decision to the governance journal.
 *
 * @param array<string, mixed> $data Entry data.
 * @return bool
 */
function wfcc_append_release_governance_entry($data) {
	$lock_option = 'wfcc_release_governance_lock';
	$lock_token  = wp_generate_uuid4();
	$lock_value  = array('token' => $lock_token, 'acquired_at' => time());
	if (!add_option($lock_option, $lock_value, '', false)) {
		$existing_lock = get_option($lock_option, array());
		if (
			!is_array($existing_lock)
			|| (time() - absint($existing_lock['acquired_at'] ?? 0)) <= 60
		) {
			return false;
		}
		delete_option($lock_option);
		if (!add_option($lock_option, $lock_value, '', false)) {
			return false;
		}
	}

	try {
		$journal = get_option(WFCC_RELEASE_GOVERNANCE_OPTION, array());
		if (!wfcc_release_governance_journal_is_valid($journal)) {
			return false;
		}

		$journal[] = wfcc_create_release_governance_entry($journal, $data);

		return update_option(WFCC_RELEASE_GOVERNANCE_OPTION, $journal, false);
	} finally {
		$current_lock = get_option($lock_option, array());
		if (is_array($current_lock) && $lock_token === ($current_lock['token'] ?? '')) {
			delete_option($lock_option);
		}
	}
}

/**
 * Reject common secret shapes from exported release evidence fields.
 *
 * @param string $value Evidence text.
 * @return bool
 */
function wfcc_release_evidence_text_is_safe($value) {
	return !preg_match(
		'/(?:sk_(?:live|test)_|whsec_|client[_ -]?secret|access[_ -]?token|authorization\\s*:\\s*bearer|password\\s*[=:]|\\bpm_[A-Za-z0-9]{8,})/i',
		(string) $value
	);
}

/**
 * Return whether the decision's evidence fields are safe to export.
 *
 * @param string $evidence_reference Evidence reference.
 * @param string $note               Decision note.
 * @return bool
 */
function wfcc_release_evidence_fields_are_safe($evidence_reference, $note) {
	if (!wfcc_release_evidence_text_is_safe($evidence_reference)) {
		return false;
	}

	return wfcc_release_evidence_text_is_safe($note);
}

/**
 * Redirect to the release readiness page with a result code.
 *
 * @param string $result Result code.
 * @return void
 */
function wfcc_release_governance_redirect($result) {
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'                 => 'wfcc-readiness',
				'wfcc_release_result'  => sanitize_key($result),
			),
			admin_url('admin.php')
		)
	);
	exit;
}

/**
 * Record an approval, rejection, or revocation.
 *
 * @return void
 */
function wfcc_handle_release_gate_action() {
	if (!current_user_can('wfcc_approve_release')) {
		wp_die(esc_html__('You are not allowed to record release decisions.', 'wfc-cart'));
	}
	check_admin_referer('wfcc_record_release_gate');

	$gate        = isset($_POST['gate']) ? sanitize_key(wp_unslash($_POST['gate'])) : '';
	$decision    = isset($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '';
	$definitions = wfcc_release_gate_definitions();
	if (!isset($definitions[$gate]) || !in_array($decision, array('approve', 'reject', 'revoke'), true)) {
		wfcc_release_governance_redirect('invalid');
	}

	$journal    = get_option(WFCC_RELEASE_GOVERNANCE_OPTION, array());
	$readiness  = get_option('wfcc_readiness_last_run', array());
	$governance = wfcc_evaluate_release_governance($journal, $readiness);
	if (!$governance['journal_valid']) {
		wfcc_release_governance_redirect('integrity');
	}

	$reviewer           = isset($_POST['reviewer']) ? substr(sanitize_text_field(wp_unslash($_POST['reviewer'])), 0, 160) : '';
	$evidence_reference = isset($_POST['evidence_reference']) ? substr(sanitize_text_field(wp_unslash($_POST['evidence_reference'])), 0, 240) : '';
	$note               = isset($_POST['note']) ? substr(sanitize_textarea_field(wp_unslash($_POST['note'])), 0, 1000) : '';
	$attested           = !empty($_POST['independent_attested']);

	if (
		'approve' === $decision
		&& (
			empty($governance['gates'][$gate]['can_approve'])
			|| '' === $reviewer
			|| '' === $evidence_reference
			|| ($definitions[$gate]['independent'] && !$attested)
		)
	) {
		wfcc_release_governance_redirect('prerequisite');
	}
	if (in_array($decision, array('reject', 'revoke'), true) && '' === $note) {
		wfcc_release_governance_redirect('note_required');
	}
	if (!wfcc_release_evidence_fields_are_safe($evidence_reference, $note)) {
		wfcc_release_governance_redirect('sensitive');
	}

	$user = wp_get_current_user();
	$saved = wfcc_append_release_governance_entry(
		array(
			'gate'                 => $gate,
			'decision'             => $decision,
			'reviewer'             => $reviewer,
			'evidence_reference'   => $evidence_reference,
			'note'                 => $note,
			'independent_attested' => $attested,
			'recorded_by_id'       => get_current_user_id(),
			'recorded_by'          => $user->display_name ?? '',
			'recorded_at'          => gmdate('c'),
		)
	);

	wfcc_release_governance_redirect($saved ? 'saved' : 'failed');
}

/**
 * Build a portable, privacy-minimised release evidence bundle.
 *
 * @param mixed  $journal     Governance journal.
 * @param mixed  $readiness   Saved technical audit.
 * @param string $environment Environment identifier.
 * @return array<string, mixed>
 */
function wfcc_build_release_evidence_bundle($journal, $readiness, $environment) {
	$governance = wfcc_evaluate_release_governance($journal, $readiness);
	$bundle = array(
		'product'         => 'WFC Cart',
		'version'         => WFCC_VERSION,
		'schema'          => WFCC_SCHEMA_VERSION,
		'generated_at'    => gmdate('c'),
		'environment'     => sanitize_text_field($environment),
		'technical_audit' => is_array($readiness) ? $readiness : array(),
		'governance'      => array(
			'status'          => $governance['status'],
			'approved'        => $governance['approved'],
			'total'           => $governance['total'],
			'journal_valid'   => $governance['journal_valid'],
			'stale_entries'   => $governance['stale_entries'],
		),
		'journal'         => is_array($journal) ? array_values($journal) : array(),
	);
	$bundle['sha256'] = hash(
		'sha256',
		(string) wp_json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
	);

	return $bundle;
}

/**
 * Download the current release evidence bundle.
 *
 * @return void
 */
function wfcc_handle_release_evidence_export() {
	if (!current_user_can('wfcc_approve_release')) {
		wp_die(esc_html__('You are not allowed to export release evidence.', 'wfc-cart'));
	}
	check_admin_referer('wfcc_export_release_evidence');

	$bundle = wfcc_build_release_evidence_bundle(
		get_option(WFCC_RELEASE_GOVERNANCE_OPTION, array()),
		get_option('wfcc_readiness_last_run', array()),
		wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'wordpress-site'
	);

	nocache_headers();
	header('Content-Type: application/json; charset=utf-8');
	header('Content-Disposition: attachment; filename="wfc-cart-' . WFCC_VERSION . '-release-evidence.json"');
	echo wp_json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}
