<?php
/**
 * Native WFC Cart transaction data model.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('init', 'wfcc_register_data_model', 5);

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
			'supports'            => array('title', 'editor', 'author'),
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

