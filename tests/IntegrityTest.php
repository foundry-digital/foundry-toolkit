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
		$this->site_root = sys_get_temp_dir() . '/sm-integrity-' . uniqid() . '/';
		$this->fetched   = array();
		$this->time      = 0.0;
		$this->options   = array();
		$this->themes    = array();
		$this->failing   = array();
		$this->core_down = false;
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
			'en_AU has no checksums, so en_US is used; readme and wp-content are skipped'
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
		Foundry_Toolkit_Integrity::$clock = fn() => 0.0; // no budget pressure here

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
					$ttl[ is_array( $v ) ? 'list' : 'none' ] = $t;
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
}
