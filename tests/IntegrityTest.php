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

	protected function setUp(): void {
		parent::setUp();
		$this->site_root = sys_get_temp_dir() . '/sm-integrity-' . uniqid() . '/';
		$this->fetched   = array();
		$this->time      = 0.0;
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
		Foundry_Toolkit_Integrity::$core_checksums   = fn( string $v, string $l ) => 'en_US' === $l && '6.8.2' === $v ? $this->core : false;
		Foundry_Toolkit_Integrity::$plugin_checksums = function ( string $slug, string $v ) {
			$this->fetched[] = $slug;
			$this->time     += 15.0; // Each plugin takes 15 seconds on this slow host.
			return $this->plugin_sums[ $slug ] ?? null;
		};
		Foundry_Toolkit_Integrity::$plugins          = fn() => $this->installed;
		Foundry_Toolkit_Integrity::$clock            = fn() => $this->time;
	}

	protected function tearDown(): void {
		Foundry_Toolkit_Integrity::$root             = null;
		Foundry_Toolkit_Integrity::$plugin_root      = null;
		Foundry_Toolkit_Integrity::$core_checksums   = null;
		Foundry_Toolkit_Integrity::$plugin_checksums = null;
		Foundry_Toolkit_Integrity::$plugins          = null;
		Foundry_Toolkit_Integrity::$clock            = null;
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
}
