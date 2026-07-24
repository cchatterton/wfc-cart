<?php
/**
 * Minimal bootstrap smoke test without a WordPress installation.
 *
 * This verifies that the plugin entry point and native runtime can be included
 * without duplicate declarations or an immediate fatal error. It is not a
 * substitute for WordPress integration tests.
 */

define('ABSPATH', __DIR__ . '/wordpress/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);

function add_action() {}
function add_filter() {}
function apply_filters($hook, $value) {
	return $value;
}
function add_shortcode() {}
function add_post_type_support() {}
function __($text) {
	return $text;
}
function register_activation_hook() {}
function register_deactivation_hook() {}
function plugin_dir_path($file) {
	return trailingslashit(dirname($file));
}
function plugin_dir_url() {
	return 'https://example.test/wp-content/plugins/wfc-cart/';
}
function trailingslashit($value) {
	return rtrim($value, '/\\') . '/';
}
function wp_parse_args($args, $defaults = array()) {
	return array_merge($defaults, $args);
}
function wp_get_upload_dir() {
	return array('basedir' => sys_get_temp_dir() . '/wfcc-bootstrap-smoke');
}
function wp_mkdir_p($directory) {
	return is_dir($directory) || mkdir($directory, 0777, true);
}
function is_admin() {
	return false;
}
function wp_doing_ajax() {
	return false;
}
function get_option($name, $default = false) {
	return $default;
}
function wp_get_current_user() {
	return (object) array(
		'display_name' => 'Test User',
		'nickname'     => 'Test',
	);
}
function current_time($type) {
	return 'timestamp' === $type ? time() : '2026-07-24 00:00:00';
}
function plugin_basename($file) {
	return basename(dirname($file)) . '/' . basename($file);
}
function post_type_exists($post_type) {
	return isset($GLOBALS['wfcc_test_post_types'][$post_type]);
}
function register_post_type($post_type, $args) {
	$GLOBALS['wfcc_test_post_types'][$post_type] = $args;
}
function taxonomy_exists($taxonomy) {
	return isset($GLOBALS['wfcc_test_taxonomies'][$taxonomy]);
}
function register_taxonomy($taxonomy, $object_type, $args) {
	$GLOBALS['wfcc_test_taxonomies'][$taxonomy] = array($object_type, $args);
}

require dirname(__DIR__) . '/wfc-cart.php';

$required_constants = array(
	'WFCC_VERSION',
	'WFCC_PLUGIN_FILE',
	'WFCC_PLUGIN_DIR',
	'WFCC_PLUGIN_URL',
);

foreach ($required_constants as $constant) {
	if (!defined($constant)) {
		fwrite(STDERR, "Missing constant: {$constant}\n");
		exit(1);
	}
}

$required_functions = array(
	'wfcc_activate',
	'wfcc_get_settings',
	'wfcc_is_checkout_form',
);

foreach ($required_functions as $function) {
	if (!function_exists($function)) {
		fwrite(STDERR, "Missing function: {$function}\n");
		exit(1);
	}
}

wfcc_register_data_model();
foreach (array('transaction', 'transactionlineitem', 'transactionbatch', 'fundcode') as $post_type) {
	if (!post_type_exists($post_type)) {
		fwrite(STDERR, "Missing native post type: {$post_type}\n");
		exit(1);
	}
}

fwrite(STDOUT, "WFC Cart bootstrap smoke test passed.\n");
