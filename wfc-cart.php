<?php
/**
 * Plugin Name: WFC Cart
 * Plugin URI: https://github.com/cchatterton/wfc-cart/releases/latest
 * Description: Gravity Forms donation, cart, Stripe payment and Salesforce transaction orchestration.
 * Version: 0.4.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: AlphaSys
 * Update URI: https://github.com/cchatterton/wfc-cart
 * Text Domain: wfc-cart
 */

if (!defined('ABSPATH')) {
	exit;
}

define('WFCC_VERSION', '0.4.0');
define('WFCC_SCHEMA_VERSION', '4');
define('WFCC_PLUGIN_FILE', __FILE__);
define('WFCC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WFCC_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WFCC_PLUGIN_DIR . 'functions/helpers.php';
require_once WFCC_PLUGIN_DIR . 'functions/idempotency.php';
require_once WFCC_PLUGIN_DIR . 'functions/capabilities.php';
require_once WFCC_PLUGIN_DIR . 'functions/setup.php';
require_once WFCC_PLUGIN_DIR . 'functions/data-model.php';
require_once WFCC_PLUGIN_DIR . 'functions/dependencies.php';
require_once WFCC_PLUGIN_DIR . 'functions/assets.php';
require_once WFCC_PLUGIN_DIR . 'functions/github-updater.php';
require_once WFCC_PLUGIN_DIR . 'checkout/packages.php';
require_once WFCC_PLUGIN_DIR . 'checkout/transactions.php';
require_once WFCC_PLUGIN_DIR . 'stripe/client.php';
require_once WFCC_PLUGIN_DIR . 'stripe/intents.php';
require_once WFCC_PLUGIN_DIR . 'stripe/webhooks.php';
require_once WFCC_PLUGIN_DIR . 'gravity-forms/forms.php';
require_once WFCC_PLUGIN_DIR . 'gravity-forms/checkout.php';
require_once WFCC_PLUGIN_DIR . 'rest/routes.php';
require_once WFCC_PLUGIN_DIR . 'admin/settings.php';
require_once WFCC_PLUGIN_DIR . 'admin/menu.php';
require_once WFCC_PLUGIN_DIR . 'admin/health.php';
require_once WFCC_PLUGIN_DIR . 'admin/delivery-queue.php';

register_activation_hook(WFCC_PLUGIN_FILE, 'wfcc_activate');
register_deactivation_hook(WFCC_PLUGIN_FILE, 'wfcc_deactivate');
