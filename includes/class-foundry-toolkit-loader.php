<?php
/**
 * The must-use loader: a small file in wp-content/mu-plugins that includes
 * Foundry Toolkit before any other plugin, while the toolkit is active.
 *
 * @package FoundryToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes, checks and removes the loader. Hardening sets constants such as
 * DISALLOW_FILE_EDIT and the HTTPS flag, which only count if nothing else set
 * them first, so it has to load before normal plugins. The loader is how
 * ManageWP Worker does the same (ADR 0025).
 */
final class Foundry_Toolkit_Loader {

	/** File name; the leading 0- sorts it first among must-use plugins. */
	public const FILE = '0-foundry-toolkit.php';

	/** A line only our loader has, so remove() never deletes someone else's file. */
	public const MARKER = 'Written by Foundry Toolkit';

	/**
	 * The must-use plugins folder.
	 *
	 * @return string
	 */
	public static function mu_dir() {
		return defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	}

	/**
	 * Full path of the loader.
	 *
	 * @return string
	 */
	public static function path() {
		return self::mu_dir() . '/' . self::FILE;
	}

	/**
	 * The plugin's folder and file, as WordPress stores it in active_plugins.
	 *
	 * @return string
	 */
	public static function plugin_basename() {
		return basename( dirname( FOUNDRY_TOOLKIT_FILE ) ) . '/' . basename( FOUNDRY_TOOLKIT_FILE );
	}

	/**
	 * The loader's source. It includes the toolkit only while the toolkit is
	 * active, so deactivating the plugin also switches the loader off even if
	 * the file were left behind.
	 *
	 * @param string $plugin Plugin basename, such as foundry-toolkit/foundry-toolkit.php.
	 * @return string
	 */
	public static function contents( $plugin ) {
		$quoted = var_export( $plugin, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writes a PHP string literal.
		return '<?php
/**
 * Plugin Name: Foundry Toolkit loader
 * Description: Loads Foundry Toolkit before other plugins so its hardening applies first. ' . self::MARKER . '; removed when Foundry Toolkit is deactivated. Do not edit.
 *
 * @package FoundryToolkit
 */

if ( ! defined( \'ABSPATH\' ) ) {
	exit;
}

$foundry_toolkit_plugin = ' . $quoted . ';
if ( in_array( $foundry_toolkit_plugin, (array) get_option( \'active_plugins\', array() ), true )
	|| ( is_multisite() && array_key_exists( $foundry_toolkit_plugin, (array) get_site_option( \'active_sitewide_plugins\', array() ) ) ) ) {
	if ( is_readable( WP_PLUGIN_DIR . \'/\' . $foundry_toolkit_plugin ) ) {
		include_once WP_PLUGIN_DIR . \'/\' . $foundry_toolkit_plugin;
	}
}
unset( $foundry_toolkit_plugin );
';
	}

	/**
	 * Whether the loader is there and exactly what this version writes.
	 *
	 * @return bool
	 */
	public static function is_current() {
		$path = self::path();
		if ( ! is_file( $path ) ) {
			return false;
		}
		return file_get_contents( $path ) === self::contents( self::plugin_basename() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a local file.
	}

	/**
	 * Activation hook: write the loader. Writes to a temporary file and
	 * renames it, so a request never includes a half-written loader.
	 *
	 * @return bool Whether the loader is now current.
	 */
	public static function install() {
		$dir = self::mu_dir();
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- must-use folder, written directly like ManageWP Worker.
			return false;
		}
		$tmp = $dir . '/.' . self::FILE . '.' . getmypid() . '.tmp';
		if ( false === file_put_contents( $tmp, self::contents( self::plugin_basename() ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- as above.
			return false;
		}
		if ( ! rename( $tmp, self::path() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- as above.
			unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- as above.
			return false;
		}
		return true;
	}

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether activated for the whole network.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		unset( $network_wide ); // One loader serves every site in a network.
		self::install();
	}

	/**
	 * Admin hook: rewrite the loader if it is missing or out of date, for
	 * example after an update changed it or someone deleted it.
	 *
	 * @return void
	 */
	public static function repair() {
		if ( ! self::is_current() ) {
			self::install();
		}
	}

	/**
	 * Deactivation hook: remove the loader, but only if it is ours.
	 *
	 * @return void
	 */
	public static function remove() {
		$path = self::path();
		if ( ! is_file( $path ) ) {
			return;
		}
		$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a local file.
		if ( false !== strpos( $body, self::MARKER ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- removing our own loader.
		}
	}
}
