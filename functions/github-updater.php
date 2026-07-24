<?php
/**
 * WordPress-native updater backed by immutable GitHub releases.
 */

if (!defined('ABSPATH')) {
	exit;
}

define('WFCC_GITHUB_RELEASE_TRANSIENT', 'wfcc_github_latest_release');
define('WFCC_GITHUB_ERROR_TRANSIENT', 'wfcc_github_latest_release_error');

add_filter('pre_set_site_transient_update_plugins', 'wfcc_add_update_data');
add_filter('site_transient_update_plugins', 'wfcc_add_update_data');
add_filter('plugins_api', 'wfcc_plugin_information', 10, 3);
add_filter('plugin_row_meta', 'wfcc_plugin_row_meta', 10, 2);
add_action('admin_init', 'wfcc_handle_manual_update_check');
add_action('upgrader_process_complete', 'wfcc_clear_release_cache_after_upgrade', 10, 2);

/**
 * Return the configured public GitHub owner.
 *
 * @return string
 */
function wfcc_github_owner() {
	return defined('WFCC_GITHUB_OWNER') ? sanitize_key(WFCC_GITHUB_OWNER) : 'cchatterton';
}

/**
 * Return the configured GitHub repository.
 *
 * @return string
 */
function wfcc_github_repository() {
	return defined('WFCC_GITHUB_REPOSITORY') ? sanitize_key(WFCC_GITHUB_REPOSITORY) : 'wfc-cart';
}

/**
 * Detect a privileged forced update check.
 *
 * @return bool
 */
function wfcc_is_forced_update_check() {
	if (!is_admin() || !current_user_can('update_plugins')) {
		return false;
	}

	$request = array_merge($_GET, $_POST);
	if (isset($request['force-check'])) {
		return true;
	}

	$action = isset($request['action']) ? sanitize_key(wp_unslash($request['action'])) : '';

	return in_array($action, array('update-selected', 'upgrade-plugin', 'do-plugin-upgrade'), true);
}

/**
 * Fetch and cache a valid release response.
 *
 * @param bool $force Force a remote lookup.
 * @return array<string, mixed>|false
 */
function wfcc_get_latest_release($force = false) {
	if ($force || wfcc_is_forced_update_check()) {
		delete_site_transient(WFCC_GITHUB_RELEASE_TRANSIENT);
		delete_site_transient(WFCC_GITHUB_ERROR_TRANSIENT);
	}

	$cached = get_site_transient(WFCC_GITHUB_RELEASE_TRANSIENT);
	if (is_array($cached) && !empty($cached['version']) && !empty($cached['package'])) {
		return $cached;
	}

	if (!$force && !wfcc_is_forced_update_check() && get_site_transient(WFCC_GITHUB_ERROR_TRANSIENT)) {
		return false;
	}

	$owner      = wfcc_github_owner();
	$repository = wfcc_github_repository();
	$response   = wp_remote_get(
		sprintf('https://api.github.com/repos/%s/%s/releases/latest', rawurlencode($owner), rawurlencode($repository)),
		array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'WFC-Cart/' . WFCC_VERSION,
			),
		)
	);

	if (is_wp_error($response)) {
		wfcc_store_update_error('wp_error', 0, $response->get_error_message(), '');
		return wfcc_get_latest_release_fallback();
	}

	$response_code = wp_remote_retrieve_response_code($response);
	if (200 !== $response_code) {
		wfcc_store_update_error(
			'http_error',
			$response_code,
			wp_remote_retrieve_response_message($response),
			substr(wp_remote_retrieve_body($response), 0, 500)
		);
		return wfcc_get_latest_release_fallback();
	}

	$body = json_decode(wp_remote_retrieve_body($response), true);
	if (!is_array($body)) {
		wfcc_store_update_error('json_error', $response_code, 'Invalid GitHub release JSON.', '');
		return false;
	}

	$release = wfcc_normalize_github_release($body);
	if (!$release) {
		wfcc_store_update_error('release_error', $response_code, 'Release tag or expected ZIP asset is missing.', '');
		return false;
	}

	wfcc_cache_release($release);

	return $release;
}

/**
 * Normalize GitHub API data into the updater's stable release shape.
 *
 * @param array<string, mixed> $body GitHub release response.
 * @return array<string, mixed>|false
 */
function wfcc_normalize_github_release($body) {
	$version = isset($body['tag_name']) ? ltrim(sanitize_text_field($body['tag_name']), 'vV') : '';
	if ('' === $version || !preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
		return false;
	}

	$package = '';
	if (!empty($body['assets']) && is_array($body['assets'])) {
		foreach ($body['assets'] as $asset) {
			if (
				is_array($asset)
				&& 'wfc-cart.zip' === (isset($asset['name']) ? $asset['name'] : '')
				&& !empty($asset['browser_download_url'])
			) {
				$package = esc_url_raw($asset['browser_download_url']);
				break;
			}
		}
	}

	if ('' === $package) {
		return false;
	}

	return array(
		'version'      => $version,
		'package'      => $package,
		'release_url'  => isset($body['html_url']) ? esc_url_raw($body['html_url']) : '',
		'release_body' => isset($body['body']) ? wp_kses_post($body['body']) : '',
		'published_at' => isset($body['published_at']) ? sanitize_text_field($body['published_at']) : '',
	);
}

/**
 * Fall back to GitHub's public latest-release redirect.
 *
 * @return array<string, mixed>|false
 */
function wfcc_get_latest_release_fallback() {
	$owner      = wfcc_github_owner();
	$repository = wfcc_github_repository();
	$latest_url = sprintf('https://github.com/%s/%s/releases/latest', rawurlencode($owner), rawurlencode($repository));
	$response   = wp_remote_get(
		$latest_url,
		array(
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array('User-Agent' => 'WFC-Cart/' . WFCC_VERSION),
		)
	);

	if (is_wp_error($response)) {
		return false;
	}

	$location = wp_remote_retrieve_header($response, 'location');
	if (!is_string($location) || !preg_match('~/releases/tag/([^/?#]+)~', $location, $matches)) {
		return false;
	}

	$tag     = rawurldecode($matches[1]);
	$version = ltrim(sanitize_text_field($tag), 'vV');
	if (!preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
		return false;
	}

	$package = sprintf(
		'https://github.com/%s/%s/releases/download/%s/wfc-cart.zip',
		rawurlencode($owner),
		rawurlencode($repository),
		rawurlencode($tag)
	);
	$asset_response = wp_remote_head(
		$package,
		array(
			'timeout'     => 10,
			'redirection' => 5,
			'headers'     => array('User-Agent' => 'WFC-Cart/' . WFCC_VERSION),
		)
	);
	if (is_wp_error($asset_response) || 200 !== wp_remote_retrieve_response_code($asset_response)) {
		return false;
	}

	$release = array(
		'version'      => $version,
		'package'      => esc_url_raw($package),
		'release_url'  => esc_url_raw($location),
		'release_body' => '',
		'published_at' => '',
	);
	wfcc_cache_release($release);

	return $release;
}

/**
 * Cache only valid release data using version-sensitive lifetimes.
 *
 * @param array<string, mixed> $release Release data.
 * @return void
 */
function wfcc_cache_release($release) {
	$expiration = version_compare($release['version'], WFCC_VERSION, '>')
		? 6 * HOUR_IN_SECONDS
		: 5 * MINUTE_IN_SECONDS;

	set_site_transient(WFCC_GITHUB_RELEASE_TRANSIENT, $release, $expiration);
	delete_site_transient(WFCC_GITHUB_ERROR_TRANSIENT);
}

/**
 * Store lookup diagnostics separately from release state.
 *
 * @param string $type    Error type.
 * @param int    $code    HTTP code.
 * @param string $message Error message.
 * @param string $body    Short response excerpt.
 * @return void
 */
function wfcc_store_update_error($type, $code, $message, $body) {
	delete_site_transient(WFCC_GITHUB_RELEASE_TRANSIENT);
	set_site_transient(
		WFCC_GITHUB_ERROR_TRANSIENT,
		array(
			'type'       => sanitize_key($type),
			'code'       => absint($code),
			'message'    => sanitize_text_field($message),
			'body'       => sanitize_textarea_field($body),
			'checked_at' => time(),
		),
		10 * MINUTE_IN_SECONDS
	);
}

/**
 * Inject or clear the native WordPress update response.
 *
 * @param mixed $transient Update transient.
 * @return mixed
 */
function wfcc_add_update_data($transient) {
	if (!is_object($transient)) {
		return $transient;
	}

	$plugin_file          = plugin_basename(WFCC_PLUGIN_FILE);
	$transient->response  = isset($transient->response) && is_array($transient->response) ? $transient->response : array();
	$transient->no_update = isset($transient->no_update) && is_array($transient->no_update) ? $transient->no_update : array();
	$release              = wfcc_get_latest_release();

	unset($transient->response[$plugin_file], $transient->no_update[$plugin_file]);
	if (!$release || !version_compare($release['version'], WFCC_VERSION, '>')) {
		return $transient;
	}

	$transient->response[$plugin_file] = (object) array(
		'id'           => 'https://github.com/' . wfcc_github_owner() . '/' . wfcc_github_repository(),
		'slug'         => 'wfc-cart',
		'plugin'       => $plugin_file,
		'new_version'  => $release['version'],
		'url'          => $release['release_url'],
		'package'      => $release['package'],
		'requires'     => '6.4',
		'requires_php' => '8.1',
	);

	return $transient;
}

/**
 * Supply the WordPress plugin details modal.
 *
 * @param mixed  $result Existing result.
 * @param string $action API action.
 * @param object $args   API arguments.
 * @return mixed
 */
function wfcc_plugin_information($result, $action, $args) {
	if ('plugin_information' !== $action || empty($args->slug) || 'wfc-cart' !== $args->slug) {
		return $result;
	}

	$release = wfcc_get_latest_release();
	if (!$release) {
		return $result;
	}

	return (object) array(
		'name'          => 'WFC Cart',
		'slug'          => 'wfc-cart',
		'version'       => $release['version'],
		'author'        => 'AlphaSys',
		'homepage'      => 'https://github.com/' . wfcc_github_owner() . '/' . wfcc_github_repository(),
		'download_link' => $release['package'],
		'requires'      => '6.4',
		'requires_php'  => '8.1',
		'last_updated'  => $release['published_at'],
		'sections'      => array(
			'description' => __('Gravity Forms donation, cart, Stripe payment and Salesforce transaction orchestration.', 'wfc-cart'),
			'changelog'   => $release['release_body'],
		),
	);
}

/**
 * Add GitHub and manual update links to the plugin row.
 *
 * @param string[] $links Plugin row metadata.
 * @param string   $file  Plugin file.
 * @return string[]
 */
function wfcc_plugin_row_meta($links, $file) {
	if (plugin_basename(WFCC_PLUGIN_FILE) !== $file || !current_user_can('update_plugins')) {
		return $links;
	}

	$plugins_url = is_multisite() ? network_admin_url('plugins.php') : admin_url('plugins.php');
	$check_url   = wp_nonce_url(add_query_arg('wfcc_check_updates', '1', $plugins_url), 'wfcc_check_updates');
	$links[]     = '<a href="' . esc_url('https://github.com/' . wfcc_github_owner() . '/' . wfcc_github_repository()) . '">' . esc_html__('GitHub', 'wfc-cart') . '</a>';
	$links[]     = '<a href="' . esc_url($check_url) . '">' . esc_html__('Check for updates', 'wfc-cart') . '</a>';

	return $links;
}

/**
 * Run a nonce-protected native WordPress update check.
 *
 * @return void
 */
function wfcc_handle_manual_update_check() {
	if (!isset($_GET['wfcc_check_updates'])) {
		return;
	}

	if (!current_user_can('update_plugins')) {
		wp_die(esc_html__('You are not allowed to check for plugin updates.', 'wfc-cart'));
	}

	check_admin_referer('wfcc_check_updates');
	delete_site_transient(WFCC_GITHUB_RELEASE_TRANSIENT);
	delete_site_transient(WFCC_GITHUB_ERROR_TRANSIENT);
	delete_site_transient('update_plugins');
	wp_update_plugins();

	$plugins_url = is_multisite() ? network_admin_url('plugins.php') : admin_url('plugins.php');
	wp_safe_redirect($plugins_url);
	exit;
}

/**
 * Clear caches after WFC Cart is updated successfully.
 *
 * @param object               $upgrader Upgrader instance.
 * @param array<string, mixed> $options  Upgrade options.
 * @return void
 */
function wfcc_clear_release_cache_after_upgrade($upgrader, $options) {
	unset($upgrader);
	if (
		empty($options['action'])
		|| 'update' !== $options['action']
		|| empty($options['type'])
		|| 'plugin' !== $options['type']
		|| empty($options['plugins'])
		|| !in_array(plugin_basename(WFCC_PLUGIN_FILE), (array) $options['plugins'], true)
	) {
		return;
	}

	delete_site_transient(WFCC_GITHUB_RELEASE_TRANSIENT);
	delete_site_transient(WFCC_GITHUB_ERROR_TRANSIENT);
}
