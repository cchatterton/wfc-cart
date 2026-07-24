<?php
/**
 * Salesforce delivery queue administration.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Count transactions awaiting or retrying Salesforce delivery.
 *
 * @return int
 */
function wfcc_count_pending_deliveries() {
	$query = new WP_Query(
		array(
			'post_type'              => 'transaction',
			'post_status'            => 'any',
			'fields'                 => 'ids',
			'posts_per_page'         => 1,
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				array(
					'key'     => 'wfcc_salesforce_state',
					'value'   => array('salesforce_pending', 'salesforce_delivering', 'salesforce_failed', 'manual_review'),
					'compare' => 'IN',
				),
			),
		)
	);

	return (int) $query->found_posts;
}

/**
 * Render delivery queue records.
 *
 * @return void
 */
function wfcc_render_delivery_queue_page() {
	if (!current_user_can('wfcc_view_transactions')) {
		wp_die(esc_html__('You are not allowed to view the delivery queue.', 'wfc-cart'));
	}

	$transactions = get_posts(
		array(
			'post_type'      => 'transaction',
			'post_status'    => 'any',
			'posts_per_page' => 100,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => 'wfcc_salesforce_state',
					'value'   => array('salesforce_pending', 'salesforce_delivering', 'salesforce_failed', 'manual_review'),
					'compare' => 'IN',
				),
			),
		)
	);
	?>
	<div class="wrap wfcc-admin">
		<h1><?php echo esc_html__('Salesforce Delivery Queue', 'wfc-cart'); ?></h1>
		<table class="widefat striped wfcc-admin__table">
			<caption class="screen-reader-text"><?php echo esc_html__('Transactions awaiting Salesforce delivery or review', 'wfc-cart'); ?></caption>
			<thead><tr><th><?php echo esc_html__('Transaction', 'wfc-cart'); ?></th><th><?php echo esc_html__('State', 'wfc-cart'); ?></th><th><?php echo esc_html__('Attempts', 'wfc-cart'); ?></th><th><?php echo esc_html__('Last attempt', 'wfc-cart'); ?></th><th><?php echo esc_html__('Next attempt', 'wfc-cart'); ?></th><th><?php echo esc_html__('Last result', 'wfc-cart'); ?></th><th><?php echo esc_html__('Action', 'wfc-cart'); ?></th></tr></thead>
			<tbody>
				<?php if (!$transactions) : ?>
					<tr><td colspan="7"><?php echo esc_html__('No pending deliveries.', 'wfc-cart'); ?></td></tr>
				<?php else : ?>
					<?php foreach ($transactions as $transaction) : ?>
						<tr>
							<th scope="row"><a href="<?php echo esc_url(get_edit_post_link($transaction->ID)); ?>"><?php echo esc_html($transaction->post_title ?: '#' . $transaction->ID); ?></a></th>
							<td><?php echo esc_html(get_post_meta($transaction->ID, 'wfcc_salesforce_state', true)); ?></td>
							<td><?php echo esc_html(absint(get_post_meta($transaction->ID, 'wfcc_salesforce_delivery_attempts', true))); ?></td>
							<td><?php echo esc_html(get_post_meta($transaction->ID, 'wfcc_salesforce_last_attempt', true)); ?></td>
							<td><?php echo esc_html(get_post_meta($transaction->ID, 'wfcc_salesforce_next_attempt', true)); ?></td>
							<td>
								<?php
								$category = get_post_meta($transaction->ID, 'wfcc_salesforce_last_error_category', true);
								$status   = absint(get_post_meta($transaction->ID, 'wfcc_salesforce_last_http_status', true));
								echo esc_html($category ? $category . ($status ? ' (HTTP ' . $status . ')' : '') : get_post_meta($transaction->ID, 'wfcc_salesforce_reconciliation_state', true));
								?>
							</td>
							<td>
								<?php $state = get_post_meta($transaction->ID, 'wfcc_salesforce_state', true); ?>
								<?php if (current_user_can('wfcc_retry_deliveries') && in_array($state, array('salesforce_failed', 'manual_review'), true)) : ?>
									<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
										<input type="hidden" name="action" value="wfcc_retry_delivery">
										<input type="hidden" name="transaction_id" value="<?php echo esc_attr($transaction->ID); ?>">
										<?php wp_nonce_field('wfcc_retry_delivery_' . $transaction->ID); ?>
										<button class="button button-small" type="submit"><?php echo esc_html__('Retry', 'wfc-cart'); ?></button>
									</form>
								<?php else : ?>
									<span aria-hidden="true">&mdash;</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}
