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
define('DAY_IN_SECONDS', 86400);

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
	return array_key_exists($name, $GLOBALS['wfcc_test_options'] ?? array())
		? $GLOBALS['wfcc_test_options'][$name]
		: $default;
}
function add_option($name, $value) {
	if (array_key_exists($name, $GLOBALS['wfcc_test_options'] ?? array())) {
		return false;
	}
	$GLOBALS['wfcc_test_options'][$name] = $value;
	return true;
}
function update_option($name, $value) {
	$GLOBALS['wfcc_test_options'][$name] = $value;
	return true;
}
function delete_option($name) {
	unset($GLOBALS['wfcc_test_options'][$name]);
	return true;
}
if (!class_exists('WFCC_Test_Role')) {
	class WFCC_Test_Role {
		public $capabilities = array();

		public function add_cap($capability) {
			$this->capabilities[$capability] = true;
		}

		public function remove_cap($capability) {
			unset($this->capabilities[$capability]);
		}
	}
}
function get_role($role) {
	if ('administrator' !== $role) {
		return null;
	}
	if (empty($GLOBALS['wfcc_test_administrator'])) {
		$GLOBALS['wfcc_test_administrator'] = new WFCC_Test_Role();
	}
	return $GLOBALS['wfcc_test_administrator'];
}
function is_multisite() {
	return !empty($GLOBALS['wfcc_test_is_multisite']);
}
function get_sites($args = array()) {
	$sites  = $GLOBALS['wfcc_test_site_ids'] ?? array();
	$offset = isset($args['offset']) ? (int) $args['offset'] : 0;
	$number = isset($args['number']) ? (int) $args['number'] : count($sites);
	return array_slice($sites, $offset, $number);
}
function switch_to_blog($site_id) {
	$GLOBALS['wfcc_test_blog_stack'][] = $GLOBALS['wfcc_test_current_blog'] ?? 1;
	$GLOBALS['wfcc_test_current_blog'] = (int) $site_id;
	return true;
}
function restore_current_blog() {
	if (empty($GLOBALS['wfcc_test_blog_stack'])) {
		return false;
	}
	$GLOBALS['wfcc_test_current_blog'] = array_pop($GLOBALS['wfcc_test_blog_stack']);
	return true;
}
function wp_next_scheduled($hook) {
	return $GLOBALS['wfcc_test_schedules'][$hook][0] ?? false;
}
function wp_schedule_event($timestamp, $recurrence, $hook) {
	$GLOBALS['wfcc_test_schedules'][$hook][] = $timestamp;
	return true;
}
function wp_unschedule_event($timestamp, $hook) {
	if (empty($GLOBALS['wfcc_test_schedules'][$hook])) {
		return false;
	}
	$GLOBALS['wfcc_test_schedules'][$hook] = array_values(
		array_filter(
			$GLOBALS['wfcc_test_schedules'][$hook],
			function ($candidate) use ($timestamp) {
				return $candidate !== $timestamp;
			}
		)
	);
	return true;
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
	'wfcc_build_operational_report',
	'wfcc_create_transaction_receipt',
	'wfcc_evaluate_readiness_context',
	'wfcc_resolve_request_ip',
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
