<?php
/**
 * GET /integrity (P64, P65): compare WordPress core and the plugins from
 * WordPress.org with their official checksums. Read only: it changes no
 * file and no setting other than its own cache transients, takes no
 * parameters, and returns paths relative to the WordPress root, never a
 * file's contents or a checksum it computed (S14).
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The file integrity check.
 */
final class Foundry_Toolkit_Integrity {

	/** The most paths any one list returns (P65). */
	public const MAX_PATHS = 200;

	/** Seconds after which no new plugin is started (P64). */
	public const BUDGET = 20.0;

	/** How long core and plugin results are cached, in seconds (P64). */
	public const CORE_TTL   = 6 * HOUR_IN_SECONDS;
	public const PLUGIN_TTL = 12 * HOUR_IN_SECONDS;

	/** The option prefix for a premium plugin's or a theme's baseline (1.7.0). */
	public const BASELINE_OPTION = 'sm_integrity_base_';

	/** Core files never checked: hardening often removes them, and none runs. */
	private const CORE_IGNORED = array( 'readme.html', 'license.txt', 'wp-config-sample.php' );

	/**
	 * The WordPress root, for tests; null means ABSPATH. The other seams
	 * below are the same: null means the real WordPress.
	 *
	 * @var string|null
	 */
	public static $root = null;

	/**
	 * The plugins directory, for tests; null means WP_PLUGIN_DIR.
	 *
	 * @var string|null
	 */
	public static $plugin_root = null;

	/**
	 * Returns the core checksums for a version and locale, path => md5, or false.
	 *
	 * @var callable|null
	 */
	public static $core_checksums = null;

	/**
	 * Returns a plugin's checksums, path => md5 or md5[], or null when WordPress.org has none.
	 *
	 * @var callable|null
	 */
	public static $plugin_checksums = null;

	/**
	 * Returns the installed plugins, file => version.
	 *
	 * @var callable|null
	 */
	public static $plugins = null;

	/**
	 * Returns the installed themes, stylesheet => version.
	 *
	 * @var callable|null
	 */
	public static $themes = null;

	/**
	 * The themes directory, for tests; null means get_theme_root().
	 *
	 * @var string|null
	 */
	public static $theme_root = null;

	/**
	 * Returns the time in seconds, for the budget.
	 *
	 * @var callable|null
	 */
	public static $clock = null;

	/**
	 * The route's callback.
	 *
	 * @param WP_REST_Request $request The request; it has no parameters to read.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route( $request ) {
		unset( $request ); // S14: nothing in the request chooses what is read.
		if ( false !== get_transient( SiteManager_Agent::LOCK_TRANSIENT ) ) {
			return new WP_Error( 'sm_busy', 'An update is running; files are changing. Check again when it has finished.', array( 'status' => 409 ) );
		}
		if ( function_exists( 'set_time_limit' ) ) { // Hosts may disable it; calling it then is fatal.
			set_time_limit( 60 ); // phpcs:ignore -- ignore failure of this call.
		}
		$response = new WP_REST_Response( self::run() );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}

	/**
	 * Run the check.
	 *
	 * @return array<string, mixed>
	 */
	public static function run() {
		$started = self::now();
		$version = (string) get_bloginfo( 'version' );
		$locale  = (string) get_locale();
		$out     = array(
			'ok'          => true,
			'complete'    => true,
			'wp_version'  => $version,
			'locale'      => $locale,
			'core'        => array(),
			'plugins'     => array(),
			'modified'    => array(),
			'missing'     => array(),
			'unexpected'  => array(),
			'others'      => array(),
			'truncated'   => false,
			'duration_ms' => 0,
		);

		$core        = self::cached(
			'sm_integrity_core_' . md5( $version . '|' . $locale ),
			self::CORE_TTL,
			static function () use ( $version, $locale ) {
				return self::check_core( $version, $locale );
			}
		);
		$out['core'] = array(
			'checked' => $core['checked'],
			'files'   => $core['files'],
		);
		self::merge( $out, $core );

		foreach ( self::installed_plugins() as $file => $plugin_version ) {
			$slug = dirname( $file );
			if ( '.' === $slug || '' === $plugin_version ) {
				continue; // A single-file plugin has no directory of its own to compare.
			}
			if ( self::now() - $started > self::BUDGET ) {
				$out['complete'] = false; // The next call carries on from the cache (P64).
				break;
			}
			$result           = self::cached(
				'sm_integrity_p_' . md5( $slug . '|' . $plugin_version ),
				self::PLUGIN_TTL,
				static function () use ( $slug, $plugin_version ) {
					return self::check_plugin( $slug, $plugin_version );
				}
			);
			$out['plugins'][] = array(
				'slug'    => $slug,
				'version' => $plugin_version,
				'checked' => $result['checked'],
				'files'   => $result['files'],
			);
			self::merge( $out, $result );
			if ( ! $result['checked'] ) {
				// Premium or custom: its own first copy, and a fingerprint for
				// the fleet (1.7.0).
				self::check_other( $out, 'plugin', $slug, $plugin_version, self::plugin_root() . '/' . $slug, 'wp-content/plugins/' . $slug . '/' );
			}
		}

		foreach ( self::installed_themes() as $slug => $theme_version ) {
			if ( self::now() - $started > self::BUDGET ) {
				$out['complete'] = false;
				break;
			}
			self::check_other( $out, 'theme', $slug, $theme_version, self::theme_root() . '/' . $slug, 'wp-content/themes/' . $slug . '/' );
		}

		foreach ( array( 'modified', 'missing', 'unexpected' ) as $list ) {
			if ( count( $out[ $list ] ) > self::MAX_PATHS ) {
				$out[ $list ]     = array_slice( $out[ $list ], 0, self::MAX_PATHS );
				$out['truncated'] = true;
			}
		}
		$out['duration_ms'] = (int) round( ( self::now() - $started ) * 1000 );
		return $out;
	}

	/**
	 * A premium or custom plugin, or a theme, against its own first copy
	 * (1.7.0): the first time a version is seen, the MD5 of each PHP, JS and
	 * .htaccess file is recorded on the site; later checks list what changed
	 * while the version stayed the same. A new version records a new
	 * baseline. Adds a fingerprint of the files for the fleet comparison.
	 *
	 * @param array<string, mixed> $out     The response.
	 * @param string               $kind    plugin or theme.
	 * @param string               $slug    Directory.
	 * @param string               $version Installed version.
	 * @param string               $dir     Directory path.
	 * @param string               $prefix  Path prefix to report, relative to the WordPress root.
	 * @return void
	 */
	private static function check_other( array &$out, $kind, $slug, $version, $dir, $prefix ) {
		$result          = self::cached(
			'sm_integrity_o_' . md5( $kind . '|' . $slug . '|' . $version ),
			self::PLUGIN_TTL,
			static function () use ( $kind, $slug, $version, $dir, $prefix ) {
				$files   = self::watched_files( $dir );
				$result  = self::empty_result();
				$key     = self::BASELINE_OPTION . md5( $kind . '/' . $slug );
				$stored  = get_option( $key, null );
				$compare = is_array( $stored ) && isset( $stored['version'], $stored['files'] ) && $stored['version'] === $version && is_array( $stored['files'] );
				if ( ! $compare ) {
					update_option(
						$key,
						array(
							'version' => $version,
							'files'   => $files,
							'at'      => gmdate( 'c' ),
						),
						false
					);
					$stored = array( 'files' => $files );
				}
				foreach ( $stored['files'] as $path => $md5 ) {
					if ( ! isset( $files[ $path ] ) ) {
						$result['missing'][] = $prefix . $path;
					} elseif ( $files[ $path ] !== $md5 ) {
						$result['modified'][] = $prefix . $path;
					}
				}
				foreach ( $files as $path => $md5 ) {
					if ( ! isset( $stored['files'][ $path ] ) ) {
						$result['unexpected'][] = $prefix . $path;
					}
				}
				$result['checked']     = true;
				$result['files']       = count( $files );
				$result['baseline']    = $compare ? 'compared' : 'recorded';
				$result['fingerprint'] = self::fingerprint( $files );
				return $result;
			}
		);
		$out['others'][] = array(
			'kind'        => $kind,
			'slug'        => $slug,
			'version'     => $version,
			'files'       => $result['files'],
			'baseline'    => isset( $result['baseline'] ) ? $result['baseline'] : 'recorded',
			'fingerprint' => isset( $result['fingerprint'] ) ? $result['fingerprint'] : '',
		);
		self::merge( $out, $result );
	}

	/**
	 * The MD5 of every PHP, JS and .htaccess file under a directory, by path
	 * relative to it: where injected code lives. Other files (images, CSS a
	 * plugin builds for itself) never count.
	 *
	 * @param string $dir Directory.
	 * @return array<string, string>
	 */
	private static function watched_files( $dir ) {
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
				continue;
			}
			$ext = strtolower( $file->getExtension() );
			if ( ! in_array( $ext, array( 'php', 'phtml', 'js' ), true ) && '.htaccess' !== $file->getFilename() ) {
				continue;
			}
			$path         = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) ) ), '/' );
			$out[ $path ] = (string) md5_file( $file->getPathname() );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * One SHA-256 over a file list and its MD5s: identical copies of a
	 * version give the same fingerprint on every site (P65).
	 *
	 * @param array<string, string> $files Path => MD5, sorted.
	 * @return string
	 */
	private static function fingerprint( array $files ) {
		$lines = '';
		foreach ( $files as $path => $md5 ) {
			$lines .= $path . "\t" . $md5 . "\n";
		}
		return hash( 'sha256', $lines );
	}

	/**
	 * Compare core.
	 *
	 * @param string $version WordPress version.
	 * @param string $locale  Locale.
	 * @return array{checked: bool, files: int, modified: string[], missing: string[], unexpected: string[]}
	 */
	private static function check_core( $version, $locale ) {
		$sums = self::core_checksums( $version, $locale );
		if ( ! is_array( $sums ) && 'en_US' !== $locale ) {
			$sums = self::core_checksums( $version, 'en_US' );
		}
		$result = self::empty_result();
		if ( ! is_array( $sums ) ) {
			return $result;
		}
		$root              = self::root();
		$result['checked'] = true;
		foreach ( $sums as $path => $md5 ) {
			$path = (string) $path;
			if ( 0 === strpos( $path, 'wp-content/' ) || in_array( $path, self::CORE_IGNORED, true ) ) {
				continue;
			}
			self::compare( $result, $root, $path, $path, array( (string) $md5 ) );
		}
		foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
			foreach ( self::php_files( $root . $dir ) as $relative ) {
				$path = $dir . '/' . $relative;
				if ( ! isset( $sums[ $path ] ) ) {
					$result['unexpected'][] = $path;
				}
			}
		}
		return $result;
	}

	/**
	 * Compare one plugin from WordPress.org.
	 *
	 * @param string $slug    Plugin directory.
	 * @param string $version Installed version.
	 * @return array{checked: bool, files: int, modified: string[], missing: string[], unexpected: string[]}
	 */
	private static function check_plugin( $slug, $version ) {
		$result = self::empty_result();
		$sums   = self::plugin_checksums( $slug, $version );
		if ( null === $sums ) {
			return $result; // Premium, custom, or a version WordPress.org never published.
		}
		$result['checked'] = true;
		$dir               = self::plugin_root() . '/' . $slug;
		$prefix            = 'wp-content/plugins/' . $slug . '/';
		foreach ( $sums as $path => $md5 ) {
			self::compare( $result, $dir . '/', (string) $path, $prefix . $path, (array) $md5 );
		}
		foreach ( self::php_files( $dir ) as $relative ) {
			if ( ! isset( $sums[ $relative ] ) ) {
				$result['unexpected'][] = $prefix . $relative;
			}
		}
		return $result;
	}

	/**
	 * Compare one file with its expected sums.
	 *
	 * @param array<string, mixed> $result   The result to add to.
	 * @param string               $base     The directory the file is under, with a trailing slash.
	 * @param string               $path     The file's path under $base.
	 * @param string               $reported The path to report, relative to the WordPress root.
	 * @param string[]             $md5s     The acceptable MD5s.
	 * @return void
	 */
	private static function compare( array &$result, $base, $path, $reported, array $md5s ) {
		if ( false !== strpos( $path, '..' ) ) {
			return; // A checksum list never names a parent directory; refuse one that does.
		}
		$file = $base . $path;
		if ( ! is_file( $file ) ) {
			$result['missing'][] = $reported;
			return;
		}
		++$result['files'];
		if ( ! in_array( md5_file( $file ), $md5s, true ) ) {
			$result['modified'][] = $reported;
		}
	}

	/**
	 * Every .php file under a directory, relative to it.
	 *
	 * @param string $dir Directory.
	 * @return string[]
	 */
	private static function php_files( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$out = array();
		$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file instanceof SplFileInfo && $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$out[] = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) ) ), '/' );
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * A result served from a transient, or made and stored.
	 *
	 * @param string   $key  Transient key.
	 * @param int      $ttl  Seconds.
	 * @param callable $make Builds the result.
	 * @return array{checked: bool, files: int, modified: string[], missing: string[], unexpected: string[]}
	 */
	private static function cached( $key, $ttl, $make ) {
		$hit = get_transient( $key );
		if ( is_array( $hit ) && isset( $hit['checked'], $hit['files'], $hit['modified'], $hit['missing'], $hit['unexpected'] ) ) {
			return $hit;
		}
		$result = call_user_func( $make );
		set_transient( $key, $result, $ttl );
		return $result;
	}

	/**
	 * Append a part's lists to the response.
	 *
	 * @param array<string, mixed> $out  The response.
	 * @param array<string, mixed> $part A core or plugin result.
	 * @return void
	 */
	private static function merge( array &$out, array $part ) {
		foreach ( array( 'modified', 'missing', 'unexpected' ) as $list ) {
			$out[ $list ] = array_merge( $out[ $list ], $part[ $list ] );
		}
	}

	/**
	 * A result with nothing found.
	 *
	 * @return array{checked: bool, files: int, modified: string[], missing: string[], unexpected: string[]}
	 */
	private static function empty_result() {
		return array(
			'checked'    => false,
			'files'      => 0,
			'modified'   => array(),
			'missing'    => array(),
			'unexpected' => array(),
		);
	}

	/**
	 * Core checksums from WordPress.org, via WordPress's own function.
	 *
	 * @param string $version Version.
	 * @param string $locale  Locale.
	 * @return array<string, string>|false
	 */
	private static function core_checksums( $version, $locale ) {
		if ( is_callable( self::$core_checksums ) ) {
			return call_user_func( self::$core_checksums, $version, $locale );
		}
		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		return get_core_checksums( $version, $locale );
	}

	/**
	 * A plugin's checksums from WordPress.org, or null when it has none.
	 *
	 * @param string $slug    Slug.
	 * @param string $version Version.
	 * @return array<string, string|string[]>|null
	 */
	private static function plugin_checksums( $slug, $version ) {
		if ( is_callable( self::$plugin_checksums ) ) {
			return call_user_func( self::$plugin_checksums, $slug, $version );
		}
		$url = 'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode( $slug ) . '/' . rawurlencode( $version ) . '.json';
		$res = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || ! isset( $data['files'] ) || ! is_array( $data['files'] ) ) {
			return null;
		}
		$sums = array();
		foreach ( $data['files'] as $path => $entry ) {
			if ( is_array( $entry ) && isset( $entry['md5'] ) ) {
				$sums[ (string) $path ] = $entry['md5'];
			}
		}
		return $sums;
	}

	/**
	 * Installed plugins, file => version, must-use left out.
	 *
	 * @return array<string, string>
	 */
	private static function installed_plugins() {
		if ( is_callable( self::$plugins ) ) {
			return call_user_func( self::$plugins );
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$out[ (string) $file ] = isset( $data['Version'] ) ? (string) $data['Version'] : '';
		}
		return $out;
	}

	/**
	 * Installed themes, stylesheet => version.
	 *
	 * @return array<string, string>
	 */
	private static function installed_themes() {
		if ( is_callable( self::$themes ) ) {
			return call_user_func( self::$themes );
		}
		$out = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$out[ (string) $stylesheet ] = (string) $theme->get( 'Version' );
		}
		return $out;
	}

	/**
	 * The themes directory, without a trailing slash.
	 *
	 * @return string
	 */
	private static function theme_root() {
		return null !== self::$theme_root ? self::$theme_root : (string) get_theme_root();
	}

	/**
	 * The WordPress root, with a trailing slash.
	 *
	 * @return string
	 */
	private static function root() {
		return null !== self::$root ? self::$root : ABSPATH;
	}

	/**
	 * The plugins directory, without a trailing slash.
	 *
	 * @return string
	 */
	private static function plugin_root() {
		return null !== self::$plugin_root ? self::$plugin_root : (string) WP_PLUGIN_DIR;
	}

	/**
	 * Seconds, for the budget.
	 *
	 * @return float
	 */
	private static function now() {
		return is_callable( self::$clock ) ? (float) call_user_func( self::$clock ) : microtime( true );
	}
}
