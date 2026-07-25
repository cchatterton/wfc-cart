<?php
/**
 * Phase 7 production-readiness contracts without a WordPress installation.
 */

require __DIR__ . '/phase-6-core.php';

/**
 * Fail the Phase 7 contract test.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure.
 * @return void
 */
function wfcc_phase_7_assert($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "Phase 7 test failed: {$message}\n");
		exit(1);
	}
}

wfcc_phase_7_assert(wfcc_ip_in_cidr('203.0.113.42', '203.0.113.0/24'), 'IPv4 CIDR membership must resolve');
wfcc_phase_7_assert(!wfcc_ip_in_cidr('203.0.114.42', '203.0.113.0/24'), 'IPv4 CIDR boundaries must be enforced');
wfcc_phase_7_assert(wfcc_ip_in_cidr('2001:db8::10', '2001:db8::/32'), 'IPv6 CIDR membership must resolve');
wfcc_phase_7_assert(!wfcc_ip_in_cidr('2001:db9::10', '2001:db8::/32'), 'IPv6 CIDR boundaries must be enforced');
wfcc_phase_7_assert(!wfcc_ip_in_cidr('invalid', '203.0.113.0/24'), 'invalid IP input must fail closed');

$proxies = wfcc_sanitize_trusted_proxy_cidrs("203.0.113.5\n2001:db8::/32\ninvalid\n10.0.0.0/99\n0.0.0.0/0\n::/0");
wfcc_phase_7_assert(
	array('203.0.113.5/32', '2001:db8::/32') === $proxies,
	'trusted proxies must be normalised and invalid networks removed'
);

$server = array(
	'REMOTE_ADDR'         => '203.0.113.5',
	'HTTP_X_FORWARDED_FOR' => '198.51.100.25, 203.0.113.5',
);
wfcc_phase_7_assert(
	'198.51.100.25' === wfcc_resolve_request_ip($server, array('203.0.113.0/24')),
	'a forwarded client address may be used behind an approved proxy'
);
wfcc_phase_7_assert(
	'203.0.113.5' === wfcc_resolve_request_ip($server, array()),
	'a forwarding header must be ignored when the proxy is not approved'
);
$spoofed_server = array(
	'REMOTE_ADDR'          => '203.0.113.5',
	'HTTP_X_FORWARDED_FOR' => '192.0.2.66, 198.51.100.25',
);
wfcc_phase_7_assert(
	'198.51.100.25' === wfcc_resolve_request_ip($spoofed_server, array('203.0.113.0/24')),
	'a spoofed leftmost forwarded address must not override the nearest untrusted client hop'
);
wfcc_phase_7_assert(
	'unknown' === wfcc_resolve_request_ip(array('REMOTE_ADDR' => 'not-an-ip'), array()),
	'an invalid remote address must fail to an opaque identity'
);

wfcc_phase_7_assert(wfcc_request_body_is_bounded(str_repeat('a', 16384), 16384), 'the exact request-size boundary must pass');
wfcc_phase_7_assert(!wfcc_request_body_is_bounded(str_repeat('a', 16385), 16384), 'an oversized request must fail');

$ready_context = array(
	'php_version'              => '8.3.0',
	'wp_version'               => '6.8',
	'https'                    => true,
	'schema_version'           => WFCC_SCHEMA_VERSION,
	'gravity_forms'            => true,
	'stripe'                   => true,
	'webhook'                  => true,
	'crm_mode'                 => 'salesforce',
	'salesforce'               => true,
	'packages'                 => true,
	'wordpress_recurring_packages' => false,
	'queue_scheduled'          => true,
	'cron_disabled'            => false,
	'receipt_email_enabled'    => false,
	'receipt_email_field'      => false,
	'forwarded_header_present' => false,
	'trusted_proxies'          => false,
	'rest_hardened'            => true,
	'multisite'                => false,
);
$checks = wfcc_evaluate_readiness_context($ready_context);
$summary = wfcc_summarize_readiness($checks);
wfcc_phase_7_assert('ready' === $summary['status'], 'a complete production context must be ready');
wfcc_phase_7_assert(0 === $summary['blocking'] && 0 === $summary['warning'], 'a ready context must have no findings');

$blocked_context = $ready_context;
$blocked_context['https'] = false;
$blocked_context['schema_version'] = '6';
$blocked = wfcc_summarize_readiness(wfcc_evaluate_readiness_context($blocked_context));
wfcc_phase_7_assert('blocked' === $blocked['status'] && 2 === $blocked['blocking'], 'HTTPS and schema failures must block readiness');

$warning_context = $ready_context;
$warning_context['forwarded_header_present'] = true;
$warning_context['trusted_proxies'] = false;
$warning_context['cron_disabled'] = true;
$warning = wfcc_summarize_readiness(wfcc_evaluate_readiness_context($warning_context));
wfcc_phase_7_assert('warning' === $warning['status'] && 2 === $warning['warning'], 'proxy and cron findings must remain visible warnings');

wfcc_phase_7_assert(function_exists('wfcc_activate_site'), 'site-scoped activation must exist');
wfcc_phase_7_assert(function_exists('wfcc_deactivate_site'), 'site-scoped deactivation must exist');
wfcc_phase_7_assert(function_exists('wfcc_initialize_new_site'), 'new multisite sites must be initialised');

$GLOBALS['wfcc_test_is_multisite'] = true;
$GLOBALS['wfcc_test_site_ids'] = array(1, 2, 3);
$GLOBALS['wfcc_test_current_blog'] = 1;
$GLOBALS['wfcc_test_blog_stack'] = array();
$visited_sites = array();
wfcc_for_each_site(
	function () use (&$visited_sites) {
		$visited_sites[] = $GLOBALS['wfcc_test_current_blog'];
	}
);
wfcc_phase_7_assert(array(1, 2, 3) === $visited_sites, 'network lifecycle callbacks must visit every site');
wfcc_phase_7_assert(array() === $GLOBALS['wfcc_test_blog_stack'], 'network lifecycle callbacks must restore every site context');
$GLOBALS['wfcc_test_is_multisite'] = false;

$GLOBALS['wfcc_test_options'] = array(
	'wfcc_schema_version' => '6',
	'wfcc_settings'       => array(
		'currency'                   => 'NZD',
		'receipt_generation_enabled' => true,
	),
);
$GLOBALS['wfcc_test_administrator'] = new WFCC_Test_Role();
wfcc_maybe_upgrade_schema();
wfcc_phase_7_assert(WFCC_SCHEMA_VERSION === get_option('wfcc_schema_version'), 'schema 6 must upgrade to the current schema');
$upgraded_settings = get_option('wfcc_settings');
wfcc_phase_7_assert('NZD' === $upgraded_settings['currency'], 'the schema upgrade must preserve existing settings');
wfcc_phase_7_assert(array() === $upgraded_settings['trusted_proxy_cidrs'], 'the schema upgrade must add a safe empty proxy list');
$upgrade_journal = get_option('wfcc_last_schema_upgrade');
wfcc_phase_7_assert('6' === $upgrade_journal['from'] && WFCC_SCHEMA_VERSION === $upgrade_journal['to'], 'the schema upgrade must create a safe rollback journal');
wfcc_maybe_upgrade_schema();
wfcc_phase_7_assert($upgrade_journal === get_option('wfcc_last_schema_upgrade'), 'the schema upgrade must be idempotent');

$GLOBALS['wfcc_test_options'] = array();
$GLOBALS['wfcc_test_schedules'] = array();
$GLOBALS['wfcc_test_administrator'] = new WFCC_Test_Role();
wfcc_activate_site();
wfcc_phase_7_assert(WFCC_SCHEMA_VERSION === get_option('wfcc_schema_version'), 'fresh activation must install the current schema');
wfcc_phase_7_assert(isset(get_option('wfcc_settings')['trusted_proxy_cidrs']), 'fresh activation must include trusted-proxy defaults');
wfcc_phase_7_assert('wordpress' === get_option('wfcc_settings')['crm_mode'], 'fresh activation must default to WordPress CRM mode');
wfcc_phase_7_assert(false === wp_next_scheduled('wfcc_process_delivery_queue'), 'WordPress CRM mode must not schedule Salesforce delivery');
wfcc_phase_7_assert((bool) wp_next_scheduled('wfcc_cleanup_idempotency'), 'fresh activation must schedule cleanup');
wfcc_phase_7_assert(
	isset($GLOBALS['wfcc_test_administrator']->capabilities['wfcc_manage_settings']),
	'fresh activation must grant WFC capabilities'
);
wfcc_deactivate_site();
wfcc_phase_7_assert(false === wp_next_scheduled('wfcc_process_delivery_queue'), 'deactivation must stop delivery scheduling');
wfcc_phase_7_assert(false === wp_next_scheduled('wfcc_cleanup_idempotency'), 'deactivation must stop cleanup scheduling');
wfcc_phase_7_assert(is_array(get_option('wfcc_settings')), 'deactivation must preserve settings');

$GLOBALS['wfcc_test_options']['wfcc_settings']['crm_mode'] = 'salesforce';
$GLOBALS['wfcc_test_schedules'] = array();
wfcc_activate_site();
wfcc_phase_7_assert((bool) wp_next_scheduled('wfcc_process_delivery_queue'), 'Salesforce CRM mode must schedule delivery');
wfcc_deactivate_site();

fwrite(STDOUT, "WFC Cart Phase 7 contract tests passed.\n");
