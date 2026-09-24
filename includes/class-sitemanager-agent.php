<?php
/**
 * The Site Manager agent: answers signed requests with an inventory report
 * and applies WordPress's own updates when SM_ALLOW_UPDATES is defined.
 *
 * The protocol it implements is docs/protocol.md in the Site Manager
 * repository. Rule numbers (P21, S6) below refer to that document.
 *
 * @package FoundryToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// An old must-use copy (foundry-sitemanager.php) returns early when this is
// defined, so whichever loads first answers and the class is declared once.
if ( ! defined( 'SITEMANAGER_AGENT_VERSION' ) ) {
	define( 'SITEMANAGER_AGENT_VERSION', FOUNDRY_TOOLKIT_VERSION );
}

/**
 * The agent. One class, static methods, no state beyond WordPress options.
 */
final class SiteManager_Agent {

	public const NAMESPACE_V1     = 'sitemanager/v1';
	public const TIMESTAMP_WINDOW = 60;
	public const NONCE_TTL        = 180;
	public const NONCE_PREFIX     = 'sm_nonce_';
	public const LAST_FATAL_OPT   = 'sitemanager_last_fatal';
	public const CRON_OPT         = 'sitemanager_cron_last_run';
	public const LOCK_TRANSIENT   = 'sm_update_lock';
	public const LOCK_TTL         = 600;
	public const MAX_MESSAGES     = 50;

	/**
	 * Clock used by the permission callback; tests replace it.
	 *
	 * @var callable(): int|null
	 */
	public static $clock = null;

	/**
	 * Builds the WordPress upgrader for a type; tests inject a fake.
	 *
	 * @var callable(string, Automatic_Upgrader_Skin): object|null
	 */
	public static $upgrader_factory = null;

	/**
	 * Test seam: stands in for loading WordPress's updater code, so tests can
	 * check it happens before the skin is created. Null loads the real files.
	 *
	 * @var callable|null
	 */
	public static $admin_loader = null;

	/**
	 * Test seam for the cache clears after a plugin update (P39b): each of
	 * elementor_files, elementor_library, wp_rocket and rocket_cdn maps to
	 * a callable answering true or an error message, or to null when that
	 * tool is not installed; mute_cdn takes a bool. Null finds the real ones.
	 *
	 * @var array<string, callable|null>|null
	 */
	public static $cache_tools = null;

	/**
	 * Overrides for the plugins directory, the old snapshot stores and the
	 * opt-in constant. Null means the WordPress value. Tests set them; a
	 * live site never does, so S2 still rests on the constant.
	 *
	 * @var string|null
	 */
	public static $plugin_dir = null;

	/**
	 * Where agents before 1.0.7 kept rollback snapshots, for tests.
	 *
	 * @var string[]|null
	 */
	public static $old_store_dirs = null;

	/**
	 * Opt-in override for tests; null means read the constant.
	 *
	 * @var bool|null
	 */
	public static $updates_override = null;

	/**
	 * Whether the shutdown release of the lock has been registered.
	 *
	 * @var bool
	 */
	private static $lock_shutdown_registered = false;

	/**
	 * Whether this request holds the update lock.
	 *
	 * @var bool
	 */
	private static $lock_taken = false;

	/**
	 * Wire the agent into WordPress. Does nothing when the key is not set (P12).
	 *
	 * @return void
	 */
	public static function boot() {
		if ( null === self::public_key() ) {
			error_log( 'Site Manager agent: SITEMANAGER_PUBLIC_KEY is missing or invalid; no routes registered.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		register_shutdown_function( array( __CLASS__, 'capture_fatal' ) );
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			update_option( self::CRON_OPT, time(), false );
		}
	}

	/**
	 * The 32-byte public key, or null when the constant is unusable.
	 *
	 * @return non-empty-string|null
	 */
	public static function public_key() {
		$raw = (string) constant( 'SITEMANAGER_PUBLIC_KEY' );
		if ( 'REPLACE_WITH_PUBLIC_KEY' === $raw ) {
			return null;
		}
		$key = base64_decode( $raw, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the key, not code.
		if ( false === $key || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) ) {
			return null;
		}
		return $key;
	}

	/**
	 * Whether the site has opted in to remote updates (P32).
	 *
	 * @return bool
	 */
	public static function updates_enabled() {
		if ( is_bool( self::$updates_override ) ) {
			return self::$updates_override;
		}
		return defined( 'SM_ALLOW_UPDATES' ) && true === SM_ALLOW_UPDATES;
	}

	/**
	 * Server time, injectable for tests.
	 *
	 * @return int
	 */
	private static function now() {
		return is_callable( self::$clock ) ? (int) call_user_func( self::$clock ) : time();
	}

	/**
	 * Register the REST routes. POST routes exist only on opted-in sites.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/report',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'report' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		if ( ! self::updates_enabled() ) {
			return; // P32, S2: without the opt-in the POST routes do not exist.
		}
		register_rest_route(
			self::NAMESPACE_V1,
			'/update',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'update' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * Verification failure: the same response WordPress gives for a route
	 * that does not exist (P22).
	 *
	 * @return WP_Error
	 */
	public static function no_route() {
		return new WP_Error(
			'rest_no_route',
			'No route was found matching the URL and request method.',
			array( 'status' => 404 )
		);
	}

	/**
	 * Permission callback for every route: verify the signature (P21).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public static function permission( $request ) {
		$reason = self::verify( $request, self::now() );
		if ( '' !== $reason ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Site Manager agent rejected a request: ' . $reason ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return self::no_route();
		}
		return true;
	}

	/**
	 * Apply P21 steps 2 to 7. Returns '' when the request is accepted, or a
	 * short reason that is never sent to the client.
	 *
	 * @param WP_REST_Request $request The request.
	 * @param int             $now     Server time, injectable for tests.
	 * @return string
	 */
	public static function verify( $request, $now ) {
		$key = self::public_key();
		if ( null === $key ) {
			return 'no_key';
		}
		$timestamp = (string) $request->get_header( 'x_sm_timestamp' );
		if ( '' === $timestamp ) {
			return 'missing_timestamp';
		}
		if ( 1 !== preg_match( '/^[1-9][0-9]{0,19}$/', $timestamp ) ) {
			return 'bad_timestamp';
		}
		$sig_b64 = (string) $request->get_header( 'x_sm_signature' );
		if ( '' === $sig_b64 ) {
			return 'missing_signature';
		}
		$sig = base64_decode( $sig_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a signature, not code.
		if ( false === $sig || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) ) {
			return 'bad_signature_encoding';
		}
		$method  = strtoupper( (string) $request->get_method() );
		$is_post = 'POST' === $method;
		$nonce   = (string) $request->get_header( 'x_sm_nonce' );
		if ( ! $is_post && '' !== $nonce ) {
			return 'unexpected_nonce';
		}
		if ( $is_post ) {
			if ( '' === $nonce ) {
				return 'missing_nonce';
			}
			if ( 1 !== preg_match( '/^[0-9a-f]{32}$/', $nonce ) ) {
				return 'bad_nonce';
			}
			// P21a: a write only over HTTPS, so nobody on the path can read
			// it or answer in the site's place.
			if ( 0 !== strpos( home_url(), 'https://' ) ) {
				return 'not_https';
			}
		}
		if ( strlen( $timestamp ) > 12 || abs( $now - (int) $timestamp ) > self::TIMESTAMP_WINDOW ) {
			return 'timestamp_out_of_window';
		}
		$canonical = implode(
			"\n",
			array(
				$method,
				(string) $request->get_route(),
				$timestamp,
				$nonce,
				home_url(),
				hash( 'sha256', (string) $request->get_body() ),
			)
		);
		if ( ! sodium_crypto_sign_verify_detached( $sig, $canonical, $key ) ) {
			return 'bad_signature';
		}
		if ( $is_post ) {
			if ( false !== get_transient( self::NONCE_PREFIX . $nonce ) ) {
				return 'nonce_used';
			}
			set_transient( self::NONCE_PREFIX . $nonce, 1, self::NONCE_TTL );
		}
		return '';
	}

	/**
	 * GET /report (P26).
	 *
	 * @return WP_REST_Response
	 */
	public static function report() {
		$response = new WP_REST_Response( self::build_report() );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}

	/**
	 * The report document. Reads existing state only; never triggers an
	 * update check (P30, S11).
	 *
	 * @return array<string, mixed>
	 */
	public static function build_report() {
		return array(
			'protocol'     => 1,
			'agent'        => array(
				'version'         => SITEMANAGER_AGENT_VERSION,
				'php_file'        => basename( FOUNDRY_TOOLKIT_FILE ),
				'updates_enabled' => self::updates_enabled(),
			) + Foundry_Toolkit::report_fields(),
			'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'wp'           => self::report_wp(),
			'php'          => self::report_php(),
			'theme'        => self::report_theme(),
			'themes'       => self::report_themes(),
			'plugins'      => self::report_plugins(),
			'users'        => self::report_users(),
			'constants'    => self::report_constants(),
			'security'     => self::report_security(),
			'health'       => self::report_health(),
		);
	}

	/**
	 * Core facts.
	 *
	 * @return array<string, mixed>
	 */
	private static function report_wp() {
		$update = null;
		$core   = get_site_transient( 'update_core' );
		if ( is_object( $core ) && isset( $core->updates ) && is_array( $core->updates ) ) {
			foreach ( $core->updates as $offer ) {
				if ( is_object( $offer ) && isset( $offer->response, $offer->version ) && 'upgrade' === $offer->response ) {
					$update = (string) $offer->version;
					break;
				}
			}
		}
		return array(
			'version'        => (string) get_bloginfo( 'version' ),
			'update_version' => $update,
			'home_url'       => home_url(),
			'site_url'       => site_url(),
			'multisite'      => is_multisite(),
			'language'       => get_locale(),
			'timezone'       => wp_timezone_string(),
		);
	}

	/**
	 * PHP facts.
	 *
	 * @return array<string, mixed>
	 */
	private static function report_php() {
		$limit = ini_get( 'memory_limit' );
		return array(
			'version'      => PHP_VERSION,
			'memory_limit' => false === $limit ? '' : (string) $limit,
			'extensions'   => array(
				'sodium'  => extension_loaded( 'sodium' ),
				'imagick' => extension_loaded( 'imagick' ),
				'gd'      => extension_loaded( 'gd' ),
				'curl'    => extension_loaded( 'curl' ),
				'zip'     => extension_loaded( 'zip' ),
			),
		);
	}

	/**
	 * The version WordPress offers for a theme, or null (P30: read the
	 * transient, never refresh it).
	 *
	 * @param string $slug Theme stylesheet.
	 * @return string|null
	 */
	private static function theme_offer( $slug ) {
		$updates = get_site_transient( 'update_themes' );
		if ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) && isset( $updates->response[ $slug ]['new_version'] ) ) {
			return (string) $updates->response[ $slug ]['new_version'];
		}
		return null;
	}

	/**
	 * Every installed theme, active or not (agent 1.0.6).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function report_themes() {
		$active = wp_get_theme();
		$parent = $active->parent();
		$out    = array();
		foreach ( (array) wp_get_themes() as $slug => $theme ) {
			if ( ! $theme instanceof WP_Theme ) {
				continue;
			}
			$slug  = (string) $slug;
			$out[] = array(
				'slug'           => $slug,
				'name'           => (string) $theme->get( 'Name' ),
				'version'        => (string) $theme->get( 'Version' ),
				'update_version' => self::theme_offer( $slug ),
				'active'         => $slug === $active->get_stylesheet(),
				'parent'         => $parent instanceof WP_Theme && $slug === $parent->get_stylesheet(),
			);
		}
		return $out;
	}

	/**
	 * Active theme and its parent.
	 *
	 * @return array<string, mixed>
	 */
	private static function report_theme() {
		$theme  = wp_get_theme();
		$parent = null;
		$p      = $theme->parent();
		if ( $p instanceof WP_Theme ) {
			$parent = array(
				'slug'           => $p->get_stylesheet(),
				'name'           => (string) $p->get( 'Name' ),
				'version'        => (string) $p->get( 'Version' ),
				'update_version' => self::theme_offer( $p->get_stylesheet() ),
			);
		}
		return array(
			'slug'           => $theme->get_stylesheet(),
			'name'           => (string) $theme->get( 'Name' ),
			'version'        => (string) $theme->get( 'Version' ),
			'update_version' => self::theme_offer( $theme->get_stylesheet() ),
			'parent'         => $parent,
		);
	}

	/**
	 * Every installed plugin (P27), from the transient WordPress keeps.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function report_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$updates = get_site_transient( 'update_plugins' );
		$offered = array();
		if ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ) {
			foreach ( $updates->response as $file => $info ) {
				if ( is_object( $info ) && isset( $info->new_version ) ) {
					$offered[ (string) $file ] = (string) $info->new_version;
				}
			}
		}
		$auto = get_site_option( 'auto_update_plugins', array() );
		if ( ! is_array( $auto ) ) {
			$auto = array();
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$file  = (string) $file;
			$out[] = array(
				'file'           => $file,
				'slug'           => self::plugin_slug( $file ),
				'name'           => isset( $data['Name'] ) ? (string) $data['Name'] : '',
				'version'        => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'update_version' => isset( $offered[ $file ] ) ? $offered[ $file ] : null,
				'active'         => is_plugin_active( $file ),
				'network_active' => is_multisite() && is_plugin_active_for_network( $file ),
				'auto_update'    => in_array( $file, $auto, true ),
				'must_use'       => false,
			);
		}
		foreach ( get_mu_plugins() as $file => $data ) {
			$file  = (string) $file;
			$out[] = array(
				'file'           => $file,
				'slug'           => self::plugin_slug( $file ),
				'name'           => isset( $data['Name'] ) ? (string) $data['Name'] : '',
				'version'        => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'update_version' => null,
				'active'         => true,
				'network_active' => false,
				'auto_update'    => false,
				'must_use'       => true,
			);
		}
		return $out;
	}

	/**
	 * The directory part of a plugin file, or the file name for single-file plugins.
	 *
	 * @param string $file Plugin basename.
	 * @return string
	 */
	private static function plugin_slug( $file ) {
		$slash = strpos( $file, '/' );
		if ( false !== $slash ) {
			return substr( $file, 0, $slash );
		}
		return preg_replace( '/\.php$/', '', $file ) ?? $file;
	}

	/**
	 * Counts and booleans about administrators. Never a name or an email (S6).
	 *
	 * @return array<string, mixed>
	 */
	private static function report_users() {
		global $wpdb;
		$counts    = count_users();
		$admins    = isset( $counts['avail_roles']['administrator'] ) ? (int) $counts['avail_roles']['administrator'] : 0;
		$admin_ids = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 500,
			)
		);
		$admin_ids = array_map( 'intval', (array) $admin_ids );
		$source    = 'none';
		$with_2fa  = 0;
		if ( $wpdb instanceof wpdb && ! empty( $admin_ids ) ) {
			$wfls = $wpdb->prefix . 'wfls_2fa_secrets';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wfls ) );
			if ( $exists === $wfls ) {
				$source = 'wordfence';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ids      = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT user_id FROM %i', $wfls ) );
				$with_2fa = count( array_intersect( $admin_ids, array_map( 'intval', $ids ) ) );
			}
		}
		if ( 'none' === $source ) {
			foreach ( $admin_ids as $id ) {
				$providers = get_user_meta( $id, '_two_factor_enabled_providers', true );
				if ( is_array( $providers ) && ! empty( $providers ) ) {
					$source = 'two-factor';
					++$with_2fa;
				}
			}
		}
		$admin_user = get_user_by( 'login', 'admin' );
		$has_admin  = $admin_user instanceof WP_User && in_array( 'administrator', (array) $admin_user->roles, true );
		return array(
			'administrators'          => $admins,
			'administrators_with_2fa' => $with_2fa,
			'two_factor_source'       => $source,
			'has_admin_username'      => $has_admin,
		);
	}

	/**
	 * Hardening constants as booleans (P26).
	 *
	 * @return array<string, bool>
	 */
	private static function report_constants() {
		$names = array( 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'WP_DEBUG', 'WP_DEBUG_DISPLAY', 'WP_DEBUG_LOG', 'FORCE_SSL_ADMIN', 'WP_AUTO_UPDATE_CORE', 'AUTOMATIC_UPDATER_DISABLED' );
		$out   = array();
		foreach ( $names as $name ) {
			$out[ $name ] = defined( $name ) && false !== constant( $name );
		}
		return $out;
	}

	/**
	 * Wordfence firewall mode and the login path.
	 *
	 * @return array<string, mixed>
	 */
	private static function report_security() {
		global $wpdb;
		$mode = null;
		if ( $wpdb instanceof wpdb ) {
			$table = $wpdb->prefix . 'wfconfig';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists === $table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$status = $wpdb->get_var( $wpdb->prepare( 'SELECT val FROM %i WHERE name = %s', $table, 'wafStatus' ) );
				$map    = array(
					'enabled'       => 'enabled',
					'learning-mode' => 'learning',
					'disabled'      => 'disabled',
				);
				$mode   = isset( $map[ (string) $status ] ) ? $map[ (string) $status ] : null;
			}
		}
		$path = wp_parse_url( wp_login_url(), PHP_URL_PATH );
		return array(
			'wordfence_firewall_mode' => $mode,
			'login_path'              => is_string( $path ) && '' !== $path ? $path : '/wp-login.php',
		);
	}

	/**
	 * Cron, sizes, recovery mode and the last fatal error (P28, P31).
	 *
	 * @return array<string, mixed>
	 */
	private static function report_health() {
		$cron           = get_option( self::CRON_OPT );
		$fatal          = get_option( self::LAST_FATAL_OPT );
		$paused_plugins = array();
		$paused_themes  = array();
		if ( function_exists( 'wp_paused_plugins' ) ) {
			$paused_plugins = array_keys( (array) wp_paused_plugins()->get_all() );
		}
		if ( function_exists( 'wp_paused_themes' ) ) {
			$paused_themes = array_keys( (array) wp_paused_themes()->get_all() );
		}
		return array(
			'cron_last_run_at'   => is_numeric( $cron ) ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $cron ) : null,
			'cron_disabled'      => defined( 'DISABLE_WP_CRON' ) && false !== DISABLE_WP_CRON,
			'db_size_bytes'      => self::db_size(),
			'uploads_size_bytes' => self::uploads_size(),
			'recovery_mode'      => function_exists( 'wp_is_recovery_mode' ) && wp_is_recovery_mode(),
			'paused_plugins'     => array_values( array_map( 'strval', $paused_plugins ) ),
			'paused_themes'      => array_values( array_map( 'strval', $paused_themes ) ),
			'last_fatal'         => is_array( $fatal ) && isset( $fatal['at'], $fatal['message'], $fatal['file'], $fatal['line'] ) ? array(
				'at'      => (string) $fatal['at'],
				'message' => self::relative( self::redact( (string) $fatal['message'] ) ),
				'file'    => self::relative( (string) $fatal['file'] ),
				'line'    => (int) $fatal['line'],
			) : null,
		);
	}

	/**
	 * Database size from information_schema, cached for an hour.
	 *
	 * @return int
	 */
	private static function db_size() {
		$cached = get_transient( 'sitemanager_db_size' );
		if ( is_numeric( $cached ) ) {
			return (int) $cached;
		}
		global $wpdb;
		$size = 0;
		if ( $wpdb instanceof wpdb ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$size = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s',
					DB_NAME,
					$wpdb->esc_like( $wpdb->prefix ) . '%'
				)
			);
		}
		set_transient( 'sitemanager_db_size', $size, HOUR_IN_SECONDS );
		return $size;
	}

	/**
	 * Uploads directory size, cached for six hours; -1 when unreadable.
	 *
	 * @return int
	 */
	private static function uploads_size() {
		$cached = get_transient( 'sitemanager_uploads_size' );
		if ( is_numeric( $cached ) ) {
			return (int) $cached;
		}
		$dir  = wp_upload_dir();
		$size = -1;
		if ( isset( $dir['basedir'] ) && is_dir( $dir['basedir'] ) && function_exists( 'recurse_dirsize' ) ) {
			$measured = recurse_dirsize( $dir['basedir'], null, 20 );
			if ( is_numeric( $measured ) ) {
				$size = (int) $measured;
			}
		}
		set_transient( 'sitemanager_uploads_size', $size, 6 * HOUR_IN_SECONDS );
		return $size;
	}

	/**
	 * Strip anything that looks like an email address from an error message
	 * and cap its length, so a fatal error can never carry personal data
	 * into the report (S6).
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	private static function redact( $message ) {
		$clean = preg_replace( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $message );
		if ( null === $clean ) {
			$clean = '';
		}
		if ( strlen( $clean ) > 300 ) {
			$clean = substr( $clean, 0, 300 ) . '…';
		}
		return $clean;
	}


	/**
	 * Paths relative to the WordPress root, so the report does not describe
	 * the server's directory layout (P28).
	 *
	 * @param string $text A path or a message holding paths.
	 * @return string
	 */
	private static function relative( $text ) {
		return str_replace( ABSPATH, '', $text );
	}

	// ------------------------------------------------------------------
	// Update endpoint (protocol section 7)
	// ------------------------------------------------------------------

	/**
	 * An error in the P47 envelope.
	 *
	 * @param string               $code    Stable code (P48).
	 * @param string               $message Human message.
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $extra   Extra data fields.
	 * @return WP_Error
	 */
	private static function fail( $code, $message, $status, $extra = array() ) {
		return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) );
	}

	/**
	 * The request body as an associative array, or null when it is not a JSON object.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array<string, mixed>|null
	 */
	private static function body( $request ) {
		$decoded = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $decoded ) || array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
			return null;
		}
		return $decoded;
	}

	/**
	 * Whether an item has the right shape for its type (P33).
	 *
	 * @param string $type Type.
	 * @param string $item Item.
	 * @return bool
	 */
	private static function item_ok( $type, $item ) {
		switch ( $type ) {
			case 'plugin':
				return 1 === preg_match( '/^[A-Za-z0-9_.-]+(\/[A-Za-z0-9_.-]+)?\.php$/', $item ) && false === strpos( $item, '..' );
			case 'theme':
				return 1 === preg_match( '/^[A-Za-z0-9_.-]+$/', $item ) && false === strpos( $item, '..' );
			case 'core':
				return 'core' === $item;
			case 'translation':
				return 'all' === $item;
		}
		return false;
	}

	/**
	 * Whether a plugin or theme is installed (P35 step 5).
	 *
	 * @param string $type Type.
	 * @param string $item Item.
	 * @return bool
	 */
	private static function installed( $type, $item ) {
		if ( 'plugin' === $type ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			return array_key_exists( $item, get_plugins() );
		}
		if ( 'theme' === $type ) {
			$theme = wp_get_theme( $item );
			return is_object( $theme ) && method_exists( $theme, 'exists' ) && $theme->exists();
		}
		return true;
	}

	/**
	 * What WordPress's own transient offers for an item (P34), and the offer
	 * object core needs. Null version means no update is offered.
	 *
	 * @param string $type Type.
	 * @param string $item Item.
	 * @return array{0: string|null, 1: mixed}
	 */
	private static function offered( $type, $item ) {
		if ( 'plugin' === $type || 'theme' === $type ) {
			$transient = get_site_transient( 'plugin' === $type ? 'update_plugins' : 'update_themes' );
			if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) || ! isset( $transient->response[ $item ] ) ) {
				return array( null, null );
			}
			$info = $transient->response[ $item ];
			if ( is_object( $info ) && isset( $info->new_version ) ) {
				return array( (string) $info->new_version, $info );
			}
			if ( is_array( $info ) && isset( $info['new_version'] ) ) {
				return array( (string) $info['new_version'], $info );
			}
			return array( null, null );
		}
		if ( 'core' === $type ) {
			if ( ! function_exists( 'get_core_updates' ) ) {
				require_once ABSPATH . 'wp-admin/includes/update.php';
			}
			$updates = get_core_updates();
			foreach ( is_array( $updates ) ? $updates : array() as $offer ) {
				if ( is_object( $offer ) && isset( $offer->response, $offer->version ) && 'upgrade' === $offer->response ) {
					return array( (string) $offer->version, $offer );
				}
			}
			return array( null, null );
		}
		if ( ! function_exists( 'wp_get_translation_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		$packs = wp_get_translation_updates();
		return empty( $packs ) ? array( null, null ) : array( '', $packs );
	}

	/**
	 * The version installed on disk, read from file headers (P36 step 4).
	 *
	 * @param string $type Type.
	 * @param string $item Item.
	 * @return string
	 */
	private static function installed_version( $type, $item ) {
		if ( 'plugin' === $type ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$file = self::plugin_dir() . '/' . $item;
			if ( ! is_file( $file ) ) {
				return '';
			}
			$data = get_plugin_data( $file, false, false );
			return isset( $data['Version'] ) ? (string) $data['Version'] : '';
		}
		if ( 'theme' === $type ) {
			wp_clean_themes_cache();
			$theme = wp_get_theme( $item );
			return is_object( $theme ) && method_exists( $theme, 'get' ) ? (string) $theme->get( 'Version' ) : '';
		}
		if ( 'core' === $type ) {
			$file = ABSPATH . 'wp-includes/version.php';
			if ( is_file( $file ) ) {
				$read = static function () use ( $file ) {
					$wp_version = '';
					include $file;
					return (string) $wp_version;
				};
				return $read();
			}
			return (string) get_bloginfo( 'version' );
		}
		return '';
	}

	/**
	 * Take the update lock (P38). False when another request holds it.
	 *
	 * @param string $nonce The request nonce, stored as the lock value.
	 * @return bool
	 */
	private static function take_lock( $nonce ) {
		if ( false !== get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}
		set_transient( self::LOCK_TRANSIENT, $nonce, self::LOCK_TTL );
		self::$lock_taken = true;
		if ( ! self::$lock_shutdown_registered ) {
			self::$lock_shutdown_registered = true;
			register_shutdown_function( array( __CLASS__, 'release_lock' ) );
		}
		return true;
	}

	/**
	 * Release the update lock. Runs at the end of every request that took
	 * it and again at shutdown, so a fatal error cannot leave it behind.
	 *
	 * @return void
	 */
	public static function release_lock() {
		if ( ! self::$lock_taken ) {
			return;
		}
		self::$lock_taken = false;
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * The plugins directory.
	 *
	 * @return string
	 */
	private static function plugin_dir() {
		return null !== self::$plugin_dir ? self::$plugin_dir : (string) WP_PLUGIN_DIR;
	}

	/**
	 * Strip URL query strings from upgrader messages, because premium
	 * updaters put licence keys in them (P39), and cap the list.
	 *
	 * @param string[] $messages Raw messages.
	 * @return string[]
	 */
	private static function clean_messages( $messages ) {
		$out = array();
		foreach ( array_slice( $messages, 0, self::MAX_MESSAGES ) as $m ) {
			$cleaned = preg_replace( '/\?[^\s"\'<>]*/', '', (string) $m );
			$out[]   = null === $cleaned ? '' : $cleaned;
		}
		return $out;
	}

	/**
	 * Whether the failure looks like a licence problem (P41).
	 *
	 * @param string[] $messages Cleaned messages.
	 * @param string   $error    The upgrader's error message.
	 * @return bool
	 */
	private static function licence_problem( $messages, $error ) {
		$text = implode( "\n", $messages ) . "\n" . $error;
		return 1 === preg_match( '/licen[cs]e|subscription|\b40[13]\b/i', $text );
	}

	/**
	 * Build the WordPress upgrader for a type, or whatever the factory
	 * seam returns in tests.
	 *
	 * @param string                  $type Type.
	 * @param Automatic_Upgrader_Skin $skin Skin.
	 * @return object
	 */
	private static function upgrader( $type, $skin ) {
		if ( is_callable( self::$upgrader_factory ) ) {
			return call_user_func( self::$upgrader_factory, $type, $skin );
		}
		switch ( $type ) {
			case 'plugin':
				return new Plugin_Upgrader( $skin );
			case 'theme':
				return new Theme_Upgrader( $skin );
			case 'core':
				return new Core_Upgrader( $skin );
			default:
				return new Language_Pack_Upgrader( $skin );
		}
	}

	/**
	 * Load the wp-admin code the upgraders and their skin need. WordPress
	 * only loads it on admin screens; a REST request has none of it.
	 * class-wp-upgrader.php brings the upgrader classes and every skin,
	 * including Automatic_Upgrader_Skin.
	 *
	 * @return void
	 */
	private static function load_updater_code() {
		if ( is_callable( self::$admin_loader ) ) {
			call_user_func( self::$admin_loader );
			return;
		}
		foreach ( array( 'file.php', 'misc.php', 'plugin.php', 'update.php', 'class-wp-upgrader.php' ) as $inc ) {
			require_once ABSPATH . 'wp-admin/includes/' . $inc;
		}
	}

	/**
	 * Whether a plugin is switched on, and whether network-wide, before
	 * WordPress touches it.
	 *
	 * @param string $item Plugin file.
	 * @return array{active: bool, network: bool}
	 */
	private static function activation_state( $item ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$network = is_multisite() && is_plugin_active_for_network( $item );
		return array(
			'active'  => $network || is_plugin_active( $item ),
			'network' => $network,
		);
	}

	/**
	 * Switch a plugin back on when it was on before and is off now. Silent,
	 * like WordPress's own background updates: the plugin's activation code
	 * does not run again, just as its deactivation code did not run when
	 * WordPress switched it off.
	 *
	 * @param string                             $item  Plugin file.
	 * @param array{active: bool, network: bool} $state From activation_state().
	 * @return bool|WP_Error True when it was switched back on.
	 */
	private static function restore_activation( $item, $state ) {
		if ( empty( $state['active'] ) || is_plugin_active( $item ) ) {
			return false;
		}
		$result = activate_plugin( $item, '', ! empty( $state['network'] ), true );
		return $result instanceof WP_Error ? $result : true;
	}

	/**
	 * POST /update (P33 to P41).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update( $request ) {
		$started = microtime( true );
		$body    = self::body( $request );
		if ( null === $body ) {
			return self::fail( 'sm_bad_request', 'The body must be a JSON object.', 400 );
		}
		$type = isset( $body['type'] ) && is_string( $body['type'] ) ? $body['type'] : '';
		if ( ! in_array( $type, array( 'plugin', 'theme', 'core', 'translation' ), true ) ) {
			return self::fail( 'sm_bad_request', 'type must be plugin, theme, core or translation.', 400 );
		}
		$item = isset( $body['item'] ) && is_string( $body['item'] ) ? $body['item'] : '';
		if ( '' === $item || ! self::item_ok( $type, $item ) ) {
			return self::fail( 'sm_bad_request', 'item is missing or not the right shape for its type.', 400 );
		}
		$expected     = isset( $body['expected_version'] ) && is_string( $body['expected_version'] ) ? $body['expected_version'] : '';
		$has_expected = array_key_exists( 'expected_version', $body );
		if ( 'translation' === $type ? $has_expected : '' === $expected ) {
			return self::fail( 'sm_bad_request', 'expected_version is required for plugin, theme and core, and not allowed for translation.', 400 );
		}
		if ( ! self::installed( $type, $item ) ) {
			return self::fail( 'sm_not_installed', 'That item is not installed.', 422 );
		}
		if ( ! self::take_lock( (string) $request->get_header( 'x_sm_nonce' ) ) ) {
			return self::fail( 'sm_busy', 'Another update is running.', 409 );
		}
		try {
			self::remove_old_stores();
			list( $offered_version, $offer ) = self::offered( $type, $item );
			if ( null === $offered_version ) {
				return self::fail( 'sm_no_update_available', 'WordPress offers no update for that item.', 409 );
			}
			if ( 'translation' !== $type && $offered_version !== $expected ) {
				return self::fail( 'sm_version_mismatch', 'WordPress now offers ' . $offered_version . ', not ' . $expected . '.', 409, array( 'offered_version' => $offered_version ) );
			}
			$from = self::installed_version( $type, $item );
			// The update leaves an active plugin on, but note the state now
			// and put it back after, in case anything switched it off.
			$state = 'plugin' === $type ? self::activation_state( $item ) : null;
			// WordPress loads its updater classes only inside wp-admin, and
			// this is a REST request, so load them before making the skin.
			self::load_updater_code();
			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = self::upgrader( $type, $skin );
			set_time_limit( 300 ); // phpcs:ignore -- P36: ignore failure of this call.
			if ( 'translation' === $type ) {
				$result = method_exists( $upgrader, 'bulk_upgrade' ) ? $upgrader->bulk_upgrade( $offer ) : false;
			} elseif ( 'core' === $type ) {
				$result = method_exists( $upgrader, 'upgrade' ) ? $upgrader->upgrade( $offer ) : false;
			} elseif ( 'plugin' === $type ) {
				// The "update now" path from wp-admin's Plugins screen: an
				// active plugin stays on, and the site is in maintenance mode
				// for the seconds the files take. Plugin_Upgrader::upgrade
				// would switch it off first (P39a). The answer is keyed by
				// plugin file, or false when the filesystem is unreachable.
				$results = method_exists( $upgrader, 'bulk_upgrade' ) ? $upgrader->bulk_upgrade( array( $item ) ) : false;
				$result  = is_array( $results ) ? ( $results[ $item ] ?? false ) : $results;
			} else {
				$result = method_exists( $upgrader, 'upgrade' ) ? $upgrader->upgrade( $item ) : false;
			}
			$raw         = method_exists( $skin, 'get_upgrade_messages' ) ? (array) $skin->get_upgrade_messages() : array();
			$messages    = self::clean_messages( array_map( 'strval', $raw ) );
			$now         = self::installed_version( $type, $item );
			$reactivated = null !== $state ? self::restore_activation( $item, $state ) : false;
			if ( $reactivated instanceof WP_Error ) {
				$messages[]  = 'Could not switch the plugin back on: ' . $reactivated->get_error_message();
				$reactivated = false;
			}
			$duration = (int) round( ( microtime( true ) - $started ) * 1000 );
			if ( $result instanceof WP_Error || false === $result || null === $result ) {
				$error = $result instanceof WP_Error ? $result->get_error_message() : 'The upgrader reported failure.';
				return self::fail(
					'sm_upgrade_failed',
					$error,
					500,
					array(
						'from_version'           => $from,
						'installed_version'      => $now,
						'messages'               => $messages,
						'likely_licence_problem' => self::licence_problem( $messages, $error ),
					)
				);
			}
			$caches   = 'plugin' === $type ? self::clear_caches( $item ) : array();
			$duration = (int) round( ( microtime( true ) - $started ) * 1000 );
			$response = new WP_REST_Response(
				array(
					'ok'           => true,
					'type'         => $type,
					'item'         => $item,
					'from_version' => $from,
					'to_version'   => $now,
					'duration_ms'  => $duration,
					'messages'     => $messages,
					'reactivated'  => $reactivated,
					'caches'       => $caches,
				)
			);
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Clear the caches a plugin update leaves stale (P39b): Elementor's files
	 * and library when Elementor or Elementor Pro was updated, then WP Rocket, then
	 * the Rocket.net CDN last so it refills from fresh pages. Only tools
	 * that are installed are listed. A failed clear is reported, never
	 * fatal: the update itself worked.
	 *
	 * @param string $item The plugin file just updated.
	 * @return array<int, array{name: string, status: string, detail: string}>
	 */
	private static function clear_caches( $item ) {
		$tools = is_array( self::$cache_tools ) ? self::$cache_tools : self::cache_tools();
		$steps = array();
		if ( in_array( $item, array( 'elementor/elementor.php', 'elementor-pro/elementor-pro.php' ), true ) ) {
			$steps[] = 'elementor_files';
			$steps[] = 'elementor_library';
		}
		$steps[] = 'wp_rocket';
		$mute    = isset( $tools['rocket_cdn'], $tools['mute_cdn'] ) ? $tools['mute_cdn'] : null;
		$out     = array();
		if ( null !== $mute ) {
			// Elementor and WP Rocket each fire a hook the CDN plugin purges
			// on. Muted, the CDN is purged once, by the last step.
			$mute( true );
		}
		try {
			foreach ( $steps as $name ) {
				if ( isset( $tools[ $name ] ) ) {
					$out[] = self::run_cache_step( $name, $tools[ $name ] );
				}
			}
		} finally {
			if ( null !== $mute ) {
				$mute( false );
			}
		}
		if ( isset( $tools['rocket_cdn'] ) ) {
			$out[] = self::run_cache_step( 'rocket_cdn', $tools['rocket_cdn'] );
		}
		return $out;
	}

	/**
	 * Run one cache clear and describe how it went.
	 *
	 * @param string   $name Step name.
	 * @param callable $tool Answers true or an error message.
	 * @return array{name: string, status: string, detail: string}
	 */
	private static function run_cache_step( $name, $tool ) {
		try {
			$answer = $tool();
		} catch ( Throwable $e ) {
			$answer = $e->getMessage();
		}
		if ( true === $answer ) {
			return array(
				'name'   => $name,
				'status' => 'cleared',
				'detail' => '',
			);
		}
		return array(
			'name'   => $name,
			'status' => 'failed',
			'detail' => is_string( $answer ) && '' !== $answer ? $answer : 'It did not say it worked.',
		);
	}

	/**
	 * The cache tools installed on this site (P39b), null for any that are
	 * not. Elementor has already loaded these classes by the time a REST
	 * request runs, so the old copy in memory does the clearing.
	 *
	 * @return array<string, callable|null>
	 */
	private static function cache_tools() {
		$tools = array(
			'elementor_files'   => null,
			'elementor_library' => null,
			'wp_rocket'         => null,
			'rocket_cdn'        => null,
			'mute_cdn'          => null,
		);
		$files = class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ? \Elementor\Plugin::$instance->files_manager : null;
		if ( null !== $files ) {
			$tools['elementor_files'] = static function () use ( $files ) {
				$files->clear_cache();
				return true;
			};
		}
		if ( class_exists( '\Elementor\Api' ) && method_exists( '\Elementor\Api', 'get_library_data' ) ) {
			// What the Sync Library button does: a forced fetch.
			$tools['elementor_library'] = static function () {
				return array() !== \Elementor\Api::get_library_data( true ) ? true : 'Elementor could not fetch its template library.';
			};
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			$tools['wp_rocket'] = static function () {
				rocket_clean_domain();
				if ( function_exists( 'rocket_clean_minify' ) ) {
					rocket_clean_minify();
				}
				return true;
			};
		}
		if ( class_exists( 'CDN_Clear_Cache_Hooks' ) && method_exists( 'CDN_Clear_Cache_Hooks', 'purge_cache' ) ) {
			$tools['rocket_cdn'] = static function () {
				if ( class_exists( 'CDN_Clear_Cache_Request_Guard' ) && CDN_Clear_Cache_Request_Guard::disabled() ) {
					return 'Rocket.net has switched off purges from this site. Delete api_requests_disabled in the CDN plugin folder to allow them again.';
				}
				$answer = CDN_Clear_Cache_Hooks::purge_cache();
				if ( is_object( $answer ) && ! empty( $answer->success ) ) {
					return true;
				}
				return is_object( $answer ) && ! empty( $answer->messages ) && is_array( $answer->messages )
					? 'Rocket.net said: ' . implode( ', ', array_map( 'strval', $answer->messages ) )
					: 'Rocket.net did not confirm the purge.';
			};
			$tools['mute_cdn']   = static function ( $mute ) {
				static $removed = array();
				if ( ! $mute ) {
					foreach ( $removed as $hook ) {
						add_action( $hook[0], $hook[1], PHP_INT_MAX );
					}
					$removed = array();
					return;
				}
				$cdn = CDN_Clear_Cache_Hooks::get_instance();
				foreach ( array(
					array( 'elementor/core/files/clear_cache', array( $cdn, 'purge_cache' ) ),
					array( 'after_rocket_clean_domain', array( $cdn, 'purge_wp_rocket_cache_clear' ) ),
					array( 'after_rocket_clean_post', array( $cdn, 'purge_cache_queue' ) ),
					array( 'set_transient_rocket_preload_complete', array( $cdn, 'purge_cache' ) ),
				) as $hook ) {
					if ( remove_action( $hook[0], $hook[1], PHP_INT_MAX ) ) {
						$removed[] = $hook;
					}
				}
			};
		}
		return $tools;
	}

	/**
	 * Remove the rollback stores agents before 1.0.7 kept: zipped copies of
	 * plugins and themes under uploads, where some web servers would serve
	 * them. Runs inside an update, so only after a person's click (A7).
	 *
	 * @return void
	 */
	private static function remove_old_stores() {
		$dirs = self::$old_store_dirs;
		if ( null === $dirs ) {
			// false: do not create this month's uploads folder as a side effect.
			$uploads = wp_upload_dir( null, false );
			$base    = empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ? (string) $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
			$dirs    = array( $base . '/foundry-site-manager', WP_CONTENT_DIR . '/sitemanager-rollback' );
		}
		foreach ( $dirs as $dir ) {
			self::remove_tree( $dir );
		}
	}

	/**
	 * Delete a file or directory tree.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	private static function remove_tree( $path ) {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( (array) scandir( $path ) as $entry ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					self::remove_tree( $path . '/' . $entry );
				}
			}
			rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- removing the agent's own old store.
		} elseif ( file_exists( $path ) || is_link( $path ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- removing the agent's own old store.
		}
	}

	/**
	 * Shutdown hook: remember a fatal error so the report can show it (P28).
	 *
	 * @return void
	 */
	public static function capture_fatal() {
		$err = error_get_last();
		if ( null === $err || ! in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			return;
		}
		update_option(
			self::LAST_FATAL_OPT,
			array(
				'at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'message' => self::redact( (string) $err['message'] ),
				'file'    => (string) $err['file'],
				'line'    => (int) $err['line'],
			),
			false
		);
	}
}
