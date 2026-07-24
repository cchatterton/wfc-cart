<?php
/**
 * WFC Cart intentionally preserves data on uninstall.
 *
 * Transaction history, Gravity Forms entries, settings, and external Stripe
 * or Salesforce data require a separately documented and explicitly
 * authorised removal process.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}
