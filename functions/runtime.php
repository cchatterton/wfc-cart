<?php
/**
 * Runtime, proxy, and request-boundary hardening.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Return whether an IP address is inside an exact address or CIDR.
 *
 * @param string $ip   Candidate IP.
 * @param string $cidr Exact IP or CIDR.
 * @return bool
 */
function wfcc_ip_in_cidr($ip, $cidr) {
	$ip = trim((string) $ip);
	$cidr = trim((string) $cidr);
	if (false === filter_var($ip, FILTER_VALIDATE_IP) || '' === $cidr) {
		return false;
	}

	$parts   = explode('/', $cidr, 2);
	$network = $parts[0];
	if (false === filter_var($network, FILTER_VALIDATE_IP)) {
		return false;
	}

	$ip_binary      = inet_pton($ip);
	$network_binary = inet_pton($network);
	if (false === $ip_binary || false === $network_binary || strlen($ip_binary) !== strlen($network_binary)) {
		return false;
	}

	$maximum_bits = strlen($ip_binary) * 8;
	$prefix       = 2 === count($parts) ? filter_var($parts[1], FILTER_VALIDATE_INT) : $maximum_bits;
	if (false === $prefix || $prefix < 0 || $prefix > $maximum_bits) {
		return false;
	}

	$full_bytes = intdiv($prefix, 8);
	if ($full_bytes > 0 && substr($ip_binary, 0, $full_bytes) !== substr($network_binary, 0, $full_bytes)) {
		return false;
	}

	$remaining_bits = $prefix % 8;
	if (0 === $remaining_bits) {
		return true;
	}

	$mask = (0xff << (8 - $remaining_bits)) & 0xff;

	return (ord($ip_binary[$full_bytes]) & $mask) === (ord($network_binary[$full_bytes]) & $mask);
}

/**
 * Sanitise a newline/comma-delimited trusted proxy list.
 *
 * @param mixed $value Proxy list.
 * @return string[]
 */
function wfcc_sanitize_trusted_proxy_cidrs($value) {
	$values = is_array($value) ? $value : preg_split('/[\r\n,]+/', (string) $value);
	$output = array();

	foreach (is_array($values) ? $values : array() as $candidate) {
		$candidate = trim((string) $candidate);
		if ('' === $candidate || strlen($candidate) > 64) {
			continue;
		}

		$parts   = explode('/', $candidate, 2);
		$address = $parts[0];
		if (false === filter_var($address, FILTER_VALIDATE_IP)) {
			continue;
		}

		$is_ipv4 = false !== filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
		$maximum = $is_ipv4 ? 32 : 128;
		$minimum = $is_ipv4 ? 8 : 16;
		$prefix  = 2 === count($parts) ? filter_var($parts[1], FILTER_VALIDATE_INT) : $maximum;
		if (false === $prefix || $prefix < $minimum || $prefix > $maximum) {
			continue;
		}

		$normalised = strtolower((string) inet_ntop((string) inet_pton($address))) . '/' . $prefix;
		$output[$normalised] = $normalised;
		if (count($output) >= 50) {
			break;
		}
	}

	return array_values($output);
}

/**
 * Return whether an address belongs to any trusted proxy network.
 *
 * @param string   $ip            IP address.
 * @param string[] $trusted_cidrs Trusted networks.
 * @return bool
 */
function wfcc_ip_is_trusted_proxy($ip, $trusted_cidrs) {
	foreach ($trusted_cidrs as $cidr) {
		if (wfcc_ip_in_cidr($ip, $cidr)) {
			return true;
		}
	}

	return false;
}

/**
 * Resolve the request address, trusting forwarding headers only from approved
 * proxy addresses.
 *
 * @param array<string, mixed>|null $server        Server values.
 * @param string[]|null             $trusted_cidrs Trusted proxies.
 * @return string
 */
function wfcc_resolve_request_ip($server = null, $trusted_cidrs = null) {
	$server = is_array($server) ? $server : $_SERVER;
	$remote = isset($server['REMOTE_ADDR']) ? trim((string) $server['REMOTE_ADDR']) : '';
	if (false === filter_var($remote, FILTER_VALIDATE_IP)) {
		return 'unknown';
	}

	$trusted_cidrs = is_array($trusted_cidrs)
		? $trusted_cidrs
		: wfcc_get_setting('trusted_proxy_cidrs', array());
	if (!wfcc_ip_is_trusted_proxy($remote, $trusted_cidrs) || empty($server['HTTP_X_FORWARDED_FOR'])) {
		return $remote;
	}

	$forwarded = array_slice(explode(',', (string) $server['HTTP_X_FORWARDED_FOR']), 0, 20);
	$chain = array();
	foreach ($forwarded as $candidate) {
		$candidate = trim($candidate);
		if (false !== filter_var($candidate, FILTER_VALIDATE_IP)) {
			$chain[] = $candidate;
		}
	}
	$chain[] = $remote;

	for ($index = count($chain) - 1; $index >= 0; --$index) {
		if (!wfcc_ip_is_trusted_proxy($chain[$index], $trusted_cidrs)) {
			return $chain[$index];
		}
	}

	return $remote;
}

/**
 * Return whether a request body is within its fixed byte limit.
 *
 * @param mixed $body      Raw body.
 * @param int   $max_bytes Maximum bytes.
 * @return bool
 */
function wfcc_request_body_is_bounded($body, $max_bytes) {
	return is_string($body) && strlen($body) <= max(0, absint($max_bytes));
}
