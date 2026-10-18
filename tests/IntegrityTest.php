<?php
/**
 * GET /integrity: core and WordPress.org plugin files against their
 * official checksums (P64, P65, S14).
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

require_once __DIR__ . '/UpdateSupport.php';
require_once __DIR__ . '/../includes/class-foundry-toolkit-integrity.php';

/** @covers Foundry_Toolkit_Integrity */
final class IntegrityTest extends UpdateSupport {

	/** @var string */
	private $site_root = '';

	/** @var array<string, string> */
	private $core = array();

	/** @var array<string, array<string, string>|null> */
	private $plugin_sums = array();

	/** @var array<string, string> */
	private $installed = array();

	/** @var string[] */
	private $fetched = array();

	/** @var float */
	private $time = 0.0;

	/** @var array<string, mixed> */
	private $options = array();

	/** @var array<string, string> */
	private $themes = array();

	/** @var string[] Plugins whose checksum request fails, as when WordPress.org cannot be reached. */
	private $failing = array();

	/** @var bool Whether the core checksum request fails. */
	private $core_down = false;

	/** @var callable|null Runs during each plugin checksum request. */
	private $during_fetch = null;

	protected function setUp(): void {
		parent::setUp();
		$this->site_root    = sys_get_temp_dir() . '/sm-integrity-' . uniqid() . '/';
		$this->fetched      = array();
		$this->time         = 0.0;
		$this->options      = array();
		$this->themes       = array();
		$this->failing      = array();
		$this->core_down    = false;
		$this->during_fetch = null;
		Functions\when( 'get_option' )->alias( fn( string $k, $d = false ) => $this->options[ $k ] ?? $d );
		Functions\when( 'update_option' )->alias(
			function ( string $k, $v ): bool {
				$this->options[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.2' );
		Functions\when( 'get_locale' )->justReturn( 'en_AU' );

		// Core: two files as shipped, the version file and a readme hardening removed.
		$this->core = array(
			'wp-includes/version.php' => $this->put( 'wp-includes/version.php', '<?php $wp_version = "6.8.2";' ),
			'wp-admin/index.php'      => $this->put( 'wp-admin/index.php', '<?php // admin' ),
			'wp-login.php'            => $this->put( 'wp-login.php', '<?php // login' ),
			'readme.html'             => md5( 'gone' ),
			'wp-content/index.php'    => md5( 'not ours to check' ),
		);
		// Akismet from WordPress.org; a premium plugin with no checksums.
		$this->plugin_sums = array(
			'akismet'   => array(
				'akismet.php'       => $this->put( 'wp-content/plugins/akismet/akismet.php', '<?php // akismet' ),
				'class.akismet.php' => $this->put( 'wp-content/plugins/akismet/class.akismet.php', '<?php // class' ),
			),
			'wp-rocket' => null,
		);
		$this->put( 'wp-content/plugins/wp-rocket/wp-rocket.php', '<?php // premium' );
		$this->installed = array(
			'akismet/akismet.php'     => '5.3',
			'wp-rocket/wp-rocket.php' => '3.18.1',
			'hello.php'               => '1.7.2',
		);

		Foundry_Toolkit_Integrity::$root             = $this->site_root;
		Foundry_Toolkit_Integrity::$plugin_root      = $this->site_root . 'wp-content/plugins';
		Foundry_Toolkit_Integrity::$core_checksums   = fn( string $v, string $l ) => ! $this->core_down && 'en_US' === $l && '6.8.2' === $v ? $this->core : false;
		Foundry_Toolkit_Integrity::$plugin_checksums = function ( string $slug, string $v ) {
			$this->fetched[] = $slug;
			$this->time     += 15.0; // Each plugin takes 15 seconds on this slow host.
			if ( null !== $this->during_fetch ) {
				call_user_func( $this->during_fetch );
			}
			if ( in_array( $slug, $this->failing, true ) ) {
				return false;
			}
			return $this->plugin_sums[ $slug ] ?? null;
		};
		Foundry_Toolkit_Integrity::$plugins          = fn() => $this->installed;
		Foundry_Toolkit_Integrity::$clock            = fn() => $this->time;
		Foundry_Toolkit_Integrity::$themes           = fn() => $this->themes;
		Foundry_Toolkit_Integrity::$theme_root       = $this->site_root . 'wp-content/themes';
		Foundry_Toolkit_Integrity::$content_root     = $this->site_root . 'wp-content';
		Foundry_Toolkit_Integrity::$uploads_root     = $this->site_root . 'wp-content/uploads';
		Foundry_Toolkit_Integrity::$recent_folders   = fn() => array();
		Foundry_Toolkit_Integrity::$dropin_names     = fn() => array( 'advanced-cache.php', 'db.php', 'object-cache.php' );
		Foundry_Toolkit_Integrity::$ini_prepend      = fn() => '';
		Foundry_Toolkit_Integrity::$admin_counts     = fn() => array( 1, 1 );
		Foundry_Toolkit_Integrity::$triggers         = fn() => array();
		Foundry_Toolkit_Integrity::$mac_key          = 'test-key';
		Foundry_Toolkit_Integrity::$local_package    = fn() => null;
	}

	protected function tearDown(): void {
		Foundry_Toolkit_Integrity::$root             = null;
		Foundry_Toolkit_Integrity::$plugin_root      = null;
		Foundry_Toolkit_Integrity::$core_checksums   = null;
		Foundry_Toolkit_Integrity::$plugin_checksums = null;
		Foundry_Toolkit_Integrity::$plugins          = null;
		Foundry_Toolkit_Integrity::$clock            = null;
		Foundry_Toolkit_Integrity::$themes           = null;
		Foundry_Toolkit_Integrity::$theme_root       = null;
		Foundry_Toolkit_Integrity::$content_root     = null;
		Foundry_Toolkit_Integrity::$uploads_root     = null;
		Foundry_Toolkit_Integrity::$recent_folders   = null;
		Foundry_Toolkit_Integrity::$dropin_names     = null;
		Foundry_Toolkit_Integrity::$ini_prepend      = null;
		Foundry_Toolkit_Integrity::$admin_counts     = null;
		Foundry_Toolkit_Integrity::$triggers         = null;
		Foundry_Toolkit_Integrity::$mac_key          = null;
		Foundry_Toolkit_Integrity::$local_package    = null;
		$this->rm( rtrim( $this->site_root, '/' ) );
		parent::tearDown();
	}

	/** Write a file under the fake root and return its MD5. */
	private function put( string $path, string $contents ): string {
		$file = $this->site_root . $path;
		if ( ! is_dir( dirname( $file ) ) ) {
			mkdir( dirname( $file ), 0777, true );
		}
		file_put_contents( $file, $contents );
		return md5( $contents );
	}

	public function test_a_clean_site_has_nothing_to_report(): void {
		$out = Foundry_Toolkit_Integrity::run();
		$this->assertTrue( $out['ok'] );
		$this->assertTrue( $out['complete'] );
		$this->assertSame(
			array(
				'checked' => true,
				'files'   => 3,
			),
			$out['core'],
			'an English package is checked against en_US; readme and wp-content are skipped'
		);
		$this->assertSame(
			array(
				array(
					'slug'    => 'akismet',
					'version' => '5.3',
					'checked' => true,
					'files'   => 2,
				),
				array(
					'slug'    => 'wp-rocket',
					'version' => '3.18.1',
					'checked' => false,
					'files'   => 0,
				),
			),
			$out['plugins'],
			'a single-file plugin is skipped; a premium one is not checked'
		);
		$this->assertSame( array(), $out['modified'] );
		$this->assertSame( array(), $out['missing'] );
		$this->assertSame( array(), $out['unexpected'] );
	}

	public function test_reports_relative_paths_only(): void {
		$this->put( 'wp-includes/version.php', '<?php // hacked' );
		unlink( $this->site_root . 'wp-admin/index.php' );
		$this->put( 'wp-includes/css/class-wp-backdoor.php', '<?php eval($_POST[1]);' );
		$this->put( 'wp-content/plugins/akismet/akismet.php', '<?php // changed' );
		$this->put( 'wp-content/plugins/akismet/views/shell.php', '<?php // dropped' );
		$this->put( 'wp-content/plugins/wp-rocket/extra.php', '<?php // premium, never judged' );
		$out = Foundry_Toolkit_Integrity::run();
		$this->assertSame( array( 'wp-includes/version.php', 'wp-content/plugins/akismet/akismet.php' ), $out['modified'] );
		$this->assertSame( array( 'wp-admin/index.php' ), $out['missing'] );
		$this->assertSame( array( 'wp-includes/css/class-wp-backdoor.php', 'wp-content/plugins/akismet/views/shell.php' ), $out['unexpected'] );
		$json = (string) json_encode( $out );
		$this->assertStringNotContainsString( $this->site_root, $json, 'S14: no absolute path' );
		$this->assertStringNotContainsString( 'eval', $json, 'S14: no file contents' );
		$this->assertStringNotContainsString( md5( '<?php // hacked' ), $json, 'S14: no computed checksum' );
	}

	public function test_takes_no_parameters_and_waits_for_an_update(): void {
		$request = new WP_REST_Request( 'GET', '/sitemanager/v1/integrity', array(), '{"path":"/etc/passwd"}' );
		$r       = Foundry_Toolkit_Integrity::route( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $r );
		$this->assertStringNotContainsString( 'passwd', (string) json_encode( $r->get_data() ), 'S14: the request chooses nothing' );
		$this->assertSame( 'no-store', $r->headers['Cache-Control'] ?? '' );

		$this->transients['sm_update_lock'] = 'nonce';
		$busy                               = Foundry_Toolkit_Integrity::route( $request );
		$this->assertInstanceOf( WP_Error::class, $busy );
		$this->assertSame( 'sm_busy', $busy->get_error_code() );
	}

	public function test_item_files_lists_one_installed_item(): void {
		$this->put( 'wp-content/plugins/wp-rocket/inc/Engine.php', '<?php // engine' );
		$this->put( 'wp-content/plugins/wp-rocket/assets/app.js', 'js' );
		$this->put( 'wp-content/plugins/wp-rocket/assets/app.css', 'never watched' );
		$this->put( 'wp-content/themes/hello/functions.php', '<?php // theme' );
		$this->themes = array( 'hello' => '3.5.1' );

		$r = Foundry_Toolkit_Integrity::files_route( new WP_REST_Request( 'GET', '/sitemanager/v1/integrity/files/plugin/wp-rocket', array(), '' ) );
		$this->assertInstanceOf( WP_REST_Response::class, $r );
		$this->assertSame( 'no-store', $r->headers['Cache-Control'] ?? '' );
		$data = $r->get_data();
		$this->assertSame( array( 'plugin', 'wp-rocket', '3.18.1', false ), array( $data['kind'], $data['slug'], $data['version'], $data['truncated'] ) );
		$this->assertSame(
			array(
				'assets/app.js'  => md5( 'js' ),
				'inc/Engine.php' => md5( '<?php // engine' ),
				'wp-rocket.php'  => md5( '<?php // premium' ),
			),
			(array) $data['files'],
			'paths relative to the plugin, watched extensions only'
		);
		$json = (string) json_encode( $data );
		$this->assertStringNotContainsString( $this->site_root, $json, 'S14: no absolute path' );
		$this->assertStringNotContainsString( 'engine', $json, 'S14: no file contents' );

		$theme = Foundry_Toolkit_Integrity::item_files( 'theme', 'hello' );
		$this->assertNotNull( $theme );
		$this->assertSame( array( 'functions.php' => md5( '<?php // theme' ) ), (array) $theme['files'] );
		$this->assertSame( '3.5.1', $theme['version'] );
	}

	public function test_item_files_reads_only_what_is_installed(): void {
		$this->put( 'wp-content/plugins/removed/removed.php', '<?php // on disk, not installed' );
		foreach ( array( 'plugin/removed', 'plugin/..', 'plugin/.', 'plugin/hello.php', 'theme/akismet', 'core/akismet', 'plugin/akismet/../wp-rocket' ) as $item ) {
			$r = Foundry_Toolkit_Integrity::files_route( new WP_REST_Request( 'GET', '/sitemanager/v1/integrity/files/' . $item, array(), '' ) );
			$this->assertInstanceOf( WP_Error::class, $r, $item );
			$this->assertSame( 'sm_not_installed', $r->get_error_code(), $item );
		}

		$this->transients['sm_update_lock'] = 'nonce';
		$busy                               = Foundry_Toolkit_Integrity::files_route( new WP_REST_Request( 'GET', '/sitemanager/v1/integrity/files/plugin/akismet', array(), '' ) );
		$this->assertInstanceOf( WP_Error::class, $busy );
		$this->assertSame( 'sm_busy', $busy->get_error_code() );
	}

	public function test_budget_stops_and_the_cache_carries_on(): void {
		// Each fetch takes 15 seconds; no new plugin starts after 20.
		$first = Foundry_Toolkit_Integrity::run();
		$this->assertTrue( $first['complete'], 'akismet at 0s and wp-rocket at 15s both start' );
		$this->time = 0.0;
		$this->installed['contact-form-7/wp-contact-form-7.php'] = '6.0';
		$this->installed['wordpress-seo/wp-seo.php']             = '28.6';
		$this->installed['woocommerce/woocommerce.php']          = '10.2';
		$second = Foundry_Toolkit_Integrity::run();
		$this->assertFalse( $second['complete'], 'woocommerce would start at 30s' );
		$this->assertSame( array( 'akismet', 'wp-rocket', 'contact-form-7', 'wordpress-seo' ), $this->fetched, 'akismet and wp-rocket came from the cache the second time' );
		$this->time = 0.0;
		$third      = Foundry_Toolkit_Integrity::run();
		$this->assertTrue( $third['complete'] );
		$this->assertSame( array( 'akismet', 'wp-rocket', 'contact-form-7', 'wordpress-seo', 'woocommerce' ), $this->fetched, 'the third call picks up where the second stopped' );
	}

	public function test_lists_are_capped(): void {
		for ( $i = 0; $i < 205; $i++ ) {
			$this->put( sprintf( 'wp-includes/drop-%03d.php', $i ), '<?php' );
		}
		$out = Foundry_Toolkit_Integrity::run();
		$this->assertCount( Foundry_Toolkit_Integrity::MAX_PATHS, $out['unexpected'] );
		$this->assertTrue( $out['truncated'] );
	}

	/** Forget the cached results, as fifteen minutes passing would; the checksum lists last a week. */
	private function expire(): void {
		foreach ( array_keys( $this->transients ) as $k ) {
			if ( 0 === strpos( (string) $k, 'sm_integrity_' ) && 0 !== strpos( (string) $k, 'sm_integrity_sums_' ) ) {
				unset( $this->transients[ $k ] );
			}
		}
	}

	/**
	 * 1.7.0: a premium plugin and a theme are checked against their own first
	 * copy. The first check records it; a later one names what changed
	 * while the version stayed; a new version records a new baseline.
	 */
	public function test_premium_and_themes_against_their_first_copy(): void {
		$this->put( 'wp-content/plugins/wp-rocket/inc/cache.php', '<?php // cache' );
		$this->put( 'wp-content/plugins/wp-rocket/assets/app.js', 'console.log(1)' );
		$this->put( 'wp-content/plugins/wp-rocket/assets/logo.png', 'PNG' );
		$this->put( 'wp-content/themes/hello-child/functions.php', '<?php // child' );
		$this->themes                     = array( 'hello-child' => '1.0' );
		Foundry_Toolkit_Integrity::$clock = fn() => 0.0; // No budget pressure here.

		$first  = Foundry_Toolkit_Integrity::run();
		$others = array();
		foreach ( $first['others'] as $o ) {
			$others[ $o['kind'] . '/' . $o['slug'] ] = $o;
		}
		$this->assertSame( 'recorded', $others['plugin/wp-rocket']['baseline'] );
		$this->assertSame( 3, $others['plugin/wp-rocket']['files'], 'php and js only, no png' );
		$this->assertSame( 'recorded', $others['theme/hello-child']['baseline'] );
		$this->assertSame( 64, strlen( $others['plugin/wp-rocket']['fingerprint'] ) );
		$this->assertSame( array(), $first['modified'] );

		$this->put( 'wp-content/plugins/wp-rocket/inc/cache.php', '<?php eval($_GET[1]);' );
		$this->put( 'wp-content/plugins/wp-rocket/inc/x.php', '<?php // dropped' );
		$this->put( 'wp-content/plugins/wp-rocket/assets/logo.png', 'changed image, never counted' );
		unlink( $this->site_root . 'wp-content/themes/hello-child/functions.php' );
		$this->expire();
		$second = Foundry_Toolkit_Integrity::run();
		$this->assertSame( array( 'wp-content/plugins/wp-rocket/inc/cache.php' ), $second['modified'] );
		$this->assertSame( array( 'wp-content/plugins/wp-rocket/inc/x.php' ), $second['unexpected'] );
		$this->assertSame( array( 'wp-content/themes/hello-child/functions.php' ), $second['missing'] );
		$this->assertNotSame( $others['plugin/wp-rocket']['fingerprint'], $second['others'][0]['fingerprint'] );
		$json = (string) json_encode( $second );
		$this->assertStringNotContainsString( md5( '<?php eval($_GET[1]);' ), $json, 'S14: no per-file checksum' );

		$this->installed['wp-rocket/wp-rocket.php'] = '3.19.0';
		$this->expire();
		$third = Foundry_Toolkit_Integrity::run();
		$this->assertSame( 'recorded', $third['others'][0]['baseline'], 'a new version records a new baseline' );
		$this->assertNotContains( 'wp-content/plugins/wp-rocket/inc/cache.php', $third['modified'] );
	}

	/** Identical copies give identical fingerprints, whatever order the disk lists them in. */
	public function test_fingerprint_is_stable(): void {
		$this->put( 'wp-content/plugins/wp-rocket/b.php', '<?php // b' );
		$this->put( 'wp-content/plugins/wp-rocket/a.php', '<?php // a' );
		$one = Foundry_Toolkit_Integrity::run()['others'][0]['fingerprint'];
		$this->expire();
		$this->options = array();
		$this->assertSame( $one, Foundry_Toolkit_Integrity::run()['others'][0]['fingerprint'] );
	}

	/**
	 * 1.8.0: WordPress.org not answering is not "premium". The plugin is left
	 * for the next call: no result, no baseline of whatever is on disk.
	 */
	public function test_a_failed_checksum_request_records_nothing(): void {
		Foundry_Toolkit_Integrity::$clock = fn() => 0.0;
		$this->failing                    = array( 'akismet' );
		$this->put( 'wp-content/plugins/akismet/views/shell.php', '<?php // dropped' );
		$out = Foundry_Toolkit_Integrity::run();
		$this->assertFalse( $out['complete'], 'the app asks again' );
		$this->assertSame( array( 'wp-rocket' ), array_column( $out['plugins'], 'slug' ), 'akismet was not reached' );
		$this->assertSame( array( 'wp-rocket' ), array_column( $out['others'], 'slug' ), 'akismet is not treated as premium' );
		$this->assertCount( 1, $this->options, 'only wp-rocket has a baseline' );

		$this->failing = array();
		$again         = Foundry_Toolkit_Integrity::run();
		$this->assertTrue( $again['complete'] );
		$this->assertSame( array( 'wp-content/plugins/akismet/views/shell.php' ), $again['unexpected'], 'the failure was not cached' );
	}

	/** 1.8.0: a version's checksum list never changes, so it is fetched once a week, as is "WordPress.org has none". */
	public function test_checksum_lists_outlive_the_results(): void {
		Foundry_Toolkit_Integrity::$clock = fn() => 0.0;
		Foundry_Toolkit_Integrity::run();
		$this->expire();
		$this->put( 'wp-content/plugins/akismet/akismet.php', '<?php // changed' );
		$out = Foundry_Toolkit_Integrity::run();
		$this->assertSame( array( 'akismet', 'wp-rocket' ), $this->fetched, 'no second request for either' );
		$this->assertSame( array( 'wp-content/plugins/akismet/akismet.php' ), $out['modified'], 'the files were read again' );
	}

	/** 1.8.0: core checksums that could not be fetched are asked for again on the next call. */
	public function test_unchecked_core_is_not_cached(): void {
		$this->core_down = true;
		$this->assertFalse( Foundry_Toolkit_Integrity::run()['core']['checked'] );
		$this->core_down = false;
		$this->assertTrue( Foundry_Toolkit_Integrity::run()['core']['checked'] );
	}

	/** 1.8.0: a directory PHP cannot open is skipped, not a fatal error. */
	public function test_an_unreadable_directory_is_skipped(): void {
		$this->put( 'wp-content/plugins/akismet/locked/a.php', '<?php' );
		$this->put( 'wp-content/plugins/wp-rocket/locked/a.php', '<?php' );
		$locked = array( $this->site_root . 'wp-content/plugins/akismet/locked', $this->site_root . 'wp-content/plugins/wp-rocket/locked' );
		array_map( fn( string $d ) => chmod( $d, 0 ), $locked );
		try {
			$out = Foundry_Toolkit_Integrity::run();
		} finally {
			array_map( fn( string $d ) => chmod( $d, 0755 ), $locked );
		}
		$this->assertTrue( $out['ok'] );
		$this->assertSame( array(), $out['unexpected'] );
	}

	/** 1.8.0: an update that starts while the files are being read makes the answer unusable. */
	public function test_an_update_starting_mid_check_is_busy(): void {
		$this->during_fetch = function (): void {
			$this->transients['sm_update_lock'] = 'nonce';
		};
		$r                  = Foundry_Toolkit_Integrity::route( new WP_REST_Request( 'GET', '/sitemanager/v1/integrity', array(), '' ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'sm_busy', $r->get_error_code() );
	}

	/**
	 * 1.8.0: a list is kept a week, but "WordPress.org has none" only a day:
	 * its checksums can appear a little after a release.
	 */
	public function test_no_checksums_is_asked_again_after_a_day(): void {
		$ttl = array();
		Functions\when( 'set_transient' )->alias(
			function ( string $k, $v, int $t = 0 ) use ( &$ttl ): bool {
				$this->transients[ $k ] = $v;
				if ( 0 === strpos( $k, 'sm_integrity_sums_' ) ) {
					$ttl[ is_array( $v['v'] ) ? 'list' : 'none' ] = $t;
				}
				return true;
			}
		);
		Foundry_Toolkit_Integrity::$clock = fn() => 0.0;
		Foundry_Toolkit_Integrity::run();
		$this->assertSame(
			array(
				'list' => 604800,
				'none' => 86400,
			),
			$ttl
		);
	}

	/**
	 * 1.9.0: a dropped file that the server may run as PHP is unexpected
	 * whatever its extension, not only .php.
	 */
	public function test_unexpected_files_of_every_php_extension(): void {
		Foundry_Toolkit_Integrity::$clock = fn() => 0.0;
		foreach ( array( 'a.phtml', 'b.phar', 'c.php5', 'd.PHP7', 'e.pht', 'f.txt', 'g.png' ) as $name ) {
			$this->put( 'wp-includes/' . $name, 'x' );
			$this->put( 'wp-content/plugins/akismet/' . $name, 'x' );
		}
		$out = Foundry_Toolkit_Integrity::run();
		$this->assertSame(
			array(
				'wp-includes/a.phtml',
				'wp-includes/b.phar',
				'wp-includes/c.php5',
				'wp-includes/d.PHP7',
				'wp-includes/e.pht',
				'wp-content/plugins/akismet/a.phtml',
				'wp-content/plugins/akismet/b.phar',
				'wp-content/plugins/akismet/c.php5',
				'wp-content/plugins/akismet/d.PHP7',
				'wp-content/plugins/akismet/e.pht',
			),
			$out['unexpected']
		);
	}

	/**
	 * 1.9.0 (P67): the places no checksum list covers, where self-healing
	 * malware hides its copies.
	 */
	public function test_places_finds_where_malware_hides(): void {
		Foundry_Toolkit_Integrity::$clock = fn() => 0.0;
		$this->put( 'wp-config.php', '<?php // config' );
		$this->put( 'wp-conflg.php', '<?php // a lookalike' );
		$this->put( 'wp-content/index.php', "<?php\n// Silence is golden.\n" );
		$this->put( 'wp-content/.cache-a1b2.php', '<?php // hidden loader' );
		$this->put( 'wp-content/x.phtml', '<?php // shim' );
		$this->put( 'wp-content/advanced-cache.php', '<?php // drop-in' );
		$this->put( 'wp-content/deadbeefdeadbeef00.zip', 'PK' );
		$this->put( 'wp-content/uploads/2024/01/photo.php', '<?php // dropped' );
		$this->put( 'wp-content/uploads/index.php', '<?php // Silence is golden.' );
		$this->put( 'wp-content/uploads/2024/index.php', '<?php eval($_POST[1]);' );
		$this->put( 'wp-content/uploads/big/index.php', '<?php ' . str_repeat( '// padding ', 20 ) );
		$this->put( 'wp-content/uploads/2024/01/photo.jpg', 'JPEG' );
		$this->put( 'wp-content/uploads/0123456789abcdef.zip', 'PK' );
		$this->put( 'wp-content/uploads/backup.zip', 'PK' );
		$this->put( 'wp-content/mu-plugins/loader.php', '<?php // loader' );
		$this->put( 'wp-content/mu-plugins/sub/inner.php', '<?php // inner' );
		$this->put( 'wp-content/mu-plugins/akismet-copy.php', '<?php // akismet' );
		$this->put( '.user.ini', "memory_limit = 256M\nauto_prepend_file = '" . $this->site_root . ".hidden.php'\n" );
		$this->put( '.htaccess', "php_value auto_prepend_file /var/elsewhere/x.php\n" );
		Foundry_Toolkit_Integrity::$ini_prepend  = fn() => $this->site_root . 'wordfence-waf.php';
		Foundry_Toolkit_Integrity::$admin_counts = fn() => array( 3, 2 );
		Foundry_Toolkit_Integrity::$triggers     = fn() => array( 'wp_users' );

		$p = Foundry_Toolkit_Integrity::run()['places'];
		$this->assertTrue( $p['complete'] );
		$this->assertSame(
			array(
				'wp-conflg.php',
				'wp-content/.cache-a1b2.php',
				'wp-content/uploads/2024/01/photo.php',
				'wp-content/uploads/2024/index.php',
				'wp-content/uploads/big/index.php',
				'wp-content/x.phtml',
			),
			array_column( $p['loose'], 'file' ),
			'core files, wp-config.php, the drop-in and empty index.php stubs are not loose; a small index.php with code is'
		);
		$this->assertSame( hash( 'sha256', '<?php // a lookalike' ), $p['loose'][0]['fingerprint'] );
		$this->assertSame( array( 'wp-content/deadbeefdeadbeef00.zip', 'wp-content/uploads/0123456789abcdef.zip' ), $p['archives'] );
		$this->assertSame(
			array(
				array(
					'file'        => 'advanced-cache.php',
					'fingerprint' => hash( 'sha256', '<?php // drop-in' ),
				),
			),
			$p['dropins']
		);
		$this->assertSame( array( 'akismet-copy.php', 'loader.php', 'sub/inner.php' ), array_column( $p['mu_plugins'], 'file' ) );
		$this->assertSame( array( 'wp-content/mu-plugins/akismet-copy.php' ), $p['copies'], 'the same file as akismet/akismet.php' );
		$this->assertSame(
			array(
				array(
					'source' => 'php',
					'target' => 'wordfence-waf.php',
				),
				array(
					'source' => '.user.ini',
					'target' => '.hidden.php',
				),
				array(
					'source' => '.htaccess',
					'target' => 'outside:x.php',
				),
			),
			$p['prepend']
		);
		$this->assertSame(
			array(
				'stored' => 3,
				'listed' => 2,
			),
			$p['administrators']
		);
		$this->assertSame( array( 'wp_users' ), $p['triggers'] );
		$this->assertFalse( $p['truncated'] );
		$json = (string) json_encode( $p );
		$this->assertStringNotContainsString( $this->site_root, $json, 'S14: no absolute path' );
		$this->assertStringNotContainsString( 'hidden loader', $json, 'S14: no file contents' );
	}

	/**
	 * 1.12.0: the loose list holds 1000 files, so a site with dozens of
	 * legitimate ones (Gravity PDF's templates and fonts) still sends a
	 * whole list and a removed file can be seen to be gone. Past 1000 it is
	 * cut and says so; the other lists stay at 50.
	 */
	public function test_loose_list_holds_a_thousand(): void {
		Foundry_Toolkit_Integrity::$clock = fn() => 0.0;
		$this->installed                  = array();
		$font                             = 'wp-content/uploads/PDF_EXTENDED_TEMPLATES/fonts/fontdata/f%04d.php';
		$zip                              = 'wp-content/uploads/%016x.zip';
		for ( $i = 0; $i < 60; $i++ ) {
			$this->put( sprintf( $font, $i ), '<?php return array();' );
			$this->put( sprintf( $zip, $i + 0x1000000000000000 ), 'PK' );
		}
		$p = Foundry_Toolkit_Integrity::run()['places'];
		$this->assertCount( 60, $p['loose'], 'sixty loose files are all listed' );
		$this->assertCount( Foundry_Toolkit_Integrity::MAX_PLACES, $p['archives'] );
		$this->assertTrue( $p['truncated'], 'the archives were cut at 50' );

		for ( $i = 0; $i < 60; $i++ ) {
			unlink( $this->site_root . sprintf( $zip, $i + 0x1000000000000000 ) );
		}
		for ( $i = 60; $i <= Foundry_Toolkit_Integrity::MAX_LOOSE; $i++ ) {
			$this->put( sprintf( $font, $i ), '<?php return array();' );
		}
		$this->expire();
		$p = Foundry_Toolkit_Integrity::run()['places'];
		$this->assertSame( 1000, Foundry_Toolkit_Integrity::MAX_LOOSE );
		$this->assertCount( Foundry_Toolkit_Integrity::MAX_LOOSE, $p['loose'] );
		$this->assertSame( sprintf( $font, 0 ), $p['loose'][0]['file'], 'sorted, then cut' );
		$this->assertTrue( $p['truncated'], 'one file past 1000 cuts the list' );

		unlink( $this->site_root . sprintf( $font, Foundry_Toolkit_Integrity::MAX_LOOSE ) );
		$this->expire();
		$p = Foundry_Toolkit_Integrity::run()['places'];
		$this->assertCount( Foundry_Toolkit_Integrity::MAX_LOOSE, $p['loose'] );
		$this->assertFalse( $p['truncated'], 'exactly 1000 is whole' );
	}

	/** 1.9.0: a huge uploads folder stops at its own limit and says so. */
	public function test_places_uploads_walk_stops_at_its_limit(): void {
		for ( $i = 0; $i < 600; $i++ ) {
			$this->put( sprintf( 'wp-content/uploads/f%03d.jpg', $i ), 'x' );
		}
		$t                                = 0.0;
		Foundry_Toolkit_Integrity::$clock = function () use ( &$t ): float {
			$t += 4.0;
			return $t;
		};
		$this->installed                  = array();
		$p                                = Foundry_Toolkit_Integrity::run()['places'];
		$this->assertFalse( $p['complete'] );
	}

	/**
	 * A clock that moves 4 seconds every time it is read, so one uploads
	 * walk reaches about 384 entries before its 10 second limit.
	 */
	private function slow_clock(): void {
		$t                                = 0.0;
		Foundry_Toolkit_Integrity::$clock = function () use ( &$t ): float {
			$t += 4.0;
			return $t;
		};
		$this->installed                  = array();
	}

	/**
	 * 1.14.0: a walk that stops at its limit carries on from there in the
	 * next call, without the places cache holding it back, until a whole
	 * pass has finished (Sydney Contemporary, 10 GB of uploads).
	 */
	public function test_uploads_walk_resumes_across_calls(): void {
		for ( $i = 0; $i < 900; $i++ ) {
			$this->put( sprintf( 'wp-content/uploads/2019/03/f%03d.jpg', $i ), 'x' );
		}
		$this->put( 'wp-content/uploads/2019/04/shell.php', '<?php // dropped' );
		$this->slow_clock();
		$calls = 0;
		do {
			$p = Foundry_Toolkit_Integrity::run()['places'];
			++$calls;
			if ( ! $p['complete'] ) {
				$this->assertSame( array(), $p['loose'], 'not reached yet' );
			}
		} while ( ! $p['complete'] && $calls < 10 );
		$this->assertTrue( $p['complete'] );
		$this->assertGreaterThan( 1, $calls, 'the walk took more than one call' );
		$this->assertSame( array( 'wp-content/uploads/2019/04/shell.php' ), array_column( $p['loose'], 'file' ) );
	}

	/**
	 * 1.14.0: once a pass has finished, places stays complete. A file the
	 * walk has not come back to yet keeps its place from the last pass, and
	 * one that has since been removed drops out at once.
	 */
	public function test_uploads_walk_keeps_the_last_pass(): void {
		for ( $i = 0; $i < 900; $i++ ) {
			$this->put( sprintf( 'wp-content/uploads/2019/03/f%03d.jpg', $i ), 'x' );
		}
		$this->put( 'wp-content/uploads/2019/04/shell.php', '<?php // dropped' );
		$this->put( 'wp-content/uploads/2019/05/other.php', '<?php // dropped too' );
		$this->slow_clock();
		for ( $calls = 0; $calls < 10; $calls++ ) {
			if ( Foundry_Toolkit_Integrity::run()['places']['complete'] ) {
				break;
			}
		}
		unlink( $this->site_root . 'wp-content/uploads/2019/04/shell.php' );
		$this->expire();
		$p = Foundry_Toolkit_Integrity::run()['places']; // A new pass, stopped in 2019/03 again.
		$this->assertTrue( $p['complete'], 'still judged from the last pass' );
		$this->assertSame( array( 'wp-content/uploads/2019/05/other.php' ), array_column( $p['loose'], 'file' ) );
	}

	/** 1.14.0: the current and previous month are walked in full on every call, before the rest. */
	public function test_recent_months_walked_every_call(): void {
		for ( $i = 0; $i < 900; $i++ ) {
			$this->put( sprintf( 'wp-content/uploads/2000/01/f%03d.jpg', $i ), 'x' );
		}
		$this->put( 'wp-content/uploads/2026/10/cache.php', '<?php // dropped today' );
		Foundry_Toolkit_Integrity::$recent_folders = fn() => array( '2026/10', '2026/09' );
		$this->slow_clock();
		$p = Foundry_Toolkit_Integrity::run()['places'];
		$this->assertFalse( $p['complete'], 'the first pass has not finished' );
		$this->assertSame( array( 'wp-content/uploads/2026/10/cache.php' ), array_column( $p['loose'], 'file' ) );
	}

	/** 1.9.0: without core checksums the root cannot be judged, so places says it is incomplete. */
	public function test_places_without_core_checksums_is_incomplete(): void {
		Foundry_Toolkit_Integrity::$clock = fn() => 0.0;
		$this->core_down                  = true;
		$this->assertFalse( Foundry_Toolkit_Integrity::run()['places']['complete'] );
	}

	/**
	 * 1.9.0: cached results and baselines are sealed with a key from
	 * wp-config.php, so one written straight into the database is ignored.
	 */
	public function test_forged_cache_and_baseline_are_ignored(): void {
		Foundry_Toolkit_Integrity::$clock                             = fn() => 0.0;
		$this->put( 'wp-content/plugins/akismet/akismet.php', '<?php // changed' );
		$this->transients[ 'sm_integrity_p_' . md5( 'akismet|5.3' ) ] = array(
			'checked'    => true,
			'files'      => 2,
			'modified'   => array(),
			'missing'    => array(),
			'unexpected' => array(),
		);
		$this->put( 'wp-content/plugins/wp-rocket/x.php', '<?php // dropped' );
		$this->options[ Foundry_Toolkit_Integrity::BASELINE_OPTION . md5( 'plugin/wp-rocket' ) ] = array(
			'version' => '3.18.1',
			'files'   => array(
				'wp-rocket.php' => md5( '<?php // premium' ),
				'x.php'         => md5( '<?php // dropped' ),
			),
		);
		$out = Foundry_Toolkit_Integrity::run();
		$this->assertContains( 'wp-content/plugins/akismet/akismet.php', $out['modified'], 'the forged clean result was ignored' );
		$this->assertSame( 'recorded', $out['others'][0]['baseline'], 'the forged baseline was replaced, so the app compares fingerprints' );
	}

	/** 1.9.0: a wp-content folder outside the WordPress root is still reported, as wp-content/. */
	public function test_content_outside_the_root_is_reported(): void {
		Foundry_Toolkit_Integrity::$clock = fn() => 0.0;
		$elsewhere                        = sys_get_temp_dir() . '/sm-content-' . uniqid();
		mkdir( $elsewhere . '/uploads', 0777, true );
		file_put_contents( $elsewhere . '/shim.php', '<?php // shim' );
		Foundry_Toolkit_Integrity::$content_root = $elsewhere;
		Foundry_Toolkit_Integrity::$uploads_root = $elsewhere . '/uploads';
		try {
			$p = Foundry_Toolkit_Integrity::run()['places'];
		} finally {
			$this->rm( $elsewhere );
		}
		$this->assertSame( array( 'wp-content/shim.php' ), array_column( $p['loose'], 'file' ) );
	}

	/**
	 * 1.9.0: the fingerprint covers the files it covered in 1.7.0 (.php,
	 * .phtml, .js, .htaccess), so a plugin that ships a .phar keeps its
	 * fingerprint across the upgrade, while the site's own baseline watches
	 * every extension a server runs as PHP.
	 */
	public function test_fingerprint_keeps_its_file_set(): void {
		Foundry_Toolkit_Integrity::$clock = fn() => 0.0;
		$before                           = Foundry_Toolkit_Integrity::run()['others'][0]['fingerprint'];
		$this->expire();
		$this->options = array();
		$this->put( 'wp-content/plugins/wp-rocket/lib/tool.phar', 'phar' );
		$after = Foundry_Toolkit_Integrity::run();
		$this->assertSame( $before, $after['others'][0]['fingerprint'] );
		$this->expire();
		$this->put( 'wp-content/plugins/wp-rocket/lib/shell.php5', '<?php // dropped' );
		$this->assertSame( array( 'wp-content/plugins/wp-rocket/lib/shell.php5' ), Foundry_Toolkit_Integrity::run()['unexpected'] );
	}

	/**
	 * 1.16.1: core is checked against the package's own locale
	 * ($wp_local_package), not the site's language setting. A zh_CN package
	 * ships its own version.php, so on a site set to en_AU it was flagged.
	 */
	public function test_core_uses_the_package_locale(): void {
		Foundry_Toolkit_Integrity::$local_package  = fn() => 'zh_CN';
		$zh                                        = $this->core;
		$zh['wp-includes/version.php']             = $this->put( 'wp-includes/version.php', '<?php $wp_version = "6.8.2"; $wp_local_package = "zh_CN";' );
		$asked                                     = array();
		Foundry_Toolkit_Integrity::$core_checksums = function ( string $v, string $l ) use ( $zh, &$asked ) {
			$asked[] = $l;
			return 'zh_CN' === $l ? $zh : $this->core;
		};
		$out                                       = Foundry_Toolkit_Integrity::run();
		$this->assertSame( array( 'zh_CN' ), $asked, 'never the site language, en_AU' );
		$this->assertSame( 'zh_CN', $out['locale'] );
		$this->assertTrue( $out['core']['checked'] );
		$this->assertSame( array(), $out['modified'] );
	}

	/**
	 * 1.16.1: an English package on a site set to Chinese is checked against
	 * en_US, whose version.php it has, not zh_CN's. A package locale that is
	 * not a locale is ignored the same way.
	 */
	public function test_core_without_a_package_locale_uses_en_us(): void {
		Functions\when( 'get_locale' )->justReturn( 'zh_CN' );
		foreach ( array( null, '../../etc', 42 ) as $package ) {
			Foundry_Toolkit_Integrity::$local_package  = fn() => $package;
			$this->expire();
			$asked                                     = array();
			Foundry_Toolkit_Integrity::$core_checksums = function ( string $v, string $l ) use ( &$asked ) {
				$asked[] = $l;
				return 'en_US' === $l ? $this->core : array( 'wp-includes/version.php' => md5( 'the zh_CN copy' ) );
			};
			$out                                       = Foundry_Toolkit_Integrity::run();
			$this->assertSame( array( 'en_US' ), $asked, (string) json_encode( $package ) );
			$this->assertSame( 'en_US', $out['locale'] );
			$this->assertSame( array(), $out['modified'] );
		}
	}
}
