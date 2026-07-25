<?php
/**
 * Native WFC Cart transaction data model.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('init', 'wfcc_register_data_model', 5);
add_filter('manage_transaction_posts_columns', 'wfcc_transaction_admin_columns');
add_action('manage_transaction_posts_custom_column', 'wfcc_render_transaction_admin_column', 10, 2);

/**
 * Register the WordPress-native transaction records used by WFC Cart.
 *
 * Existing slugs are retained so previously created operational records remain
 * readable without copying or rewriting them.
 *
 * @return void
 */
function wfcc_register_data_model() {
	wfcc_register_operational_post_type(
		'transaction',
		__('Transaction', 'wfc-cart'),
		__('Transactions', 'wfc-cart'),
		true
	);
	wfcc_register_operational_post_type(
		'transactionlineitem',
		__('Transaction Line Item', 'wfc-cart'),
		__('Transaction Line Items', 'wfc-cart'),
		false
	);
	wfcc_register_operational_post_type(
		'transactionbatch',
		__('Transaction Batch', 'wfc-cart'),
		__('Transaction Batches', 'wfc-cart'),
		false
	);
	wfcc_register_operational_post_type(
		'fundcode',
		__('Fund Code', 'wfc-cart'),
		__('Fund Codes', 'wfc-cart'),
		true
	);

	if (!taxonomy_exists('transaction')) {
		register_taxonomy(
			'transaction',
			array('transactionlineitem'),
			array(
				'label'        => __('Transactions', 'wfc-cart'),
				'public'       => false,
				'show_ui'      => false,
				'hierarchical' => true,
			)
		);
	}

	if (!taxonomy_exists('fundcode')) {
		register_taxonomy(
			'fundcode',
			array('transactionlineitem', 'product', 'give', 'person'),
			array(
				'label'             => __('Fund Codes', 'wfc-cart'),
				'public'            => false,
				'show_ui'           => false,
				'show_admin_column' => false,
				'hierarchical'      => true,
			)
		);
	}
}

/**
 * Register one protected operational post type.
 *
 * @param string $slug       Post type slug.
 * @param string $singular   Singular label.
 * @param string $plural     Plural label.
 * @param bool   $show_ui    Whether to show the list UI.
 * @return void
 */
function wfcc_register_operational_post_type($slug, $singular, $plural, $show_ui) {
	if (post_type_exists($slug)) {
		return;
	}

	register_post_type(
		$slug,
		array(
			'labels' => array(
				'name'          => $plural,
				'singular_name' => $singular,
				'search_items'  => sprintf(__('Search %s', 'wfc-cart'), $plural),
				'not_found'     => sprintf(__('No %s found.', 'wfc-cart'), strtolower($plural)),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => $show_ui,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'hierarchical'        => false,
			// Operational records are created by WFC Cart. Donor-entered text
			// belongs only in the linked Gravity Forms entry.
			'supports'            => array(),
			'capabilities'        => array(
				'edit_post'          => 'wfcc_view_transactions',
				'read_post'          => 'wfcc_view_transactions',
				'delete_post'        => 'wfcc_retry_deliveries',
				'edit_posts'         => 'wfcc_view_transactions',
				'edit_others_posts'  => 'wfcc_view_transactions',
				'publish_posts'      => 'wfcc_retry_deliveries',
				'read_private_posts' => 'wfcc_view_transactions',
				'delete_posts'       => 'wfcc_retry_deliveries',
				'create_posts'       => 'do_not_allow',
			),
			'map_meta_cap'        => false,
		)
	);
}

/**
 * Add privacy-safe CRM and Gravity Forms references to the transaction list.
 *
 * @param array<string, string> $columns Existing columns.
 * @return array<string, string>
 */
function wfcc_transaction_admin_columns($columns) {
	$output = array();
	foreach ($columns as $key => $label) {
		$output[$key] = $label;
		if ('title' === $key) {
			$output['wfcc_crm'] = __('CRM state', 'wfc-cart');
			$output['wfcc_entry'] = __('Cart entry', 'wfc-cart');
		}
	}

	return $output;
}

/**
 * Render transaction-list columns without exposing donor field values.
 *
 * @param string $column  Column key.
 * @param int    $post_id Transaction ID.
 * @return void
 */
function wfcc_render_transaction_admin_column($column, $post_id) {
	if ('wfcc_crm' === $column) {
		echo esc_html(wfcc_get_transaction_crm_mode($post_id) . ': ' . wfcc_get_transaction_crm_state($post_id));
		return;
	}
	if ('wfcc_entry' !== $column) {
		return;
	}

	$entry_id = absint(get_post_meta($post_id, 'wfcc_gravity_forms_entry_id', true));
	$form_id  = absint(get_post_meta($post_id, 'wfcc_form_id', true));
	if (!$entry_id || !$form_id) {
		echo '<span aria-hidden="true">&mdash;</span>';
		return;
	}

	$url = add_query_arg(
		array(
			'page' => 'gf_entries',
			'view' => 'entry',
			'id'   => $form_id,
			'lid'  => $entry_id,
		),
		admin_url('admin.php')
	);
	echo '<a href="' . esc_url($url) . '">' . esc_html(sprintf(__('Entry #%d', 'wfc-cart'), $entry_id)) . '</a>';
}
