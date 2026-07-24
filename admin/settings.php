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

	foreach (array('stripe_publishable_key', 'salesforce_login_url', 'salesforce_client_id') as $key) {
		if (isset($input[$key])) {
			$output[$key] = sanitize_text_field($input[$key]);
		}
	}

	foreach (array('stripe_secret_key', 'stripe_webhook_secret', 'salesforce_client_secret') as $key) {
		if (isset($input[$key]) && '' !== trim((string) $input[$key])) {
			$output[$key] = sanitize_text_field($input[$key]);
		}
	}

	if (isset($input['salesforce_api_path'])) {
		$path = '/' . ltrim(sanitize_text_field($input['salesforce_api_path']), '/');
		if (0 === strpos($path, '/services/apexrest/')) {
			$output['salesforce_api_path'] = $path;
		}
	}

	if (isset($input['delivery_retry_limit'])) {
		$output['delivery_retry_limit'] = min(20, max(1, absint($input['delivery_retry_limit'])));
	}

	if (isset($input['approved_redirect_hosts'])) {
		$hosts = preg_split('/[\r\n,]+/', (string) $input['approved_redirect_hosts']);
		$hosts = array_filter(array_map('wfcc_sanitize_host', $hosts));
		$output['approved_redirect_hosts'] = array_values(array_unique($hosts));
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
			break;
		case 'salesforce':
			wfcc_settings_text_row('salesforce_login_url', __('Login URL', 'wfc-cart'), isset($settings['salesforce_login_url']) ? $settings['salesforce_login_url'] : 'https://login.salesforce.com');
			wfcc_settings_text_row('salesforce_client_id', __('External Client App ID', 'wfc-cart'), isset($settings['salesforce_client_id']) ? $settings['salesforce_client_id'] : '');
			wfcc_settings_secret_row('salesforce_client_secret', __('External Client App secret', 'wfc-cart'), wfcc_get_secret('salesforce_client_secret', 'WFCC_SALESFORCE_CLIENT_SECRET', 'WFCC_SALESFORCE_CLIENT_SECRET'));
			wfcc_settings_text_row('salesforce_api_path', __('Apex REST path', 'wfc-cart'), isset($settings['salesforce_api_path']) ? $settings['salesforce_api_path'] : '/services/apexrest/wfc-cart/v1/transactions');
			break;
		case 'checkout':
			$hosts = isset($settings['approved_redirect_hosts']) && is_array($settings['approved_redirect_hosts'])
				? implode("\n", $settings['approved_redirect_hosts'])
				: '';
			echo '<tr><th scope="row"><label for="wfcc-approved-redirect-hosts">' . esc_html__('Approved external redirect hosts', 'wfc-cart') . '</label></th>';
			echo '<td><textarea class="large-text code" rows="6" id="wfcc-approved-redirect-hosts" name="wfcc_settings[approved_redirect_hosts]">' . esc_textarea($hosts) . '</textarea>';
			echo '<p class="description">' . esc_html__('One hostname per line. This site is always approved.', 'wfc-cart') . '</p></td></tr>';
			break;
		case 'advanced':
			wfcc_settings_text_row('delivery_retry_limit', __('Delivery retry limit', 'wfc-cart'), isset($settings['delivery_retry_limit']) ? $settings['delivery_retry_limit'] : 8, 'number');
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
