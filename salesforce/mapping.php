<?php
/**
 * Controlled Gravity Forms to WFC Salesforce payload mapping.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Fixed mapping targets supported by the payload schema.
 *
 * @return string[]
 */
function wfcc_salesforce_mapping_targets() {
	return array(
		'first_name',
		'last_name',
		'email',
		'phone',
		'address_line1',
		'address_line2',
		'city',
		'state',
		'postcode',
		'country',
		'source',
		'medium',
		'attribution_campaign',
		'recurrence_start',
		'consent_evidence',
	);
}

/**
 * Sanitise a Gravity Forms entry key, including sub-inputs such as 1.3.
 *
 * @param mixed $value Candidate entry key.
 * @return string
 */
function wfcc_sanitize_gf_entry_key($value) {
	$value = trim((string) $value);

	return preg_match('/^\d+(?:\.\d+)?$/', $value) ? $value : '';
}

/**
 * Sanitise one field or constant mapping rule.
 *
 * @param mixed $candidate Rule.
 * @return array<string, mixed>|null
 */
function wfcc_sanitize_salesforce_mapping_rule($candidate) {
	if (!is_array($candidate)) {
		return null;
	}

	$source = isset($candidate['source']) && 'constant' === $candidate['source'] ? 'constant' : 'field';
	$rule   = array(
		'source'    => $source,
		'transform' => isset($candidate['transform']) ? sanitize_key($candidate['transform']) : 'text',
	);
	$transforms = array('text', 'email', 'phone', 'upper', 'lower', 'date', 'boolean');
	if (!in_array($rule['transform'], $transforms, true)) {
		$rule['transform'] = 'text';
	}

	if ('constant' === $source) {
		$rule['value'] = isset($candidate['value'])
			? substr(sanitize_text_field((string) $candidate['value']), 0, 500)
			: '';
	} else {
		$rule['field_id'] = wfcc_sanitize_gf_entry_key($candidate['field_id'] ?? '');
		if ('' === $rule['field_id']) {
			return null;
		}
	}

	if (!empty($candidate['when']) && is_array($candidate['when'])) {
		$field_id = wfcc_sanitize_gf_entry_key($candidate['when']['field_id'] ?? '');
		if ('' !== $field_id) {
			$rule['when'] = array(
				'field_id' => $field_id,
				'equals'   => substr(sanitize_text_field((string) ($candidate['when']['equals'] ?? '')), 0, 500),
			);
		}
	}

	return $rule;
}

/**
 * Sanitise field mapping JSON into fixed payload targets.
 *
 * @param mixed $json JSON string.
 * @return array<string, mixed>|WP_Error
 */
function wfcc_sanitize_salesforce_field_map($json) {
	if (!is_string($json) || '' === trim($json)) {
		return array();
	}

	$decoded = json_decode($json, true);
	if (!is_array($decoded)) {
		return new WP_Error('wfcc_invalid_salesforce_map', __('Salesforce field mapping must be valid JSON.', 'wfc-cart'));
	}

	$output = array();
	foreach (wfcc_salesforce_mapping_targets() as $target) {
		if (!isset($decoded[$target])) {
			continue;
		}
		$rule = wfcc_sanitize_salesforce_mapping_rule($decoded[$target]);
		if ($rule) {
			$output[$target] = $rule;
		}
	}

	$output['metadata'] = array();
	if (!empty($decoded['metadata']) && is_array($decoded['metadata'])) {
		foreach ($decoded['metadata'] as $key => $candidate) {
			$key = sanitize_key($key);
			if ('' === $key || strlen($key) > 50) {
				continue;
			}
			$rule = wfcc_sanitize_salesforce_mapping_rule($candidate);
			if ($rule) {
				$output['metadata'][$key] = $rule;
			}
		}
	}

	return $output;
}

/**
 * Return configured mapping.
 *
 * @return array<string, mixed>
 */
function wfcc_get_salesforce_field_map() {
	$mapping = wfcc_get_setting('salesforce_field_map', array());

	return is_array($mapping) ? $mapping : array();
}

/**
 * Sanitise required fixed payload fields.
 *
 * @param mixed $value Comma or line-delimited names.
 * @return string[]
 */
function wfcc_sanitize_salesforce_required_fields($value) {
	$values  = preg_split('/[\s,]+/', (string) $value);
	$allowed = wfcc_salesforce_mapping_targets();
	$values  = array_map('sanitize_key', is_array($values) ? $values : array());

	return array_values(array_unique(array_intersect($values, $allowed)));
}

/**
 * Transform one mapped value with an allow-listed transform.
 *
 * @param mixed  $value     Value.
 * @param string $transform Transform.
 * @return mixed
 */
function wfcc_transform_salesforce_value($value, $transform) {
	$value = is_scalar($value) ? (string) $value : '';
	switch ($transform) {
		case 'email':
			return sanitize_email($value);
		case 'phone':
			return preg_replace('/[^0-9+().\-\s]/', '', $value);
		case 'upper':
			return function_exists('mb_strtoupper') ? mb_strtoupper(sanitize_text_field($value)) : strtoupper(sanitize_text_field($value));
		case 'lower':
			return function_exists('mb_strtolower') ? mb_strtolower(sanitize_text_field($value)) : strtolower(sanitize_text_field($value));
		case 'date':
			$timestamp = strtotime($value);
			return false === $timestamp ? '' : gmdate('Y-m-d', $timestamp);
		case 'boolean':
			return in_array(strtolower(trim($value)), array('1', 'true', 'yes', 'on'), true);
		default:
			return sanitize_text_field($value);
	}
}

/**
 * Resolve one mapping rule from a Gravity Forms entry.
 *
 * @param array<string, mixed> $entry Entry.
 * @param array<string, mixed> $rule  Sanitised rule.
 * @return mixed
 */
function wfcc_resolve_salesforce_mapping_rule($entry, $rule) {
	if (!empty($rule['when'])) {
		$actual = isset($entry[$rule['when']['field_id']]) ? (string) $entry[$rule['when']['field_id']] : '';
		if (!hash_equals((string) $rule['when']['equals'], $actual)) {
			return '';
		}
	}

	$value = 'constant' === $rule['source']
		? ($rule['value'] ?? '')
		: ($entry[$rule['field_id']] ?? '');

	return wfcc_transform_salesforce_value($value, $rule['transform']);
}

/**
 * Resolve all configured fixed targets and metadata.
 *
 * @param array<string, mixed> $entry Gravity Forms entry.
 * @return array<string, mixed>
 */
function wfcc_resolve_salesforce_mapping($entry) {
	$mapping = wfcc_get_salesforce_field_map();
	$output  = array();
	foreach (wfcc_salesforce_mapping_targets() as $target) {
		$output[$target] = isset($mapping[$target])
			? wfcc_resolve_salesforce_mapping_rule($entry, $mapping[$target])
			: '';
	}

	$output['metadata'] = array();
	foreach (($mapping['metadata'] ?? array()) as $key => $rule) {
		$value = wfcc_resolve_salesforce_mapping_rule($entry, $rule);
		if ('' !== $value && null !== $value) {
			$output['metadata'][$key] = $value;
		}
	}

	return $output;
}
