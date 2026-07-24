<?php
/**
 * Production-readiness administration.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Render the executable readiness audit.
 *
 * @return void
 */
function wfcc_render_readiness_page() {
	if (!current_user_can('wfcc_manage_settings')) {
		wp_die(esc_html__('You are not allowed to view production readiness.', 'wfc-cart'));
	}

	$checks   = wfcc_evaluate_readiness_context(wfcc_get_readiness_context());
	$summary  = wfcc_summarize_readiness($checks);
	$last_run = get_option('wfcc_readiness_last_run', array());
	$journal  = get_option(WFCC_RELEASE_GOVERNANCE_OPTION, array());
	$release  = wfcc_evaluate_release_governance($journal, $last_run);
	$result   = isset($_GET['wfcc_release_result']) ? sanitize_key(wp_unslash($_GET['wfcc_release_result'])) : '';
	?>
	<div class="wrap wfcc-admin">
		<h1><?php echo esc_html__('WFC Cart Production Readiness', 'wfc-cart'); ?></h1>
		<?php if ($result) : ?>
			<div class="notice <?php echo 'saved' === $result ? 'notice-success' : 'notice-error'; ?> is-dismissible" role="status">
				<p>
					<?php
					$messages = array(
						'saved'         => __('The release decision was added to the governance journal.', 'wfc-cart'),
						'invalid'       => __('The release decision was invalid.', 'wfc-cart'),
						'integrity'     => __('The governance journal failed its integrity check. No decision was recorded.', 'wfc-cart'),
						'prerequisite'  => __('The gate cannot be approved until its prerequisites, reviewer, evidence reference, and required attestation are complete.', 'wfc-cart'),
						'note_required' => __('A note is required when rejecting or revoking a release gate.', 'wfc-cart'),
						'sensitive'     => __('The evidence reference or note appears to contain a secret. Store sensitive evidence externally and enter only its controlled reference.', 'wfc-cart'),
						'failed'        => __('The release decision could not be saved.', 'wfc-cart'),
					);
					echo esc_html($messages[$result] ?? __('The release governance request could not be completed.', 'wfc-cart'));
					?>
				</p>
			</div>
		<?php endif; ?>
		<div class="notice <?php echo 'blocked' === $summary['status'] ? 'notice-error' : ('warning' === $summary['status'] ? 'notice-warning' : 'notice-success'); ?>" role="status">
			<p>
				<?php
				echo esc_html(
					sprintf(
						__('Current result: %1$s — %2$d passed, %3$d warnings, %4$d blocking.', 'wfc-cart'),
						$summary['status'],
						$summary['pass'],
						$summary['warning'],
						$summary['blocking']
					)
				);
				?>
			</p>
		</div>
		<?php if (is_array($last_run) && !empty($last_run['checked_at'])) : ?>
			<p><?php echo esc_html(sprintf(__('Last saved audit: %1$s (%2$s).', 'wfc-cart'), $last_run['checked_at'], $last_run['status'] ?? 'unknown')); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="wfcc_run_readiness_audit">
			<?php wp_nonce_field('wfcc_run_readiness_audit'); ?>
			<button class="button button-primary" type="submit"><?php echo esc_html__('Run and save readiness audit', 'wfc-cart'); ?></button>
		</form>
		<table class="widefat striped wfcc-admin__table">
			<caption class="screen-reader-text"><?php echo esc_html__('WFC Cart production-readiness checks', 'wfc-cart'); ?></caption>
			<thead><tr><th><?php echo esc_html__('Check', 'wfc-cart'); ?></th><th><?php echo esc_html__('Status', 'wfc-cart'); ?></th><th><?php echo esc_html__('Detail', 'wfc-cart'); ?></th></tr></thead>
			<tbody>
			<?php foreach ($checks as $check) : ?>
				<tr>
					<th scope="row"><?php echo esc_html($check['label']); ?></th>
					<td><span class="wfcc-status wfcc-status--<?php echo esc_attr($check['status']); ?>"><?php echo esc_html($check['status']); ?></span></td>
					<td><?php echo esc_html($check['detail']); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php echo esc_html__('This audit validates configuration and runtime boundaries. Complete the documented external staging scenarios before production launch.', 'wfc-cart'); ?></p>

		<hr>
		<h2><?php echo esc_html__('Phase 8 release governance', 'wfc-cart'); ?></h2>
		<div class="notice <?php echo 'approved' === $release['status'] ? 'notice-success' : ('tampered' === $release['status'] ? 'notice-error' : 'notice-warning'); ?>" role="status">
			<p>
				<?php
				echo esc_html(
					sprintf(
						__('Release result for WFC Cart %1$s: %2$s — %3$d of %4$d gates approved.', 'wfc-cart'),
						WFCC_VERSION,
						$release['status'],
						$release['approved'],
						$release['total']
					)
				);
				?>
			</p>
		</div>
		<?php if (!$release['technical_ready']) : ?>
			<p class="description"><?php echo esc_html__('Run and save the technical readiness audit for this exact plugin version and schema before approving external staging.', 'wfc-cart'); ?></p>
		<?php endif; ?>
		<?php if (!$release['journal_valid']) : ?>
			<p class="wfcc-admin__error"><?php echo esc_html__('The approval journal hash chain is invalid. Approval actions are disabled pending investigation and recovery from a trusted backup.', 'wfc-cart'); ?></p>
		<?php endif; ?>
		<?php if ($release['stale_entries'] > 0) : ?>
			<p class="description"><?php echo esc_html(sprintf(__('%d earlier-version journal entries remain preserved but do not approve this release.', 'wfc-cart'), $release['stale_entries'])); ?></p>
		<?php endif; ?>

		<table class="widefat striped wfcc-admin__table">
			<caption class="screen-reader-text"><?php echo esc_html__('WFC Cart production release gates', 'wfc-cart'); ?></caption>
			<thead>
				<tr>
					<th><?php echo esc_html__('Gate', 'wfc-cart'); ?></th>
					<th><?php echo esc_html__('Status', 'wfc-cart'); ?></th>
					<th><?php echo esc_html__('Current evidence', 'wfc-cart'); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($release['gates'] as $gate) : ?>
				<?php
				$entry        = $gate['entry'];
				$status_class = 'approved' === $gate['status'] ? 'pass' : (in_array($gate['status'], array('blocked', 'rejected'), true) ? 'blocking' : 'warning');
				?>
				<tr>
					<th scope="row">
						<?php echo esc_html($gate['label']); ?>
						<p class="description"><?php echo esc_html($gate['description']); ?></p>
					</th>
					<td><span class="wfcc-status wfcc-status--<?php echo esc_attr($status_class); ?>"><?php echo esc_html($gate['status']); ?></span></td>
					<td>
						<?php if ($entry) : ?>
							<strong><?php echo esc_html($entry['reviewer'] ?? __('Not recorded', 'wfc-cart')); ?></strong><br>
							<?php echo esc_html($entry['evidence_reference'] ?? ''); ?><br>
							<span class="description"><?php echo esc_html($entry['recorded_at'] ?? ''); ?></span>
						<?php else : ?>
							<?php echo esc_html__('No current-version decision recorded.', 'wfc-cart'); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if (current_user_can('wfcc_approve_release') && $release['journal_valid']) : ?>
			<h3><?php echo esc_html__('Record a release decision', 'wfc-cart'); ?></h3>
			<p class="description"><?php echo esc_html__('Approvals require a named reviewer and a non-sensitive evidence reference. Rejections and revocations require a note. Every decision is appended to the version-bound hash chain.', 'wfc-cart'); ?></p>
			<form class="wfcc-admin__governance-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="wfcc_record_release_gate">
				<?php wp_nonce_field('wfcc_record_release_gate'); ?>
				<p>
					<label for="wfcc-release-gate"><?php echo esc_html__('Release gate', 'wfc-cart'); ?></label><br>
					<select id="wfcc-release-gate" name="gate">
					<?php foreach ($release['gates'] as $gate_key => $gate) : ?>
						<option value="<?php echo esc_attr($gate_key); ?>">
							<?php
							echo esc_html(
								$gate['can_approve']
									? $gate['label']
									: sprintf(__('%s — prerequisites pending', 'wfc-cart'), $gate['label'])
							);
							?>
						</option>
					<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label for="wfcc-release-reviewer"><?php echo esc_html__('Reviewer or approval owner', 'wfc-cart'); ?></label><br>
					<input class="regular-text" id="wfcc-release-reviewer" name="reviewer" maxlength="160" type="text">
				</p>
				<p>
					<label for="wfcc-release-evidence"><?php echo esc_html__('Evidence reference', 'wfc-cart'); ?></label><br>
					<input class="regular-text" id="wfcc-release-evidence" name="evidence_reference" maxlength="240" placeholder="<?php echo esc_attr__('Ticket, report, or controlled document reference', 'wfc-cart'); ?>" type="text">
				</p>
				<p>
					<label for="wfcc-release-note"><?php echo esc_html__('Decision note', 'wfc-cart'); ?></label><br>
					<textarea class="large-text" id="wfcc-release-note" name="note" maxlength="1000" rows="3"></textarea>
				</p>
				<p>
					<label>
						<input name="independent_attested" type="checkbox" value="1">
						<?php echo esc_html__('For an independent review gate, I attest that the named reviewer was independent of implementation and that the evidence contains no secrets or donor data.', 'wfc-cart'); ?>
					</label>
				</p>
				<p>
					<button class="button button-primary" name="decision" type="submit" value="approve"><?php echo esc_html__('Approve selected gate', 'wfc-cart'); ?></button>
					<button class="button" name="decision" type="submit" value="reject"><?php echo esc_html__('Reject selected gate', 'wfc-cart'); ?></button>
					<button class="button" name="decision" type="submit" value="revoke"><?php echo esc_html__('Revoke selected gate', 'wfc-cart'); ?></button>
				</p>
			</form>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="wfcc_export_release_evidence">
				<?php wp_nonce_field('wfcc_export_release_evidence'); ?>
				<button class="button" type="submit"><?php echo esc_html__('Download release evidence JSON', 'wfc-cart'); ?></button>
			</form>
		<?php endif; ?>
	</div>
	<?php
}
