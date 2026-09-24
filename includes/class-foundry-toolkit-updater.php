<?php
/**
 * Self-update from signed GitHub releases (S13, ADR 0025).
 *
 * @package FoundryToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offers the latest GitHub release as an update, and installs it only when
 * the zip's Ed25519 signature verifies against SITEMANAGER_PUBLIC_KEY. Like a
 * parcel that is only opened if the seal on it is James's: a GitHub account
 * alone cannot put code on a site.
 */
final class Foundry_Toolkit_Updater {

	/** Where releases are listed. */
	public const API = 'https://api.github.com/repos/foundry-digital/foundry-toolkit/releases';

	/** The only place a release zip may come from. */
	public const DOWNLOAD_PREFIX = 'https://github.com/foundry-digital/foundry-toolkit/releases/download/';

	/** Site transient holding the latest release, or null after a failure. */
	public const CACHE = 'foundry_toolkit_release';

	/** A version: x.y.z with an optional suffix such as -rc1. */
	public const VERSION_PATTERN = '[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.]+)?';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		// WordPress 5.8+ asks this filter about a plugin whose Update URI is on
		// github.com, and only while it runs its own update check.
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'offer' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verify_download' ), 10, 4 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'no_auto_update' ), 99, 2 );
	}

	/**
	 * The latest usable release, cached for six hours (one hour after a
	 * failure, so a GitHub outage does not mean a request on every check).
	 * Set FOUNDRY_TOOLKIT_PRERELEASES in wp-config.php to be offered
	 * pre-releases such as 1.3.0-rc1, on a test site only.
	 *
	 * @return array{version: string, package: string, url: string, notes: string}|null
	 */
	public static function latest() {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) && array_key_exists( 'release', $cached ) ) {
			return $cached['release'];
		}
		$pre      = defined( 'FOUNDRY_TOOLKIT_PRERELEASES' ) && FOUNDRY_TOOLKIT_PRERELEASES;
		$response = wp_remote_get(
			$pre ? self::API . '?per_page=20' : self::API . '/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Foundry-Toolkit/' . FOUNDRY_TOOLKIT_VERSION,
				),
			)
		);
		$release  = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$list = $pre ? $data : array( $data );
			foreach ( is_array( $list ) ? $list : array() as $item ) {
				$r = is_array( $item ) ? self::usable( $item ) : null;
				if ( null !== $r && ( null === $release || version_compare( $r['version'], $release['version'], '>' ) ) ) {
					$release = $r;
				}
			}
		}
		set_site_transient( self::CACHE, array( 'release' => $release ), null === $release ? HOUR_IN_SECONDS : 6 * HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * A GitHub release we can offer: not a draft, a vX.Y.Z tag, and both
	 * the zip and its signature attached under our own release URL.
	 *
	 * @param array<string, mixed> $item Release from the GitHub API.
	 * @return array{version: string, package: string, url: string, notes: string}|null
	 */
	public static function usable( array $item ) {
		if ( ! empty( $item['draft'] ) || ! isset( $item['tag_name'] ) || ! preg_match( '/^v(' . self::VERSION_PATTERN . ')$/', (string) $item['tag_name'], $m ) ) {
			return null;
		}
		$want = self::DOWNLOAD_PREFIX . 'v' . $m[1] . '/foundry-toolkit.zip';
		$urls = array();
		foreach ( isset( $item['assets'] ) && is_array( $item['assets'] ) ? $item['assets'] : array() as $a ) {
			if ( is_array( $a ) && isset( $a['browser_download_url'] ) ) {
				$urls[] = (string) $a['browser_download_url'];
			}
		}
		if ( ! in_array( $want, $urls, true ) || ! in_array( $want . '.sig', $urls, true ) ) {
			return null;
		}
		return array(
			'version' => $m[1],
			'package' => $want,
			'url'     => isset( $item['html_url'] ) ? (string) $item['html_url'] : 'https://github.com/foundry-digital/foundry-toolkit/releases',
			'notes'   => isset( $item['body'] ) ? (string) $item['body'] : '',
		);
	}

	/**
	 * Filter update_plugins_github.com. WordPress compares the version and
	 * files it under updates or no updates itself.
	 *
	 * @param array<string, mixed>|false $update      Update so far.
	 * @param array<string, mixed>       $plugin_data Plugin headers.
	 * @param string                     $plugin_file Plugin basename.
	 * @param string[]                   $locales     Locales.
	 * @return array<string, mixed>|false
	 */
	public static function offer( $update, $plugin_data, $plugin_file, $locales ) {
		unset( $plugin_data, $locales );
		if ( Foundry_Toolkit_Loader::plugin_basename() !== $plugin_file ) {
			return $update;
		}
		$r = self::latest();
		if ( null === $r ) {
			return $update;
		}
		return array(
			'slug'         => 'foundry-toolkit',
			'version'      => $r['version'],
			'url'          => $r['url'],
			'package'      => $r['package'],
			'requires_php' => '7.4',
		);
	}

	/**
	 * Filter plugins_api: the "View details" box.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $action Action.
	 * @param object $args   Arguments.
	 * @return mixed
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || 'foundry-toolkit' !== $args->slug ) {
			return $result;
		}
		$r = self::latest();
		return (object) array(
			'name'          => 'Foundry Toolkit',
			'slug'          => 'foundry-toolkit',
			'version'       => null === $r ? FOUNDRY_TOOLKIT_VERSION : $r['version'],
			'author'        => 'Foundry Digital',
			'homepage'      => 'https://github.com/foundry-digital/foundry-toolkit',
			'requires_php'  => '7.4',
			'download_link' => null === $r ? '' : $r['package'],
			'sections'      => array(
				'description' => esc_html__( 'The Site Manager agent and security hardening for Foundry Digital client sites.', 'foundry-toolkit' ),
				'changelog'   => null === $r ? '' : nl2br( esc_html( $r['notes'] ) ),
			),
		);
	}

	/**
	 * Filter upgrader_pre_download: any update of this plugin must be a
	 * release zip from our GitHub releases whose signature verifies (S13).
	 * Anything else is left to WordPress.
	 *
	 * @param mixed                $reply      False, or what another filter decided.
	 * @param string               $package    Package URL.
	 * @param mixed                $upgrader   Upgrader.
	 * @param array<string, mixed> $hook_extra Extra, with the plugin basename.
	 * @return mixed Path of the verified zip, a WP_Error, or $reply.
	 */
	public static function verify_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		unset( $upgrader );
		$package = (string) $package;
		$ours    = ( is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && Foundry_Toolkit_Loader::plugin_basename() === $hook_extra['plugin'] )
			|| 0 === strpos( $package, self::DOWNLOAD_PREFIX );
		if ( ! $ours ) {
			return $reply;
		}
		if ( ! preg_match( '#^' . preg_quote( self::DOWNLOAD_PREFIX, '#' ) . 'v(' . self::VERSION_PATTERN . ')/foundry-toolkit\.zip$#', $package, $m ) ) {
			return new WP_Error( 'foundry_toolkit_not_a_release', __( 'Foundry Toolkit only updates from its own GitHub releases.', 'foundry-toolkit' ) );
		}
		$version = $m[1];

		$sig = wp_remote_get( $package . '.sig', array( 'timeout' => 30 ) );
		if ( is_wp_error( $sig ) || 200 !== (int) wp_remote_retrieve_response_code( $sig ) ) {
			return new WP_Error( 'foundry_toolkit_no_signature', __( 'The Foundry Toolkit release has no signature, so it was not installed.', 'foundry-toolkit' ) );
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$zip = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- the downloaded temporary file.
		if ( ! self::signature_ok( $version, $zip, (string) wp_remote_retrieve_body( $sig ) ) ) {
			wp_delete_file( $file );
			return new WP_Error( 'foundry_toolkit_bad_signature', __( 'The Foundry Toolkit release is not signed by Site Manager, so it was not installed.', 'foundry-toolkit' ) );
		}
		return $file;
	}

	/**
	 * Whether any line of the signature file verifies with this site's key.
	 *
	 * @param string $version Version from the release URL.
	 * @param string $zip     Zip bytes.
	 * @param string $sigs    Signature file: one base64 signature per line.
	 * @return bool
	 */
	public static function signature_ok( $version, $zip, $sigs ) {
		$key = base64_decode( (string) constant( 'SITEMANAGER_PUBLIC_KEY' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the key, not code.
		if ( false === $key || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) ) {
			return false;
		}
		$message = "foundry-toolkit\n" . $version . "\n" . hash( 'sha256', $zip );
		$lines   = preg_split( '/\r?\n/', trim( $sigs ) );
		foreach ( false === $lines ? array() : $lines as $line ) {
			$sig = base64_decode( trim( $line ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a signature.
			if ( false !== $sig && SODIUM_CRYPTO_SIGN_BYTES === strlen( $sig ) && sodium_crypto_sign_verify_detached( $sig, $message, $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Filter auto_update_plugin: never for Foundry Toolkit. It updates only
	 * when James clicks, in WordPress or in Site Manager.
	 *
	 * @param bool|null $update Whether to update.
	 * @param object    $item   The update offer.
	 * @return bool|null
	 */
	public static function no_auto_update( $update, $item ) {
		if ( is_object( $item ) && isset( $item->plugin ) && Foundry_Toolkit_Loader::plugin_basename() === $item->plugin ) {
			return false;
		}
		return $update;
	}
}
