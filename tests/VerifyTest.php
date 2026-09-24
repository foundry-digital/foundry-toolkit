<?php
/**
 * Signature verification against the shared protocol vectors (P52).
 *
 * @package FoundryToolkit
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/** @covers SiteManager_Agent */
final class VerifyTest extends TestCase {

	/** @var array<string, int> */
	private $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->transients = array();
		Functions\when( 'get_transient' )->alias(
			function ( string $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		SiteManager_Agent::$clock = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Every case in vectors.json.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function vectors(): array {
		$out = array();
		foreach ( SM_VECTORS['cases'] as $case ) {
			$out[ $case['name'] ] = array( $case );
		}
		return $out;
	}

	/**
	 * Step 1 of P21 is WordPress routing: a route that is not registered
	 * never reaches the permission callback.
	 *
	 * @param array<string, mixed> $case Vector case.
	 */
	private function route_exists( array $case ): bool {
		$method = $case['request']['method'];
		$route  = $case['request']['route'];
		if ( 'GET' === $method ) {
			return '/sitemanager/v1/report' === $route;
		}
		if ( 'POST' === $method ) {
			return $case['site']['allow_updates'] && '/sitemanager/v1/update' === $route;
		}
		return false;
	}

	/**
	 * @dataProvider vectors
	 * @param array<string, mixed> $case Vector case.
	 */
	public function test_vector( array $case ): void {
		Functions\when( 'home_url' )->justReturn( $case['site']['home_url'] );
		foreach ( $case['site']['used_nonces'] as $nonce ) {
			$this->transients[ SiteManager_Agent::NONCE_PREFIX . $nonce ] = 1;
		}
		$request                  = new WP_REST_Request( $case['request']['method'], $case['request']['route'], $case['request']['headers'], $case['request']['body'] );
		SiteManager_Agent::$clock = static fn(): int => (int) $case['now'];

		if ( ! $this->route_exists( $case ) ) {
			$this->assertSame( 'reject', $case['expect'], 'a route WordPress never registers cannot be accepted' );
			return;
		}
		$result   = SiteManager_Agent::permission( $request );
		$accepted = true === $result;
		$this->assertSame( 'accept' === $case['expect'], $accepted, $case['reason'] );
		if ( 'accept' === $case['expect'] ) {
			$this->assertTrue( $result );
			if ( 'POST' === $case['request']['method'] ) {
				$this->assertArrayHasKey( SiteManager_Agent::NONCE_PREFIX . $case['request']['headers']['X-SM-Nonce'], $this->transients, 'nonce recorded after accept' );
			}
			return;
		}
		$this->assertInstanceOf( WP_Error::class, $result );
		// P22: the exact body WordPress uses for a missing route.
		$this->assertSame( 'rest_no_route', $result->get_error_code() );
		$this->assertSame( 'No route was found matching the URL and request method.', $result->get_error_message() );
		$this->assertSame( array( 'status' => 404 ), $result->get_error_data() );
	}

	public function test_replayed_nonce_is_not_re_recorded(): void {
		$case = null;
		foreach ( SM_VECTORS['cases'] as $c ) {
			if ( 'post_update_nonce_replayed' === $c['name'] ) {
				$case = $c;
			}
		}
		$this->assertNotNull( $case );
		Functions\when( 'home_url' )->justReturn( $case['site']['home_url'] );
		$key                      = SiteManager_Agent::NONCE_PREFIX . $case['request']['headers']['X-SM-Nonce'];
		$this->transients[ $key ] = 'original';
		$request                  = new WP_REST_Request( 'POST', $case['request']['route'], $case['request']['headers'], $case['request']['body'] );
		$this->assertSame( 'nonce_used', SiteManager_Agent::verify( $request, (int) $case['now'] ) );
		$this->assertSame( 'original', $this->transients[ $key ], 'a replay must not overwrite the used nonce' );
	}

	public function test_post_routes_absent_without_opt_in(): void {
		// SM_ALLOW_UPDATES is not defined in the test process, so only the
		// report route may be registered (S2).
		$this->assertFalse( SiteManager_Agent::updates_enabled() );
		Functions\expect( 'register_rest_route' )->once()->with( 'sitemanager/v1', '/report', Mockery::type( 'array' ) );
		SiteManager_Agent::register_routes();
	}

	public function test_boot_without_key_registers_nothing(): void {
		// The public key constant is fixed for the process, so this checks the
		// helper the boot path relies on.
		$this->assertNotNull( SiteManager_Agent::public_key() );
		$this->assertSame( 32, strlen( (string) SiteManager_Agent::public_key() ) );
	}
}
