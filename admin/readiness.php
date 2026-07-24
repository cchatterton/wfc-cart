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
	?>
	<div class="wrap wfcc-admin">
		<h1><?php echo esc_html__('WFC Cart Production Readiness', 'wfc-cart'); ?></h1>
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
	</div>
	<?php
}
