<?php
/**
 * Executable production-readiness evaluation.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('admin_post_wfcc_run_readiness_audit', 'wfcc_handle_readiness_audit');

/**
 * Build fixed readiness checks from a testable environment context.
 *
 * @param array<string, mixed> $context Context.
 * @return array<string, array{label:string,status:string,detail:string}>
 */
function wfcc_evaluate_readiness_context($context) {
	$checks = array();

	$php_ok = version_compare((string) ($context['php_version'] ?? '0'), '8.1', '>=');
	$checks['php'] = array(
		'label'  => __('PHP runtime', 'wfc-cart'),
		'status' => $php_ok ? 'pass' : 'blocking',
		'detail' => $php_ok
			? sprintf(__('PHP %s meets the minimum.', 'wfc-cart'), $context['php_version'])
			: __('PHP 8.1 or later is required.', 'wfc-cart'),
	);

	$wp_ok = version_compare((string) ($context['wp_version'] ?? '0'), '6.4', '>=');
	$checks['wordpress'] = array(
		'label'  => __('WordPress runtime', 'wfc-cart'),
		'status' => $wp_ok ? 'pass' : 'blocking',
		'detail' => $wp_ok
			? sprintf(__('WordPress %s meets the minimum.', 'wfc-cart'), $context['wp_version'])
			: __('WordPress 6.4 or later is required.', 'wfc-cart'),
	);

	$checks['https'] = array(
		'label'  => __('HTTPS checkout', 'wfc-cart'),
		'status' => !empty($context['https']) ? 'pass' : 'blocking',
		'detail' => !empty($context['https'])
			? __('WordPress recognises this request as HTTPS.', 'wfc-cart')
			: __('WordPress does not recognise HTTPS. Correct proxy and site URL configuration before checkout.', 'wfc-cart'),
	);

	$schema_ok = (string) ($context['schema_version'] ?? '') === (string) WFCC_SCHEMA_VERSION;
	$checks['schema'] = array(
		'label'  => __('WFC schema', 'wfc-cart'),
		'status' => $schema_ok ? 'pass' : 'blocking',
		'detail' => $schema_ok
			? sprintf(__('Schema %s is current.', 'wfc-cart'), WFCC_SCHEMA_VERSION)
			: __('The WFC option schema upgrade has not completed.', 'wfc-cart'),
	);

	$crm_mode = wfcc_sanitize_crm_mode($context['crm_mode'] ?? 'salesforce', 'salesforce');
	$checks['crm_mode'] = array(
		'label'  => __('CRM data location', 'wfc-cart'),
		'status' => 'pass',
		'detail' => 'salesforce' === $crm_mode
			? __('Salesforce is the configured CRM and receives donor data server-to-server.', 'wfc-cart')
			: __('WordPress mode retains donor PII only in the protected Gravity Forms cart entry.', 'wfc-cart'),
	);

	foreach (
		array(
			'gravity_forms' => array(__('Gravity Forms', 'wfc-cart'), __('Gravity Forms is available.', 'wfc-cart'), __('Gravity Forms is required for checkout.', 'wfc-cart')),
			'stripe'        => array(__('Stripe credentials', 'wfc-cart'), __('Stripe publishable and secret keys are configured.', 'wfc-cart'), __('Stripe publishable or secret key is missing.', 'wfc-cart')),
			'webhook'       => array(__('Stripe webhook', 'wfc-cart'), __('The webhook signing secret is configured.', 'wfc-cart'), __('The webhook signing secret is missing.', 'wfc-cart')),
			'packages'      => array(__('Checkout packages', 'wfc-cart'), __('At least one checkout package is configured.', 'wfc-cart'), __('No checkout package is configured.', 'wfc-cart')),
		) as $key => $messages
	) {
		$ready = !empty($context[$key]);
		$checks[$key] = array(
			'label'  => $messages[0],
			'status' => $ready ? 'pass' : 'blocking',
			'detail' => $ready ? $messages[1] : $messages[2],
		);
	}

	$salesforce_required = 'salesforce' === $crm_mode;
	$salesforce_ready    = !$salesforce_required || !empty($context['salesforce']);
	$checks['salesforce'] = array(
		'label'  => __('Salesforce delivery', 'wfc-cart'),
		'status' => $salesforce_ready ? 'pass' : 'blocking',
		'detail' => !$salesforce_required
			? __('Not required in WordPress CRM mode.', 'wfc-cart')
			: ($salesforce_ready
				? __('OAuth and the fixed Apex endpoint are configured.', 'wfc-cart')
				: __('Salesforce OAuth or endpoint configuration is incomplete.', 'wfc-cart')),
	);

	$recurring_ready = $salesforce_required || empty($context['wordpress_recurring_packages']);
	$checks['recurring_ownership'] = array(
		'label'  => __('Recurring-payment ownership', 'wfc-cart'),
		'status' => $recurring_ready ? 'pass' : 'blocking',
		'detail' => $recurring_ready
			? ($salesforce_required
				? __('Salesforce owns subsequent recurring-payment orchestration.', 'wfc-cart')
				: __('WordPress CRM mode is configured for one-off packages only.', 'wfc-cart'))
			: __('WordPress CRM mode cannot enable recurring or SetupIntent packages because no downstream recurring-payment owner is configured.', 'wfc-cart'),
	);

	$cron_ready = $salesforce_required
		? (!empty($context['queue_scheduled']) && empty($context['cron_disabled']))
		: empty($context['queue_scheduled']);
	$checks['scheduled_processing'] = array(
		'label'  => __('CRM delivery scheduling', 'wfc-cart'),
		'status' => $cron_ready ? 'pass' : 'warning',
		'detail' => $salesforce_required
			? ($cron_ready
				? __('The Salesforce delivery queue has a WP-Cron schedule.', 'wfc-cart')
				: __('WP-Cron is disabled or the Salesforce queue schedule is missing; verify an external cron runner.', 'wfc-cart'))
			: ($cron_ready
				? __('No Salesforce delivery schedule is active in WordPress CRM mode.', 'wfc-cart')
				: __('A Salesforce delivery schedule remains active even though WordPress CRM mode is selected.', 'wfc-cart')),
	);

	$receipt_ready = empty($context['receipt_email_enabled']) || !empty($context['receipt_email_field']);
	$checks['receipt_email'] = array(
		'label'  => __('Receipt email', 'wfc-cart'),
		'status' => $receipt_ready ? 'pass' : 'warning',
		'detail' => $receipt_ready
			? __('Receipt email is disabled or has a configured Gravity Forms field.', 'wfc-cart')
			: __('Automatic receipt email is enabled without a valid Gravity Forms field.', 'wfc-cart'),
	);

	$proxy_ready = empty($context['forwarded_header_present']) || !empty($context['trusted_proxies']);
	$checks['trusted_proxy'] = array(
		'label'  => __('Proxy rate limiting', 'wfc-cart'),
		'status' => $proxy_ready ? 'pass' : 'warning',
		'detail' => $proxy_ready
			? __('Forwarded addresses are absent or restricted to approved proxy CIDRs.', 'wfc-cart')
			: __('A forwarding header is present but no trusted proxy CIDR is configured.', 'wfc-cart'),
	);

	$checks['rest_boundaries'] = array(
		'label'  => __('REST cache and size boundaries', 'wfc-cart'),
		'status' => !empty($context['rest_hardened']) ? 'pass' : 'blocking',
		'detail' => !empty($context['rest_hardened'])
			? __('WFC REST responses are non-cacheable and request bodies are bounded.', 'wfc-cart')
			: __('WFC REST response hardening is unavailable.', 'wfc-cart'),
	);

	$checks['multisite'] = array(
		'label'  => __('Site lifecycle', 'wfc-cart'),
		'status' => 'pass',
		'detail' => !empty($context['multisite'])
			? __('Multisite-aware activation, deactivation, and new-site initialisation are active.', 'wfc-cart')
			: __('Single-site lifecycle is active.', 'wfc-cart'),
	);

	return $checks;
}

/**
 * Gather the current runtime context without exposing secrets.
 *
 * @return array<string, mixed>
 */
function wfcc_get_readiness_context() {
	$trusted_proxies = wfcc_get_setting('trusted_proxy_cidrs', array());
	$remote_address  = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
	$trusted_proxy_active = is_array($trusted_proxies)
		&& wfcc_ip_is_trusted_proxy($remote_address, $trusted_proxies);

	return array(
		'php_version'              => PHP_VERSION,
		'wp_version'               => get_bloginfo('version'),
		'https'                    => is_ssl(),
		'schema_version'           => (string) get_option('wfcc_schema_version', '0'),
		'gravity_forms'            => class_exists('GFForms'),
		'stripe'                   => (bool) (wfcc_get_stripe_publishable_key() && wfcc_get_secret('stripe_secret_key', 'WFCC_STRIPE_SECRET_KEY', 'WFCC_STRIPE_SECRET_KEY')),
		'webhook'                  => (bool) wfcc_get_secret('stripe_webhook_secret', 'WFCC_STRIPE_WEBHOOK_SECRET', 'WFCC_STRIPE_WEBHOOK_SECRET'),
		'crm_mode'                 => wfcc_get_crm_mode(),
		'salesforce'               => (bool) (wfcc_get_salesforce_login_url() && wfcc_get_salesforce_client_id() && wfcc_get_salesforce_client_secret() && wfcc_get_salesforce_api_path()),
		'packages'                 => (bool) wfcc_get_checkout_packages(),
		'wordpress_recurring_packages' => 'wordpress' === wfcc_get_crm_mode() && wfcc_wordpress_crm_has_recurring_packages(),
		'queue_scheduled'          => (bool) wp_next_scheduled('wfcc_process_delivery_queue'),
		'cron_disabled'            => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
		'receipt_email_enabled'    => (bool) wfcc_get_setting('receipt_email_enabled', false),
		'receipt_email_field'      => (bool) wfcc_sanitize_gf_entry_key(wfcc_get_setting('receipt_email_field_id', '')),
		'forwarded_header_present' => !empty($_SERVER['HTTP_X_FORWARDED_FOR']),
		'trusted_proxies'          => $trusted_proxy_active,
		'rest_hardened'            => function_exists('wfcc_harden_rest_response_headers') && function_exists('wfcc_request_body_is_bounded'),
		'multisite'                => is_multisite(),
	);
}

/**
 * Summarise readiness states.
 *
 * @param array<string, array{status:string}> $checks Checks.
 * @return array{status:string,pass:int,warning:int,blocking:int}
 */
function wfcc_summarize_readiness($checks) {
	$summary = array('status' => 'ready', 'pass' => 0, 'warning' => 0, 'blocking' => 0);
	foreach ($checks as $check) {
		$status = isset($check['status']) && in_array($check['status'], array('pass', 'warning', 'blocking'), true)
			? $check['status']
			: 'blocking';
		++$summary[$status];
	}
	$summary['status'] = $summary['blocking'] > 0
		? 'blocked'
		: ($summary['warning'] > 0 ? 'warning' : 'ready');

	return $summary;
}

/**
 * Store a safe readiness snapshot after a protected manual run.
 *
 * @return void
 */
function wfcc_handle_readiness_audit() {
	if (!current_user_can('wfcc_manage_settings')) {
		wp_die(esc_html__('You are not allowed to run the readiness audit.', 'wfc-cart'));
	}
	check_admin_referer('wfcc_run_readiness_audit');

	$checks  = wfcc_evaluate_readiness_context(wfcc_get_readiness_context());
	$summary = wfcc_summarize_readiness($checks);
	$states  = array();
	foreach ($checks as $key => $check) {
		$states[sanitize_key($key)] = sanitize_key($check['status']);
	}

	update_option(
		'wfcc_readiness_last_run',
		array(
			'checked_at' => gmdate('c'),
			'version'    => WFCC_VERSION,
			'schema'     => WFCC_SCHEMA_VERSION,
			'status'     => $summary['status'],
			'pass'       => $summary['pass'],
			'warning'    => $summary['warning'],
			'blocking'   => $summary['blocking'],
			'checks'     => $states,
		),
		false
	);

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'              => 'wfcc-readiness',
				'wfcc_audit_result' => $summary['status'],
			),
			admin_url('admin.php')
		)
	);
	exit;
}
