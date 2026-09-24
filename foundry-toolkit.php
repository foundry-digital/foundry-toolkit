<?php
/**
 * Plugin Name: Foundry Toolkit
 * Plugin URI: https://github.com/foundry-digital/foundry-toolkit
 * Description: Foundry Digital's toolkit for client sites: the Site Manager agent (signed inventory reports and WordPress's own updates on request) and opinionated security hardening.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Foundry Digital
 * Author URI: https://foundrydigital.com.au
 * License: MIT
 * Update URI: https://github.com/foundry-digital/foundry-toolkit
 * Text Domain: foundry-toolkit
 *
 * The agent protocol is docs/protocol.md in the Site Manager repository; rule
 * numbers (P21, S6) in the code refer to that document. ADR 0025 there
 * explains why this is one normal plugin rather than two must-use files.
 *
 * @package FoundryToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The mu-plugins loader includes this file before other plugins load, and
// WordPress includes it again as a normal plugin. The second time stops here.
if ( defined( 'FOUNDRY_TOOLKIT_VERSION' ) ) {
	return;
}

define( 'FOUNDRY_TOOLKIT_VERSION', '1.1.0' );
define( 'FOUNDRY_TOOLKIT_FILE', __FILE__ );

if ( ! defined( 'SITEMANAGER_PUBLIC_KEY' ) ) {
	// Site Manager's Ed25519 public key. Public by design: it checks
	// signatures, it cannot make them. A new key ships as a new release.
	define( 'SITEMANAGER_PUBLIC_KEY', 'REPLACE_WITH_PUBLIC_KEY' );
}

require_once __DIR__ . '/includes/class-foundry-toolkit.php';
require_once __DIR__ . '/includes/class-foundry-toolkit-loader.php';
require_once __DIR__ . '/includes/class-foundry-toolkit-updater.php';

if ( ! defined( 'SM_AGENT_TESTING' ) ) {
	Foundry_Toolkit::boot();
}
