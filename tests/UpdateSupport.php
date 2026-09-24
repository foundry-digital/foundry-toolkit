<?php
/**
 * Shared fixture for the update tests: a fake site on disk
 * under the system temp directory, mocked WordPress functions, and helpers
 * to build requests.
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

abstract class UpdateSupport extends TestCase {

	/** @var array<string, mixed> */
	protected $transients = array();
	/** @var array<string, mixed> */
	protected $site_transients = array();
	/** @var SM_Test_Upgrader|null */
	protected $upgrader = null;
	/** @var string */
	protected $root = '';
	/** @var array<string, array<string, string>> */
	protected $plugins = array();
	/** @var array<string, string> */
	protected $theme_versions = array();
	/** @var string */
	protected $core_version = '6.8.2';
	/** @var array<string, bool> Active plugins, by file. */
	protected $active = array();
	/** @var array<string, bool> Network-active plugins, by file. */
	protected $network_active = array();
	/** @var array<int, array<int, mixed>> Calls to activate_plugin. */
	protected $activations = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->root = sys_get_temp_dir() . '/sm-agent-tests-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/wp-content/plugins', 0777, true );
		mkdir( $this->root . '/wp-content/themes', 0777, true );
		SiteManager_Agent::$old_store_dirs   = array();
		SiteManager_Agent::$plugin_dir       = $this->root . '/wp-content/plugins';
		SiteManager_Agent::$updates_override = true;
		SiteManager_Agent::$clock            = static fn() => 1790000000;
		$this->transients                    = array();
		$this->site_transients               = array();
		$this->plugins                       = array(
			'gravityforms/gravityforms.php' => array(
				'Name'    => 'Gravity Forms',
				'Version' => '2.9.12',
			),
			'hello.php'                     => array(
				'Name'    => 'Hello Dolly',
				'Version' => '1.7.2',
			),
		);
		$this->theme_versions                = array( 'achk27' => '1.4.0' );
		$this->write_plugin( 'gravityforms/gravityforms.php', '2.9.12' );
		$this->write_plugin( 'hello.php', '1.7.2' );
		$this->write_theme( 'achk27', '1.4.0' );
		$this->site_transients['update_plugins'] = (object) array(
			'response' => array(
				'gravityforms/gravityforms.php' => (object) array(
					'new_version' => '2.9.13',
					'package'     => 'https://example.test/gf.zip?key=SECRET',
				),
			),
		);
		$this->site_transients['update_themes']  = (object) array( 'response' => array( 'achk27' => array( 'new_version' => '1.5.0' ) ) );
		$this->upgrader                          = null;
		Automatic_Upgrader_Skin::$log            = array();
		SiteManager_Agent::$admin_loader         = function () {
			Automatic_Upgrader_Skin::$log[] = 'load';
		};
		SiteManager_Agent::$upgrader_factory     = function ( string $type, Automatic_Upgrader_Skin $skin ) {
			$this->upgrader = new SM_Test_Upgrader( $skin );
			$skin->messages = array( 'Downloading update from https://example.test/gf.zip?key=SECRET', 'Unpacking the update...', 'Plugin updated successfully.' );
			return $this->upgrader;
		};

		Functions\when( 'get_transient' )->alias( fn( string $k ) => $this->transients[ $k ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $k, $v ): bool {
				$this->transients[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $k ): bool {
				unset( $this->transients[ $k ] );
				return true;
			}
		);
		Functions\when( 'get_site_transient' )->alias( fn( string $k ) => $this->site_transients[ $k ] ?? false );
		Functions\when( 'get_plugins' )->alias( fn() => $this->plugins );
		$this->active         = array( 'gravityforms/gravityforms.php' => true );
		$this->network_active = array();
		$this->activations    = array();
		Functions\when( 'is_plugin_active' )->alias( fn( string $f ) => ! empty( $this->active[ $f ] ) || ! empty( $this->network_active[ $f ] ) );
		Functions\when( 'is_plugin_active_for_network' )->alias( fn( string $f ) => ! empty( $this->network_active[ $f ] ) );
		Functions\when( 'is_multisite' )->alias( fn() => ! empty( $this->network_active ) );
		Functions\when( 'activate_plugin' )->alias(
			function ( string $f, string $redirect = '', bool $network = false, bool $silent = false ) {
				$this->activations[] = array( $f, $network, $silent );
				if ( $network ) {
					$this->network_active[ $f ] = true;
				} else {
					$this->active[ $f ] = true;
				}
				return null;
			}
		);
		Functions\when( 'get_plugin_data' )->alias(
			function ( string $file ): array {
				$src = (string) file_get_contents( $file );
				preg_match( '/Version:\s*([^\s]+)/', $src, $m );
				return array( 'Version' => $m[1] ?? '' );
			}
		);
		Functions\when( 'wp_get_theme' )->alias(
			function ( $slug = null ) {
				$slug = (string) $slug;
				$file = $this->root . '/wp-content/themes/' . $slug . '/style.css';
				if ( ! is_file( $file ) ) {
					return new SM_Test_Theme( $slug, '', false );
				}
				preg_match( '/Version:\s*([^\s]+)/', (string) file_get_contents( $file ), $m );
				return new SM_Test_Theme( $slug, $m[1] ?? '', true );
			}
		);
		Functions\when( 'wp_clean_themes_cache' )->justReturn( null );
		Functions\when( 'get_core_updates' )->justReturn(
			array(
				(object) array(
					'response' => 'upgrade',
					'version'  => '6.9',
					'locale'   => 'en_AU',
				),
			)
		);
		Functions\when( 'wp_get_translation_updates' )->justReturn(
			array(
				(object) array(
					'type' => 'plugin',
					'slug' => 'gravityforms',
				),
			)
		);
		Functions\when( 'get_bloginfo' )->alias( fn() => $this->core_version );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
	}

	protected function tearDown(): void {
		SiteManager_Agent::$old_store_dirs   = null;
		SiteManager_Agent::$plugin_dir       = null;
		SiteManager_Agent::$updates_override = null;
		SiteManager_Agent::$upgrader_factory = null;
		SiteManager_Agent::$admin_loader     = null;
		SiteManager_Agent::$clock            = null;
		$this->rm( $this->root );
		Monkey\tearDown();
		parent::tearDown();
	}

	protected function rm( string $path ): void {
		if ( is_dir( $path ) ) {
			foreach ( (array) scandir( $path ) as $f ) {
				if ( '.' !== $f && '..' !== $f ) {
					$this->rm( $path . '/' . $f );
				}
			}
			rmdir( $path );
		} elseif ( is_file( $path ) ) {
			unlink( $path );
		}
	}

	protected function write_plugin( string $file, string $version ): void {
		$path = $this->root . '/wp-content/plugins/' . $file;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, "<?php\n/**\n * Plugin Name: Test\n * Version: {$version}\n */\n" );
		if ( false !== strpos( $file, '/' ) ) {
			file_put_contents( dirname( $path ) . '/readme.txt', 'version ' . $version );
		}
	}

	protected function write_theme( string $slug, string $version ): void {
		$dir = $this->root . '/wp-content/themes/' . $slug;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $dir . '/style.css', "/*\nTheme Name: Test\nVersion: {$version}\n*/\n" );
	}

	/** @param mixed $body Body, encoded as JSON unless a string. */
	protected function post( string $route, $body ): WP_REST_Request {
		$raw = is_string( $body ) ? $body : (string) wp_json_encode_test( $body );
		return new WP_REST_Request( 'POST', '/sitemanager/v1/' . $route, array( 'X-SM-Nonce' => str_repeat( 'a', 32 ) ), $raw );
	}

	/** @param mixed $response A WP_Error or WP_REST_Response. */
	protected function code( $response ): string {
		return $response instanceof WP_Error ? $response->get_error_code() : 'ok';
	}

	/** @param mixed $response A WP_Error. */
	protected function status( $response ): int {
		$this->assertInstanceOf( WP_Error::class, $response );
		$data = $response->get_error_data();
		return is_array( $data ) ? (int) $data['status'] : 0;
	}

	/**
	 * The names in a directory, sorted, without the dot entries.
	 *
	 * @return string[]
	 */
	protected function entries( string $dir ): array {
		$names = array_values( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) );
		sort( $names );
		return $names;
	}
}

/** Minimal theme object for the update tests. */
class SM_Test_Theme {
	/** @var string */
	public $slug;
	/** @var string */
	public $version;
	/** @var bool */
	public $exists;

	public function __construct( string $slug, string $version, bool $exists ) {
		$this->slug    = $slug;
		$this->version = $version;
		$this->exists  = $exists;
	}

	public function exists(): bool {
		return $this->exists;
	}

	public function get( string $key ): string {
		return 'Version' === $key ? $this->version : '';
	}

	public function get_stylesheet(): string {
		return $this->slug;
	}
}

/**
 * @param mixed $v Value.
 * @return string|false
 */
function wp_json_encode_test( $v ) {
	return json_encode( $v ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}
