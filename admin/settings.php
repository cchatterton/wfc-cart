<?php
/**
 * WFC Cart settings registration and screen.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('admin_init', 'wfcc_register_settings');

/**
 * Register the versioned WFC Cart settings array.
 *
 * @return void
 */
function wfcc_register_settings() {
	register_setting(
		'wfcc_settings_group',
		'wfcc_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'wfcc_sanitize_settings',
			'default'           => array(),
		)
	);
}

/**
 * Sanitise settings using a fixed allow-list.
 *
 * @param mixed $input Submitted settings.
 * @return array<string, mixed>
 */
function wfcc_sanitize_settings($input) {
	$existing = wfcc_get_settings();
	if (!is_array($input)) {
		return $existing;
	}

	$output = $existing;

	if (isset($input['currency'])) {
		$currency = strtoupper(sanitize_key($input['currency']));
		if (3 === strlen($currency)) {
			$output['currency'] = $currency;
		}
	}

	foreach (array('stripe_publishable_key', 'salesforce_client_id') as $key) {
		if (isset($input[$key])) {
			$output[$key] = sanitize_text_field($input[$key]);
		}
	}

	if (isset($input['salesforce_login_url'])) {
		$url = wfcc_sanitize_salesforce_login_url($input['salesforce_login_url']);
		if ('' === $url) {
			add_settings_error('wfcc_settings', 'wfcc_invalid_salesforce_url', __('Salesforce login URL must be an HTTPS salesforce.com origin.', 'wfc-cart'), 'error');
		} else {
			$output['salesforce_login_url'] = $url;
		}
	}

	foreach (array('stripe_secret_key', 'stripe_webhook_secret', 'salesforce_client_secret') as $key) {
		if (isset($input[$key]) && '' !== trim((string) $input[$key])) {
			$output[$key] = sanitize_text_field($input[$key]);
		}
	}

	if (isset($input['salesforce_api_path'])) {
		$path = wfcc_sanitize_salesforce_api_path($input['salesforce_api_path']);
		if ('' !== $path) {
			$output['salesforce_api_path'] = $path;
		} else {
			add_settings_error('wfcc_settings', 'wfcc_invalid_salesforce_path', __('The Apex REST path must remain under /services/apexrest/wfc-cart/.', 'wfc-cart'), 'error');
		}
	}

	if (array_key_exists('salesforce_field_map_json', $input)) {
		$mapping = wfcc_sanitize_salesforce_field_map(wp_unslash($input['salesforce_field_map_json']));
		if (is_wp_error($mapping)) {
			add_settings_error('wfcc_settings', 'wfcc_invalid_salesforce_map', $mapping->get_error_message(), 'error');
		} else {
			$output['salesforce_field_map'] = $mapping;
		}
	}

	if (isset($input['salesforce_required_fields'])) {
		$output['salesforce_required_fields'] = wfcc_sanitize_salesforce_required_fields($input['salesforce_required_fields']);
	}

	if (isset($input['delivery_retry_limit'])) {
		$output['delivery_retry_limit'] = min(20, max(1, absint($input['delivery_retry_limit'])));
	}

	foreach (array('receipt_generation_enabled', 'receipt_email_enabled') as $key) {
		if (array_key_exists($key, $input)) {
			$output[$key] = !empty($input[$key]);
		}
	}

	if (isset($input['receipt_number_prefix'])) {
		$prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9-]/', '', (string) $input['receipt_number_prefix']), 0, 12));
		$output['receipt_number_prefix'] = $prefix ?: 'WFC';
	}
	if (isset($input['receipt_email_field_id'])) {
		$output['receipt_email_field_id'] = wfcc_sanitize_gf_entry_key($input['receipt_email_field_id']);
	}
	if (isset($input['receipt_email_subject'])) {
		$output['receipt_email_subject'] = substr(sanitize_text_field($input['receipt_email_subject']), 0, 200);
	}

	if (isset($input['approved_redirect_hosts'])) {
		$hosts = preg_split('/[\r\n,]+/', (string) $input['approved_redirect_hosts']);
		$hosts = array_filter(array_map('wfcc_sanitize_host', $hosts));
		$output['approved_redirect_hosts'] = array_values(array_unique($hosts));
	}

	if (isset($input['trusted_proxy_cidrs'])) {
		$output['trusted_proxy_cidrs'] = wfcc_sanitize_trusted_proxy_cidrs($input['trusted_proxy_cidrs']);
	}

	if (array_key_exists('checkout_packages_json', $input)) {
		$packages = wfcc_sanitize_checkout_packages(wp_unslash($input['checkout_packages_json']));
		if (is_wp_error($packages)) {
			add_settings_error('wfcc_settings', 'wfcc_invalid_packages', $packages->get_error_message(), 'error');
		} else {
			$output['checkout_packages'] = $packages;
		}
	}

	return $output;
}

/**
 * Sanitise a configured hostname.
 *
 * @param string $host Candidate hostname.
 * @return string
 */
function wfcc_sanitize_host($host) {
	$host = strtolower(trim(sanitize_text_field($host)));
	$host = preg_replace('/[^a-z0-9.-]/', '', $host);

	return trim((string) $host, '.');
}

/**
 * Render WFC Cart settings.
 *
 * @return void
 */
function wfcc_render_settings_page() {
	if (!current_user_can('wfcc_manage_settings')) {
		wp_die(esc_html__('You are not allowed to configure WFC Cart.', 'wfc-cart'));
	}

	$settings = wfcc_get_settings();
	$tabs     = array(
		'general'       => __('General', 'wfc-cart'),
		'gravity-forms' => __('Gravity Forms', 'wfc-cart'),
		'checkout'      => __('Checkout', 'wfc-cart'),
		'stripe'        => __('Stripe', 'wfc-cart'),
		'salesforce'    => __('Salesforce', 'wfc-cart'),
		'recurring'     => __('Recurring Gifts', 'wfc-cart'),
		'receipts'      => __('Receipts', 'wfc-cart'),
		'analytics'     => __('Analytics', 'wfc-cart'),
		'advanced'      => __('Advanced', 'wfc-cart'),
	);
	$active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
	if (!isset($tabs[$active_tab])) {
		$active_tab = 'general';
	}
	?>
	<div class="wrap wfcc-admin">
		<h1><?php echo esc_html__('WFC Cart Settings', 'wfc-cart'); ?></h1>
		<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__('Settings sections', 'wfc-cart'); ?>">
			<?php foreach ($tabs as $tab => $label) : ?>
				<a
					class="nav-tab <?php echo $active_tab === $tab ? 'nav-tab-active' : ''; ?>"
					href="<?php echo esc_url(add_query_arg(array('page' => 'wfcc-settings', 'tab' => $tab), admin_url('admin.php'))); ?>"
				><?php echo esc_html($label); ?></a>
			<?php endforeach; ?>
		</nav>
		<form method="post" action="options.php">
			<?php settings_fields('wfcc_settings_group'); ?>
			<?php wfcc_render_settings_fields($active_tab, $settings); ?>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Render fields for one settings section.
 *
 * @param string               $tab      Active tab.
 * @param array<string, mixed> $settings Current settings.
 * @return void
 */
function wfcc_render_settings_fields($tab, $settings) {
	echo '<table class="form-table" role="presentation">';

	switch ($tab) {
		case 'general':
			wfcc_settings_text_row('currency', __('Default currency', 'wfc-cart'), isset($settings['currency']) ? $settings['currency'] : 'AUD');
			break;
		case 'stripe':
			wfcc_settings_text_row('stripe_publishable_key', __('Publishable key', 'wfc-cart'), isset($settings['stripe_publishable_key']) ? $settings['stripe_publishable_key'] : '');
			wfcc_settings_secret_row('stripe_secret_key', __('Secret key', 'wfc-cart'), wfcc_get_secret('stripe_secret_key', 'WFCC_STRIPE_SECRET_KEY', 'WFCC_STRIPE_SECRET_KEY'));
			wfcc_settings_secret_row('stripe_webhook_secret', __('Webhook signing secret', 'wfc-cart'), wfcc_get_secret('stripe_webhook_secret', 'WFCC_STRIPE_WEBHOOK_SECRET', 'WFCC_STRIPE_WEBHOOK_SECRET'));
			echo '<tr><th scope="row">' . esc_html__('Webhook endpoint', 'wfc-cart') . '</th><td><code>' . esc_html(rest_url('wfc-cart/v1/stripe/webhook')) . '</code>';
			echo '<p class="description">' . esc_html__('Register this exact endpoint in Stripe and copy its signing secret above.', 'wfc-cart') . '</p></td></tr>';
			break;
		case 'salesforce':
			wfcc_settings_text_row('salesforce_login_url', __('Login URL', 'wfc-cart'), isset($settings['salesforce_login_url']) ? $settings['salesforce_login_url'] : 'https://login.salesforce.com');
			wfcc_settings_text_row('salesforce_client_id', __('External Client App ID', 'wfc-cart'), isset($settings['salesforce_client_id']) ? $settings['salesforce_client_id'] : '');
			wfcc_settings_secret_row('salesforce_client_secret', __('External Client App secret', 'wfc-cart'), wfcc_get_secret('salesforce_client_secret', 'WFCC_SALESFORCE_CLIENT_SECRET', 'WFCC_SALESFORCE_CLIENT_SECRET'));
			wfcc_settings_text_row('salesforce_api_path', __('Apex REST path', 'wfc-cart'), isset($settings['salesforce_api_path']) ? $settings['salesforce_api_path'] : '/services/apexrest/wfc-cart/v1/transactions');
			$mapping = isset($settings['salesforce_field_map']) && is_array($settings['salesforce_field_map'])
				? wp_json_encode($settings['salesforce_field_map'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
				: '{}';
			echo '<tr><th scope="row"><label for="wfcc-salesforce-field-map">' . esc_html__('Gravity Forms field mapping', 'wfc-cart') . '</label></th>';
			echo '<td><textarea class="large-text code" rows="22" id="wfcc-salesforce-field-map" name="wfcc_settings[salesforce_field_map_json]">' . esc_textarea($mapping) . '</textarea>';
			echo '<p class="description">' . esc_html__('JSON keyed only by the fixed WFC payload fields. Rules may read a Gravity Forms field or use a constant; Salesforce object and field API names are not accepted here.', 'wfc-cart') . '</p></td></tr>';
			$required = isset($settings['salesforce_required_fields']) && is_array($settings['salesforce_required_fields'])
				? implode(', ', $settings['salesforce_required_fields'])
				: 'email, last_name';
			wfcc_settings_text_row('salesforce_required_fields', __('Required mapped fields', 'wfc-cart'), $required);
			$diagnostic = wfcc_get_salesforce_connection_diagnostic();
			$detail = empty($diagnostic)
				? __('Not tested yet.', 'wfc-cart')
				: sprintf(
					__('Last test: %1$s at %2$s%3$s', 'wfc-cart'),
					'ok' === ($diagnostic['status'] ?? '') ? __('successful', 'wfc-cart') : __('failed', 'wfc-cart'),
					(string) ($diagnostic['checked_at'] ?? ''),
					empty($diagnostic['category']) ? '' : ' (' . sanitize_key($diagnostic['category']) . ')'
				);
			$test_url = wp_nonce_url(
				add_query_arg('action', 'wfcc_test_salesforce_connection', admin_url('admin-post.php')),
				'wfcc_test_salesforce_connection'
			);
			echo '<tr><th scope="row">' . esc_html__('Connection test', 'wfc-cart') . '</th><td>';
			echo '<a class="button button-secondary" href="' . esc_url($test_url) . '">' . esc_html__('Test saved connection', 'wfc-cart') . '</a>';
			echo '<p class="description">' . esc_html($detail) . '</p></td></tr>';
			break;
		case 'checkout':
			$hosts = isset($settings['approved_redirect_hosts']) && is_array($settings['approved_redirect_hosts'])
				? implode("\n", $settings['approved_redirect_hosts'])
				: '';
			echo '<tr><th scope="row"><label for="wfcc-approved-redirect-hosts">' . esc_html__('Approved external redirect hosts', 'wfc-cart') . '</label></th>';
			echo '<td><textarea class="large-text code" rows="6" id="wfcc-approved-redirect-hosts" name="wfcc_settings[approved_redirect_hosts]">' . esc_textarea($hosts) . '</textarea>';
			echo '<p class="description">' . esc_html__('One hostname per line. This site is always approved.', 'wfc-cart') . '</p></td></tr>';
			$packages = isset($settings['checkout_packages']) && is_array($settings['checkout_packages'])
				? wp_json_encode($settings['checkout_packages'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
				: '{}';
			echo '<tr><th scope="row"><label for="wfcc-checkout-packages">' . esc_html__('Checkout packages', 'wfc-cart') . '</label></th>';
			echo '<td><textarea class="large-text code" rows="24" id="wfcc-checkout-packages" name="wfcc_settings[checkout_packages_json]">' . esc_textarea($packages) . '</textarea>';
			echo '<p class="description">' . esc_html__('JSON object keyed by opaque package ID. Monetary amounts use Stripe minor units (5000 = AUD 50.00). Each checkout form must name its default package.', 'wfc-cart') . '</p></td></tr>';
			break;
		case 'receipts':
			wfcc_settings_checkbox_row(
				'receipt_generation_enabled',
				__('Generate receipt records', 'wfc-cart'),
				!array_key_exists('receipt_generation_enabled', $settings) || !empty($settings['receipt_generation_enabled']),
				__('Create one deterministic receipt number for each successful payment.', 'wfc-cart')
			);
			wfcc_settings_checkbox_row(
				'receipt_email_enabled',
				__('Email receipts automatically', 'wfc-cart'),
				!empty($settings['receipt_email_enabled']),
				__('Email after the Gravity Forms entry and successful transaction are linked.', 'wfc-cart')
			);
			wfcc_settings_text_row('receipt_number_prefix', __('Receipt number prefix', 'wfc-cart'), $settings['receipt_number_prefix'] ?? 'WFC');
			wfcc_settings_text_row('receipt_email_field_id', __('Gravity Forms email field ID', 'wfc-cart'), $settings['receipt_email_field_id'] ?? '');
			wfcc_settings_text_row('receipt_email_subject', __('Email subject', 'wfc-cart'), $settings['receipt_email_subject'] ?? __('Your contribution receipt {receipt_number}', 'wfc-cart'));
			echo '<tr><th scope="row">' . esc_html__('Privacy', 'wfc-cart') . '</th><td><p class="description">';
			echo esc_html__('Receipt email addresses are read from the protected Gravity Forms entry when sending and are not copied into transaction metadata.', 'wfc-cart');
			echo '</p></td></tr>';
			break;
		case 'advanced':
			wfcc_settings_text_row('delivery_retry_limit', __('Delivery retry limit', 'wfc-cart'), isset($settings['delivery_retry_limit']) ? $settings['delivery_retry_limit'] : 8, 'number');
			$trusted_proxies = isset($settings['trusted_proxy_cidrs']) && is_array($settings['trusted_proxy_cidrs'])
				? implode("\n", $settings['trusted_proxy_cidrs'])
				: '';
			echo '<tr><th scope="row"><label for="wfcc-trusted-proxies">' . esc_html__('Trusted proxy CIDRs', 'wfc-cart') . '</label></th>';
			echo '<td><textarea class="large-text code" rows="6" id="wfcc-trusted-proxies" name="wfcc_settings[trusted_proxy_cidrs]">' . esc_textarea($trusted_proxies) . '</textarea>';
			echo '<p class="description">' . esc_html__('One exact proxy IP or CIDR per line. Forwarded client addresses are ignored unless REMOTE_ADDR matches this list.', 'wfc-cart') . '</p></td></tr>';
			break;
		default:
			echo '<tr><th scope="row">' . esc_html($GLOBALS['title']) . '</th><td>';
			echo esc_html__('This section is reserved for its phase-specific WFC Cart configuration.', 'wfc-cart');
			echo '</td></tr>';
			break;
	}

	echo '</table>';
}

/**
 * Render a text setting row.
 *
 * @param string $key   Key.
 * @param string $label Label.
 * @param mixed  $value Value.
 * @param string $type  Input type.
 * @return void
 */
function wfcc_settings_text_row($key, $label, $value, $type = 'text') {
	printf(
		'<tr><th scope="row"><label for="wfcc-%1$s">%2$s</label></th><td><input class="regular-text" type="%3$s" id="wfcc-%1$s" name="wfcc_settings[%1$s]" value="%4$s"></td></tr>',
		esc_attr($key),
		esc_html($label),
		esc_attr($type),
		esc_attr((string) $value)
	);
}

/**
 * Render a secret field that leaves the stored value unchanged when blank.
 *
 * @param string $key    Key.
 * @param string $label  Label.
 * @param string $secret Current resolved secret.
 * @return void
 */
function wfcc_settings_secret_row($key, $label, $secret) {
	printf(
		'<tr><th scope="row"><label for="wfcc-%1$s">%2$s</label></th><td><input class="regular-text" type="password" autocomplete="new-password" id="wfcc-%1$s" name="wfcc_settings[%1$s]" value=""><p class="description">%3$s</p></td></tr>',
		esc_attr($key),
		esc_html($label),
		'' === $secret
			? esc_html__('Not configured.', 'wfc-cart')
			: esc_html(sprintf(__('Configured: %s. Leave blank to keep it.', 'wfc-cart'), wfcc_mask_secret($secret)))
	);
}

/**
 * Render a checkbox setting row with an explicit unchecked value.
 *
 * @param string $key         Key.
 * @param string $label       Label.
 * @param bool   $checked     Checked state.
 * @param string $description Description.
 * @return void
 */
function wfcc_settings_checkbox_row($key, $label, $checked, $description = '') {
	printf(
		'<tr><th scope="row">%1$s</th><td><input type="hidden" name="wfcc_settings[%2$s]" value="0"><label><input type="checkbox" name="wfcc_settings[%2$s]" value="1" %3$s> %4$s</label>%5$s</td></tr>',
		esc_html($label),
		esc_attr($key),
		checked($checked, true, false),
		esc_html__('Enabled', 'wfc-cart'),
		'' === $description ? '' : '<p class="description">' . esc_html($description) . '</p>'
	);
}
