<?php
/**
 * POST /update with a list of items: one request per site, one bulk upgrade
 * per type, a fresh report in the answer (P33b, ADR 0026).
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

require_once __DIR__ . '/UpdateSupport.php';

/** @covers SiteManager_Agent */
final class BatchUpdateTest extends UpdateSupport {

	/** @var SM_Test_Upgrader[] Every upgrader made, in order. */
	private $upgraders = array();

	/** @var string[] Update checks WordPress was asked to run. */
	private $checks = array();

	/** @var int */
	private $cache_runs = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->upgraders = array();
		$this->checks    = array();
		$this->cache_runs = 0;
		$this->plugins['wp-rocket/wp-rocket.php'] = array(
			'Name'    => 'WP Rocket',
			'Version' => '3.18',
		);
		$this->write_plugin( 'wp-rocket/wp-rocket.php', '3.18' );
		$this->site_transients['update_plugins']->response['wp-rocket/wp-rocket.php'] = (object) array( 'new_version' => '3.19' );
		$this->active['wp-rocket/wp-rocket.php'] = true;

		SiteManager_Agent::$upgrader_factory = function ( string $type, Automatic_Upgrader_Skin $skin ) {
			$u              = new SM_Test_Upgrader( $skin );
			$skin->messages = array( 'Downloading update from https://example.test/x.zip?key=SECRET', 'Updated ' . $type . '.' );
			$u->on_upgrade  = function ( $what ) use ( $type ) {
				foreach ( (array) $what as $item ) {
					if ( 'plugin' === $type && is_string( $item ) ) {
						$this->write_plugin( $item, (string) $this->site_transients['update_plugins']->response[ $item ]->new_version );
					}
					if ( 'theme' === $type && is_string( $item ) ) {
						$this->write_theme( $item, '1.5.0' );
					}
				}
			};
			$this->upgraders[] = $u;
			return $u;
		};
		SiteManager_Agent::$report_factory = static fn() => array( 'protocol' => 1 );
		SiteManager_Agent::$cache_tools    = array(
			'wp_rocket' => function () {
				++$this->cache_runs;
				return true;
			},
		);
		Functions\when( 'delete_site_transient' )->alias(
			function ( string $k ): bool {
				$this->checks[] = 'delete ' . $k;
				return true;
			}
		);
		Functions\when( 'wp_update_plugins' )->alias(
			function () {
				$this->checks[] = 'wp_update_plugins';
			}
		);
		Functions\when( 'wp_update_themes' )->alias(
			function () {
				$this->checks[] = 'wp_update_themes';
			}
		);
		Functions\when( 'wp_version_check' )->alias(
			function () {
				$this->checks[] = 'wp_version_check';
			}
		);
	}

	protected function tearDown(): void {
		SiteManager_Agent::$report_factory = null;
		SiteManager_Agent::$cache_tools    = null;
		parent::tearDown();
	}

	/** @return array<string, mixed> */
	private function run_batch( array $items ): array {
		$r = SiteManager_Agent::update( $this->post( 'update', array( 'items' => $items ) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $r, $this->code( $r ) );
		return $r->get_data();
	}

	private static function item( string $type, string $item, string $expected = '' ): array {
		$out = array(
			'type' => $type,
			'item' => $item,
		);
		if ( '' !== $expected ) {
			$out['expected_version'] = $expected;
		}
		return $out;
	}

	public function test_one_bulk_upgrade_per_type_and_one_answer(): void {
		$data = $this->run_batch(
			array(
				self::item( 'plugin', 'gravityforms/gravityforms.php', '2.9.13' ),
				self::item( 'plugin', 'wp-rocket/wp-rocket.php', '3.19' ),
				self::item( 'theme', 'achk27', '1.5.0' ),
			)
		);
		$this->assertCount( 2, $this->upgraders, 'one upgrader for plugins, one for themes' );
		$this->assertSame( array( array( 'gravityforms/gravityforms.php', 'wp-rocket/wp-rocket.php' ) ), $this->upgraders[0]->calls, 'both plugins in one bulk upgrade: one maintenance window' );
		$this->assertSame( array( 'bulk_upgrade' ), $this->upgraders[0]->methods );
		$this->assertSame( array( array( 'achk27' ) ), $this->upgraders[1]->calls );
		$this->assertTrue( $data['ok'] );
		$this->assertSame( array( 'gravityforms/gravityforms.php', 'wp-rocket/wp-rocket.php', 'achk27' ), array_column( $data['items'], 'item' ), 'results in request order' );
		$this->assertSame( array( '2.9.12', '3.18', '1.4.0' ), array_column( $data['items'], 'from_version' ) );
		$this->assertSame( array( '2.9.13', '3.19', '1.5.0' ), array_column( $data['items'], 'to_version' ) );
		$this->assertSame( array( true, true, true ), array_column( $data['items'], 'ok' ) );
		$this->assertSame( array( 'protocol' => 1 ), $data['report'], 'a fresh report, so the app need not fetch one' );
		$this->assertSame( array(), $this->checks, 'everything was on WordPress\'s list, so no update check' );
		$this->assertSame( 1, $this->cache_runs, 'caches cleared once for the whole request' );
		$this->assertNotContains( 'SECRET', array_map( 'strval', $data['messages'] ) );
		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode_test( $data ) );
		$this->assertArrayHasKey( 'duration_ms', $data );
	}

	public function test_item_missing_from_the_list_refreshes_it_once(): void {
		unset( $this->site_transients['update_plugins']->response['wp-rocket/wp-rocket.php'] );
		Functions\when( 'wp_update_plugins' )->alias(
			function () {
				$this->checks[] = 'wp_update_plugins';
				$this->site_transients['update_plugins']->response['wp-rocket/wp-rocket.php'] = (object) array( 'new_version' => '3.19' );
			}
		);
		$data = $this->run_batch(
			array(
				self::item( 'plugin', 'gravityforms/gravityforms.php', '2.9.13' ),
				self::item( 'plugin', 'wp-rocket/wp-rocket.php', '3.19' ),
			)
		);
		$this->assertSame( array( 'delete update_plugins', 'wp_update_plugins' ), $this->checks, 'one refresh for the type' );
		$this->assertSame( array( true, true ), array_column( $data['items'], 'ok' ) );
	}

	public function test_one_bad_item_does_not_stop_the_others(): void {
		$data = $this->run_batch(
			array(
				self::item( 'plugin', 'gravityforms/gravityforms.php', '2.9.13' ),
				self::item( 'plugin', 'missing/missing.php', '1.0' ),
				self::item( 'plugin', 'wp-rocket/wp-rocket.php', '9.9' ),
				self::item( 'plugin', 'hello.php', '1.7.3' ),
			)
		);
		$this->assertFalse( $data['ok'] );
		$codes = array_column( $data['items'], 'code' );
		$this->assertSame( array( '', 'sm_not_installed', 'sm_version_mismatch', 'sm_no_update_available' ), $codes );
		$this->assertSame( '3.19', $data['items'][2]['offered_version'] );
		$this->assertSame( array( array( 'gravityforms/gravityforms.php' ) ), $this->upgraders[0]->calls, 'only the runnable item was upgraded' );
		$this->assertTrue( $data['items'][0]['ok'] );
	}

	public function test_a_failed_plugin_is_reported_with_the_licence_hint(): void {
		SiteManager_Agent::$upgrader_factory = function ( string $type, Automatic_Upgrader_Skin $skin ) {
			$u                = new SM_Test_Upgrader( $skin );
			$skin->messages   = array( 'Your license key is not valid for this site (403).' );
			$u->per_item      = array( 'gravityforms/gravityforms.php' => new WP_Error( 'download_failed', 'Download failed. Forbidden' ) );
			$this->upgraders[] = $u;
			return $u;
		};
		$data = $this->run_batch(
			array(
				self::item( 'plugin', 'gravityforms/gravityforms.php', '2.9.13' ),
				self::item( 'plugin', 'wp-rocket/wp-rocket.php', '3.19' ),
			)
		);
		$this->assertFalse( $data['items'][0]['ok'] );
		$this->assertSame( 'sm_upgrade_failed', $data['items'][0]['code'] );
		$this->assertSame( 'Download failed. Forbidden', $data['items'][0]['message'] );
		$this->assertTrue( $data['items'][0]['likely_licence_problem'] );
		$this->assertSame( '2.9.12', $data['items'][0]['to_version'], 'still the old version' );
		$this->assertTrue( $data['items'][1]['ok'] );
	}

	public function test_plugins_switched_off_are_switched_back_on(): void {
		SiteManager_Agent::$upgrader_factory = function ( string $type, Automatic_Upgrader_Skin $skin ) {
			$u             = new SM_Test_Upgrader( $skin );
			$u->on_upgrade = function () {
				$this->active = array();
			};
			return $u;
		};
		$data = $this->run_batch(
			array(
				self::item( 'plugin', 'gravityforms/gravityforms.php', '2.9.13' ),
				self::item( 'plugin', 'wp-rocket/wp-rocket.php', '3.19' ),
			)
		);
		$this->assertSame( array( true, true ), array_column( $data['items'], 'reactivated' ) );
		$this->assertCount( 2, $this->activations );
	}

	public function test_busy_site_refuses_the_whole_request(): void {
		$this->transients['sm_update_lock'] = 'other';
		$r = SiteManager_Agent::update( $this->post( 'update', array( 'items' => array( self::item( 'plugin', 'hello.php', '1.7.3' ) ) ) ) );
		$this->assertSame( 'sm_busy', $this->code( $r ) );
		$this->assertSame( 409, $this->status( $r ) );
	}

	/** @return array<string, array{0: mixed}> */
	public function bad_envelopes(): array {
		return array(
			'empty list'      => array( array() ),
			'not a list'      => array( array( 'a' => self::item( 'plugin', 'hello.php', '1' ) ) ),
			'too many'        => array( array_fill( 0, 51, self::item( 'plugin', 'hello.php', '1' ) ) ),
			'bad type'        => array( array( self::item( 'widget', 'x', '1' ) ) ),
			'path in item'    => array( array( self::item( 'plugin', '../evil.php', '1' ) ) ),
			'no expected'     => array( array( self::item( 'plugin', 'hello.php' ) ) ),
			'duplicate item'  => array( array( self::item( 'plugin', 'hello.php', '1' ), self::item( 'plugin', 'hello.php', '1' ) ) ),
			'package smuggle' => array(
				array(
					array(
						'type'             => 'plugin',
						'item'             => 'hello.php',
						'expected_version' => '1',
						'package'          => 'https://evil.test/x.zip',
					),
				),
			),
		);
	}

	/**
	 * @dataProvider bad_envelopes
	 * @param mixed $items Items.
	 */
	public function test_bad_envelopes_are_refused_before_anything_runs( $items ): void {
		$r = SiteManager_Agent::update( $this->post( 'update', array( 'items' => $items ) ) );
		$this->assertSame( 'sm_bad_request', $this->code( $r ) );
		$this->assertSame( 400, $this->status( $r ) );
		$this->assertSame( array(), $this->upgraders );
		$this->assertArrayNotHasKey( 'sm_update_lock', $this->transients );
	}
}
