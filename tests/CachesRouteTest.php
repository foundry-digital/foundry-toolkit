<?php
/**
 * POST /caches: clear WP Rocket and then the Rocket.net CDN on request
 * (P63, ADR 0033 in the Site Manager repository), the P39b steps without
 * Elementor.
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

require_once __DIR__ . '/UpdateSupport.php';

/** @covers SiteManager_Agent */
final class CachesRouteTest extends UpdateSupport {

	/** @var string[] */
	private $calls = array();

	protected function setUp(): void {
		parent::setUp();
		$this->calls = array();
	}

	protected function tearDown(): void {
		SiteManager_Agent::$cache_tools    = null;
		SiteManager_Agent::$rocket_upgrade = null;
		parent::tearDown();
	}

	/**
	 * WP Rocket 3.23.4 loaded, with $stored recorded. The loopback records
	 * its call, then records $after as WP Rocket's upgrade would, and
	 * answers $answer.
	 */
	private function rocket( string $stored, string $after = '3.23.4', string $answer = 'HTTP 400' ): void {
		SiteManager_Agent::$rocket_upgrade = array(
			'current'  => '3.23.4',
			'stored'   => function () use ( &$stored ): string {
				return $stored;
			},
			'loopback' => function () use ( &$stored, $after, $answer ): string {
				$this->calls[] = 'loopback';
				$stored        = $after;
				return $answer;
			},
		);
	}

	/**
	 * Every tool present, each recording its call and answering $answers[name]
	 * (true when not given). The mute records whether the lock is held, so a
	 * test can see the clear ran under it.
	 *
	 * @param array<string, mixed> $answers Name to answer.
	 * @param string[]             $absent  Names of tools not installed.
	 */
	private function tools( array $answers = array(), array $absent = array() ): void {
		$tools = array();
		foreach ( array( 'elementor_files', 'elementor_library', 'wp_rocket', 'rocket_cdn' ) as $name ) {
			$tools[ $name ] = in_array( $name, $absent, true ) ? null : function () use ( $name, $answers ) {
				$this->calls[] = $name . ( isset( $this->transients['sm_update_lock'] ) ? '' : ' unlocked' );
				$answer        = $answers[ $name ] ?? true;
				if ( $answer instanceof Throwable ) {
					throw $answer;
				}
				return $answer;
			};
		}
		$tools['mute_cdn']              = in_array( 'rocket_cdn', $absent, true ) ? null : function ( bool $mute ) {
			$this->calls[] = $mute ? 'mute' : 'unmute';
		};
		SiteManager_Agent::$cache_tools = $tools;
	}

	/**
	 * @param mixed $body Body, encoded as JSON unless a string.
	 * @return WP_REST_Response|WP_Error
	 */
	private function clear( $body = '{}' ) {
		return SiteManager_Agent::caches( $this->post( 'caches', $body ) );
	}

	/**
	 * @param WP_REST_Response|WP_Error $res Response.
	 * @return array<int, array{name: string, status: string, detail: string}>
	 */
	private function caches( $res ): array {
		$this->assertInstanceOf( WP_REST_Response::class, $res, $this->code( $res ) );
		return $res->get_data()['caches'];
	}

	/** P63, S2: the route exists only where SM_ALLOW_UPDATES is true. */
	public function test_route_absent_without_opt_in(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( string $ns, string $route, array $args ) use ( &$routes ): bool {
				$routes[ $route ] = $args['methods'];
				return true;
			}
		);
		SiteManager_Agent::$updates_override = false;
		SiteManager_Agent::register_routes();
		$this->assertArrayNotHasKey( '/caches', $routes );

		$routes                              = array();
		SiteManager_Agent::$updates_override = true;
		SiteManager_Agent::register_routes();
		$this->assertSame( 'POST', $routes['/caches'] ?? '', 'opted in, /caches is a POST route' );
		$this->assertArrayNotHasKey( '/cache', $routes, 'P62 stays withdrawn' );
	}

	/**
	 * P63: WP Rocket, then the CDN last so it refills from fresh pages, with
	 * the CDN plugin's own hooks muted while WP Rocket clears. Elementor
	 * never clears from this route, even when it is installed.
	 */
	public function test_clears_wp_rocket_then_the_cdn(): void {
		$this->tools();
		$res    = $this->clear();
		$caches = $this->caches( $res );
		$this->assertSame( array( 'mute', 'wp_rocket', 'unmute', 'rocket_cdn' ), $this->calls );
		$this->assertSame( array( 'wp_rocket', 'rocket_cdn' ), array_column( $caches, 'name' ) );
		$this->assertSame( array( 'cleared', 'cleared' ), array_column( $caches, 'status' ) );
		$data = $res->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertIsInt( $data['duration_ms'] );
		$this->assertSame( array( 'ok', 'caches', 'duration_ms' ), array_keys( $data ) );
		$this->assertSame( 'no-store', $res->headers['Cache-Control'] ?? '' );
	}

	/** P38, P63: the clear holds the update lock, and lets it go afterwards. */
	public function test_holds_the_lock_while_clearing_and_releases_it(): void {
		$this->tools();
		$this->caches( $this->clear() );
		$this->assertNotContains( 'wp_rocket unlocked', $this->calls, 'an update cannot start mid-clear' );
		$this->assertArrayNotHasKey( 'sm_update_lock', $this->transients );
	}

	/** P63: a running update holds the lock, so the caches wait for it. */
	public function test_busy_while_an_update_runs(): void {
		$this->tools();
		$this->transients['sm_update_lock'] = 'other';
		$res                                = $this->clear();
		$this->assertSame( 'sm_busy', $this->code( $res ) );
		$this->assertSame( 409, $this->status( $res ) );
		$this->assertSame( array(), $this->calls, 'nothing cleared while an update runs' );
		$this->assertSame( 'other', $this->transients['sm_update_lock'], 'the update keeps its lock' );
	}

	/**
	 * P63: the body is exactly {}. Anything else is refused before any cache
	 * is touched.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function bad_bodies(): array {
		return array(
			'empty'       => array( '' ),
			'not json'    => array( 'clear' ),
			'array'       => array( '[]' ),
			'list'        => array( '["wp_rocket"]' ),
			'other keys'  => array( '{"tools":["wp_rocket"]}' ),
			'items'       => array( '{"items":[]}' ),
			'json string' => array( '"{}"' ),
			'null'        => array( 'null' ),
		);
	}

	/** @dataProvider bad_bodies */
	public function test_bad_body_is_refused( string $body ): void {
		$this->tools();
		$res = $this->clear( $body );
		$this->assertSame( 'sm_bad_request', $this->code( $res ) );
		$this->assertSame( 400, $this->status( $res ) );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * A clear that fails or throws is reported and the CDN still runs: the
	 * route answers ok, with the failure in its entry, the way an update does.
	 */
	public function test_a_failed_step_is_reported(): void {
		$this->tools( array( 'wp_rocket' => new RuntimeException( 'disk full' ) ) );
		$res    = $this->clear();
		$caches = $this->caches( $res );
		$this->assertTrue( $res->get_data()['ok'] );
		$this->assertSame( array( 'failed', 'cleared' ), array_column( $caches, 'status' ) );
		$this->assertSame( 'disk full', $caches[0]['detail'] );
		$this->assertSame( array( 'mute', 'wp_rocket', 'unmute', 'rocket_cdn' ), $this->calls, 'the CDN plugin is always unmuted' );
	}

	/**
	 * P63, ADR 0033: WP Rocket upgrades on admin_init, which a REST request
	 * never fires, so a pending upgrade is run first by a loopback to
	 * admin-ajax.php, and reported ahead of the clears.
	 */
	public function test_pending_rocket_upgrade_runs_first_by_loopback(): void {
		$this->tools();
		$this->rocket( '3.23.3.3' );
		$caches = $this->caches( $this->clear() );
		$this->assertSame( array( 'loopback', 'mute', 'wp_rocket', 'unmute', 'rocket_cdn' ), $this->calls );
		$this->assertSame( array( 'wp_rocket_upgrade', 'wp_rocket', 'rocket_cdn' ), array_column( $caches, 'name' ) );
		$this->assertSame( array( 'cleared', 'cleared', 'cleared' ), array_column( $caches, 'status' ) );
		$this->assertSame( '', $caches[0]['detail'] );
	}

	/** An upgrade still pending after the loopback is reported; the clears still run. */
	public function test_rocket_upgrade_still_pending_is_reported(): void {
		$this->tools();
		$this->rocket( '3.23.3.3', '3.23.3.3', 'HTTP 403' );
		$res    = $this->clear();
		$caches = $this->caches( $res );
		$this->assertTrue( $res->get_data()['ok'] );
		$this->assertSame( array( 'loopback', 'mute', 'wp_rocket', 'unmute', 'rocket_cdn' ), $this->calls );
		$this->assertSame( array( 'wp_rocket_upgrade', 'wp_rocket', 'rocket_cdn' ), array_column( $caches, 'name' ) );
		$this->assertSame( array( 'failed', 'cleared', 'cleared' ), array_column( $caches, 'status' ) );
		$this->assertSame( "WP Rocket's upgrade from 3.23.3.3 to 3.23.4 is still pending after the loopback (HTTP 403).", $caches[0]['detail'] );
	}

	/** A loopback that throws is reported the same way. */
	public function test_rocket_loopback_that_throws_is_reported(): void {
		$this->tools();
		SiteManager_Agent::$rocket_upgrade = array(
			'current'  => '3.23.4',
			'stored'   => static fn(): string => '3.23.3.3',
			'loopback' => static function (): string {
				throw new RuntimeException( 'cURL error 28: timed out' );
			},
		);
		$caches = $this->caches( $this->clear() );
		$this->assertSame( 'failed', $caches[0]['status'] );
		$this->assertStringContainsString( '(cURL error 28: timed out)', $caches[0]['detail'] );
		$this->assertSame( array( 'mute', 'wp_rocket', 'unmute', 'rocket_cdn' ), $this->calls );
	}

	/**
	 * No upgrade pending, or no version recorded yet (a first install is not
	 * ours to start): no loopback and no entry.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function settled_versions(): array {
		return array(
			'up to date'   => array( '3.23.4' ),
			'none stored'  => array( '' ),
		);
	}

	/** @dataProvider settled_versions */
	public function test_no_pending_rocket_upgrade_makes_no_loopback( string $stored ): void {
		$this->tools();
		$this->rocket( $stored );
		$caches = $this->caches( $this->clear() );
		$this->assertSame( array( 'mute', 'wp_rocket', 'unmute', 'rocket_cdn' ), $this->calls );
		$this->assertSame( array( 'wp_rocket', 'rocket_cdn' ), array_column( $caches, 'name' ) );
	}

	/** No WP Rocket and no CDN plugin: nothing to clear, and still ok. */
	public function test_no_tools_gives_an_empty_list(): void {
		$this->tools( array(), array( 'elementor_files', 'elementor_library', 'wp_rocket', 'rocket_cdn' ) );
		$res = $this->clear();
		$this->assertSame( array(), $this->caches( $res ) );
		$this->assertTrue( $res->get_data()['ok'] );
		$this->assertSame( array(), $this->calls );
	}
}
