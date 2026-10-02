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

	/**
	 * How long core and plugin results are cached, in seconds (P64): long
	 * enough for the app's next call to carry on, short enough that a check
	 * an hour later reads the files again (1.8.0).
	 */
	public const CORE_TTL   = 15 * MINUTE_IN_SECONDS;
	public const PLUGIN_TTL = 15 * MINUTE_IN_SECONDS;

	/** How long a plugin version's checksum list is kept: it never changes (1.8.0). */
	public const SUMS_TTL = WEEK_IN_SECONDS;

	/** How long "WordPress.org has none" is kept: its checksums can appear a little after a release (1.8.0). */
	public const NO_SUMS_TTL = DAY_IN_SECONDS;

	/** The option prefix for a premium plugin's or a theme's baseline (1.7.0). */
	public const BASELINE_OPTION = 'sm_integrity_base_';

	/** The most entries any list in places returns (P67). */
	public const MAX_PLACES = 50;

	/** Seconds the walk of the uploads folder may take (P67). */
	public const UPLOADS_BUDGET = 10.0;

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
	 * Returns a plugin's checksums, path => md5 or md5[], null when
	 * WordPress.org has none, or false when it could not be asked.
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
	 * The wp-content folder, for tests; null means WP_CONTENT_DIR.
	 *
	 * @var string|null
	 */
	public static $content_root = null;

	/**
	 * The uploads folder, for tests; null means wp_upload_dir().
	 *
	 * @var string|null
	 */
	public static $uploads_root = null;

	/**
	 * Returns the drop-in file names; null means _get_dropins().
	 *
	 * @var callable|null
	 */
	public static $dropin_names = null;

	/**
	 * Returns PHP's auto_prepend_file; null means ini_get().
	 *
	 * @var callable|null
	 */
	public static $ini_prepend = null;

	/**
	 * Returns administrators stored and listed, array( int, int ); null means the database.
	 *
	 * @var callable|null
	 */
	public static $admin_counts = null;

	/**
	 * Returns the names of tables with triggers; null means the database.
	 *
	 * @var callable|null
	 */
	public static $triggers = null;

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
		$result = self::run();
		if ( false !== get_transient( SiteManager_Agent::LOCK_TRANSIENT ) ) {
			// An update began while the files were being read (1.8.0).
			return new WP_Error( 'sm_busy', 'An update started during the check; files were changing. Check again when it has finished.', array( 'status' => 409 ) );
		}
		$response = new WP_REST_Response( $result );
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
			'places'      => array(),
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
			$sums = self::sums( $slug, $plugin_version );
			if ( false === $sums ) {
				// WordPress.org did not answer. That is not "premium": leave
				// the plugin for the next call and record nothing (1.8.0).
				$out['complete'] = false;
				continue;
			}
			$result           = null === $sums ? self::empty_result() : self::cached(
				'sm_integrity_p_' . md5( $slug . '|' . $plugin_version ),
				self::PLUGIN_TTL,
				static function () use ( $slug, $sums ) {
					return self::check_plugin( $slug, $sums );
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

		$out['places'] = self::cached_places( $version, $locale );

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
		foreach ( self::walk( $dir ) as $file ) {
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
				continue;
			}
			$ext = strtolower( $file->getExtension() );
			if ( 'js' !== $ext && ! self::runs_as_php( $ext ) && '.htaccess' !== $file->getFilename() ) {
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
		$sums   = self::core_sums( $version, $locale );
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
	 * Core checksums for a version and locale, falling back to en_US, kept
	 * for a week: they never change. Null when none could be fetched, which
	 * is never kept.
	 *
	 * @param string $version WordPress version.
	 * @param string $locale  Locale.
	 * @return array<string, string>|null
	 */
	private static function core_sums( $version, $locale ) {
		$key = 'sm_integrity_csums_' . md5( $version . '|' . $locale );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$sums = self::core_checksums( $version, $locale );
		if ( ! is_array( $sums ) && 'en_US' !== $locale ) {
			$sums = self::core_checksums( $version, 'en_US' );
		}
		if ( ! is_array( $sums ) ) {
			return null;
		}
		set_transient( $key, $sums, self::SUMS_TTL );
		return $sums;
	}

	/**
	 * The places, cached like the other results (P67).
	 *
	 * @param string $version WordPress version.
	 * @param string $locale  Locale.
	 * @return array<string, mixed>
	 */
	private static function cached_places( $version, $locale ) {
		$key = 'sm_integrity_places';
		$hit = get_transient( $key );
		if ( is_array( $hit ) && isset( $hit['loose'] ) ) {
			return $hit;
		}
		$places = self::places( $version, $locale );
		set_transient( $key, $places, self::CORE_TTL );
		return $places;
	}

	/**
	 * The places no checksum list covers, where self-healing malware keeps
	 * its copies (P67). Paths relative to the WordPress root only (S14).
	 *
	 * @param string $version WordPress version.
	 * @param string $locale  Locale.
	 * @return array<string, mixed>
	 */
	private static function places( $version, $locale ) {
		$root     = self::root();
		$content  = self::content_root();
		$uploads  = self::uploads_root();
		$dropins  = self::dropin_names();
		$loose    = array();
		$archives = array();
		$complete = true;

		// The WordPress root: PHP that core does not ship, other than wp-config.php.
		$sums = self::core_sums( $version, $locale );
		if ( is_array( $sums ) ) {
			foreach ( self::files_in( $root ) as $name ) {
				if ( self::runs_as_php( pathinfo( $name, PATHINFO_EXTENSION ) ) && 'wp-config.php' !== $name && ! isset( $sums[ $name ] ) ) {
					$loose[] = $name;
				}
			}
		}
		// wp-content itself: anything but drop-ins and index.php.
		foreach ( self::files_in( $content ) as $name ) {
			$file = $content . '/' . $name;
			if ( self::is_hex_archive( $name ) ) {
				$archives[] = self::relative( $file );
			} elseif ( self::runs_as_php( pathinfo( $name, PATHINFO_EXTENSION ) ) && 'index.php' !== $name && ! in_array( $name, $dropins, true ) ) {
				$loose[] = self::relative( $file );
			}
		}
		// Uploads: no PHP at all, within its own time limit.
		if ( '' !== $uploads && is_dir( $uploads ) ) {
			$started = self::now();
			$seen    = 0;
			foreach ( self::walk( $uploads ) as $f ) {
				++$seen;
				if ( 0 === $seen % 128 && self::now() - $started > self::UPLOADS_BUDGET ) {
					$complete = false;
					break;
				}
				if ( ! $f instanceof SplFileInfo || ! $f->isFile() ) {
					continue;
				}
				$name = $f->getFilename();
				if ( self::is_hex_archive( $name ) ) {
					$archives[] = self::relative( $f->getPathname() );
				} elseif ( self::runs_as_php( $f->getExtension() ) && ! ( 'index.php' === $name && $f->getSize() <= 100 ) ) {
					$loose[] = self::relative( $f->getPathname() );
				}
			}
		}

		$dropin_list = array();
		foreach ( $dropins as $name ) {
			if ( is_file( $content . '/' . $name ) ) {
				$dropin_list[] = array(
					'file'        => $name,
					'fingerprint' => (string) hash_file( 'sha256', $content . '/' . $name ),
				);
			}
		}

		// Must-use files, and any that is the same file as a plugin's main file.
		$main = array();
		foreach ( array_keys( self::installed_plugins() ) as $plugin ) {
			$file = self::plugin_root() . '/' . $plugin;
			if ( is_file( $file ) ) {
				$main[ (string) md5_file( $file ) ] = true;
			}
		}
		$mu_dir = $content . '/mu-plugins';
		$mu     = array();
		$copies = array();
		foreach ( is_dir( $mu_dir ) ? self::walk( $mu_dir ) : array() as $f ) {
			if ( ! $f instanceof SplFileInfo || ! $f->isFile() || ! self::runs_as_php( $f->getExtension() ) ) {
				continue;
			}
			$path        = ltrim( str_replace( '\\', '/', substr( $f->getPathname(), strlen( $mu_dir ) ) ), '/' );
			$mu[ $path ] = (string) hash_file( 'sha256', $f->getPathname() );
			if ( isset( $main[ (string) md5_file( $f->getPathname() ) ] ) ) {
				$copies[] = self::relative( $f->getPathname() );
			}
		}
		ksort( $mu );
		$mu_list = array();
		foreach ( $mu as $path => $fingerprint ) {
			$mu_list[] = array(
				'file'        => (string) $path,
				'fingerprint' => $fingerprint,
			);
		}

		$counts   = self::admin_counts();
		$triggers = array_values( array_map( 'strval', self::trigger_tables() ) );
		$cut      = false;
		foreach ( array( $loose, $archives, $copies, $mu_list, $triggers ) as $list ) {
			$cut = $cut || count( array_filter( $list ) ) > self::MAX_PLACES;
		}
		return array(
			'complete'       => $complete,
			'loose'          => self::listed( $loose ),
			'archives'       => self::listed( $archives ),
			'dropins'        => $dropin_list,
			'mu_plugins'     => array_slice( $mu_list, 0, self::MAX_PLACES ),
			'copies'         => self::listed( $copies ),
			'prepend'        => self::prepends(),
			'administrators' => array(
				'stored' => (int) $counts[0],
				'listed' => (int) $counts[1],
			),
			'triggers'       => array_slice( $triggers, 0, self::MAX_PLACES ),
			'truncated'      => $cut,
		);
	}

	/**
	 * A list of paths, without the ones outside the WordPress root, sorted and capped.
	 *
	 * @param array<int, string|null> $paths Paths, null for one outside the root.
	 * @return string[]
	 */
	private static function listed( array $paths ) {
		$out = array_values( array_filter( $paths, 'is_string' ) );
		sort( $out );
		return array_slice( $out, 0, self::MAX_PLACES );
	}

	/**
	 * Where auto_prepend_file is set: PHP's running value, then the config
	 * files that can set it (P67).
	 *
	 * @return array<int, array{source: string, target: string}>
	 */
	private static function prepends() {
		$out   = array();
		$value = is_callable( self::$ini_prepend ) ? (string) call_user_func( self::$ini_prepend ) : (string) ini_get( 'auto_prepend_file' );
		if ( '' !== trim( $value ) ) {
			$out[] = array(
				'source' => 'php',
				'target' => self::prepend_target( $value, self::root() ),
			);
		}
		$root = self::root();
		foreach ( array( '.user.ini', 'php.ini', '.htaccess', 'wp-admin/.user.ini' ) as $source ) {
			$file = $root . $source;
			if ( ! is_file( $file ) || ! is_readable( $file ) ) {
				continue;
			}
			$text    = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local config file, read and never returned (S14).
			$pattern = '.htaccess' === $source ? '/^\s*php_value\s+auto_prepend_file\s+["\']?([^"\'\s]*)/mi' : '/^\s*auto_prepend_file\s*=\s*["\']?([^"\'\r\n;]*)/mi';
			if ( 1 === preg_match( $pattern, $text, $m ) ) {
				$out[] = array(
					'source' => $source,
					'target' => self::prepend_target( $m[1], dirname( $file ) . '/' ),
				);
			}
		}
		return $out;
	}

	/**
	 * The file a prepend setting names, relative to the WordPress root,
	 * "outside" when it is outside it, "" when it names none.
	 *
	 * @param string $value The setting.
	 * @param string $base  The directory a relative value is under.
	 * @return string
	 */
	private static function prepend_target( $value, $base ) {
		$value = trim( $value );
		if ( '' === $value || 'none' === strtolower( $value ) ) {
			return '';
		}
		$path = 0 === strpos( $value, '/' ) || 1 === preg_match( '#^[A-Za-z]:[\\\\/]#', $value ) ? $value : $base . $value;
		$rel  = self::relative( $path );
		return null === $rel || false !== strpos( $rel, '..' ) ? 'outside' : $rel;
	}

	/**
	 * A path relative to the WordPress root, or null when it is outside it.
	 *
	 * @param string $path Absolute path.
	 * @return string|null
	 */
	private static function relative( $path ) {
		$path = str_replace( '\\', '/', $path );
		$root = str_replace( '\\', '/', self::root() );
		return 0 === strpos( $path, $root ) ? (string) substr( $path, strlen( $root ) ) : null;
	}

	/**
	 * The names of the files directly in a directory, not its subdirectories.
	 *
	 * @param string $dir Directory.
	 * @return string[]
	 */
	private static function files_in( $dir ) {
		$names = is_dir( $dir ) ? scandir( $dir ) : false;
		$out   = array();
		foreach ( false === $names ? array() : $names as $name ) {
			if ( '.' !== $name && '..' !== $name && is_file( $dir . '/' . $name ) ) {
				$out[] = (string) $name;
			}
		}
		return $out;
	}

	/**
	 * Whether a file name is a ZIP named with 16 or more hex digits.
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	private static function is_hex_archive( $name ) {
		return 1 === preg_match( '/^[0-9a-f]{16,}\.zip$/i', $name );
	}

	/**
	 * The drop-in names WordPress knows.
	 *
	 * @return string[]
	 */
	private static function dropin_names() {
		if ( is_callable( self::$dropin_names ) ) {
			return (array) call_user_func( self::$dropin_names );
		}
		if ( ! function_exists( '_get_dropins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return array_map( 'strval', array_keys( _get_dropins() ) );
	}

	/**
	 * Administrators stored in the database, counted with SQL, and listed
	 * by get_users(), which plugins and malware can filter.
	 *
	 * @return array{0: int, 1: int}
	 */
	private static function admin_counts() {
		if ( is_callable( self::$admin_counts ) ) {
			$c = (array) call_user_func( self::$admin_counts );
			return array( (int) $c[0], (int) $c[1] );
		}
		global $wpdb;
		$stored = 0;
		if ( $wpdb instanceof wpdb ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$stored = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i m JOIN %i u ON u.ID = m.user_id WHERE m.meta_key = %s AND m.meta_value LIKE %s', $wpdb->usermeta, $wpdb->users, $wpdb->get_blog_prefix() . 'capabilities', '%"administrator"%' ) );
		}
		$listed = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 1000,
			)
		);
		return array( $stored, count( (array) $listed ) );
	}

	/**
	 * The tables in the site's database that have a trigger.
	 *
	 * @return string[]
	 */
	private static function trigger_tables() {
		if ( is_callable( self::$triggers ) ) {
			return (array) call_user_func( self::$triggers );
		}
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = %s', DB_NAME ) ) );
	}

	/**
	 * The wp-content folder, without a trailing slash.
	 *
	 * @return string
	 */
	private static function content_root() {
		return null !== self::$content_root ? self::$content_root : (string) WP_CONTENT_DIR;
	}

	/**
	 * The uploads folder, without a trailing slash, or "" when unknown.
	 *
	 * @return string
	 */
	private static function uploads_root() {
		if ( null !== self::$uploads_root ) {
			return self::$uploads_root;
		}
		$dir = wp_upload_dir( null, false );
		return empty( $dir['error'] ) && ! empty( $dir['basedir'] ) ? untrailingslashit( (string) $dir['basedir'] ) : '';
	}

	/**
	 * Compare one plugin from WordPress.org.
	 *
	 * @param string                         $slug Plugin directory.
	 * @param array<string, string|string[]> $sums Its checksums.
	 * @return array{checked: bool, files: int, modified: string[], missing: string[], unexpected: string[]}
	 */
	private static function check_plugin( $slug, array $sums ) {
		$result            = self::empty_result();
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
	 * Whether a server may run a file with this extension as PHP: .php and
	 * the older and alternative ones (.php5, .phtml, .pht, .phar), which a
	 * dropped file uses to slip past a check for .php alone (1.9.0).
	 *
	 * @param string $ext Extension, any case.
	 * @return bool
	 */
	private static function runs_as_php( $ext ) {
		return 1 === preg_match( '/^(php[0-9]?|phtml|pht|phar)$/i', $ext );
	}

	/**
	 * Every PHP file under a directory, relative to it.
	 *
	 * @param string $dir Directory.
	 * @return string[]
	 */
	private static function php_files( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$out = array();
		foreach ( self::walk( $dir ) as $file ) {
			if ( $file instanceof SplFileInfo && $file->isFile() && self::runs_as_php( $file->getExtension() ) ) {
				$out[] = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) ) ), '/' );
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Every entry under a directory. A directory PHP cannot open is skipped,
	 * not a fatal error (1.8.0).
	 *
	 * @param string $dir Directory.
	 * @return iterable<mixed>
	 */
	private static function walk( $dir ) {
		try {
			return new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
		} catch ( UnexpectedValueException $e ) {
			return array();
		}
	}

	/**
	 * A plugin version's checksums: the list, kept for a week; null when
	 * WordPress.org has none (premium, custom, or a version it never
	 * published), kept for a day; or false when it could not be asked,
	 * which is never kept.
	 *
	 * @param string $slug    Slug.
	 * @param string $version Version.
	 * @return array<string, string|string[]>|null|false
	 */
	private static function sums( $slug, $version ) {
		$key = 'sm_integrity_sums_' . md5( $slug . '|' . $version );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		if ( 'none' === $hit ) {
			return null;
		}
		$sums = self::plugin_checksums( $slug, $version );
		if ( false !== $sums ) {
			set_transient( $key, null === $sums ? 'none' : $sums, null === $sums ? self::NO_SUMS_TTL : self::SUMS_TTL );
		}
		return $sums;
	}

	/**
	 * A result served from a transient, or made and stored. A result that
	 * could not be checked is made again next time.
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
		if ( $result['checked'] ) {
			set_transient( $key, $result, $ttl );
		}
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
	 * A plugin's checksums from WordPress.org, null when it has none (404),
	 * or false when it could not be asked or its answer made no sense.
	 *
	 * @param string $slug    Slug.
	 * @param string $version Version.
	 * @return array<string, string|string[]>|null|false
	 */
	private static function plugin_checksums( $slug, $version ) {
		if ( is_callable( self::$plugin_checksums ) ) {
			return call_user_func( self::$plugin_checksums, $slug, $version );
		}
		$url = 'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode( $slug ) . '/' . rawurlencode( $version ) . '.json';
		$res = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 404 === $code ) {
			return null;
		}
		$data = 200 === $code ? json_decode( (string) wp_remote_retrieve_body( $res ), true ) : null;
		if ( ! is_array( $data ) || ! isset( $data['files'] ) || ! is_array( $data['files'] ) ) {
			return false;
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
