<?php
/**
 * Regenerates testdata/protocol/vectors.json.
 *
 * Development tool only. It is not part of the agent and is not shipped.
 * Run it from the repo root after any change to the signing rules in
 * docs/protocol.md, then commit the new vectors.json alongside the doc.
 *
 *   php testdata/protocol/generate-vectors.php > testdata/protocol/vectors.json
 *
 * The keypair is the first test vector from RFC 8032 section 7.1, so the
 * derived public key can be checked against the RFC as well.
 */

declare(strict_types=1);

const PROTOCOL_VERSION = 1;

// RFC 8032 test vector 1 seed. Public key must come out as
// d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a.
const SEED_HEX       = '9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60';
const OTHER_SEED_HEX = '4ccd089b28ff96da9db6c346ec114e0f5b8a319f35aba624da8cf6ed4fb8a6fb';

const SITE_URL  = 'https://example.test';
const OTHER_URL = 'https://other.example.test';
const HTTP_URL  = 'http://example.test';
const NOW       = 1790000000;

function keypair( string $seed_hex ): array {
	$kp = sodium_crypto_sign_seed_keypair( hex2bin( $seed_hex ) );
	return array(
		'secret' => sodium_crypto_sign_secretkey( $kp ),
		'public' => sodium_crypto_sign_publickey( $kp ),
	);
}

function canonical( string $method, string $route, string $timestamp, string $nonce, string $site_url, string $body ): string {
	return implode( "\n", array( $method, $route, $timestamp, $nonce, $site_url, hash( 'sha256', $body ) ) );
}

/**
 * Build one case. $sign is what was actually signed; $request is what is
 * sent. They differ only in tamper cases.
 */
function make_case( array $keys, string $name, string $expect, string $reason, array $sign, ?array $request = null, array $extra = array() ): array {
	$sign    = array_merge(
		array(
			'method'    => 'GET',
			'route'     => '/sitemanager/v1/report',
			'timestamp' => (string) NOW,
			'nonce'     => '',
			'site_url'  => SITE_URL,
			'body'      => '',
		),
		$sign
	);
	$request = array_merge( $sign, $request ?? array() );

	$canonical = canonical( $sign['method'], $sign['route'], $sign['timestamp'], $sign['nonce'], $sign['site_url'], $sign['body'] );
	$signature = base64_encode( sodium_crypto_sign_detached( $canonical, $keys['secret'] ) );

	$headers = array( 'X-SM-Timestamp' => $request['timestamp'] );
	if ( '' !== $request['nonce'] ) {
		$headers['X-SM-Nonce'] = $request['nonce'];
	}
	$headers['X-SM-Signature'] = $signature;
	if ( 'POST' === $request['method'] ) {
		$headers['Content-Type'] = 'application/json';
	}
	foreach ( $extra['header_overrides'] ?? array() as $k => $v ) {
		if ( null === $v ) {
			unset( $headers[ $k ] );
		} else {
			$headers[ $k ] = $v;
		}
	}

	$case = array(
		'name'   => $name,
		'expect' => $expect,
		'reason' => $reason,
		'now'    => NOW,
		'site'   => array(
			'home_url'      => $request['site_url'],
			'allow_updates' => $extra['allow_updates'] ?? true,
			'used_nonces'   => $extra['used_nonces'] ?? array(),
		),
		'signed' => array(
			'method'      => $sign['method'],
			'route'       => $sign['route'],
			'timestamp'   => $sign['timestamp'],
			'nonce'       => $sign['nonce'],
			'site_url'    => $sign['site_url'],
			'body'        => $sign['body'],
			'body_sha256' => hash( 'sha256', $sign['body'] ),
			'canonical'   => $canonical,
			'signature'   => $signature,
		),
		'request' => array(
			'method'  => $request['method'],
			'route'   => $request['route'],
			'headers' => $headers,
			'body'    => $request['body'],
		),
	);
	if ( isset( $extra['signed_with'] ) ) {
		$case['signed']['signed_with'] = $extra['signed_with'];
	}
	return $case;
}

$main  = keypair( SEED_HEX );
$other = keypair( OTHER_SEED_HEX );

$update_body   = '{"type":"plugin","item":"wp-rocket/wp-rocket.php","expected_version":"3.18.1"}';
$rollback_body = '{"type":"plugin","item":"wp-rocket/wp-rocket.php","rollback_id":"5f1d0c9a7b3e2a41"}';
$nonce_a       = '9f2c1e4b7a6d3c2e1f0a9b8c7d6e5f4a';
$nonce_b       = '0123456789abcdef0123456789abcdef';

$cases = array();

// Accept cases.
$cases[] = make_case( $main, 'get_report_valid', 'accept', 'Well formed GET within the window.', array() );
$cases[] = make_case( $main, 'get_report_timestamp_59s_old', 'accept', 'Timestamp 59 seconds behind server time is inside the window.', array( 'timestamp' => (string) ( NOW - 59 ) ) );
$cases[] = make_case( $main, 'get_report_timestamp_60s_old', 'accept', 'Exactly 60 seconds behind is the inclusive boundary.', array( 'timestamp' => (string) ( NOW - 60 ) ) );
$cases[] = make_case( $main, 'get_report_timestamp_60s_ahead', 'accept', 'Exactly 60 seconds ahead of server time is the inclusive boundary.', array( 'timestamp' => (string) ( NOW + 60 ) ) );
$cases[] = make_case( $main, 'post_update_valid', 'accept', 'Well formed POST with nonce and body hash.', array( 'method' => 'POST', 'route' => '/sitemanager/v1/update', 'nonce' => $nonce_a, 'body' => $update_body ) );

// Reject: timestamp.
$cases[] = make_case( $main, 'get_report_timestamp_61s_old', 'reject', 'timestamp_out_of_window: 61 seconds behind server time.', array( 'timestamp' => (string) ( NOW - 61 ) ) );
$cases[] = make_case( $main, 'get_report_timestamp_61s_ahead', 'reject', 'timestamp_out_of_window: 61 seconds ahead of server time.', array( 'timestamp' => (string) ( NOW + 61 ) ) );
$cases[] = make_case( $main, 'get_report_timestamp_missing', 'reject', 'missing_header: X-SM-Timestamp absent.', array(), null, array( 'header_overrides' => array( 'X-SM-Timestamp' => null ) ) );
$cases[] = make_case( $main, 'get_report_timestamp_not_integer', 'reject', 'bad_timestamp: not a decimal integer.', array( 'timestamp' => '1790000000.5' ) );
$cases[] = make_case( $main, 'get_report_timestamp_leading_zero', 'reject', 'bad_timestamp: leading zero is not canonical.', array( 'timestamp' => '01790000000' ) );
$cases[] = make_case( $main, 'get_report_timestamp_tampered', 'reject', 'bad_signature: signed for one timestamp, sent with another inside the window.', array(), array( 'timestamp' => (string) ( NOW + 5 ) ) );

// Reject: signature and key.
$cases[] = make_case( $main, 'get_report_signature_missing', 'reject', 'missing_header: X-SM-Signature absent.', array(), null, array( 'header_overrides' => array( 'X-SM-Signature' => null ) ) );
$cases[] = make_case( $main, 'get_report_signature_not_base64', 'reject', 'bad_signature: header is not valid base64.', array(), null, array( 'header_overrides' => array( 'X-SM-Signature' => 'not*base64!' ) ) );
$cases[] = make_case( $main, 'get_report_signature_wrong_length', 'reject', 'bad_signature: decodes to 32 bytes, not 64.', array(), null, array( 'header_overrides' => array( 'X-SM-Signature' => base64_encode( str_repeat( "\x01", 32 ) ) ) ) );
$cases[] = make_case( $other, 'get_report_wrong_key', 'reject', 'bad_signature: signed with a key the site does not trust.', array(), null, array( 'signed_with' => 'other_seed' ) );
$cases[] = make_case( $main, 'get_report_signed_for_other_site', 'reject', 'bad_signature: signed with another site home_url. Stops a captured request working elsewhere.', array( 'site_url' => OTHER_URL ), array( 'site_url' => SITE_URL ) );
$cases[] = make_case( $main, 'get_report_signed_for_other_route', 'reject', 'bad_signature: signed for the update route, sent to the report route.', array( 'route' => '/sitemanager/v1/update' ), array( 'route' => '/sitemanager/v1/report' ) );
$cases[] = make_case( $main, 'get_report_signed_lowercase_method', 'reject', 'bad_signature: method must be upper case in the canonical string.', array( 'method' => 'get' ), array( 'method' => 'GET' ) );
$cases[] = make_case( $main, 'get_report_with_nonce_header', 'reject', 'unexpected_nonce: GET requests must not carry X-SM-Nonce.', array(), null, array( 'header_overrides' => array( 'X-SM-Nonce' => $nonce_a ) ) );

// Reject: POST specifics.
$cases[] = make_case( $main, 'post_update_body_tampered', 'reject', 'bad_signature: body changed after signing.', array( 'method' => 'POST', 'route' => '/sitemanager/v1/update', 'nonce' => $nonce_a, 'body' => $update_body ), array( 'body' => '{"type":"plugin","item":"wp-rocket/wp-rocket.php","expected_version":"9.9.9"}' ) );
$cases[] = make_case( $main, 'post_update_nonce_missing', 'reject', 'missing_header: POST without X-SM-Nonce.', array( 'method' => 'POST', 'route' => '/sitemanager/v1/update', 'nonce' => $nonce_a, 'body' => $update_body ), null, array( 'header_overrides' => array( 'X-SM-Nonce' => null ) ) );
$cases[] = make_case( $main, 'post_update_nonce_uppercase', 'reject', 'bad_nonce: nonce must be 32 lower case hex characters.', array( 'method' => 'POST', 'route' => '/sitemanager/v1/update', 'nonce' => strtoupper( $nonce_a ), 'body' => $update_body ) );
$cases[] = make_case( $main, 'post_update_nonce_short', 'reject', 'bad_nonce: nonce shorter than 32 characters.', array( 'method' => 'POST', 'route' => '/sitemanager/v1/update', 'nonce' => substr( $nonce_a, 0, 30 ), 'body' => $update_body ) );
$cases[] = make_case( $main, 'post_update_nonce_replayed', 'reject', 'nonce_used: identical to post_update_valid but the nonce is already in the used set.', array( 'method' => 'POST', 'route' => '/sitemanager/v1/update', 'nonce' => $nonce_a, 'body' => $update_body ), null, array( 'used_nonces' => array( $nonce_a ) ) );
$cases[] = make_case( $main, 'post_update_without_opt_in', 'reject', 'not_opted_in: SM_ALLOW_UPDATES is not defined, so the route does not exist.', array( 'method' => 'POST', 'route' => '/sitemanager/v1/update', 'nonce' => $nonce_a, 'body' => $update_body ), null, array( 'allow_updates' => false ) );
$cases[] = make_case( $main, 'post_update_http_site', 'reject', 'not_https: the site home_url is http, so a write could be read or answered by anyone on the path. Correctly signed, still refused.', array( 'method' => 'POST', 'route' => '/sitemanager/v1/update', 'nonce' => $nonce_a, 'body' => $update_body, 'site_url' => HTTP_URL ) );
$cases[] = make_case( $main, 'post_rollback_removed', 'reject', 'no_route: the rollback route was removed in agent 1.0.7. Correctly signed, still refused.', array( 'method' => 'POST', 'route' => '/sitemanager/v1/rollback', 'nonce' => $nonce_b, 'body' => $rollback_body ) );
$cases[] = make_case( $main, 'post_report_wrong_method', 'reject', 'no_route: the report route only accepts GET.', array( 'method' => 'POST', 'route' => '/sitemanager/v1/report', 'nonce' => $nonce_a, 'body' => '' ) );

$out = array(
	'protocol'    => PROTOCOL_VERSION,
	'description' => 'Signing test vectors for the Site Manager agent protocol. See docs/protocol.md section 11 for how to use them. Regenerate with testdata/protocol/generate-vectors.php.',
	'keys'        => array(
		'seed_hex'          => SEED_HEX,
		'public_key_hex'    => bin2hex( $main['public'] ),
		'public_key_base64' => base64_encode( $main['public'] ),
		'other_seed_hex'    => OTHER_SEED_HEX,
		'other_public_hex'  => bin2hex( $other['public'] ),
	),
	'constants'   => array(
		'timestamp_window_seconds' => 60,
		'nonce_pattern'            => '^[0-9a-f]{32}$',
		'timestamp_pattern'        => '^[1-9][0-9]{0,19}$',
		'empty_body_sha256'        => hash( 'sha256', '' ),
	),
	'cases'       => $cases,
);

echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
